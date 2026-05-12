<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

// Cria novo álbum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['nome'])) {
    $id   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $nome = trim($_POST['nome']);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome)) . '-' . substr($id, 0, 8);

    $stmt = $db->prepare("
        INSERT INTO albuns (id, usuario_id, nome, slug_publico)
        VALUES (:id, :uid, :nome, :slug)
    ");
    $stmt->execute([
        ':id'   => $id,
        ':uid'  => $usuario['id'],
        ':nome' => $nome,
        ':slug' => $slug,
    ]);

    header('Location: /albuns');
    exit;
}

// Lista álbuns do usuário
$stmt = $db->prepare("
    SELECT id, nome, slug_publico, percentual_conclusao,
           total_faltantes, total_repetidas
    FROM albuns
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY criado_em DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Meus Álbuns');
?>

<h1 class="page-title">Meus Álbuns</h1>

<?php if (empty($albuns)): ?>
    <p style="margin-bottom:1.5rem;color:#666;">Você ainda não tem álbuns. Crie um abaixo!</p>
<?php else: ?>
    <div class="albuns-grid">
        <?php foreach ($albuns as $album): ?>
            <a href="/inventario/<?= $album['id'] ?>" class="card-album">
                <h3><?= htmlspecialchars($album['nome']) ?></h3>
                <div class="progresso-bar">
                    <span style="width:<?= $album['percentual_conclusao'] ?>%"></span>
                </div>
                <p class="meta">
                    <?= number_format($album['percentual_conclusao'], 1) ?>% completo<br>
                    Faltam: <?= $album['total_faltantes'] ?> •
                    Repetidas: <?= $album['total_repetidas'] ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="form-novo-album">
    <h3>Novo Álbum</h3>
    <form method="POST" action="/albuns">
        <input type="text" name="nome" placeholder="Nome do álbum" required maxlength="120">
        <button type="submit" class="btn btn-primary">Criar álbum</button>
    </form>
</div>

<?php layoutFim(); ?>
