<?php
// pages/perfil.php — Perfil do usuário: dados pessoais e contato para trocas
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Recarrega dados frescos do banco (sessão pode estar desatualizada)
$stmt = $db->prepare("
    SELECT nome, email, cidade, estado, avatar_url,
           contato_tipo, contato_valor, slug_publico
    FROM " . tbl('usuarios') . " WHERE id = :id
");
$stmt->execute([':id' => $usuario['id']]);
$perfil = $stmt->fetch();

$sucesso = $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contatoTipo  = $_POST['contato_tipo']  ?? '';
    $contatoValor = trim($_POST['contato_valor'] ?? '');
    $cidade       = trim($_POST['cidade'] ?? '');
    $estado       = trim($_POST['estado'] ?? '');

    $tiposValidos = ['', 'whatsapp', 'telegram', 'email'];

    if (!in_array($contatoTipo, $tiposValidos)) {
        $erro = 'Tipo de contato inválido.';
    } elseif ($contatoTipo && !$contatoValor) {
        $erro = 'Informe o valor do contato.';
    } else {
        $db->prepare("
            UPDATE " . tbl('usuarios') . "
            SET contato_tipo  = :tipo,
                contato_valor = :valor,
                cidade        = :cidade,
                estado        = :estado
            WHERE id = :id
        ")->execute([
            ':tipo'   => $contatoTipo  ?: null,
            ':valor'  => $contatoValor ?: null,
            ':cidade' => $cidade       ?: null,
            ':estado' => $estado       ?: null,
            ':id'     => $usuario['id'],
        ]);

        // Atualiza dados locais para exibir no form
        $perfil['contato_tipo']  = $contatoTipo;
        $perfil['contato_valor'] = $contatoValor;
        $perfil['cidade']        = $cidade;
        $perfil['estado']        = $estado;
        $sucesso = 'Perfil atualizado com sucesso!';
    }
}

layoutInicio('Meu Perfil');
?>

<div class="inventario-header">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title" style="margin-bottom:.25rem">Meu Perfil</h1>
    </div>
</div>

<div class="perfil-container">

    <!-- Dados da conta (somente leitura) -->
    <div class="perfil-secao">
        <h2 class="perfil-secao-titulo">👤 Conta</h2>
        <div class="perfil-conta">
            <?php if ($perfil['avatar_url']): ?>
                <img src="<?= htmlspecialchars($perfil['avatar_url']) ?>"
                     alt="avatar" class="perfil-avatar">
            <?php endif; ?>
            <div>
                <div class="perfil-nome"><?= htmlspecialchars($perfil['nome']) ?></div>
                <div class="perfil-email"><?= htmlspecialchars($perfil['email']) ?></div>
                <div class="perfil-slug">@<?= htmlspecialchars($perfil['slug_publico']) ?></div>
            </div>
        </div>
    </div>

    <!-- Formulário editável -->
    <div class="perfil-secao">
        <h2 class="perfil-secao-titulo">✏️ Editar Perfil</h2>

        <?php if ($sucesso): ?>
            <div class="alerta alerta-ok">✅ <?= htmlspecialchars($sucesso) ?></div>
        <?php elseif ($erro): ?>
            <div class="alerta alerta-erro">⚠️ <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="POST" class="perfil-form">

            <div class="form-grupo">
                <label>Cidade</label>
                <input type="text" name="cidade" maxlength="120"
                       value="<?= htmlspecialchars($perfil['cidade'] ?? '') ?>"
                       placeholder="Ex: Campinas">
            </div>

            <div class="form-grupo">
                <label>Estado</label>
                <input type="text" name="estado" maxlength="120"
                       value="<?= htmlspecialchars($perfil['estado'] ?? '') ?>"
                       placeholder="Ex: SP">
            </div>

            <fieldset class="perfil-fieldset">
                <legend>📲 Contato para Trocas</legend>
                <p class="perfil-hint">
                    Exibido para outros usuários quando quiserem negociar com você.
                    Deixe em branco para não divulgar.
                </p>

                <div class="form-grupo">
                    <label>Tipo</label>
                    <select name="contato_tipo" id="sel-tipo" onchange="alternarCampoContato()">
                        <option value="" <?= !$perfil['contato_tipo'] ? 'selected' : '' ?>>Não informar</option>
                        <option value="whatsapp" <?= $perfil['contato_tipo'] === 'whatsapp' ? 'selected' : '' ?>>💬 WhatsApp</option>
                        <option value="telegram" <?= $perfil['contato_tipo'] === 'telegram' ? 'selected' : '' ?>>✈️ Telegram</option>
                        <option value="email"    <?= $perfil['contato_tipo'] === 'email'    ? 'selected' : '' ?>>📧 E-mail</option>
                    </select>
                </div>

                <div class="form-grupo" id="grupo-valor"
                     style="<?= !$perfil['contato_tipo'] ? 'display:none' : '' ?>">
                    <label id="label-valor">Valor</label>
                    <input type="text" name="contato_valor" id="input-contato-valor"
                           maxlength="120"
                           value="<?= htmlspecialchars($perfil['contato_valor'] ?? '') ?>"
                           placeholder="">
                </div>
            </fieldset>

            <button type="submit" class="btn-sm btn-todas-inc" style="margin-top:1rem">
                💾 Salvar alterações
            </button>
        </form>
    </div>
</div>

<style>
.perfil-container { max-width: 520px; margin: 0 auto; }
.perfil-secao {
    background: var(--bg-card,#fff);
    border: 1px solid var(--border,#e0e0e0);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.perfil-secao-titulo { margin: 0 0 .9rem; font-size: 1rem; }
.perfil-conta { display: flex; align-items: center; gap: 1rem; }
.perfil-avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
.perfil-nome   { font-weight: 700; font-size: 1rem; }
.perfil-email  { font-size: .85rem; color: #666; }
.perfil-slug   { font-size: .8rem; color: #aaa; }

.perfil-form { display: flex; flex-direction: column; gap: .9rem; }
.form-grupo { display: flex; flex-direction: column; gap: .3rem; }
.form-grupo label { font-size: .85rem; font-weight: 600; }
.form-grupo input,
.form-grupo select {
    padding: .45rem .6rem;
    border: 1px solid var(--border,#ddd);
    border-radius: 8px;
    font-size: .9rem;
    background: var(--bg,#fafafa);
}

.perfil-fieldset {
    border: 1px solid var(--border,#e0e0e0);
    border-radius: 8px;
    padding: .9rem 1rem;
    display: flex; flex-direction: column; gap: .75rem;
}
.perfil-fieldset legend { font-weight: 700; font-size: .9rem; padding: 0 .3rem; }
.perfil-hint { margin: 0; font-size: .8rem; color: #888; }

.alerta { padding: .6rem .9rem; border-radius: 8px; margin-bottom: .75rem; font-size: .88rem; }
.alerta-ok  { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.alerta-erro { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
</style>

<script>
const placeholders = {
    whatsapp: 'Ex: 5519999999999 (com DDI)',
    telegram: 'Ex: @seunick',
    email:    'Ex: voce@email.com',
};

function alternarCampoContato() {
    const tipo   = document.getElementById('sel-tipo').value;
    const grupo  = document.getElementById('grupo-valor');
    const label  = document.getElementById('label-valor');
    const input  = document.getElementById('input-contato-valor');

    if (tipo) {
        grupo.style.display = '';
        label.textContent   = tipo.charAt(0).toUpperCase() + tipo.slice(1);
        input.placeholder   = placeholders[tipo] ?? '';
    } else {
        grupo.style.display = 'none';
        input.value = '';
    }
}

// Inicializa placeholder correto no carregamento
alternarCampoContato();
</script>

<?php layoutFim(); ?>
