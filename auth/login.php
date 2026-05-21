<?php
// auth/login.php — Login via Google ou email/senha
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

if (usuarioLogado()) {
    header('Location: ' . APP_PATH . '/albuns');
    exit;
}

// Gera state CSRF para o Google
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

// Mensagem de erro vinda da api/auth.php
$erro = $_GET['erro'] ?? '';
$erros = [
    'credenciais' => 'E-mail ou senha incorretos.',
    'email_vazio' => 'Informe o e-mail.',
    'senha_vazia' => 'Informe a senha.',
    'sem_senha'   => 'Esta conta usa login Google. Clique em "Entrar com Google".',
];
$msgErro = $erros[$erro] ?? '';
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
            display: flex; align-items: center; justify-content: center;
            background: var(--cinza);
        }
        .login-card {
            background: #fff; border-radius: 12px;
            padding: 2.5rem 2rem; text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            max-width: 380px; width: 100%;
        }
        .login-logo  { font-size: 3rem; margin-bottom: .5rem; }
        .login-titulo { font-size: 1.4rem; font-weight: 700; color: var(--verde); margin-bottom: .25rem; }
        .login-sub   { font-size: .9rem; color: #666; margin-bottom: 1.5rem; }

        .btn-google {
            display: flex; align-items: center; justify-content: center; gap: .75rem;
            width: 100%; padding: .75rem 1.25rem;
            border: 2px solid #ddd; border-radius: 8px;
            background: #fff; font-size: 1rem; font-weight: 600; color: #333;
            cursor: pointer; text-decoration: none;
            transition: box-shadow .2s, border-color .2s;
        }
        .btn-google:hover { box-shadow: 0 2px 8px rgba(0,0,0,.15); border-color: #bbb; }
        .btn-google img   { width: 22px; height: 22px; }

        .divisor {
            display: flex; align-items: center; gap: .75rem;
            margin: 1.25rem 0; color: #bbb; font-size: .82rem;
        }
        .divisor::before, .divisor::after {
            content: ''; flex: 1; height: 1px; background: #e0e0e0;
        }

        .form-campo { text-align: left; margin-bottom: .85rem; }
        .form-campo label { display: block; font-size: .82rem; font-weight: 600; margin-bottom: .3rem; }
        .form-campo input {
            width: 100%; padding: .55rem .75rem;
            border: 1px solid #ddd; border-radius: 8px;
            font-size: .95rem; box-sizing: border-box;
            background: #fafafa;
        }
        .form-campo input:focus { outline: none; border-color: var(--verde); }

        .btn-entrar {
            width: 100%; padding: .75rem;
            background: var(--verde); color: #fff;
            border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: opacity .2s;
        }
        .btn-entrar:hover { opacity: .88; }

        .login-link { font-size: .85rem; color: #666; margin-top: 1rem; }
        .login-link a { color: var(--verde); font-weight: 600; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }

        .alerta-erro {
            background: #fef2f2; color: #dc2626;
            border: 1px solid #fecaca; border-radius: 8px;
            padding: .6rem .85rem; font-size: .88rem;
            margin-bottom: 1rem; text-align: left;
        }
        .login-rodape { margin-top: 1.5rem; font-size: .78rem; color: #aaa; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">⚽</div>
        <h1 class="login-titulo">IFC Copa 2026</h1>
        <p class="login-sub">Inventário de Figurinhas</p>

        <!-- Google -->
        <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="btn-google">
            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="Google">
            Entrar com Google
        </a>

        <div class="divisor">ou</div>

        <!-- Email + senha -->
        <?php if ($msgErro): ?>
            <div class="alerta-erro">⚠️ <?= htmlspecialchars($msgErro) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_PATH ?>/api/auth?acao=login">
            <div class="form-campo">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_GET['email'] ?? '') ?>"
                       placeholder="voce@email.com" required autocomplete="email">
            </div>
            <div class="form-campo">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-entrar">Entrar</button>
        </form>

        <p class="login-link">
            Não tem conta? <a href="<?= APP_PATH ?>/auth/registro">Criar conta</a>
        </p>

        <p class="login-rodape">
            Ao entrar, você concorda com o uso dos seus dados<br>
            apenas para gerenciar seu álbum de figurinhas.
        </p>
    </div>
</div>
</body>
</html>
