<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/oauth.php';
require_once __DIR__ . '/config/session.php';

$route = trim($_GET['route'] ?? '', '/');
$route = $route === '' ? 'albuns' : $route;

$segmentos = array_values(array_filter(explode('/', $route), 'strlen'));
$rotaCompleta = implode('/', array_slice($segmentos, 0, 2));
$rotaBase = $segmentos[0] ?? '';

$apis = [
 'api/inventario' => 'api/inventario.php',
 'api/csv' => 'api/csv.php',
 'api/scanner' => 'api/scanner.php',
];

$paginas = [
 'albuns' => 'pages/albuns.php',
 'inventario' => 'pages/inventario.php',
 'trocas' => 'pages/trocas.php',
 'scanner' => 'pages/scanner.php',
 'auth/login' => 'auth/login.php',
 'auth/callback' => 'auth/callback.php',
 'auth/logout' => 'auth/logout.php',
];

$rotasPublicas = ['auth/login', 'auth/callback', 'auth/logout'];

// Rotas públicas
if (in_array($rotaCompleta, $rotasPublicas, true)) {
 require_once __DIR__ . '/' . $paginas[$rotaCompleta];
 exit;
}

// Protege tudo o resto
requireLogin();

// APIs
if (isset($apis[$route])) {
 require_once __DIR__ . '/' . $apis[$route];
 exit;
}

// inventario/{uuid}
if ($rotaBase === 'inventario' && !empty($segmentos[1])) {
 $_GET['id'] = $segmentos[1];
 require_once __DIR__ . '/pages/inventario.php';
 exit;
}

// Página exata
if (isset($paginas[$route])) {
 require_once __DIR__ . '/' . $paginas[$route];
 exit;
}

// Fallback
http_response_code(404);
echo 'Página não encontrada';