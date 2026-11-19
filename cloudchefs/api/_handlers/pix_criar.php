<?php
/**
 * POST /api/pix_criar.php  { id_pedido }
 * Gera a cobrança Pix no Mercado Pago para um pedido "Aguardando PIX".
 *
 * Se o Mercado Pago ainda não foi configurado, responde em MODO DEMONSTRAÇÃO:
 * nenhuma cobrança real é criada e a tela oferece um botão para simular o pagamento.
 */
require __DIR__ . '/../_lib/bootstrap.php';

metodo(['POST']);
exigir_login();
$db = db();
$idPedido = id(entrada(), 'id_pedido');

$stmt = $db->prepare('SELECT * FROM pedido WHERE id_pedido = :id');
$stmt->execute([':id' => $idPedido]);
$pedido = $stmt->fetch();
if (!$pedido) {
    erro('Pedido não encontrado.', 404);
}
if ($pedido['forma_pagamento'] !== 'PIX' || $pedido['status'] !== 'Aguardando PIX') {
    erro('Este pedido não está aguardando pagamento Pix.', 409);
}

$config = mp_config($db);
if (!$config) {
    responder([
        'success'     => true,
        'demo'        => true,
        'id_pedido'   => $idPedido,
        'valor_total' => (float) $pedido['valor_total'],
    ]);
}

// Se o QR Code já foi gerado antes, reaproveita (não cria cobrança duplicada).
if (!empty($pedido['mercado_pago_payment_id']) && !empty($pedido['pix_copia_cola'])) {
    responder([
        'success'        => true,
        'id_pedido'      => $idPedido,
        'valor_total'    => (float) $pedido['valor_total'],
        'qr_code_base64' => $pedido['pix_qr_code_base64'],
        'copia_cola'     => $pedido['pix_copia_cola'],
        'ticket_url'     => $pedido['pix_ticket_url'],
    ]);
}

$payload = [
    'transaction_amount' => (float) $pedido['valor_total'],
    'description'        => 'Pedido Cloud Chefs #' . $idPedido,
    'payment_method_id'  => 'pix',
    'external_reference' => (string) $idPedido,
    // O Mercado Pago exige um e-mail do pagador; o sistema não coleta e-mail do cliente.
    'payer' => [
        'email'      => "cliente{$idPedido}@cloudchefs.com.br",
        'first_name' => $pedido['nome_cliente'] ?: 'Cliente',
    ],
    'notification_url' => $config['webhook_url'] ?: url_webhook_padrao(),
];

$mp = mp_request('POST', '/v1/payments', $config['access_token'], $payload, 'cloudchefs-pedido-' . $idPedido);
$dados = $mp['point_of_interaction']['transaction_data'] ?? [];
if (empty($mp['id']) || empty($dados['qr_code'])) {
    erro('O Mercado Pago não devolveu o QR Code Pix.', 502);
}

$db->prepare('
    UPDATE pedido
    SET mercado_pago_payment_id = :pid, pix_copia_cola = :cc, pix_qr_code_base64 = :qr,
        pix_ticket_url = :url, status_pagamento = :sp, mp_status_detalhe = :det
    WHERE id_pedido = :id
')->execute([
    ':pid' => (string) $mp['id'],
    ':cc'  => $dados['qr_code'],
    ':qr'  => $dados['qr_code_base64'] ?? null,
    ':url' => $dados['ticket_url'] ?? null,
    ':sp'  => $mp['status'] ?? 'pending',
    ':det' => $mp['status_detail'] ?? null,
    ':id'  => $idPedido,
]);

responder([
    'success'        => true,
    'id_pedido'      => $idPedido,
    'valor_total'    => (float) $pedido['valor_total'],
    'qr_code_base64' => $dados['qr_code_base64'] ?? null,
    'copia_cola'     => $dados['qr_code'],
    'ticket_url'     => $dados['ticket_url'] ?? null,
]);
