<?php
// tests/album.test.php — Testes do cálculo de percentual e estatísticas do álbum

// ── Função isolada para teste (lógica idêntica à do projeto) ─────────────────
// Recebe os dados diretamente em vez de consultar o banco

function calcularEstatisticasAlbum(int $totalFigurinhas, array $inventario): array {
    // $inventario = array de quantidades, ex: [0, 1, 2, 0, 3]

    $totalTem   = count(array_filter($inventario, fn($q) => $q > 0));
    $totalRep   = array_sum(array_map(fn($q) => max(0, $q - 1), $inventario));
    $percentual = $totalFigurinhas > 0
        ? round(($totalTem / $totalFigurinhas) * 100, 2)
        : 0.0;
    $faltantes  = $totalFigurinhas - $totalTem;

    return [
        'percentual' => $percentual,
        'faltantes'  => $faltantes,
        'repetidas'  => $totalRep,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────

// Teste 1: álbum vazio — 0%, todas faltando, 0 repetidas
$stats = calcularEstatisticasAlbum(10, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
testar($stats['percentual'] === 0.0,  'Álbum: vazio → 0% completo');
testar($stats['faltantes']  === 10,   'Álbum: vazio → 10 faltantes');
testar($stats['repetidas']  === 0,    'Álbum: vazio → 0 repetidas');

// Teste 2: álbum completo sem repetidas — 100%, 0 faltando, 0 repetidas
$stats = calcularEstatisticasAlbum(4, [1, 1, 1, 1]);
testar($stats['percentual'] === 100.0, 'Álbum: completo → 100%');
testar($stats['faltantes']  === 0,     'Álbum: completo → 0 faltantes');
testar($stats['repetidas']  === 0,     'Álbum: completo → 0 repetidas');

// Teste 3: metade completa — 50%
$stats = calcularEstatisticasAlbum(4, [1, 1, 0, 0]);
testar($stats['percentual'] === 50.0, 'Álbum: metade → 50%');
testar($stats['faltantes']  === 2,    'Álbum: metade → 2 faltantes');

// Teste 4: figurinhas repetidas contam certo
// qtd=3 → 2 repetidas; qtd=2 → 1 repetida; qtd=1 → 0 repetidas
$stats = calcularEstatisticasAlbum(3, [3, 2, 1]);
testar($stats['repetidas']  === 3,    'Álbum: repetidas calculadas corretamente (3+2+1 → 0+1+2=3)');
testar($stats['percentual'] === 100.0,'Álbum: todas com qtd>0 → 100%');

// Teste 5: total de figurinhas zero não divide por zero
$stats = calcularEstatisticasAlbum(0, []);
testar($stats['percentual'] === 0.0, 'Álbum: sem figurinhas → 0% (sem divisão por zero)');

// Teste 6: percentual arredonda para 2 casas
// 1 de 3 = 33.333... → deve arredondar para 33.33
$stats = calcularEstatisticasAlbum(3, [1, 0, 0]);
testar($stats['percentual'] === 33.33, 'Álbum: percentual arredondado para 2 casas decimais');
