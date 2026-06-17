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

$data = json_decode(file_get_contents("php://input"), true);

$publicKey = trim($data['public_key'] ?? '');
$accessTokenNovo = trim($data['access_token'] ?? '');
$webhookUrl = trim($data['webhook_url'] ?? '');
$webhookSecretNovo = trim($data['webhook_secret'] ?? '');
$ambiente = trim($data['ambiente'] ?? 'teste');

if (!in_array($ambiente, ['teste', 'producao'])) {
    $ambiente = 'teste';
}

try {
    $stmtAtual = $db->query("SELECT * FROM mercado_pago_config WHERE ativo = TRUE ORDER BY id_config DESC LIMIT 1");
    $configAtual = $stmtAtual->fetch(PDO::FETCH_ASSOC);

    $accessToken = $accessTokenNovo ?: ($configAtual['access_token'] ?? '');
    $webhookSecret = $webhookSecretNovo ?: ($configAtual['webhook_secret'] ?? '');

    if (!$accessToken) {
        echo json_encode([
            "success" => false,
            "error" => "Informe o Access Token do Mercado Pago."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $db->beginTransaction();

    $db->exec("UPDATE mercado_pago_config SET ativo = FALSE, atualizado_em = NOW() WHERE ativo = TRUE");

    $stmt = $db->prepare("
        INSERT INTO mercado_pago_config
            (public_key, access_token, webhook_url, webhook_secret, ambiente, ativo, criado_em, atualizado_em)
        VALUES
            (:public_key, :access_token, :webhook_url, :webhook_secret, :ambiente, TRUE, NOW(), NOW())
    ");

    $stmt->execute([
        ':public_key' => $publicKey,
        ':access_token' => $accessToken,
        ':webhook_url' => $webhookUrl,
        ':webhook_secret' => $webhookSecret,
        ':ambiente' => $ambiente
    ]);

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => "Configuração do Mercado Pago salva com sucesso."
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
