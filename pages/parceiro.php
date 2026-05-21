<?php
// pages/parceiro.php — Figurinhas de um parceiro de troca
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Slug do parceiro vem na rota: /parceiro/{slug}
$partes       = explode('/', trim($_GET['route'] ?? '', '/'));
$slugParceiro = $partes[1] ?? '';

if (!$slugParceiro) { header('Location: /trocas'); exit; }

// Álbuns do usuário logado para o <select>
$stmt = $db->prepare("
    SELECT id, nome, total_repetidas
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY nome
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Parceiro de Troca');
?>

<div class="inventario-header">
    <div>
        <a href="/trocas" class="btn-voltar">← Trocas</a>
        <h1 class="page-title" id="titulo-parceiro" style="margin-bottom:.25rem">
            Carregando...
        </h1>
        <p id="subtitulo-parceiro" style="color:#888;font-size:.9rem;margin:0"></p>
    </div>
    <div id="contato-header"></div>
</div>

<!-- Seletor de álbum + botão carregar -->
<div class="acoes-barra" style="margin-bottom:1rem">
    <label style="font-weight:600;font-size:.9rem">📚 Meu álbum:</label>
    <select id="select-album"
            style="padding:.4rem .6rem;border-radius:6px;border:1px solid #ddd;font-size:.9rem">
        <option value="">— selecione —</option>
        <?php foreach ($albuns as $a): ?>
            <option value="<?= $a['id'] ?>">
                <?= htmlspecialchars($a['nome']) ?>
                (<?= $a['total_repetidas'] ?> repetidas)
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn-sm btn-todas-inc" onclick="carregar()">Ver figurinhas</button>
</div>

<!-- Estado inicial -->
<div id="estado-inicial" class="trocas-estado">
    Selecione seu álbum e clique em "Ver figurinhas".
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
            Repetidas do parceiro — clique em "Contato" para negociar.
        </p>

        <!-- Filtro por status -->
        <div class="status-filtros">
            <button class="status-pill ativa" data-status="" onclick="filtrarOferece(this)">Todas</button>
            <button class="status-pill" data-status="livre"     onclick="filtrarOferece(this)">🟢 Livre</button>
            <button class="status-pill" data-status="troca"     onclick="filtrarOferece(this)">🔄 Troca</button>
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
            Figurinhas que ele precisa e você tem repetidas — com seu status atual.
        </p>
        <div id="grid-precisa" class="lista-grupos-trocas"></div>
        <div id="vazio-precisa" class="trocas-estado" style="display:none">
            Nenhuma figurinha em comum encontrada.
        </div>
    </div>
</div>

<!-- Modal de contato -->
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
/* Abas */
.abas-nav {
    display: flex; gap: .5rem; margin-bottom: 1rem; flex-wrap: wrap;
}
.aba {
    display: flex; align-items: center; gap: .5rem;
    padding: .55rem 1rem; border-radius: 8px;
    border: 1px solid #ddd; background: #f5f5f5;
    font-size: .9rem; font-weight: 600; cursor: pointer;
    transition: background .15s;
}
.aba.ativa { background: #6366f1; color: #fff; border-color: #6366f1; }
.aba.ativa .trocas-badge { background: rgba(255,255,255,.3); }

.aba-conteudo { animation: fadeIn .15s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Status filtros */
.status-filtros { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.75rem; }
.status-pill {
    padding:.25rem .65rem; border-radius:20px;
    border:1px solid #ddd; background:#f5f5f5;
    font-size:.8rem; cursor:pointer;
}
.status-pill.ativa { background:#6366f1; color:#fff; border-color:#6366f1; }

/* Grid agrupado */
.lista-grupos-trocas .grupo-titulo {
    font-weight:700; font-size:.78rem; text-transform:uppercase;
    letter-spacing:.05em; color:#aaa; margin:.75rem 0 .3rem;
}
.lista-grupos-trocas .selecao-mini {
    display:flex; align-items:center; gap:.4rem;
    font-size:.82rem; font-weight:600; margin:.5rem 0 .3rem;
}
.lista-grupos-trocas .selecao-mini img { width:20px; height:14px; object-fit:cover; border-radius:2px; }

/* Badge de status no card */
.fig-status-badge {
    position:absolute; bottom:2px; left:0; right:0;
    text-align:center; font-size:.6rem; line-height:1.3; pointer-events:none;
}
/* Card bloqueado */
.figurinha-card.bloqueada { opacity: .5; }

/* Contato no header */
.contato-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .3rem .75rem; border-radius: 20px;
    background: #f0fdf4; border: 1px solid #bbf7d0;
    font-size: .85rem; font-weight: 600; color: #16a34a;
    text-decoration: none; cursor: pointer;
}
.contato-pill:hover { background: #dcfce7; }

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
.modal-rodape { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }
.contato-linha {
    display:flex; align-items:center; gap:.75rem;
    padding:.75rem; background:#f5f5f5; border-radius:8px; margin-bottom:.5rem;
}
.contato-icone { font-size:1.4rem; }
.contato-link { font-weight:600; font-size:.95rem; color:#6366f1; text-decoration:none; }
.contato-link:hover { text-decoration:underline; }

.trocas-estado { text-align:center; padding:2.5rem 1rem; color:#aaa; font-size:.95rem; line-height:1.7; }
.trocas-badge {
    background:#6366f1; color:#fff;
    border-radius:20px; padding:.1rem .55rem;
    font-size:.78rem; font-weight:700;
}
.trocas-secao-desc { color:#888; font-size:.85rem; margin:0 0 .75rem; }
</style>

<script>
const SLUG_PARCEIRO = <?= json_encode($slugParceiro) ?>;
let dadosParceiro   = null;
let filtroOferece   = '';

// ── Carrega dados do parceiro ─────────────────────────────────────────────
async function carregar() {
    const albumId = document.getElementById('select-album').value;
    if (!albumId) { alert('Selecione seu álbum primeiro.'); return; }

    document.getElementById('estado-inicial').textContent = '⏳ Carregando...';
    document.getElementById('estado-inicial').style.display = 'block';
    document.getElementById('conteudo').style.display = 'none';

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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

    // Atualiza cabeçalho
    document.getElementById('titulo-parceiro').textContent = data.parceiro.nome;
    const loc = [data.parceiro.cidade, data.parceiro.estado].filter(Boolean).join(', ');
    document.getElementById('subtitulo-parceiro').textContent = loc || '';

    // Botão de contato no header
    const ch = document.getElementById('contato-header');
    ch.innerHTML = `<button class="contato-pill" onclick="abrirContato()">📲 Contato</button>`;

    // Renderiza abas
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

    if (!figurinhas.length) {
        vazio.style.display = 'block';
        return;
    }
    vazio.style.display = 'none';

    const statusEmoji = { livre:'🟢', troca:'🔄', venda:'💰', bloqueada:'🔒' };
    const meuEmoji    = { livre:'🟢', troca:'🔄', venda:'💰', bloqueada:'🔒' };

    // Agrupa por grupo → seleção
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
                const status = tipo === 'oferece' ? f.status_troca : f.meu_status;
                const emoji  = statusEmoji[status] ?? '🟢';

                card.className = `figurinha-card tem repetida${status === 'bloqueada' ? ' bloqueada' : ''}`;
                card.dataset.status = status ?? 'livre';
                card.style.position = 'relative';
                card.style.cursor   = status === 'bloqueada' ? 'default' : 'pointer';

                // Linha de quantidade/valor
                let detalhe = `×${f.quantidade ?? f.minha_quantidade}`;
                if (tipo === 'oferece' && status === 'venda' && f.valor_troca) {
                    detalhe += ` · R$${parseFloat(f.valor_troca).toFixed(2)}`;
                }

                card.innerHTML = `
                    <span class="fig-codigo">${f.codigo}</span>
                    <div style="font-size:.75rem;text-align:center;margin-top:.1rem">${detalhe}</div>
                    <div class="fig-status-badge">${emoji}</div>`;

                subGrid.appendChild(card);
            }

            divS.appendChild(subGrid);
            divG.appendChild(divS);
        }

        grid.appendChild(divG);
    }
}

// ── Abas ──────────────────────────────────────────────────────────────────
function trocarAba(btn) {
    document.querySelectorAll('.aba').forEach(a => a.classList.remove('ativa'));
    document.querySelectorAll('.aba-conteudo').forEach(c => c.style.display = 'none');
    btn.classList.add('ativa');
    document.getElementById(`aba-${btn.dataset.aba}`).style.display = 'block';
}

// ── Filtro por status (aba "ele oferece") ─────────────────────────────────
function filtrarOferece(btn) {
    document.querySelectorAll('.status-pill').forEach(p => p.classList.remove('ativa'));
    btn.classList.add('ativa');
    filtroOferece = btn.dataset.status;
    document.querySelectorAll('#grid-oferece .figurinha-card').forEach(card => {
        card.style.display = (!filtroOferece || card.dataset.status === filtroOferece) ? '' : 'none';
    });
}

// ── Modal de contato ──────────────────────────────────────────────────────
function abrirContato() {
    if (!dadosParceiro) return;
    const m = dadosParceiro;

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

function fecharModal(e) {
    if (e.target.classList.contains('modal-overlay')) e.target.style.display = 'none';
}
</script>

<?php layoutFim(); ?>
