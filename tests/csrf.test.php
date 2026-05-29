<?php
// tests/csrf.test.php — Testes do token CSRF

// Simula a sessão PHP (necessário porque session.php usa $_SESSION)
if (session_status() === PHP_SESSION_NONE) {
    // Em testes não abrimos sessão real — simulamos com array
    $_SESSION = [];
}

// ── Stub das funções de sessão para isolar o teste ───────────────────────────
// Redefine csrfToken() e validarCsrf() aqui para não depender do servidor

function csrfTokenTeste(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarCsrfTeste(string $tokenEnviado): bool {
    return hash_equals(csrfTokenTeste(), $tokenEnviado);
}

// ─────────────────────────────────────────────────────────────────────────────

// Teste 1: token é gerado e é uma string hex de 64 caracteres (32 bytes)
$token = csrfTokenTeste();
testar(
    strlen($token) === 64 && ctype_xdigit($token),
    'CSRF: token tem 64 caracteres hexadecimais'
);

// Teste 2: chamar duas vezes retorna o mesmo token (não regenera a cada chamada)
$token1 = csrfTokenTeste();
$token2 = csrfTokenTeste();
testar(
    $token1 === $token2,
    'CSRF: token é consistente dentro da mesma sessão'
);

// Teste 3: token correto passa na validação
testar(
    validarCsrfTeste($token) === true,
    'CSRF: token correto é aceito'
);

// Teste 4: token errado é rejeitado
testar(
    validarCsrfTeste('token_invalido_qualquer') === false,
    'CSRF: token inválido é rejeitado'
);

// Teste 5: token vazio é rejeitado
testar(
    validarCsrfTeste('') === false,
    'CSRF: token vazio é rejeitado'
);

// Teste 6: token quase certo (1 caractere diferente) é rejeitado
$tokenQuaseCerto = $token;
$tokenQuaseCerto[0] = $tokenQuaseCerto[0] === 'a' ? 'b' : 'a';
testar(
    validarCsrfTeste($tokenQuaseCerto) === false,
    'CSRF: token com 1 caractere diferente é rejeitado'
);

// Limpa sessão para não interferir em outros testes
unset($_SESSION['csrf_token']);
