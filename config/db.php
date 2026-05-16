<?php
// config/db.php — Conexão PDO para Hostinger
// Detecta ambiente automaticamente

$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['ifc.local', 'localhost', '127.0.0.1']);

if ($isLocal) {
    // Ambiente local
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'ifc');
    define('DB_USER', 'root');
    define('DB_PASS', '123');
    define('TBL', '');  // sem prefixo local
} else {
    // Hostinger
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u450461275_quiz_db');
    define('DB_USER', 'u450461275_contato');
    define('DB_PASS', 'L9!nQ2I?NU@x'); // ← trocar
    define('TBL', 'ifc_'); // prefixo das tabelas
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Helper para nome de tabela com prefixo correto
function tbl(string $nome): string {
    return TBL . $nome;
}
