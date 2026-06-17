<?php
// ---- INÍCIO DO BLOCO DE CORREÇÃO CORS (Obrigatório) ----
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Cache-Control");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Permite POST, GET e OPTIONS
header("Content-Type: application/json; charset=utf-8");

// Responde ao "preflight"
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ---- FIM DO BLOCO DE CORREÇÃO CORS ----


// ---- O SEU CÓDIGO ANTIGO COMEÇA A PARTIR DAQUI ----
require 'connect.php';
// ... o seu bloco de CORS e require 'connect.php' continua igualzinho em cima ...

$data = json_decode(file_get_contents("php://input"), true);

$login = $data['login'] ?? null;
$senha = $data['senha'] ?? null;

if (!$login || !$senha) {
    echo json_encode(["success" => false, "error" => "Login e senha são obrigatórios"]);
    exit();
}

try {
    $stmt = $db->prepare("SELECT * FROM usuario WHERE login ILIKE :login");
    $stmt->execute([':login' => $login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($senha, $usuario['senha'])){
        unset($usuario['senha']); // Apaga a senha antes de mandar pro frontend (Boa prática!)
        
        echo json_encode([
            "success" => true, 
            // 🚀 MUDANÇA AQUI: Enviando como 'user' e 'usuario' para garantir que o JS vai achar, independente de qual ele busque!
            "user" => $usuario,
            "usuario" => $usuario 
        ]);
    } else {
        echo json_encode(["success" => false, "error" => "Usuário ou senha inválidos"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>