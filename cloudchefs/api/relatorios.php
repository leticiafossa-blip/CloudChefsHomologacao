<?php
/**
 * Relatórios (somente gerente).
 *   GET /api/relatorios.php?tipo=vendas&inicio=2026-11-01&fim=2026-11-30
 *   GET /api/relatorios.php?tipo=produtos&inicio=...&fim=...
 */
require __DIR__ . '/_lib/bootstrap.php';

metodo(['GET']);
exigir_gerente();
$db = db();

$tipo = $_GET['tipo'] ?? '';
$inicio = data_param('inicio');
$fim = data_param('fim');
if (!$inicio || !$fim) {
    erro('Informe a data de início e a data de fim.');
}
if ($inicio > $fim) {
    erro('A data de início precisa ser antes da data de fim.');
}

// Só conta pedidos pagos e não cancelados.
$periodo = "p.data_pedido >= :inicio::date AND p.data_pedido < (:fim::date + INTERVAL '1 day')
            AND p.status <> 'Cancelado' AND p.status_pagamento = 'aprovado'";
$params = [':inicio' => $inicio, ':fim' => $fim];

if ($tipo === 'vendas') {
    $stmt = $db->prepare("
        SELECT DATE(p.data_pedido) AS data, COUNT(*) AS pedidos, SUM(p.valor_total) AS valor_total
        FROM pedido p WHERE $periodo
        GROUP BY DATE(p.data_pedido) ORDER BY data
    ");
    $stmt->execute($params);
    $linhas = array_map(fn($l) => [
        'data'        => $l['data'],
        'pedidos'     => (int) $l['pedidos'],
        'valor_total' => (float) $l['valor_total'],
    ], $stmt->fetchAll());
} elseif ($tipo === 'produtos') {
    $stmt = $db->prepare("
        SELECT i.nome_produto, SUM(i.quantidade) AS quantidade, SUM(i.valor_unitario * i.quantidade) AS receita
        FROM itens_pedido i JOIN pedido p ON p.id_pedido = i.id_pedido_fk
        WHERE $periodo
        GROUP BY i.nome_produto ORDER BY quantidade DESC, receita DESC
    ");
    $stmt->execute($params);
    $linhas = array_map(fn($l) => [
        'nome_produto' => $l['nome_produto'],
        'quantidade'   => (int) $l['quantidade'],
        'receita'      => (float) $l['receita'],
    ], $stmt->fetchAll());
} else {
    erro('Tipo de relatório inválido. Use "vendas" ou "produtos".');
}

responder(['success' => true, 'tipo' => $tipo, 'inicio' => $inicio, 'fim' => $fim, 'linhas' => $linhas]);
