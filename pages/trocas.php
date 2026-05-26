<?php
// pages/trocas.php — Página de escolha entre trocas internas e externas
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Álbuns ordenados por mais repetidas primeiro
$stmt = $db->prepare("
    SELECT id, nome, total_repetidas, percentual_conclusao, total_faltantes
    FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY total_repetidas DESC, percentual_conclusao DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

// Álbum padrão = primeiro da lista (mais repetidas)
$albumPadrao = $albuns[0] ?? null;

layoutInicio('Trocas — Copa 2026');
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">Trocas</h1>
    </div>
</div>

<!-- Seletor de álbum ───────────────────────────────────────────────────── -->
<div class="trocas-album-bar">
    <label style="font-weight:600;font-size:.9rem;white-space:nowrap">📚 Álbum:</label>
    <select id="select-album" onchange="trocarAlbum()">
        <?php foreach ($albuns as $a): ?>
            <option value="<?= $a['id'] ?>"
                    <?= $albumPadrao && $a['id'] === $albumPadrao['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['nome']) ?>
                (<?= $a['total_repetidas'] ?> repetidas)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Conteúdo principal ─────────────────────────────────────────────────── -->
<div class="trocas-escolha-grid">

    <!-- ══ Internas ══════════════════════════════════════════════════════ -->
    <div class="trocas-escolha-card">
        <div class="trocas-escolha-titulo">
            <span class="trocas-escolha-icone">↔️</span>
            <div>
                <h2>Trocas Internas</h2>
                <p>Transfira figurinhas entre seus próprios álbuns</p>
            </div>
        </div>

        <div id="lista-albuns-internos" class="trocas-lista-albuns">
            <?php if (empty($albuns)): ?>
                <p class="trocas-lista-vazia">Nenhum álbum cadastrado.</p>
            <?php else: ?>
                <?php foreach ($albuns as $a):
                    $pct = number_format($a['percentual_conclusao'], 1);
                    $barW = min(100, (float)$a['percentual_conclusao']);
                ?>
                    <a href="/trocas/internas?origem=<?= $a['id'] ?>"
                       class="trocas-album-item">
                        <div class="trocas-album-item-info">
                            <span class="trocas-album-item-nome">
                                <?= htmlspecialchars($a['nome']) ?>
                            </span>
                            <span class="trocas-album-item-meta">
                                <?= $pct ?>% · <?= $a['total_faltantes'] ?> faltantes · <?= $a['total_repetidas'] ?> repetidas
                            </span>
                        </div>
                        <div class="trocas-album-item-barra">
                            <div style="width:<?= $barW ?>%"></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <a href="/trocas/internas" class="trocas-escolha-btn">
            Acessar Internas →
        </a>
    </div>

    <!-- ══ Externas ══════════════════════════════════════════════════════ -->
    <div class="trocas-escolha-card">
        <div class="trocas-escolha-titulo">
            <span class="trocas-escolha-icone">🤝</span>
            <div>
                <h2>Trocas Externas</h2>
                <p>Encontre parceiros e troque com outros colecionadores</p>
            </div>
        </div>

        <!-- Busca de colecionadores -->
        <div class="trocas-busca-wrap">
            <input type="text" id="busca-colecionador"
                   placeholder="🔍 Buscar colecionador..."
                   oninput="buscarColecionadores()">
        </div>

        <!-- Filtros de ordenação combináveis -->
        <div class="trocas-ordem-filtros">
            <button class="trocas-ordem-btn ativo" data-ordem="matches"
                    onclick="toggleOrdem(this)">🤝 Matches</button>
            <button class="trocas-ordem-btn" data-ordem="distancia"
                    onclick="toggleOrdem(this)">📍 Distância</button>
            <button class="trocas-ordem-btn" data-ordem="favoritos"
                    onclick="toggleOrdem(this)">⭐ Favoritos</button>
        </div>

        <!-- Lista de colecionadores -->
        <div id="lista-colecionadores" class="trocas-lista-coletores">
            <div class="trocas-lista-vazia">⏳ Carregando...</div>
        </div>

        <div id="btn-ver-todos-wrap" style="display:none;margin-top:.75rem">
            <a href="/trocas/externas" class="trocas-escolha-btn">
                Ver todos os parceiros →
            </a>
        </div>

        <a href="/trocas/externas" class="trocas-escolha-btn" style="margin-top:.5rem">
            Acessar Externas →
        </a>
    </div>

</div>

<style>
/* ── Album bar ────────────────────────────────────────────────── */
.trocas-album-bar {
    display: flex; align-items: center; gap: .75rem;
    background: #fff; border: 1px solid var(--borda);
    border-radius: var(--radius); padding: .6rem .85rem;
    margin-bottom: 1.25rem; flex-wrap: wrap;
}
.trocas-album-bar select {
    flex: 1; min-width: 180px;
    padding: .4rem .6rem; border-radius: 6px;
    border: 1px solid #ddd; font-size: .9rem;
    background: #fafafa;
}

/* ── Grid de escolha ──────────────────────────────────────────── */
.trocas-escolha-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}
.trocas-escolha-card {
    background: #fff; border: 1px solid var(--borda);
    border-radius: 12px; padding: 1.25rem;
    display: flex; flex-direction: column; gap: .85rem;
}
.trocas-escolha-titulo {
    display: flex; align-items: flex-start; gap: .75rem;
}
.trocas-escolha-icone { font-size: 2rem; flex-shrink: 0; }
.trocas-escolha-titulo h2 { font-size: 1rem; margin: 0 0 .2rem; color: var(--verde); }
.trocas-escolha-titulo p  { font-size: .82rem; color: #888; margin: 0; }

.trocas-escolha-btn {
    display: block; text-align: center;
    padding: .55rem 1rem; border-radius: 8px;
    background: var(--verde); color: #fff;
    font-size: .88rem; font-weight: 700;
    text-decoration: none; margin-top: auto;
    transition: opacity .2s;
}
.trocas-escolha-btn:hover { opacity: .88; }

/* ── Lista de álbuns internos ─────────────────────────────────── */
.trocas-lista-albuns {
    display: flex; flex-direction: column; gap: .4rem;
    max-height: 240px; overflow-y: auto;
}
.trocas-album-item {
    display: flex; flex-direction: column; gap: .2rem;
    padding: .5rem .65rem; border-radius: 8px;
    border: 1px solid #eee; background: #fafafa;
    text-decoration: none; color: var(--texto);
    transition: background .15s;
}
.trocas-album-item:hover { background: #f0f4ff; border-color: #c7d2fe; }
.trocas-album-item-info {
    display: flex; justify-content: space-between;
    align-items: center; gap: .5rem; flex-wrap: wrap;
}
.trocas-album-item-nome { font-weight: 600; font-size: .88rem; }
.trocas-album-item-meta { font-size: .75rem; color: #888; }
.trocas-album-item-barra {
    height: 4px; background: #e0e0e0; border-radius: 99px; overflow: hidden;
}
.trocas-album-item-barra div {
    height: 100%; background: var(--verde); border-radius: 99px;
}
.trocas-lista-vazia { font-size: .85rem; color: #aaa; text-align: center; padding: 1rem; }

/* ── Busca e filtros externos ─────────────────────────────────── */
.trocas-busca-wrap input {
    width: 100%; padding: .45rem .65rem;
    border: 1px solid #ddd; border-radius: 8px;
    font-size: .88rem; background: #fafafa;
    box-sizing: border-box;
}
.trocas-ordem-filtros {
    display: flex; gap: .4rem; flex-wrap: wrap;
}
.trocas-ordem-btn {
    padding: .25rem .65rem; border-radius: 20px;
    border: 1px solid #ddd; background: #f5f5f5;
    font-size: .8rem; cursor: pointer; font-weight: 600;
    transition: all .15s;
}
.trocas-ordem-btn.ativo {
    background: #6366f1; color: #fff; border-color: #6366f1;
}

/* ── Lista de colecionadores ──────────────────────────────────── */
.trocas-lista-coletores {
    display: flex; flex-direction: column; gap: .4rem;
    max-height: 280px; overflow-y: auto;
}
.coletor-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .5rem .65rem; border-radius: 8px;
    border: 1px solid #eee; background: #fafafa;
    text-decoration: none; color: var(--texto);
    transition: background .15s;
}
.coletor-item:hover { background: #f0f4ff; border-color: #c7d2fe; }
.coletor-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0; background: #e0e0e0;
}
.coletor-info { flex: 1; min-width: 0; }
.coletor-nome { font-weight: 600; font-size: .88rem; }
.coletor-meta { font-size: .75rem; color: #888; }
.coletor-badges { display: flex; gap: .3rem; align-items: center; flex-shrink: 0; }
.coletor-score {
    background: #f0fdf4; color: #16a34a;
    border: 1px solid #bbf7d0; border-radius: 12px;
    padding: .1rem .45rem; font-size: .72rem; font-weight: 700;
}
.coletor-fav-btn {
    background: none; border: none; font-size: 1.1rem;
    cursor: pointer; padding: 0; line-height: 1;
    opacity: .4; transition: opacity .15s;
}
.coletor-fav-btn.ativo { opacity: 1; }
.coletor-fav-btn:hover { opacity: .8; }

@media(max-width:600px) {
    .trocas-escolha-grid { grid-template-columns: 1fr; }
}
</style>

<script>
const ALBUM_ID_PADRAO = <?= json_encode($albumPadrao ? $albumPadrao['id'] : null) ?>;
let albumAtual  = ALBUM_ID_PADRAO;
let ordemAtiva  = new Set(['matches']); // critérios ativos
let buscaTimer  = null;

// ── Troca álbum e recarrega lista de colecionadores ──────────────────────
function trocarAlbum() {
    albumAtual = document.getElementById('select-album').value || ALBUM_ID_PADRAO;
    carregarColecionadores();
}

// ── Toggle de critério de ordenação ──────────────────────────────────────
function toggleOrdem(btn) {
    const criterio = btn.dataset.ordem;
    if (ordemAtiva.has(criterio)) {
        // Não permite desativar todos
        if (ordemAtiva.size > 1) {
            ordemAtiva.delete(criterio);
            btn.classList.remove('ativo');
        }
    } else {
        ordemAtiva.add(criterio);
        btn.classList.add('ativo');
    }
    carregarColecionadores();
}

// ── Busca com debounce ────────────────────────────────────────────────────
function buscarColecionadores() {
    clearTimeout(buscaTimer);
    buscaTimer = setTimeout(carregarColecionadores, 300);
}

// ── Carrega lista de colecionadores ──────────────────────────────────────
async function carregarColecionadores() {
    if (!albumAtual) return;

    const busca = document.getElementById('busca-colecionador').value.trim();
    const lista = document.getElementById('lista-colecionadores');
    lista.innerHTML = '<div class="trocas-lista-vazia">⏳ Carregando...</div>';

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao:     'colecionadores_listar',
            album_id: albumAtual,
            busca,
            ordem:    [...ordemAtiva].join(','),
            limite:   '10',
        }),
    });
    const data = await resp.json();

    if (!data.sucesso || data.colecionadores.length === 0) {
        lista.innerHTML = '<div class="trocas-lista-vazia">Nenhum colecionador encontrado.</div>';
        document.getElementById('btn-ver-todos-wrap').style.display = 'none';
        return;
    }

    lista.innerHTML = '';
    for (const c of data.colecionadores) {
        lista.appendChild(criarItemColetor(c));
    }

    // Mostra "Ver todos" se há mais
    document.getElementById('btn-ver-todos-wrap').style.display =
        data.tem_mais ? 'block' : 'none';
}

// ── Cria item de colecionador ─────────────────────────────────────────────
function criarItemColetor(c) {
    const avatar  = c.avatar_url
        ?? `https://ui-avatars.com/api/?name=${encodeURIComponent(c.nome)}&size=36`;
    const loc     = [c.cidade, c.estado].filter(Boolean).join(', ');
    const dist    = c.distancia_km ? `· ${Math.round(c.distancia_km)} km` : '';
    const matches = c.score_match > 0 ? `${c.score_match} match${c.score_match !== 1 ? 'es' : ''}` : '';

    const div = document.createElement('div');
    div.className = 'coletor-item';
    div.innerHTML = `
        <img class="coletor-avatar" src="${avatar}" alt="${c.nome}"
             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(c.nome)}&size=36'">
        <div class="coletor-info">
            <div class="coletor-nome">${c.nome}</div>
            <div class="coletor-meta">${loc} ${dist}</div>
        </div>
        <div class="coletor-badges">
            ${matches ? `<span class="coletor-score">${matches}</span>` : ''}
            <button class="coletor-fav-btn ${c.favorito ? 'ativo' : ''}"
                    data-id="${c.id}"
                    data-fav="${c.favorito ? '1' : '0'}"
                    onclick="toggleFavorito(this, event)">⭐</button>
        </div>`;

    // Clique no item → vai para a página do parceiro
    div.addEventListener('click', e => {
        if (e.target.closest('.coletor-fav-btn')) return;
        window.location.href = `/trocas/parceiro/${c.slug_publico}?album=${albumAtual}`;
    });

    return div;
}

// ── Favoritar / desfavoritar ──────────────────────────────────────────────
async function toggleFavorito(btn, e) {
    e.stopPropagation();
    const favoritoId = btn.dataset.id;

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ acao: 'favorito_toggle', favorito_id: favoritoId }),
    });
    const data = await resp.json();

    if (data.sucesso) {
        btn.dataset.fav = data.favoritado ? '1' : '0';
        btn.classList.toggle('ativo', data.favoritado);
    }
}

// ── Inicializa ao carregar ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (albumAtual) carregarColecionadores();
});
</script>

<?php layoutFim(); ?>
