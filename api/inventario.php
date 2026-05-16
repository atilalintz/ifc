<?php
// api/inventario.php — Atualiza quantidade de figurinha via AJAX
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
$acao        = $_POST['acao']         ?? ''; // 'incrementar' ou 'decrementar'

if (!$albumId || !$figurinhaId || !in_array($acao, ['incrementar', 'decrementar'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros inválidos']);
    exit;
}

// CORREÇÃO: ifc_albuns
$stmt = $db->prepare("SELECT id FROM ifc_albuns WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['erro' => 'Álbum não encontrado']);
    exit;
}

// CORREÇÃO: ifc_inventario
$stmt = $db->prepare("
    SELECT id, quantidade FROM ifc_inventario
    WHERE album_id = :aid AND figurinha_id = :fid
");
$stmt->execute([':aid' => $albumId, ':fid' => $figurinhaId]);
$registro = $stmt->fetch();

if ($acao === 'incrementar') {
    if ($registro) {
        // CORREÇÃO: ifc_inventario
        $db->prepare("
            UPDATE ifc_inventario SET quantidade = quantidade + 1
            WHERE id = :id
        ")->execute([':id' => $registro['id']]);
        $novaQtd = $registro['quantidade'] + 1;
    } else {
        // CORREÇÃO: ifc_inventario
        $db->prepare("
            INSERT INTO ifc_inventario (id, album_id, usuario_id, figurinha_id, quantidade)
            VALUES (UUID(), :aid, :uid, :fid, 1)
        ")->execute([':aid' => $albumId, ':uid' => $usuario['id'], ':fid' => $figurinhaId]);
        $novaQtd = 1;
    }
} else { // decrementar
    $novaQtd = max(0, ($registro['quantidade'] ?? 0) - 1);
    if ($registro) {
        // CORREÇÃO: ifc_inventario
        $db->prepare("
            UPDATE ifc_inventario SET quantidade = :qtd WHERE id = :id
        ")->execute([':qtd' => $novaQtd, ':id' => $registro['id']]);
    }
}

// Recalcula estatísticas do álbum
// CORREÇÃO: ifc_figurinhas
$stmt = $db->prepare("SELECT COUNT(*) FROM ifc_figurinhas");
$stmt->execute();
$totalFigurinhas = (int) $stmt->fetchColumn();

// CORREÇÃO: ifc_inventario
$stmt = $db->prepare("
    SELECT COUNT(*) FROM ifc_inventario
    WHERE album_id = :aid AND quantidade > 0
");
$stmt->execute([':aid' => $albumId]);
$totalTem = (int) $stmt->fetchColumn();

// CORREÇÃO: ifc_inventario
$stmt = $db->prepare("
    SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
    FROM ifc_inventario WHERE album_id = :aid
");
$stmt->execute([':aid' => $albumId]);
$totalRepetidas = (int) $stmt->fetchColumn();

$percentual  = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
$totalFaltam = $totalFigurinhas - $totalTem;

// CORREÇÃO: ifc_albuns
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
