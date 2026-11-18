-- =============================================================
-- Cloud Chefs - Dados de exemplo (cardápio, estoque e pedidos)
-- =============================================================
-- Rodado automaticamente junto com schema.sql quando o banco está
-- vazio. Os usuários NÃO são criados aqui: o primeiro gerente é
-- criado a partir das variáveis de ambiente ADMIN_LOGIN/ADMIN_PASSWORD.
-- =============================================================

INSERT INTO estoque (nome_item, quantidade_atual, quantidade_minima, unidade_medida) VALUES
    ('Pão de hambúrguer',        120, 30, 'un'),
    ('Pão com gergelim',          80, 20, 'un'),
    ('Hambúrguer bovino',        200, 40, 'un'),
    ('Frango empanado',           60, 20, 'un'),
    ('Queijo cheddar (fatia)',   150, 40, 'un'),
    ('Bacon (fatia)',            100, 30, 'un'),
    ('Alface',                     3,  1, 'kg'),
    ('Molho especial',             2,  1, 'L'),
    ('Batata congelada',          15,  5, 'kg'),
    ('Lata Coca-Cola',            48, 12, 'un'),
    ('Lata Guaraná',              36, 12, 'un'),
    ('Lata Fanta Laranja',         6, 12, 'un')
ON CONFLICT (nome_item) DO NOTHING;

INSERT INTO produto (nome, preco, categoria, imagem) VALUES
    ('Mega Stacker',            28.90, 'lanche',         'img/hambuguer1.png'),
    ('Big Mac',                 28.90, 'lanche',         'img/bigmac.png'),
    ('Duplo Quarteirão',        32.50, 'lanche',         'img/duploquarterao.png'),
    ('Duplo Burguer c/ Queijo', 25.00, 'lanche',         'img/duploburguercomqueijo.png'),
    ('Duplo Burguer c/ Bacon',  30.50, 'lanche',         'img/duploburguerbacon.png'),
    ('Mc Chicken Duplo',        18.00, 'lanche',         'img/mcchickenduplo.png'),
    ('Cheeseburguer',           22.00, 'lanche',         'img/cheeseburguer.png'),
    ('Mc Chicken Bacon',        38.00, 'lanche',         'img/mccchickenbacon.png'),
    ('Batata Frita P',           9.00, 'acompanhamento', 'img/batatap.png'),
    ('Batata Frita M',          13.00, 'acompanhamento', 'img/batatam.png'),
    ('Batata Frita G',          17.00, 'acompanhamento', 'img/batatag.png'),
    ('Coca-Cola',                8.00, 'bebida',         'img/cocacola.png'),
    ('Guaraná',                  8.00, 'bebida',         'img/guarana.png'),
    ('Fanta Laranja',            8.00, 'bebida',         'img/fantalaranja.png')
ON CONFLICT (nome) DO NOTHING;

-- Receitas (produto -> itens de estoque)
INSERT INTO composicao_produto (id_produto, id_item_estoque, quantidade_necessaria)
SELECT p.id_produto, e.id_item, r.qtd
FROM (VALUES
    ('Mega Stacker',            'Pão de hambúrguer',      1),
    ('Mega Stacker',            'Hambúrguer bovino',      3),
    ('Mega Stacker',            'Queijo cheddar (fatia)', 3),
    ('Mega Stacker',            'Bacon (fatia)',          2),
    ('Mega Stacker',            'Molho especial',         0.03),
    ('Big Mac',                 'Pão com gergelim',       1),
    ('Big Mac',                 'Hambúrguer bovino',      2),
    ('Big Mac',                 'Queijo cheddar (fatia)', 1),
    ('Big Mac',                 'Alface',                 0.02),
    ('Big Mac',                 'Molho especial',         0.03),
    ('Duplo Quarteirão',        'Pão com gergelim',       1),
    ('Duplo Quarteirão',        'Hambúrguer bovino',      2),
    ('Duplo Quarteirão',        'Queijo cheddar (fatia)', 2),
    ('Duplo Burguer c/ Queijo', 'Pão de hambúrguer',      1),
    ('Duplo Burguer c/ Queijo', 'Hambúrguer bovino',      2),
    ('Duplo Burguer c/ Queijo', 'Queijo cheddar (fatia)', 2),
    ('Duplo Burguer c/ Bacon',  'Pão de hambúrguer',      1),
    ('Duplo Burguer c/ Bacon',  'Hambúrguer bovino',      2),
    ('Duplo Burguer c/ Bacon',  'Queijo cheddar (fatia)', 1),
    ('Duplo Burguer c/ Bacon',  'Bacon (fatia)',          2),
    ('Mc Chicken Duplo',        'Pão de hambúrguer',      1),
    ('Mc Chicken Duplo',        'Frango empanado',        2),
    ('Mc Chicken Duplo',        'Alface',                 0.02),
    ('Mc Chicken Duplo',        'Molho especial',         0.02),
    ('Cheeseburguer',           'Pão de hambúrguer',      1),
    ('Cheeseburguer',           'Hambúrguer bovino',      1),
    ('Cheeseburguer',           'Queijo cheddar (fatia)', 1),
    ('Mc Chicken Bacon',        'Pão de hambúrguer',      1),
    ('Mc Chicken Bacon',        'Frango empanado',        1),
    ('Mc Chicken Bacon',        'Bacon (fatia)',          2),
    ('Mc Chicken Bacon',        'Alface',                 0.02),
    ('Batata Frita P',          'Batata congelada',       0.10),
    ('Batata Frita M',          'Batata congelada',       0.15),
    ('Batata Frita G',          'Batata congelada',       0.20),
    ('Coca-Cola',               'Lata Coca-Cola',         1),
    ('Guaraná',                 'Lata Guaraná',           1),
    ('Fanta Laranja',           'Lata Fanta Laranja',     1)
) AS r(produto, item, qtd)
JOIN produto p ON p.nome = r.produto
JOIN estoque e ON e.nome_item = r.item
ON CONFLICT DO NOTHING;

-- Histórico de vendas dos últimos 14 dias, para os relatórios e o
-- financeiro já terem o que mostrar na apresentação. Esses pedidos
-- já constam como entregues e não mexem no estoque atual.
DO $$
DECLARE
    clientes TEXT[] := ARRAY['Ana', 'Bruno', 'Carla', 'Diego', 'Eduarda', 'Felipe', 'Gabriela',
                             'Henrique', 'Isabela', 'João', 'Larissa', 'Marcos', 'Natália', 'Otávio'];
    formas   TEXT[] := ARRAY['Dinheiro', 'Debito', 'Credito', 'PIX'];
    dia INT; n INT; i INT; k INT;
    novo_id INT; prod RECORD; qtd INT; total NUMERIC;
    momento TIMESTAMPTZ;
BEGIN
    IF EXISTS (SELECT 1 FROM pedido) THEN
        RETURN;
    END IF;

    PERFORM setseed(0.42);

    FOR dia IN REVERSE 14..1 LOOP
        n := 4 + floor(random() * 6)::INT;
        FOR i IN 1..n LOOP
            momento := date_trunc('day', NOW() AT TIME ZONE 'America/Sao_Paulo') AT TIME ZONE 'America/Sao_Paulo'
                       - make_interval(days => dia)
                       + make_interval(hours => 11 + floor(random() * 11)::INT, mins => floor(random() * 60)::INT);

            INSERT INTO pedido (nome_cliente, data_pedido, valor_total, status, forma_pagamento,
                                status_pagamento, estoque_baixado)
            VALUES (clientes[1 + floor(random() * array_length(clientes, 1))::INT], momento, 0,
                    'Concluído', formas[1 + floor(random() * 4)::INT], 'aprovado', TRUE)
            RETURNING id_pedido INTO novo_id;

            total := 0;
            FOR k IN 1..(1 + floor(random() * 3)::INT) LOOP
                SELECT * INTO prod FROM produto ORDER BY random() LIMIT 1;
                qtd := 1 + floor(random() * 2)::INT;
                INSERT INTO itens_pedido (id_pedido_fk, id_produto_fk, nome_produto, valor_unitario, quantidade)
                VALUES (novo_id, prod.id_produto, prod.nome, prod.preco, qtd);
                total := total + prod.preco * qtd;
            END LOOP;

            UPDATE pedido SET valor_total = total WHERE id_pedido = novo_id;
        END LOOP;
    END LOOP;
END $$;
