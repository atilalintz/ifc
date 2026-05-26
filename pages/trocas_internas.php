<?php
// pages/trocas_internas.php — Transferência entre álbuns do mesmo usuário
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

$stmt = $db->prepare("
    SELECT id, nome, total_repetidas, percentual_conclusao
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY total_repetidas DESC, percentual_conclusao DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

// Pré-seleciona origem e destino via query string
$origemParam  = $_GET['origem']  ?? '';
$destinoParam = $_GET['destino'] ?? '';

layoutInicio('Trocas Internas');
?>

<div class="inventario-header">
    <div>
        <a href="/trocas" class="btn-voltar">← Trocas</a>
        <h1 class="page-title" style="margin-bottom:.25rem">↔️ Trocas Internas</h1>
        <p style="color:#888;font-size:.9rem;margin:0">
            Transfira figurinhas repetidas entre seus álbuns
        </p>
    </div>
</div>

<!-- Seletores origem / destino -->
<div class="trocas-secao">
    <div class="trocas-seletores">
        <div class="trocas-painel">
            <label class="trocas-label">📤 Origem <small>(tem repetidas)</small></label>
            <select id="sel-origem" onchange="carregarTransferencia()">
                <option value="">— selecione —</option>
                <?php foreach ($albuns as $a): ?>
                    <option value="<?= $a['id'] ?>"
                            <?= $a['id'] === $origemParam ? 'selected' : '' ?>>
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
                    <option value="<?= $a['id'] ?>"
                            <?= $a['id'] === $destinoParam ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['nome']) ?>
                        (<?= number_format($a['percentual_conclusao'],1) ?>%)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Barra de ação -->
    <div id="barra-transferir" style="display:none" class="acoes-barra">
        <span id="msg-transfer" style="font-size:.88rem;color:#555"></span>
        <button class="btn-sm" onclick="selecionarTodasTransfer()">Selecionar todas</button>
        <button class="btn-sm" onclick="limparTransfer()">Limpar</button>
        <button class="btn-sm btn-todas-inc" id="btn-transferir"
                onclick="executarTransferencia()" disabled>
            ✓ Transferir selecionadas
        </button>
    </div>

    <!-- Estado e grid -->
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

<style>
.trocas-secao {
    background:#fff; border:1px solid var(--borda);
    border-radius:12px; padding:1.25rem; margin-bottom:1.5rem;
}
.trocas-seletores { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:.75rem; }
.trocas-painel { flex:1; min-width:200px; }
.trocas-label { display:block; font-weight:600; font-size:.85rem; margin-bottom:.3rem; }
.trocas-label small { font-weight:400; color:#888; }
.trocas-painel select {
    width:100%; padding:.42rem .6rem;
    border:1px solid #ddd; border-radius:8px;
    font-size:.88rem; background:#fafafa;
}
.trocas-seta-meio { font-size:1.5rem; color:#bbb; padding-bottom:.1rem; }
.trocas-estado { text-align:center; padding:2rem 1rem; color:#aaa; font-size:.92rem; }
.figurinha-card.para-transferir { outline:2px solid #22c55e; background:#f0fdf4 !important; }
.lista-grupos-trocas .grupo-titulo {
    font-weight:700; font-size:.78rem; text-transform:uppercase;
    letter-spacing:.05em; color:#aaa; margin:.75rem 0 .3rem;
}
.lista-grupos-trocas .selecao-mini {
    display:flex; align-items:center; gap:.4rem;
    font-size:.82rem; font-weight:600; margin:.5rem 0 .3rem;
}
.lista-grupos-trocas .selecao-mini img { width:20px; height:14px; object-fit:cover; border-radius:2px; }
@media(max-width:600px){ .trocas-seta-meio { display:none; } }
</style>

<script>
const transfer = {
    origemId:     '<?= htmlspecialchars($origemParam) ?>',
    destinoId:    '<?= htmlspecialchars($destinoParam) ?>',
    figurinhas:   [],
    selecionadas: new Set(),
};

// Auto-carrega se vier pré-selecionado via URL
document.addEventListener('DOMContentLoaded', () => {
    if (transfer.origemId && transfer.destinoId) carregarTransferencia();
});

async function carregarTransferencia() {
    transfer.origemId  = document.getElementById('sel-origem').value  || null;
    transfer.destinoId = document.getElementById('sel-destino').value || null;

    const estado = document.getElementById('transfer-estado');
    const grid   = document.getElementById('transfer-grid');
    const filtro = document.getElementById('transfer-filtro');
    const barra  = document.getElementById('barra-transferir');

    transfer.selecionadas.clear();
    grid.innerHTML = ''; grid.style.display = 'none';
    filtro.style.display = 'none'; barra.style.display = 'none';

    if (!transfer.origemId || !transfer.destinoId) {
        estado.textContent = 'Selecione origem e destino para ver as figurinhas disponíveis.';
        estado.style.display = 'block'; return;
    }
    if (transfer.origemId === transfer.destinoId) {
        estado.textContent = 'Origem e destino precisam ser álbuns diferentes.';
        estado.style.display = 'block'; return;
    }

    estado.textContent = '⏳ Carregando figurinhas...';
    estado.style.display = 'block';

    const [resOrigem, resDestino] = await Promise.all([
        fetchAlbum(transfer.origemId,  'origem'),
        fetchAlbum(transfer.destinoId, 'destino'),
    ]);

    if (!resOrigem.sucesso || !resDestino.sucesso) {
        estado.textContent = 'Erro ao carregar. Tente novamente.'; return;
    }

    const faltamNoDestino = new Set(resDestino.figurinhas.map(f => f.figurinha_id));
    transfer.figurinhas   = resOrigem.figurinhas.filter(f => faltamNoDestino.has(f.figurinha_id));

    if (transfer.figurinhas.length === 0) {
        estado.textContent = 'Nenhuma figurinha elegível — a origem não tem repetidas que o destino precise.';
        estado.style.display = 'block'; return;
    }

    estado.style.display = 'none';
    filtro.style.display = 'block';
    grid.style.display   = 'block';
    barra.style.display  = '';

    renderizarGrid(); atualizarBarra();
}

async function fetchAlbum(albumId, modo) {
    try {
        const r = await fetch('/api/trocas', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=figurinhas_album&album_id=${albumId}&modo=${modo}`,
        });
        return await r.json();
    } catch { return { sucesso: false }; }
}

function renderizarGrid() {
    const grid = document.getElementById('transfer-grid');
    grid.innerHTML = '';
    const grupos = {};
    for (const f of transfer.figurinhas) {
        const gKey = f.grupo_codigo || 'especial';
        if (!grupos[gKey]) grupos[gKey] = {};
        if (!grupos[gKey][f.selecao_sigla])
            grupos[gKey][f.selecao_sigla] = { nome: f.selecao_nome, bandeira: f.bandeira_url, itens: [] };
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
            divS.dataset.sigla = sigla; divS.dataset.nome = sel.nome.toLowerCase();
            const mini = document.createElement('div');
            mini.className = 'selecao-mini';
            mini.innerHTML = `<img src="${sel.bandeira}" alt="${sigla}" onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span><span>${sel.nome}</span>`;
            divS.appendChild(mini);
            const subGrid = document.createElement('div');
            subGrid.className = 'figurinhas-grid';
            for (const f of sel.itens) {
                const card = document.createElement('div');
                card.className = 'figurinha-card tem repetida';
                card.id = `tr-${f.figurinha_id}`;
                card.dataset.id = f.figurinha_id; card.dataset.codigo = f.codigo;
                card.style.cursor = 'pointer';
                card.innerHTML = `<span class="fig-codigo">${f.codigo}</span>
                    <div style="font-size:.8rem;text-align:center">×${f.quantidade}</div>`;
                card.addEventListener('click', () => toggleTransfer(f.figurinha_id, card));
                card.addEventListener('touchend', e => { e.preventDefault(); toggleTransfer(f.figurinha_id, card); }, { passive: false });
                subGrid.appendChild(card);
            }
            divS.appendChild(subGrid); divG.appendChild(divS);
        }
        grid.appendChild(divG);
    }
}

function toggleTransfer(id, card) {
    if (transfer.selecionadas.has(id)) { transfer.selecionadas.delete(id); card.classList.remove('para-transferir'); }
    else { transfer.selecionadas.add(id); card.classList.add('para-transferir'); }
    atualizarBarra();
}

function atualizarBarra() {
    const n = transfer.selecionadas.size, tot = transfer.figurinhas.length;
    document.getElementById('msg-transfer').textContent = n === 0
        ? `${tot} disponível(is) — clique para selecionar` : `${n} selecionada(s)`;
    document.getElementById('btn-transferir').disabled = n === 0;
}

function selecionarTodasTransfer() {
    document.querySelectorAll('#transfer-grid .figurinha-card').forEach(card => {
        if (card.style.display === 'none') return;
        transfer.selecionadas.add(card.dataset.id); card.classList.add('para-transferir');
    });
    atualizarBarra();
}

function limparTransfer() {
    transfer.selecionadas.clear();
    document.querySelectorAll('#transfer-grid .figurinha-card').forEach(c => c.classList.remove('para-transferir'));
    atualizarBarra();
}

function filtrarTransfer() {
    const termo = document.getElementById('busca-transfer').value.toLowerCase().trim();
    document.querySelectorAll('#transfer-grid [data-sigla]').forEach(bloco => {
        const bate = !termo || bloco.dataset.sigla.toLowerCase().includes(termo) || bloco.dataset.nome.includes(termo);
        let algum = false;
        bloco.querySelectorAll('.figurinha-card').forEach(card => {
            const ok = bate || card.dataset.codigo.toLowerCase().includes(termo);
            card.style.display = ok ? '' : 'none'; if (ok) algum = true;
        });
        bloco.style.display = algum ? '' : 'none';
    });
}

async function executarTransferencia() {
    const n = transfer.selecionadas.size; if (n === 0) return;
    const nOrig = document.getElementById('sel-origem').options[document.getElementById('sel-origem').selectedIndex].text;
    const nDest = document.getElementById('sel-destino').options[document.getElementById('sel-destino').selectedIndex].text;
    if (!confirm(`Transferir ${n} figurinha(s)\nde "${nOrig}"\npara "${nDest}"?`)) return;
    const btn = document.getElementById('btn-transferir');
    btn.disabled = true; btn.textContent = '⏳ Transferindo...';
    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ acao: 'transferir', album_origem_id: transfer.origemId, album_destino_id: transfer.destinoId, figurinha_ids: JSON.stringify([...transfer.selecionadas]) }),
    });
    const data = await resp.json();
    btn.textContent = '✓ Transferir selecionadas';
    if (data.sucesso) {
        alert(`✅ ${data.transferidas} transferida(s).` + (data.ignoradas > 0 ? ` (${data.ignoradas} ignoradas)` : ''));
        await carregarTransferencia();
    } else { alert('Erro: ' + (data.erro ?? 'desconhecido')); btn.disabled = false; }
}
</script>

<?php layoutFim(); ?>
