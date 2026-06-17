const API_ESTOQUE = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/estoque.php";
const API_PRODUTOS = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/produtos.php";

let itensEstoque = [];

// 1. Busca os itens do estoque para preencher os selects
async function inicializarPagina() {
    // 1. Primeiro busca os dados da AWS
    await carregarEstoque(); 
    
    // 2. Só depois de ter os dados, cria a primeira linha na tela
    adicionarLinhaIngrediente(); 
}

async function carregarEstoque() {
    try {
        const res = await fetch("http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/estoque.php");
        const dados = await res.json();
        
        // Salva na variável global para as funções usarem
        itensEstoque = dados; 
        console.log("Ingredientes carregados:", itensEstoque);
    } catch (err) {
        console.error("Erro ao carregar ingredientes:", err);
        alert("Não foi possível carregar os itens do estoque.");
    }
}

// 2. Adiciona uma nova linha de ingrediente no formulário
function adicionarLinha() {
    const container = document.getElementById('lista-ingredientes');
    const div = document.createElement('div');
    div.className = 'ingrediente-row';

    const options = itensEstoque.map(item => 
        `<option value="${item.id_item}">${item.nome_item} (${item.unidade_medida})</option>`
    ).join('');

    div.innerHTML = `
        <select class="ing-select">${options}</select>
        <input type="number" class="ing-qtd" step="0.001" placeholder="Qtd">
        <button type="button" class="btn-remover" onclick="this.parentElement.remove()">X</button>
    `;
    container.appendChild(div);
}

// 3. Envia o produto e a receita para o backend
async function salvarProduto() {
    const nome = document.getElementById('nome').value;
    const preco = document.getElementById('preco').value;
    const imagem = document.getElementById('imagem').value;

    const ingredientes = [];
    document.querySelectorAll('.ingrediente-row').forEach(row => {
        const id = row.querySelector('.ing-select').value;
        const qtd = row.querySelector('.ing-qtd').value;
        if (id && qtd) {
            ingredientes.push({ id_item: id, quantidade: qtd });
        }
    });

    if (!nome || !preco || ingredientes.length === 0) {
        alert("Preencha o nome, preço e ao menos um ingrediente.");
        return;
    }

    try {
        const res = await fetch(API_PRODUTOS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nome, preco, imagem, ingredientes })
        });
        const data = await res.json();

        if (data.success) {
            alert("Produto e Composição cadastrados com sucesso!");
            window.location.reload();
        } else {
            alert("Erro: " + data.error);
        }
    } catch (err) {
        alert("Erro de conexão com o servidor.");
    }
}

document.addEventListener('DOMContentLoaded', carregarEstoque);