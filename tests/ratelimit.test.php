<?php
// tests/ratelimit.test.php — Testes do rate limit de login

// ── Implementação isolada para teste (sem banco de dados) ─────────────────────
// Usa array em memória no lugar da tabela ifc_login_attempts
// A lógica é idêntica à do api/auth.php

$_tentativas = []; // simula a tabela do banco

function rl_registrarFalha(string $ip, string $email): void {
    global $_tentativas;
    $chave = "{$ip}|{$email}";

    if (!isset($_tentativas[$chave])) {
        $_tentativas[$chave] = ['tentativas' => 1, 'primeira' => time()];
    } else {
        // Se a janela de 15 min expirou, reinicia
        if ($_tentativas[$chave]['primeira'] < time() - 900) {
            $_tentativas[$chave] = ['tentativas' => 1, 'primeira' => time()];
        } else {
            $_tentativas[$chave]['tentativas']++;
        }
    }
}

function rl_rateLimitAtingido(string $ip, string $email): bool {
    global $_tentativas;
    $chave = "{$ip}|{$email}";

    if (!isset($_tentativas[$chave])) return false;

    // Janela de 15 min expirou → não bloqueia
    if ($_tentativas[$chave]['primeira'] < time() - 900) return false;

    return $_tentativas[$chave]['tentativas'] >= 5;
}

function rl_limparTentativas(string $ip, string $email): void {
    global $_tentativas;
    unset($_tentativas["{$ip}|{$email}"]);
}

// ─────────────────────────────────────────────────────────────────────────────

$ip    = '192.168.1.1';
$email = 'usuario@teste.com';

// Reseta estado antes dos testes
rl_limparTentativas($ip, $email);

// Teste 1: sem tentativas, não bloqueia
testar(
    rl_rateLimitAtingido($ip, $email) === false,
    'Rate limit: sem tentativas, acesso liberado'
);

// Teste 2: 4 tentativas não bloqueiam
for ($i = 0; $i < 4; $i++) rl_registrarFalha($ip, $email);
testar(
    rl_rateLimitAtingido($ip, $email) === false,
    'Rate limit: 4 tentativas ainda não bloqueiam'
);

// Teste 3: 5ª tentativa bloqueia
rl_registrarFalha($ip, $email);
testar(
    rl_rateLimitAtingido($ip, $email) === true,
    'Rate limit: 5ª tentativa bloqueia o acesso'
);

// Teste 4: login bem-sucedido limpa o contador
rl_limparTentativas($ip, $email);
testar(
    rl_rateLimitAtingido($ip, $email) === false,
    'Rate limit: login bem-sucedido limpa o bloqueio'
);

// Teste 5: IPs diferentes são contados separadamente
$ip2 = '10.0.0.1';
rl_limparTentativas($ip2, $email);
for ($i = 0; $i < 5; $i++) rl_registrarFalha($ip, $email);

testar(
    rl_rateLimitAtingido($ip2, $email) === false,
    'Rate limit: IP diferente não é afetado pelo bloqueio de outro IP'
);

// Teste 6: mesmo IP, e-mails diferentes são contados separadamente
$email2 = 'outro@teste.com';
rl_limparTentativas($ip, $email2);

testar(
    rl_rateLimitAtingido($ip, $email2) === false,
    'Rate limit: e-mail diferente não é afetado pelo bloqueio de outro e-mail'
);

// Limpa estado
rl_limparTentativas($ip, $email);
rl_limparTentativas($ip, $email2);
rl_limparTentativas($ip2, $email);
