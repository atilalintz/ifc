<?php
// tests/runner.php — Executor de testes
// Uso: php tests/runner.php

$passou  = 0;
$falhou  = 0;
$erros   = [];

// ── Função principal de asserção ─────────────────────────────────────────────
// $condicao : o que você quer verificar (deve ser true)
// $descricao: texto que aparece no resultado
function testar(bool $condicao, string $descricao): void {
    global $passou, $falhou, $erros;
    if ($condicao) {
        echo "\033[32m  ✓ {$descricao}\033[0m\n"; // verde
        $passou++;
    } else {
        echo "\033[31m  ✗ {$descricao}\033[0m\n"; // vermelho
        $falhou++;
        $erros[] = $descricao;
    }
}

// ── Carrega todos os arquivos de teste da pasta ───────────────────────────────
$arquivos = glob(__DIR__ . '/*.test.php');
sort($arquivos);

foreach ($arquivos as $arquivo) {
    $nome = basename($arquivo, '.test.php');
    echo "\n\033[1m── {$nome} ──────────────────────────────────────\033[0m\n";
    require $arquivo;
}

// ── Resumo final ─────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('─', 50) . "\n";
echo "\033[32m  Passou : {$passou}\033[0m\n";
if ($falhou > 0) {
    echo "\033[31m  Falhou : {$falhou}\033[0m\n";
    echo "\n\033[31mFalhas:\033[0m\n";
    foreach ($erros as $e) echo "  • {$e}\n";
} else {
    echo "\033[32m  Todos os testes passaram! 🎉\033[0m\n";
}
echo str_repeat('─', 50) . "\n\n";

exit($falhou > 0 ? 1 : 0); // exit code 1 se algum falhou
