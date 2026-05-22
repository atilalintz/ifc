<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

$partes  = explode('/', trim($_GET['route'] ?? '', '/'));
$albumId = $partes[1] ?? '';

if (!$albumId) { header('Location: /albuns'); exit; }

$stmt = $db->prepare("
    SELECT id, nome, percentual_conclusao, total_faltantes, total_repetidas
    FROM " . tbl('albuns') . " WHERE id = :id AND usuario_id = :uid AND ativo = 1
");
$stmt->execute([':id' => $albumId, ':uid' => $usuario['id']]);
$album = $stmt->fetch();
if (!$album) { header('Location: /albuns'); exit; }

$stmt = $db->prepare("
    SELECT
        g.codigo AS grupo_codigo,
        g.nome   AS grupo_nome,
        g.ordem  AS grupo_ordem,
        s.id     AS selecao_id,
        s.sigla  AS selecao_sigla,
        s.nome   AS selecao_nome,
        s.bandeira_url,
        f.id     AS figurinha_id,
        f.codigo AS figurinha_codigo,
        f.numero,
        f.tipo,
        COALESCE(i.quantidade, 0)          AS quantidade,
        COALESCE(i.quantidade_bloqueada, 0) AS reservada,
        COALESCE(i.status_troca, 'livre')   AS status_troca,
        i.valor_troca
    FROM " . tbl('figurinhas') . " f
    JOIN " . tbl('selecoes')   . " s ON s.id = f.selecao_id
    LEFT JOIN " . tbl('grupos'). " g ON g.id = s.grupo_id
    LEFT JOIN " . tbl('inventario') . " i
        ON i.figurinha_id = f.id AND i.album_id = :aid
    ORDER BY COALESCE(g.ordem, -1), s.sigla, f.numero
");
$stmt->execute([':aid' => $albumId]);
$rows = $stmt->fetchAll();

// Organiza estrutura hierárquica
$estrutura = [];
foreach ($rows as $row) {
    $gKey = $row['grupo_codigo'] ?? 'especial';
    $sKey = $row['selecao_sigla'];
    if (!isset($estrutura[$gKey])) {
        $estrutura[$gKey] = ['nome' => $row['grupo_nome'] ?? 'Especiais', 'selecoes' => []];
    }
    if (!isset($estrutura[$gKey]['selecoes'][$sKey])) {
        $estrutura[$gKey]['selecoes'][$sKey] = [
            'id'         => $row['selecao_id'],
            'sigla'      => $row['selecao_sigla'],
            'nome'       => $row['selecao_nome'],
            'bandeira'   => $row['bandeira_url'],
            'figurinhas' => [],
            'total'      => 0,
            'tenho'      => 0,
        ];
    }
    $estrutura[$gKey]['selecoes'][$sKey]['figurinhas'][] = [
        'id'          => $row['figurinha_id'],
        'codigo'      => $row['figurinha_codigo'],
        'numero'      => $row['numero'],
        'tipo'        => $row['tipo'],
        'qtd'         => (int) $row['quantidade'],
        'reservada'   => (int) $row['reservada'],
        'status_troca'=> $row['status_troca'],
        'valor_troca' => $row['valor_troca'],
    ];
    $estrutura[$gKey]['selecoes'][$sKey]['total']++;
    if ((int)$row['quantidade'] > 0) {
        $estrutura[$gKey]['selecoes'][$sKey]['tenho']++;
    }
}

layoutInicio('Inventário — ' . $album['nome']);
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">
            <?= htmlspecialchars($album['nome']) ?>
        </h1>
    </div>
    <div class="inventario-stats">
        <span><strong id="stat-pct"><?= number_format($album['percentual_conclusao'],1) ?>%</strong> completo</span>
        <span>Faltam: <strong id="stat-falt"><?= $album['total_faltantes'] ?></strong></span>
        <span>Repetidas: <strong id="stat-rep"><?= $album['total_repetidas'] ?></strong></span>
    </div>
</div>

<div class="acoes-barra">
    <button class="btn-sm btn-todas-inc" onclick="atualizarTodas('incrementar')">+1 em todas</button>
    <button class="btn-sm btn-todas-dec" onclick="atualizarTodas('decrementar')">−1 em todas</button>
    <div class="dropdown">
        <button class="btn-sm" onclick="toggleDropdown()">⬆⬇ Exp/Imp ▾</button>
        <div class="dropdown-menu" id="dropdown-menu">
            <a href="/api/csv?acao=exportar&album_id=<?= $albumId ?>" class="dropdown-item">
                ⬇ Exportar CSV
            </a>
            <label class="dropdown-item" style="cursor:pointer">
                ⬆ Importar CSV
                <input type="file" id="csv-input" accept=".csv" style="display:none"
                       onchange="importarCSV(this)">
            </label>
        </div>
    </div>
    <span id="csv-msg" style="font-size:.82rem;color:#666;"></span>
</div>

<!-- Filtros -->
<div class="filtros-sticky">

    <!-- Linha 1: busca -->
    <div class="filtros-barra">
        <input type="text" id="busca" placeholder="Buscar código ou país...">
    </div>

    <!-- Linha 2: selects de quantidade e status -->
    <div class="filtros-barra">
        <select id="filtro-qtd">
            <option value="">Todas qtd.</option>
            <option value="faltante">Faltantes (0)</option>
            <option value="tenho1">Tenho 1</option>
            <option value="tenho2">Tenho 2+</option>
        </select>
        <select id="filtro-troca">
            <option value="">Qualquer status</option>
            <option value="livre">🟢 Livre</option>
            <option value="troca">🔄 Troca</option>
            <option value="venda">💰 Venda</option>
            <option value="bloqueada">🔒 Bloqueada</option>
        </select>
    </div>

    <!-- Linha 3: pílula de visibilidade (cicla 3 estados) + grupos com wrap natural -->
    <div class="grupos-pilulas">
        <button class="pilula pilula-visib pilula-visib--incompletas"
                id="pilula-visib"
                data-estado="incompletas"
                onclick="ciclarVisib()">
            🔵 Incompletas
        </button>
        <?php foreach ($estrutura as $gKey => $grupo): ?>
            <button class="pilula" data-grupo="<?= htmlspecialchars($gKey) ?>"
                    onclick="filtrarGrupo(this)">
                <?= htmlspecialchars($gKey === 'especial' ? 'Esp.' : $gKey) ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Seleções -->
<div id="lista-selecoes">
<?php foreach ($estrutura as $grupoKey => $grupo): ?>
    <?php foreach ($grupo['selecoes'] as $sigla => $selecao):
        $pct = $selecao['total'] > 0 ? round(($selecao['tenho'] / $selecao['total']) * 100) : 0;
        $completa = $selecao['tenho'] === $selecao['total'];
    ?>
        <div class="selecao-row <?= $completa ? 'selecao-completa' : '' ?>"
             data-grupo="<?= htmlspecialchars($grupoKey) ?>"
             data-sigla="<?= htmlspecialchars($sigla) ?>"
             data-nome="<?= htmlspecialchars(mb_strtolower($selecao['nome'])) ?>"
             data-completa="<?= $completa ? '1' : '0' ?>">

            <div class="selecao-cabecalho" onclick="toggleSelecao(this)">
                <div class="selecao-info">
                    <img src="<?= htmlspecialchars($selecao['bandeira']) ?>"
                         alt="<?= htmlspecialchars($sigla) ?>"
                         class="bandeira"
                         onerror="this.style.display='none'">
                    <span class="selecao-sigla-badge"><?= htmlspecialchars($sigla) ?></span>
                    <span class="selecao-nome"><?= htmlspecialchars($selecao['nome']) ?></span>
                    <span class="selecao-grupo-badge">
                        <?= htmlspecialchars($grupoKey === 'especial' ? 'Esp.' : 'Grupo '.$grupoKey) ?>
                    </span>
                </div>
                <div class="selecao-progresso-wrap">
                    <span class="selecao-contagem" id="cont-<?= $selecao['id'] ?>">
                        <?= $selecao['tenho'] ?>/<?= $selecao['total'] ?>
                    </span>
                    <div class="selecao-barra">
                        <div class="selecao-barra-fill" id="barra-<?= $selecao['id'] ?>"
                             style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="selecao-seta">▼</span>
                </div>
            </div>

            <div class="selecao-figurinhas">
                <div class="figurinhas-grid">
                    <?php foreach ($selecao['figurinhas'] as $fig):
                        $statusEmoji = [
                            'livre'     => '🟢',
                            'troca'     => '🔄',
                            'venda'     => '💰',
                            'bloqueada' => '🔒',
                        ];
                        $emoji = $fig['qtd'] > 0
                            ? ($statusEmoji[$fig['status_troca']] ?? '🟢')
                            : '';
                    ?>
                        <div class="figurinha-card <?= $fig['qtd'] > 0 ? 'tem' : '' ?> <?= $fig['qtd'] > 1 ? 'repetida' : '' ?>"
                             id="fig-<?= $fig['id'] ?>"
                             data-codigo="<?= htmlspecialchars($fig['codigo']) ?>"
                             data-qtd="<?= $fig['qtd'] ?>"
                             data-status="<?= htmlspecialchars($fig['status_troca']) ?>"
                             data-selecao="<?= $selecao['id'] ?>"
                             data-total="<?= $selecao['total'] ?>"
                             style="position:relative">
                            <span class="fig-codigo"><?= htmlspecialchars($fig['codigo']) ?></span>
                            <div class="fig-controles">
                                <div class="fig-metade fig-metade-dec"
                                     onclick="event.stopPropagation(); atualizar('<?= $fig['id'] ?>', '<?= $albumId ?>', 'decrementar')">
                                    <span class="fig-sinal">−</span>
                                </div>
                                <div class="fig-qtd" id="qtd-<?= $fig['id'] ?>"><?= $fig['qtd'] ?></div>
                                <div class="fig-metade fig-metade-inc"
                                     onclick="event.stopPropagation(); atualizar('<?= $fig['id'] ?>', '<?= $albumId ?>', 'incrementar')">
                                    <span class="fig-sinal">+</span>
                                </div>
                            </div>
                            <?php if ($fig['qtd'] > 0): ?>
                                <div class="fig-status-badge" id="badge-<?= $fig['id'] ?>"
                                     data-status="<?= htmlspecialchars($fig['status_troca']) ?>">
                                    <?= $emoji ?>
                                </div>
                            <?php else: ?>
                                <div class="fig-status-badge" id="badge-<?= $fig['id'] ?>"
                                     data-status="livre" style="display:none"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
</div>

<!-- Modal de status de troca -->
<div id="modal-status" class="modal-overlay" style="display:none" onclick="fecharModalStatus(event)">
    <div class="modal-box">
        <div class="modal-titulo" id="modal-status-titulo">Figurinha</div>
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
            <button class="btn-sm btn-todas-inc" onclick="confirmarStatus()">✓ Confirmar</button>
            <button class="btn-sm" onclick="document.getElementById('modal-status').style.display='none'">Cancelar</button>
        </div>
    </div>
</div>

<style>
/* Badge de status — canto superior direito com fundo escuro */
.fig-status-badge {
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: .62rem;
    line-height: 1;
    background: rgba(0,0,0,.55);
    border-radius: 6px;
    padding: 1px 3px;
    pointer-events: none;
}

/* Pílula de visibilidade — 3 estados */
.pilula-visib {
    font-weight: 700;
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1d4ed8;
}
.pilula-visib.verde {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #16a34a;
}
.pilula-visib.cinza {
    background: #f5f5f5;
    border-color: #ddd;
    color: #666;
}

/* Modal */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000; padding: 1rem;
}
.modal-box {
    background: #fff; border-radius: 14px; padding: 1.5rem;
    width: 100%; max-width: 360px; box-shadow: 0 8px 32px rgba(0,0,0,.18);
}
.modal-titulo { font-weight: 700; font-size: 1rem; margin-bottom: 1rem; }
.modal-opcoes { display: flex; flex-direction: column; gap: .5rem; }
.modal-opcao {
    padding: .6rem .9rem; border-radius: 8px; border: 1px solid #e0e0e0;
    background: #fafafa; text-align: left; font-size: .9rem; cursor: pointer;
}
.modal-opcao:hover { background: #f0f0f0; }
.modal-rodape { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
</style>

<script>
const ALBUM_ID = '<?= $albumId ?>';

// ── Estado dos filtros ────────────────────────────────────────────────────────
const filtros = {
    busca: '',
    qtd:   '',   // '' | 'faltante' | 'tenho1' | 'tenho2'
    troca: '',   // '' | 'livre' | 'troca' | 'venda' | 'bloqueada'
    grupo: '',
    visib: 'incompletas', // 'incompletas' | 'completas' | 'todas'
};

// ── Toggle accordion (abre/fecha figurinhas da seleção) ──────────────────────
function toggleSelecao(cabecalho) {
    const row  = cabecalho.closest('.selecao-row');
    const figs = row.querySelector('.selecao-figurinhas');
    const seta = cabecalho.querySelector('.selecao-seta');
    const aberto = figs.classList.toggle('aberto');
    seta.style.transform = aberto ? 'rotate(180deg)' : '';
}

// ── Pílula de visibilidade — cicla entre 3 estados ───────────────────────────
const visibEstados = [
    { key: 'incompletas', label: '🔵 Incompletas', cls: ''      },
    { key: 'completas',   label: '🟢 Completas',   cls: 'verde' },
    { key: 'todas',       label: '⚪ Todas',        cls: 'cinza' },
];
let visibIdx = 0; // começa em incompletas

function ciclarVisib() {
    visibIdx = (visibIdx + 1) % visibEstados.length;
    const estado = visibEstados[visibIdx];
    filtros.visib = estado.key;

    const btn = document.getElementById('pilula-visib');
    btn.textContent = estado.label;
    btn.className   = 'pilula pilula-visib ' + estado.cls;

    aplicarFiltros();
}

// ── Filtro por grupo (pílulas) ────────────────────────────────────────────────
function filtrarGrupo(btn) {
    document.querySelectorAll('.pilula:not(.pilula-visib)').forEach(p => p.classList.remove('ativa'));
    btn.classList.add('ativa');
    filtros.grupo = btn.dataset.grupo;
    aplicarFiltros();
}

// ── Aplicar todos os filtros ──────────────────────────────────────────────────
function aplicarFiltros() {
    filtros.busca = document.getElementById('busca').value.toLowerCase().trim();
    filtros.qtd   = document.getElementById('filtro-qtd').value;
    filtros.troca = document.getElementById('filtro-troca').value;

    document.querySelectorAll('.selecao-row').forEach(row => {
        const rowGrupo   = row.dataset.grupo;
        const rowNome    = row.dataset.nome;
        const rowSigla   = row.dataset.sigla.toLowerCase();
        const rowCompleta = row.dataset.completa === '1';

        // Filtro grupo
        if (filtros.grupo && rowGrupo !== filtros.grupo) {
            row.style.display = 'none'; return;
        }

        // Filtro visibilidade (pílula de 3 estados)
        if (filtros.visib === 'incompletas' && rowCompleta) {
            row.style.display = 'none'; return;
        }
        if (filtros.visib === 'completas' && !rowCompleta) {
            row.style.display = 'none'; return;
        }
        // 'todas' → não filtra por completa/incompleta

        // Busca por seleção
        const buscaMatchSel = !filtros.busca
            || rowNome.includes(filtros.busca)
            || rowSigla.includes(filtros.busca);

        // Filtra cards individuais
        let algumVisivel = false;
        row.querySelectorAll('.figurinha-card').forEach(card => {
            const qtd    = parseInt(card.dataset.qtd);
            const status = card.dataset.status;
            const codigo = card.dataset.codigo.toLowerCase();

            const buscaOk = !filtros.busca
                || buscaMatchSel
                || codigo.includes(filtros.busca);

            const qtdOk = !filtros.qtd
                || (filtros.qtd === 'faltante' && qtd === 0)
                || (filtros.qtd === 'tenho1'   && qtd === 1)
                || (filtros.qtd === 'tenho2'   && qtd >= 2);

            const trocaOk = !filtros.troca
                || (qtd > 0 && status === filtros.troca);

            const visivel = buscaOk && qtdOk && trocaOk;
            card.style.display = visivel ? '' : 'none';
            if (visivel) algumVisivel = true;
        });

        row.style.display = algumVisivel ? '' : 'none';
    });
}

document.getElementById('busca').addEventListener('input', aplicarFiltros);
document.getElementById('filtro-qtd').addEventListener('change', aplicarFiltros);
document.getElementById('filtro-troca').addEventListener('change', aplicarFiltros);

// ── API inventário ────────────────────────────────────────────────────────────
async function atualizar(figurinhaId, albumId, acao) {
    const resp = await fetch('/api/inventario', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `figurinha_id=${figurinhaId}&album_id=${albumId}&acao=${acao}`
    });
    const data = await resp.json();

    const card  = document.getElementById(`fig-${figurinhaId}`);
    const badge = document.getElementById(`badge-${figurinhaId}`);

    card.dataset.qtd = data.quantidade;
    document.getElementById(`qtd-${figurinhaId}`).textContent = data.quantidade;
    card.classList.toggle('tem',      data.quantidade > 0);
    card.classList.toggle('repetida', data.quantidade > 1);

    // Mostra/oculta badge conforme quantidade
    if (data.quantidade > 0) {
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
        // Zera status quando vai a 0
        card.dataset.status  = 'livre';
        badge.dataset.status = 'livre';
    }

    // Atualiza barra e contagem da seleção
    const selecaoId = card.dataset.selecao;
    const totalSel  = parseInt(card.dataset.total);
    const tenhoSel  = [...document.querySelectorAll(`[data-selecao="${selecaoId}"]`)]
        .filter(c => parseInt(c.dataset.qtd) > 0).length;
    const pctSel    = totalSel > 0 ? (tenhoSel / totalSel * 100) : 0;

    document.getElementById(`cont-${selecaoId}`).textContent = `${tenhoSel}/${totalSel}`;
    document.getElementById(`barra-${selecaoId}`).style.width = pctSel + '%';

    // Marca seleção como completa ou não
    const row = card.closest('.selecao-row');
    const completa = tenhoSel === totalSel;
    row.dataset.completa = completa ? '1' : '0';
    row.classList.toggle('selecao-completa', completa);

    // Stats globais
    document.getElementById('stat-pct').textContent  = data.percentual.toFixed(1) + '%';
    document.getElementById('stat-falt').textContent = data.faltantes;
    document.getElementById('stat-rep').textContent  = data.repetidas;

    aplicarFiltros();
}

// ── +1 / -1 em todas ─────────────────────────────────────────────────────────
async function atualizarTodas(acao) {
    const cards = [...document.querySelectorAll('.figurinha-card')]
        .filter(c => c.style.display !== 'none');

    const alvo = acao === 'decrementar'
        ? cards.filter(c => parseInt(c.dataset.qtd) > 0)
        : cards;

    if (alvo.length === 0) return;

    const msg = acao === 'incrementar'
        ? `Adicionar +1 em ${alvo.length} figurinha(s)?`
        : `Remover -1 de ${alvo.length} figurinha(s) com quantidade > 0?`;

    if (!confirm(msg)) return;

    for (const card of alvo) {
        await atualizar(card.id.replace('fig-', ''), ALBUM_ID, acao);
    }
}

// ── Importar CSV ──────────────────────────────────────────────────────────────
async function importarCSV(input) {
    const arquivo = input.files[0];
    if (!arquivo) return;

    const msg = document.getElementById('csv-msg');
    msg.textContent = 'Importando...';

    const form = new FormData();
    form.append('arquivo', arquivo);

    const resp = await fetch(`/api/csv?acao=importar&album_id=${ALBUM_ID}`, {
        method: 'POST', body: form,
    });
    const data = await resp.json();

    if (data.sucesso) {
        msg.textContent = `✓ ${data.importados} figurinhas importadas!`;
        msg.style.color = 'green';
        document.getElementById('stat-pct').textContent  = data.percentual.toFixed(1) + '%';
        document.getElementById('stat-falt').textContent = data.faltantes;
        document.getElementById('stat-rep').textContent  = data.repetidas;
        setTimeout(() => location.reload(), 1500);
    } else {
        msg.textContent = 'Erro ao importar.';
        msg.style.color = 'red';
    }
    input.value = '';
}

// ── Dropdown Exp/Imp ──────────────────────────────────────────────────────────
function toggleDropdown() {
    document.getElementById('dropdown-menu').classList.toggle('aberto');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.dropdown'))
        document.getElementById('dropdown-menu').classList.remove('aberto');
});

// ════════════════════════════════════════════════════════════════
// MODAL DE STATUS DE TROCA
// ════════════════════════════════════════════════════════════════
let figSelecionada = null; // { id, codigo }
let statusPendente = null;

const statusEmoji = { livre:'🟢', troca:'🔄', venda:'💰', bloqueada:'🔒' };

function abrirModalStatus(figId, codigo, statusAtual) {
    figSelecionada = { id: figId, codigo };
    statusPendente = null;
    document.getElementById('modal-status-titulo').textContent = `Figurinha ${codigo}`;
    document.getElementById('campo-valor').style.display = statusAtual === 'venda' ? 'block' : 'none';
    document.getElementById('input-valor').value = '';
    document.getElementById('modal-status').style.display = 'flex';
}

function definirStatus(status) {
    statusPendente = status;
    document.getElementById('campo-valor').style.display = status === 'venda' ? 'block' : 'none';
}

async function confirmarStatus() {
    if (!statusPendente || !figSelecionada) return;

    const valor = document.getElementById('input-valor').value;

    const resp = await fetch('/api/trocas', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            acao:         'status_figurinha',
            album_id:     ALBUM_ID,
            figurinha_id: figSelecionada.id,
            status:       statusPendente,
            valor,
        }),
    });
    const data = await resp.json();

    if (data.sucesso) {
        const card  = document.getElementById(`fig-${figSelecionada.id}`);
        const badge = document.getElementById(`badge-${figSelecionada.id}`);
        card.dataset.status  = data.status;
        badge.dataset.status = data.status;
        badge.textContent    = statusEmoji[data.status];
        document.getElementById('modal-status').style.display = 'none';
        statusPendente = null;
        aplicarFiltros();
    } else {
        alert('Erro: ' + (data.erro ?? 'desconhecido'));
    }
}

function fecharModalStatus(e) {
    if (e.target.classList.contains('modal-overlay'))
        e.target.style.display = 'none';
}

// ── Long press / botão direito → abre modal de status ────────────────────────
document.querySelectorAll('.figurinha-card').forEach(card => {
    let pressTimer = null;
    let moveu      = false;
    let disparou   = false;
    let touchStartX = 0, touchStartY = 0, tempoInicio = 0;

    function abrirSeTemFigurinha() {
        const qtd = parseInt(card.dataset.qtd);
        if (qtd === 0) return; // sem figurinha, não abre
        abrirModalStatus(
            card.id.replace('fig-', ''),
            card.dataset.codigo,
            card.dataset.status
        );
        if (navigator.vibrate) navigator.vibrate(40);
    }

    card.addEventListener('touchstart', e => {
        if (e.target.closest('.fig-controles')) return;
        tempoInicio = Date.now();
        moveu = false; disparou = false;
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        pressTimer = setTimeout(() => {
            if (!moveu) { disparou = true; abrirSeTemFigurinha(); }
        }, 450);
    }, { passive: true });

    card.addEventListener('touchmove', e => {
        const dx = Math.abs(e.touches[0].clientX - touchStartX);
        const dy = Math.abs(e.touches[0].clientY - touchStartY);
        if (dx > 8 || dy > 8) { moveu = true; clearTimeout(pressTimer); }
    }, { passive: true });

    card.addEventListener('touchend', e => {
        clearTimeout(pressTimer);
        if (e.target.closest('.fig-controles') || moveu || disparou) return;

        // Toque curto → esquerda/direita = dec/inc
        if (Date.now() - tempoInicio < 350) {
            e.preventDefault();
            const rect  = card.getBoundingClientRect();
            const toqueX = e.changedTouches[0].clientX - rect.left;
            if (toqueX < rect.width / 2) {
                card.querySelector('.fig-metade-dec')?.click();
            } else {
                card.querySelector('.fig-metade-inc')?.click();
            }
        }
    }, { passive: false });

    card.addEventListener('contextmenu', e => {
        e.preventDefault();
        if (!e.target.closest('.fig-controles')) abrirSeTemFigurinha();
    });
});
</script>

<?php layoutFim(); ?>
