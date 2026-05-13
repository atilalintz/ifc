<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Pega o ID do álbum da URL: /inventario/{id}
$partes  = explode('/', trim($_GET['route'] ?? '', '/'));
$albumId = $partes[1] ?? '';

if (!$albumId) {
    header('Location: /albuns');
    exit;
}

// Verifica se o álbum pertence ao usuário
$stmt = $db->prepare("
    SELECT id, nome, percentual_conclusao, total_faltantes, total_repetidas
    FROM albuns WHERE id = :id AND usuario_id = :uid AND ativo = 1
");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
$album = $stmt->fetch();

if (!$album) {
    header('Location: /albuns');
    exit;
}

// Busca figurinhas agrupadas por grupo e seleção, com quantidade do inventário
$stmt = $db->prepare("
    SELECT
        g.codigo  AS grupo_codigo,
        g.nome    AS grupo_nome,
        g.ordem   AS grupo_ordem,
        s.id      AS selecao_id,
        s.sigla   AS selecao_sigla,
        s.nome    AS selecao_nome,
        s.bandeira_url,
        f.id      AS figurinha_id,
        f.codigo  AS figurinha_codigo,
        f.numero,
        f.tipo,
        COALESCE(i.quantidade, 0) AS quantidade
    FROM figurinhas f
    JOIN selecoes s ON s.id = f.selecao_id
    LEFT JOIN grupos g ON g.id = s.grupo_id
    LEFT JOIN inventario_usuario i
        ON i.figurinha_id = f.id AND i.album_id = :aid
    ORDER BY
        COALESCE(g.ordem, -1),
        s.sigla,
        f.numero
");
$stmt->execute([':aid' => $albumId]);
$rows = $stmt->fetchAll();

// Organiza em estrutura hierárquica: grupo → seleção → figurinhas
$estrutura = [];
foreach ($rows as $row) {
    $gKey = $row['grupo_codigo'] ?? 'especial';
    $sKey = $row['selecao_sigla'];

    if (!isset($estrutura[$gKey])) {
        $estrutura[$gKey] = [
            'nome'     => $row['grupo_nome'] ?? 'Especiais',
            'selecoes' => [],
        ];
    }
    if (!isset($estrutura[$gKey]['selecoes'][$sKey])) {
        $estrutura[$gKey]['selecoes'][$sKey] = [
            'id'          => $row['selecao_id'],
            'sigla'       => $row['selecao_sigla'],
            'nome'        => $row['selecao_nome'],
            'bandeira'    => $row['bandeira_url'],
            'figurinhas'  => [],
        ];
    }
    $estrutura[$gKey]['selecoes'][$sKey]['figurinhas'][] = [
        'id'       => $row['figurinha_id'],
        'codigo'   => $row['figurinha_codigo'],
        'numero'   => $row['numero'],
        'tipo'     => $row['tipo'],
        'qtd'      => (int) $row['quantidade'],
    ];
}

layoutInicio('Inventário — ' . $album['nome']);
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">
            <?= htmlspecialchars($album['nome']) ?>
        </h1>
    </div>
    <div class="inventario-stats" id="stats">
        <span><strong id="stat-pct"><?= number_format($album['percentual_conclusao'],1) ?>%</strong> completo</span>
        <span>Faltam: <strong id="stat-falt"><?= $album['total_faltantes'] ?></strong></span>
        <span>Repetidas: <strong id="stat-rep"><?= $album['total_repetidas'] ?></strong></span>
    </div>
</div>

<?php foreach ($estrutura as $grupoKey => $grupo): ?>
    <div class="grupo-secao">
        <h2 class="grupo-titulo"><?= htmlspecialchars($grupo['nome']) ?></h2>

        <?php foreach ($grupo['selecoes'] as $selecao): ?>
            <div class="selecao-bloco">
                <div class="selecao-header">
                    <img src="<?= htmlspecialchars($selecao['bandeira']) ?>"
                         alt="<?= htmlspecialchars($selecao['sigla']) ?>"
                         class="bandeira"
                         onerror="this.style.display='none'">
                    <span class="selecao-nome"><?= htmlspecialchars($selecao['nome']) ?></span>
                    <span class="selecao-sigla"><?= htmlspecialchars($selecao['sigla']) ?></span>
                </div>

                <div class="figurinhas-grid">
                    <?php foreach ($selecao['figurinhas'] as $fig): ?>
                        <div class="figurinha-card <?= $fig['qtd'] > 0 ? 'tem' : '' ?> <?= $fig['qtd'] > 1 ? 'repetida' : '' ?>"
                             id="fig-<?= $fig['id'] ?>"
                             data-id="<?= $fig['id'] ?>"
                             data-album="<?= $albumId ?>">
                            <span class="fig-codigo"><?= htmlspecialchars($fig['codigo']) ?></span>
                            <div class="fig-controles">
                                <button class="btn-dec" onclick="atualizar('<?= $fig['id'] ?>', '<?= $albumId ?>', 'decrementar')">−</button>
                                <span class="fig-qtd" id="qtd-<?= $fig['id'] ?>"><?= $fig['qtd'] ?></span>
                                <button class="btn-inc" onclick="atualizar('<?= $fig['id'] ?>', '<?= $albumId ?>', 'incrementar')">+</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<script>
async function atualizar(figurinhaId, albumId, acao) {
    const resp = await fetch('/api/inventario', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `figurinha_id=${figurinhaId}&album_id=${albumId}&acao=${acao}`
    });
    const data = await resp.json();

    // Atualiza quantidade no card
    const qtdEl = document.getElementById(`qtd-${figurinhaId}`);
    qtdEl.textContent = data.quantidade;

    // Atualiza visual do card
    const card = document.getElementById(`fig-${figurinhaId}`);
    card.classList.toggle('tem',      data.quantidade > 0);
    card.classList.toggle('repetida', data.quantidade > 1);

    // Atualiza estatísticas no topo
    document.getElementById('stat-pct').textContent  = data.percentual.toFixed(1) + '%';
    document.getElementById('stat-falt').textContent = data.faltantes;
    document.getElementById('stat-rep').textContent  = data.repetidas;
}
</script>

<?php layoutFim(); ?>
