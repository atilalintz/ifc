<?php
// pages/layout.php — Layout base reutilizável
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

function layoutInicio(string $titulo): void {
    $usuario  = usuarioLogado();
    $appPath  = defined('APP_PATH') ? APP_PATH : '';
    $rota     = trim($_GET['route'] ?? '', '/');
    $rotaBase = explode('/', $rota)[0] ?? '';
    $emTrocas  = str_starts_with($rota, 'trocas');
    $emScanner = str_starts_with($rota, 'scanner');
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
                <a href="<?= $appPath ?>/albuns"  <?= $rotaBase==='albuns'  ? 'class="nav-ativa"' : '' ?>>Álbuns</a>
                <a href="<?= $appPath ?>/trocas"  <?= $emTrocas              ? 'class="nav-ativa"' : '' ?>>Trocas</a>
                <a href="<?= $appPath ?>/scanner" <?= $emScanner             ? 'class="nav-ativa"' : '' ?>>Scanner</a>
            </nav>
            <div class="header-usuario">
                <?php if ($usuario): ?>
                    <a href="<?= $appPath ?>/perfil" class="avatar-link" title="Meu perfil">
                        <?php if (!empty($usuario['avatar'])): ?>
                            <img src="<?= htmlspecialchars($usuario['avatar']) ?>"
                                 alt="<?= htmlspecialchars($usuario['nome']) ?>"
                                 class="avatar">
                        <?php else: ?>
                            <span class="avatar-inicial">
                                <?= mb_strtoupper(mb_substr($usuario['nome'], 0, 1)) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $appPath ?>/perfil" class="usuario-nome" title="Meu perfil">
                        <?= htmlspecialchars(explode(' ', $usuario['nome'])[0]) ?>
                    </a>
                    <a href="<?= $appPath ?>/auth/logout" class="btn-logout" title="Sair">⏻</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($emTrocas): ?>
        <div class="trocas-subnav">
            <div class="container">
                <a href="<?= $appPath ?>/trocas"
                   class="subnav-link <?= $rota==='trocas' ? 'ativa' : '' ?>">
                    Visão Geral
                </a>
                <a href="<?= $appPath ?>/trocas/internas"
                   class="subnav-link <?= str_starts_with($rota,'trocas/internas') ? 'ativa' : '' ?>">
                    ↔️ Internas
                </a>
                <a href="<?= $appPath ?>/trocas/externas"
                   class="subnav-link <?= str_starts_with($rota,'trocas/externas')||str_starts_with($rota,'trocas/parceiro') ? 'ativa' : '' ?>">
                    🤝 Externas
                </a>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($emScanner): ?>
        <div class="trocas-subnav">
            <div class="container">
                <a href="<?= $appPath ?>/scanner"
                   class="subnav-link <?= $rota==='scanner' || str_starts_with($rota,'scanner/camera') ? 'ativa' : '' ?>">
                    📷 Câmera
                </a>
                <a href="<?= $appPath ?>/scanner/voz"
                   class="subnav-link <?= str_starts_with($rota,'scanner/voz') ? 'ativa' : '' ?>">
                    🎙️ Voz
                </a>
            </div>
        </div>
        <?php endif; ?>
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
