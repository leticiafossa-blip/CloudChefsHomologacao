<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Cache-Control");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'connect.php';
require 'pix_funcoes.php';

$data = json_decode(file_get_contents("php://input"), true);
$idPedido = $data['id_pedido'] ?? null;

if (!$idPedido) {
    echo json_encode(["success" => false, "error" => "ID do pedido não informado."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $config = mp_get_config($db);

    $stmt = $db->prepare("SELECT * FROM pedido WHERE id_pedido = :id");
    $stmt->execute([':id' => $idPedido]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        throw new Exception("Pedido não encontrado.");
    }

    if (strtoupper($pedido['forma_pagamento'] ?? '') !== 'PIX') {
        throw new Exception("Este pedido não foi criado com forma de pagamento PIX.");
    }

    // Se o QR Code já foi criado antes, não cria outro pagamento duplicado.
    if (!empty($pedido['mercado_pago_payment_id']) && !empty($pedido['pix_copia_cola'])) {
        echo json_encode([
            "success" => true,
            "message" => "QR Code Pix já existente para este pedido.",
            "id_pedido" => $idPedido,
            "payment_id" => $pedido['mercado_pago_payment_id'],
            "qr_code_base64" => $pedido['pix_qr_code_base64'],
            "copia_cola" => $pedido['pix_copia_cola'],
            "ticket_url" => $pedido['pix_ticket_url']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $valor = (float) $pedido['valor_total'];
    $nomeCliente = trim($pedido['nome_cliente'] ?? 'Cliente');

    // O Mercado Pago exige e-mail do pagador no payload. Como seu sistema não coleta e-mail,
    // usamos um e-mail técnico apenas para identificar o pedido.
    $emailPagador = "cliente{$idPedido}@cloudchefs.com.br";

    $payload = [
        "transaction_amount" => $valor,
        "description" => "Pedido Cloud Chefs #" . $idPedido,
        "payment_method_id" => "pix",
        "external_reference" => (string) $idPedido,
        "payer" => [
            "email" => $emailPagador,
            "first_name" => $nomeCliente ?: "Cliente"
        ]
    ];

    if (!empty($config['webhook_url'])) {
        $payload["notification_url"] = $config['webhook_url'];
    }

    $idempotencyKey = "cloudchefs-pedido-" . $idPedido;
    $mp = mp_request('POST', '/v1/payments', $config['access_token'], $payload, $idempotencyKey);

    $paymentId = $mp['id'] ?? null;
    $transactionData = $mp['point_of_interaction']['transaction_data'] ?? [];
    $qrCode = $transactionData['qr_code'] ?? null;
    $qrCodeBase64 = $transactionData['qr_code_base64'] ?? null;
    $ticketUrl = $transactionData['ticket_url'] ?? null;

    if (!$paymentId || !$qrCode) {
        throw new Exception("Mercado Pago não retornou os dados do QR Code Pix.");
    }

    $stmtUpdate = $db->prepare("
        UPDATE pedido
        SET mercado_pago_payment_id = :payment_id,
            pix_copia_cola = :copia_cola,
            pix_qr_code_base64 = :qr_base64,
            pix_ticket_url = :ticket_url,
            status_pagamento = :status_pagamento,
            mp_status_detalhe = :status_detalhe
        WHERE id_pedido = :id
    ");

    $stmtUpdate->execute([
        ':payment_id' => $paymentId,
        ':copia_cola' => $qrCode,
        ':qr_base64' => $qrCodeBase64,
        ':ticket_url' => $ticketUrl,
        ':status_pagamento' => $mp['status'] ?? 'pending',
        ':status_detalhe' => $mp['status_detail'] ?? null,
        ':id' => $idPedido
    ]);

    echo json_encode([
        "success" => true,
        "id_pedido" => $idPedido,
        "payment_id" => $paymentId,
        "qr_code_base64" => $qrCodeBase64,
        "copia_cola" => $qrCode,
        "ticket_url" => $ticketUrl,
        "status" => $mp['status'] ?? 'pending'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
