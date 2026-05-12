-- ===========================================
-- IFC – Inventário de Figurinhas da Copa 2026
-- Schema MariaDB
-- ===========================================

CREATE DATABASE IF NOT EXISTS ifc
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ifc;

-- -------------------------------------------
-- Tabela: usuarios
-- -------------------------------------------
CREATE TABLE usuarios (
    id            CHAR(36)        NOT NULL,
    nome          VARCHAR(100)    NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    senha_hash    VARCHAR(255)    NULL,
    login_google  TINYINT(1)      NOT NULL DEFAULT 0,
    avatar_url    VARCHAR(500)    NULL,
    cidade        VARCHAR(120)    NULL,
    estado        VARCHAR(120)    NULL,
    latitude      DECIMAL(10,8)   NULL,
    longitude     DECIMAL(11,8)   NULL,
    slug_publico  VARCHAR(120)    NOT NULL,
    criado_em     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email       (email),
    UNIQUE KEY uq_usuarios_slug        (slug_publico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: albuns
-- -------------------------------------------
CREATE TABLE albuns (
    id                   CHAR(36)       NOT NULL,
    usuario_id           CHAR(36)       NOT NULL,
    nome                 VARCHAR(120)   NOT NULL,
    slug_publico         VARCHAR(120)   NOT NULL,
    percentual_conclusao DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    total_faltantes      INT            NOT NULL DEFAULT 0,
    total_repetidas      INT            NOT NULL DEFAULT 0,
    capa_url             VARCHAR(500)   NULL,
    ativo                TINYINT(1)     NOT NULL DEFAULT 1,
    criado_em            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_albuns_slug_usuario (usuario_id, slug_publico),
    CONSTRAINT fk_albuns_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: grupos
-- (Ex: Grupo A, Grupo B, FWC, CC, etc.)
-- -------------------------------------------
CREATE TABLE grupos (
    id     CHAR(36)     NOT NULL,
    codigo VARCHAR(10)  NOT NULL,
    nome   VARCHAR(100) NOT NULL,
    ordem  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grupos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: selecoes
-- -------------------------------------------
CREATE TABLE selecoes (
    id          CHAR(36)     NOT NULL,
    grupo_id    CHAR(36)     NOT NULL,
    nome        VARCHAR(100) NOT NULL,
    sigla       VARCHAR(10)  NOT NULL,
    bandeira_url VARCHAR(500) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_selecoes_grupo
        FOREIGN KEY (grupo_id) REFERENCES grupos (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: figurinhas
-- -------------------------------------------
CREATE TABLE figurinhas (
    id          CHAR(36)                              NOT NULL,
    codigo      VARCHAR(20)                           NOT NULL,
    selecao_id  CHAR(36)                              NULL,
    nome        VARCHAR(120)                          NULL,
    tipo        ENUM('normal','especial','lenda','capa') NOT NULL DEFAULT 'normal',
    numero      INT                                   NOT NULL,
    imagem_url  VARCHAR(500)                          NULL,
    criado_em   DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_figurinhas_codigo (codigo),
    CONSTRAINT fk_figurinhas_selecao
        FOREIGN KEY (selecao_id) REFERENCES selecoes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: inventario_usuario
-- -------------------------------------------
CREATE TABLE inventario_usuario (
    id           CHAR(36) NOT NULL,
    album_id     CHAR(36) NOT NULL,
    usuario_id   CHAR(36) NOT NULL,
    figurinha_id CHAR(36) NOT NULL,
    quantidade   INT      NOT NULL DEFAULT 0,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventario (album_id, figurinha_id),
    CONSTRAINT fk_inv_album
        FOREIGN KEY (album_id) REFERENCES albuns (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_inv_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_inv_figurinha
        FOREIGN KEY (figurinha_id) REFERENCES figurinhas (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: matches_troca
-- -------------------------------------------
CREATE TABLE matches_troca (
    id                  CHAR(36)       NOT NULL,
    usuario_origem_id   CHAR(36)       NOT NULL,
    usuario_destino_id  CHAR(36)       NOT NULL,
    quantidade_match    INT            NOT NULL DEFAULT 0,
    distancia_km        DECIMAL(10,2)  NULL,
    score_match         INT            NOT NULL DEFAULT 0,
    criado_em           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_match_origem
        FOREIGN KEY (usuario_origem_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_destino
        FOREIGN KEY (usuario_destino_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: importacoes_csv
-- -------------------------------------------
CREATE TABLE importacoes_csv (
    id            CHAR(36)     NOT NULL,
    usuario_id    CHAR(36)     NOT NULL,
    nome_arquivo  VARCHAR(255) NOT NULL,
    processado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_csv_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: scans_figurinha
-- -------------------------------------------
CREATE TABLE scans_figurinha (
    id                  CHAR(36)                          NOT NULL,
    usuario_id          CHAR(36)                          NOT NULL,
    imagem_scan_url     VARCHAR(500)                      NULL,
    quantidade_detectada INT                              NOT NULL DEFAULT 0,
    status              ENUM('processando','concluido','erro') NOT NULL DEFAULT 'processando',
    criado_em           DATETIME                          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_scan_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: scans_itens
-- -------------------------------------------
CREATE TABLE scans_itens (
    id               CHAR(36)    NOT NULL,
    scan_id          CHAR(36)    NOT NULL,
    figurinha_id     CHAR(36)    NOT NULL,
    codigo_detectado VARCHAR(20) NOT NULL,
    confianca        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    CONSTRAINT fk_scanitem_scan
        FOREIGN KEY (scan_id) REFERENCES scans_figurinha (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_scanitem_figurinha
        FOREIGN KEY (figurinha_id) REFERENCES figurinhas (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- DADOS INICIAIS
-- ===========================================

-- Usuário fake para desenvolvimento (sem login)
INSERT INTO usuarios (id, nome, email, senha_hash, login_google, slug_publico) VALUES
('00000000-0000-0000-0000-000000000001', 'Dev Local', 'dev@local.test', NULL, 0, 'dev-local');

-- Grupos da Copa 2026
INSERT INTO grupos (id, codigo, nome, ordem) VALUES
('10000000-0000-0000-0000-000000000001', 'FWC', 'FIFA World Cup',    0),
('10000000-0000-0000-0000-000000000002', 'CC',  'Estádios e Cidades', 1),
('10000000-0000-0000-0000-000000000003', 'A',   'Grupo A',           2),
('10000000-0000-0000-0000-000000000004', 'B',   'Grupo B',           3),
('10000000-0000-0000-0000-000000000005', 'C',   'Grupo C',           4),
('10000000-0000-0000-0000-000000000006', 'D',   'Grupo D',           5),
('10000000-0000-0000-0000-000000000007', 'E',   'Grupo E',           6),
('10000000-0000-0000-0000-000000000008', 'F',   'Grupo F',           7),
('10000000-0000-0000-0000-000000000009', 'G',   'Grupo G',           8),
('10000000-0000-0000-0000-000000000010', 'H',   'Grupo H',           9),
('10000000-0000-0000-0000-000000000011', 'I',   'Grupo I',          10),
('10000000-0000-0000-0000-000000000012', 'J',   'Grupo J',          11),
('10000000-0000-0000-0000-000000000013', 'K',   'Grupo K',          12),
('10000000-0000-0000-0000-000000000014', 'L',   'Grupo L',          13);

-- Álbum inicial do usuário dev
INSERT INTO albuns (id, usuario_id, nome, slug_publico) VALUES
('20000000-0000-0000-0000-000000000001',
 '00000000-0000-0000-0000-000000000001',
 'Meu Álbum Copa 2026',
 'album-copa-2026');
