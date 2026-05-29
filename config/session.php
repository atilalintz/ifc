<?php
// config/session.php — Gerenciamento de sessão

// ── Detecta ambiente antes do session_start ───────────────────────────────────
$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', [
    'ifc.local', 'localhost', '127.0.0.1', '192.168.15.12',
]);

// ── Configura cookie ANTES de iniciar a sessão ───────────────────────────────
// httponly: JS não acessa o cookie (bloqueia roubo via XSS)
// secure:   trafega só em HTTPS (desativado local para não quebrar dev)
// samesite: cookie não é enviado em requisições cross-site (mitiga CSRF)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // expira ao fechar o browser
        'path'     => '/',
        'domain'   => '',
        'secure'   => !$isLocal,  // HTTPS apenas em produção
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

define('DEV_USER_ID', '00000000-0000-0000-0000-000000000001');

// ── Regenera o session ID após autenticação ───────────────────────────────────
// Impede Session Fixation: troca o ID antigo por um novo e apaga o arquivo anterior
function regenerarSessao(): void {
    session_regenerate_id(true);
}

// ── Sessão de desenvolvimento local ──────────────────────────────────────────
function iniciarSessaoDev(): void {
    if (!isset($_SESSION['ifc_usuario_id'])) {
        $_SESSION['ifc_usuario_id']    = DEV_USER_ID;
        $_SESSION['ifc_usuario_nome']  = 'Dev Local';
        $_SESSION['ifc_usuario_email'] = 'dev@local.test';
        $_SESSION['ifc_avatar']        = '';
    }
}

// ── Retorna dados do usuário logado ou null ───────────────────────────────────
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

// ── Protege rotas autenticadas ────────────────────────────────────────────────
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

// ── Logout completo ───────────────────────────────────────────────────────────
// 1) Limpa os dados da sessão na memória
// 2) Apaga o cookie no browser do usuário
// 3) Destroi o arquivo de sessão no servidor
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
