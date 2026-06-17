-- Execute este arquivo UMA VEZ no seu banco PostgreSQL antes de usar o Pix.
-- Ele cria a tabela de configuração do Mercado Pago e adiciona os campos necessários no pedido.

CREATE TABLE IF NOT EXISTS mercado_pago_config (
    id_config SERIAL PRIMARY KEY,
    public_key TEXT,
    access_token TEXT NOT NULL,
    webhook_url TEXT,
    webhook_secret TEXT,
    ambiente VARCHAR(20) NOT NULL DEFAULT 'teste',
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

ALTER TABLE pedido ADD COLUMN IF NOT EXISTS mercado_pago_payment_id VARCHAR(100);
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS status_pagamento VARCHAR(50) DEFAULT 'nao_aplicavel';
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS pix_copia_cola TEXT;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS pix_qr_code_base64 TEXT;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS pix_ticket_url TEXT;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS mp_status_detalhe TEXT;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS estoque_baixado BOOLEAN DEFAULT FALSE;

-- Ajusta pedidos antigos que não são Pix para não quebrarem relatórios.
UPDATE pedido
SET status_pagamento = 'nao_aplicavel'
WHERE status_pagamento IS NULL;

UPDATE pedido
SET estoque_baixado = TRUE
WHERE estoque_baixado IS NULL;
