<?php
// api/csv.php — Importação e exportação de inventário via CSV
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

requireLogin();

$usuario = usuarioLogado();
$db      = getDB();
$acao    = $_GET['acao']     ?? '';
$albumId = $_GET['album_id'] ?? '';

// Verifica posse do álbum
$stmt = $db->prepare("SELECT id, nome FROM " . tbl('albuns') . " WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
$album = $stmt->fetch();
if (!$album) {
    http_response_code(403);
    die('Álbum não encontrado');
}

// ─────────────────────────────────────────────
// EXPORTAR — GET, não precisa de CSRF
// ─────────────────────────────────────────────
if ($acao === 'exportar') {
    $nomeArquivo = 'ifc-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($album['nome'])) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    $stmt = $db->prepare("
        SELECT f.codigo,
               COALESCE(i.quantidade, 0) AS quantidade
        FROM " . tbl('figurinhas') . " f
        LEFT JOIN " . tbl('inventario') . " i
            ON i.figurinha_id = f.id AND i.album_id = :aid
        ORDER BY f.codigo
    ");
    $stmt->execute([':aid' => $albumId]);
    $figurinhas = $stmt->fetchAll();

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    fputcsv($out, ['Codigo','Quantidade','Codigo','Quantidade','Codigo','Quantidade','Codigo','Quantidade'], ';');

    $linha = [];
    foreach ($figurinhas as $fig) {
        // Exporta quantidade real; 0 e 1 viram 0 (só exporta repetidas)
        $qtdExportada = (int)$fig['quantidade'] >= 2 ? (int)$fig['quantidade'] : 0;
        $linha[] = $fig['codigo'];
        $linha[] = $qtdExportada;

        if (count($linha) === 8) {
            fputcsv($out, $linha, ';');
            $linha = [];
        }
    }

    if (!empty($linha)) {
        while (count($linha) < 8) $linha[] = '';
        fputcsv($out, $linha, ';');
    }

    fclose($out);
    exit;
}

// ─────────────────────────────────────────────
// IMPORTAR — POST, valida CSRF
// ─────────────────────────────────────────────
if ($acao === 'importar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();
    header('Content-Type: application/json');

    if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['erro' => 'Arquivo inválido']);
        exit;
    }

    $handle   = fopen($_FILES['arquivo']['tmp_name'], 'r');
    $total    = 0;
    $erros    = 0;
    $primeira = true;

    // Remove BOM se existir
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $stmtBusca = $db->prepare("
        SELECT id FROM " . tbl('figurinhas') . " WHERE codigo = :codigo
    ");
    $stmtVerifica = $db->prepare("
        SELECT id FROM " . tbl('inventario') . "
        WHERE album_id = :aid AND figurinha_id = :fid
    ");
    $stmtInsert = $db->prepare("
        INSERT INTO " . tbl('inventario') . " (id, album_id, usuario_id, figurinha_id, quantidade)
        VALUES (UUID(), :aid, :uid, :fid, :qtd)
    ");
    $stmtUpdate = $db->prepare("
        UPDATE " . tbl('inventario') . " SET quantidade = :qtd WHERE id = :id
    ");

    while (($linha = fgetcsv($handle, 0, ';')) !== false) {
        if ($primeira) { $primeira = false; continue; }

        for ($i = 0; $i + 1 < count($linha); $i += 2) {
            $codigo = strtoupper(trim($linha[$i]));
            if (empty($codigo)) continue;

            $qtdCSV  = max(0, (int) trim($linha[$i + 1]));
            $qtdReal = $qtdCSV >= 2 ? $qtdCSV : 0;

            $stmtBusca->execute([':codigo' => $codigo]);
            $figurinhaId = $stmtBusca->fetchColumn();
            if (!$figurinhaId) { $erros++; continue; }

            $stmtVerifica->execute([':aid' => $albumId, ':fid' => $figurinhaId]);
            $registroId = $stmtVerifica->fetchColumn();

            if ($registroId) {
                $stmtUpdate->execute([':qtd' => $qtdReal, ':id' => $registroId]);
            } elseif ($qtdReal > 0) {
                $stmtInsert->execute([
                    ':aid' => $albumId,
                    ':uid' => $usuario['id'],
                    ':fid' => $figurinhaId,
                    ':qtd' => $qtdReal,
                ]);
            }
            $total++;
        }
    }

    fclose($handle);

    // Recalcula estatísticas do álbum
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM " . tbl('figurinhas'));
    $stmtTotal->execute();
    $totalFigurinhas = (int) $stmtTotal->fetchColumn();

    $stmtTem = $db->prepare("
        SELECT COUNT(*) FROM " . tbl('inventario') . "
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmtTem->execute([':aid' => $albumId]);
    $totalTem = (int) $stmtTem->fetchColumn();

    $stmtRep = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
        FROM " . tbl('inventario') . " WHERE album_id = :aid
    ");
    $stmtRep->execute([':aid' => $albumId]);
    $totalRep = (int) $stmtRep->fetchColumn();

    $percentual = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $faltantes  = $totalFigurinhas - $totalTem;

    $db->prepare("
        UPDATE " . tbl('albuns') . " SET
            percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $percentual, ':falt' => $faltantes, ':rep' => $totalRep, ':id' => $albumId]);

    echo json_encode([
        'sucesso'    => true,
        'importados' => $total,
        'erros'      => $erros,
        'percentual' => $percentual,
        'faltantes'  => $faltantes,
        'repetidas'  => $totalRep,
    ]);
    exit;
}

http_response_code(400);
echo 'Ação inválida';
