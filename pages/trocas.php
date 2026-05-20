<?php
// pages/trocas.php — Transferência de figurinhas entre álbuns do mesmo usuário
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Busca todos os álbuns ativos do usuário
$stmt = $db->prepare("
    SELECT id, nome, percentual_conclusao, total_repetidas
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY nome
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Trocas entre Álbuns');
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">Transferir Figurinhas</h1>
        <p style="color:#888;font-size:.9rem;margin:0">
            Mova repetidas de um álbum para outro onde estão faltando
        </p>
    </div>
</div>

<!-- Seletores de álbum ─────────────────────────────────────────────────── -->
<div class="trocas-seletores">
    <div class="trocas-painel" id="painel-origem">
        <label class="trocas-label">📤 Álbum de Origem <small>(tem repetidas)</small></label>
        <select id="select-origem" onchange="carregarAlbum('origem')">
            <option value="">— selecione —</option>
            <?php foreach ($albuns as $a): ?>
                <option value="<?= $a['id'] ?>"
                        data-rep="<?= $a['total_repetidas'] ?>">
                    <?= htmlspecialchars($a['nome']) ?>
                    (<?= $a['total_repetidas'] ?> repetidas)
                </option>
            <?php endforeach; ?>
        </select>
        <div id="info-origem" class="trocas-info" style="display:none"></div>
    </div>

    <div class="trocas-seta-meio">→</div>

    <div class="trocas-painel" id="painel-destino">
        <label class="trocas-label">📥 Álbum de Destino <small>(receberá as figurinhas)</small></label>
        <select id="select-destino" onchange="carregarAlbum('destino')">
            <option value="">— selecione —</option>
            <?php foreach ($albuns as $a): ?>
                <option value="<?= $a['id'] ?>"
                        data-pct="<?= $a['percentual_conclusao'] ?>">
                    <?= htmlspecialchars($a['nome']) ?>
                    (<?= number_format($a['percentual_conclusao'], 1) ?>% completo)
                </option>
            <?php endforeach; ?>
        </select>
        <div id="info-destino" class="trocas-info" style="display:none"></div>
    </div>
</div>

<!-- Barra de ação ──────────────────────────────────────────────────────── -->
<div class="acoes-barra" id="barra-transferir" style="display:none">
    <span id="msg-selecionadas" style="font-size:.9rem;color:#555"></span>
    <button class="btn-sm" onclick="selecionarTodas()">Selecionar todas</button>
    <button class="btn-sm" onclick="limparSelecao()">Limpar</button>
    <button class="btn-sm btn-todas-inc" id="btn-transferir" onclick="transferir()">
        ✓ Transferir selecionadas
    </button>
</div>

<!-- Grid de figurinhas ─────────────────────────────────────────────────── -->
<div id="area-figurinhas">
    <div id="estado-inicial" class="trocas-estado">
        Selecione os dois álbuns para ver as figurinhas disponíveis para transferência.
    </div>
    <div id="grid-figurinhas" style="display:none">
        <div class="filtros-sticky">
            <div class="filtros-barra">
                <input type="text" id="busca-trocas" placeholder="Buscar código ou país..."
                       oninput="filtrarGrid()">
            </div>
        </div>
        <div id="lista-grupos"></div>
    </div>
    <div id="estado-vazio" class="trocas-estado" style="display:none">
        Nenhuma figurinha disponível para transferência.<br>
        <small>A origem precisa ter repetidas que o destino não possui.</small>
    </div>
</div>

<style>
/* ── Layout seletores ─────────────────────────────────────── */
.trocas-seletores {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.trocas-painel {
    flex: 1;
    min-width: 240px;
    background: var(--bg-card, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 10px;
    padding: 1rem;
}
.trocas-label {
    display: block;
    font-weight: 600;
    margin-bottom: .5rem;
    font-size: .9rem;
}
.trocas-label small { font-weight: 400; color: #888; }
.trocas-painel select {
    width: 100%;
    padding: .45rem .6rem;
    border: 1px solid var(--border, #ddd);
    border-radius: 6px;
    font-size: .9rem;
    background: var(--bg, #fafafa);
}
.trocas-seta-meio {
    font-size: 2rem;
    color: #aaa;
    align-self: center;
    padding-top: 1.5rem;
}
.trocas-info {
    margin-top: .5rem;
    font-size: .82rem;
    color: #666;
    padding: .4rem .5rem;
    background: var(--bg, #f5f5f5);
    border-radius: 6px;
}

/* ── Estado vazio / inicial ───────────────────────────────── */
.trocas-estado {
    text-align: center;
    padding: 3rem 1rem;
    color: #aaa;
    font-size: .95rem;
    line-height: 1.7;
}

/* ── Grid de figurinhas (reutiliza estilo do inventário) ─── */
.grupo-bloco { margin-bottom: 1.5rem; }
.grupo-titulo {
    font-weight: 700;
    font-size: .85rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #888;
    margin-bottom: .5rem;
    padding-left: .25rem;
}
.selecao-bloco { margin-bottom: 1rem; }
.selecao-mini-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .4rem;
    font-size: .85rem;
    font-weight: 600;
}
.selecao-mini-header img { width: 20px; height: 14px; object-fit: cover; border-radius: 2px; }

/* Figurinha selecionada para transferência */
.figurinha-card.para-transferir {
    outline: 2px solid #22c55e;
    background: #f0fdf4 !important;
}
.figurinha-card.para-transferir .fig-qtd {
    color: #16a34a;
    font-weight: 700;
}

/* Badge de quantidade repetida */
.fig-badge-rep {
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: .62rem;
    background: #f59e0b;
    color: #fff;
    border-radius: 8px;
    padding: 0 4px;
    line-height: 1.4;
    pointer-events: none;
}

@media (max-width: 600px) {
    .trocas-seta-meio { display: none; }
    .trocas-seletores { gap: .75rem; }
}
</style>

<script>
// ── Estado global ─────────────────────────────────────────────────────────
const estado = {
    origemId:    null,
    destinoId:   null,
    figurinhas:  [],       // figurinhas elegíveis para transferência
    selecionadas: new Set(), // IDs das figurinhas marcadas
};

// ── Carrega figurinhas ao trocar álbum ───────────────────────────────────
async function carregarAlbum(lado) {
    const select = document.getElementById(`select-${lado}`);
    const albumId = select.value;

    if (lado === 'origem') {
        estado.origemId = albumId || null;
    } else {
        estado.destinoId = albumId || null;
    }

    // Atualiza info sob o select
    const info = document.getElementById(`info-${lado}`);
    if (!albumId) {
        info.style.display = 'none';
    } else {
        const opt = select.options[select.selectedIndex];
        if (lado === 'origem') {
            info.textContent = `${opt.dataset.rep} figurinhas repetidas disponíveis`;
        } else {
            info.textContent = `Álbum ${parseFloat(opt.dataset.pct).toFixed(1)}% completo`;
        }
        info.style.display = 'block';
    }

    // Só busca figurinhas elegíveis quando os dois lados estão selecionados
    if (estado.origemId && estado.destinoId) {
        if (estado.origemId === estado.destinoId) {
            mostrarEstado('inicial', 'Origem e destino precisam ser álbuns diferentes.');
            return;
        }
        await buscarFigurinhasElegiveis();
    } else {
        mostrarEstado('inicial');
    }
}

// ── Busca figurinhas que são repetidas na origem E faltantes no destino ──
async function buscarFigurinhasElegiveis() {
    mostrarEstado('carregando');
    estado.selecionadas.clear();

    // Paraleliza as duas requisições
    const [resOrigem, resDestino] = await Promise.all([
        fetchFigurinhas(estado.origemId,  'origem'),
        fetchFigurinhas(estado.destinoId, 'destino'),
    ]);

    if (!resOrigem.sucesso || !resDestino.sucesso) {
        mostrarEstado('inicial', 'Erro ao carregar figurinhas. Tente novamente.');
        return;
    }

    // IDs que faltam no destino
    const faltamNoDestino = new Set(resDestino.figurinhas.map(f => f.figurinha_id));

    // Elegíveis = repetidas na origem que faltam no destino
    estado.figurinhas = resOrigem.figurinhas.filter(f => faltamNoDestino.has(f.figurinha_id));

    if (estado.figurinhas.length === 0) {
        mostrarEstado('vazio');
        return;
    }

    renderizarGrid();
    mostrarEstado('grid');
    atualizarBarraTroca();
}

async function fetchFigurinhas(albumId, modo) {
    try {
        const resp = await fetch('/api/trocas', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=figurinhas_album&album_id=${albumId}&modo=${modo}`,
        });
        return await resp.json();
    } catch {
        return { sucesso: false };
    }
}

// ── Renderiza o grid agrupado por seleção ────────────────────────────────
function renderizarGrid() {
    const lista = document.getElementById('lista-grupos');
    lista.innerHTML = '';

    // Agrupa por grupo → seleção
    const grupos = {};
    for (const f of estado.figurinhas) {
        const gKey = f.grupo_codigo || 'especial';
        if (!grupos[gKey]) grupos[gKey] = {};
        const sKey = f.selecao_sigla;
        if (!grupos[gKey][sKey]) {
            grupos[gKey][sKey] = { nome: f.selecao_nome, bandeira: f.bandeira_url, itens: [] };
        }
        grupos[gKey][sKey].itens.push(f);
    }

    for (const [gKey, selecoes] of Object.entries(grupos)) {
        const divGrupo = document.createElement('div');
        divGrupo.className = 'grupo-bloco';
        divGrupo.dataset.grupo = gKey;

        const titulo = document.createElement('div');
        titulo.className = 'grupo-titulo';
        titulo.textContent = gKey === 'especial' ? 'Especiais' : `Grupo ${gKey}`;
        divGrupo.appendChild(titulo);

        for (const [sigla, sel] of Object.entries(selecoes)) {
            const divSel = document.createElement('div');
            divSel.className = 'selecao-bloco';
            divSel.dataset.sigla = sigla;
            divSel.dataset.nome  = sel.nome.toLowerCase();

            const header = document.createElement('div');
            header.className = 'selecao-mini-header';
            header.innerHTML = `
                <img src="${sel.bandeira}" alt="${sigla}"
                     onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span>
                <span>${sel.nome}</span>
            `;
            divSel.appendChild(header);

            const grid = document.createElement('div');
            grid.className = 'figurinhas-grid';

            for (const f of sel.itens) {
                grid.appendChild(criarCard(f));
            }

            divSel.appendChild(grid);
            divGrupo.appendChild(divSel);
        }

        lista.appendChild(divGrupo);
    }
}

function criarCard(f) {
    const card = document.createElement('div');
    card.className = 'figurinha-card tem' + (f.quantidade > 1 ? ' repetida' : '');
    card.id = `fig-${f.figurinha_id}`;
    card.dataset.id     = f.figurinha_id;
    card.dataset.codigo = f.codigo;
    card.dataset.qtd    = f.quantidade;
    card.style.position = 'relative';
    card.style.cursor   = 'pointer';

    card.innerHTML = `
        <span class="fig-codigo">${f.codigo}</span>
        <div class="fig-qtd" style="font-size:.85rem;text-align:center;margin-top:.2rem">
            ×${f.quantidade}
        </div>
        <span class="fig-badge-rep">×${f.quantidade}</span>
    `;

    // Clique alterna seleção para transferência
    card.addEventListener('click', () => toggleSelecionar(f.figurinha_id, card));

    // Mobile: toque longo NÃO faz nada diferente aqui — é só clique simples
    card.addEventListener('touchend', (e) => {
        e.preventDefault();
        toggleSelecionar(f.figurinha_id, card);
    }, { passive: false });

    return card;
}

function toggleSelecionar(id, card) {
    if (estado.selecionadas.has(id)) {
        estado.selecionadas.delete(id);
        card.classList.remove('para-transferir');
    } else {
        estado.selecionadas.add(id);
        card.classList.add('para-transferir');
    }
    atualizarBarraTroca();
}

// ── Barra de ação ────────────────────────────────────────────────────────
function atualizarBarraTroca() {
    const barra = document.getElementById('barra-transferir');
    const msg   = document.getElementById('msg-selecionadas');
    const n     = estado.selecionadas.size;

    if (estado.figurinhas.length === 0) {
        barra.style.display = 'none';
        return;
    }

    barra.style.display = '';
    msg.textContent = n === 0
        ? `${estado.figurinhas.length} disponíveis — clique para selecionar`
        : `${n} selecionada(s)`;

    document.getElementById('btn-transferir').disabled = n === 0;
}

function selecionarTodas() {
    document.querySelectorAll('#lista-grupos .figurinha-card').forEach(card => {
        const id = card.dataset.id;
        estado.selecionadas.add(id);
        card.classList.add('para-transferir');
    });
    atualizarBarraTroca();
}

function limparSelecao() {
    estado.selecionadas.clear();
    document.querySelectorAll('#lista-grupos .figurinha-card').forEach(card => {
        card.classList.remove('para-transferir');
    });
    atualizarBarraTroca();
}

// ── Transferir ───────────────────────────────────────────────────────────
async function transferir() {
    const n = estado.selecionadas.size;
    if (n === 0) return;

    const nomeOrigem  = document.getElementById('select-origem').options[
        document.getElementById('select-origem').selectedIndex].text;
    const nomeDestino = document.getElementById('select-destino').options[
        document.getElementById('select-destino').selectedIndex].text;

    if (!confirm(`Transferir ${n} figurinha(s)\nde "${nomeOrigem}"\npara "${nomeDestino}"?`)) return;

    const btn = document.getElementById('btn-transferir');
    btn.disabled   = true;
    btn.textContent = '⏳ Transferindo...';

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao:             'transferir',
            album_origem_id:  estado.origemId,
            album_destino_id: estado.destinoId,
            figurinha_ids:    JSON.stringify([...estado.selecionadas]),
        }),
    });

    const data = await resp.json();

    btn.textContent = '✓ Transferir selecionadas';

    if (data.sucesso) {
        const msg = `✅ ${data.transferidas} transferida(s).`
            + (data.ignoradas > 0 ? ` (${data.ignoradas} ignorada(s) — origem sem repetida)` : '');
        alert(msg);

        // Recarrega o grid com os dados atualizados
        await buscarFigurinhasElegiveis();
    } else {
        alert('Erro: ' + (data.erro ?? 'desconhecido'));
        btn.disabled = false;
    }
}

// ── Filtro de busca ───────────────────────────────────────────────────────
function filtrarGrid() {
    const termo = document.getElementById('busca-trocas').value.toLowerCase().trim();

    document.querySelectorAll('#lista-grupos .selecao-bloco').forEach(bloco => {
        const sigla = bloco.dataset.sigla.toLowerCase();
        const nome  = bloco.dataset.nome;

        const baterSelecao = !termo || sigla.includes(termo) || nome.includes(termo);

        let algumCardVisivel = false;
        bloco.querySelectorAll('.figurinha-card').forEach(card => {
            const bateCodigo = card.dataset.codigo.toLowerCase().includes(termo);
            const visivel = baterSelecao || bateCodigo;
            card.style.display = visivel ? '' : 'none';
            if (visivel) algumCardVisivel = true;
        });

        bloco.style.display = algumCardVisivel ? '' : 'none';
    });

    // Oculta grupos sem seleções visíveis
    document.querySelectorAll('#lista-grupos .grupo-bloco').forEach(grupo => {
        const temVisivel = [...grupo.querySelectorAll('.selecao-bloco')]
            .some(b => b.style.display !== 'none');
        grupo.style.display = temVisivel ? '' : 'none';
    });
}

// ── Utilitários de estado da UI ───────────────────────────────────────────
function mostrarEstado(estado, msg = null) {
    document.getElementById('estado-inicial').style.display  = 'none';
    document.getElementById('grid-figurinhas').style.display  = 'none';
    document.getElementById('estado-vazio').style.display     = 'none';
    document.getElementById('barra-transferir').style.display = 'none';

    if (estado === 'inicial') {
        const el = document.getElementById('estado-inicial');
        if (msg) el.textContent = msg;
        el.style.display = 'block';
    } else if (estado === 'grid') {
        document.getElementById('grid-figurinhas').style.display = 'block';
    } else if (estado === 'vazio') {
        document.getElementById('estado-vazio').style.display = 'block';
    } else if (estado === 'carregando') {
        const el = document.getElementById('estado-inicial');
        el.textContent = '⏳ Carregando figurinhas...';
        el.style.display = 'block';
    }
}
</script>

<?php layoutFim(); ?>
