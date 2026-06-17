<?php
// ---- BLOCO DE CORREÇÃO CORS ----
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Cache-Control");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'connect.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "error" => "Nenhum dado recebido."]);
    exit();
}

$nome_cliente = $data['nome_cliente'] ?? 'Consumidor';
$produtos = $data['produtos'] ?? [];
$total = $data['total'] ?? 0;
$status = "Pendente";
$forma_pagamento = $data['forma_pagamento'] ?? '';

try {
    $db->beginTransaction();

    // 1. INSERIR PEDIDO PRINCIPAL
    $stmt = $db->prepare("
        INSERT INTO pedido (nome_cliente, data_pedido, valor_total, status, forma_pagamento)
        VALUES (:nome_cliente, NOW(), :valor_total, :status, :forma_pagamento)
        RETURNING id_pedido
    ");
    $stmt->execute([
        ':nome_cliente' => $nome_cliente,
        ':valor_total' => $total,
        ':status' => $status,
        ':forma_pagamento' => $forma_pagamento
    ]);
    $id_pedido = $stmt->fetchColumn();

    // 2. PREPARAR STATEMENTS PARA O LOOP (Ganha performance)
    // Inserir item
    $stmtItem = $db->prepare("
        INSERT INTO itens_pedido (id_pedido_fk, nome_produto, valor_unitario, quantidade)
        VALUES (:id_pedido, :nome_produto, :valor_unitario, :quantidade)
    ");

    // Buscar a receita (composição) do produto no banco
    $stmtReceita = $db->prepare("
        SELECT id_item_estoque, quantidade_necessaria 
        FROM composicao_produto 
        WHERE id_produto = (SELECT id_produto FROM produto WHERE nome = :nome_prod LIMIT 1)
    ");

    // Deduzir do estoque (pelo ID do item para ser mais preciso)
    $stmtEstoque = $db->prepare("
        UPDATE estoque 
        SET quantidade_atual = quantidade_atual - :qtd_deduzir 
        WHERE id_item = :id_item 
        AND quantidade_atual >= :qtd_deduzir
    ");

    // 3. PROCESSAR CADA PRODUTO DO CARRINHO
    foreach ($produtos as $p) {
        $nome_produto = $p['nome'];
        $quantidade_pedido = $p['quantidade'];

        // A. Salva na itens_pedido
        $stmtItem->execute([
            ':id_pedido' => $id_pedido,
            ':nome_produto' => $nome_produto,
            ':valor_unitario' => $p['preco'],
            ':quantidade' => $quantidade_pedido
        ]);

        // B. BUSCA A RECEITA DINÂMICA (Não usa mais o array fixo!)
        $stmtReceita->execute([':nome_prod' => $nome_produto]);
        $ingredientes = $stmtReceita->fetchAll(PDO::FETCH_ASSOC);

        // C. Se o produto tiver uma receita cadastrada, deduz o estoque
        if ($ingredientes) {
            foreach ($ingredientes as $ing) {
                $qtd_total_deduzir = $ing['quantidade_necessaria'] * $quantidade_pedido;
                
                $stmtEstoque->execute([
                    ':qtd_deduzir' => $qtd_total_deduzir,
                    ':id_item' => $ing['id_item_estoque']
                ]);

                // Verifica se tinha estoque suficiente
                if ($stmtEstoque->rowCount() === 0) {
                    throw new Exception("Estoque insuficiente para um dos ingredientes do produto: $nome_produto");
                }
            }
        }
        // Se não tiver receita cadastrada (ex: uma bala ou item avulso), 
        // ele apenas segue sem deduzir nada, conforme você pediu.
    }

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => "Pedido realizado! O estoque foi reduzido com base nas receitas do banco.",
        "id_pedido" => $id_pedido
    ]);

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>