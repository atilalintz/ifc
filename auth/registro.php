<?php
// auth/registro.php — Cadastro com email e senha
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

if (usuarioLogado()) {
    header('Location: ' . APP_PATH . '/albuns');
    exit;
}

$erro = $_GET['erro'] ?? '';
$erros = [
    'email_existe'  => 'Este e-mail já está cadastrado. Faça login.',
    'senha_curta'   => 'A senha precisa ter pelo menos 6 caracteres.',
    'nome_vazio'    => 'Informe seu nome.',
    'email_invalido'=> 'E-mail inválido.',
    'senhas_diferentes' => 'As senhas não coincidem.',
];
$msgErro = $erros[$erro] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar conta — IFC</title>
    <link rel="stylesheet" href="<?= APP_PATH ?>/assets/css/style.css">
    <style>
        .login-wrap {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: var(--cinza);
        }
        .login-card {
            background: #fff; border-radius: 12px;
            padding: 2.5rem 2rem;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            max-width: 380px; width: 100%;
        }
        .login-logo   { font-size: 2.5rem; text-align: center; margin-bottom: .4rem; }
        .login-titulo { font-size: 1.3rem; font-weight: 700; color: var(--verde); text-align: center; margin-bottom: 1.5rem; }

        .form-campo { margin-bottom: .85rem; }
        .form-campo label { display: block; font-size: .82rem; font-weight: 600; margin-bottom: .3rem; }
        .form-campo input {
            width: 100%; padding: .55rem .75rem;
            border: 1px solid #ddd; border-radius: 8px;
            font-size: .95rem; box-sizing: border-box; background: #fafafa;
        }
        .form-campo input:focus { outline: none; border-color: var(--verde); }

        .btn-entrar {
            width: 100%; padding: .75rem;
            background: var(--verde); color: #fff;
            border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: opacity .2s; margin-top: .25rem;
        }
        .btn-entrar:hover { opacity: .88; }

        .login-link { font-size: .85rem; color: #666; margin-top: 1rem; text-align: center; }
        .login-link a { color: var(--verde); font-weight: 600; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }

        .alerta-erro {
            background: #fef2f2; color: #dc2626;
            border: 1px solid #fecaca; border-radius: 8px;
            padding: .6rem .85rem; font-size: .88rem; margin-bottom: 1rem;
        }
        .senha-hint { font-size: .75rem; color: #aaa; margin-top: .2rem; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">⚽</div>
        <h1 class="login-titulo">Criar conta — IFC Copa 2026</h1>

        <?php if ($msgErro): ?>
            <div class="alerta-erro">⚠️ <?= htmlspecialchars($msgErro) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_PATH ?>/api/auth?acao=registro">
            <div class="form-campo">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome"
                       value="<?= htmlspecialchars($_GET['nome'] ?? '') ?>"
                       placeholder="Seu nome" required autocomplete="name">
            </div>
            <div class="form-campo">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_GET['email'] ?? '') ?>"
                       placeholder="voce@email.com" required autocomplete="email">
            </div>
            <div class="form-campo">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha"
                       placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
                <div class="senha-hint">Mínimo 6 caracteres</div>
            </div>
            <div class="form-campo">
                <label for="senha2">Confirmar senha</label>
                <input type="password" id="senha2" name="senha2"
                       placeholder="Repita a senha" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn-entrar">Criar conta</button>
        </form>

        <p class="login-link">
            Já tem conta? <a href="<?= APP_PATH ?>/auth/login">Entrar</a>
        </p>
    </div>
</div>
</body>
</html>
