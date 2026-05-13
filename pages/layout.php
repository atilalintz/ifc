<?php
function layoutInicio(string $titulo): void { ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?> — IFC</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="/albuns" class="logo">⚽ IFC</a>
            <nav>
                <a href="/albuns">Álbuns</a>
                <a href="/trocas">Trocas</a>
                <a href="/scanner">Scanner</a>
            </nav>
        </div>
    </header>
    <main class="container">
<?php }

function layoutFim(): void { ?>
    </main>
    <footer class="footer">
        <div class="container">IFC — Inventário de Figurinhas da Copa 2026</div>
    </footer>
</body>
</html>
<?php }
