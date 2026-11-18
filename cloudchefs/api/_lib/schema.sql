-- =============================================================
-- Cloud Chefs - Esquema completo do banco (PostgreSQL)
-- =============================================================
-- Este arquivo é executado automaticamente pela API na primeira
-- vez que ela se conecta a um banco vazio. Também pode ser rodado
-- manualmente:  psql "$DATABASE_URL" -f schema.sql
-- =============================================================

CREATE TABLE IF NOT EXISTS usuario (
    id_usuario     SERIAL PRIMARY KEY,
    nome           VARCHAR(120) NOT NULL,
    login          VARCHAR(60)  NOT NULL UNIQUE,
    senha          VARCHAR(255) NOT NULL,              -- hash bcrypt
    tipo_usuario   VARCHAR(20)  NOT NULL DEFAULT 'ATENDENTE'
                   CHECK (tipo_usuario IN ('GERENTE', 'ATENDENTE')),
    ativo          BOOLEAN      NOT NULL DEFAULT TRUE,
    criado_em      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS estoque (
    id_item            SERIAL PRIMARY KEY,
    nome_item          VARCHAR(120)   NOT NULL UNIQUE,
    quantidade_atual   NUMERIC(12, 3) NOT NULL DEFAULT 0 CHECK (quantidade_atual >= 0),
    quantidade_minima  NUMERIC(12, 3) NOT NULL DEFAULT 10 CHECK (quantidade_minima >= 0),
    unidade_medida     VARCHAR(10)    NOT NULL DEFAULT 'un'
);

CREATE TABLE IF NOT EXISTS produto (
    id_produto  SERIAL PRIMARY KEY,
    nome        VARCHAR(120)   NOT NULL UNIQUE,
    preco       NUMERIC(10, 2) NOT NULL CHECK (preco > 0),
    categoria   VARCHAR(20)    NOT NULL DEFAULT 'lanche'
                CHECK (categoria IN ('lanche', 'acompanhamento', 'bebida')),
    imagem      VARCHAR(255),
    status      BOOLEAN        NOT NULL DEFAULT TRUE   -- TRUE = aparece no cardápio
);

-- Receita do produto: quanto de cada item de estoque ele consome.
CREATE TABLE IF NOT EXISTS composicao_produto (
    id_produto             INTEGER        NOT NULL REFERENCES produto(id_produto) ON DELETE CASCADE,
    id_item_estoque        INTEGER        NOT NULL REFERENCES estoque(id_item) ON DELETE RESTRICT,
    quantidade_necessaria  NUMERIC(12, 3) NOT NULL CHECK (quantidade_necessaria > 0),
    PRIMARY KEY (id_produto, id_item_estoque)
);

CREATE TABLE IF NOT EXISTS pedido (
    id_pedido                SERIAL PRIMARY KEY,
    nome_cliente             VARCHAR(120)   NOT NULL DEFAULT 'Consumidor',
    data_pedido              TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
    valor_total              NUMERIC(10, 2) NOT NULL CHECK (valor_total >= 0),
    status                   VARCHAR(30)    NOT NULL DEFAULT 'Pendente'
                             CHECK (status IN ('Aguardando PIX', 'Pendente', 'Pronto', 'Concluído', 'Cancelado')),
    forma_pagamento          VARCHAR(20)    NOT NULL
                             CHECK (forma_pagamento IN ('Dinheiro', 'Debito', 'Credito', 'PIX')),
    status_pagamento         VARCHAR(50)    NOT NULL DEFAULT 'aprovado',
    estoque_baixado          BOOLEAN        NOT NULL DEFAULT FALSE,
    id_usuario               INTEGER        REFERENCES usuario(id_usuario) ON DELETE SET NULL,
    -- Integração Pix / Mercado Pago
    mercado_pago_payment_id  VARCHAR(100),
    pix_copia_cola           TEXT,
    pix_qr_code_base64       TEXT,
    pix_ticket_url           TEXT,
    mp_status_detalhe        TEXT
);

CREATE INDEX IF NOT EXISTS idx_pedido_data   ON pedido (data_pedido);
CREATE INDEX IF NOT EXISTS idx_pedido_status ON pedido (status);

CREATE TABLE IF NOT EXISTS itens_pedido (
    id_item_pedido  SERIAL PRIMARY KEY,
    id_pedido_fk    INTEGER        NOT NULL REFERENCES pedido(id_pedido) ON DELETE CASCADE,
    id_produto_fk   INTEGER        REFERENCES produto(id_produto) ON DELETE SET NULL,
    nome_produto    VARCHAR(120)   NOT NULL,   -- guardado para o histórico não mudar se o produto for renomeado
    valor_unitario  NUMERIC(10, 2) NOT NULL,
    quantidade      INTEGER        NOT NULL CHECK (quantidade > 0)
);

CREATE INDEX IF NOT EXISTS idx_itens_pedido ON itens_pedido (id_pedido_fk);

CREATE TABLE IF NOT EXISTS mercado_pago_config (
    id_config       SERIAL PRIMARY KEY,
    public_key      TEXT,
    access_token    TEXT        NOT NULL,
    webhook_url     TEXT,
    webhook_secret  TEXT,
    ambiente        VARCHAR(20) NOT NULL DEFAULT 'teste' CHECK (ambiente IN ('teste', 'producao')),
    ativo           BOOLEAN     NOT NULL DEFAULT TRUE,
    criado_em       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
