<?php
// auth/login.php — Página de login
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

// Se já logado, redireciona para álbuns
if (usuarioLogado()) {
    header('Location: ' . APP_PATH . '/albuns');
    exit;
}

// Gera state para proteção CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

$googleLoginUrl = GOOGLE_AUTH_URL . '?' . $params;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar — IFC</title>
    <link rel="stylesheet" href="<?= APP_PATH ?>/assets/css/style.css">
    <style>
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cinza);
        }
        .login-card {
            background: #fff;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            max-width: 360px;
            width: 100%;
        }
        .login-logo {
            font-size: 3rem;
            margin-bottom: .5rem;
        }
        .login-titulo {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--verde);
            margin-bottom: .25rem;
        }
        .login-sub {
            font-size: .9rem;
            color: #666;
            margin-bottom: 2rem;
        }
        .btn-google {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .75rem;
            width: 100%;
            padding: .75rem 1.25rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #fff;
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            text-decoration: none;
            transition: box-shadow .2s, border-color .2s;
        }
        .btn-google:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            border-color: #bbb;
        }
        .btn-google img {
            width: 22px;
            height: 22px;
        }
        .login-rodape {
            margin-top: 1.5rem;
            font-size: .78rem;
            color: #aaa;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">⚽</div>
        <h1 class="login-titulo">IFC Copa 2026</h1>
        <p class="login-sub">Inventário de Figurinhas</p>

        <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="btn-google">
            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="Google">
            Entrar com Google
        </a>

        <p class="login-rodape">
            Ao entrar, você concorda com o uso dos seus dados<br>
            apenas para gerenciar seu álbum de figurinhas.
        </p>
    </div>
</div>
</body>
</html>
