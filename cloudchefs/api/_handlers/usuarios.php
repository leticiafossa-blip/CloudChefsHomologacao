<?php
/**
 * Colaboradores (somente gerente).
 *   GET    /api/usuarios.php  -> lista
 *   POST   /api/usuarios.php  -> cadastra { nome, login, senha, tipo_usuario }
 *   PUT    /api/usuarios.php  -> altera { id_usuario, tipo_usuario?, senha? }
 *   DELETE /api/usuarios.php  -> remove { id_usuario }
 */
require __DIR__ . '/../_lib/bootstrap.php';

$metodo = metodo(['GET', 'POST', 'PUT', 'DELETE']);
$eu = exigir_gerente();
$db = db();

const PERFIS = ['GERENTE', 'ATENDENTE'];
const SENHA_MINIMA = 6;

function perfil(array $dados): string
{
    $tipo = strtoupper(texto($dados, 'tipo_usuario', false, 20) ?? 'ATENDENTE');
    if (!in_array($tipo, PERFIS, true)) {
        erro('Perfil inválido. Use GERENTE ou ATENDENTE.');
    }
    return $tipo;
}

function senha_valida(array $dados): string
{
    $senha = (string) ($dados['senha'] ?? '');
    if (mb_strlen($senha) < SENHA_MINIMA) {
        erro('A senha precisa ter pelo menos ' . SENHA_MINIMA . ' caracteres.');
    }
    return $senha;
}

if ($metodo === 'GET') {
    $lista = $db->query('SELECT id_usuario, nome, login, tipo_usuario FROM usuario WHERE ativo ORDER BY nome')->fetchAll();
    foreach ($lista as &$u) {
        $u['id_usuario'] = (int) $u['id_usuario'];
        $u['sou_eu'] = $u['id_usuario'] === (int) $eu['id_usuario'];
    }
    responder(['success' => true, 'usuarios' => $lista]);
}

$dados = entrada();

if ($metodo === 'POST') {
    $login = strtolower(texto($dados, 'login', true, 60));
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $login)) {
        erro('O login deve ter de 3 a 60 caracteres: letras, números, ponto, hífen ou sublinhado.');
    }
    $stmt = $db->prepare('
        INSERT INTO usuario (nome, login, senha, tipo_usuario)
        VALUES (:nome, :login, :senha, :tipo) RETURNING id_usuario
    ');
    $stmt->execute([
        ':nome'  => texto($dados, 'nome'),
        ':login' => $login,
        ':senha' => password_hash(senha_valida($dados), PASSWORD_DEFAULT),
        ':tipo'  => perfil($dados),
    ]);
    responder(['success' => true, 'id_usuario' => (int) $stmt->fetchColumn()], 201);
}

$idUsuario = id($dados, 'id_usuario');
$ehEu = $idUsuario === (int) $eu['id_usuario'];

if ($metodo === 'DELETE') {
    if ($ehEu) {
        erro('Você não pode excluir o seu próprio usuário.');
    }
    $stmt = $db->prepare('DELETE FROM usuario WHERE id_usuario = :id');
    $stmt->execute([':id' => $idUsuario]);
    if ($stmt->rowCount() === 0) {
        erro('Usuário não encontrado.', 404);
    }
    responder(['success' => true]);
}

// PUT
$campos = [];
$params = [':id' => $idUsuario];
if (isset($dados['tipo_usuario'])) {
    $tipo = perfil($dados);
    if ($ehEu && $tipo !== 'GERENTE') {
        erro('Você não pode tirar a sua própria permissão de gerente.');
    }
    $campos[] = 'tipo_usuario = :tipo';
    $params[':tipo'] = $tipo;
}
if (isset($dados['senha']) && $dados['senha'] !== '') {
    $campos[] = 'senha = :senha';
    $params[':senha'] = password_hash(senha_valida($dados), PASSWORD_DEFAULT);
}
if (!$campos) {
    erro('Nada para alterar.');
}
$stmt = $db->prepare('UPDATE usuario SET ' . implode(', ', $campos) . ' WHERE id_usuario = :id');
$stmt->execute($params);
if ($stmt->rowCount() === 0) {
    erro('Usuário não encontrado.', 404);
}
responder(['success' => true]);
