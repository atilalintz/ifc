<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();
$t       = TBL;

// ─────────────────────────────────────────────
// DELETAR ÁLBUM
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['deletar_id'])) {
    $albumDeletarId = $_POST['deletar_id'];
    $acao           = $_POST['acao_deletar'] ?? 'descartar';

    $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
    $stmt->execute([':id' => $albumDeletarId, ':uid' => $usuario['id']]);

    if ($stmt->fetch()) {
        if ($acao === 'distribuir') {
            // Busca todos os outros álbuns do usuário
            $stmt = $db->prepare("
                SELECT id, total_faltantes, total_repetidas
                FROM {$t}albuns
                WHERE usuario_id = :uid AND ativo = 1 AND id != :aid
                ORDER BY total_faltantes ASC
            ");
            $stmt->execute([':uid' => $usuario['id'], ':aid' => $albumDeletarId]);
            $outrosAlbuns = $stmt->fetchAll();

            if (!empty($outrosAlbuns)) {
                // Busca todas as figurinhas do álbum a deletar
                $stmt = $db->prepare("
                    SELECT figurinha_id, quantidade
                    FROM {$t}inventario
                    WHERE album_id = :aid AND quantidade > 0
                ");
                $stmt->execute([':aid' => $albumDeletarId]);
                $figurinhas = $stmt->fetchAll();

                foreach ($figurinhas as $fig) {
                    $fid     = $fig['figurinha_id'];
                    $qtdDisp = $fig['quantidade'];

                    // Álbuns que precisam desta figurinha (qtd = 0)
                    $precisam = [];
                    foreach ($outrosAlbuns as $outro) {
                        $s = $db->prepare("
                            SELECT quantidade FROM {$t}inventario
                            WHERE album_id = :aid AND figurinha_id = :fid
                        ");
                        $s->execute([':aid' => $outro['id'], ':fid' => $fid]);
                        $reg = $s->fetchColumn();
                        if ($reg === false || (int)$reg === 0) {
                            $precisam[] = $outro;
                        }
                    }

                    // Ordena por menos faltantes (quase completo primeiro)
                    usort($precisam, fn($a, $b) => $a['total_faltantes'] <=> $b['total_faltantes']);

                    // Distribui 1 para cada álbum que precisa
                    foreach ($precisam as $destino) {
                        if ($qtdDisp <= 0) break;

                        $db->prepare("
                            INSERT INTO {$t}inventario (id, album_id, usuario_id, figurinha_id, quantidade)
                            VALUES (UUID(), :aid, :uid, :fid, 1)
                            ON DUPLICATE KEY UPDATE quantidade = quantidade + 1
                        ")->execute([
                            ':aid' => $destino['id'],
                            ':uid' => $usuario['id'],
                            ':fid' => $fid,
                        ]);
                        $qtdDisp--;
                        recalcularAlbum($db, $t, $destino['id'], $usuario['id']);
                    }

                    // Sobrou? → vai pro álbum com mais repetidas
                    if ($qtdDisp > 0) {
                        $maisRepetidas = $outrosAlbuns;
                        usort($maisRepetidas, fn($a, $b) => $b['total_repetidas'] <=> $a['total_repetidas']);
                        $destino = $maisRepetidas[0];

                        $db->prepare("
                            INSERT INTO {$t}inventario (id, album_id, usuario_id, figurinha_id, quantidade)
                            VALUES (UUID(), :aid, :uid, :fid, :qtd)
                            ON DUPLICATE KEY UPDATE quantidade = quantidade + :qtd2
                        ")->execute([
                            ':aid'  => $destino['id'],
                            ':uid'  => $usuario['id'],
                            ':fid'  => $fid,
                            ':qtd'  => $qtdDisp,
                            ':qtd2' => $qtdDisp,
                        ]);
                        recalcularAlbum($db, $t, $destino['id'], $usuario['id']);
                    }
                }
            }
        }

        // Deleta o álbum (CASCADE apaga o inventário)
        $db->prepare("DELETE FROM {$t}albuns WHERE id = :id AND usuario_id = :uid")
           ->execute([':id' => $albumDeletarId, ':uid' => $usuario['id']]);
    }

    header('Location: /albuns');
    exit;
}

// ─────────────────────────────────────────────
// CRIAR ÁLBUM
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['nome'])) {
    $id   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $nome = mb_substr(trim($_POST['nome']), 0, 20);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome)) . '-' . substr($id, 0, 8);

    $db->prepare("
        INSERT INTO {$t}albuns (id, usuario_id, nome, slug_publico)
        VALUES (:id, :uid, :nome, :slug)
    ")->execute([':id' => $id, ':uid' => $usuario['id'], ':nome' => $nome, ':slug' => $slug]);

    // Herda repetidas de múltiplos álbuns?
    $albumOrigens = $_POST['album_origens'] ?? [];
    if (!empty($albumOrigens)) {
        $origensValidas = [];
        foreach ($albumOrigens as $albumOrigem) {
            $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':id' => $albumOrigem, ':uid' => $usuario['id']]);
            if ($stmt->fetch()) $origensValidas[] = $albumOrigem;
        }

        if (!empty($origensValidas)) {
            $placeholders = implode(',', array_fill(0, count($origensValidas), '?'));
            $stmt = $db->prepare("
                SELECT figurinha_id, album_id, id, quantidade
                FROM {$t}inventario
                WHERE album_id IN ($placeholders) AND quantidade >= 2
                ORDER BY quantidade DESC
            ");
            $stmt->execute($origensValidas);
            $todasRepetidas = $stmt->fetchAll();

            $porFigurinha = [];
            $roundRobin   = [];

            foreach ($todasRepetidas as $rep) {
                $fid = $rep['figurinha_id'];
                if (!isset($porFigurinha[$fid])) {
                    $porFigurinha[$fid] = [];
                }
                $porFigurinha[$fid][] = $rep;
            }

            foreach ($porFigurinha as $fid => $registros) {
                usort($registros, fn($a, $b) => $b['quantidade'] <=> $a['quantidade']);

                $melhor = $registros[0];
                if (count($registros) > 1 && $registros[0]['quantidade'] === $registros[1]['quantidade']) {
                    $idx              = $roundRobin[$fid] ?? 0;
                    $melhor           = $registros[$idx % count($registros)];
                    $roundRobin[$fid] = $idx + 1;
                }

                $qtdHerdar = $melhor['quantidade'] - 1;

                $db->prepare("
                    INSERT INTO {$t}inventario (id, album_id, usuario_id, figurinha_id, quantidade)
                    VALUES (UUID(), :aid, :uid, :fid, :qtd)
                    ON DUPLICATE KEY UPDATE quantidade = quantidade + :qtd2
                ")->execute([
                    ':aid'  => $id,
                    ':uid'  => $usuario['id'],
                    ':fid'  => $fid,
                    ':qtd'  => $qtdHerdar,
                    ':qtd2' => $qtdHerdar,
                ]);

                $db->prepare("
                    UPDATE {$t}inventario SET quantidade = 1 WHERE id = :id
                ")->execute([':id' => $melhor['id']]);

                recalcularAlbum($db, $t, $melhor['album_id'], $usuario['id']);
            }

            recalcularAlbum($db, $t, $id, $usuario['id']);
        }
    }

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
                    🗑
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

        <?php if (!empty($albuns)): ?>
        <div class="form-herdar">
            <label class="checkbox-label">
                <input type="checkbox" id="chk-herdar" onchange="toggleHerdar(this)">
                Herdar repetidas de outros álbuns
            </label>
            <div id="lista-origens" class="lista-origens" style="display:none;">
                <?php
                $temRepetidas = array_filter($albuns, fn($a) => $a['total_repetidas'] > 0);
                if (!empty($temRepetidas)):
                    foreach ($albuns as $a):
                        if ($a['total_repetidas'] > 0):
                ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="album_origens[]" value="<?= $a['id'] ?>">
                        <?= htmlspecialchars($a['nome']) ?>
                        <span style="color:#888;font-size:.8rem">
                            (<?= $a['total_repetidas'] ?> repetidas)
                        </span>
                    </label>
                <?php
                        endif;
                    endforeach;
                else:
                ?>
                    <p style="font-size:.85rem;color:#888;">
                        Nenhum álbum com repetidas no momento.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="margin-top:.75rem">
            Criar álbum
        </button>
    </form>
</div>

<!-- Modal deletar -->
<div id="modal-deletar" class="modal-overlay" style="display:none">
    <div class="modal-box">
        <h3 id="modal-titulo">Deletar álbum</h3>
        <p id="modal-msg" style="margin:.75rem 0;font-size:.9rem;color:#555;"></p>
        <form method="POST" action="/albuns">
            <input type="hidden" name="deletar_id" id="modal-album-id">
            <div class="modal-opcoes">
                <label class="radio-label">
                    <input type="radio" name="acao_deletar" value="distribuir" checked>
                    Distribuir figurinhas entre outros álbuns
                </label>
                <label class="radio-label">
                    <input type="radio" name="acao_deletar" value="descartar">
                    Descartar tudo (vendi o álbum)
                </label>
            </div>
            <div class="modal-acoes">
                <button type="submit" class="btn btn-primary">Confirmar</button>
                <button type="button" class="btn btn-sm" onclick="fecharModal()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleHerdar(chk) {
    document.getElementById('lista-origens').style.display = chk.checked ? 'block' : 'none';
}
function confirmarDeletar(id, nome) {
    document.getElementById('modal-album-id').value = id;
    document.getElementById('modal-titulo').textContent = 'Deletar "' + nome + '"';
    document.getElementById('modal-msg').textContent =
        'O que deseja fazer com as figurinhas deste álbum?';
    document.getElementById('modal-deletar').style.display = 'flex';
}
function fecharModal() {
    document.getElementById('modal-deletar').style.display = 'none';
}
</script>

<?php layoutFim(); ?>

<?php
// ─────────────────────────────────────────────
// Helper: recalcula estatísticas do álbum
// ─────────────────────────────────────────────
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
