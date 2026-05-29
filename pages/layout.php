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

    <!-- Token CSRF: lido pelo JS para injetar em todo POST -->
    <meta name="csrf-token" content="<?= csrfToken() ?>">

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

<!-- Overlay de loading global -->
<div id="loading-overlay" style="display:none">
    <div class="loading-box">
        <div class="loading-spinner">⟳</div>
        <p id="loading-msg">Processando...</p>
        <div id="loading-progresso" style="display:none">
            <div class="loading-barra-wrap">
                <div class="loading-barra-fill" id="loading-barra-fill"></div>
            </div>
            <small id="loading-contador" style="color:#555;font-weight:600"></small>
        </div>
        <small class="loading-aviso">Não feche nem recarregue a página</small>
        <button id="loading-btn-cancelar" style="display:none"
                onclick="cancelarProcessamento()"
                class="loading-btn-cancel">
            ✕ Cancelar processo
        </button>
        <small id="loading-aviso-cancel" style="display:none;color:#dc2626;font-size:.75rem">
            ⚠️ Cancelar pode deixar dados incompletos
        </small>
    </div>
</div>

<?php }

function layoutFim(): void { ?>
    </main>
    <footer class="footer">
        <div class="container">IFC — Inventário de Figurinhas da Copa 2026</div>
    </footer>
    <script>
    // ── Token CSRF global ─────────────────────────────────────────────────
    // Lê o token da meta tag uma única vez e armazena na constante.
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ── Interceptor de fetch ──────────────────────────────────────────────
    // Sobrescreve o fetch nativo para injetar csrf_token automaticamente
    // em todo POST com Content-Type: application/x-www-form-urlencoded.
    // Assim nenhuma página precisa ser alterada manualmente.
    const _fetchOriginal = window.fetch.bind(window);
    window.fetch = function(url, options = {}) {
        const method = (options.method ?? 'GET').toUpperCase();

        if (method === 'POST' && options.body instanceof URLSearchParams) {
            // Clona para não mutar o objeto original do chamador
            const body = new URLSearchParams(options.body);
            if (!body.has('csrf_token')) {
                body.append('csrf_token', CSRF_TOKEN);
            }
            options = { ...options, body };
        }

        return _fetchOriginal(url, options);
    };

    // ── Loading overlay global ────────────────────────────────────────────
    let _loadingCancelado = false;

    function mostrarLoading(msg = 'Processando...', comCancelar = false) {
        _loadingCancelado = false;
        document.getElementById('loading-msg').textContent          = msg;
        document.getElementById('loading-overlay').style.display    = 'flex';
        document.getElementById('loading-progresso').style.display  = 'none';
        document.getElementById('loading-barra-fill').style.width   = '0%';
        document.getElementById('loading-btn-cancelar').style.display = comCancelar ? '' : 'none';
        document.getElementById('loading-aviso-cancel').style.display = comCancelar ? '' : 'none';
        document.getElementById('loading-overlay').style.pointerEvents = 'all';
    }

    function atualizarProgresso(atual, total, msg = null) {
        const pct = total > 0 ? Math.round((atual / total) * 100) : 0;
        document.getElementById('loading-progresso').style.display  = '';
        document.getElementById('loading-barra-fill').style.width   = pct + '%';
        document.getElementById('loading-contador').textContent     = `${atual} / ${total} (${pct}%)`;
        if (msg) document.getElementById('loading-msg').textContent = msg;
    }

    function esconderLoading() {
        document.getElementById('loading-overlay').style.display = 'none';
        _loadingCancelado = false;
    }

    function cancelarProcessamento() {
        if (!confirm('Cancelar o processamento?\n\n⚠️ Os itens já processados serão mantidos, mas os restantes não serão atualizados.')) return;
        _loadingCancelado = true;
        esconderLoading();
    }

    function loadingFoiCancelado() {
        return _loadingCancelado;
    }
    </script>
</body>
</html>
<?php }
