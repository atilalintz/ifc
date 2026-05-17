<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();
$t       = TBL;

// Cria novo álbum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['nome'])) {
    $id   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $nome = trim($_POST['nome']);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome)) . '-' . substr($id, 0, 8);

    $db->prepare("
        INSERT INTO {$t}albuns (id, usuario_id, nome, slug_publico)
        VALUES (:id, :uid, :nome, :slug)
    ")->execute([':id' => $id, ':uid' => $usuario['id'], ':nome' => $nome, ':slug' => $slug]);

    // Herda repetidas de múltiplos álbuns?
	$albumOrigens = $_POST['album_origens'] ?? [];
	if (!empty($albumOrigens)) {
	    foreach ($albumOrigens as $albumOrigem) {
		// Verifica se pertence ao usuário
		$stmt = $db->prepare("
		    SELECT id FROM {$t}albuns WHERE id = :id AND usuario_id = :uid
		");
		$stmt->execute([':id' => $albumOrigem, ':uid' => $usuario['id']]);
		if (!$stmt->fetch()) continue;

		// Busca repetidas do álbum de origem
		$stmt = $db->prepare("
		    SELECT id, figurinha_id, quantidade
		    FROM {$t}inventario
		    WHERE album_id = :aid AND quantidade >= 2
		");
		$stmt->execute([':aid' => $albumOrigem]);
		$repetidas = $stmt->fetchAll();

		foreach ($repetidas as $rep) {
		    $qtdHerdar = $rep['quantidade'] - 1;

		    // Insere no novo álbum
		    $db->prepare("
		        INSERT INTO {$t}inventario (id, album_id, usuario_id, figurinha_id, quantidade)
		        VALUES (UUID(), :aid, :uid, :fid, :qtd)
		        ON DUPLICATE KEY UPDATE quantidade = quantidade + :qtd2
		    ")->execute([
		        ':aid'  => $id,
		        ':uid'  => $usuario['id'],
		        ':fid'  => $rep['figurinha_id'],
		        ':qtd'  => $qtdHerdar,
		        ':qtd2' => $qtdHerdar,
		    ]);

		    // Reduz no álbum de origem (deixa só 1)
		    $db->prepare("
		        UPDATE {$t}inventario SET quantidade = 1 WHERE id = :id
		    ")->execute([':id' => $rep['id']]);
		}

		recalcularAlbum($db, $t, $albumOrigem, $usuario['id']);
	    }

	    recalcularAlbum($db, $t, $id, $usuario['id']);
	}
	
    header('Location: /albuns');
    exit;
}

// Lista álbuns do usuário
$stmt = $db->prepare("
    SELECT id, nome, slug_publico, percentual_conclusao,
           total_faltantes, total_repetidas
    FROM {$t}albuns
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

        <?php if (!empty($albuns)): ?>
	<div class="form-herdar">
	    <label class="checkbox-label">
		<input type="checkbox" id="chk-herdar" onchange="toggleHerdar(this)">
		Herdar repetidas de outros álbuns
	    </label>
	    <div id="lista-origens" class="lista-origens" style="display:none;">
		<?php foreach ($albuns as $a): ?>
		    <?php if ($a['total_repetidas'] > 0): ?>
		        <label class="checkbox-label" style="margin-bottom:.4rem">
		            <input type="checkbox" name="album_origens[]" value="<?= $a['id'] ?>">
		            <?= htmlspecialchars($a['nome']) ?>
		            <span style="color:#888;font-size:.8rem">
		                (<?= $a['total_repetidas'] ?> repetidas)
		            </span>
		        </label>
		    <?php endif; ?>
		<?php endforeach; ?>
		<?php
		$temRepetidas = array_filter($albuns, fn($a) => $a['total_repetidas'] > 0);
		if (empty($temRepetidas)):
		?>
		    <p style="font-size:.85rem;color:#888;">Nenhum álbum com repetidas no momento.</p>
		<?php endif; ?>
	    </div>
	</div>
	<?php endif; ?>

        <button type="submit" class="btn btn-primary" style="margin-top:.75rem">
            Criar álbum
        </button>
    </form>
</div>

<script>
function toggleHerdar(chk) {
    document.getElementById('lista-origens').style.display = chk.checked ? 'block' : 'none';
}
</script>

<?php layoutFim(); ?>

<?php
function recalcularAlbum(PDO $db, string $t, string $albumId, string $usuarioId): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$t}figurinhas");
    $stmt->execute();
    $totalFigurinhas = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM {$t}inventario WHERE album_id = :aid AND quantidade > 0");
    $stmt->execute([':aid' => $albumId]);
    $totalTem = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(GREATEST(quantidade-1,0)),0) FROM {$t}inventario WHERE album_id = :aid");
    $stmt->execute([':aid' => $albumId]);
    $totalRep = (int) $stmt->fetchColumn();

    $pct   = $totalFigurinhas > 0 ? round(($totalTem / $totalFigurinhas) * 100, 2) : 0;
    $falt  = $totalFigurinhas - $totalTem;

    $db->prepare("
        UPDATE {$t}albuns SET
            percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $pct, ':falt' => $falt, ':rep' => $totalRep, ':id' => $albumId]);
}
?>
