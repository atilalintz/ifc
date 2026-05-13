<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$route    = trim($_GET['route'] ?? '', '/');
$route    = $route === '' ? 'albuns' : $route;
$segmento = explode('/', $route)[0];

// Rotas de API (retornam JSON)
$apis = [
    'api/inventario' => 'api/inventario.php',
];

// Rotas de páginas
$paginas = [
    'albuns'     => 'pages/albuns.php',
    'inventario' => 'pages/inventario.php',
    'trocas'     => 'pages/trocas.php',
    'scanner'    => 'pages/scanner.php',
];

if (isset($apis[$route])) {
    require_once __DIR__ . '/' . $apis[$route];
} elseif (isset($paginas[$segmento])) {
    require_once __DIR__ . '/' . $paginas[$segmento];
} else {
    http_response_code(404);
    echo "<h2>404 — Página não encontrada</h2>";
    echo "<a href='/albuns'>Voltar para álbuns</a>";
}
