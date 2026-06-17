<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require 'connect.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    // --- MÉTODO GET: LISTAR ESTOQUE ---
    if ($method === 'GET') {
        $stmt = $db->query("SELECT id_item, nome_item, quantidade_atual, unidade_medida FROM estoque ORDER BY nome_item ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // --- MÉTODO POST: ATUALIZAR OU SALVAR ESTOQUE ---
    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);

        // Captura todas as variações possíveis que o JavaScript pode ter enviado
        $id = $data['id_item'] ?? $data['id'] ?? null;
        $qtd = $data['quantidade_atual'] ?? $data['quantidade'] ?? $data['qtd'] ?? null;
        $nome = $data['nome_item'] ?? $data['nome'] ?? null;
        $unidade = $data['unidade_medida'] ?? $data['unidade'] ?? 'un';

        if ($qtd === null) {
            echo json_encode(["success" => false, "error" => "Quantidade não informada."]);
            exit;
        }

        // 🚀 A BLINDAGEM CONTRA O ERRO DE CHAVE DUPLICADA:
        // Se o frontend passou um nome (ex: "Pao"), verificamos se ele já existe no RDS.
        // Se já existir, interceptamos o ID dele e transformamos o INSERT em UPDATE na hora!
        if (!empty($nome)) {
            $stmtCheck = $db->prepare("SELECT id_item FROM estoque WHERE nome_item ILIKE ?");
            $stmtCheck->execute([trim($nome)]);
            $itemExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($itemExistente) {
                $id = $itemExistente['id_item']; // Força o ID do item que já existe
            }
        }

        // EXECUÇÃO SEGURA
        if ($id !== null && $id !== '') {
            // Caso A: O item já existe (ou descobrimos o ID pelo nome), faz o UPDATE
            $stmt = $db->prepare("UPDATE estoque SET quantidade_atual = :qtd WHERE id_item = :id");
            $stmt->execute([':qtd' => $qtd, ':id' => $id]);
            
            echo json_encode(["success" => true, "message" => "Estoque atualizado com sucesso!"]);
            exit;
        } else if (!empty($nome)) {
            // Caso B: É realmente um ingrediente novo, faz o INSERT
            $stmt = $db->prepare("INSERT INTO estoque (nome_item, quantidade_atual, unidade_medida) VALUES (:nome, :qtd, :unidade)");
            $stmt->execute([':nome' => $nome, ':qtd' => $qtd, ':unidade' => $unidade]);
            
            echo json_encode(["success" => true, "message" => "Novo item cadastrado com sucesso!"]);
            exit;
        }

        echo json_encode(["success" => false, "error" => "Dados insuficientes para processar."]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>