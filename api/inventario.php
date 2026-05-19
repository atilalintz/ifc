<?php
// api/inventario.php — Atualiza quantidade ou reserva de figurinha via AJAX
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json');
requireLogin();

$usuario = usuarioLogado();
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

$albumId     = $_POST['album_id']     ?? '';
$figurinhaId = $_POST['figurinha_id'] ?? '';
$acao        = $_POST['acao']         ?? ''; // 'incrementar', 'decrementar', 'reservar', 'desreservar'

if (!$albumId || !$figurinhaId || !in_array($acao, ['incrementar', 'decrementar', 'reservar', 'desreservar'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros inválidos']);
    exit;
}

// Verifica se o álbum pertence ao utilizador
$stmt = $db->prepare("SELECT id FROM ifc_albuns WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['erro' => 'Álbum não encontrado']);
    exit;
}

// Procura o registo atual no inventário
$stmt = $db->prepare("
    SELECT id, quantidade, quantidade_bloqueada FROM ifc_inventario
    WHERE album_id = :aid AND figurinha_id = :fid
");
$stmt->execute([':aid' => $albumId, ':fid' => $figurinhaId]);
$registro = $stmt->fetch();

$novaQtd = $registro ? (int)$registro['quantidade'] : 0;

// PROCESSAMENTO DAS AÇÕES DE RESERVA (NOVO)
if ($acao === 'reservar' || $acao === 'desreservar') {
    $valorBloqueio = ($acao === 'reservar') ? 1 : 0;

    if ($registro) {
        $db->prepare("
            UPDATE ifc_inventario 
            SET quantidade_bloqueada = :bloqueio 
            WHERE id = :id
        ")->execute([':bloqueio' => $valorBloqueio, ':id' => $registro['id']]);
    } else {
        $db->prepare("
            INSERT INTO ifc_inventario (id, album_id, usuario_id, figurinha_id, quantidade, quantidade_bloqueada)
            VALUES (UUID(), :aid, :uid, :fid, 0, :bloqueio)
        ")->execute([
            ':aid'      => $albumId, 
            ':uid'      => $usuario['id'], 
            ':fid'      => $figurinhaId,
            ':bloqueio' => $valorBloqueio
        ]);
    }

    echo json_encode(['sucesso' => true]);
    exit;
}

// PROCESSAMENTO DAS AÇÕES DE QUANTIDADE
if ($acao === 'incrementar') {
    if ($registro) {
        $db->prepare("
            UPDATE ifc_inventario SET quantidade = quantidade + 1
            WHERE id = :id
        ")->execute([':id' => $registro['id']]);
        $novaQtd = $registro['quantidade'] + 1;
    } else {
        $db->prepare("
            INSERT INTO ifc_inventario (id, album_id, usuario_id, figurinha_id, quantidade, quantidade_bloqueada)
            VALUES (UUID(), :aid, :uid, :fid, 1, 0)
        ")->execute([':aid' => $albumId, ':uid' => $usuario['id'], ':fid' => $figurinhaId]);
        $novaQtd = 1;
    }
} else if ($acao === 'decrementar') {
    $novaQtd = max(0, ($registro['quantidade'] ?? 0) - 1);
    if ($registro) {
        $db->prepare("
            UPDATE ifc_inventario SET quantidade = :qtd WHERE id = :id
        ")->execute([':qtd' => $novaQtd, ':id' => $registro['id']]);
    }
}

// Recalcula estatísticas do álbum
$stmt = $db->prepare("SELECT COUNT(*) FROM ifc_figurinhas");
$stmt->execute();
$totalFigurinhas = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM ifc_inventario
    WHERE album_id = :aid AND quantidade > 0
");
$stmt->execute([':aid' => $albumId]);
$totalTem = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
    FROM ifc_inventario WHERE album_id = :aid
");
$stmt->execute([':aid' => $albumId]);
$totalRepetidas = (int) $stmt->fetchColumn();

$percentual  = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
$totalFaltam = $totalFigurinhas - $totalTem;

$db->prepare("
    UPDATE ifc_albuns SET
        percentual_conclusao = :pct,
        total_faltantes      = :falt,
        total_repetidas      = :rep
    WHERE id = :id
")->execute([
    ':pct'  => $percentual,
    ':falt' => $totalFaltam,
    ':rep'  => $totalRepetidas,
    ':id'   => $albumId,
]);

echo json_encode([
    'quantidade'  => $novaQtd,
    'percentual'  => $percentual,
    'faltantes'   => $totalFaltam,
    'repetidas'   => $totalRepetidas,
]);
exit;
