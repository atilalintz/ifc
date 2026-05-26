<?php
// pages/trocas_externas.php — Trocas com outros colecionadores
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
$albumPadrao = $albuns[0] ?? null;

layoutInicio('Trocas Externas');
?>

<div class="inventario-header">
    <div>
        <a href="/trocas" class="btn-voltar">← Trocas</a>
        <h1 class="page-title" style="margin-bottom:.25rem">🤝 Trocas Externas</h1>
        <p style="color:#888;font-size:.9rem;margin:0">
            Gerencie suas repetidas e encontre parceiros de troca
        </p>
    </div>
</div>

<!-- Seletor de álbum -->
<div class="acoes-barra" style="margin-bottom:1rem">
    <label style="font-weight:600;font-size:.9rem">📚 Álbum:</label>
    <select id="select-album" onchange="trocarAlbum()"
            style="padding:.4rem .6rem;border-radius:6px;border:1px solid #ddd;font-size:.9rem">
        <?php foreach ($albuns as $a): ?>
            <option value="<?= $a['id'] ?>"
                    <?= $albumPadrao && $a['id'] === $albumPadrao['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['nome']) ?>
                (<?= $a['total_repetidas'] ?> repetidas · <?= number_format($a['percentual_conclusao'],1) ?>%)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div id="conteudo-trocas">

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
            <button class="status-pill" data-status="venda"     onclick="filtrarStatus(this)">💰 Venda</button>
            <button class="status-pill" data-status="bloqueada" onclick="filtrarStatus(this)">🔒 Bloqueada</button>
        </div>
        <div id="grid-repetidas" class="lista-grupos-trocas"></div>
        <div id="estado-sem-repetidas" class="trocas-estado" style="display:none">
            Nenhuma figurinha repetida neste álbum ainda.
        </div>
    </div>

    <!-- ══ SEÇÃO 2: Parceiros ═════════════════════════════════════════════ -->
    <div class="trocas-secao">
        <div class="trocas-secao-header">
            <h2 class="trocas-secao-titulo">🤝 Parceiros de Troca</h2>
            <span id="badge-matches" class="trocas-badge">0</span>
        </div>
        <p class="trocas-secao-desc">
            Usuários que têm o que você precisa ou precisam do que você tem.
        </p>

        <!-- Busca + ordenação -->
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem">
            <input type="text" id="busca-parceiro" placeholder="🔍 Buscar parceiro..."
                   oninput="buscarParceiros()"
                   style="flex:1;min-width:140px;padding:.4rem .6rem;border:1px solid #ddd;border-radius:8px;font-size:.85rem">
        </div>
        <div class="trocas-ordem-filtros" style="margin-bottom:.75rem">
            <button class="trocas-ordem-btn ativo" data-ordem="matches"   onclick="toggleOrdem(this)">🤝 Matches</button>
            <button class="trocas-ordem-btn"        data-ordem="distancia" onclick="toggleOrdem(this)">📍 Distância</button>
            <button class="trocas-ordem-btn"        data-ordem="favoritos" onclick="toggleOrdem(this)">⭐ Favoritos</button>
        </div>

        <div id="lista-matches"></div>
        <div id="estado-sem-matches" class="trocas-estado" style="display:none">
            Nenhum parceiro encontrado ainda.
        </div>
        <div id="estado-calculando" class="trocas-estado" style="display:none">
            ⏳ Calculando matches...
        </div>
    </div>
</div>

<!-- Modal de status -->
<div id="modal-status" class="modal-overlay" style="display:none" onclick="fecharModal(event)">
    <div class="modal-box">
        <div class="modal-titulo" id="modal-status-titulo">Figurinha</div>
        <div class="modal-opcoes">
            <button class="modal-opcao" data-status="livre"     onclick="definirStatus('livre')">
                <span class="modal-opcao-emoji">🟢</span>
                <div><div class="modal-opcao-titulo">Livre</div><div class="modal-opcao-desc">Disponível para troca</div></div>
            </button>
            <button class="modal-opcao" data-status="venda"     onclick="definirStatus('venda')">
                <span class="modal-opcao-emoji">💰</span>
                <div><div class="modal-opcao-titulo">Venda</div><div class="modal-opcao-desc">Quero vender</div></div>
            </button>
            <button class="modal-opcao" data-status="bloqueada" onclick="definirStatus('bloqueada')">
                <span class="modal-opcao-emoji">🔒</span>
                <div><div class="modal-opcao-titulo">Bloqueada</div><div class="modal-opcao-desc">Não negociar</div></div>
            </button>
        </div>
        <div id="campo-valor" style="display:none;margin-top:.75rem">
            <label style="font-size:.85rem;font-weight:600">Valor (R$):</label>
            <input type="number" id="input-valor" min="0" step="0.50" placeholder="Ex: 5.00"
                   style="width:100%;padding:.4rem;border-radius:6px;border:1px solid #ddd;margin-top:.3rem">
        </div>
        <div class="modal-rodape">
            <button class="btn-sm btn-todas-inc" id="btn-confirmar-status"
                    onclick="confirmarStatus()">✓ Confirmar</button>
            <button class="btn-sm" onclick="document.getElementById('modal-status').style.display='none'">Cancelar</button>
        </div>
    </div>
</div>

<style>
.trocas-secao { background:#fff; border:1px solid var(--borda); border-radius:12px; padding:1.25rem; margin-bottom:1.5rem; }
.trocas-secao-header { display:flex; align-items:center; gap:.75rem; margin-bottom:.4rem; }
.trocas-secao-titulo { margin:0; font-size:1.05rem; }
.trocas-badge { background:#6366f1; color:#fff; border-radius:20px; padding:.1rem .55rem; font-size:.78rem; font-weight:700; }
.trocas-secao-desc { color:#888; font-size:.85rem; margin:0 0 .75rem; }
.trocas-estado { text-align:center; padding:2rem 1rem; color:#aaa; font-size:.92rem; line-height:1.7; }
.status-filtros { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.75rem; }
.status-pill { padding:.25rem .65rem; border-radius:20px; border:1px solid #ddd; background:#f5f5f5; font-size:.8rem; cursor:pointer; }
.status-pill.ativa { background:#6366f1; color:#fff; border-color:#6366f1; }
.trocas-ordem-filtros { display:flex; gap:.4rem; flex-wrap:wrap; }
.trocas-ordem-btn { padding:.25rem .65rem; border-radius:20px; border:1px solid #ddd; background:#f5f5f5; font-size:.8rem; cursor:pointer; font-weight:600; }
.trocas-ordem-btn.ativo { background:#6366f1; color:#fff; border-color:#6366f1; }
.lista-grupos-trocas .grupo-titulo { font-weight:700; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:#aaa; margin:.75rem 0 .3rem; }
.lista-grupos-trocas .selecao-mini { display:flex; align-items:center; gap:.4rem; font-size:.82rem; font-weight:600; margin:.5rem 0 .3rem; }
.lista-grupos-trocas .selecao-mini img { width:20px; height:14px; object-fit:cover; border-radius:2px; }
.fig-status-badge { position:absolute; top:2px; right:3px; font-size:.62rem; line-height:1; background:rgba(0,0,0,.55); border-radius:6px; padding:1px 3px; pointer-events:none; }
.match-card { display:flex; align-items:center; gap:1rem; padding:.85rem 1rem; border:1px solid var(--borda); border-radius:10px; margin-bottom:.6rem; background:#fafafa; }
.match-avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; flex-shrink:0; background:#e0e0e0; }
.match-info { flex:1; min-width:0; }
.match-nome { font-weight:700; font-size:.95rem; }
.match-loc  { font-size:.78rem; color:#888; }
.match-score { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:20px; padding:.2rem .6rem; font-size:.8rem; font-weight:700; white-space:nowrap; }
.match-btn { padding:.35rem .8rem; border-radius:8px; background:#6366f1; color:#fff; border:none; font-size:.82rem; cursor:pointer; }
.match-fav  { background:none; border:none; font-size:1.1rem; cursor:pointer; opacity:.4; transition:opacity .15s; }
.match-fav.ativo { opacity:1; }
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; z-index:1000; padding:1rem; }
.modal-box { background:#fff; border-radius:14px; padding:1.5rem; width:100%; max-width:360px; box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal-titulo { font-weight:700; font-size:1rem; margin-bottom:1rem; }
.modal-opcoes { display:flex; flex-direction:column; gap:.5rem; }
.modal-opcao { display:flex; align-items:center; gap:.75rem; padding:.7rem .9rem; border-radius:10px; border:2px solid #e0e0e0; background:#fafafa; text-align:left; font-size:.9rem; cursor:pointer; transition:all .15s; }
.modal-opcao:hover { border-color:#6366f1; background:#eef2ff; }
.modal-opcao.ativa { border-color:#6366f1; background:#eef2ff; }
.modal-opcao.ativa[data-status="livre"]     { border-color:#16a34a; background:#f0fdf4; }
.modal-opcao.ativa[data-status="venda"]     { border-color:#d97706; background:#fffbeb; }
.modal-opcao.ativa[data-status="bloqueada"] { border-color:#6b7280; background:#f3f4f6; }
.modal-opcao-emoji { font-size:1.3rem; flex-shrink:0; }
.modal-opcao-titulo { font-weight:700; font-size:.9rem; }
.modal-opcao-desc   { font-size:.78rem; color:#888; }
.modal-rodape { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }
</style>

<script>
const ALBUM_PADRAO = <?= json_encode($albumPadrao ? $albumPadrao['id'] : null) ?>;
let albumId     = ALBUM_PADRAO;
let ordemAtiva  = new Set(['matches']);
let buscaTimer  = null;
let figSelecionada = null, statusPendente = null;
const statusEmoji  = { venda:'💰', bloqueada:'🔒' };

// ── Troca álbum ───────────────────────────────────────────────────────────
async function trocarAlbum() {
    albumId = document.getElementById('select-album').value || ALBUM_PADRAO;
    await Promise.all([carregarRepetidas(), recalcularEListarMatches()]);
}

// ── Seção 1: Minhas repetidas ─────────────────────────────────────────────
async function carregarRepetidas() {
    const resp = await fetch('/api/trocas', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `acao=minhas_repetidas&album_id=${albumId}`,
    });
    const data = await resp.json();
    const grid = document.getElementById('grid-repetidas');
    const vazio= document.getElementById('estado-sem-repetidas');
    const badge= document.getElementById('badge-repetidas');
    if (!data.sucesso || !data.figurinhas.length) {
        grid.innerHTML=''; grid.style.display='none'; vazio.style.display='block'; badge.textContent='0'; return;
    }
    vazio.style.display='none'; grid.style.display='block'; badge.textContent=data.figurinhas.length;
    renderizarRepetidas(data.figurinhas);
}

function renderizarRepetidas(figurinhas) {
    const grid = document.getElementById('grid-repetidas');
    grid.innerHTML = '';
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
        tit.className='grupo-titulo'; tit.textContent=gKey==='especial'?'Especiais':`Grupo ${gKey}`;
        divG.appendChild(tit);
        for (const [sigla, sel] of Object.entries(selecoes)) {
            const divS = document.createElement('div'); divS.dataset.sigla=sigla;
            const mini = document.createElement('div'); mini.className='selecao-mini';
            mini.innerHTML=`<img src="${sel.bandeira}" alt="${sigla}" onerror="this.style.display='none'">
                <span class="selecao-sigla-badge">${sigla}</span><span>${sel.nome}</span>`;
            divS.appendChild(mini);
            const subGrid = document.createElement('div'); subGrid.className='figurinhas-grid';
            for (const f of sel.itens) subGrid.appendChild(criarCardRepetida(f));
            divS.appendChild(subGrid); divG.appendChild(divS);
        }
        grid.appendChild(divG);
    }
    aplicarFiltroStatus();
}

function criarCardRepetida(f) {
    const card = document.createElement('div');
    card.className=`figurinha-card tem${f.quantidade>1?' repetida':''}`;
    card.id=`rep-${f.figurinha_id}`; card.dataset.id=f.figurinha_id;
    card.dataset.codigo=f.codigo; card.dataset.status=f.status_troca;
    card.style.position='relative'; card.style.cursor='pointer';
    const emoji = statusEmoji[f.status_troca] ?? '';
    card.innerHTML=`<span class="fig-codigo">${f.codigo}</span>
        <div class="fig-qtd" style="font-size:.8rem;text-align:center">×${f.quantidade}</div>
        <div class="fig-status-badge" id="badge-rep-${f.figurinha_id}"
             style="${emoji?'':'display:none'}">${emoji}</div>`;
    configurarLongPress(card, () => abrirModalStatus(f));
    return card;
}

function filtrarStatus(btn) {
    document.querySelectorAll('.status-pill').forEach(p=>p.classList.remove('ativa'));
    btn.classList.add('ativa');
    const s = btn.dataset.status;
    document.querySelectorAll('#grid-repetidas .figurinha-card').forEach(c=>{
        c.style.display = (!s || c.dataset.status===s) ? '' : 'none';
    });
}
function aplicarFiltroStatus() {
    const s = document.querySelector('.status-pill.ativa')?.dataset.status ?? '';
    document.querySelectorAll('#grid-repetidas .figurinha-card').forEach(c=>{
        c.style.display = (!s || c.dataset.status===s) ? '' : 'none';
    });
}

function abrirModalStatus(f) {
    figSelecionada=f; statusPendente=f.status_troca;
    document.getElementById('modal-status-titulo').textContent=`Figurinha ${f.codigo}`;
    document.querySelectorAll('.modal-opcao').forEach(b=>b.classList.toggle('ativa',b.dataset.status===f.status_troca));
    document.getElementById('campo-valor').style.display=f.status_troca==='venda'?'block':'none';
    document.getElementById('input-valor').value=f.valor_troca??'';
    document.getElementById('modal-status').style.display='flex';
}
function definirStatus(status) {
    statusPendente=status;
    document.querySelectorAll('.modal-opcao').forEach(b=>b.classList.toggle('ativa',b.dataset.status===status));
    document.getElementById('campo-valor').style.display=status==='venda'?'block':'none';
}
async function confirmarStatus() {
    if (!statusPendente||!figSelecionada) return;
    const valor=document.getElementById('input-valor').value;
    const resp=await fetch('/api/trocas',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({acao:'status_figurinha',album_id:albumId,figurinha_id:figSelecionada.figurinha_id,status:statusPendente,valor})});
    const data=await resp.json();
    if (data.sucesso) {
        const card=document.getElementById(`rep-${figSelecionada.figurinha_id}`);
        const badge=document.getElementById(`badge-rep-${figSelecionada.figurinha_id}`);
        if(card) card.dataset.status=data.status;
        if(badge){ const e=statusEmoji[data.status]??''; badge.textContent=e; badge.style.display=e?'':'none'; }
        document.getElementById('modal-status').style.display='none';
        statusPendente=null; aplicarFiltroStatus();
    } else alert('Erro: '+(data.erro??'desconhecido'));
}

// ── Seção 2: Parceiros ────────────────────────────────────────────────────
function buscarParceiros() { clearTimeout(buscaTimer); buscaTimer=setTimeout(recalcularEListarMatches,300); }

function toggleOrdem(btn) {
    const c=btn.dataset.ordem;
    if(ordemAtiva.has(c)){ if(ordemAtiva.size>1){ordemAtiva.delete(c);btn.classList.remove('ativo');} }
    else { ordemAtiva.add(c); btn.classList.add('ativo'); }
    recalcularEListarMatches();
}

async function recalcularEListarMatches() {
    document.getElementById('lista-matches').innerHTML='';
    document.getElementById('estado-sem-matches').style.display='none';
    document.getElementById('estado-calculando').style.display='block';

    await fetch('/api/trocas',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`acao=match_recalcular&album_id=${albumId}`});

    const busca=document.getElementById('busca-parceiro').value.trim();
    const resp=await fetch('/api/trocas',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({acao:'colecionadores_listar',album_id:albumId,busca,ordem:[...ordemAtiva].join(','),limite:'0'})});
    const data=await resp.json();

    document.getElementById('estado-calculando').style.display='none';
    const lista=document.getElementById('lista-matches');
    const badge=document.getElementById('badge-matches');
    const vazio=document.getElementById('estado-sem-matches');

    if (!data.sucesso||!data.colecionadores.length) { vazio.style.display='block'; badge.textContent='0'; return; }
    badge.textContent=data.colecionadores.length;
    for (const m of data.colecionadores) lista.appendChild(criarCardMatch(m));
}

function criarCardMatch(m) {
    const avatar=m.avatar_url??`https://ui-avatars.com/api/?name=${encodeURIComponent(m.nome)}&size=44`;
    const loc=[m.cidade,m.estado].filter(Boolean).join(', ')||'Localização não informada';
    const dist=m.distancia_km?`· ${Math.round(m.distancia_km)} km`:'';
    const card=document.createElement('div'); card.className='match-card';
    card.innerHTML=`
        <img class="match-avatar" src="${avatar}" alt="${m.nome}"
             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(m.nome)}&size=44'">
        <div class="match-info">
            <div class="match-nome">${m.nome}</div>
            <div class="match-loc">${loc} ${dist}</div>
        </div>
        <span class="match-score">${m.score_match} match${m.score_match!==1?'es':''}</span>
        <button class="match-fav ${m.favorito?'ativo':''}" data-id="${m.id}"
                onclick="toggleFavorito(this,event)">⭐</button>
        <button class="match-btn"
                onclick="window.location.href='/trocas/parceiro/${m.slug_publico}?album=${albumId}'">
            Ver figurinhas
        </button>`;
    return card;
}

async function toggleFavorito(btn,e) {
    e.stopPropagation();
    const resp=await fetch('/api/trocas',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({acao:'favorito_toggle',favorito_id:btn.dataset.id})});
    const data=await resp.json();
    if(data.sucesso) btn.classList.toggle('ativo',data.favoritado);
}

function configurarLongPress(el,callback) {
    let timer=null,moveu=false;
    el.addEventListener('touchstart',()=>{moveu=false;timer=setTimeout(()=>{if(!moveu){callback();if(navigator.vibrate)navigator.vibrate(40);}},500);},{passive:true});
    el.addEventListener('touchmove',()=>{moveu=true;clearTimeout(timer);},{passive:true});
    el.addEventListener('touchend',()=>clearTimeout(timer));
    el.addEventListener('contextmenu',e=>{e.preventDefault();callback();});
}
function fecharModal(e){if(e.target.classList.contains('modal-overlay'))e.target.style.display='none';}

// Inicializa
document.addEventListener('DOMContentLoaded',()=>{ if(albumId) Promise.all([carregarRepetidas(),recalcularEListarMatches()]); });
</script>

<?php layoutFim(); ?>
