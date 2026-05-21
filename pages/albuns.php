<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();
$t       = TBL;

// ─────────────────────────────────────────────
// CRIAR ÁLBUM
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['nome'])) {
    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $nome = mb_substr(trim($_POST['nome']), 0, 20);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome)) . '-' . substr($id, 0, 8);

    $db->prepare("
        INSERT INTO {$t}albuns (id, usuario_id, nome, slug_publico)
        VALUES (:id, :uid, :nome, :slug)
    ")->execute([':id' => $id, ':uid' => $usuario['id'], ':nome' => $nome, ':slug' => $slug]);

    // Opções de herança
    $incluirRepetidas  = !empty($_POST['incluir_repetidas']);
    $incluirBloqueadas = !empty($_POST['incluir_bloqueadas']);
    $albumOrigens      = $_POST['album_origens'] ?? [];

    if (!empty($albumOrigens)) {
        $origensValidas = [];
        foreach ($albumOrigens as $albumOrigem) {
            $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':id' => $albumOrigem, ':uid' => $usuario['id']]);
            if ($stmt->fetch()) $origensValidas[] = $albumOrigem;
        }

        if (!empty($origensValidas)) {
            $placeholders = implode(',', array_fill(0, count($origensValidas), '?'));

            // ── Monta query base ──────────────────────────────────────────
            // Sempre pega repetidas (quantidade >= 2)
            // Se NÃO incluir bloqueadas: exclui as que têm quantidade_bloqueada > 0
            $filtroBloqueadas = $incluirBloqueadas ? '' : 'AND quantidade_bloqueada = 0';

            $stmt = $db->prepare("
                SELECT figurinha_id, album_id, id, quantidade, quantidade_bloqueada
                FROM {$t}inventario
                WHERE album_id IN ($placeholders)
                  AND quantidade >= 2
                  $filtroBloqueadas
                ORDER BY quantidade DESC
            ");
            $stmt->execute($origensValidas);
            $todasRepetidas = $stmt->fetchAll();

            // Agrupa por figurinha para escolher a melhor origem (round-robin)
            $porFigurinha = [];
            foreach ($todasRepetidas as $rep) {
                $porFigurinha[$rep['figurinha_id']][] = $rep;
            }

            $cedidas = [];

            foreach ($porFigurinha as $fid => $registros) {
                // Ordena por quem menos cedeu (round-robin entre origens)
                usort($registros, function($a, $b) use (&$cedidas) {
                    return ($cedidas[$a['album_id']] ?? 0) <=> ($cedidas[$b['album_id']] ?? 0);
                });

                $melhor = $registros[0];

                // ── Calcula quanto transferir ─────────────────────────────
                if ($incluirRepetidas) {
                    // Passa tudo menos 1 (origem fica com 1)
                    $qtdHerdar = $melhor['quantidade'] - 1;
                } else {
                    // Passa só 1 (comportamento padrão)
                    $qtdHerdar = 1;
                }

                if ($qtdHerdar <= 0) continue;

                // Insere no novo álbum
                $db->prepare("
                    INSERT INTO {$t}inventario
                        (id, album_id, usuario_id, figurinha_id, quantidade)
                    VALUES (UUID(), :aid, :uid, :fid, :qtd)
                    ON DUPLICATE KEY UPDATE quantidade = quantidade + :qtd2
                ")->execute([
                    ':aid'  => $id,
                    ':uid'  => $usuario['id'],
                    ':fid'  => $fid,
                    ':qtd'  => $qtdHerdar,
                    ':qtd2' => $qtdHerdar,
                ]);

                // Remove da origem
                $db->prepare("
                    UPDATE {$t}inventario
                    SET quantidade = quantidade - :qtd
                    WHERE id = :id
                ")->execute([':qtd' => $qtdHerdar, ':id' => $melhor['id']]);

                $cedidas[$melhor['album_id']] = ($cedidas[$melhor['album_id']] ?? 0) + 1;

                recalcularAlbum($db, $t, $melhor['album_id'], $usuario['id']);
            }
        }
    }

    recalcularAlbum($db, $t, $id, $usuario['id']);
    header('Location: /albuns');
    exit;
}

// ─────────────────────────────────────────────
// LISTA ÁLBUNS
// ─────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT id, nome, slug_publico, percentual_conclusao,
           total_faltantes, total_repetidas
    FROM {$t}albuns
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY criado_em DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Meus Álbuns');
?>

<h1 class="page-title">Meus Álbuns</h1>

<?php if (empty($albuns)): ?>
    <p style="margin-bottom:1.5rem;color:#666;">Você ainda não tem álbuns. Crie um abaixo!</p>
<?php else: ?>
    <div class="albuns-grid">
        <?php foreach ($albuns as $album): ?>
            <div class="card-album-wrap">
                <a href="/inventario/<?= $album['id'] ?>" class="card-album">
                    <h3><?= htmlspecialchars($album['nome']) ?></h3>
                    <div class="progresso-bar">
                        <span style="width:<?= $album['percentual_conclusao'] ?>%"></span>
                    </div>
                    <p class="meta">
                        <?= number_format($album['percentual_conclusao'], 1) ?>% completo<br>
                        Faltam: <?= $album['total_faltantes'] ?> •
                        Repetidas: <?= $album['total_repetidas'] ?>
                    </p>
                </a>
                <button class="btn-deletar" title="Deletar álbum"
                        onclick="confirmarDeletar('<?= $album['id'] ?>', '<?= htmlspecialchars($album['nome'], ENT_QUOTES) ?>')">
                    🗑️
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Formulário novo álbum -->
<div class="form-novo-album">
    <h3>Novo Álbum</h3>
    <form method="POST" action="/albuns">
        <input type="text" name="nome" placeholder="Nome do álbum (máx. 20 caracteres)"
               required maxlength="20">

        <?php
        $temRepetidas = array_filter($albuns, fn($a) => (int)$a['total_repetidas'] > 0);
        ?>

        <div class="form-herdar">
            <label class="checkbox-label">
                <input type="checkbox" id="chk-herdar" onchange="toggleHerdar(this)">
                Herdar repetidas de outros álbuns
            </label>

            <div id="lista-origens" class="lista-origens" style="display:none">

                <!-- Opções de o que incluir -->
                <div class="herdar-opcoes">
                    <label class="checkbox-label">
                        <input type="checkbox" name="incluir_repetidas" id="chk-repetidas">
                        Incluir todas as repetidas
                        <span style="color:#888;font-size:.8rem">
                            (origem fica com 1 de cada)
                        </span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="incluir_bloqueadas" id="chk-bloqueadas">
                        Incluir bloqueadas
                        <span style="color:#888;font-size:.8rem">
                            (transfere 1 de cada bloqueada)
                        </span>
                    </label>
                </div>

                <div class="herdar-divisor"></div>

                <!-- Lista de álbuns origem -->
                <?php if (!empty($temRepetidas)): ?>
                    <?php foreach ($temRepetidas as $a): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="album_origens[]"
                                   value="<?= htmlspecialchars($a['id']) ?>">
                            <?= htmlspecialchars($a['nome']) ?>
                            <span style="color:#888;font-size:.8rem">
                                (<?= (int)$a['total_repetidas'] ?> repetidas)
                            </span>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="font-size:.85rem;color:#888;">
                        Nenhum álbum com repetidas no momento.
                    </p>
                <?php endif; ?>

            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:.75rem">
            Criar álbum
        </button>
    </form>
</div>

<!-- Modal deletar -->
<div id="modal-deletar" class="modal-overlay" style="display:none">
    <div class="modal-box">

        <div id="modal-etapa1">
            <h3 id="modal-titulo">Deletar álbum</h3>
            <p style="margin:.75rem 0;font-size:.9rem;color:#555;">
                O que deseja fazer com as figurinhas?
            </p>
            <div class="modal-opcoes">
                <label class="radio-label">
                    <input type="radio" name="acao_modal" value="distribuir" checked>
                    Distribuir entre outros álbuns
                </label>
                <label class="radio-label">
                    <input type="radio" name="acao_modal" value="descartar">
                    Descartar tudo (vendi o álbum)
                </label>
            </div>
            <div class="modal-acoes">
                <button class="btn btn-primary" onclick="avancarModal()">Próximo →</button>
                <button class="btn btn-sm" onclick="fecharModal()">Cancelar</button>
            </div>
        </div>

        <div id="modal-etapa2" style="display:none">
            <h3>Para onde vão as sobras?</h3>
            <p style="margin:.5rem 0 1rem;font-size:.85rem;color:#666;">
                Você pode selecionar múltiplos álbuns.<br><br>
                Se nenhum for marcado, as sobras irão automaticamente
                para o álbum com mais repetidas.
            </p>
            <div id="modal-lista-albuns" class="modal-opcoes"></div>
            <div class="modal-acoes">
                <button class="btn btn-primary" onclick="confirmarDistribuir()">Confirmar</button>
                <button class="btn btn-sm" onclick="voltarModal()">← Voltar</button>
            </div>
        </div>

        <div id="modal-etapa3" style="display:none">
            <h3>Processando...</h3>
            <div class="modal-progresso">
                <div class="progresso-spinner">⟳</div>
                <p id="modal-progresso-msg" style="margin-top:.75rem;font-size:.9rem;color:#555;">
                    Distribuindo figurinhas...
                </p>
            </div>
        </div>

        <div id="modal-etapa4" style="display:none">
            <h3 style="color:var(--verde)">✓ Concluído!</h3>
            <div id="modal-resultado" style="margin:1rem 0;font-size:.9rem;"></div>
            <div class="modal-acoes">
                <button class="btn btn-primary" onclick="location.reload()">OK</button>
            </div>
        </div>

    </div>
</div>

<style>
.herdar-opcoes {
    display: flex;
    flex-direction: column;
    gap: .4rem;
    padding: .5rem .75rem;
    background: #f0f4ff;
    border-radius: 8px;
    border: 1px solid #c7d2fe;
    margin-bottom: .5rem;
}
.herdar-divisor {
    height: 1px;
    background: #e0e0e0;
    margin: .4rem 0;
}
</style>

<script>
let modalAlbumId   = '';
let modalAlbumNome = '';

function toggleHerdar(el) {
    document.getElementById('lista-origens').style.display =
        el.checked ? 'flex' : 'none';
}

function confirmarDeletar(id, nome) {
    modalAlbumId   = id;
    modalAlbumNome = nome;
    document.getElementById('modal-titulo').textContent = 'Deletar "' + nome + '"';
    mostrarEtapa(1);
    document.getElementById('modal-deletar').style.display = 'flex';
}

function fecharModal() {
    document.getElementById('modal-deletar').style.display = 'none';
    mostrarEtapa(1);
}

function mostrarEtapa(n) {
    [1,2,3,4].forEach(i => {
        document.getElementById('modal-etapa' + i).style.display = i === n ? 'block' : 'none';
    });
}

async function avancarModal() {
    const acao = document.querySelector('input[name="acao_modal"]:checked').value;

    if (acao === 'descartar') {
        mostrarEtapa(3);
        document.getElementById('modal-progresso-msg').textContent = 'Descartando álbum...';

        const resp = await fetch('/api/albuns', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=descartar&album_id=${modalAlbumId}`
        });
        const data = await resp.json();

        mostrarEtapa(4);
        document.getElementById('modal-resultado').innerHTML =
            '<p style="color:#666">Álbum deletado. Todas as figurinhas foram descartadas.</p>';
        return;
    }

    mostrarEtapa(3);
    document.getElementById('modal-progresso-msg').textContent = 'Carregando álbuns...';

    const resp = await fetch('/api/albuns', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `acao=preview&album_id=${modalAlbumId}`
    });
    const data = await resp.json();

    const lista = document.getElementById('modal-lista-albuns');
    lista.innerHTML = '';
    data.albuns.forEach(a => {
        lista.innerHTML += `
            <label class="checkbox-label">
                <input type="checkbox" name="album_sobras[]"
                       class="album-destino" value="${a.id}">
                ${a.nome}
                <span style="color:#888;font-size:.8rem">
                    (${a.total_repetidas} repetidas)
                </span>
            </label>`;
    });
    mostrarEtapa(2);
}

function voltarModal() { mostrarEtapa(1); }

async function confirmarDistribuir() {
    const albumSobrasSelecionados = [...document.querySelectorAll('input[name="album_sobras[]"]:checked')]
        .map(el => el.value);
    const albumSobras = encodeURIComponent(albumSobrasSelecionados.join(','));

    mostrarEtapa(3);

    const msgs = [
        'Distribuindo figurinhas...',
        'Verificando álbuns...',
        'Enviando sobras...',
        'Recalculando estatísticas...',
    ];
    let msgIdx = 0;
    const intervalo = setInterval(() => {
        msgIdx = (msgIdx + 1) % msgs.length;
        document.getElementById('modal-progresso-msg').textContent = msgs[msgIdx];
    }, 800);

    const resp = await fetch('/api/albuns', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `acao=distribuir&album_id=${encodeURIComponent(modalAlbumId)}&album_sobras=${albumSobras}`
    });
    const data = await resp.json();

    clearInterval(intervalo);
    mostrarEtapa(4);
    document.getElementById('modal-resultado').innerHTML = `
        <p>✓ <strong>${data.distribuidas}</strong> figurinhas distribuídas entre os álbuns</p>
        ${data.sobras > 0 ? `<p>✓ <strong>${data.sobras}</strong> sobras enviadas para o álbum selecionado</p>` : ''}
    `;
}
</script>

<?php layoutFim(); ?>

<?php
function recalcularAlbum(PDO $db, string $t, string $albumId, string $usuarioId): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$t}figurinhas");
    $stmt->execute();
    $totalFigurinhas = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM {$t}inventario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $totalTem = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade-1,0)),0)
        FROM {$t}inventario WHERE album_id = :aid
    ");
    $stmt->execute([':aid' => $albumId]);
    $totalRep = (int) $stmt->fetchColumn();

    $pct  = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $falt = $totalFigurinhas - $totalTem;

    $db->prepare("
        UPDATE {$t}albuns SET
            percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $pct, ':falt' => $falt, ':rep' => $totalRep, ':id' => $albumId]);
}
?>
