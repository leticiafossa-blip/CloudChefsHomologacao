const API_PRODUTOS = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/produtos.php";
const API_ESTOQUE = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/estoque.php";

let itensEstoqueGlobais = [];

// Busca itens do estoque ao carregar a página
async function carregarItensEstoque() {
    const res = await fetch(API_ESTOQUE);
    itensEstoqueGlobais = await res.json();
}

function adicionarLinhaIngrediente() {
    const div = document.getElementById('lista-ingredientes');
    const row = document.createElement('div');
    row.className = 'ingrediente-row';
    
    let options = itensEstoqueGlobais.map(i => `<option value="${i.id_item}">${i.nome}</option>`).join('');
    
    row.innerHTML = `
        <select class="select-item">${options}</select>
        <input type="number" class="qtd-item" placeholder="Qtd (Ex: 1)">
        <button type="button" onclick="this.parentElement.remove()">X</button>
    `;
    div.appendChild(row);
}

async function salvarProduto() {
    const nome = document.getElementById('nome-produto').value;
    const preco = document.getElementById('preco-produto').value;
    
    const ingredientes = [];
    document.querySelectorAll('.ingrediente-row').forEach(row => {
        ingredientes.push({
            id_item: row.querySelector('.select-item').value,
            quantidade: row.querySelector('.qtd-item').value
        });
    });

    const payload = { nome, preco, ingredientes };

    const res = await fetch(API_PRODUTOS, {
        method: 'POST',
        body: JSON.stringify(payload)
    });
    
    if((await res.json()).success) alert("Produto e Receita salvos!");
}

document.addEventListener('DOMContentLoaded', carregarItensEstoque);