<?php
// api/auth.php — Processa login e registro por email/senha
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

function redir(string $path): never {
    header('Location: ' . APP_PATH . $path);
    exit;
}

$acao = $_GET['acao'] ?? '';

match ($acao) {
    'login'    => processarLogin(),
    'registro' => processarRegistro(),
    default    => redir('/auth/login'),
};

// ── Login por email/senha ─────────────────────────────────────────────────────
function processarLogin(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redir('/auth/login');

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!$email) redir('/auth/login?erro=email_vazio');
    if (!$senha) redir('/auth/login?erro=senha_vazia');

    $db = getDB();
    $t  = tbl('');

    $stmt = $db->prepare("
        SELECT id, nome, email, senha_hash, avatar_url
        FROM {$t}usuarios
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        redir('/auth/login?erro=credenciais&email=' . urlencode($email));
    }

    // Usuário Google sem senha cadastrada
    if (empty($usuario['senha_hash'])) {
        redir('/auth/login?erro=sem_senha&email=' . urlencode($email));
    }

    if (!password_verify($senha, $usuario['senha_hash'])) {
        redir('/auth/login?erro=credenciais&email=' . urlencode($email));
    }

    // Grava sessão e regenera ID — impede Session Fixation
    $_SESSION['ifc_usuario_id']    = $usuario['id'];
    $_SESSION['ifc_usuario_nome']  = $usuario['nome'];
    $_SESSION['ifc_usuario_email'] = $usuario['email'];
    $_SESSION['ifc_avatar']        = $usuario['avatar_url'] ?? '';
    regenerarSessao();

    redir('/albuns');
}

// ── Registro por email/senha ──────────────────────────────────────────────────
function processarRegistro(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redir('/auth/registro');

    $nome   = trim($_POST['nome']   ?? '');
    $email  = trim($_POST['email']  ?? '');
    $senha  = $_POST['senha']  ?? '';
    $senha2 = $_POST['senha2'] ?? '';

    if (!$nome)                                          redir('/auth/registro?erro=nome_vazio');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      redir('/auth/registro?erro=email_invalido&nome='  . urlencode($nome));
    if (strlen($senha) < 6)                              redir('/auth/registro?erro=senha_curta&nome='     . urlencode($nome) . '&email=' . urlencode($email));
    if ($senha !== $senha2)                              redir('/auth/registro?erro=senhas_diferentes&nome='. urlencode($nome) . '&email=' . urlencode($email));

    $db = getDB();
    $t  = tbl('');

    $stmt = $db->prepare("SELECT id FROM {$t}usuarios WHERE email = :email");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        redir('/auth/registro?erro=email_existe&email=' . urlencode($email));
    }

    $userId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome))
          . '-' . substr($userId, 0, 8);

    $db->prepare("
        INSERT INTO {$t}usuarios (id, nome, email, senha_hash, slug_publico)
        VALUES (:id, :nome, :email, :hash, :slug)
    ")->execute([
        ':id'   => $userId,
        ':nome' => $nome,
        ':email'=> $email,
        ':hash' => password_hash($senha, PASSWORD_DEFAULT),
        ':slug' => $slug,
    ]);

    $albumId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $db->prepare("
        INSERT INTO {$t}albuns (id, usuario_id, nome, slug_publico)
        VALUES (:id, :uid, 'Meu Álbum Copa 2026', 'album-copa-2026')
    ")->execute([':id' => $albumId, ':uid' => $userId]);

    // Grava sessão e regenera ID — novo usuário autenticado
    $_SESSION['ifc_usuario_id']    = $userId;
    $_SESSION['ifc_usuario_nome']  = $nome;
    $_SESSION['ifc_usuario_email'] = $email;
    $_SESSION['ifc_avatar']        = '';
    regenerarSessao();

    redir('/perfil?novo=1');
}
