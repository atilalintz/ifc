<?php
// index.php — Roteador central
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/oauth.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

// Pega a rota da URL
$route    = trim($_GET['route'] ?? '', '/');
$route    = $route === '' ? 'albuns' : $route;
$segmento = explode('/', $route)[0];

// Rotas de API (retornam JSON)
$apis = [
    'api/inventario' => 'api/inventario.php',
    'api/csv'        => 'api/csv.php',
    'api/scanner'    => 'api/scanner.php',
];

// Rotas de páginas
$paginas = [
    'albuns'          => 'pages/albuns.php',
    'inventario'      => 'pages/inventario.php',
    'trocas'          => 'pages/trocas.php',
    'scanner'         => 'pages/scanner.php',
    'auth/login'      => 'auth/login.php',
    'auth/callback'   => 'auth/callback.php',
    'auth/logout'     => 'auth/logout.php',
];

// Rotas que não precisam de login
$rotasPublicas = ['auth/login', 'auth/callback'];

// Verifica se é rota pública
$rotaCompleta = $segmento === 'auth' ? $route : $segmento;
if (in_array($rotaCompleta, $rotasPublicas)) {
    // Permite acesso sem login
    if (isset($paginas[$rotaCompleta])) {
        require_once __DIR__ . '/' . $paginas[$rotaCompleta];
        exit;
    }
}

// Rotas de API
if (isset($apis[$route])) {
    require_once __DIR__ . '/' . $apis[$route];
    exit;
}

// Rotas de páginas
if (isset($paginas[$segmento])) {
    require_once __DIR__ . '/' . $paginas[$segmento];
    exit;
}

// Rota auth com subsegmento (ex: auth/callback, auth/logout)
if ($segmento === 'auth' && isset($paginas[$route])) {
    require_once __DIR__ . '/' . $paginas[$route];
    exit;
}

// 404
http_response_code(404);
echo "<h2>404 — Página não encontrada</h2>";
echo "<a href='" . (defined('APP_PATH') ? APP_PATH : '') . "/albuns'>Voltar para álbuns</a>";
