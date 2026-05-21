<?php
// pages/trocas.php — Trocas: transferência entre álbuns + repetidas + matches
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

$stmt = $db->prepare("
    SELECT id, nome, total_repetidas, percentual_conclusao
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY nome
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Trocas — Copa 2026');
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">Trocas</h1>
        <p style="color:#888;font-size:.9rem;margin:0">
            Transfira entre seus álbuns, gerencie repetidas e encontre parceiros
        </p>
    </div>
</div>

<!-- ══ SEÇÃO 0: Transferência entre álbuns ══════════════════════════════════ -->
<div class="trocas-secao">
    <div class="trocas-secao-header">
        <h2 class="trocas-secao-titulo">↔️ Transferir entre Álbuns</h2>
    </div>
    <p class="trocas-secao-desc">
        Move figurinhas repetidas de um álbum para onde estão faltando.
    </p>

    <!-- Seletores origem / destino -->
    <div class="trocas-seletores">
        <div class="trocas-painel">
            <label class="trocas-label">📤 Origem <small>(tem repetidas)</small></label>
            <select id="sel-origem" onchange="carregarTransferencia()">
                <option value="">— selecione —</option>
                <?php foreach ($albuns as $a): ?>
                    <option value="<?= $a['id'] ?>">
                        <?= htmlspecialchars($a['nome']) ?>
                        (<?= $a['total_repetidas'] ?> repetidas)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="trocas-seta-meio">→</div>
        <div class="trocas-painel">
            <label class="trocas-label">📥 Destino <small>(receberá)</small></label>
            <select id="sel-destino" onchange="carregarTransferencia()">
                <option value="">— selecione —</option>
                <?php foreach ($albuns as $a): ?>
                    <option value="<?= $a['id'] ?>">
                        <?= htmlspecialchars($a['nome']) ?>
                        (<?= number_format($a['percentual_conclusao'],1) ?>%)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Barra de ação da transferência -->
    <div id="barra-transferir" style="display:none" class="acoes-barra" style="margin-top:.75rem">
        <span id="msg-transfer" style="font-size:.88rem;color:#555"></span>
        <button class="btn-sm" onclick="selecionarTodasTransfer()">Selecionar todas</button>
        <button class="btn-sm" onclick="limparTransfer()">Limpar</button>
        <button class="btn-sm btn-todas-inc" id="btn-transferir" onclick="executarTransferencia()" disabled>
            ✓ Transferir selecionadas
        </button>
    </div>

    <!-- Grid de figurinhas elegíveis -->
    <div id="transfer-estado" class="trocas-estado">
        Selecione origem e destino para ver as figurinhas disponíveis.
    </div>
    <div id="transfer-filtro" style="display:none;margin:.5rem 0">
        <input type="text" id="busca-transfer" placeholder="Buscar código ou país..."
               oninput="filtrarTransfer()"
               style="padding:.4rem .65rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem;width:100%;max-width:280px">
    </div>
    <div id="transfer-grid" style="display:none" class="lista-grupos-trocas"></div>
</div>

<!-- Seletor de álbum para seções 1 e 2 -->
<div class="acoes-barra" style="margin-bottom:1rem">
    <label style="font-weight:600;font-size:.9rem">📚 Álbum para trocas:</label>
    <select id="select-album" onchange="trocarAlbum()"
            style="padding:.4rem .6rem;border-radius:6px;border:1px solid #ddd;font-size:.9rem">
        <option value="">— selecione —</option>
        <?php foreach ($albuns as $a): ?>
            <option value="<?= $a['id'] ?>"
                    data-rep="<?= $a['total_repetidas'] ?>"
                    data-pct="<?= number_format($a['percentual_conclusao'],1) ?>">
                <?= htmlspecialchars($a['nome']) ?>
                (<?= $a['total_repetidas'] ?> repetidas · <?= number_format($a['percentual_conclusao'],1) ?>%)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div id="estado-inicial" class="trocas-estado">
    Selecione um álbum acima para ver suas repetidas e parceiros de troca.
</div>

<div id="conteudo-trocas" style="display:none">

    <!-- ══ SEÇÃO 1: Minhas repetidas ══════════════════════════════════════ -->
    <div class="trocas-secao">
        <div class="trocas-secao-header">
            <h2 class="trocas-secao-titulo">🔁 Minhas Repetidas</h2>
            <span id="badge-repetidas" class="trocas-badge">0</span>
        </div>
        <p class="trocas-secao-desc">
            Clique longo (ou botão direito no PC) para definir o status de cada figurinha.
        </p>
        <div class="status-filtros">
            <button class="status-pill ativa" data-status="" onclick="filtrarStatus(this)">Todas</button>
            <button class="status-pill" data-status="livre"     onclick="filtrarStatus(this)">🟢 Livre</button>
            <button class="status-pill" data-status="troca"     onclick="filtrarStatus(this)">🔄 Troca</button>
            <button class="status-pill" data-status="venda"     onclick="filtrarStatus(this)">💰 Venda</button>
            <button class="status-pill" data-status="bloqueada" onclick="filtrarStatus(this)">🔒 Bloqueada</button>
        </div>
        <div id="grid-repetidas" class="lista-grupos-trocas"></div>
        <div id="estado-sem-repetidas" class="trocas-estado" style="display:none">
            Nenhuma figurinha repetida neste álbum ainda.
        </div>
    </div>

    <!-- ══ SEÇÃO 2: Matches com outros usuários ═══════════════════════════ -->
    <div class="trocas-secao">
        <div class="trocas-secao-header">
            <h2 class="trocas-secao-titulo">🤝 Parceiros de Troca</h2>
            <span id="badge-matches" class="trocas-badge">0</span>
        </div>
        <p class="trocas-secao-desc">
            Usuários que têm o que você precisa ou precisam do que você tem.
        </p>
        <div id="lista-matches"></div>
        <div id="estado-sem-matches" class="trocas-estado" style="display:none">
            Nenhum parceiro encontrado ainda.<br>
            <small>Isso melhora conforme mais usuários cadastrarem seus álbuns.</small>
        </div>
        <div id="estado-calculando" class="trocas-estado" style="display:none">
            ⏳ Calculando matches...
        </div>
    </div>
</div>

<!-- Modal de status ────────────────────────────────────────────────────── -->
<div id="modal-status" class="modal-overlay" style="display:none" onclick="fecharModal(event)">
    <div class="modal-box">
        <div class="modal-titulo" id="modal-titulo-fig">Figurinha</div>
        <div class="modal-opcoes">
            <button class="modal-opcao" onclick="definirStatus('livre')">🟢 Livre (disponível para troca)</button>
            <button class="modal-opcao" onclick="definirStatus('troca')">🔄 Quero trocar</button>
            <button class="modal-opcao" onclick="definirStatus('venda')">💰 Quero vender</button>
            <button class="modal-opcao" onclick="definirStatus('bloqueada')">🔒 Bloquear (não negociar)</button>
        </div>
        <div id="campo-valor" style="display:none;margin-top:.75rem">
            <label style="font-size:.85rem;font-weight:600">Valor (R$):</label>
            <input type="number" id="input-valor" min="0" step="0.50" placeholder="Ex: 5.00"
                   style="width:100%;padding:.4rem;border-radius:6px;border:1px solid #ddd;margin-top:.3rem">
        </div>
        <div class="modal-rodape">
            <button class="btn-sm" onclick="confirmarStatus()">✓ Confirmar</button>
            <button class="btn-sm" onclick="document.getElementById('modal-status').style.display='none'">Cancelar</button>
        </div>
    </div>
</div>

<!-- Modal de contato ───────────────────────────────────────────────────── -->
<div id="modal-contato" class="modal-overlay" style="display:none" onclick="fecharModal(event)">
    <div class="modal-box">
        <div class="modal-titulo" id="modal-contato-nome"></div>
        <div id="modal-contato-corpo"></div>
        <div class="modal-rodape">
            <button class="btn-sm" onclick="document.getElementById('modal-contato').style.display='none'">Fechar</button>
        </div>
    </div>
</div>

<style>
.trocas-secao {
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.trocas-secao-header { display:flex; align-items:center; gap:.75rem; margin-bottom:.4rem; }
.trocas-secao-titulo { margin:0; font-size:1.05rem; }
.trocas-badge {
    background:#6366f1; color:#fff;
    border-radius:20px; padding:.1rem .55rem;
    font-size:.78rem; font-weight:700;
}
.trocas-secao-desc { color:#888; font-size:.85rem; margin:0 0 .75rem; }
.trocas-estado { text-align:center; padding:2rem 1rem; color:#aaa; font-size:.92rem; line-height:1.7; }

/* Seletores de transferência */
.trocas-seletores { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:.75rem; }
.trocas-painel { flex:1; min-width:200px; }
.trocas-label { display:block; font-weight:600; font-size:.85rem; margin-bottom:.3rem; }
.trocas-label small { font-weight:400; color:#888; }
.trocas-painel select {
    width:100%; padding:.42rem .6rem;
    border:1px solid var(--border,#ddd);
    border-radius:8px; font-size:.88rem;
    background:var(--bg,#fafafa);
}
.trocas-seta-meio { font-size:1.5rem; color:#bbb; padding-bottom:.1rem; }

/* Filtros de status */
.status-filtros { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.75rem; }
.status-pill {
    padding:.25rem .65rem; border-radius:20px;
    border:1px solid #ddd; background:#f5f5f5;
    font-size:.8rem; cursor:pointer;
}
.status-pill.ativa { background:#6366f1; color:#fff; border-color:#6366f1; }

/* Grid agrupado (compartilhado pelas duas seções) */
.lista-grupos-trocas .grupo-titulo {
    font-weight:700; font-size:.78rem; text-transform:uppercase;
    letter-spacing:.05em; color:#aaa; margin:.75rem 0 .3rem; padding-left:.2rem;
}
.lista-grupos-trocas .selecao-mini {
    display:flex; align-items:center; gap:.4rem;
    font-size:.82rem; font-weight:600; margin:.5rem 0 .3rem;
}
.lista-grupos-trocas .selecao-mini img { width:20px; height:14px; object-fit:cover; border-radius:2px; }

/* Card selecionado para transferência */
.figurinha-card.para-transferir {
    outline:2px solid #22c55e;
    background:#f0fdf4 !important;
}

/* Badge de status */
.fig-status-badge {
    position:absolute; bottom:2px; left:0; right:0;
    text-align:center; font-size:.6rem; line-height:1.3;
    pointer-events:none;
}

/* Cards de match */
.match-card {
    display:flex; align-items:center; gap:1rem;
    padding:.85rem 1rem;
    border:1px solid var(--border,#e0e0e0);
    border-radius:10px; margin-bottom:.6rem;
    background:var(--bg,#fafafa);
}
.match-avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; flex-shrink:0; background:#e0e0e0; }
.match-info { flex:1; min-width:0; }
.match-nome { font-weight:700; font-size:.95rem; }
.match-loc  { font-size:.78rem; color:#888; }
.match-exemplos { font-size:.78rem; color:#555; margin-top:.2rem; }
.match-score {
    background:#f0fdf4; color:#16a34a;
    border:1px solid #bbf7d0; border-radius:20px;
    padding:.2rem .6rem; font-size:.8rem; font-weight:700; white-space:nowrap;
}
.match-btn {
    padding:.35rem .8rem; border-radius:8px;
    background:#6366f1; color:#fff; border:none;
    font-size:.82rem; cursor:pointer; white-space:nowrap;
}
.match-btn:hover { background:#4f46e5; }

/* Modais */
.modal-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    z-index:1000; padding:1rem;
}
.modal-box {
    background:#fff; border-radius:14px; padding:1.5rem;
    width:100%; max-width:360px; box-shadow:0 8px 32px rgba(0,0,0,.18);
}
.modal-titulo { font-weight:700; font-size:1rem; margin-bottom:1rem; }
.modal-opcoes { display:flex; flex-direction:column; gap:.5rem; }
.modal-opcao {
    padding:.6rem .9rem; border-radius:8px; border:1px solid #e0e0e0;
    background:#fafafa; text-align:left; font-size:.9rem; cursor:pointer;
}
.modal-opcao:hover { background:#f0f0f0; }
.modal-rodape { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }

.contato-linha {
    display:flex; align-items:center; gap:.75rem;
    padding:.75rem; background:#f5f5f5; border-radius:8px; margin-bottom:.5rem;
}
.contato-icone { font-size:1.4rem; }
.contato-link { font-weight:600; font-size:.95rem; color:#6366f1; text-decoration:none; }
.contato-link:hover { text-decoration:underline; }

@media(max-width:600px){
    .trocas-seta-meio { display:none; }
    .match-card { flex-wrap:wrap; }
    .match-score { order:-1; }
}
</style>

<script>
// ════════════════════════════════════════════════════════════════
// ESTADO GLOBAL
// ════════════════════════════════════════════════════════════════
const trocas = {
    albumId:              null,
    figurinhaSelecionada: null,
    filtroStatus:         '',
};

const transfer = {
    origemId:    null,
    destinoId:   null,
    figurinhas:  [],          // elegíveis
    selecionadas: new Set(),
};

// ════════════════════════════════════════════════════════════════
// SEÇÃO 0 — TRANSFERÊNCIA ENTRE ÁLBUNS
// ════════════════════════════════════════════════════════════════

async function carregarTransferencia() {
    transfer.origemId  = document.getElementById('sel-origem').value  || null;
    transfer.destinoId = document.getElementById('sel-destino').value || null;

    const estado  = document.getElementById('transfer-estado');
    const grid    = document.getElementById('transfer-grid');
    const filtro  = document.getElementById('transfer-filtro');
    const barra   = document.getElementById('barra-transferir');

    // Reseta
    transfer.selecionadas.clear();
    grid.innerHTML    = '';
    grid.style.display   = 'none';
    filtro.style.display = 'none';
    barra.style.display  = 'none';

    if (!transfer.origemId || !transfer.destinoId) {
        estado.textContent = 'Selecione origem e destino para ver as figurinhas disponíveis.';
        estado.style.display = 'block';
        return;
    }
    if (transfer.origemId === transfer.destinoId) {
        estado.textContent = 'Origem e destino precisam ser álbuns diferentes.';
        estado.style.display = 'block';
        return;
    }

    estado.textContent   = '⏳ Carregando figurinhas...';
    estado.style.display = 'block';

    const [resOrigem, resDestino] = await Promise.all([
        fetchFigurinhasAlbum(transfer.origemId,  'origem'),
        fetchFigurinhasAlbum(transfer.destinoId, 'destino'),
    ]);

    if (!resOrigem.sucesso || !resDestino.sucesso) {
        estado.textContent = 'Erro ao carregar. Tente novamente.';
        return;
    }

    const faltamNoDestino = new Set(resDestino.figurinhas.map(f => f.figurinha_id));
    transfer.figurinhas   = resOrigem.figurinhas.filter(f => faltamNoDestino.has(f.figurinha_id));

    if (transfer.figurinhas.length === 0) {
        estado.textContent   = 'Nenhuma figurinha elegível — a origem não tem repetidas que o destino precise.';
        estado.style.display = 'block';
        return;
    }

    estado.style.display = 'none';
    filtro.style.display = 'block';
    grid.style.display   = 'block';
    barra.style.display  = '';

    renderizarGridTransfer();
    atualizarBarraTransfer();
}

async function fetchFigurinhasAlbum(albumId, modo) {
    try {
        const r = await fetch('/api/trocas', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=figurinhas_album&album_id=${albumId}&modo=${modo}`,
        });
        return await r.json();
    } catch { return { sucesso: false }; }
}

function renderizarGridTransfer() {
    const grid = document.getElementById('transfer-grid');
    grid.innerHTML = '';

    const grupos = {};
    for (const f of transfer.figurinhas) {
        const gKey = f.grupo_codigo || 'especial';
        if (!grupos[gKey]) grupos[gKey] = {};
        if (!grupos[gKey][f.selecao_sigla]) {
            grupos[gKey][f.selecao_sigla] = { nome: f.selecao_nome, bandeira: f.bandeira_url, itens: [] };
        }
        grupos[gKey][f.selecao_sigla].itens.push(f);
    }

    for (const [gKey, selecoes] of Object.entries(grupos)) {
        const divG = document.createElement('div');

        const tit = document.createElement('div');
        tit.className   = 'grupo-titulo';
        tit.textContent = gKey === 'especial' ? 'Especiais' : `Grupo ${gKey}`;
        divG.appendChild(tit);

        for (const [sigla, sel] of Object.entries(selecoes)) {
            const divS = document.createElement('div');
            divS.dataset.sigla = sigla;
            divS.dataset.nome  = sel.nome.toLowerCase();

            const mini = document.createElement('div');
            mini.className = 'selecao-mini';
            mini.innerHTML = `
                <img src="${sel.bandeira}" alt="${sigla}" onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span>
                <span>${sel.nome}</span>`;
            divS.appendChild(mini);

            const subGrid = document.createElement('div');
            subGrid.className = 'figurinhas-grid';

            for (const f of sel.itens) {
                const card = document.createElement('div');
                card.className   = 'figurinha-card tem repetida';
                card.id          = `tr-${f.figurinha_id}`;
                card.dataset.id  = f.figurinha_id;
                card.dataset.codigo = f.codigo;
                card.style.cursor   = 'pointer';
                card.innerHTML = `
                    <span class="fig-codigo">${f.codigo}</span>
                    <div style="font-size:.8rem;text-align:center">×${f.quantidade}</div>`;

                card.addEventListener('click', () => toggleTransfer(f.figurinha_id, card));
                card.addEventListener('touchend', e => {
                    e.preventDefault();
                    toggleTransfer(f.figurinha_id, card);
                }, { passive: false });

                subGrid.appendChild(card);
            }

            divS.appendChild(subGrid);
            divG.appendChild(divS);
        }

        grid.appendChild(divG);
    }
}

function toggleTransfer(id, card) {
    if (transfer.selecionadas.has(id)) {
        transfer.selecionadas.delete(id);
        card.classList.remove('para-transferir');
    } else {
        transfer.selecionadas.add(id);
        card.classList.add('para-transferir');
    }
    atualizarBarraTransfer();
}

function atualizarBarraTransfer() {
    const n   = transfer.selecionadas.size;
    const tot = transfer.figurinhas.length;
    document.getElementById('msg-transfer').textContent = n === 0
        ? `${tot} disponível(is) — clique para selecionar`
        : `${n} selecionada(s)`;
    document.getElementById('btn-transferir').disabled = n === 0;
}

function selecionarTodasTransfer() {
    document.querySelectorAll('#transfer-grid .figurinha-card').forEach(card => {
        if (card.style.display === 'none') return; // respeita o filtro de busca
        transfer.selecionadas.add(card.dataset.id);
        card.classList.add('para-transferir');
    });
    atualizarBarraTransfer();
}

function limparTransfer() {
    transfer.selecionadas.clear();
    document.querySelectorAll('#transfer-grid .figurinha-card').forEach(c => c.classList.remove('para-transferir'));
    atualizarBarraTransfer();
}

function filtrarTransfer() {
    const termo = document.getElementById('busca-transfer').value.toLowerCase().trim();
    document.querySelectorAll('#transfer-grid [data-sigla]').forEach(bloco => {
        const sigla = bloco.dataset.sigla?.toLowerCase() ?? '';
        const nome  = bloco.dataset.nome  ?? '';
        const baterSel = !termo || sigla.includes(termo) || nome.includes(termo);
        let algum = false;
        bloco.querySelectorAll('.figurinha-card').forEach(card => {
            const ok = baterSel || card.dataset.codigo.toLowerCase().includes(termo);
            card.style.display = ok ? '' : 'none';
            if (ok) algum = true;
        });
        bloco.style.display = algum ? '' : 'none';
    });
}

async function executarTransferencia() {
    const n = transfer.selecionadas.size;
    if (n === 0) return;

    const nomeOrig = document.getElementById('sel-origem').options[document.getElementById('sel-origem').selectedIndex].text;
    const nomeDest = document.getElementById('sel-destino').options[document.getElementById('sel-destino').selectedIndex].text;

    if (!confirm(`Transferir ${n} figurinha(s)\nde "${nomeOrig}"\npara "${nomeDest}"?`)) return;

    const btn = document.getElementById('btn-transferir');
    btn.disabled    = true;
    btn.textContent = '⏳ Transferindo...';

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao:             'transferir',
            album_origem_id:  transfer.origemId,
            album_destino_id: transfer.destinoId,
            figurinha_ids:    JSON.stringify([...transfer.selecionadas]),
        }),
    });
    const data = await resp.json();

    btn.textContent = '✓ Transferir selecionadas';

    if (data.sucesso) {
        const msg = `✅ ${data.transferidas} transferida(s).`
            + (data.ignoradas > 0 ? ` (${data.ignoradas} sem repetida disponível)` : '');
        alert(msg);
        await carregarTransferencia(); // recarrega o grid
    } else {
        alert('Erro: ' + (data.erro ?? 'desconhecido'));
        btn.disabled = false;
    }
}

// ════════════════════════════════════════════════════════════════
// SEÇÃO 1 + 2 — ÁLBUM PARA TROCAS COM OUTROS
// ════════════════════════════════════════════════════════════════

async function trocarAlbum() {
    trocas.albumId = document.getElementById('select-album').value || null;

    if (!trocas.albumId) {
        document.getElementById('conteudo-trocas').style.display = 'none';
        document.getElementById('estado-inicial').style.display  = 'block';
        return;
    }
    document.getElementById('estado-inicial').style.display  = 'none';
    document.getElementById('conteudo-trocas').style.display = 'block';

    await Promise.all([carregarRepetidas(), recalcularEListarMatches()]);
}

// ── Seção 1: Minhas repetidas ─────────────────────────────────────────────
async function carregarRepetidas() {
    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `acao=minhas_repetidas&album_id=${trocas.albumId}`,
    });
    const data = await resp.json();

    const grid  = document.getElementById('grid-repetidas');
    const vazio = document.getElementById('estado-sem-repetidas');
    const badge = document.getElementById('badge-repetidas');

    if (!data.sucesso || data.figurinhas.length === 0) {
        grid.innerHTML = ''; grid.style.display = 'none';
        vazio.style.display = 'block'; badge.textContent = '0';
        return;
    }
    vazio.style.display = 'none';
    grid.style.display  = 'block';
    badge.textContent   = data.figurinhas.length;
    renderizarRepetidas(data.figurinhas);
}

function renderizarRepetidas(figurinhas) {
    const grid = document.getElementById('grid-repetidas');
    grid.innerHTML = '';

    const grupos = {};
    for (const f of figurinhas) {
        const gKey = f.grupo_codigo || 'especial';
        if (!grupos[gKey]) grupos[gKey] = {};
        if (!grupos[gKey][f.selecao_sigla]) {
            grupos[gKey][f.selecao_sigla] = { nome: f.selecao_nome, bandeira: f.bandeira_url, itens: [] };
        }
        grupos[gKey][f.selecao_sigla].itens.push(f);
    }

    for (const [gKey, selecoes] of Object.entries(grupos)) {
        const divG = document.createElement('div');
        const tit  = document.createElement('div');
        tit.className = 'grupo-titulo';
        tit.textContent = gKey === 'especial' ? 'Especiais' : `Grupo ${gKey}`;
        divG.appendChild(tit);

        for (const [sigla, sel] of Object.entries(selecoes)) {
            const divS = document.createElement('div');
            divS.dataset.sigla = sigla;

            const mini = document.createElement('div');
            mini.className = 'selecao-mini';
            mini.innerHTML = `
                <img src="${sel.bandeira}" alt="${sigla}" onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span>
                <span>${sel.nome}</span>`;
            divS.appendChild(mini);

            const subGrid = document.createElement('div');
            subGrid.className = 'figurinhas-grid';
            for (const f of sel.itens) subGrid.appendChild(criarCardRepetida(f));

            divS.appendChild(subGrid);
            divG.appendChild(divS);
        }
        grid.appendChild(divG);
    }
    aplicarFiltroStatus();
}

function criarCardRepetida(f) {
    const statusEmoji = { livre:'🟢', troca:'🔄', venda:'💰', bloqueada:'🔒' };
    const card = document.createElement('div');
    card.className = `figurinha-card tem${f.quantidade > 1 ? ' repetida' : ''}`;
    card.id = `rep-${f.figurinha_id}`;
    card.dataset.id     = f.figurinha_id;
    card.dataset.codigo = f.codigo;
    card.dataset.status = f.status_troca;
    card.dataset.valor  = f.valor_troca ?? '';
    card.style.position = 'relative';
    card.style.cursor   = 'pointer';
    card.innerHTML = `
        <span class="fig-codigo">${f.codigo}</span>
        <div class="fig-qtd" style="font-size:.8rem;text-align:center">×${f.quantidade}</div>
        <div class="fig-status-badge">${statusEmoji[f.status_troca] ?? '🟢'}</div>`;
    configurarLongPress(card, () => abrirModalStatus(f));
    return card;
}

function filtrarStatus(btn) {
    document.querySelectorAll('.status-pill').forEach(p => p.classList.remove('ativa'));
    btn.classList.add('ativa');
    trocas.filtroStatus = btn.dataset.status;
    aplicarFiltroStatus();
}

function aplicarFiltroStatus() {
    document.querySelectorAll('#grid-repetidas .figurinha-card').forEach(card => {
        card.style.display = (!trocas.filtroStatus || card.dataset.status === trocas.filtroStatus) ? '' : 'none';
    });
}

function abrirModalStatus(f) {
    trocas.figurinhaSelecionada = f;
    document.getElementById('modal-titulo-fig').textContent = `Figurinha ${f.codigo}`;
    document.getElementById('campo-valor').style.display = f.status_troca === 'venda' ? 'block' : 'none';
    document.getElementById('input-valor').value = f.valor_troca ?? '';
    document.getElementById('modal-status').style.display = 'flex';
}

function definirStatus(status) {
    document.getElementById('campo-valor').style.display = status === 'venda' ? 'block' : 'none';
    trocas.statusPendente = status;
}

async function confirmarStatus() {
    const status = trocas.statusPendente ?? trocas.figurinhaSelecionada.status_troca;
    const valor  = document.getElementById('input-valor').value;
    const f      = trocas.figurinhaSelecionada;

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao: 'status_figurinha', album_id: trocas.albumId,
            figurinha_id: f.figurinha_id, status, valor,
        }),
    });
    const data = await resp.json();

    if (data.sucesso) {
        const card = document.getElementById(`rep-${f.figurinha_id}`);
        if (card) {
            card.dataset.status = data.status;
            const statusEmoji = { livre:'🟢', troca:'🔄', venda:'💰', bloqueada:'🔒' };
            card.querySelector('.fig-status-badge').textContent = statusEmoji[data.status];
        }
        document.getElementById('modal-status').style.display = 'none';
        trocas.statusPendente = null;
        aplicarFiltroStatus();
    } else {
        alert('Erro ao salvar: ' + (data.erro ?? 'desconhecido'));
    }
}

// ── Seção 2: Matches ──────────────────────────────────────────────────────
async function recalcularEListarMatches() {
    document.getElementById('lista-matches').innerHTML = '';
    document.getElementById('estado-sem-matches').style.display = 'none';
    document.getElementById('estado-calculando').style.display  = 'block';

    await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `acao=match_recalcular&album_id=${trocas.albumId}`,
    });

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `acao=match_listar&album_id=${trocas.albumId}`,
    });
    const data = await resp.json();

    document.getElementById('estado-calculando').style.display = 'none';

    const lista = document.getElementById('lista-matches');
    const badge = document.getElementById('badge-matches');
    const vazio = document.getElementById('estado-sem-matches');

    if (!data.sucesso || data.matches.length === 0) {
        vazio.style.display = 'block'; badge.textContent = '0'; return;
    }
    badge.textContent = data.matches.length;
    for (const m of data.matches) lista.appendChild(criarCardMatch(m));
}

function criarCardMatch(m) {
    const avatar  = m.avatar_url ?? 'https://ui-avatars.com/api/?name=' + encodeURIComponent(m.nome) + '&size=44';
    const loc     = [m.cidade, m.estado].filter(Boolean).join(', ') || 'Localização não informada';
    const dist    = m.distancia_km ? `· ${Math.round(m.distancia_km)} km` : '';
    const exemplos = m.exemplos_oferta?.length
        ? `Eu ofereço: ${m.exemplos_oferta.join(', ')}${m.exemplos_oferta.length === 5 ? '...' : ''}`
        : '';
    const card = document.createElement('div');
    card.className = 'match-card';
    card.innerHTML = `
        <img class="match-avatar" src="${avatar}" alt="${m.nome}"
             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(m.nome)}&size=44'">
        <div class="match-info">
            <div class="match-nome">${m.nome}</div>
            <div class="match-loc">${loc} ${dist}</div>
            ${exemplos ? `<div class="match-exemplos">${exemplos}</div>` : ''}
        </div>
        <span class="match-score">${m.quantidade_match} match${m.quantidade_match !== 1 ? 'es' : ''}</span>
        <button class="match-btn" onclick='abrirContato(${JSON.stringify(m)})'>Contato</button>`;
    return card;
}

function abrirContato(m) {
    document.getElementById('modal-contato-nome').textContent = m.nome;
    const corpo = document.getElementById('modal-contato-corpo');
    corpo.innerHTML = '';

    if (!m.contato_tipo || !m.contato_valor) {
        corpo.innerHTML = `
            <div class="contato-linha">
                <span class="contato-icone">ℹ️</span>
                <div><div>Contato não informado</div>
                <small>Este usuário ainda não cadastrou um meio de contato.</small></div>
            </div>`;
    } else {
        const icones = { whatsapp:'💬', telegram:'✈️', email:'📧' };
        let link = '';
        if (m.contato_tipo === 'whatsapp') {
            link = `<a class="contato-link" href="https://wa.me/${m.contato_valor.replace(/\D/g,'')}" target="_blank">Abrir WhatsApp</a>`;
        } else if (m.contato_tipo === 'telegram') {
            link = `<a class="contato-link" href="https://t.me/${m.contato_valor.replace('@','')}" target="_blank">Abrir Telegram</a>`;
        } else {
            link = `<a class="contato-link" href="mailto:${m.contato_valor}">${m.contato_valor}</a>`;
        }
        corpo.innerHTML = `
            <div class="contato-linha">
                <span class="contato-icone">${icones[m.contato_tipo] ?? '📞'}</span>
                <div><div>${m.contato_valor}</div>
                <small>${m.contato_tipo.charAt(0).toUpperCase() + m.contato_tipo.slice(1)}</small></div>
            </div>
            <div style="text-align:center;margin-top:.5rem">${link}</div>`;
    }
    document.getElementById('modal-contato').style.display = 'flex';
}

// ── Utilitários ───────────────────────────────────────────────────────────
function configurarLongPress(el, callback) {
    let timer = null, moveu = false;
    el.addEventListener('touchstart', () => {
        moveu = false;
        timer = setTimeout(() => { if (!moveu) { callback(); if(navigator.vibrate) navigator.vibrate(40); } }, 500);
    }, { passive: true });
    el.addEventListener('touchmove', () => { moveu = true; clearTimeout(timer); }, { passive: true });
    el.addEventListener('touchend',  () => clearTimeout(timer));
    el.addEventListener('contextmenu', e => { e.preventDefault(); callback(); });
}

function fecharModal(e) {
    if (e.target.classList.contains('modal-overlay')) e.target.style.display = 'none';
}
</script>

<?php layoutFim(); ?>
