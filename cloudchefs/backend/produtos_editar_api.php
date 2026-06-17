<?php
require 'connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = $_GET['id'];
        // Busca produto
        $stmt = $db->prepare("SELECT * FROM produto WHERE id_produto = ?");
        $stmt->execute([$id]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        // Busca ingredientes da receita
        $stmtComp = $db->prepare("SELECT id_item_estoque, quantidade_necessaria FROM composicao_produto WHERE id_produto = ?");
        $stmtComp->execute([$id]);
        $produto['ingredientes'] = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($produto);
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        $db->beginTransaction();

        // 1. Atualiza Produto
        $stmt = $db->prepare("UPDATE produto SET nome = ?, preco = ?, status = ? WHERE id_produto = ?");
        $stmt->execute([$data['nome'], $data['preco'], $data['status'], $data['id_produto']]);

        // 2. Limpa receita antiga e insere a nova
        $db->prepare("DELETE FROM composicao_produto WHERE id_produto = ?")->execute([$data['id_produto']]);
        
        $sqlComp = "INSERT INTO composicao_produto (id_produto, id_item_estoque, quantidade_necessaria) VALUES (?, ?, ?)";
        $stmtComp = $db->prepare($sqlComp);
        foreach ($data['ingredientes'] as $ing) {
            $stmtComp->execute([$data['id_produto'], $ing['id_item'], $ing['quantidade']]);
        }

        $db->commit();
        echo json_encode(["success" => true]);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}