<?php
require 'connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$method = $_SERVER['REQUEST_METHOD'];

try {
    // --- MÉTODO GET: LISTAR PRODUTOS ---
    if ($method === 'GET') {
        // CORREÇÃO: Usando 'status' em vez de 'ativo'
        $stmt = $db->query("SELECT * FROM produto WHERE status = true ORDER BY id_produto ASC");
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($produtos);
    }

    // --- MÉTODO POST: SALVAR NOVO PRODUTO ---
    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['nome']) || empty($data['preco'])) {
            echo json_encode(["success" => false, "error" => "Nome e Preço são obrigatórios"]);
            exit;
        }

        $db->beginTransaction();

        // CORREÇÃO: Trocado 'ativo' por 'status' no INSERT
        $sqlProd = "INSERT INTO produto (nome, preco, imagem, status) VALUES (:nome, :preco, :imagem, true)";
        $stmtProd = $db->prepare($sqlProd);
        $stmtProd->execute([
            ':nome'   => $data['nome'],
            ':preco'  => $data['preco'],
            ':imagem' => $data['imagem'] ?? null
        ]);

        $idProduto = $db->lastInsertId();

        if (!empty($data['ingredientes'])) {
            $sqlComp = "INSERT INTO composicao_produto (id_produto, id_item_estoque, quantidade_necessaria) 
                        VALUES (:id_p, :id_e, :qtd)";
            $stmtComp = $db->prepare($sqlComp);

            foreach ($data['ingredientes'] as $ing) {
                $stmtComp->execute([
                    ':id_p' => $idProduto,
                    ':id_e' => $ing['id_item'],
                    ':qtd'  => $ing['quantidade']
                ]);
            }
        }

        $db->commit();
        echo json_encode(["success" => true, "id_produto" => $idProduto]);
    }

} catch (Exception $e) {
    if ($method === 'POST' && $db->inTransaction()) $db->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>