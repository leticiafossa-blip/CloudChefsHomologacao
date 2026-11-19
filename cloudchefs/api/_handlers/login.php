<?php
// POST /api/login.php  { login, senha }
require __DIR__ . '/../_lib/bootstrap.php';

metodo(['POST']);
$dados = entrada();
$login = strtolower(texto($dados, 'login', true, 60));
$senha = (string) ($dados['senha'] ?? '');
if ($senha === '') {
    erro('Informe a senha.');
}

garantir_usuarios_iniciais();

$stmt = db()->prepare('SELECT id_usuario, nome, login, senha, tipo_usuario FROM usuario WHERE lower(login) = :login AND ativo');
$stmt->execute([':login' => $login]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha'])) {
    usleep(400000); // atrasa tentativas de adivinhar senha
    erro('Usuário ou senha inválidos.', 401);
}

if (password_needs_rehash($usuario['senha'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE usuario SET senha = :s WHERE id_usuario = :id')
        ->execute([':s' => password_hash($senha, PASSWORD_DEFAULT), ':id' => $usuario['id_usuario']]);
}

iniciar_sessao($usuario);
unset($usuario['senha']);
responder(['success' => true, 'usuario' => $usuario]);
