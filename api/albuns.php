<?php
// api/albuns.php — Processa deleção com distribuição via AJAX
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json');
requireLogin();

$usuario = usuarioLogado();
$db      = getDB();
$t       = TBL;

$acao = $_POST['acao'] ?? '';

// ─────────────────────────────────────────────
// PREVIEW — quantas figurinhas serão distribuídas
// ─────────────────────────────────────────────
if ($acao === 'preview') {
    $albumId = $_POST['album_id'] ?? '';

    $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
    $stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
    if (!$stmt->fetch()) { echo json_encode(['erro' => 'Álbum não encontrado']); exit; }

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM {$t}inventario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $totalFigurinhas = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT a.id, a.nome, a.total_faltantes, a.total_repetidas
        FROM {$t}albuns a
        WHERE a.usuario_id = :uid
          AND a.ativo = 1
          AND a.id != :aid
        ORDER BY a.total_repetidas DESC
    ");
    $stmt->execute([':uid' => $usuario['id'], ':aid' => $albumId]);
    $albuns = $stmt->fetchAll();

    echo json_encode(['total' => $totalFigurinhas, 'albuns' => $albuns]);
    exit;
}

// ─────────────────────────────────────────────
// DISTRIBUIR — distribuição inteligente
// ─────────────────────────────────────────────
if ($acao === 'distribuir') {
    $albumId      = $_POST['album_id']       ?? '';
    $destinosRaw  = $_POST['album_destinos']  ?? '';

    // Álbuns destino selecionados pelo usuário (vazio = todos)
    $destinosFiltro = array_values(array_filter(
        array_map('trim', explode(',', $destinosRaw))
    ));

    $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
    $stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
    if (!$stmt->fetch()) { echo json_encode(['erro' => 'Álbum não encontrado']); exit; }

    // Busca álbuns destino — filtra pelos selecionados se houver
    $sqlFiltro = !empty($destinosFiltro)
        ? "AND id IN (" . implode(',', array_fill(0, count($destinosFiltro), '?')) . ")"
        : '';

    $stmt = $db->prepare("
        SELECT id, total_faltantes, total_repetidas
        FROM {$t}albuns
        WHERE usuario_id = ?
          AND ativo = 1
          AND id != ?
          $sqlFiltro
        ORDER BY total_faltantes DESC
    ");
    $params = [$usuario['id'], $albumId, ...$destinosFiltro];
    $stmt->execute($params);
    $destinos = $stmt->fetchAll();

    if (empty($destinos)) {
        echo json_encode(['erro' => 'Nenhum álbum destino encontrado']);
        exit;
    }

    // Busca todas as figurinhas do álbum a deletar
    $stmt = $db->prepare("
        SELECT figurinha_id, quantidade
        FROM {$t}inventario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $figurinhas = $stmt->fetchAll();

    $db->beginTransaction();

    try {
        $distribuidas      = 0;
        $albumsAtualizados = [];

        // Índice para round-robin nas sobras
        $rrIdx = 0;

        foreach ($figurinhas as $fig) {
            $fid     = $fig['figurinha_id'];
            $qtdDisp = (int) $fig['quantidade'];

            // ── Passo 1: distribui para álbuns que precisam (têm 0) ──────────
            // Ordena destinos por mais incompleto a cada figurinha
            usort($destinos, fn($a, $b) => $b['total_faltantes'] <=> $a['total_faltantes']);

            foreach ($destinos as &$destino) {
                if ($qtdDisp <= 0) break;

                // Verifica se este álbum tem esta figurinha
                $s = $db->prepare("
                    SELECT quantidade FROM {$t}inventario
                    WHERE album_id = :aid AND figurinha_id = :fid
                ");
                $s->execute([':aid' => $destino['id'], ':fid' => $fid]);
                $qtdDestino = (int) ($s->fetchColumn() ?: 0);

                if ($qtdDestino === 0) {
                    // Álbum precisa → manda 1
                    $db->prepare("
                        INSERT INTO {$t}inventario
                            (id, album_id, usuario_id, figurinha_id, quantidade)
                        VALUES (UUID(), :aid, :uid, :fid, 1)
                        ON DUPLICATE KEY UPDATE quantidade = quantidade + 1
                    ")->execute([
                        ':aid' => $destino['id'],
                        ':uid' => $usuario['id'],
                        ':fid' => $fid,
                    ]);
                    $qtdDisp--;
                    $distribuidas++;
                    $destino['total_faltantes']  = max(0, $destino['total_faltantes'] - 1);
                    $albumsAtualizados[$destino['id']] = true;
                }
            }
            unset($destino);

            // ── Passo 2: sobras → round-robin entre álbuns destino ───────────
            // Cada sobra vai para o próximo álbum em sequência (distribui igualmente)
            while ($qtdDisp > 0) {
                $destinoRR = &$destinos[$rrIdx % count($destinos)];
                $rrIdx++;

                $db->prepare("
                    INSERT INTO {$t}inventario
                        (id, album_id, usuario_id, figurinha_id, quantidade)
                    VALUES (UUID(), :aid, :uid, :fid, 1)
                    ON DUPLICATE KEY UPDATE quantidade = quantidade + 1
                ")->execute([
                    ':aid' => $destinoRR['id'],
                    ':uid' => $usuario['id'],
                    ':fid' => $fid,
                ]);
                $qtdDisp--;
                $distribuidas++;
                $destinoRR['total_repetidas']++;
                $albumsAtualizados[$destinoRR['id']] = true;
                unset($destinoRR);
            }
        }

        // Recalcula estatísticas de todos os álbuns atualizados
        foreach (array_keys($albumsAtualizados) as $aid) {
            recalcularAlbum($db, $t, $aid, $usuario['id']);
        }

        // Remove inventário e álbum deletado
        $db->prepare("DELETE FROM {$t}inventario WHERE album_id = :aid")
           ->execute([':aid' => $albumId]);

        $db->prepare("DELETE FROM {$t}albuns WHERE id = :id AND usuario_id = :uid")
           ->execute([':id' => $albumId, ':uid' => $usuario['id']]);

        $db->commit();

        echo json_encode([
            'sucesso'      => true,
            'distribuidas' => $distribuidas,
        ]);

    } catch (Throwable $e) {
        $db->rollBack();
        echo json_encode(['erro' => 'Erro interno: ' . $e->getMessage()]);
    }

    exit;
}

// ─────────────────────────────────────────────
// DESCARTAR — só deleta
// ─────────────────────────────────────────────
if ($acao === 'descartar') {
    $albumId = $_POST['album_id'] ?? '';

    $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
    $stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
    if (!$stmt->fetch()) { echo json_encode(['erro' => 'Álbum não encontrado']); exit; }

    $db->prepare("DELETE FROM {$t}inventario WHERE album_id = :aid")
       ->execute([':aid' => $albumId]);

    $db->prepare("DELETE FROM {$t}albuns WHERE id = :id AND usuario_id = :uid")
       ->execute([':id' => $albumId, ':uid' => $usuario['id']]);

    echo json_encode(['sucesso' => true]);
    exit;
}

echo json_encode(['erro' => 'Ação inválida']);

function recalcularAlbum(PDO $db, string $t, string $albumId, string $usuarioId): void
{
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
