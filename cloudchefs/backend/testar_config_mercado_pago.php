<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Cache-Control");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'connect.php';
require 'pix_funcoes.php';

try {
    $config = mp_get_config($db);

    // Endpoint simples apenas para validar se o token autentica na API.
    $resposta = mp_request('GET', '/users/me', $config['access_token']);

    echo json_encode([
        "success" => true,
        "message" => "Conexão com Mercado Pago funcionando.",
        "usuario_mp_id" => $resposta['id'] ?? null,
        "nickname" => $resposta['nickname'] ?? null,
        "site_id" => $resposta['site_id'] ?? null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
