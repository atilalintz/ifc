<?php
// pages/parceiro.php — Figurinhas de um parceiro de troca
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Slug vem na rota: /trocas/parceiro/{slug}
$partes       = explode('/', trim($_GET['route'] ?? '', '/'));
$slugParceiro = $partes[2] ?? '';

if (!$slugParceiro) { header('Location: /trocas/externas'); exit; }

// Álbum pré-selecionado via query string
$albumParam = $_GET['album'] ?? '';

$stmt = $db->prepare("
    SELECT id, nome, total_repetidas
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY total_repetidas DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Parceiro de Troca');
?>

<div class="inventario-header">
    <div>
        <a href="/trocas/externas" class="btn-voltar">← Externas</a>
        <h1 class="page-title" id="titulo-parceiro" style="margin-bottom:.25rem">Carregando...</h1>
        <p id="subtitulo-parceiro" style="color:#888;font-size:.9rem;margin:0"></p>
    </div>
</div>

<!-- Seletor de álbum -->
<div class="acoes-barra" style="margin-bottom:1rem">
    <label style="font-weight:600;font-size:.9rem">📚 Meu álbum:</label>
    <select id="select-album"
            style="padding:.4rem .6rem;border-radius:6px;border:1px solid #ddd;font-size:.9rem"
            onchange="carregar()">
        <option value="">— selecione —</option>
        <?php foreach ($albuns as $a): ?>
            <option value="<?= $a['id'] ?>"
                    <?= $a['id'] === $albumParam ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['nome']) ?>
                (<?= $a['total_repetidas'] ?> repetidas)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Estado inicial -->
<div id="estado-inicial" class="trocas-estado">
    Selecione seu álbum para ver as figurinhas disponíveis para troca.
</div>

<!-- Barra de ação flutuante (aparece quando tem selecionadas) -->
<div id="barra-mensagem" style="display:none" class="barra-mensagem-wrap">
    <span id="msg-selecionadas" style="font-size:.88rem;font-weight:600"></span>
    <button class="btn-sm" onclick="limparSelecao()">Limpar</button>
    <button class="btn-sm btn-todas-inc" onclick="enviarMensagem()">
        📲 Enviar mensagem
    </button>
</div>

<!-- Conteúdo -->
<div id="conteudo" style="display:none">

    <!-- Abas -->
    <div class="abas-nav">
        <button class="aba ativa" data-aba="oferece" onclick="trocarAba(this)">
            📤 Ele oferece
            <span id="badge-oferece" class="trocas-badge">0</span>
        </button>
        <button class="aba" data-aba="precisa" onclick="trocarAba(this)">
            📥 Ele precisa (tenho)
            <span id="badge-precisa" class="trocas-badge">0</span>
        </button>
    </div>

    <!-- Aba: O que ele oferece -->
    <div id="aba-oferece" class="aba-conteudo">
        <p class="trocas-secao-desc">
            Clique nas figurinhas que você quer receber dele.
        </p>
        <div class="status-filtros">
            <button class="status-pill ativa" data-status="" onclick="filtrarOferece(this)">Todas</button>
            <button class="status-pill" data-status="livre"     onclick="filtrarOferece(this)">🟢 Livre</button>
            <button class="status-pill" data-status="venda"     onclick="filtrarOferece(this)">💰 Venda</button>
            <button class="status-pill" data-status="bloqueada" onclick="filtrarOferece(this)">🔒 Bloqueada</button>
        </div>
        <div id="grid-oferece" class="lista-grupos-trocas"></div>
        <div id="vazio-oferece" class="trocas-estado" style="display:none">
            Este parceiro não tem figurinhas repetidas ainda.
        </div>
    </div>

    <!-- Aba: O que ele precisa -->
    <div id="aba-precisa" class="aba-conteudo" style="display:none">
        <p class="trocas-secao-desc">
            Clique nas figurinhas que você quer oferecer a ele.
        </p>
        <div id="grid-precisa" class="lista-grupos-trocas"></div>
        <div id="vazio-precisa" class="trocas-estado" style="display:none">
            Nenhuma figurinha em comum encontrada.
        </div>
    </div>
</div>

<style>
.barra-mensagem-wrap {
    position: sticky; top: 56px; z-index: 95;
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
    background: #eef2ff; border: 1px solid #c7d2fe;
    border-radius: 10px; padding: .6rem .85rem;
    margin-bottom: .75rem;
    box-shadow: 0 2px 8px rgba(99,102,241,.15);
}
.abas-nav { display:flex; gap:.5rem; margin-bottom:1rem; flex-wrap:wrap; }
.aba {
    display:flex; align-items:center; gap:.5rem;
    padding:.55rem 1rem; border-radius:8px;
    border:1px solid #ddd; background:#f5f5f5;
    font-size:.9rem; font-weight:600; cursor:pointer;
}
.aba.ativa { background:#6366f1; color:#fff; border-color:#6366f1; }
.aba.ativa .trocas-badge { background:rgba(255,255,255,.3); }
.aba-conteudo { animation:fadeIn .15s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.status-filtros { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.75rem; }
.status-pill { padding:.25rem .65rem; border-radius:20px; border:1px solid #ddd; background:#f5f5f5; font-size:.8rem; cursor:pointer; }
.status-pill.ativa { background:#6366f1; color:#fff; border-color:#6366f1; }
.lista-grupos-trocas .grupo-titulo { font-weight:700; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#aaa; margin:.75rem 0 .3rem; }
.lista-grupos-trocas .selecao-mini { display:flex; align-items:center; gap:.4rem; font-size:.82rem; font-weight:600; margin:.5rem 0 .3rem; }
.lista-grupos-trocas .selecao-mini img { width:20px; height:14px; object-fit:cover; border-radius:2px; }
.fig-status-badge { position:absolute; top:2px; right:3px; font-size:.62rem; line-height:1; background:rgba(0,0,0,.55); border-radius:6px; padding:1px 3px; pointer-events:none; }
.figurinha-card.selecionada-troca { outline:2px solid #6366f1; background:#eef2ff !important; }
.figurinha-card.bloqueada { opacity:.5; cursor:default !important; }
.trocas-estado { text-align:center; padding:2.5rem 1rem; color:#aaa; font-size:.95rem; line-height:1.7; }
.trocas-badge { background:#6366f1; color:#fff; border-radius:20px; padding:.1rem .55rem; font-size:.78rem; font-weight:700; }
.trocas-secao-desc { color:#888; font-size:.85rem; margin:0 0 .75rem; }
</style>

<script>
const SLUG_PARCEIRO = <?= json_encode($slugParceiro) ?>;
const ALBUM_PARAM   = <?= json_encode($albumParam) ?>;

let dadosParceiro = null;

// Selecionadas separadas por aba
const selecionadas = {
    oferece: new Set(), // figurinhas que quero receber
    precisa: new Set(), // figurinhas que vou oferecer
};

// Dados completos das figurinhas selecionadas (para montar a mensagem)
const dadosSelecionadas = {
    oferece: new Map(), // figurinha_id → { codigo, quantidade, valor_troca, status }
    precisa: new Map(), // figurinha_id → { codigo, minha_quantidade }
};

// ── Auto-carrega se álbum pré-selecionado via URL ─────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (ALBUM_PARAM) {
        document.getElementById('select-album').value = ALBUM_PARAM;
        carregar();
    }
});

// ── Carrega dados do parceiro ─────────────────────────────────────────────
async function carregar() {
    const albumId = document.getElementById('select-album').value;
    if (!albumId) return;

    // Limpa seleção ao trocar álbum
    selecionadas.oferece.clear(); selecionadas.precisa.clear();
    dadosSelecionadas.oferece.clear(); dadosSelecionadas.precisa.clear();
    atualizarBarraMensagem();

    document.getElementById('estado-inicial').textContent = '⏳ Carregando...';
    document.getElementById('estado-inicial').style.display = 'block';
    document.getElementById('conteudo').style.display = 'none';

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao:          'parceiro_figurinhas',
            slug_parceiro: SLUG_PARCEIRO,
            meu_album_id:  albumId,
        }),
    });
    const data = await resp.json();

    if (!data.sucesso) {
        document.getElementById('estado-inicial').textContent = 'Erro ao carregar. Tente novamente.';
        return;
    }

    dadosParceiro = data.parceiro;
    document.getElementById('titulo-parceiro').textContent = data.parceiro.nome;
    const loc = [data.parceiro.cidade, data.parceiro.estado].filter(Boolean).join(', ');
    document.getElementById('subtitulo-parceiro').textContent = loc || '';

    renderizarGrid('grid-oferece', 'vazio-oferece', data.oferece, 'oferece');
    renderizarGrid('grid-precisa', 'vazio-precisa', data.precisa, 'precisa');

    document.getElementById('badge-oferece').textContent = data.oferece.length;
    document.getElementById('badge-precisa').textContent = data.precisa.length;

    document.getElementById('estado-inicial').style.display = 'none';
    document.getElementById('conteudo').style.display = 'block';
}

// ── Renderiza grid agrupado ───────────────────────────────────────────────
function renderizarGrid(gridId, vazioId, figurinhas, tipo) {
    const grid  = document.getElementById(gridId);
    const vazio = document.getElementById(vazioId);
    grid.innerHTML = '';

    if (!figurinhas.length) { vazio.style.display='block'; return; }
    vazio.style.display = 'none';

    const statusEmoji = { venda:'💰', bloqueada:'🔒' };

    const grupos = {};
    for (const f of figurinhas) {
        const gKey = f.grupo_codigo || 'especial';
        if (!grupos[gKey]) grupos[gKey] = {};
        if (!grupos[gKey][f.selecao_sigla])
            grupos[gKey][f.selecao_sigla] = { nome:f.selecao_nome, bandeira:f.bandeira_url, itens:[] };
        grupos[gKey][f.selecao_sigla].itens.push(f);
    }

    for (const [gKey, selecoes] of Object.entries(grupos)) {
        const divG = document.createElement('div');
        const tit  = document.createElement('div');
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
    		<img src="${sel.bandeira}" alt="${sigla}" loading="lazy" onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span>
                <span>${sel.nome}</span>`;
            divS.appendChild(mini);

            const subGrid = document.createElement('div');
            subGrid.className = 'figurinhas-grid';

            for (const f of sel.itens) {
                const status = tipo === 'oferece' ? f.status_troca : (f.meu_status ?? 'livre');
                const emoji  = statusEmoji[status] ?? '';
                const bloqueada = status === 'bloqueada';
                const qtd    = tipo === 'oferece' ? f.quantidade : f.minha_quantidade;

                const card = document.createElement('div');
                card.className = `figurinha-card tem repetida${bloqueada ? ' bloqueada' : ''}`;
                card.dataset.figId  = f.figurinha_id;
                card.dataset.tipo   = tipo;
                card.dataset.status = status;
                card.dataset.codigo = f.codigo;
                card.dataset.qtd    = qtd;
                card.style.position = 'relative';
                card.style.cursor   = bloqueada ? 'default' : 'pointer';

                let detalhe = `×${qtd}`;
                if (tipo === 'oferece' && status === 'venda' && f.valor_troca) {
                    detalhe += ` R$${parseFloat(f.valor_troca).toFixed(2)}`;
                }

                card.innerHTML = `
                    <span class="fig-codigo">${f.codigo}</span>
                    <div style="font-size:.72rem;text-align:center;margin-top:.1rem">${detalhe}</div>
                    ${emoji ? `<div class="fig-status-badge">${emoji}</div>` : ''}`;

                if (!bloqueada) {
                    card.addEventListener('click', () => toggleSelecionada(card, f, tipo));
                    card.addEventListener('touchend', e => {
                        e.preventDefault();
                        toggleSelecionada(card, f, tipo);
                    }, { passive: false });
                }

                subGrid.appendChild(card);
            }

            divS.appendChild(subGrid);
            divG.appendChild(divS);
        }

        grid.appendChild(divG);
    }
}

// ── Seleciona / deseleciona figurinha ─────────────────────────────────────
function toggleSelecionada(card, f, tipo) {
    const id = f.figurinha_id;
    if (selecionadas[tipo].has(id)) {
        selecionadas[tipo].delete(id);
        dadosSelecionadas[tipo].delete(id);
        card.classList.remove('selecionada-troca');
    } else {
        selecionadas[tipo].add(id);
        dadosSelecionadas[tipo].set(id, f);
        card.classList.add('selecionada-troca');
    }
    atualizarBarraMensagem();
}

function atualizarBarraMensagem() {
    const total = selecionadas.oferece.size + selecionadas.precisa.size;
    const barra = document.getElementById('barra-mensagem');
    const msg   = document.getElementById('msg-selecionadas');

    if (total === 0) { barra.style.display = 'none'; return; }

    barra.style.display = '';
    const partes = [];
    if (selecionadas.oferece.size) partes.push(`${selecionadas.oferece.size} que quero`);
    if (selecionadas.precisa.size) partes.push(`${selecionadas.precisa.size} que ofereço`);
    msg.textContent = partes.join(' · ');
}

function limparSelecao() {
    selecionadas.oferece.clear(); selecionadas.precisa.clear();
    dadosSelecionadas.oferece.clear(); dadosSelecionadas.precisa.clear();
    document.querySelectorAll('.figurinha-card.selecionada-troca')
        .forEach(c => c.classList.remove('selecionada-troca'));
    atualizarBarraMensagem();
}

// ── Monta e envia mensagem ────────────────────────────────────────────────
function enviarMensagem() {
    if (!dadosParceiro) return;

    const linhas = [];
    linhas.push(`Olá ${dadosParceiro.nome}! Vi no IFC Copa 2026 que temos figurinhas para trocar. 😊`);
    linhas.push('');

    // Figurinhas que quero receber dele
    if (dadosSelecionadas.oferece.size > 0) {
        linhas.push('📥 *Quero receber de você:*');
        linhas.push(formatarListaCSV([...dadosSelecionadas.oferece.values()], 'oferece'));
        linhas.push('');
    }

    // Figurinhas que vou oferecer a ele
    if (dadosSelecionadas.precisa.size > 0) {
        linhas.push('📤 *Posso te oferecer:*');
        linhas.push(formatarListaCSV([...dadosSelecionadas.precisa.values()], 'precisa'));
        linhas.push('');
    }

    linhas.push('Podemos combinar a troca? 🤝');

    const texto = linhas.join('\n');
    const m = dadosParceiro;

    if (m.contato_tipo === 'whatsapp') {
        const num = m.contato_valor.replace(/\D/g, '');
        window.open(`https://wa.me/${num}?text=${encodeURIComponent(texto)}`, '_blank');
    } else if (m.contato_tipo === 'telegram') {
        const user = m.contato_valor.replace('@', '');
        // Telegram não suporta texto pré-preenchido via URL — copia para clipboard
        navigator.clipboard.writeText(texto).then(() => {
            alert('Mensagem copiada! Abra o Telegram e cole para ' + m.contato_valor);
            window.open(`https://t.me/${user}`, '_blank');
        });
    } else if (m.contato_tipo === 'email') {
        const assunto = 'Troca de figurinhas — IFC Copa 2026';
        window.open(`mailto:${m.contato_valor}?subject=${encodeURIComponent(assunto)}&body=${encodeURIComponent(texto)}`, '_blank');
    } else {
        // Sem contato — copia para clipboard
        navigator.clipboard.writeText(texto).then(() => {
            alert('Mensagem copiada para a área de transferência!');
        });
    }
}

// ── Formata lista em 8 colunas (4 pares código×qtd por linha) ────────────
function formatarListaCSV(figurinhas, tipo) {
    const colunas = 4; // pares por linha
    const linhas  = [];
    let linha = [];

    for (const f of figurinhas) {
        const qtd = tipo === 'oferece' ? f.quantidade : f.minha_quantidade;
        linha.push(`${f.codigo}×${qtd}`);
        if (linha.length === colunas) {
            linhas.push(linha.join('  '));
            linha = [];
        }
    }
    if (linha.length) linhas.push(linha.join('  '));

    return linhas.join('\n');
}

// ── Abas ──────────────────────────────────────────────────────────────────
function trocarAba(btn) {
    document.querySelectorAll('.aba').forEach(a => a.classList.remove('ativa'));
    document.querySelectorAll('.aba-conteudo').forEach(c => c.style.display = 'none');
    btn.classList.add('ativa');
    document.getElementById(`aba-${btn.dataset.aba}`).style.display = 'block';
}

// ── Filtro por status ─────────────────────────────────────────────────────
function filtrarOferece(btn) {
    document.querySelectorAll('.status-pill').forEach(p => p.classList.remove('ativa'));
    btn.classList.add('ativa');
    const s = btn.dataset.status;
    document.querySelectorAll('#grid-oferece .figurinha-card').forEach(card => {
        card.style.display = (!s || card.dataset.status === s) ? '' : 'none';
    });
}
</script>

<?php layoutFim(); ?>
