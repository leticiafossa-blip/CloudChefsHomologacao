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
    $resultado = sincronizar_pagamento_mp($db, null, $idPedido);

    echo json_encode([
        "success" => true,
        "id_pedido" => $resultado['id_pedido'],
        "payment_id" => $resultado['payment_id'],
        "status" => $resultado['status'],
        "status_detail" => $resultado['status_detail'],
        "pago" => $resultado['pago']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
