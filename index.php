<?php
require_once __DIR__ . '/config/db.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) AS total FROM grupos");
    $row  = $stmt->fetch();
    echo "Conexão OK! Grupos cadastrados: " . $row['total'];
} catch (PDOException $e) {
    echo "Erro na conexão: " . $e->getMessage();
}
