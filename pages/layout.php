<?php
// pages/layout.php — Layout base reutilizável
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

function layoutInicio(string $titulo): void {
    $usuario = usuarioLogado();
    $appPath = defined('APP_PATH') ? APP_PATH : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?> — IFC</title>
    <link rel="stylesheet" href="<?= $appPath ?>/assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="<?= $appPath ?>/albuns" class="logo">⚽ IFC</a>
            <nav>
                <a href="<?= $appPath ?>/albuns">Álbuns</a>
                <a href="<?= $appPath ?>/trocas">Trocas</a>
                <a href="<?= $appPath ?>/scanner">Scanner</a>
            </nav>
            <div class="header-usuario">
                <?php if ($usuario): ?>
                    <?php if (!empty($usuario['avatar'])): ?>
                        <img src="<?= htmlspecialchars($usuario['avatar']) ?>"
                             alt="<?= htmlspecialchars($usuario['nome']) ?>"
                             class="avatar">
                    <?php else: ?>
                        <span class="avatar-inicial">
                            <?= mb_strtoupper(mb_substr($usuario['nome'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                    <span class="usuario-nome"><?= htmlspecialchars(explode(' ', $usuario['nome'])[0]) ?></span>
                    <a href="<?= $appPath ?>/auth/logout" class="btn-logout" title="Sair">⏻</a>
                <?php endif; ?>
            </div>
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
