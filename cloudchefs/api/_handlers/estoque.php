<?php
/**
 * Estoque de ingredientes.
 *   GET   /api/estoque.php   -> lista (qualquer usuário logado)
 *   POST  /api/estoque.php   -> cadastra item novo (gerente)
 *         { nome_item, unidade_medida, quantidade_atual, quantidade_minima }
 *   PATCH /api/estoque.php   -> movimenta ou ajusta um item (gerente)
 *         { id_item, operacao: "adicionar" | "remover" | "definir", quantidade }
 *         { id_item, quantidade_minima }
 */
require __DIR__ . '/../_lib/bootstrap.php';

$metodo = metodo(['GET', 'POST', 'PATCH']);
$db = db();

const UNIDADES = ['un', 'kg', 'g', 'L', 'ml'];

function formatar_item(array $i): array
{
    return [
        'id_item'           => (int) $i['id_item'],
        'nome_item'         => $i['nome_item'],
        'quantidade_atual'  => (float) $i['quantidade_atual'],
        'quantidade_minima' => (float) $i['quantidade_minima'],
        'unidade_medida'    => $i['unidade_medida'],
    ];
}

if ($metodo === 'GET') {
    exigir_login();
    $itens = $db->query('SELECT * FROM estoque ORDER BY nome_item')->fetchAll();
    responder(['success' => true, 'itens' => array_map('formatar_item', $itens)]);
}

exigir_gerente();
$dados = entrada();

if ($metodo === 'POST') {
    $unidade = texto($dados, 'unidade_medida', false, 10) ?? 'un';
    if (!in_array($unidade, UNIDADES, true)) {
        erro('Unidade inválida.');
    }
    $qtd = numero($dados, 'quantidade_atual', 0, 1000000);
    if ($unidade === 'un' && floor($qtd) != $qtd) {
        erro('Itens em unidade (un) só aceitam números inteiros.');
    }
    $stmt = $db->prepare('
        INSERT INTO estoque (nome_item, unidade_medida, quantidade_atual, quantidade_minima)
        VALUES (:nome, :unidade, :qtd, :minima)
        RETURNING *
    ');
    $stmt->execute([
        ':nome'    => texto($dados, 'nome_item'),
        ':unidade' => $unidade,
        ':qtd'     => $qtd,
        ':minima'  => isset($dados['quantidade_minima']) ? numero($dados, 'quantidade_minima', 0, 1000000) : 10,
    ]);
    responder(['success' => true, 'item' => formatar_item($stmt->fetch())], 201);
}

// PATCH
$idItem = id($dados, 'id_item');

$db->beginTransaction();
$stmt = $db->prepare('SELECT * FROM estoque WHERE id_item = :id FOR UPDATE');
$stmt->execute([':id' => $idItem]);
$item = $stmt->fetch();
if (!$item) {
    erro('Item não encontrado.', 404);
}

$atual = (float) $item['quantidade_atual'];
$nova = $atual;
$minima = (float) $item['quantidade_minima'];

if (isset($dados['operacao'])) {
    $qtd = numero($dados, 'quantidade', 0, 1000000);
    if ($item['unidade_medida'] === 'un' && floor($qtd) != $qtd) {
        erro('Itens em unidade (un) só aceitam números inteiros.');
    }
    $nova = match ($dados['operacao']) {
        'adicionar' => $atual + $qtd,
        'remover'   => $atual - $qtd,
        'definir'   => $qtd,
        default     => erro('Operação inválida.'),
    };
    if ($nova < 0) {
        erro("Não há {$item['nome_item']} suficiente para remover essa quantidade.", 409);
    }
}
if (isset($dados['quantidade_minima'])) {
    $minima = numero($dados, 'quantidade_minima', 0, 1000000);
}

$stmt = $db->prepare('UPDATE estoque SET quantidade_atual = :q, quantidade_minima = :m WHERE id_item = :id RETURNING *');
$stmt->execute([':q' => $nova, ':m' => $minima, ':id' => $idItem]);
$atualizado = $stmt->fetch();
$db->commit();

responder(['success' => true, 'item' => formatar_item($atualizado)]);
