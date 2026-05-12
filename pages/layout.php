Vamos lá! O Passo 6 vai ser a primeira tela real — lista os álbuns do usuário e permite criar um novo.

Primeiro cria o layout base que todas as páginas vão reaproveitar:
bash

nano /var/www/html/ifc/pages/layout.php

Cole:
php

<?php
// pages/layout.php — Layout base reutilizável
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
