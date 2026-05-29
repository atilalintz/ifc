<?php
// api/trocas.php — Transferência e trocas entre usuários
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json');
requireLogin();
validarCsrf();

$usuario = usuarioLogado();
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

$acao = $_POST['acao'] ?? '';

// ── Roteador de ações ────────────────────────────────────────────────────────
match ($acao) {
    'transferir'       => transferir($db, $usuario),
    'figurinhas_album' => figurinhasAlbum($db, $usuario),
    'match_recalcular' => matchRecalcular($db, $usuario),
    'match_listar'         => matchListar($db, $usuario),
    'status_figurinha'     => statusFigurinha($db, $usuario),
    'minhas_repetidas'     => minhasRepetidas($db, $usuario),
    'parceiro_figurinhas'  => parceirFigurinhas($db, $usuario),
    'favorito_toggle'      => favoritoToggle($db, $usuario),
    'colecionadores_listar'=> colecionadoresListar($db, $usuario),
    default                => responderErro(400, 'Ação inválida'),
};

// ── Retorna figurinhas de um álbum para popular o grid ───────────────────────
// Usada pelo JS ao trocar o álbum selecionado no <select>
function figurinhasAlbum(PDO $db, array $usuario): void
{
    $albumId = $_POST['album_id'] ?? '';
    $modo    = $_POST['modo']     ?? 'origem'; // 'origem' = repetidas | 'destino' = faltantes

    if (!$albumId) responderErro(400, 'album_id obrigatório');

    // Garante que o álbum pertence ao usuário
    verificarDono($db, $albumId, $usuario['id']);

    if ($modo === 'origem') {
        // Figurinhas com quantidade > 1 (tem repetida para transferir)
        $stmt = $db->prepare("
            SELECT
                f.id         AS figurinha_id,
                f.codigo,
                f.numero,
                f.tipo,
                s.sigla      AS selecao_sigla,
                s.nome       AS selecao_nome,
                s.bandeira_url,
                g.codigo     AS grupo_codigo,
                i.quantidade
            FROM " . tbl('inventario') . " i
            JOIN " . tbl('figurinhas') . " f ON f.id = i.figurinha_id
            JOIN " . tbl('selecoes')   . " s ON s.id = f.selecao_id
            LEFT JOIN " . tbl('grupos'). " g ON g.id = s.grupo_id
            WHERE i.album_id  = :aid
              AND i.usuario_id = :uid
              AND i.quantidade > 1
            ORDER BY g.ordem, s.sigla, f.numero
        ");
    } else {
        // Figurinhas com quantidade = 0 no destino (faltando)
        $stmt = $db->prepare("
            SELECT
                f.id         AS figurinha_id,
                f.codigo,
                f.numero,
                f.tipo,
                s.sigla      AS selecao_sigla,
                s.nome       AS selecao_nome,
                s.bandeira_url,
                g.codigo     AS grupo_codigo,
                0            AS quantidade
            FROM " . tbl('figurinhas') . " f
            JOIN " . tbl('selecoes')   . " s ON s.id = f.selecao_id
            LEFT JOIN " . tbl('grupos'). " g ON g.id = s.grupo_id
            LEFT JOIN " . tbl('inventario') . " i
                ON i.figurinha_id = f.id AND i.album_id = :aid
            WHERE COALESCE(i.quantidade, 0) = 0
            ORDER BY g.ordem, s.sigla, f.numero
        ");
    }

    if ($modo === 'origem') {
        $stmt->execute([':aid' => $albumId, ':uid' => $usuario['id']]);
    } else {
        $stmt->execute([':aid' => $albumId]);
    }
    echo json_encode(['sucesso' => true, 'figurinhas' => $stmt->fetchAll()]);
    exit;
}

// ── Transfere figurinhas selecionadas de origem → destino ───────────────────
function transferir(PDO $db, array $usuario): void
{
    $origemId  = $_POST['album_origem_id']  ?? '';
    $destinoId = $_POST['album_destino_id'] ?? '';
    $ids       = $_POST['figurinha_ids']    ?? ''; // JSON string: ["uuid1","uuid2"]

    if (!$origemId || !$destinoId || !$ids) {
        responderErro(400, 'Parâmetros incompletos');
    }

    if ($origemId === $destinoId) {
        responderErro(400, 'Origem e destino não podem ser o mesmo álbum');
    }

    $figurinhaIds = json_decode($ids, true);
    if (!is_array($figurinhaIds) || empty($figurinhaIds)) {
        responderErro(400, 'Nenhuma figurinha selecionada');
    }

    // Garante que ambos os álbuns pertencem ao usuário
    verificarDono($db, $origemId,  $usuario['id']);
    verificarDono($db, $destinoId, $usuario['id']);

    $db->beginTransaction();

    try {
        $transferidas = 0;
        $ignoradas    = 0; // destino já tem a figurinha

        foreach ($figurinhaIds as $figId) {
            $figId = (string) $figId;

            // Busca quantidade atual na origem
            $stmt = $db->prepare("
                SELECT id, quantidade FROM " . tbl('inventario') . "
                WHERE album_id = :aid AND figurinha_id = :fid AND usuario_id = :uid
            ");
            $stmt->execute([':aid' => $origemId, ':fid' => $figId, ':uid' => $usuario['id']]);
            $origem = $stmt->fetch();

            // Pula se não existe ou já ficou sem repetida (corrida entre requests)
            if (!$origem || (int)$origem['quantidade'] <= 1) {
                $ignoradas++;
                continue;
            }

            // Busca registro no destino
            $stmt = $db->prepare("
                SELECT id, quantidade FROM " . tbl('inventario') . "
                WHERE album_id = :aid AND figurinha_id = :fid
            ");
            $stmt->execute([':aid' => $destinoId, ':fid' => $figId]);
            $destino = $stmt->fetch();

            // Desconta 1 da origem
            $db->prepare("
                UPDATE " . tbl('inventario') . "
                SET quantidade = quantidade - 1
                WHERE id = :id
            ")->execute([':id' => $origem['id']]);

            if ($destino) {
                // Destino já existe: incrementa
                $db->prepare("
                    UPDATE " . tbl('inventario') . "
                    SET quantidade = quantidade + 1
                    WHERE id = :id
                ")->execute([':id' => $destino['id']]);
            } else {
                // Destino não existe: cria com quantidade 1
                $db->prepare("
                    INSERT INTO " . tbl('inventario') . "
                        (id, album_id, usuario_id, figurinha_id, quantidade, quantidade_bloqueada)
                    VALUES (UUID(), :aid, :uid, :fid, 1, 0)
                ")->execute([':aid' => $destinoId, ':uid' => $usuario['id'], ':fid' => $figId]);
            }

            $transferidas++;
        }

        // Recalcula estatísticas dos dois álbuns
        recalcularAlbum($db, $origemId);
        recalcularAlbum($db, $destinoId);

        $db->commit();

        echo json_encode([
            'sucesso'      => true,
            'transferidas' => $transferidas,
            'ignoradas'    => $ignoradas,
        ]);

    } catch (Throwable $e) {
        $db->rollBack();
        responderErro(500, 'Erro interno: ' . $e->getMessage());
    }

    exit;
}

// ── Minhas repetidas disponíveis para troca ──────────────────────────────────
// Retorna figurinhas com quantidade > 1 do álbum escolhido,
// com status_troca e valor_troca para exibição na seção 1
function minhasRepetidas(PDO $db, array $usuario): void
{
    $albumId = $_POST['album_id'] ?? '';
    if (!$albumId) responderErro(400, 'album_id obrigatório');
    verificarDono($db, $albumId, $usuario['id']);

    $stmt = $db->prepare("
        SELECT
            f.id            AS figurinha_id,
            f.codigo,
            f.numero,
            f.tipo,
            s.sigla         AS selecao_sigla,
            s.nome          AS selecao_nome,
            s.bandeira_url,
            g.codigo        AS grupo_codigo,
            i.quantidade,
            i.status_troca,
            i.valor_troca,
            i.quantidade_bloqueada
        FROM " . tbl('inventario') . " i
        JOIN " . tbl('figurinhas') . " f ON f.id = i.figurinha_id
        JOIN " . tbl('selecoes')   . " s ON s.id = f.selecao_id
        LEFT JOIN " . tbl('grupos'). " g ON g.id = s.grupo_id
        WHERE i.album_id   = :aid
          AND i.usuario_id = :uid
          AND i.quantidade > 1
        ORDER BY g.ordem, s.sigla, f.numero
    ");
    $stmt->execute([':aid' => $albumId, ':uid' => $usuario['id']]);

    echo json_encode(['sucesso' => true, 'figurinhas' => $stmt->fetchAll()]);
    exit;
}

// ── Altera status_troca de uma figurinha ─────────────────────────────────────
// status: livre | troca | venda | bloqueada
// valor_troca: decimal ou null (só usado quando status = venda)
function statusFigurinha(PDO $db, array $usuario): void
{
    $albumId     = $_POST['album_id']     ?? '';
    $figurinhaId = $_POST['figurinha_id'] ?? '';
    $status      = $_POST['status']       ?? '';
    $valor       = $_POST['valor']        ?? null;

    $statusValidos = ['livre', 'troca', 'venda', 'bloqueada'];
    if (!$albumId || !$figurinhaId || !in_array($status, $statusValidos)) {
        responderErro(400, 'Parâmetros inválidos');
    }

    verificarDono($db, $albumId, $usuario['id']);

    // valor só faz sentido para venda; nos demais, limpa
    $valorFinal = ($status === 'venda' && is_numeric($valor)) ? (float)$valor : null;

    $stmt = $db->prepare("
        UPDATE " . tbl('inventario') . "
        SET status_troca = :status,
            valor_troca  = :valor
        WHERE album_id    = :aid
          AND figurinha_id = :fid
          AND usuario_id   = :uid
    ");
    $stmt->execute([
        ':status' => $status,
        ':valor'  => $valorFinal,
        ':aid'    => $albumId,
        ':fid'    => $figurinhaId,
        ':uid'    => $usuario['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        responderErro(404, 'Figurinha não encontrada no inventário');
    }

    echo json_encode(['sucesso' => true, 'status' => $status, 'valor' => $valorFinal]);
    exit;
}

// ── Recalcula ifc_matches_troca para o usuário logado ────────────────────────
// Lógica:
//   Para cada outro usuário com álbum ativo:
//     quantidade_match = figurinhas que EU tenho repetidas E o outro não tem
//                      + figurinhas que o outro tem repetidas E eu não tenho
//     score_match      = quantidade_match (pode evoluir com distância depois)
//   Apaga registros antigos do usuário e reinsere os novos
function matchRecalcular(PDO $db, array $usuario): void
{
    $albumId = $_POST['album_id'] ?? '';
    if (!$albumId) responderErro(400, 'album_id obrigatório');
    verificarDono($db, $albumId, $usuario['id']);

    // Busca outros usuários que têm pelo menos um álbum ativo com figurinhas
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.nome,
            ST_Distance_Sphere(
                POINT(:lng, :lat),
                POINT(u.longitude, u.latitude)
            ) / 1000 AS distancia_km
        FROM " . tbl('usuarios') . " u
        JOIN " . tbl('albuns')   . " a ON a.usuario_id = u.id AND a.ativo = 1
        JOIN " . tbl('inventario'). " i ON i.album_id = a.id
        WHERE u.id != :uid
        GROUP BY u.id
    ");
    $stmt->execute([
        ':uid' => $usuario['id'],
        ':lat' => $usuario['latitude']  ?? 0,
        ':lng' => $usuario['longitude'] ?? 0,
    ]);
    $outrosUsuarios = $stmt->fetchAll();

    if (empty($outrosUsuarios)) {
        // Limpa matches antigos e encerra
        $db->prepare("DELETE FROM " . tbl('matches_troca') . " WHERE usuario_origem_id = :uid")
           ->execute([':uid' => $usuario['id']]);
        echo json_encode(['sucesso' => true, 'matches' => 0]);
        exit;
    }

    // Minhas repetidas (quantidade > 1) no álbum escolhido
    $stmt = $db->prepare("
        SELECT figurinha_id FROM " . tbl('inventario') . "
        WHERE album_id = :aid AND usuario_id = :uid AND quantidade > 1
    ");
    $stmt->execute([':aid' => $albumId, ':uid' => $usuario['id']]);
    $minhasRepetidas = array_column($stmt->fetchAll(), 'figurinha_id');

    // Minhas faltantes (quantidade = 0) no álbum escolhido
    $stmt = $db->prepare("
        SELECT f.id FROM " . tbl('figurinhas') . " f
        LEFT JOIN " . tbl('inventario') . " i
            ON i.figurinha_id = f.id AND i.album_id = :aid
        WHERE COALESCE(i.quantidade, 0) = 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $minhasFaltantes = array_column($stmt->fetchAll(), 'id');

    // Apaga matches antigos deste usuário
    $db->prepare("DELETE FROM " . tbl('matches_troca') . " WHERE usuario_origem_id = :uid")
       ->execute([':uid' => $usuario['id']]);

    $totalMatches = 0;

    foreach ($outrosUsuarios as $outro) {
        // Melhor álbum do outro (mais completo)
        $stmt = $db->prepare("
            SELECT id FROM " . tbl('albuns') . "
            WHERE usuario_id = :uid AND ativo = 1
            ORDER BY percentual_conclusao DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $outro['id']]);
        $albumOutro = $stmt->fetchColumn();
        if (!$albumOutro) continue;

        // Repetidas do outro
        $stmt = $db->prepare("
            SELECT figurinha_id FROM " . tbl('inventario') . "
            WHERE album_id = :aid AND usuario_id = :uid AND quantidade > 1
        ");
        $stmt->execute([':aid' => $albumOutro, ':uid' => $outro['id']]);
        $repetidrasOutro = array_column($stmt->fetchAll(), 'figurinha_id');

        // Faltantes do outro
        $stmt = $db->prepare("
            SELECT f.id FROM " . tbl('figurinhas') . " f
            LEFT JOIN " . tbl('inventario') . " i
                ON i.figurinha_id = f.id AND i.album_id = :aid
            WHERE COALESCE(i.quantidade, 0) = 0
        ");
        $stmt->execute([':aid' => $albumOutro]);
        $faltantesOutro = array_column($stmt->fetchAll(), 'id');

        // Eu tenho repetida que ele precisa
        $euTenhoEleNao = count(array_intersect($minhasRepetidas, $faltantesOutro));

        // Ele tem repetida que eu preciso
        $eleTenhoEuNao = count(array_intersect($repetidrasOutro, $minhasFaltantes));

        $qtdMatch = $euTenhoEleNao + $eleTenhoEuNao;
        if ($qtdMatch === 0) continue;

        // Score simples: quantidade de matches (pode ponderar distância futuramente)
        $score = $qtdMatch;

        $db->prepare("
            INSERT INTO " . tbl('matches_troca') . "
                (id, usuario_origem_id, usuario_destino_id, quantidade_match, distancia_km, score_match)
            VALUES (UUID(), :orig, :dest, :qtd, :dist, :score)
        ")->execute([
            ':orig'  => $usuario['id'],
            ':dest'  => $outro['id'],
            ':qtd'   => $qtdMatch,
            ':dist'  => $outro['distancia_km'] ?? null,
            ':score' => $score,
        ]);

        $totalMatches++;
    }

    echo json_encode(['sucesso' => true, 'matches' => $totalMatches]);
    exit;
}

// ── Lista matches calculados com detalhes dos usuários ───────────────────────
function matchListar(PDO $db, array $usuario): void
{
    $albumId = $_POST['album_id'] ?? '';
    if (!$albumId) responderErro(400, 'album_id obrigatório');

    $stmt = $db->prepare("
        SELECT
            m.id              AS match_id,
            m.quantidade_match,
            m.distancia_km,
            m.score_match,
            u.id              AS usuario_id,
            u.nome,
            u.avatar_url,
            u.cidade,
            u.estado,
            u.contato_tipo,
            u.contato_valor,
            u.slug_publico
        FROM " . tbl('matches_troca') . " m
        JOIN " . tbl('usuarios')      . " u ON u.id = m.usuario_destino_id
        WHERE m.usuario_origem_id = :uid
        ORDER BY m.score_match DESC, m.distancia_km ASC
        LIMIT 50
    ");
    $stmt->execute([':uid' => $usuario['id']]);
    $matches = $stmt->fetchAll();

    // Para cada match, busca quais figurinhas são o "cruzamento"
    // (eu tenho repetida que ele precisa) para exibir no card
    $minhasRepetidas = [];
    if (!empty($matches)) {
        $stmt = $db->prepare("
            SELECT figurinha_id, f.codigo
            FROM " . tbl('inventario') . " i
            JOIN " . tbl('figurinhas') . " f ON f.id = i.figurinha_id
            WHERE i.album_id = :aid AND i.usuario_id = :uid AND i.quantidade > 1
            LIMIT 200
        ");
        $stmt->execute([':aid' => $albumId, ':uid' => $usuario['id']]);
        $minhasRepetidas = array_column($stmt->fetchAll(), 'codigo', 'figurinha_id');
    }

    foreach ($matches as &$match) {
        // Álbum principal do outro usuário
        $stmt = $db->prepare("
            SELECT id FROM " . tbl('albuns') . "
            WHERE usuario_id = :uid AND ativo = 1
            ORDER BY percentual_conclusao DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $match['usuario_id']]);
        $albumOutro = $stmt->fetchColumn();

        $exemplos = [];
        if ($albumOutro && !empty($minhasRepetidas)) {
            // Pega até 5 figurinhas que ele precisa e eu tenho de sobra
            $placeholders = implode(',', array_fill(0, count($minhasRepetidas), '?'));
            $stmt = $db->prepare("
                SELECT f.codigo
                FROM " . tbl('figurinhas') . " f
                LEFT JOIN " . tbl('inventario') . " i
                    ON i.figurinha_id = f.id AND i.album_id = ?
                WHERE f.id IN ($placeholders)
                  AND COALESCE(i.quantidade, 0) = 0
                LIMIT 5
            ");
            $params = array_merge([$albumOutro], array_keys($minhasRepetidas));
            $stmt->execute($params);
            $exemplos = array_column($stmt->fetchAll(), 'codigo');
        }

        $match['exemplos_oferta'] = $exemplos; // figurinhas que eu ofereço a ele
    }
    unset($match);

    echo json_encode(['sucesso' => true, 'matches' => $matches]);
    exit;
}

// ── Figurinhas do parceiro: o que ele oferece e o que ele precisa ────────────
function parceirFigurinhas(PDO $db, array $usuario): void
{
    $slugParceiro = $_POST['slug_parceiro'] ?? '';
    $meuAlbumId   = $_POST['meu_album_id'] ?? '';

    if (!$slugParceiro || !$meuAlbumId) responderErro(400, 'Parâmetros obrigatórios');

    verificarDono($db, $meuAlbumId, $usuario['id']);

    // Busca o parceiro pelo slug
    $stmt = $db->prepare("
        SELECT id, nome, avatar_url, cidade, estado, contato_tipo, contato_valor
        FROM " . tbl('usuarios') . "
        WHERE slug_publico = :slug
        LIMIT 1
    ");
    $stmt->execute([':slug' => $slugParceiro]);
    $parceiro = $stmt->fetch();
    if (!$parceiro) responderErro(404, 'Usuário não encontrado');

    // Melhor álbum do parceiro
    $stmt = $db->prepare("
        SELECT id FROM " . tbl('albuns') . "
        WHERE usuario_id = :uid AND ativo = 1
        ORDER BY percentual_conclusao DESC LIMIT 1
    ");
    $stmt->execute([':uid' => $parceiro['id']]);
    $albumParceiro = $stmt->fetchColumn();
    if (!$albumParceiro) {
        echo json_encode(['sucesso' => true, 'parceiro' => $parceiro, 'oferece' => [], 'precisa' => []]);
        exit;
    }

    // ── O QUE ELE OFERECE ────────────────────────────────────────────────────
    // Repetidas dele (qtd > 1), com status_troca — todas, inclusive bloqueadas
    $stmt = $db->prepare("
        SELECT
            f.id            AS figurinha_id,
            f.codigo,
            f.numero,
            f.tipo,
            s.sigla         AS selecao_sigla,
            s.nome          AS selecao_nome,
            s.bandeira_url,
            g.codigo        AS grupo_codigo,
            i.quantidade,
            i.status_troca,
            i.valor_troca
        FROM " . tbl('inventario') . " i
        JOIN " . tbl('figurinhas') . " f ON f.id = i.figurinha_id
        JOIN " . tbl('selecoes')   . " s ON s.id = f.selecao_id
        LEFT JOIN " . tbl('grupos'). " g ON g.id = s.grupo_id
        WHERE i.album_id   = :aid
          AND i.usuario_id = :uid
          AND i.quantidade > 1
        ORDER BY g.ordem, s.sigla, f.numero
    ");
    $stmt->execute([':aid' => $albumParceiro, ':uid' => $parceiro['id']]);
    $oferece = $stmt->fetchAll();

    // ── O QUE ELE PRECISA (e eu tenho repetida) ──────────────────────────────
    // Faltantes dele que eu tenho repetidas no meu álbum
    $stmt = $db->prepare("
        SELECT
            f.id            AS figurinha_id,
            f.codigo,
            f.numero,
            f.tipo,
            s.sigla         AS selecao_sigla,
            s.nome          AS selecao_nome,
            s.bandeira_url,
            g.codigo        AS grupo_codigo,
            meu.quantidade  AS minha_quantidade,
            meu.status_troca AS meu_status
        FROM " . tbl('figurinhas') . " f
        JOIN " . tbl('selecoes')   . " s  ON s.id = f.selecao_id
        LEFT JOIN " . tbl('grupos'). " g  ON g.id = s.grupo_id
        JOIN " . tbl('inventario') . " meu
            ON meu.figurinha_id = f.id
           AND meu.album_id     = :meu_aid
           AND meu.quantidade   > 1
        LEFT JOIN " . tbl('inventario') . " dele
            ON dele.figurinha_id = f.id
           AND dele.album_id     = :aid_parceiro
        WHERE COALESCE(dele.quantidade, 0) = 0
        ORDER BY g.ordem, s.sigla, f.numero
    ");
    $stmt->execute([':meu_aid' => $meuAlbumId, ':aid_parceiro' => $albumParceiro]);
    $precisa = $stmt->fetchAll();

    echo json_encode([
        'sucesso'  => true,
        'parceiro' => $parceiro,
        'oferece'  => $oferece,
        'precisa'  => $precisa,
    ]);
    exit;
}

// ── Favoritar / desfavoritar um colecionador ─────────────────────────────────
function favoritoToggle(PDO $db, array $usuario): void
{
    $favoritoId = $_POST['favorito_id'] ?? '';
    if (!$favoritoId) responderErro(400, 'favorito_id obrigatório');
    if ($favoritoId === $usuario['id']) responderErro(400, 'Você não pode favoritar a si mesmo');

    // Verifica se o usuário existe
    $stmt = $db->prepare("SELECT id FROM " . tbl('usuarios') . " WHERE id = :id");
    $stmt->execute([':id' => $favoritoId]);
    if (!$stmt->fetch()) responderErro(404, 'Usuário não encontrado');

    // Verifica se já é favorito
    $stmt = $db->prepare("
        SELECT id FROM " . tbl('favoritos') . "
        WHERE usuario_id = :uid AND favorito_id = :fid
    ");
    $stmt->execute([':uid' => $usuario['id'], ':fid' => $favoritoId]);
    $existe = $stmt->fetch();

    if ($existe) {
        // Remove favorito
        $db->prepare("
            DELETE FROM " . tbl('favoritos') . "
            WHERE usuario_id = :uid AND favorito_id = :fid
        ")->execute([':uid' => $usuario['id'], ':fid' => $favoritoId]);
        echo json_encode(['sucesso' => true, 'favoritado' => false]);
    } else {
        // Adiciona favorito
        $db->prepare("
            INSERT INTO " . tbl('favoritos') . "
                (id, usuario_id, favorito_id)
            VALUES (UUID(), :uid, :fid)
        ")->execute([':uid' => $usuario['id'], ':fid' => $favoritoId]);
        echo json_encode(['sucesso' => true, 'favoritado' => true]);
    }
    exit;
}

// ── Lista colecionadores com score, distância e favorito ─────────────────────
// Parâmetros POST:
//   album_id  — álbum do usuário logado para calcular matches
//   busca     — filtro por nome (opcional)
//   ordem     — combinação de: favoritos, distancia, matches (separados por vírgula)
//   limite    — quantos retornar (padrão 10, 0 = todos)
function colecionadoresListar(PDO $db, array $usuario): void
{
    $albumId = $_POST['album_id'] ?? '';
    $busca   = trim($_POST['busca']  ?? '');
    $ordem   = $_POST['ordem']   ?? 'matches';
    $limite  = (int)($_POST['limite'] ?? 10);

    if (!$albumId) responderErro(400, 'album_id obrigatório');

    // IDs de favoritos do usuário logado
    $stmt = $db->prepare("
        SELECT favorito_id FROM " . tbl('favoritos') . "
        WHERE usuario_id = :uid
    ");
    $stmt->execute([':uid' => $usuario['id']]);
    $favoritosIds = array_column($stmt->fetchAll(), 'favorito_id');
    $favSet = array_flip($favoritosIds);

    // Busca todos os outros usuários com match calculado
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.nome,
            u.avatar_url,
            u.cidade,
            u.estado,
            u.contato_tipo,
            u.contato_valor,
            u.slug_publico,
            u.latitude,
            u.longitude,
            COALESCE(m.quantidade_match, 0) AS quantidade_match,
            COALESCE(m.score_match, 0)      AS score_match,
            COALESCE(m.distancia_km, NULL)  AS distancia_km
        FROM " . tbl('usuarios') . " u
        LEFT JOIN " . tbl('matches_troca') . " m
            ON m.usuario_destino_id = u.id
           AND m.usuario_origem_id  = :uid
        WHERE u.id != :uid2
        " . ($busca ? "AND u.nome LIKE :busca" : "") . "
        ORDER BY u.nome ASC
    ");

    $params = [':uid' => $usuario['id'], ':uid2' => $usuario['id']];
    if ($busca) $params[':busca'] = '%' . $busca . '%';
    $stmt->execute($params);
    $todos = $stmt->fetchAll();

    // Enriquece com flag de favorito
    foreach ($todos as &$u) {
        $u['favorito'] = isset($favSet[$u['id']]);
    }
    unset($u);

    // Ordena conforme critérios combinados escolhidos pelo usuário
    $criterios = array_map('trim', explode(',', $ordem));

    usort($todos, function($a, $b) use ($criterios) {
        foreach ($criterios as $criterio) {
            switch ($criterio) {
                case 'favoritos':
                    $cmp = (int)$b['favorito'] <=> (int)$a['favorito'];
                    if ($cmp !== 0) return $cmp;
                    break;

                case 'distancia':
                    // Sem localização vai para o final
                    $da = $a['distancia_km'] ?? PHP_FLOAT_MAX;
                    $db2 = $b['distancia_km'] ?? PHP_FLOAT_MAX;
                    $cmp = $da <=> $db2;
                    if ($cmp !== 0) return $cmp;
                    break;

                case 'matches':
                    $cmp = (int)$b['score_match'] <=> (int)$a['score_match'];
                    if ($cmp !== 0) return $cmp;
                    break;
            }
        }
        // Desempate: nome alfabético
        return strcmp($a['nome'], $b['nome']);
    });

    // Se busca começar com o termo → prioriza (começa com > contém)
    if ($busca) {
        $prefixo = array_filter($todos, fn($u) =>
            stripos($u['nome'], $busca) === 0
        );
        $contem  = array_filter($todos, fn($u) =>
            stripos($u['nome'], $busca) !== 0
        );
        $todos = array_values(array_merge($prefixo, $contem));
    }

    $total = count($todos);

    // Aplica limite (0 = todos)
    if ($limite > 0) {
        $todos = array_slice($todos, 0, $limite);
    }

    echo json_encode([
        'sucesso'       => true,
        'colecionadores'=> $todos,
        'total'         => $total,
        'tem_mais'      => $limite > 0 && $total > $limite,
    ]);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function verificarDono(PDO $db, string $albumId, string $usuarioId): void
{
    $stmt = $db->prepare("
        SELECT id FROM " . tbl('albuns') . "
        WHERE id = :id AND usuario_id = :uid AND ativo = 1
    ");
    $stmt->execute([':id' => $albumId, ':uid' => $usuarioId]);
    if (!$stmt->fetch()) responderErro(403, 'Álbum não encontrado ou sem permissão');
}

function recalcularAlbum(PDO $db, string $albumId): void
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM " . tbl('figurinhas'));
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM " . tbl('inventario') . "
        WHERE album_id = :aid AND quantidade > 0
    ");
    $stmt->execute([':aid' => $albumId]);
    $tem = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(GREATEST(quantidade - 1, 0)), 0)
        FROM " . tbl('inventario') . " WHERE album_id = :aid
    ");
    $stmt->execute([':aid' => $albumId]);
    $repetidas = (int) $stmt->fetchColumn();

    $pct   = $total > 0 ? round(($tem / $total) * 100, 2) : 0;
    $faltam = $total - $tem;

    $db->prepare("
        UPDATE " . tbl('albuns') . "
        SET percentual_conclusao = :pct,
            total_faltantes      = :falt,
            total_repetidas      = :rep
        WHERE id = :id
    ")->execute([':pct' => $pct, ':falt' => $faltam, ':rep' => $repetidas, ':id' => $albumId]);
}

function responderErro(int $codigo, string $msg): never
{
    http_response_code($codigo);
    echo json_encode(['erro' => $msg]);
    exit;
}
