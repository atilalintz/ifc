<?php
// config/session.php — Gerenciamento de sessão

$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', [
    'ifc.local', 'localhost', '127.0.0.1', '192.168.15.12',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !$isLocal,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

define('DEV_USER_ID', '00000000-0000-0000-0000-000000000001');

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarCsrf(): void {
    $tokenEnviado = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    if (!hash_equals(csrfToken(), $tokenEnviado)) {
        http_response_code(403);
        echo json_encode(['erro' => 'Token CSRF inválido']);
        exit;
    }
}

function regenerarSessao(): void {
    session_regenerate_id(true);
}

function iniciarSessaoDev(): void {
    if (!isset($_SESSION['ifc_usuario_id'])) {
        $_SESSION['ifc_usuario_id']    = DEV_USER_ID;
        $_SESSION['ifc_usuario_nome']  = 'Dev Local';
        $_SESSION['ifc_usuario_email'] = 'dev@local.test';
        $_SESSION['ifc_avatar']        = '';
    }
}

function usuarioLogado(): array|null {
    if (!isset($_SESSION['ifc_usuario_id'])) {
        return null;
    }
    return [
        'id'     => $_SESSION['ifc_usuario_id'],
        'nome'   => $_SESSION['ifc_usuario_nome'],
        'email'  => $_SESSION['ifc_usuario_email'],
        'avatar' => $_SESSION['ifc_avatar'] ?? '',
    ];
}

function requireLogin(): void {
    global $isLocal;
    if ($isLocal) {
        iniciarSessaoDev();
    } else {
        if (!isset($_SESSION['ifc_usuario_id'])) {
            $path = defined('APP_PATH') ? APP_PATH : '/ifc';
            header('Location: ' . $path . '/auth/login');
            exit;
        }
    }
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}
