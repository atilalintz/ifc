<?php
// auth/callback.php — Callback do Google OAuth
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

function redirecionar(string $path): void {
    header('Location: ' . APP_PATH . $path);
    exit;
}

// Verifica state CSRF
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    die('Erro de segurança. Tente novamente.');
}
unset($_SESSION['oauth_state']);

// Verifica código
if (empty($_GET['code'])) {
    redirecionar('/auth/login');
}

// ── Troca code por token ──────────────────────
$response = file_get_contents(GOOGLE_TOKEN_URL, false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $_GET['code'],
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
    ],
]));

$token = json_decode($response, true);

if (empty($token['access_token'])) {
    error_log('OAuth erro token: ' . $response);
    redirecionar('/auth/login');
}

// ── Busca dados do usuário no Google ─────────
$userInfo = file_get_contents(GOOGLE_USERINFO_URL, false, stream_context_create([
    'http' => [
        'header' => 'Authorization: Bearer ' . $token['access_token'],
    ],
]));

$google = json_decode($userInfo, true);

if (empty($google['email'])) {
    redirecionar('/auth/login');
}

// ── Cria ou atualiza usuário no banco ────────
$db = getDB();
$t  = 'ifc_'; // prefixo das tabelas

// Busca por google_id ou email
$stmt = $db->prepare("
    SELECT id, nome, email FROM {$t}usuarios
    WHERE google_id = :gid OR email = :email
    LIMIT 1
");
$stmt->execute([':gid' => $google['sub'], ':email' => $google['email']]);
$usuario = $stmt->fetch();

if ($usuario) {
    // Atualiza dados do Google
    $db->prepare("
        UPDATE {$t}usuarios SET
            google_id  = :gid,
            nome       = :nome,
            avatar_url = :avatar,
            atualizado_em = NOW()
        WHERE id = :id
    ")->execute([
        ':gid'    => $google['sub'],
        ':nome'   => $google['name'],
        ':avatar' => $google['picture'] ?? '',
        ':id'     => $usuario['id'],
    ]);
    $userId = $usuario['id'];
} else {
    // Cria novo usuário
    $userId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $google['name']))
          . '-' . substr($userId, 0, 8);

    $db->prepare("
        INSERT INTO {$t}usuarios (id, nome, email, google_id, avatar_url, slug_publico)
        VALUES (:id, :nome, :email, :gid, :avatar, :slug)
    ")->execute([
        ':id'     => $userId,
        ':nome'   => $google['name'],
        ':email'  => $google['email'],
        ':gid'    => $google['sub'],
        ':avatar' => $google['picture'] ?? '',
        ':slug'   => $slug,
    ]);

    // Cria álbum inicial
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
}

// ── Inicia sessão ────────────────────────────
$_SESSION['ifc_usuario_id']    = $userId;
$_SESSION['ifc_usuario_nome']  = $google['name'];
$_SESSION['ifc_usuario_email'] = $google['email'];
$_SESSION['ifc_avatar']        = $google['picture'] ?? '';

redirecionar('/albuns');
