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

try {
    $stmt = $db->query("SELECT * FROM mercado_pago_config WHERE ativo = TRUE ORDER BY id_config DESC LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        echo json_encode([
            "success" => true,
            "configurado" => false
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $token = $config['access_token'] ?? '';
    $tokenMascarado = '';

    if ($token) {
        $inicio = substr($token, 0, 8);
        $fim = substr($token, -6);
        $tokenMascarado = $inicio . '...' . $fim;
    }

    echo json_encode([
        "success" => true,
        "configurado" => true,
        "public_key" => $config['public_key'] ?? '',
        "access_token_mascarado" => $tokenMascarado,
        "possui_access_token" => !empty($token),
        "webhook_url" => $config['webhook_url'] ?? '',
        "possui_webhook_secret" => !empty($config['webhook_secret']),
        "ambiente" => $config['ambiente'] ?? 'teste'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
