<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'connect.php';

try {
    // Não mostra pedidos que ainda aguardam pagamento Pix na fila da cozinha.
    $stmt = $db->query("
        SELECT *
        FROM pedido
        WHERE COALESCE(status, '') <> 'Aguardando PIX'
        ORDER BY id_pedido DESC
        LIMIT 10
    ");

    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pedidos as &$pedido) {
        $id = $pedido['id_pedido'] ?? $pedido['id'] ?? 0;
        $pedido['id_pedido'] = $id;
        $pedido['nome_cliente'] = $pedido['nome_cliente'] ?? $pedido['nome'] ?? 'Cliente';
        $pedido['valor_total'] = $pedido['valor_total'] ?? $pedido['total'] ?? 0;
        $pedido['status'] = $pedido['status'] ?? 'Pendente';

        try {
            $stmtItem = $db->prepare("SELECT * FROM itens_pedido WHERE id_pedido_fk = ?");
            $stmtItem->execute([$id]);
            $itens = $stmtItem->fetchAll(PDO::FETCH_ASSOC);
            $pedido['itens'] = $itens ?: [["nome_produto" => "Pedido #$id", "quantidade" => 1]];
        } catch (Exception $e) {
            $pedido['itens'] = [["nome_produto" => "Pedido #$id", "quantidade" => 1]];
        }
    }

    echo json_encode($pedidos, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
