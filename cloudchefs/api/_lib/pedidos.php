<?php
/**
 * Regras de negócio de pedidos e estoque, usadas por mais de um endpoint.
 */
declare(strict_types=1);

/** Erro de regra de negócio: a mensagem pode ser mostrada ao usuário. */
class ErroNegocio extends RuntimeException
{
}

/** Quanto de cada item de estoque um pedido consome (pela receita dos produtos). */
function consumo_do_pedido(PDO $db, int $idPedido): array
{
    $stmt = $db->prepare('
        SELECT c.id_item_estoque AS id_item, SUM(c.quantidade_necessaria * i.quantidade) AS necessario
        FROM itens_pedido i
        JOIN composicao_produto c ON c.id_produto = i.id_produto_fk
        WHERE i.id_pedido_fk = :id
        GROUP BY c.id_item_estoque
        ORDER BY c.id_item_estoque
    ');
    $stmt->execute([':id' => $idPedido]);
    return $stmt->fetchAll();
}

/**
 * Dá baixa no estoque de um pedido (uma única vez).
 * Deve ser chamada dentro de uma transação.
 */
function baixar_estoque_do_pedido(PDO $db, int $idPedido): void
{
    $stmt = $db->prepare('SELECT estoque_baixado FROM pedido WHERE id_pedido = :id FOR UPDATE');
    $stmt->execute([':id' => $idPedido]);
    $pedido = $stmt->fetch();
    if (!$pedido) {
        throw new ErroNegocio('Pedido não encontrado.');
    }
    if ($pedido['estoque_baixado']) {
        return;
    }

    $consumo = consumo_do_pedido($db, $idPedido);
    if ($consumo) {
        $ids = array_map(fn($c) => (int) $c['id_item'], $consumo);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        // Trava as linhas do estoque para dois pedidos não usarem o mesmo item ao mesmo tempo.
        $lock = $db->prepare("SELECT id_item, nome_item, quantidade_atual FROM estoque WHERE id_item IN ($marcadores) FOR UPDATE");
        $lock->execute($ids);
        $estoque = [];
        foreach ($lock->fetchAll() as $linha) {
            $estoque[(int) $linha['id_item']] = $linha;
        }

        $faltando = [];
        foreach ($consumo as $c) {
            $item = $estoque[(int) $c['id_item']];
            if ((float) $item['quantidade_atual'] < (float) $c['necessario']) {
                $faltando[] = $item['nome_item'];
            }
        }
        if ($faltando) {
            throw new ErroNegocio('Estoque insuficiente: ' . implode(', ', $faltando) . '.');
        }

        $upd = $db->prepare('UPDATE estoque SET quantidade_atual = quantidade_atual - :qtd WHERE id_item = :id');
        foreach ($consumo as $c) {
            $upd->execute([':qtd' => $c['necessario'], ':id' => $c['id_item']]);
        }
    }

    $db->prepare('UPDATE pedido SET estoque_baixado = TRUE WHERE id_pedido = :id')->execute([':id' => $idPedido]);
}

/** Devolve ao estoque o que um pedido cancelado tinha consumido. */
function devolver_estoque_do_pedido(PDO $db, int $idPedido): void
{
    $stmt = $db->prepare('SELECT estoque_baixado FROM pedido WHERE id_pedido = :id FOR UPDATE');
    $stmt->execute([':id' => $idPedido]);
    $pedido = $stmt->fetch();
    if (!$pedido || !$pedido['estoque_baixado']) {
        return;
    }
    $upd = $db->prepare('UPDATE estoque SET quantidade_atual = quantidade_atual + :qtd WHERE id_item = :id');
    foreach (consumo_do_pedido($db, $idPedido) as $c) {
        $upd->execute([':qtd' => $c['necessario'], ':id' => $c['id_item']]);
    }
    $db->prepare('UPDATE pedido SET estoque_baixado = FALSE WHERE id_pedido = :id')->execute([':id' => $idPedido]);
}

/** Aprova o pagamento de um pedido Pix e o libera para a cozinha. */
function aprovar_pagamento_pix(PDO $db, int $idPedido, ?string $detalhe): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT status FROM pedido WHERE id_pedido = :id FOR UPDATE');
        $stmt->execute([':id' => $idPedido]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new ErroNegocio('Pedido não encontrado.');
        }
        if ($status === 'Aguardando PIX') {
            baixar_estoque_do_pedido($db, $idPedido);
            $db->prepare("
                UPDATE pedido
                SET status_pagamento = 'aprovado', status = 'Pendente', mp_status_detalhe = :detalhe
                WHERE id_pedido = :id
            ")->execute([':detalhe' => $detalhe, ':id' => $idPedido]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Formata um pedido do banco para a resposta JSON. */
function formatar_pedido(array $p): array
{
    return [
        'id_pedido'        => (int) $p['id_pedido'],
        'nome_cliente'     => $p['nome_cliente'],
        'data_pedido'      => $p['data_pedido'],
        'valor_total'      => (float) $p['valor_total'],
        'status'           => $p['status'],
        'forma_pagamento'  => $p['forma_pagamento'],
        'status_pagamento' => $p['status_pagamento'],
    ];
}
