<?php
// config/session.php — Gerenciamento de sessão

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DEV_USER_ID', '00000000-0000-0000-0000-000000000001');

$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', [
    'ifc.local',
    'localhost',
    '127.0.0.1',
    '192.168.15.16',
]);

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
        // Dev local: sessão fake automática
        iniciarSessaoDev();
    } else {
        // Produção: redireciona para login se não autenticado
        if (!isset($_SESSION['ifc_usuario_id'])) {
            $path = defined('APP_PATH') ? APP_PATH : '/ifc';
            header('Location: ' . $path . '/auth/login');
            exit;
        }
    }
}

function logout(): void {
    unset(
        $_SESSION['ifc_usuario_id'],
        $_SESSION['ifc_usuario_nome'],
        $_SESSION['ifc_usuario_email'],
        $_SESSION['ifc_avatar']
    );
}
