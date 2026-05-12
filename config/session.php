<?php
// config/session.php — Gerenciamento de sessão

session_start();

// ID do usuário dev inserido no schema.sql
define('DEV_USER_ID', '00000000-0000-0000-0000-000000000001');

function iniciarSessaoDev(): void {
    if (!isset($_SESSION['usuario_id'])) {
        $_SESSION['usuario_id'] = DEV_USER_ID;
        $_SESSION['usuario_nome'] = 'Dev Local';
    }
}

function usuarioLogado(): array|null {
    if (!isset($_SESSION['usuario_id'])) {
        return null;
    }

    return [
        'id'   => $_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'],
    ];
}

function requireLogin(): void {
    // Em dev: inicia sessão fake automaticamente
    // Em produção (AWS): vai redirecionar para login Google
    iniciarSessaoDev();
}
