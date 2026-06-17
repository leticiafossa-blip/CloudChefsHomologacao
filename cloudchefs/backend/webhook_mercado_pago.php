<?php
// URL pública para cadastrar no Mercado Pago, por exemplo:
// https://seudominio.com/Cloudchefs2026/cloudchefs/backend/webhook_mercado_pago.php

header("Content-Type: application/json; charset=utf-8");

require 'connect.php';
require 'pix_funcoes.php';

try {
    $config = mp_get_config($db);

    // Validação de assinatura, se você preencher o webhook_secret na tela do gerente.
    // Se deixar vazio, o webhook funciona sem validar assinatura, útil para trabalho/teste.
    if (!empty($config['webhook_secret']) && !validar_assinatura_webhook_mp($config['webhook_secret'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Assinatura inválida."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $payload = json_decode(file_get_contents("php://input"), true) ?: [];

    $paymentId = $payload['data']['id']
        ?? $_GET['data.id']
        ?? $_GET['id']
        ?? null;

    $type = $payload['type'] ?? $_GET['type'] ?? '';

    if (!$paymentId || ($type && $type !== 'payment')) {
        echo json_encode(["success" => true, "message" => "Notificação ignorada."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $resultado = sincronizar_pagamento_mp($db, $paymentId, null);

    echo json_encode([
        "success" => true,
        "message" => "Pagamento sincronizado.",
        "resultado" => $resultado
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // O Mercado Pago pode reenviar se receber erro; para depurar, veja logs do servidor.
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
