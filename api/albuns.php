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

    // Busca álbuns disponíveis para receber sobras
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

    echo json_encode([
        'total'  => $totalFigurinhas,
        'albuns' => $albuns,
    ]);
    exit;
}

// ─────────────────────────────────────────────
// DISTRIBUIR — processa a distribuição
// ─────────────────────────────────────────────
if ($acao === 'distribuir') {
    $albumId      = $_POST['album_id']      ?? '';
    $albumSobrasRaw = $_POST['album_sobras'] ?? '';

	$albumSobras = array_filter(
	    array_map('trim', explode(',', $albumSobrasRaw))
	);

    $stmt = $db->prepare("SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid");
    $stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
    if (!$stmt->fetch()) { echo json_encode(['erro' => 'Álbum não encontrado']); exit; }

    // Busca outros álbuns
    $stmt = $db->prepare("
        SELECT a.id, a.total_faltantes, a.total_repetidas
        FROM {$t}albuns a
        WHERE a.usuario_id = :uid
          AND a.ativo = 1
          AND a.id != :aid
          AND EXISTS (
              SELECT 1 FROM {$t}inventario i
              WHERE i.album_id = a.id AND i.quantidade > 0
          )
        ORDER BY a.total_faltantes ASC
    ");
    $stmt->execute([':uid' => $usuario['id'], ':aid' => $albumId]);
    if (empty($albumSobras) && !empty($outrosAlbuns)) {
	    usort(
		$outrosAlbuns,
		fn($a, $b) =>
		    $b['total_repetidas'] <=> $a['total_repetidas']
	    );
	    $albumSobras = [
		$outrosAlbuns[0]['id']
	    ];
	}

    // Busca figurinhas do álbum a deletar
    $stmt = $db->prepare("
        SELECT figurinha_id, quantidade
        FROM {$t}inventario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $figurinhas = $stmt->fetchAll();

    $distribuidas = 0;
    $sobras       = 0;
    $albumsAtualizados = [];

    foreach ($figurinhas as $fig) {
        $fid     = $fig['figurinha_id'];
        $qtdDisp = $fig['quantidade'];

        // Álbuns que precisam desta figurinha
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

        // Ordena por menos faltantes
        usort($precisam, fn($a, $b) => $a['total_faltantes'] <=> $b['total_faltantes']);

        // Distribui para quem precisa
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
            $distribuidas++;
            $albumsAtualizados[$destino['id']] = true;
        }

        // Sobrou → vai pro álbum escolhido para sobras
        if ($qtdDisp > 0 && !empty($albumSobras)) {

	    $destinoSobras =
		$albumSobras[array_rand($albumSobras)];

	    $db->prepare("
		INSERT INTO {$t}inventario (
		    id,
		    album_id,
		    usuario_id,
		    figurinha_id,
		    quantidade
		)
		VALUES (
		    UUID(),
		    :aid,
		    :uid,
		    :fid,
		    :qtd
		)
		ON DUPLICATE KEY UPDATE
		    quantidade = quantidade + :qtd2
	    ")->execute([
		':aid'  => $destinoSobras,
		':uid'  => $usuario['id'],
		':fid'  => $fid,
		':qtd'  => $qtdDisp,
		':qtd2' => $qtdDisp,
	    ]);

	    $sobras += $qtdDisp;

	    $albumsAtualizados[$destinoSobras] = true;
	}
    }

    // Recalcula estatísticas
    foreach (array_keys($albumsAtualizados) as $aid) {
        recalcularAlbum($db, $t, $aid, $usuario['id']);
    }

    // Remove inventário antigo
    $db->prepare("
        DELETE FROM {$t}inventario
        WHERE album_id = :aid
    ")->execute([
        ':aid' => $albumId
    ]);

    // Deleta álbum
    $db->prepare("
        DELETE FROM {$t}albuns
        WHERE id = :id
          AND usuario_id = :uid
    ")->execute([
        ':id'  => $albumId,
        ':uid' => $usuario['id']
    ]);

    echo json_encode([
        'sucesso'      => true,
        'distribuidas' => $distribuidas,
        'sobras'       => $sobras,
    ]);
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

    $db->prepare("DELETE FROM {$t}albuns WHERE id = :id AND usuario_id = :uid")
       ->execute([':id' => $albumId, ':uid' => $usuario['id']]);

    echo json_encode(['sucesso' => true]);
    exit;
}

echo json_encode(['erro' => 'Ação inválida']);

function recalcularAlbum(PDO $db, string $t, string $albumId, string $usuarioId): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$t}figurinhas");
    $stmt->execute();
    $totalFigurinhas = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM {$t}inventario WHERE album_id = :aid AND quantidade > 0");
    $stmt->execute([':aid' => $albumId]);
    $totalTem = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(GREATEST(quantidade-1,0)),0) FROM {$t}inventario WHERE album_id = :aid");
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
