<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

// Pega a rota da URL (ex: "albuns", "inventario/123", etc.)
$route = trim($_GET['route'] ?? '', '/');
$route = $route === '' ? 'albuns' : $route;

// Mapeia rota para arquivo de página
$paginas = [
    'albuns'         => 'pages/albuns.php',
    'inventario'     => 'pages/inventario.php',
    'trocas'         => 'pages/trocas.php',
    'scanner'        => 'pages/scanner.php',
];

// Pega só o primeiro segmento da rota (ex: "inventario/123" → "inventario")
$segmento = explode('/', $route)[0];

if (isset($paginas[$segmento])) {
    require_once __DIR__ . '/' . $paginas[$segmento];
} else {
    http_response_code(404);
    echo "<h2>404 — Página não encontrada</h2>";
    echo "<a href='/albuns'>Voltar para álbuns</a>";
}
