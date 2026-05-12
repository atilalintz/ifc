<?php
// config/db.php — Conexão PDO com MariaDB

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'ifc');
define('DB_USER', 'root');       // troque pelo usuário do seu MariaDB
define('DB_PASS', '123');  // troque pela sua senha

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // lança exceção em erro
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // retorna array associativo
            PDO::ATTR_EMULATE_PREPARES   => false,                   // usa prepared statements reais
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo; // sempre retorna a mesma instância (singleton)
}
