<?php
// api/scanner.php — Valida códigos e confirma figurinhas detectadas
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
requireLogin();
validarCsrf();

$usuario = usuarioLogado();
$db      = getDB();
$albumId = $_POST['album_id'] ?? '';

// CORREÇÃO: ifc_albuns
$stmt = $db->prepare("SELECT id FROM ifc_albuns WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['erro' => 'Álbum não encontrado']);
    exit;
}

// ─────────────────────────────────────────────
// VALIDAR CÓDIGOS
// ─────────────────────────────────────────────
if (!empty($_POST['codigos'])) {
    $codigos = json_decode($_POST['codigos'], true);
    if (!is_array($codigos)) {
        echo json_encode(['validos' => []]);
        exit;
    }

    $validos = [];
    // CORREÇÃO: ifc_figurinhas e ifc_selecoes
    $stmt = $db->prepare("
        SELECT f.id, f.codigo, s.nome AS selecao_nome
        FROM ifc_figurinhas f
        JOIN ifc_selecoes s ON s.id = f.selecao_id
        WHERE f.codigo = :codigo
    ");

    foreach ($codigos as $codigo) {
        $stmt->execute([':codigo' => strtoupper(trim($codigo))]);
        $fig = $stmt->fetch();
        if ($fig) {
            $validos[] = [
                'id'     => $fig['id'],
                'codigo' => $fig['codigo'],
                'nome'   => $fig['selecao_nome'],
            ];
        }
    }

    echo json_encode(['validos' => $validos]);
    exit;
}

// ─────────────────────────────────────────────
// CONFIRMAR FIGURINHAS
// ─────────────────────────────────────────────
if (!empty($_POST['confirmar']) && !empty($_POST['itens'])) {
    $itens = json_decode($_POST['itens'], true);
    if (!is_array($itens)) {
        echo json_encode(['sucesso' => false]);
        exit;
    }

    // CORREÇÃO: ifc_inventario
    $stmtVerifica = $db->prepare("
        SELECT id, quantidade FROM ifc_inventario
        WHERE album_id = :aid AND figurinha_id = :fid
    ");
    
    // CORREÇÃO: ifc_inventario
    $stmtInsert = $db->prepare("
        INSERT INTO ifc_inventario (id, album_id, usuario_id, figurinha_id, quantidade)
        VALUES (UUID(), :aid, :uid, :fid, :qtd)
    ");
    
    // CORREÇÃO: ifc_inventario
    $stmtUpdate = $db->prepare("
        UPDATE ifc_inventario SET quantidade = quantidade + :qtd WHERE id = :id
    ");
    
    // CORREÇÃO: ifc_figurinhas
    $stmtFig = $db->prepare("SELECT id FROM ifc_figurinhas WHERE codigo = :codigo");

    foreach ($itens as $item) {
        $stmtFig->execute([':codigo' => $item['codigo']]);
        $figId = $stmtFig->fetchColumn();
        if (!$figId) continue;

        $qtd = max(1, (int)($item['qtd'] ?? 1));

        $stmtVerifica->execute([':aid' => $albumId, ':fid' => $figId]);
        $registro = $stmtVerifica->fetch();

        if ($registro) {
            $stmtUpdate->execute([':qtd' => $qtd, ':id' => $registro['id']]);
        } else {
            $stmtInsert->execute([
                ':aid' => $albumId,
                ':uid' => $usuario['id'],
                ':fid' => $figId,
                ':qtd' => $qtd,
            ]);
        }
    }

    // Recalcula estatísticas
    // CORREÇÃO: ifc_figurinhas
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM ifc_figurinhas");
    $stmtTotal->execute();
    $totalFigurinhas = (int) $stmtTotal->fetchColumn();

    // CORREÇÃO: ifc_inventario
    $stmtTem = $db->prepare("
        SELECT COUNT(*) FROM ifc_inventario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmtTem->execute([':aid' => $albumId]);
    $totalTem = (int) $stmtTem->fetchColumn();

    $percentual = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $faltantes  = $totalFigurinhas - $totalTem;

    // CORREÇÃO: ifc_inventario
    $stmtRep = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
        FROM ifc_inventario WHERE album_id = :aid
    ");
    $stmtRep->execute([':aid' => $albumId]);
    $totalRep = (int) $stmtRep->fetchColumn();

    // CORREÇÃO: ifc_albuns
    $db->prepare("
        UPDATE ifc_albuns SET
            percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $percentual, ':falt' => $faltantes, ':rep' => $totalRep, ':id' => $albumId]);

    echo json_encode(['sucesso' => true]);
    exit;
}

echo json_encode(['erro' => 'Ação inválida']);
exit;
