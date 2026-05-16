-- ===========================================
-- IFC – Inventário de Figurinhas da Copa 2026
-- Schema para Hostinger (prefixo ifc_)
-- Não inclui CREATE DATABASE nem USE
-- ===========================================

-- -------------------------------------------
-- Tabela: ifc_usuarios
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_usuarios (
    id            CHAR(36)        NOT NULL,
    nome          VARCHAR(100)    NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    senha_hash    VARCHAR(255)    NULL,
    google_id     VARCHAR(100)    NULL,
    avatar_url    VARCHAR(500)    NULL,
    cidade        VARCHAR(120)    NULL,
    estado        VARCHAR(120)    NULL,
    latitude      DECIMAL(10,8)   NULL,
    longitude     DECIMAL(11,8)   NULL,
    slug_publico  VARCHAR(120)    NOT NULL,
    criado_em     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifc_usuarios_email  (email),
    UNIQUE KEY uq_ifc_usuarios_slug   (slug_publico),
    UNIQUE KEY uq_ifc_usuarios_google (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_grupos
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_grupos (
    id     CHAR(36)     NOT NULL,
    codigo VARCHAR(10)  NOT NULL,
    nome   VARCHAR(100) NOT NULL,
    ordem  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifc_grupos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_selecoes
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_selecoes (
    id           CHAR(36)     NOT NULL,
    grupo_id     CHAR(36)     NULL,
    nome         VARCHAR(100) NOT NULL,
    sigla        VARCHAR(10)  NOT NULL,
    bandeira_url VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifc_selecoes_sigla (sigla),
    CONSTRAINT fk_ifc_selecoes_grupo
        FOREIGN KEY (grupo_id) REFERENCES ifc_grupos (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_figurinhas
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_figurinhas (
    id         CHAR(36)                                   NOT NULL,
    codigo     VARCHAR(20)                                NOT NULL,
    selecao_id CHAR(36)                                   NULL,
    nome       VARCHAR(120)                               NULL,
    tipo       ENUM('normal','especial','lenda','capa')   NOT NULL DEFAULT 'normal',
    numero     INT                                        NOT NULL,
    imagem_url VARCHAR(500)                               NULL,
    criado_em  DATETIME                                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifc_figurinhas_codigo (codigo),
    CONSTRAINT fk_ifc_figurinhas_selecao
        FOREIGN KEY (selecao_id) REFERENCES ifc_selecoes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_albuns
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_albuns (
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
    UNIQUE KEY uq_ifc_albuns_slug (usuario_id, slug_publico),
    CONSTRAINT fk_ifc_albuns_usuario
        FOREIGN KEY (usuario_id) REFERENCES ifc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_inventario
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_inventario (
    id            CHAR(36) NOT NULL,
    album_id      CHAR(36) NOT NULL,
    usuario_id    CHAR(36) NOT NULL,
    figurinha_id  CHAR(36) NOT NULL,
    quantidade    INT      NOT NULL DEFAULT 0,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifc_inventario (album_id, figurinha_id),
    CONSTRAINT fk_ifc_inv_album
        FOREIGN KEY (album_id) REFERENCES ifc_albuns (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ifc_inv_usuario
        FOREIGN KEY (usuario_id) REFERENCES ifc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ifc_inv_figurinha
        FOREIGN KEY (figurinha_id) REFERENCES ifc_figurinhas (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_matches_troca
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_matches_troca (
    id                 CHAR(36)      NOT NULL,
    usuario_origem_id  CHAR(36)      NOT NULL,
    usuario_destino_id CHAR(36)      NOT NULL,
    quantidade_match   INT           NOT NULL DEFAULT 0,
    distancia_km       DECIMAL(10,2) NULL,
    score_match        INT           NOT NULL DEFAULT 0,
    criado_em          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ifc_match_origem
        FOREIGN KEY (usuario_origem_id) REFERENCES ifc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ifc_match_destino
        FOREIGN KEY (usuario_destino_id) REFERENCES ifc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Tabela: ifc_scans
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS ifc_scans (
    id                   CHAR(36)                              NOT NULL,
    usuario_id           CHAR(36)                              NOT NULL,
    quantidade_detectada INT                                   NOT NULL DEFAULT 0,
    status               ENUM('processando','concluido','erro') NOT NULL DEFAULT 'processando',
    criado_em            DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ifc_scan_usuario
        FOREIGN KEY (usuario_id) REFERENCES ifc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- DADOS INICIAIS
-- ===========================================

INSERT IGNORE INTO ifc_grupos (id, codigo, nome, ordem) VALUES
('10000000-0000-0000-0000-000000000001', 'FWC', 'FIFA World Cup',  0),
('10000000-0000-0000-0000-000000000002', 'CC',  'Coca-Cola',       1),
('10000000-0000-0000-0000-000000000003', 'A',   'Grupo A',         2),
('10000000-0000-0000-0000-000000000004', 'B',   'Grupo B',         3),
('10000000-0000-0000-0000-000000000005', 'C',   'Grupo C',         4),
('10000000-0000-0000-0000-000000000006', 'D',   'Grupo D',         5),
('10000000-0000-0000-0000-000000000007', 'E',   'Grupo E',         6),
('10000000-0000-0000-0000-000000000008', 'F',   'Grupo F',         7),
('10000000-0000-0000-0000-000000000009', 'G',   'Grupo G',         8),
('10000000-0000-0000-0000-000000000010', 'H',   'Grupo H',         9),
('10000000-0000-0000-0000-000000000011', 'I',   'Grupo I',        10),
('10000000-0000-0000-0000-000000000012', 'J',   'Grupo J',        11),
('10000000-0000-0000-0000-000000000013', 'K',   'Grupo K',        12),
('10000000-0000-0000-0000-000000000014', 'L',   'Grupo L',        13);
