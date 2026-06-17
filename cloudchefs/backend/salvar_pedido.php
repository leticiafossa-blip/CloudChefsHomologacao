<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Cache-Control");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'connect.php';
require 'pix_funcoes.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "error" => "Nenhum dado recebido."], JSON_UNESCAPED_UNICODE);
    exit();
}

$nome_cliente = trim($data['nome_cliente'] ?? 'Consumidor');
$produtos = $data['produtos'] ?? [];
$total = (float) ($data['total'] ?? 0);
$forma_pagamento = trim($data['forma_pagamento'] ?? '');

if (empty($produtos)) {
    echo json_encode(["success" => false, "error" => "O pedido precisa ter pelo menos um produto."], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($total <= 0) {
    echo json_encode(["success" => false, "error" => "Total inválido."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $ehPix = strtoupper($forma_pagamento) === 'PIX';

    // Para PIX, o pedido fica aguardando pagamento e só entra na produção depois de aprovado.
    $status = $ehPix ? "Aguardando PIX" : "Pendente";
    $status_pagamento = $ehPix ? "pendente" : "aprovado";
  

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO pedido
            (nome_cliente, data_pedido, valor_total, status, forma_pagamento, status_pagamento, estoque_baixado)
        VALUES
            (:nome_cliente, NOW(), :valor_total, :status, :forma_pagamento, :status_pagamento, FALSE)
        RETURNING id_pedido
    ");

    $stmt->execute([
        ':nome_cliente' => $nome_cliente ?: 'Consumidor',
        ':valor_total' => $total,
        ':status' => $status,
        ':forma_pagamento' => $forma_pagamento,
        ':status_pagamento' => $status_pagamento
    ]);

    $id_pedido = $stmt->fetchColumn();

    $stmtItem = $db->prepare("
        INSERT INTO itens_pedido (id_pedido_fk, nome_produto, valor_unitario, quantidade)
        VALUES (:id_pedido, :nome_produto, :valor_unitario, :quantidade)
    ");

    foreach ($produtos as $p) {
        $stmtItem->execute([
            ':id_pedido' => $id_pedido,
            ':nome_produto' => $p['nome'],
            ':valor_unitario' => $p['preco'],
            ':quantidade' => $p['quantidade']
        ]);
    }

    // Para Dinheiro/Débito/Crédito, mantém o comportamento antigo: baixa estoque na hora.
    // Para PIX, baixa apenas quando o pagamento for aprovado.
    if (!$ehPix) {
        baixar_estoque_do_pedido($db, $id_pedido);
    }

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => $ehPix
            ? "Pedido criado. Agora gere o QR Code Pix para pagamento."
            : "Pedido realizado! O estoque foi reduzido com base nas receitas do banco.",
        "id_pedido" => $id_pedido,
        "pix" => $ehPix
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
