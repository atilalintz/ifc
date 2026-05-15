<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Busca álbuns do usuário para selecionar qual atualizar
$stmt = $db->prepare("
    SELECT id, nome FROM albuns
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY criado_em DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Scanner');
?>

<div class="scanner-wrap">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title">Scanner de Figurinhas</h1>
    </div>

    <!-- Seleção de álbum -->
    <div class="scanner-config">
        <label for="scanner-album"><strong>Álbum:</strong></label>
        <select id="scanner-album">
            <?php foreach ($albuns as $album): ?>
                <option value="<?= $album['id'] ?>">
                    <?= htmlspecialchars($album['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Câmera -->
    <div class="scanner-camera-wrap">
        <video id="camera" autoplay playsinline muted></video>
        <canvas id="canvas" style="display:none"></canvas>
        <div class="scanner-mira">
	    <div class="mira-canto"></div>
	    <p class="mira-dica">Alinhe o código aqui ↗</p>
	</div>
        <div id="scanner-status" class="scanner-status">Iniciando câmera...</div>
    </div>

    <!-- Controles -->
    <div class="scanner-controles">
        <button id="btn-capturar" class="btn btn-primary" onclick="capturar()">
            📷 Capturar
        </button>
        <button id="btn-continuo" class="btn btn-secondary" onclick="toggleContinuo()">
            🔄 Modo contínuo: OFF
        </button>
    </div>

    <!-- Resultado do OCR -->
    <div id="resultado-wrap" class="resultado-wrap" style="display:none">
        <h3>Figurinhas detectadas</h3>
        <div id="lista-detectadas" class="lista-detectadas"></div>
        <div class="resultado-acoes">
            <button class="btn btn-primary" onclick="confirmarTodas()">✓ Confirmar todas</button>
            <button class="btn btn-sm" onclick="limparResultado()">✕ Limpar</button>
        </div>
    </div>

    <!-- Log de confirmações -->
    <div id="log-wrap" class="log-wrap" style="display:none">
        <h3>Adicionadas nesta sessão</h3>
        <div id="log-itens"></div>
    </div>
</div>

<!-- Tesseract.js via CDN -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<script>
let stream        = null;
let worker        = null;
let modoContinuo  = false;
let intervalo     = null;
let processando   = false;
let detectadas    = {}; // codigo → quantidade

const video   = document.getElementById('camera');
const canvas  = document.getElementById('canvas');
const status  = document.getElementById('scanner-status');
const ctx     = canvas.getContext('2d');

// ── Inicia câmera ───────────────────────────
async function iniciarCamera() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: 1280, height: 720 }
        });
        video.srcObject = stream;
        status.textContent = 'Câmera pronta. Aponte para as figurinhas.';
        status.className = 'scanner-status ok';
    } catch (e) {
        status.textContent = 'Erro ao acessar câmera: ' + e.message;
        status.className = 'scanner-status erro';
    }
}

// ── Inicia Tesseract ────────────────────────
async function iniciarTesseract() {
    status.textContent = 'Carregando OCR...';
    worker = await Tesseract.createWorker('eng', 1, {
        logger: m => {
            if (m.status === 'recognizing text') {
                status.textContent = 'Lendo... ' + Math.round(m.progress * 100) + '%';
            }
        }
    });
    // Configura para reconhecer apenas letras maiúsculas e números
    await worker.setParameters({
        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    });
    await iniciarCamera();
}

// ── Captura frame e faz OCR ─────────────────
async function capturar() {
    if (processando || !worker) return;
    processando = true;
    status.textContent = 'Processando...';
    status.className = 'scanner-status';

    const w = video.videoWidth;
    const h = video.videoHeight;

    canvas.width  = w;
    canvas.height = h;
    ctx.drawImage(video, 0, 0);

    // ── Recorta canto superior direito (25% largura x 20% altura) ──
    const recorteX = Math.floor(w * 0.70); // começa em 70% da largura
    const recorteY = 0;                     // começa do topo
    const recorteW = Math.floor(w * 0.30); // 30% da largura
    const recorteH = Math.floor(h * 0.25); // 25% da altura

    // Canvas auxiliar para o recorte ampliado
    const canvasRecorte = document.createElement('canvas');
    canvasRecorte.width  = recorteW * 3; // amplia 3x para o OCR
    canvasRecorte.height = recorteH * 3;
    const ctxR = canvasRecorte.getContext('2d');

    // Amplia o recorte
    ctxR.drawImage(canvas,
        recorteX, recorteY, recorteW, recorteH,
        0, 0, canvasRecorte.width, canvasRecorte.height
    );

    // ── Pré-processamento: detecta fundo e binariza ──
	const imgData = ctxR.getImageData(0, 0, canvasRecorte.width, canvasRecorte.height);
	const pixels  = imgData.data;

	// Calcula brilho médio para detectar se fundo é escuro ou claro
	let somaGray = 0;
	for (let i = 0; i < pixels.length; i += 4) {
	    somaGray += 0.299 * pixels[i] + 0.587 * pixels[i+1] + 0.114 * pixels[i+2];
	}
	const mediaGray = somaGray / (pixels.length / 4);
	const fundoEscuro = mediaGray < 128; // true = fundo escuro, texto claro

	for (let i = 0; i < pixels.length; i += 4) {
	    const gray = 0.299 * pixels[i] + 0.587 * pixels[i+1] + 0.114 * pixels[i+2];
	    // Se fundo escuro: inverte (texto branco → preto para OCR)
	    // Se fundo claro: normal (texto escuro → preto para OCR)
	    const bin = fundoEscuro ? (gray > 128 ? 0 : 255) : (gray < 128 ? 0 : 255);
	    pixels[i] = pixels[i+1] = pixels[i+2] = bin;
	}
	ctxR.putImageData(imgData, 0, 0);

    const imageData = canvasRecorte.toDataURL('image/png');
    const { data: { text } } = await worker.recognize(imageData);

    // Debug — remova depois que estiver funcionando
    status.textContent = 'OCR: ' + text.substring(0, 150);

    // Regex aceita BRA01, BRA 01, BRA  1, etc.
    const regex = /\b([A-Z]{2,3})\s*(\d{1,2})\b/g;
    const codigos = [];
    let m;
    while ((m = regex.exec(text)) !== null) {
        const codigo = m[1] + m[2].padStart(2, '0');
        codigos.push(codigo);
    }
    const codigosUnicos = [...new Set(codigos)];

    if (codigosUnicos.length > 0) {
        await validarCodigos(codigosUnicos);
    } else {
        status.textContent = 'Nenhum código detectado. Tente novamente.';
        status.className = 'scanner-status erro';
    }

    processando = false;
}

// ── Valida códigos contra o banco ───────────
async function validarCodigos(codigos) {
    const albumId = document.getElementById('scanner-album').value;

    const resp = await fetch('/api/scanner', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'codigos=' + encodeURIComponent(JSON.stringify(codigos)) + '&album_id=' + albumId
    });
    const data = await resp.json();

    if (data.validos && data.validos.length > 0) {
        mostrarDetectadas(data.validos);
        status.textContent = `${data.validos.length} código(s) detectado(s)!`;
        status.className = 'scanner-status ok';
    } else {
        status.textContent = 'Nenhum código válido encontrado.';
        status.className = 'scanner-status erro';
    }
}

// ── Mostra lista de figurinhas detectadas ───
function mostrarDetectadas(validos) {
    const wrap = document.getElementById('resultado-wrap');
    const lista = document.getElementById('lista-detectadas');
    wrap.style.display = '';

    validos.forEach(fig => {
        if (!detectadas[fig.codigo]) {
            detectadas[fig.codigo] = { ...fig, qtd: 1 };
        } else {
            detectadas[fig.codigo].qtd++;
        }
    });

    lista.innerHTML = '';
    Object.values(detectadas).forEach(fig => {
        const div = document.createElement('div');
        div.className = 'detectada-item';
        div.innerHTML = `
            <span class="det-codigo">${fig.codigo}</span>
            <span class="det-nome">${fig.nome}</span>
            <div class="det-controles">
                <button onclick="ajustarDetectada('${fig.codigo}', -1)">−</button>
                <span id="det-qtd-${fig.codigo}">${fig.qtd}</span>
                <button onclick="ajustarDetectada('${fig.codigo}', 1)">+</button>
                <button class="det-remover" onclick="removerDetectada('${fig.codigo}')">✕</button>
            </div>
        `;
        lista.appendChild(div);
    });
}

function ajustarDetectada(codigo, delta) {
    if (!detectadas[codigo]) return;
    detectadas[codigo].qtd = Math.max(1, detectadas[codigo].qtd + delta);
    document.getElementById(`det-qtd-${codigo}`).textContent = detectadas[codigo].qtd;
}

function removerDetectada(codigo) {
    delete detectadas[codigo];
    mostrarDetectadas([]);
    if (Object.keys(detectadas).length === 0) limparResultado();
}

// ── Confirma todas as figurinhas detectadas ─
async function confirmarTodas() {
    const albumId = document.getElementById('scanner-album').value;
    const itens   = Object.values(detectadas);
    if (itens.length === 0) return;

    const resp = await fetch('/api/scanner', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'confirmar=1&itens=' + encodeURIComponent(JSON.stringify(itens)) + '&album_id=' + albumId
    });
    const data = await resp.json();

    if (data.sucesso) {
        // Adiciona ao log
        const log     = document.getElementById('log-itens');
        const logWrap = document.getElementById('log-wrap');
        logWrap.style.display = '';
        itens.forEach(fig => {
            const p = document.createElement('p');
            p.textContent = `✓ ${fig.codigo} — ${fig.nome} (+${fig.qtd})`;
            p.style.color = 'green';
            log.prepend(p);
        });
        limparResultado();
        status.textContent = `${itens.length} figurinha(s) adicionada(s)!`;
        status.className = 'scanner-status ok';
    }
}

function limparResultado() {
    detectadas = {};
    document.getElementById('resultado-wrap').style.display = 'none';
    document.getElementById('lista-detectadas').innerHTML = '';
}

// ── Modo contínuo ───────────────────────────
function toggleContinuo() {
    modoContinuo = !modoContinuo;
    const btn = document.getElementById('btn-continuo');
    if (modoContinuo) {
        btn.textContent = '🔄 Modo contínuo: ON';
        btn.style.background = 'var(--verde)';
        btn.style.color = '#fff';
        intervalo = setInterval(() => { if (!processando) capturar(); }, 3000);
    } else {
        btn.textContent = '🔄 Modo contínuo: OFF';
        btn.style.background = '';
        btn.style.color = '';
        clearInterval(intervalo);
    }
}

// Inicia tudo
iniciarTesseract();
</script>

<?php layoutFim(); ?>
