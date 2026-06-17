// --- CONFIGURAÇÃO ---
const IP_VM = "3.144.118.111"; // Seu IP atualizado
const API_PRODUTOS = `http://${IP_VM}/Cloudchefs2026/cloudchefs/backend/produtos.php`;
const API_SALVAR_PEDIDO = `http://${IP_VM}/Cloudchefs2026/cloudchefs/backend/salvar_pedido.php`;

let PRODUTOS_BANCO = []; // Onde guardaremos os lanches vindos da AWS
let carrinho = {}; // Formato: { id_produto: quantidade }
let total = 0;
let formaPagamento = null;

// --- INICIALIZAÇÃO ---
document.addEventListener("DOMContentLoaded", () => {
    carregarCardapio();
});

// --- BUSCA OS PRODUTOS REAIS NO BANCO ---
async function carregarCardapio() {
    try {
        const res = await fetch(API_PRODUTOS);
        PRODUTOS_BANCO = await res.json();
        
        console.log("✅ Cardápio carregado:", PRODUTOS_BANCO);
        renderizarCardapio();
    } catch (err) {
        console.error("❌ Erro ao buscar cardápio:", err);
        exibirMensagem("Erro ao carregar produtos do servidor.", "erro");
    }
}

// --- DESENHA OS PRODUTOS NA TELA COM O LAYOUT CORRETO ---
function renderizarCardapio() {
    const containerLanches = document.getElementById('produtos-lanches');
    const containerAcompanhamentos = document.getElementById('produtos-acompanhamentos');
    const containerBebidas = document.getElementById('produtos-bebidas');

    if (!containerLanches) return;

    // Limpa os containers antes de renderizar os dados do banco
    containerLanches.innerHTML = "";
    if (containerAcompanhamentos) containerAcompanhamentos.innerHTML = "";
    if (containerBebidas) containerBebidas.innerHTML = "";

    PRODUTOS_BANCO.forEach(p => {
        // Verifica se o produto está ATIVO no banco
        if (p.status === true || p.status === 't' || p.status === 1 || p.status === '1') {
            
            const icone = getItemIcone(p.nome);
            
            // CORREÇÃO: HTML adaptado para usar as classes originais do CSS do Cloud Chefs e os botões vermelhos
            const cardHtml = `
                <div class="produto-item">
                    <span class="item-icone" style="font-size: 65px; display: block; margin-bottom: 10px;">${icone}</span>
                    <h2>${p.nome}</h2>
                    <p class="preco">R$ ${parseFloat(p.preco).toFixed(2).replace('.', ',')}</p>
                    <div class="quantidade">
                        <button class="btn-qtd" onclick="alterarQtd(${p.id_produto}, -1)">−</button>
                        <span class="qtd-numero" id="qtd-${p.id_produto}">0</span>
                        <button class="btn-qtd" onclick="alterarQtd(${p.id_produto}, 1)">+</button>
                    </div>
                </div>
            `;

            // Lógica de separação por abas baseada no nome do item
            const nome = p.nome.toLowerCase();
            if (nome.includes('batata') || nome.includes('fritas') || nome.includes('acompanhamento')) {
                if (containerAcompanhamentos) containerAcompanhamentos.innerHTML += cardHtml;
            } else if (nome.includes('coca') || nome.includes('suco') || nome.includes('refri') || nome.includes('agua') || nome.includes('fanta') || nome.includes('guaraná')) {
                if (containerBebidas) containerBebidas.innerHTML += cardHtml;
            } else {
                // Tudo o que não for bebida ou batata cai em lanches por segurança
                containerLanches.innerHTML += cardHtml;
            }
        }
    });
}

// --- LOGICA DO CARRINHO ---
function alterarQtd(id, delta) {
    if (!carrinho[id]) carrinho[id] = 0;
    
    carrinho[id] += delta;
    if (carrinho[id] < 0) carrinho[id] = 0;

    // Se a quantidade voltar a ser zero, podemos remover do carrinho para limpar o payload
    if (carrinho[id] === 0) {
        delete carrinho[id];
    }

    // Atualiza dinamicamente o número central do contador na tela
    const qtdEl = document.getElementById(`qtd-${id}`);
    if (qtdEl) {
        qtdEl.textContent = carrinho[id] || 0;
    }

    atualizarTotais();
}

function atualizarTotais() {
    total = 0;
    
    // Percorre o carrinho e busca o preço correspondente no array vindo da AWS
    for (const id in carrinho) {
        const produto = PRODUTOS_BANCO.find(p => p.id_produto == id);
        if (produto) {
            total += carrinho[id] * parseFloat(produto.preco);
        }
    }

    const subtotalEl = document.getElementById("subtotal");
    const totalEl = document.getElementById("total");

    if (subtotalEl && totalEl) {
        subtotalEl.textContent = total.toFixed(2).replace('.', ',');
        totalEl.textContent = total.toFixed(2).replace('.', ',');
    }
}

// --- FINALIZAÇÃO E ENVIO DO PEDIDO ---
function finalizarPedido() {
    if (total === 0) return exibirMensagem("O pedido não pode estar vazio.", "alerta");
    if (!formaPagamento) return exibirMensagem("Selecione a forma de pagamento.", "alerta");

    const nomeCliente = document.getElementById('nome-cliente').value.trim();

    // Mapeia o carrinho para a estrutura JSON que o seu salvar_pedido.php precisa ler
    const itensPedido = [];
    for (const id in carrinho) {
        if (carrinho[id] > 0) {
            const p = PRODUTOS_BANCO.find(prod => prod.id_produto == id);
            itensPedido.push({
                nome: p.nome,
                quantidade: carrinho[id],
                preco: p.preco
            });
        }
    }

    const novoPedido = {
        nome_cliente: nomeCliente,
        total: total.toFixed(2),
        forma_pagamento: formaPagamento,
        produtos: itensPedido
    };

    fetch(API_SALVAR_PEDIDO, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(novoPedido)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            exibirMensagem("Pedido enviado com sucesso!", "sucesso");
            setTimeout(() => window.location.href = "monitoramento.html", 1500);
        } else {
            exibirMensagem(`Erro: ${data.error}`, "erro");
        }
    })
    .catch(err => exibirMensagem("Falha na conexão com o servidor.", "erro"));
}

// --- MAPEAMENTO DE ÍCONES (EMOJIS) ---
function getItemIcone(nomeItem) {
    const nome = nomeItem.toLowerCase();
    if (nome.match(/(burguer|mac|queijo|chicken|quarteirão|stacker|combo|leader)/)) return '🍔';
    if (nome.includes('batata') || nome.includes('frita')) return '🍟';
    if (nome.match(/(coca|guaraná|fanta|suco|refri|agua|água)/)) return '🥤';
    return '🍽️';
}

function selecionarPagamento(forma) {
    formaPagamento = forma;
    document.querySelectorAll('.metodo-btn').forEach(btn => btn.classList.remove('selecionado'));
    const btnSel = document.querySelector(`.metodo-btn[data-forma='${forma}']`);
    if (btnSel) btnSel.classList.add('selecionado');
    const btnFinalizar = document.getElementById('btn-finalizar-pedido');
    if (btnFinalizar) btnFinalizar.disabled = false;
}

function proximaEtapa() {
    const nomeClienteInput = document.getElementById('nome-cliente');
    if (nomeClienteInput.value.trim() === "") {
        exibirMensagem("Por favor, insira o nome do cliente.", "alerta");
        return;
    }
    document.getElementById('nome-cliente-exibicao').textContent = nomeClienteInput.value;
    document.getElementById('etapa-cliente').classList.remove('ativa');
    document.getElementById('etapa-cardapio').classList.add('ativa');
}

function proximaEtapaPagamento() {
    if (total === 0) {
        exibirMensagem("Adicione itens ao pedido antes de avançar.", "alerta");
        return;
    }
    document.getElementById('etapa-cardapio').classList.remove('ativa');
    document.getElementById('etapa-pagamento').classList.add('ativa');
    document.getElementById('nome-cliente-pagamento').textContent = document.getElementById('nome-cliente').value;
    document.getElementById('total-pagamento').textContent = total.toFixed(2).replace('.', ',');
}

function mudarAba(categoria) {
    document.querySelectorAll('.produtos-container').forEach(c => c.classList.remove('ativa'));
    const sel = document.getElementById(`produtos-${categoria}`);
    if (sel) sel.classList.add('ativa');
    document.querySelectorAll('.aba-btn').forEach(b => b.classList.remove('ativa'));
    const btn = document.querySelector(`.aba-btn[data-categoria='${categoria}']`);
    if (btn) btn.classList.add('ativa');
}