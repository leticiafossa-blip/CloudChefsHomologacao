<?php
/**
 * Pedidos.
 *   GET   /api/pedidos.php   -> fila da cozinha (Pendente e Pronto) + resumo do dia
 *   POST  /api/pedidos.php   -> novo pedido
 *         { nome_cliente, forma_pagamento, itens: [{ id_produto, quantidade }] }
 *         O preço e o total são calculados AQUI, com os valores do banco.
 *   PATCH /api/pedidos.php   -> muda o status { id_pedido, status }
 */
require __DIR__ . '/../_lib/bootstrap.php';

$metodo = metodo(['GET', 'POST', 'PATCH']);
$usuario = exigir_login();
$db = db();

const FORMAS_PAGAMENTO = ['Dinheiro', 'Debito', 'Credito', 'PIX'];

if ($metodo === 'GET') {
    $pedidos = $db->query("
        SELECT * FROM pedido
        WHERE status IN ('Pendente', 'Pronto')
        ORDER BY data_pedido ASC
        LIMIT 50
    ")->fetchAll();

    $itensPorPedido = [];
    if ($pedidos) {
        $ids = array_column($pedidos, 'id_pedido');
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT i.id_pedido_fk, i.nome_produto, i.quantidade, p.categoria
            FROM itens_pedido i LEFT JOIN produto p ON p.id_produto = i.id_produto_fk
            WHERE i.id_pedido_fk IN ($marcadores) ORDER BY i.id_item_pedido
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $item) {
            $itensPorPedido[$item['id_pedido_fk']][] = [
                'nome_produto' => $item['nome_produto'],
                'quantidade'   => (int) $item['quantidade'],
                'categoria'    => $item['categoria'],
            ];
        }
    }

    $resumo = $db->query("
        SELECT COUNT(*) AS pedidos, COALESCE(SUM(valor_total), 0) AS faturamento
        FROM pedido
        WHERE data_pedido >= date_trunc('day', NOW())
          AND status <> 'Cancelado' AND status_pagamento = 'aprovado'
    ")->fetch();

    responder([
        'success' => true,
        'pedidos' => array_map(function ($p) use ($itensPorPedido) {
            $f = formatar_pedido($p);
            $f['itens'] = $itensPorPedido[$p['id_pedido']] ?? [];
            return $f;
        }, $pedidos),
        'hoje' => [
            'pedidos'     => (int) $resumo['pedidos'],
            'faturamento' => (float) $resumo['faturamento'],
        ],
    ]);
}

$dados = entrada();

if ($metodo === 'POST') {
    $cliente = texto($dados, 'nome_cliente', false, 120) ?? 'Consumidor';
    $forma = (string) ($dados['forma_pagamento'] ?? '');
    if (!in_array($forma, FORMAS_PAGAMENTO, true)) {
        erro('Selecione uma forma de pagamento válida.');
    }

    // Junta os itens por produto e valida as quantidades.
    $quantidades = [];
    foreach (($dados['itens'] ?? []) as $item) {
        if (!is_array($item)) {
            erro('Lista de itens inválida.');
        }
        $idProduto = id($item, 'id_produto');
        $qtd = (int) numero($item, 'quantidade', 1, 50);
        $quantidades[$idProduto] = ($quantidades[$idProduto] ?? 0) + $qtd;
    }
    if (!$quantidades) {
        erro('O pedido precisa ter pelo menos um produto.');
    }

    // Busca os preços REAIS no banco: o navegador não decide o valor do pedido.
    $ids = array_keys($quantidades);
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id_produto, nome, preco FROM produto WHERE status AND id_produto IN ($marcadores)");
    $stmt->execute($ids);
    $produtos = [];
    foreach ($stmt->fetchAll() as $p) {
        $produtos[(int) $p['id_produto']] = $p;
    }
    if (count($produtos) !== count($ids)) {
        erro('Algum produto do pedido não está mais disponível. Atualize a página.', 409);
    }

    $total = 0.0;
    foreach ($quantidades as $idProduto => $qtd) {
        $total += (float) $produtos[$idProduto]['preco'] * $qtd;
    }
    $total = round($total, 2);

    $ehPix = $forma === 'PIX';

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO pedido (nome_cliente, valor_total, status, forma_pagamento, status_pagamento, id_usuario)
            VALUES (:cliente, :total, :status, :forma, :pagamento, :usuario)
            RETURNING id_pedido
        ');
        $stmt->execute([
            ':cliente'   => $cliente,
            ':total'     => $total,
            ':status'    => $ehPix ? 'Aguardando PIX' : 'Pendente',
            ':forma'     => $forma,
            ':pagamento' => $ehPix ? 'pendente' : 'aprovado',
            ':usuario'   => $usuario['id_usuario'],
        ]);
        $idPedido = (int) $stmt->fetchColumn();

        $stmtItem = $db->prepare('
            INSERT INTO itens_pedido (id_pedido_fk, id_produto_fk, nome_produto, valor_unitario, quantidade)
            VALUES (:pedido, :produto, :nome, :valor, :qtd)
        ');
        foreach ($quantidades as $idProduto => $qtd) {
            $stmtItem->execute([
                ':pedido'  => $idPedido,
                ':produto' => $idProduto,
                ':nome'    => $produtos[$idProduto]['nome'],
                ':valor'   => $produtos[$idProduto]['preco'],
                ':qtd'     => $qtd,
            ]);
        }

        // Dinheiro/cartão: baixa o estoque na hora. Pix: só quando o pagamento for aprovado.
        if (!$ehPix) {
            baixar_estoque_do_pedido($db, $idPedido);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    responder(['success' => true, 'id_pedido' => $idPedido, 'valor_total' => $total, 'pix' => $ehPix], 201);
}

// PATCH: mudança de status
$idPedido = id($dados, 'id_pedido');
$novo = (string) ($dados['status'] ?? '');

// Para cada status novo, de quais status ele pode vir.
$transicoes = [
    'Pronto'    => ['Pendente'],
    'Concluído' => ['Pronto'],
    'Cancelado' => ['Aguardando PIX', 'Pendente', 'Pronto'],
];
if (!isset($transicoes[$novo])) {
    erro('Status inválido.');
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT status FROM pedido WHERE id_pedido = :id FOR UPDATE');
    $stmt->execute([':id' => $idPedido]);
    $atual = $stmt->fetchColumn();
    if ($atual === false) {
        throw new ErroNegocio('Pedido não encontrado.');
    }
    if (!in_array($atual, $transicoes[$novo], true)) {
        throw new ErroNegocio("Um pedido \"$atual\" não pode passar para \"$novo\".");
    }
    // Atendente só cancela pedido Pix que ainda não foi pago; o resto é com o gerente.
    if ($novo === 'Cancelado' && $atual !== 'Aguardando PIX' && $usuario['tipo_usuario'] !== 'GERENTE') {
        throw new ErroNegocio('Apenas o gerente pode cancelar um pedido que já foi pago.');
    }
    if ($novo === 'Cancelado') {
        devolver_estoque_do_pedido($db, $idPedido);
    }
    $db->prepare('UPDATE pedido SET status = :s WHERE id_pedido = :id')->execute([':s' => $novo, ':id' => $idPedido]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

responder(['success' => true]);
