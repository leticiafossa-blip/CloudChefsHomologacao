<?php
require 'connect.php';

try {
    $nova_senha = password_hash('123', PASSWORD_BCRYPT);
    
    // Força a atualização do admin para a senha '123' com o hash correto
    $stmt = $db->prepare("UPDATE usuario SET senha = ? WHERE login = 'admin'");
    $stmt->execute([$nova_senha]);

    if ($stmt->rowCount() > 0) {
        echo "✅ Sucesso! Senha do admin resetada para '123' com BCRYPT.";
    } else {
        // Se o admin não existir, ele cria um
        $db->prepare("INSERT INTO usuario (nome, login, senha, tipo_usuario) VALUES ('Administrador', 'admin', ?, 'GERENTE')")
           ->execute([$nova_senha]);
        echo "🆕 Admin não existia e foi criado com a senha '123'.";
    }
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}
?>