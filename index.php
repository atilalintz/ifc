<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$usuario = usuarioLogado();

echo "Olá, " . $usuario['nome'] . "! <br>";
echo "ID: " . $usuario['id'];
