<?php
/**
 * Financeiro (somente gerente).
 *   GET /api/financeiro.php?forma=PIX&inicio=2026-11-01&fim=2026-11-30
 */
require __DIR__ . '/_lib/bootstrap.php';

metodo(['GET']);
exigir_gerente();
$db = db();

$filtros = ["status <> 'Aguardando PIX'"];
$params = [];

$forma = trim((string) ($_GET['forma'] ?? ''));
if ($forma !== '') {
    if (!in_array($forma, ['Dinheiro', 'Debito', 'Credito', 'PIX'], true)) {
        erro('Forma de pagamento inválida.');
    }
    $filtros[] = 'forma_pagamento = :forma';
    $params[':forma'] = $forma;
}
if ($inicio = data_param('inicio')) {
    $filtros[] = 'data_pedido >= :inicio::date';
    $params[':inicio'] = $inicio;
}
if ($fim = data_param('fim')) {
    $filtros[] = "data_pedido < (:fim::date + INTERVAL '1 day')";
    $params[':fim'] = $fim;
}

$stmt = $db->prepare('SELECT * FROM pedido WHERE ' . implode(' AND ', $filtros) . ' ORDER BY data_pedido DESC LIMIT 1000');
$stmt->execute($params);
$pedidos = array_map('formatar_pedido', $stmt->fetchAll());

// Resumo: só entra o que foi pago e não foi cancelado.
$validos = array_filter($pedidos, fn($p) => $p['status'] !== 'Cancelado' && $p['status_pagamento'] === 'aprovado');
$total = array_sum(array_column($validos, 'valor_total'));
$porForma = [];
foreach ($validos as $p) {
    $porForma[$p['forma_pagamento']] = round(($porForma[$p['forma_pagamento']] ?? 0) + $p['valor_total'], 2);
}

responder([
    'success' => true,
    'pedidos' => $pedidos,
    'resumo'  => [
        'quantidade'   => count($validos),
        'total'        => round($total, 2),
        'ticket_medio' => count($validos) ? round($total / count($validos), 2) : 0,
        'por_forma'    => $porForma,
        'cancelados'   => count(array_filter($pedidos, fn($p) => $p['status'] === 'Cancelado')),
    ],
]);
