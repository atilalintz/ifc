<?php
// api/csv.php — Importação e exportação de inventário via CSV
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

requireLogin();
$usuario = usuarioLogado();
$db      = getDB();

$acao    = $_GET['acao']     ?? '';
$albumId = $_GET['album_id'] ?? '';

// Valida álbum
$stmt = $db->prepare("SELECT id, nome FROM albuns WHERE id = :id AND usuario_id = :uid");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
$album = $stmt->fetch();
if (!$album) { http_response_code(403); die('Álbum não encontrado'); }

// ─────────────────────────────────────────────
// EXPORTAR
// ─────────────────────────────────────────────
if ($acao === 'exportar') {
    $nomeArquivo = 'ifc-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($album['nome'])) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    // Busca TODAS as figurinhas com quantidade (0 se não tem)
    $stmt = $db->prepare("
        SELECT f.codigo,
               COALESCE(i.quantidade, 0) AS quantidade
        FROM figurinhas f
        LEFT JOIN inventario_usuario i
            ON i.figurinha_id = f.id AND i.album_id = :aid
        ORDER BY f.codigo
    ");
    $stmt->execute([':aid' => $albumId]);
    $figurinhas = $stmt->fetchAll();

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    // Cabeçalho 8 colunas
    fputcsv($out, ['Codigo','Quantidade','Codigo','Quantidade','Codigo','Quantidade','Codigo','Quantidade'], ';');

    // Agrupa em linhas de 4
    $linha = [];
    foreach ($figurinhas as $fig) {
        // Quantidade exportada: max(0, qtd - 1)
        // qtd=0 → faltante → exporta 0
        // qtd=1 → colada   → exporta 0
        // qtd=2 → 1 repetida → exporta 1
        $qtdExportada = (int)$fig['quantidade'] >= 2 ? (int)$fig['quantidade'] : 0;

        $linha[] = $fig['codigo'];
        $linha[] = $qtdExportada;

        if (count($linha) === 8) {
            fputcsv($out, $linha, ';');
            $linha = [];
        }
    }

    // Última linha incompleta
    if (!empty($linha)) {
        while (count($linha) < 8) $linha[] = '';
        fputcsv($out, $linha, ';');
    }

    fclose($out);
    exit;
}

// ─────────────────────────────────────────────
// IMPORTAR
// ─────────────────────────────────────────────
if ($acao === 'importar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
        SELECT id FROM figurinhas WHERE codigo = :codigo
    ");
    $stmtVerifica = $db->prepare("
        SELECT id FROM inventario_usuario
        WHERE album_id = :aid AND figurinha_id = :fid
    ");
    $stmtInsert = $db->prepare("
        INSERT INTO inventario_usuario (id, album_id, usuario_id, figurinha_id, quantidade)
        VALUES (UUID(), :aid, :uid, :fid, :qtd)
    ");
    $stmtUpdate = $db->prepare("
        UPDATE inventario_usuario SET quantidade = :qtd WHERE id = :id
    ");

    while (($linha = fgetcsv($handle, 0, ';')) !== false) {
        // Pula cabeçalho
        if ($primeira) { $primeira = false; continue; }

        // Processa pares codigo;quantidade na linha (até 4 pares = 8 colunas)
        for ($i = 0; $i + 1 < count($linha); $i += 2) {
            $codigo = strtoupper(trim($linha[$i]));
            if (empty($codigo)) continue;

            $qtdCSV = max(0, (int) trim($linha[$i + 1]));

            // Converte de volta: quantidade real = qtdCSV + 1 se > 0, senão 0
            // qtdCSV=0 → faltante → quantidade real = 0
            // qtdCSV=1 → 1 repetida → quantidade real = 2
            // qtdCSV=2 → 2 repetidas → quantidade real = 3
            $qtdReal = $qtdCSV >= 2 ? $qtdCSV : 0;
            // Busca figurinha
            $stmtBusca->execute([':codigo' => $codigo]);
            $figurinhaId = $stmtBusca->fetchColumn();
            if (!$figurinhaId) { $erros++; continue; }

            // Verifica se já existe no inventário
            $stmtVerifica->execute([':aid' => $albumId, ':fid' => $figurinhaId]);
            $registroId = $stmtVerifica->fetchColumn();

            if ($registroId) {
                $stmtUpdate->execute([':qtd' => $qtdReal, ':id' => $registroId]);
            } else if ($qtdReal > 0) {
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

    $stmtRep = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
        FROM inventario_usuario WHERE album_id = :aid
    ");
    $stmtRep->execute([':aid' => $albumId]);
    $totalRep = (int) $stmtRep->fetchColumn();

    $percentual = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $faltantes  = $totalFigurinhas - $totalTem;

    $db->prepare("
        UPDATE albuns SET
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
