<?php
// pages/scanner_voz.php — Scanner por voz integrado ao IFC
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Álbuns do usuário
$stmt = $db->prepare("
    SELECT id, nome FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY criado_em DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

// Carrega seleções do banco para montar dicionário fonético real
$stmt = $db->prepare("
    SELECT s.sigla, s.nome, g.codigo AS grupo_codigo,
           COUNT(f.id) AS total_figurinhas
    FROM " . tbl('selecoes') . " s
    LEFT JOIN " . tbl('grupos')    . " g ON g.id = s.grupo_id
    LEFT JOIN " . tbl('figurinhas'). " f ON f.selecao_id = s.id
    GROUP BY s.id, s.sigla, s.nome, g.codigo
    ORDER BY g.ordem, s.sigla
");
$stmt->execute();
$selecoes = $stmt->fetchAll();

// Monta dicionário fonético: NOME_MAIUSCULO => SIGLA
$dicionario = [];
$mapaGrupos = []; // grupo => [siglas]
foreach ($selecoes as $s) {
    $nome = mb_strtoupper($s['nome']);
    $dicionario[$nome] = $s['sigla'];
    if ($s['grupo_codigo']) {
        $mapaGrupos[$s['grupo_codigo']][] = $s['sigla'];
    }
}

// Mapa de limites: sigla => total de figurinhas
$limites = [];
foreach ($selecoes as $s) {
    $limites[$s['sigla']] = (int)$s['total_figurinhas'];
}

layoutInicio('Scanner — Voz');
?>

<div class="scanner-wrap">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title">Scanner por Voz</h1>
    </div>

    <!-- Seletor de álbum -->
    <div class="scanner-config">
        <label for="voz-album"><strong>Álbum:</strong></label>
        <select id="voz-album">
            <?php foreach ($albuns as $album): ?>
                <option value="<?= $album['id'] ?>">
                    <?= htmlspecialchars($album['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Status e console -->
    <div class="card" style="margin-bottom:1rem">
        <div class="status-box">
            <div class="status-line">
                <span>Microfone:</span>
                <span id="mic-status" class="badge badge-danger">Desativado</span>
            </div>
            <div class="status-line">
                <span>Trava de confirmação:</span>
                <span id="lock-status" class="badge badge-danger">🔒 Travado</span>
            </div>
        </div>

        <p style="font-weight:600;margin-bottom:.4rem">Console:</p>
        <div id="transcript-console" class="transcript-box">
            [Clique em Iniciar para ativar]
        </div>

        <div class="btn-group" style="margin-top:.75rem">
            <button id="btn-toggle-mic" class="btn btn-primary" onclick="toggleMicrofone()">
                🎤 Iniciar Ditado
            </button>
            <button class="btn btn-secondary" onclick="toggleTravaManual()">
                🔓 Alternar Trava
            </button>
        </div>

        <div class="help-box" style="margin-top:1rem;font-size:.82rem">
            <strong>🗣️ Comandos de voz:</strong>
            <ul style="margin:.4rem 0 0 1rem;padding:0;color:#333">
                <li>Fale <strong>"Brasil 5"</strong> → adiciona BRA05</li>
                <li>Fale <strong>"Grupo A completo"</strong> → adiciona todo o grupo</li>
                <li>Fale <strong>"Brasil completo"</strong> → adiciona toda a seleção</li>
                <li>Fale <strong>"Excluir Brasil 5"</strong> → remove BRA05</li>
                <li>Fale <strong>"Corrigir"</strong> → remove o último item</li>
                <li>Fale <strong>"Habilitar confirmar com voz"</strong> → destrava</li>
                <li>Fale <strong>"Confirmar"</strong> → salva o lote (se destravado)</li>
            </ul>
        </div>
    </div>

    <!-- Tabela de figurinhas na fila -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
            <h3 style="margin:0">📋 Fila de Figurinhas</h3>
            <span id="total-badge" class="badge badge-info">0 itens</span>
        </div>

        <div class="table-responsive" style="margin-top:.75rem">
            <table id="tabela-figurinhas">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>País</th>
                        <th>Qtd</th>
                        <th class="no-print">Ajuste</th>
                    </tr>
                </thead>
                <tbody id="corpo-tabela">
                    <tr id="linha-vazia">
                        <td colspan="4" style="text-align:center;color:#888;padding:2rem">
                            Fila vazia. Fale no microfone ou importe um CSV.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="btn-group no-print" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--borda)">
            <button class="btn btn-primary"   onclick="confirmarLote()">💾 Salvar Lote</button>
            <button class="btn btn-secondary" onclick="exportarCSV()">📤 Exportar CSV</button>
            <button class="btn btn-secondary" onclick="document.getElementById('input-importar').click()">📥 Importar CSV</button>
            <button class="btn btn-secondary" onclick="window.print()">🖨️ Imprimir</button>
            <button class="btn btn-danger"    onclick="limparTabela()">✕ Limpar</button>
        </div>
        <input type="file" id="input-importar" style="display:none" accept=".csv" onchange="importarCSV(event)">
    </div>

    <!-- Log de confirmados -->
    <div id="log-wrap" class="log-wrap" style="display:none">
        <h3>Adicionadas nesta sessão</h3>
        <div id="log-itens"></div>
    </div>
</div>

<style>
.status-box   { background:#fdfbf7; padding:1rem; border-radius:var(--radius); border-left:5px solid var(--amarelo); margin-bottom:1rem; display:flex; flex-direction:column; gap:.6rem; }
.status-line  { display:flex; justify-content:space-between; align-items:center; font-weight:700; }
.badge        { padding:.3rem .6rem; border-radius:4px; font-size:.82rem; color:#fff; }
.badge-danger  { background:#dc3545; }
.badge-success { background:#28a745; }
.badge-info    { background:#17a2b8; }
.badge-warning { background:#ffc107; color:#222; }
.transcript-box { background:#222; color:#00ff00; font-family:monospace; padding:.75rem; border-radius:var(--radius); height:110px; overflow-y:auto; font-size:.88rem; border:2px solid #333; }
.btn-group    { display:flex; flex-direction:column; gap:.5rem; }
.help-box     { background:#e9ecef; padding:.85rem; border-radius:var(--radius); }
.table-responsive { width:100%; max-height:360px; overflow-y:auto; overflow-x:auto; border:1px solid var(--borda); border-radius:4px; }
table { width:100%; border-collapse:collapse; text-align:left; }
th    { background:#f8f9fa; font-weight:600; position:sticky; top:0; z-index:10; box-shadow:inset 0 -1px 0 var(--borda); }
th, td { padding:.65rem .5rem; border-bottom:1px solid var(--borda); }
.grande-btn { padding:.5rem 1rem; font-size:1.2rem; font-weight:700; border-radius:6px; border:1px solid var(--borda); background:#f8f9fa; cursor:pointer; }
.grande-btn:active { background:#e2e6ea; }
.btn-remover-linha { padding:.5rem; background:none; border:none; color:#dc3545; cursor:pointer; }
.card { background:#fff; border-radius:var(--radius); padding:1.25rem; border:1px solid var(--borda); margin-bottom:1rem; }
@media(min-width:576px){ .btn-group { flex-direction:row; flex-wrap:wrap; } }
@media print {
    .header,.btn-group,.status-box,.transcript-box,.grande-btn,.btn-remover-linha,.no-print { display:none !important; }
    .card { border:none; padding:0; }
    .table-responsive { max-height:none; overflow:visible; }
    table { border:1px solid #000; }
    th,td { border:1px solid #000; padding:.4rem; }
}
</style>

<script>
// ── Dados do banco ────────────────────────────────────────────────────────
const dicionarioFonetico = <?= json_encode($dicionario, JSON_UNESCAPED_UNICODE) ?>;
const mapaGrupos         = <?= json_encode($mapaGrupos, JSON_UNESCAPED_UNICODE) ?>;
const limitesMaximos     = <?= json_encode($limites, JSON_UNESCAPED_UNICODE) ?>;

// ── Estado ────────────────────────────────────────────────────────────────
let inventario             = {};
let reconhecimentoVoz      = null;
let microfoneAtivo         = false;
let sistemaPausado         = false;
let confirmarPorVozLiberado = false;

const consoleVoz   = document.getElementById('transcript-console');
const micBadge     = document.getElementById('mic-status');
const lockBadge    = document.getElementById('lock-status');
const btnToggleMic = document.getElementById('btn-toggle-mic');

function obterLimite(sigla) {
    return limitesMaximos[sigla] ?? 20;
}

function encontrarNomePorSigla(sigla) {
    for (const [nome, s] of Object.entries(dicionarioFonetico)) {
        if (s === sigla) return nome;
    }
    return sigla;
}

// ── Reconhecimento de voz ─────────────────────────────────────────────────
function atualizarBadgeMic() {
    if (!microfoneAtivo) {
        micBadge.textContent = 'Desativado'; micBadge.className = 'badge badge-danger';
    } else if (sistemaPausado) {
        micBadge.textContent = '⏸️ Pausado'; micBadge.className = 'badge badge-warning';
    } else {
        micBadge.textContent = 'Ouvindo...'; micBadge.className = 'badge badge-success';
    }
}

function iniciarWebSpeech() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { logConsole('❌ Navegador incompatível com reconhecimento de voz.'); return; }

    reconhecimentoVoz = new SR();
    reconhecimentoVoz.continuous    = false;
    reconhecimentoVoz.interimResults = false;
    reconhecimentoVoz.lang           = 'pt-BR';

    reconhecimentoVoz.onstart  = () => { microfoneAtivo = true;  atualizarBadgeMic(); };
    reconhecimentoVoz.onend    = () => { if (microfoneAtivo) reconhecimentoVoz.start(); else { atualizarBadgeMic(); btnToggleMic.textContent = '🎤 Iniciar Ditado'; btnToggleMic.className = 'btn btn-primary'; } };
    reconhecimentoVoz.onerror  = e  => { if (e.error !== 'no-speech') logConsole('Aviso: ' + e.error); };
    reconhecimentoVoz.onresult = e  => processarTextoDito(e.results[e.results.length - 1][0].transcript);
}

function toggleMicrofone() {
    if (!reconhecimentoVoz) iniciarWebSpeech();
    if (!reconhecimentoVoz) return;
    if (microfoneAtivo) {
        microfoneAtivo = false; sistemaPausado = false; reconhecimentoVoz.stop();
        btnToggleMic.textContent = '🎤 Iniciar Ditado'; btnToggleMic.className = 'btn btn-primary';
    } else {
        microfoneAtivo = true; sistemaPausado = false; reconhecimentoVoz.start();
        btnToggleMic.textContent = '⏹️ Parar Ditado'; btnToggleMic.className = 'btn btn-danger';
        logConsole('🎤 Ouvinte ativo!');
    }
}

function toggleTravaManual() {
    confirmarPorVozLiberado = !confirmarPorVozLiberado;
    lockBadge.textContent = confirmarPorVozLiberado ? '🔓 Liberado' : '🔒 Travado';
    lockBadge.className   = confirmarPorVozLiberado ? 'badge badge-success' : 'badge badge-danger';
}

function logConsole(msg) {
    const p = document.createElement('div');
    p.textContent = '> ' + msg;
    consoleVoz.appendChild(p);
    consoleVoz.scrollTop = consoleVoz.scrollHeight;
}

// ── Processamento de voz ──────────────────────────────────────────────────
function processarTextoDito(textoBruto) {
    let texto = textoBruto.toUpperCase().trim();

    // Pausar / retomar
    if (texto.includes('PAUSAR DITADO') || texto.includes('CONGELAR DITADO')) {
        sistemaPausado = true; atualizarBadgeMic(); logConsole('⏸️ Pausado.'); emitirSom(false); return;
    }
    if (texto.includes('CONTINUAR DITADO') || texto.includes('RETOMAR DITADO')) {
        sistemaPausado = false; atualizarBadgeMic(); logConsole('▶️ Retomado!'); emitirSom(true); return;
    }
    if (sistemaPausado) return;

    logConsole(`Capturado: "${textoBruto}"`);

    // Limpar tudo
    if (['EXCLUIR TODAS','LIMPAR TUDO','APAGAR TUDO'].includes(texto)) {
        inventario = {}; renderizarTabela(); logConsole('✕ Fila limpa.'); emitirSom(false); return;
    }

    // Habilitar confirmação por voz
    if (texto.includes('HABILITAR CONFIRMAR COM VOZ')) {
        confirmarPorVozLiberado = true;
        lockBadge.textContent = '🔓 Liberado'; lockBadge.className = 'badge badge-success';
        logConsole('🔓 Confirmação destravada!'); emitirSom(true); return;
    }

    // Confirmar / salvar
    if (texto === 'CONFIRMAR' || texto === 'SALVAR') {
        if (confirmarPorVozLiberado) confirmarLote();
        else { logConsole('🔒 Bloqueado! Libere com voz.'); emitirSom(false); }
        return;
    }

    // Corrigir (remove último)
    if (texto.includes('CORRIGIR') || texto.includes('APAGAR') || texto.includes('VOLTAR')) {
        removerUltimoItem(); emitirSom(false); return;
    }

    // Grupo X completo
    if (texto.includes('GRUPO') && (texto.includes('COMPLETO') || texto.includes('TODAS') || texto.includes('TODOS'))) {
        let textoC = texto.replace(/\bCAR\b/g,'K').replace(/\bCARRO\b/g,'K').replace(/\bKILO\b/g,'K').replace(/\bINDIA\b/g,'I').replace(/\bESCOLA\b/g,'E');
        const matchG = textoC.match(/GRUPO\s+([A-Z])/);
        if (matchG && mapaGrupos[matchG[1]]) {
            const letra = matchG[1];
            logConsole(`📦 Inserindo Grupo ${letra}...`);
            mostrarLoading(`Processando Grupo ${letra}...`);
            setTimeout(() => {
                mapaGrupos[letra].forEach(sigla => {
                    const lim = obterLimite(sigla);
                    const nome = encontrarNomePorSigla(sigla);
                    for (let n = 1; n <= lim; n++) adicionarItem(sigla + String(n).padStart(2,'0'), nome, 1);
                });
                esconderLoading();
                emitirSom(true);
            }, 50);
            return;
        }
    }

    // País completo
    if (texto.includes('COMPLETO') || texto.includes('TODAS') || texto.includes('TODOS')) {
        let textoSem = texto.replace('COMPLETO','').replace('TODAS','').replace('TODOS','').replace(/[^A-ZÃÉÍÓÚÇ ]/g,'').trim();
        for (const [chave, sigla] of Object.entries(dicionarioFonetico)) {
            if (textoSem.includes(chave) || (chave.includes(textoSem) && textoSem.length > 2)) {
                const lim = obterLimite(sigla);
                logConsole(`📦 Lote: ${chave} (01 a ${lim})`);
                mostrarLoading(`Processando ${chave}...`);
                setTimeout(() => {
                    for (let n = 1; n <= lim; n++) adicionarItem(sigla + String(n).padStart(2,'0'), chave, 1);
                    esconderLoading();
                    emitirSom(true);
                }, 50);
                return;
            }
        }
    }

    // Excluir grupo
    if (texto.includes('GRUPO') && (texto.includes('EXCLUIR') || texto.includes('APAGAR'))) {
        const matchG = texto.match(/(?:EXCLUIR|APAGAR)\s+GRUPO\s+([A-Z])/);
        if (matchG && mapaGrupos[matchG[1]]) {
            let del = 0;
            mapaGrupos[matchG[1]].forEach(sigla => {
                for (const cod in inventario) { if (cod.startsWith(sigla)) { delete inventario[cod]; del++; } }
            });
            if (del > 0) { renderizarTabela(); logConsole(`🗑️ ${del} itens do Grupo ${matchG[1]} removidos.`); emitirSom(true); }
            else { logConsole(`⚠️ Nenhum item do Grupo ${matchG[1]}.`); emitirSom(false); }
            return;
        }
    }

    // Excluir país (ex: "excluir brasil" ou "excluir brasil 5")
    if (texto.startsWith('EXCLUIR ') || texto.startsWith('APAGAR ')) {
        let textoSem = texto.replace('EXCLUIR ','').replace('APAGAR ','').replace(/ TODOS| TUDO| TODAS/g,'').trim();
        const numEx = textoSem.match(/\d+/);
        let numItem = numEx ? parseInt(numEx[0]) : null;
        if (numEx) textoSem = textoSem.replace(numEx[0],'').trim();
        for (const [chave, sigla] of Object.entries(dicionarioFonetico)) {
            if (textoSem.includes(chave) || (chave.includes(textoSem) && textoSem.length > 2)) {
                if (numItem !== null) {
                    const cod = sigla + String(numItem).padStart(2,'0');
                    if (inventario[cod]) { delete inventario[cod]; renderizarTabela(); logConsole(`🗑️ Removido: ${cod}`); emitirSom(true); }
                    else { logConsole(`⚠️ ${cod} não encontrado.`); emitirSom(false); }
                } else {
                    let del = 0;
                    for (const cod in inventario) { if (cod.startsWith(sigla)) { delete inventario[cod]; del++; } }
                    if (del > 0) { renderizarTabela(); logConsole(`🗑️ ${del} itens de ${chave} removidos.`); emitirSom(true); }
                    else { logConsole(`⚠️ Nenhum item de ${chave}.`); emitirSom(false); }
                }
                return;
            }
        }
    }

    // Adicionar figurinha individual: "Brasil 5"
    const numerosEncontrados = texto.match(/\d+/);
    let numeroReal = numerosEncontrados ? parseInt(numerosEncontrados[0]) : null;
    if (numeroReal === null) return;

    let textoSemNum = texto.replace(numerosEncontrados[0],'').replace(/[^A-ZÃÉÍÓÚÇ ]/g,'').trim();

    for (const [chave, sigla] of Object.entries(dicionarioFonetico)) {
        if (textoSemNum.includes(chave) || (chave.includes(textoSemNum) && textoSemNum.length > 2)) {
            const lim = obterLimite(sigla);
            if (numeroReal < 1 || numeroReal > lim) {
                logConsole(`❌ Inválido! ${chave} vai de 01 a ${lim}.`); emitirSom(false); return;
            }
            const cod = sigla + String(numeroReal).padStart(2,'0');
            adicionarItem(cod, chave, 1);
            logConsole(`Adicionado: ${cod}`); emitirSom(true); return;
        }
    }

    logConsole(`⚠️ Não entendi: "${textoSemNum}"`); emitirSom(false);
}

// ── Tabela ────────────────────────────────────────────────────────────────
function adicionarItem(codigo, selecao, qtd) {
    if (inventario[codigo]) inventario[codigo].quantidade += qtd;
    else inventario[codigo] = { codigo, selecao, quantidade: qtd };
    renderizarTabela();
}

function removerUltimoItem() {
    const chaves = Object.keys(inventario);
    if (!chaves.length) return;
    const ultima = chaves[chaves.length - 1];
    logConsole(`🗑️ Removido: ${ultima}`); delete inventario[ultima]; renderizarTabela();
}

function alterarQuantidade(codigo, delta) {
    if (!inventario[codigo]) return;
    inventario[codigo].quantidade += delta;
    if (inventario[codigo].quantidade <= 0) delete inventario[codigo];
    renderizarTabela();
}

function deletarItem(codigo) { delete inventario[codigo]; renderizarTabela(); }
function limparTabela() { inventario = {}; renderizarTabela(); }

function renderizarTabela() {
    const corpo = document.getElementById('corpo-tabela');
    const linhaVazia = document.getElementById('linha-vazia');
    const totalBadge = document.getElementById('total-badge');
    [...corpo.querySelectorAll('tr')].forEach(l => { if (l.id !== 'linha-vazia') l.remove(); });
    const itens = Object.values(inventario);
    totalBadge.textContent = `${itens.length} item(ns)`;
    if (!itens.length) { linhaVazia.style.display = ''; return; }
    linhaVazia.style.display = 'none';
    itens.sort((a,b) => a.codigo.localeCompare(b.codigo));
    itens.forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><strong>${item.codigo}</strong></td><td>${item.selecao}</td>
            <td><strong style="font-size:1.1rem">${item.quantidade}</strong></td>
            <td class="no-print" style="display:flex;gap:.3rem;align-items:center">
                <button class="grande-btn" onclick="alterarQuantidade('${item.codigo}',1)">+</button>
                <button class="grande-btn" onclick="alterarQuantidade('${item.codigo}',-1)">−</button>
                <button class="btn-remover-linha" onclick="deletarItem('${item.codigo}')">✕</button>
            </td>`;
        corpo.appendChild(tr);
    });
}

// ── Salvar lote no banco ──────────────────────────────────────────────────
async function confirmarLote() {
    const itens = Object.values(inventario);
    if (!itens.length) { alert('Fila vazia!'); return; }

    const albumId = document.getElementById('voz-album').value;
    const itensSalvar = itens.map(i => ({ codigo: i.codigo, nome: i.selecao, qtd: i.quantidade }));

    mostrarLoading(`Salvando ${itens.length} figurinhas...`, false);

    try {
        const resp = await fetch('/api/scanner', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'confirmar=1&itens=' + encodeURIComponent(JSON.stringify(itensSalvar)) + '&album_id=' + albumId,
        });
        const data = await resp.json();

        if (data.sucesso) {
            const log = document.getElementById('log-itens');
            document.getElementById('log-wrap').style.display = '';
            itens.forEach(item => {
                const p = document.createElement('p');
                p.textContent = `✓ ${item.codigo} — ${item.selecao} (+${item.quantidade})`;
                p.style.color = 'green'; p.style.margin = '2px 0';
                log.prepend(p);
            });
            confirmarPorVozLiberado = false;
            lockBadge.textContent = '🔒 Travado'; lockBadge.className = 'badge badge-danger';
            limparTabela();
            logConsole(`✅ ${itens.length} figurinha(s) salva(s)!`);
        } else {
            alert('Erro ao salvar: ' + (data.erro ?? 'desconhecido'));
        }
    } catch (err) {
        alert('Erro de conexão.');
    } finally {
        esconderLoading();
    }
}

// ── CSV ───────────────────────────────────────────────────────────────────
function exportarCSV() {
    const itens = Object.values(inventario);
    if (!itens.length) { alert('Fila vazia!'); return; }
    itens.sort((a,b) => a.codigo.localeCompare(b.codigo));
    let csv = '\uFEFFCodigo;Quantidade;Codigo;Quantidade;Codigo;Quantidade;Codigo;Quantidade\n';
    for (let i = 0; i < itens.length; i += 4) {
        let linha = [];
        for (let j = 0; j < 4; j++) {
            const idx = i + j;
            if (idx < itens.length) { linha.push(itens[idx].codigo, itens[idx].quantidade); }
            else { linha.push('',''); }
        }
        csv += linha.join(';') + '\n';
    }
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    link.download = `inventario_voz_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}

function importarCSV(event) {
    const arquivo = event.target.files[0]; if (!arquivo) return;
    const leitor = new FileReader();
    leitor.onload = e => {
        const linhas = e.target.result.split(/\r?\n/);
        let carregados = 0;
        linhas.forEach((linha, i) => {
            if (i === 0 || !linha.trim()) return;
            const cols = linha.split(';');
            for (let j = 0; j < cols.length; j += 2) {
                if (cols[j] && cols[j].trim()) {
                    const cod = cols[j].trim().toUpperCase();
                    const qtd = parseInt(cols[j+1]) || 1;
                    if (cod && cod !== 'CODIGO') {
                        let nomePais = 'Importado';
                        for (const [nome, s] of Object.entries(dicionarioFonetico)) {
                            if (cod.startsWith(s)) { nomePais = nome; break; }
                        }
                        adicionarItem(cod, nomePais, qtd); carregados++;
                    }
                }
            }
        });
        alert(`${carregados} figurinhas carregadas!`);
        event.target.value = '';
    };
    leitor.readAsText(arquivo);
}

// ── Som de feedback ───────────────────────────────────────────────────────
function emitirSom(sucesso) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator(), gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(sucesso ? 880 : 220, ctx.currentTime);
        gain.gain.setValueAtTime(0.1, ctx.currentTime);
        osc.start(); osc.stop(ctx.currentTime + (sucesso ? 0.1 : 0.25));
    } catch(e) {}
}

window.onload = () => iniciarWebSpeech();
</script>

<?php layoutFim(); ?>
