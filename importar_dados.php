<?php
/**
 * importar_dados.php
 * Importa seleções e figurinhas seguindo a ordem exata do álbum (PDF).
 * Executar via linha de comando: php importar_dados.php
 *
 * Estrutura do álbum:
 *   Panini  → PAN00 (capa)
 *   FWC     → FWC01–FWC08 (primeiras páginas) + FWC09–FWC19 (final)
 *   CC      → CC01–CC14
 *   Grupos A–L → 4 seleções × 20 figurinhas cada
 */

require_once __DIR__ . '/config/db.php';

// ─────────────────────────────────────────────
// 1. Ordem exata do álbum conforme o PDF
//    Cada entrada: [grupo_codigo, sigla, nome]
//    grupo_codigo null = sem grupo de seleção
// ─────────────────────────────────────────────
$ordemAlbum = [
    // Capa
    [null,  'PNN', 'Panini'],

    // FIFA World Cup (bloco inicial + bloco final — mesma seleção)
    [null,  'FWC', 'FIFA World Cup'],

    // Coca-Cola
    [null,  'CC',  'Coca-Cola'],

    // Grupo A
    ['A',   'MEX', 'México'],
    ['A',   'RSA', 'África do Sul'],
    ['A',   'KOR', 'Coreia do Sul'],
    ['A',   'CZE', 'República Tcheca'],

    // Grupo B
    ['B',   'CAN', 'Canadá'],
    ['B',   'BIH', 'Bósnia e Herzegovina'],
    ['B',   'QAT', 'Catar'],
    ['B',   'SUI', 'Suíça'],

    // Grupo C
    ['C',   'BRA', 'Brasil'],
    ['C',   'MAR', 'Marrocos'],
    ['C',   'HAI', 'Haiti'],
    ['C',   'SCO', 'Escócia'],

    // Grupo D
    ['D',   'USA', 'Estados Unidos'],
    ['D',   'PAR', 'Paraguai'],
    ['D',   'AUS', 'Austrália'],
    ['D',   'TUR', 'Turquia'],

    // Grupo E
    ['E',   'GER', 'Alemanha'],
    ['E',   'CUW', 'Curaçao'],
    ['E',   'CIV', 'Costa do Marfim'],
    ['E',   'ECU', 'Equador'],

    // Grupo F
    ['F',   'NED', 'Holanda'],
    ['F',   'JPN', 'Japão'],
    ['F',   'SWE', 'Suécia'],
    ['F',   'TUN', 'Tunísia'],

    // Grupo G
    ['G',   'BEL', 'Bélgica'],
    ['G',   'EGY', 'Egito'],
    ['G',   'IRN', 'Irã'],
    ['G',   'NZL', 'Nova Zelândia'],

    // Grupo H
    ['H',   'ESP', 'Espanha'],
    ['H',   'CPV', 'Cabo Verde'],
    ['H',   'KSA', 'Arábia Saudita'],
    ['H',   'URU', 'Uruguai'],

    // Grupo I
    ['I',   'FRA', 'França'],
    ['I',   'SEN', 'Senegal'],
    ['I',   'IRQ', 'Iraque'],
    ['I',   'NOR', 'Noruega'],

    // Grupo J
    ['J',   'ARG', 'Argentina'],
    ['J',   'ALG', 'Argélia'],
    ['J',   'AUT', 'Áustria'],
    ['J',   'JOR', 'Jordânia'],

    // Grupo K
    ['K',   'POR', 'Portugal'],
    ['K',   'COD', 'Rep. Democrática do Congo'],
    ['K',   'UZB', 'Uzbequistão'],
    ['K',   'COL', 'Colômbia'],

    // Grupo L
    ['L',   'ENG', 'Inglaterra'],
    ['L',   'CRO', 'Croácia'],
    ['L',   'GHA', 'Gana'],
    ['L',   'PAN', 'Panamá'],  // PAN = Panamá (Panini = PNN)
];

// ─────────────────────────────────────────────
// 2. Figurinhas por seleção (ordem e tipo)
//    Baseado no PDF
// ─────────────────────────────────────────────
$figurinhasPorSelecao = [
    'PNN' => [
        ['PNN00', 0, 'capa'],
    ],
    'FWC' => array_merge(
        // Bloco inicial: FWC01–FWC08
        array_map(fn($n) => [sprintf('FWC%02d', $n), $n, 'especial'], range(1, 8)),
        // Bloco final: FWC09–FWC19
        array_map(fn($n) => [sprintf('FWC%02d', $n), $n, 'especial'], range(9, 19))
    ),
    'CC' => array_map(fn($n) => [sprintf('CC%02d', $n), $n, 'especial'], range(1, 14)),
    // Seleções normais: 20 figurinhas cada
    'default' => fn(string $sigla) => array_map(
        fn($n) => [sprintf('%s%02d', $sigla, $n), $n, 'normal'],
        range(1, 20)
    ),
];

// ─────────────────────────────────────────────
// 3. Inicia importação
// ─────────────────────────────────────────────
$db = getDB();

// Busca IDs dos grupos
$stmt   = $db->query("SELECT id, codigo FROM grupos");
$grupos = [];
foreach ($stmt->fetchAll() as $row) {
    $grupos[$row['codigo']] = $row['id'];
}

$totalSelecoes   = 0;
$totalFigurinhas = 0;
$erros           = 0;

echo "===========================================\n";
echo " IFC — Importação de dados\n";
echo "===========================================\n\n";

// ─────────────────────────────────────────────
// 4. Insere seleções e figurinhas na ordem do álbum
// ─────────────────────────────────────────────
$stmtSelecao = $db->prepare("
    INSERT IGNORE INTO selecoes (id, grupo_id, nome, sigla, bandeira_url)
    VALUES (:id, :grupo_id, :nome, :sigla, :bandeira_url)
");

$stmtFigurinhas = $db->prepare("
    INSERT IGNORE INTO figurinhas (id, codigo, selecao_id, numero, tipo)
    VALUES (:id, :codigo, :selecao_id, :numero, :tipo)
");

$stmtBuscaSelecao = $db->prepare("SELECT id FROM selecoes WHERE sigla = :sigla");

foreach ($ordemAlbum as [$grupoCode, $sigla, $nome]) {

    // ── Insere seleção ──
    $grupoId  = $grupoCode ? ($grupos[$grupoCode] ?? null) : null;
    $bandeira = "/assets/img/selecoes/{$sigla}.png";

    $stmtSelecao->execute([
        ':id'           => uuid(),
        ':grupo_id'     => $grupoId,
        ':nome'         => $nome,
        ':sigla'        => $sigla,
        ':bandeira_url' => $bandeira,
    ]);

    // Busca ID real (pode já existir)
    $stmtBuscaSelecao->execute([':sigla' => $sigla]);
    $selecaoId = $stmtBuscaSelecao->fetchColumn();
    $totalSelecoes++;

    echo "Seleção: [{$sigla}] {$nome}" . ($grupoCode ? " (Grupo {$grupoCode})" : "") . "\n";

    // ── Define figurinhas desta seleção ──
    if (isset($figurinhasPorSelecao[$sigla])) {
        $lista = $figurinhasPorSelecao[$sigla];
    } else {
        $lista = ($figurinhasPorSelecao['default'])($sigla);
    }

    foreach ($lista as [$codigo, $numero, $tipo]) {
        $stmtFigurinhas->execute([
            ':id'         => uuid(),
            ':codigo'     => $codigo,
            ':selecao_id' => $selecaoId,
            ':numero'     => $numero,
            ':tipo'       => $tipo,
        ]);
        $totalFigurinhas++;
        echo "  + {$codigo} ({$tipo})\n";
    }
}

echo "\n===========================================\n";
echo " Importação concluída!\n";
echo " Seleções inseridas  : {$totalSelecoes}\n";
echo " Figurinhas inseridas: {$totalFigurinhas}\n";
if ($erros > 0) {
    echo " Erros               : {$erros}\n";
}
echo "===========================================\n";

// ─────────────────────────────────────────────
// Helper UUID
// ─────────────────────────────────────────────
function uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
