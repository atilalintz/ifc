<?php
// api/inventario.php — Atualiza quantidade de figurinha via AJAX
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json');
requireLogin();
validarCsrf();

$usuario = usuarioLogado();
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

$albumId     = $_POST['album_id']     ?? '';
$figurinhaId = $_POST['figurinha_id'] ?? '';
$acao        = $_POST['acao']         ?? '';

$acoesValidas = ['incrementar', 'decrementar', 'zerar', 'reservar', 'desreservar'];
if (!$albumId || !$figurinhaId || !in_array($acao, $acoesValidas)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros inválidos']);
    exit;
}

// Verifica se o álbum pertence ao usuário
$stmt = $db->prepare("SELECT id FROM " . tbl('albuns') . " WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['erro' => 'Álbum não encontrado']);
    exit;
}

// Busca registro atual
$stmt = $db->prepare("
    SELECT id, quantidade, quantidade_bloqueada
    FROM " . tbl('inventario') . "
    WHERE album_id = :aid AND figurinha_id = :fid
");
$stmt->execute([':aid' => $albumId, ':fid' => $figurinhaId]);
$registro = $stmt->fetch();

$novaQtd = $registro ? (int)$registro['quantidade'] : 0;

// ── Reservar / Desreservar (legado — mantido por compatibilidade) ─────────────
if ($acao === 'reservar' || $acao === 'desreservar') {
    $valorBloqueio = ($acao === 'reservar') ? 1 : 0;
    if ($registro) {
        $db->prepare("
            UPDATE " . tbl('inventario') . "
            SET quantidade_bloqueada = :bloqueio WHERE id = :id
        ")->execute([':bloqueio' => $valorBloqueio, ':id' => $registro['id']]);
    } else {
        $db->prepare("
            INSERT INTO " . tbl('inventario') . "
                (id, album_id, usuario_id, figurinha_id, quantidade, quantidade_bloqueada)
            VALUES (UUID(), :aid, :uid, :fid, 0, :bloqueio)
        ")->execute([
            ':aid' => $albumId, ':uid' => $usuario['id'],
            ':fid' => $figurinhaId, ':bloqueio' => $valorBloqueio,
        ]);
    }
    echo json_encode(['sucesso' => true]);
    exit;
}

// ── Incrementar ───────────────────────────────────────────────────────────────
if ($acao === 'incrementar') {
    if ($registro) {
        $db->prepare("
            UPDATE " . tbl('inventario') . "
            SET quantidade = quantidade + 1 WHERE id = :id
        ")->execute([':id' => $registro['id']]);
        $novaQtd = $registro['quantidade'] + 1;
    } else {
        $db->prepare("
            INSERT INTO " . tbl('inventario') . "
                (id, album_id, usuario_id, figurinha_id, quantidade, quantidade_bloqueada)
            VALUES (UUID(), :aid, :uid, :fid, 1, 0)
        ")->execute([':aid' => $albumId, ':uid' => $usuario['id'], ':fid' => $figurinhaId]);
        $novaQtd = 1;
    }
}

// ── Decrementar ───────────────────────────────────────────────────────────────
if ($acao === 'decrementar') {
    $novaQtd = max(0, $novaQtd - 1);
    if ($registro) {
        $db->prepare("
            UPDATE " . tbl('inventario') . "
            SET quantidade = :qtd WHERE id = :id
        ")->execute([':qtd' => $novaQtd, ':id' => $registro['id']]);
    }
}

// ── Zerar ─────────────────────────────────────────────────────────────────────
if ($acao === 'zerar') {
    $novaQtd = 0;
    if ($registro) {
        $db->prepare("
            UPDATE " . tbl('inventario') . "
            SET quantidade = 0 WHERE id = :id
        ")->execute([':id' => $registro['id']]);
    }
    // Se não existe registro, quantidade já é 0 — nada a fazer
}

// ── Recalcula estatísticas do álbum ──────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) FROM " . tbl('figurinhas'));
$stmt->execute();
$totalFigurinhas = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM " . tbl('inventario') . "
    WHERE album_id = :aid AND quantidade > 0
");
$stmt->execute([':aid' => $albumId]);
$totalTem = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
    FROM " . tbl('inventario') . " WHERE album_id = :aid
");
$stmt->execute([':aid' => $albumId]);
$totalRepetidas = (int) $stmt->fetchColumn();

$percentual  = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
$totalFaltam = $totalFigurinhas - $totalTem;

$db->prepare("
    UPDATE " . tbl('albuns') . " SET
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
    'quantidade' => $novaQtd,
    'percentual' => $percentual,
    'faltantes'  => $totalFaltam,
    'repetidas'  => $totalRepetidas,
]);
exit;
