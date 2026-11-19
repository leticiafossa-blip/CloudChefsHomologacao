<?php
/**
 * Produtos do cardápio.
 *   GET    /api/produtos.php            -> produtos ativos (qualquer usuário logado)
 *   GET    /api/produtos.php?todos=1    -> todos, inclusive inativos (gerente)
 *   GET    /api/produtos.php?id=5       -> um produto com a receita (gerente)
 *   POST   /api/produtos.php            -> cadastra (gerente)
 *   PUT    /api/produtos.php            -> altera (gerente)
 */
require __DIR__ . '/../_lib/bootstrap.php';

$metodo = metodo(['GET', 'POST', 'PUT']);
$db = db();

const CATEGORIAS = ['lanche', 'acompanhamento', 'bebida'];

function formatar_produto(array $p): array
{
    return [
        'id_produto' => (int) $p['id_produto'],
        'nome'       => $p['nome'],
        'preco'      => (float) $p['preco'],
        'categoria'  => $p['categoria'],
        'imagem'     => $p['imagem'],
        'status'     => (bool) $p['status'],
    ];
}

/** Valida e grava a receita (lista de ingredientes) de um produto. */
function salvar_receita(PDO $db, int $idProduto, array $ingredientes): void
{
    $db->prepare('DELETE FROM composicao_produto WHERE id_produto = :id')->execute([':id' => $idProduto]);
    $stmt = $db->prepare('
        INSERT INTO composicao_produto (id_produto, id_item_estoque, quantidade_necessaria)
        VALUES (:p, :e, :q)
        ON CONFLICT (id_produto, id_item_estoque) DO UPDATE SET quantidade_necessaria = EXCLUDED.quantidade_necessaria
    ');
    foreach ($ingredientes as $ing) {
        if (!is_array($ing)) {
            erro('Lista de ingredientes inválida.');
        }
        $stmt->execute([
            ':p' => $idProduto,
            ':e' => id($ing, 'id_item'),
            ':q' => numero($ing, 'quantidade', 0.001, 100000),
        ]);
    }
}

function ler_produto(array $dados): array
{
    $categoria = texto($dados, 'categoria', false, 20) ?? 'lanche';
    if (!in_array($categoria, CATEGORIAS, true)) {
        erro('Categoria inválida.');
    }
    $imagem = texto($dados, 'imagem', false, 255);
    if ($imagem !== null && !preg_match('#^(img/[\w.-]+|https://\S+)$#', $imagem)) {
        erro('Imagem inválida: use um arquivo da pasta img/ ou um endereço https://');
    }
    $ingredientes = $dados['ingredientes'] ?? [];
    if (!is_array($ingredientes)) {
        erro('Lista de ingredientes inválida.');
    }
    return [
        'nome'         => texto($dados, 'nome'),
        'preco'        => round(numero($dados, 'preco', 0.01, 10000), 2),
        'categoria'    => $categoria,
        'imagem'       => $imagem,
        'ingredientes' => $ingredientes,
    ];
}

if ($metodo === 'GET') {
    if (isset($_GET['id'])) {
        exigir_gerente();
        $stmt = $db->prepare('SELECT * FROM produto WHERE id_produto = :id');
        $stmt->execute([':id' => id($_GET, 'id')]);
        $produto = $stmt->fetch();
        if (!$produto) {
            erro('Produto não encontrado.', 404);
        }
        $receita = $db->prepare('
            SELECT c.id_item_estoque AS id_item, e.nome_item, e.unidade_medida, c.quantidade_necessaria AS quantidade
            FROM composicao_produto c JOIN estoque e ON e.id_item = c.id_item_estoque
            WHERE c.id_produto = :id ORDER BY e.nome_item
        ');
        $receita->execute([':id' => $produto['id_produto']]);
        $resultado = formatar_produto($produto);
        $resultado['ingredientes'] = array_map(fn($r) => [
            'id_item'        => (int) $r['id_item'],
            'nome_item'      => $r['nome_item'],
            'unidade_medida' => $r['unidade_medida'],
            'quantidade'     => (float) $r['quantidade'],
        ], $receita->fetchAll());
        responder(['success' => true, 'produto' => $resultado]);
    }

    if (!empty($_GET['todos'])) {
        exigir_gerente();
        $lista = $db->query('SELECT * FROM produto ORDER BY categoria, nome')->fetchAll();
    } else {
        exigir_login();
        $lista = $db->query('SELECT * FROM produto WHERE status ORDER BY categoria, nome')->fetchAll();
    }
    responder(['success' => true, 'produtos' => array_map('formatar_produto', $lista)]);
}

exigir_gerente();
$dados = entrada();
$p = ler_produto($dados);

$db->beginTransaction();
if ($metodo === 'POST') {
    $stmt = $db->prepare('
        INSERT INTO produto (nome, preco, categoria, imagem, status)
        VALUES (:nome, :preco, :categoria, :imagem, TRUE)
        RETURNING id_produto
    ');
    $stmt->execute([':nome' => $p['nome'], ':preco' => $p['preco'], ':categoria' => $p['categoria'], ':imagem' => $p['imagem']]);
    $idProduto = (int) $stmt->fetchColumn();
} else {
    $idProduto = id($dados, 'id_produto');
    $stmt = $db->prepare('
        UPDATE produto SET nome = :nome, preco = :preco, categoria = :categoria, imagem = :imagem, status = :status
        WHERE id_produto = :id
    ');
    $stmt->bindValue(':nome', $p['nome']);
    $stmt->bindValue(':preco', $p['preco']);
    $stmt->bindValue(':categoria', $p['categoria']);
    $stmt->bindValue(':imagem', $p['imagem']);
    $stmt->bindValue(':status', !empty($dados['status']), PDO::PARAM_BOOL);
    $stmt->bindValue(':id', $idProduto, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        erro('Produto não encontrado.', 404);
    }
}
salvar_receita($db, $idProduto, $p['ingredientes']);
$db->commit();

responder(['success' => true, 'id_produto' => $idProduto]);
