<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

$partes  = explode('/', trim($_GET['route'] ?? '', '/'));
$albumId = $partes[1] ?? '';

if (!$albumId) { header('Location: /albuns'); exit; }

$stmt = $db->prepare("
    SELECT id, nome, percentual_conclusao, total_faltantes, total_repetidas
    FROM ifc_albuns WHERE id = :id AND usuario_id = :uid AND ativo = 1
");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
$album = $stmt->fetch();
if (!$album) { header('Location: /albuns'); exit; }

$stmt = $db->prepare("
 SELECT
 g.codigo AS grupo_codigo,
 g.nome AS grupo_nome,
 g.ordem AS grupo_ordem,
 s.id AS selecao_id,
 s.sigla AS selecao_sigla,
 s.nome AS selecao_nome,
 s.bandeira_url,
 f.id AS figurinha_id,
 f.codigo AS figurinha_codigo,
 f.numero,
 f.tipo,
 COALESCE(i.quantidade, 0) AS quantidade
 FROM " . tbl('figurinhas') . " f
 JOIN " . tbl('selecoes') . " s ON s.id = f.selecao_id
 LEFT JOIN " . tbl('grupos') . " g ON g.id = s.grupo_id
 LEFT JOIN " . tbl('inventario') . " i
 ON i.figurinha_id = f.id AND i.album_id = :aid
 ORDER BY COALESCE(g.ordem, -1), s.sigla, f.numero
");
$stmt->execute([':aid' => $albumId]);
$rows = $stmt->fetchAll();

// Organiza estrutura hierárquica
$estrutura = [];
foreach ($rows as $row) {
    $gKey = $row['grupo_codigo'] ?? 'especial';
    $sKey = $row['selecao_sigla'];
    if (!isset($estrutura[$gKey])) {
        $estrutura[$gKey] = ['nome' => $row['grupo_nome'] ?? 'Especiais', 'selecoes' => []];
    }
    if (!isset($estrutura[$gKey]['selecoes'][$sKey])) {
        $estrutura[$gKey]['selecoes'][$sKey] = [
            'id'         => $row['selecao_id'],
            'sigla'      => $row['selecao_sigla'],
            'nome'       => $row['selecao_nome'],
            'bandeira'   => $row['bandeira_url'],
            'figurinhas' => [],
            'total'      => 0,
            'tenho'      => 0,
        ];
    }
    $estrutura[$gKey]['selecoes'][$sKey]['figurinhas'][] = [
        'id'     => $row['figurinha_id'],
        'codigo' => $row['figurinha_codigo'],
        'numero' => $row['numero'],
        'tipo'   => $row['tipo'],
        'qtd'    => (int) $row['quantidade'],
    ];
    $estrutura[$gKey]['selecoes'][$sKey]['total']++;
    if ((int)$row['quantidade'] > 0) {
        $estrutura[$gKey]['selecoes'][$sKey]['tenho']++;
    }
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
    <div class="inventario-stats">
        <span><strong id="stat-pct"><?= number_format($album['percentual_conclusao'],1) ?>%</strong> completo</span>
        <span>Faltam: <strong id="stat-falt"><?= $album['total_faltantes'] ?></strong></span>
        <span>Repetidas: <strong id="stat-rep"><?= $album['total_repetidas'] ?></strong></span>
    </div>
</div>
<div class="acoes-barra">
    <button class="btn-sm btn-todas-inc" onclick="atualizarTodas('incrementar')">+1 em todas</button>
    <button class="btn-sm btn-todas-dec" onclick="atualizarTodas('decrementar')">−1 em todas</button>

    <div class="dropdown">
        <button class="btn-sm" onclick="toggleDropdown()">⬆⬇ Exp/Imp ▾</button>
        <div class="dropdown-menu" id="dropdown-menu">
            <a href="/api/csv?acao=exportar&album_id=<?= $albumId ?>" class="dropdown-item">
                ⬇ Exportar CSV
            </a>
            <label class="dropdown-item" style="cursor:pointer">
                ⬆ Importar CSV
                <input type="file" id="csv-input" accept=".csv" style="display:none"
                       onchange="importarCSV(this)">
            </label>
        </div>
    </div>
    <span id="csv-msg" style="font-size:.82rem;color:#666;"></span>
</div>

<!-- Filtros -->
<div class="filtros-sticky">
    <div class="filtros-barra">
    <input type="text" id="busca" placeholder="Buscar código ou país...">
    <select id="filtro-status">
        <option value="">Todas</option>
        <option value="faltante">Faltantes</option>
        <option value="tenho">Tenho (≥1)</option>
        <option value="repetida">Repetidas (≥2)</option>
    </select>
    <label class="checkbox-label">
        <input type="checkbox" id="mostrar-completas">
        Mostrar completas
    </label>
</div>
    <!-- Pílulas de grupo -->
    <div class="grupos-pilulas">
        <button class="pilula ativa" data-grupo="" onclick="filtrarGrupo(this)">Todos</button>
        <?php foreach ($estrutura as $gKey => $grupo): ?>
            <button class="pilula" data-grupo="<?= htmlspecialchars($gKey) ?>" onclick="filtrarGrupo(this)">
                <?= htmlspecialchars($gKey === 'especial' ? 'Esp.' : $gKey) ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Seleções -->
<div id="lista-selecoes">
<?php foreach ($estrutura as $grupoKey => $grupo): ?>
    <?php foreach ($grupo['selecoes'] as $sigla => $selecao):
        $pct = $selecao['total'] > 0 ? round(($selecao['tenho'] / $selecao['total']) * 100) : 0;
    ?>
        <div class="selecao-row"
             data-grupo="<?= htmlspecialchars($grupoKey) ?>"
             data-sigla="<?= htmlspecialchars($sigla) ?>"
             data-nome="<?= htmlspecialchars(mb_strtolower($selecao['nome'])) ?>">

            <!-- Cabeçalho da seleção (clicável) -->
            <div class="selecao-cabecalho" onclick="toggleSelecao(this)">
                <div class="selecao-info">
                    <img src="<?= htmlspecialchars($selecao['bandeira']) ?>"
                         alt="<?= htmlspecialchars($sigla) ?>"
                         class="bandeira"
                         onerror="this.style.display='none'">
                    <span class="selecao-sigla-badge"><?= htmlspecialchars($sigla) ?></span>
                    <span class="selecao-nome"><?= htmlspecialchars($selecao['nome']) ?></span>
                    <span class="selecao-grupo-badge"><?= htmlspecialchars($grupoKey === 'especial' ? 'Esp.' : 'Grupo '.$grupoKey) ?></span>
                </div>
                <div class="selecao-progresso-wrap">
                    <span class="selecao-contagem" id="cont-<?= $selecao['id'] ?>">
                        <?= $selecao['tenho'] ?>/<?= $selecao['total'] ?>
                    </span>
                    <div class="selecao-barra">
                        <div class="selecao-barra-fill" id="barra-<?= $selecao['id'] ?>"
                             style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="selecao-seta">▼</span>
                </div>
            </div>

            <!-- Figurinhas (recolhidas por padrão) -->
            <div class="selecao-figurinhas">
                <div class="figurinhas-grid">
                    <?php foreach ($selecao['figurinhas'] as $fig): ?>
                        <div class="figurinha-card <?= $fig['qtd'] > 0 ? 'tem' : '' ?> <?= $fig['qtd'] > 1 ? 'repetida' : '' ?>"
                             id="fig-<?= $fig['id'] ?>"
                             data-codigo="<?= htmlspecialchars($fig['codigo']) ?>"
                             data-qtd="<?= $fig['qtd'] ?>"
                             data-selecao="<?= $selecao['id'] ?>"
                             data-total="<?= $selecao['total'] ?>">
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
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
</div>

<script>
// ── Toggle seleção ──────────────────────────
function toggleSelecao(cabecalho) {
    const row  = cabecalho.closest('.selecao-row');
    const figs = row.querySelector('.selecao-figurinhas');
    const seta = cabecalho.querySelector('.selecao-seta');
    const aberto = figs.classList.toggle('aberto');
    seta.style.transform = aberto ? 'rotate(180deg)' : '';
}

function expandirTudo() {
    document.querySelectorAll('.selecao-row:not([style*="display: none"])').forEach(row => {
        row.querySelector('.selecao-figurinhas').classList.add('aberto');
        row.querySelector('.selecao-seta').style.transform = 'rotate(180deg)';
    });
}
function recolherTudo() {
    document.querySelectorAll('.selecao-figurinhas').forEach(f => f.classList.remove('aberto'));
    document.querySelectorAll('.selecao-seta').forEach(s => s.style.transform = '');
}

// ── Filtro por grupo (pílulas) ──────────────
function filtrarGrupo(btn) {
    document.querySelectorAll('.pilula').forEach(p => p.classList.remove('ativa'));
    btn.classList.add('ativa');
    aplicarFiltros();
}

// ── Filtros gerais ──────────────────────────
function aplicarFiltros() {
    const busca  = document.getElementById('busca').value.toLowerCase().trim();
    const status = document.getElementById('filtro-status').value;
    const mostrarCompletas = document.getElementById('mostrar-completas').checked;
    const grupo  = document.querySelector('.pilula.ativa').dataset.grupo;

    document.querySelectorAll('.selecao-row').forEach(row => {
        const rowGrupo = row.dataset.grupo;
        const rowNome  = row.dataset.nome;
        const rowSigla = row.dataset.sigla.toLowerCase();

        // Filtro grupo
        if (grupo && rowGrupo !== grupo) { row.style.display = 'none'; return; }
        
        // Oculta seleções completas por padrão
	if (!mostrarCompletas) {
	    const cards = [...row.querySelectorAll('.figurinha-card')];
	    const temFaltante = cards.some(c => parseInt(c.dataset.qtd) === 0);
	    if (!temFaltante) { row.style.display = 'none'; return; }
	}

        // Filtro busca por nome ou sigla
        if (busca && !rowNome.includes(busca) && !rowSigla.includes(busca)) {
            // Tenta busca por código de figurinha
            const temCodigo = [...row.querySelectorAll('.figurinha-card')]
                .some(c => c.dataset.codigo.toLowerCase().includes(busca));
            if (!temCodigo) { row.style.display = 'none'; return; }
        }

        row.style.display = '';

        // Filtro status — esconde/mostra cards individuais
        row.querySelectorAll('.figurinha-card').forEach(card => {
            const qtd = parseInt(card.dataset.qtd);
            const codigoMatch = !busca || card.dataset.codigo.toLowerCase().includes(busca)
                || row.dataset.nome.includes(busca) || row.dataset.sigla.toLowerCase().includes(busca);
            const statusMatch = !status
                || (status === 'faltante' && qtd === 0)
                || (status === 'tenho'    && qtd >= 1)
                || (status === 'repetida' && qtd >= 2);
            card.style.display = (codigoMatch && statusMatch) ? '' : 'none';
        });
    });
}

document.getElementById('busca').addEventListener('input', aplicarFiltros);
document.getElementById('filtro-status').addEventListener('change', aplicarFiltros);
document.getElementById('mostrar-completas').addEventListener('change', aplicarFiltros);
// ── API ─────────────────────────────────────
async function atualizar(figurinhaId, albumId, acao) {
    const resp = await fetch('/api/inventario', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `figurinha_id=${figurinhaId}&album_id=${albumId}&acao=${acao}`
    });
    const data = await resp.json();

    // Atualiza card
    const card = document.getElementById(`fig-${figurinhaId}`);
    card.dataset.qtd = data.quantidade;
    document.getElementById(`qtd-${figurinhaId}`).textContent = data.quantidade;
    card.classList.toggle('tem',      data.quantidade > 0);
    card.classList.toggle('repetida', data.quantidade > 1);

    // Atualiza barra e contagem da seleção
    const selecaoId = card.dataset.selecao;
    const totalSel  = parseInt(card.dataset.total);
    const tenhoSel  = [...document.querySelectorAll(`[data-selecao="${selecaoId}"]`)]
        .filter(c => parseInt(c.dataset.qtd) > 0).length;
    const pctSel    = totalSel > 0 ? (tenhoSel / totalSel * 100) : 0;

    document.getElementById(`cont-${selecaoId}`).textContent = `${tenhoSel}/${totalSel}`;
    document.getElementById(`barra-${selecaoId}`).style.width = pctSel + '%';

    // Atualiza stats globais
    document.getElementById('stat-pct').textContent  = data.percentual.toFixed(1) + '%';
    document.getElementById('stat-falt').textContent = data.faltantes;
    document.getElementById('stat-rep').textContent  = data.repetidas;

    aplicarFiltros();
}

// ── +1 / -1 em todas ───────────────────────
async function atualizarTodas(acao) {
    const albumId = '<?= $albumId ?>';

    // Pega todos os cards visíveis
    const cards = [...document.querySelectorAll('.figurinha-card')]
        .filter(c => c.style.display !== 'none');

    // No decrementar, ignora cards com quantidade 0
    const alvo = acao === 'decrementar'
        ? cards.filter(c => parseInt(c.dataset.qtd) > 0)
        : cards;

    if (alvo.length === 0) return;

    // Confirmação para operação em massa
    const msg = acao === 'incrementar'
        ? `Adicionar +1 em ${alvo.length} figurinha(s)?`
        : `Remover -1 de ${alvo.length} figurinha(s) com quantidade > 0?`;

    if (!confirm(msg)) return;

    // Processa em sequência para não sobrecarregar o servidor
    for (const card of alvo) {
        const figId = card.id.replace('fig-', '');
        await atualizar(figId, albumId, acao);
    }
}
// ── Importar CSV ────────────────────────────
async function importarCSV(input) {
    const arquivo = input.files[0];
    if (!arquivo) return;

    const msg = document.getElementById('csv-msg');
    msg.textContent = 'Importando...';

    const form = new FormData();
    form.append('arquivo', arquivo);

    const resp = await fetch(`/api/csv?acao=importar&album_id=<?= $albumId ?>`, {
        method: 'POST',
        body: form,
    });
    const data = await resp.json();

    if (data.sucesso) {
        msg.textContent = `✓ ${data.importados} figurinhas importadas!`;
        msg.style.color = 'green';
        document.getElementById('stat-pct').textContent  = data.percentual.toFixed(1) + '%';
        document.getElementById('stat-falt').textContent = data.faltantes;
        document.getElementById('stat-rep').textContent  = data.repetidas;
        // Recarrega a página para refletir as quantidades
        setTimeout(() => location.reload(), 1500);
    } else {
        msg.textContent = 'Erro ao importar.';
        msg.style.color = 'red';
    }
    input.value = '';
}
// ── Dropdown Exp/Imp ────────────────────────
function toggleDropdown() {
    document.getElementById('dropdown-menu').classList.toggle('aberto');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.getElementById('dropdown-menu').classList.remove('aberto');
    }
});
</script>

<?php layoutFim(); ?>

