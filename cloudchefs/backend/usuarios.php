<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require 'connect.php';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === "GET") {
        $stmt = $db->query("SELECT id_usuario, nome, login, tipo_usuario FROM usuario ORDER BY id_usuario ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === "POST") {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $nome = $data['nome'] ?? null;
        $login = $data['login'] ?? null;
        $senhaRaw = $data['senha'] ?? null;
        $tipo = $data['tipo_usuario'] ?? 'colaborador';

        if (!$nome || !$login || !$senhaRaw) {
            echo json_encode(["success" => false, "error" => "Dados incompletos: nome, login e senha sao obrigatorios"]);
            exit;
        }

        // Criptografia da senha
        $senhaHash = password_hash($senhaRaw, PASSWORD_BCRYPT);
        
        // SQL com parâmetros nomeados para evitar erros de ordem
        $sql = "INSERT INTO usuario (nome, login, senha, tipo_usuario) 
                VALUES (:nome, :login, :senha, :tipo)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':nome'  => $nome,
            ':login' => $login,
            ':senha' => $senhaHash,
            ':tipo'  => $tipo
        ]);

        echo json_encode(["success" => true, "message" => "Usuario criado com sucesso"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Erro no banco: " . $e->getMessage()]);
}
?>