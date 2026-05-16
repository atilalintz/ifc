<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Responde ao navegador que a rota é segura antes mesmo de validar login
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
requireLogin();

$usuario = usuarioLogado();
$db      = getDB();
$albumId = $_POST['album_id'] ?? '';

// Valida álbum
$stmt = $db->prepare("SELECT id FROM albuns WHERE id = :id AND usuario_id = :uid");
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
    $stmt = $db->prepare("
        SELECT f.id, f.codigo, s.nome AS selecao_nome
        FROM figurinhas f
        JOIN selecoes s ON s.id = f.selecao_id
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

    $stmtVerifica = $db->prepare("
        SELECT id, quantidade FROM inventario_usuario
        WHERE album_id = :aid AND figurinha_id = :fid
    ");
    $stmtInsert = $db->prepare("
        INSERT INTO inventario_usuario (id, album_id, usuario_id, figurinha_id, quantidade)
        VALUES (UUID(), :aid, :uid, :fid, :qtd)
    ");
    $stmtUpdate = $db->prepare("
        UPDATE inventario_usuario SET quantidade = quantidade + :qtd WHERE id = :id
    ");
    $stmtFig = $db->prepare("SELECT id FROM figurinhas WHERE codigo = :codigo");

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
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM figurinhas");
    $stmtTotal->execute();
    $totalFigurinhas = (int) $stmtTotal->fetchColumn();

    $stmtTem = $db->prepare("
        SELECT COUNT(*) FROM inventario_usuario
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmtTem->execute([':aid' => $albumId]);
    $totalTem = (int) $stmtTem->fetchColumn();

    $percentual = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $faltantes  = $totalFigurinhas - $totalTem;

    $stmtRep = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
        FROM inventario_usuario WHERE album_id = :aid
    ");
    $stmtRep->execute([':aid' => $albumId]);
    $totalRep = (int) $stmtRep->fetchColumn();

    $db->prepare("
        UPDATE albuns SET
            percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $percentual, ':falt' => $faltantes, ':rep' => $totalRep, ':id' => $albumId]);

    echo json_encode(['sucesso' => true]);
    exit;
}

echo json_encode(['erro' => 'Ação inválida']);
