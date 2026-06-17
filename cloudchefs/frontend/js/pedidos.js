// --- CONFIGURAÇÃO ---
const BASE_BACKEND = "../backend";
const API_PRODUTOS = `${BASE_BACKEND}/produtos.php`;
const API_SALVAR_PEDIDO = `${BASE_BACKEND}/salvar_pedido.php`;
const API_CRIAR_PIX = `${BASE_BACKEND}/criar_pix.php`;
const API_CONSULTAR_PIX = `${BASE_BACKEND}/consultar_pagamento_pix.php`;

let PRODUTOS_BANCO = [];
let carrinho = {};
let total = 0;
let formaPagamento = null;
let intervaloPix = null;

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

// --- DESENHA OS PRODUTOS NA TELA ---
function renderizarCardapio() {
    const containerLanches = document.getElementById('produtos-lanches');
    const containerAcompanhamentos = document.getElementById('produtos-acompanhamentos');
    const containerBebidas = document.getElementById('produtos-bebidas');

    if (!containerLanches) return;

    containerLanches.innerHTML = "";
    if (containerAcompanhamentos) containerAcompanhamentos.innerHTML = "";
    if (containerBebidas) containerBebidas.innerHTML = "";

    PRODUTOS_BANCO.forEach(p => {
        if (p.status === true || p.status === 't' || p.status === 1 || p.status === '1') {
            const icone = getItemIcone(p.nome);

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

            const nome = p.nome.toLowerCase();
            if (nome.includes('batata') || nome.includes('fritas') || nome.includes('acompanhamento')) {
                if (containerAcompanhamentos) containerAcompanhamentos.innerHTML += cardHtml;
            } else if (nome.includes('coca') || nome.includes('suco') || nome.includes('refri') || nome.includes('agua') || nome.includes('fanta') || nome.includes('guaraná')) {
                if (containerBebidas) containerBebidas.innerHTML += cardHtml;
            } else {
                containerLanches.innerHTML += cardHtml;
            }
        }
    });
}

// --- CARRINHO ---
function alterarQtd(id, delta) {
    if (!carrinho[id]) carrinho[id] = 0;

    carrinho[id] += delta;
    if (carrinho[id] < 0) carrinho[id] = 0;

    if (carrinho[id] === 0) {
        delete carrinho[id];
    }

    const qtdEl = document.getElementById(`qtd-${id}`);
    if (qtdEl) {
        qtdEl.textContent = carrinho[id] || 0;
    }

    atualizarTotais();
}

function atualizarTotais() {
    total = 0;

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
async function finalizarPedido() {
    if (total === 0) return exibirMensagem("O pedido não pode estar vazio.", "alerta");
    if (!formaPagamento) return exibirMensagem("Selecione a forma de pagamento.", "alerta");

    const nomeCliente = document.getElementById('nome-cliente').value.trim();

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

    const btnFinalizar = document.getElementById('btn-finalizar-pedido');
    if (btnFinalizar) {
        btnFinalizar.disabled = true;
        btnFinalizar.textContent = "PROCESSANDO...";
    }

    try {
        const resposta = await fetch(API_SALVAR_PEDIDO, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(novoPedido)
        });

        const data = await resposta.json();

        if (!data.success) {
            exibirMensagem(`Erro: ${data.error}`, "erro");
            restaurarBotaoFinalizar();
            return;
        }

        if (formaPagamento === "PIX") {
            await criarPix(data.id_pedido);
        } else {
            exibirMensagem("Pedido enviado com sucesso!", "sucesso");
            setTimeout(() => window.location.href = "monitoramento.html", 1500);
        }

    } catch (err) {
        console.error(err);
        exibirMensagem("Falha na conexão com o servidor.", "erro");
        restaurarBotaoFinalizar();
    }
}

function restaurarBotaoFinalizar() {
    const btnFinalizar = document.getElementById('btn-finalizar-pedido');
    if (btnFinalizar) {
        btnFinalizar.disabled = false;
        btnFinalizar.textContent = "FINALIZAR";
    }
}

// --- PIX MERCADO PAGO ---
async function criarPix(idPedido) {
    try {
        const resposta = await fetch(API_CRIAR_PIX, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_pedido: idPedido })
        });

        const data = await resposta.json();

        if (!data.success) {
            exibirMensagem(data.error || "Erro ao gerar Pix.", "erro");
            restaurarBotaoFinalizar();
            return;
        }

        mostrarModalPix(data, idPedido);
        iniciarConsultaPagamentoPix(idPedido);

    } catch (err) {
        console.error(err);
        exibirMensagem("Falha ao gerar Pix.", "erro");
        restaurarBotaoFinalizar();
    }
}

function mostrarModalPix(data, idPedido) {
    fecharModalPix();

    const modal = document.createElement("div");
    modal.id = "modal-pix";
    modal.style.position = "fixed";
    modal.style.top = "0";
    modal.style.left = "0";
    modal.style.width = "100%";
    modal.style.height = "100%";
    modal.style.background = "rgba(0, 0, 0, 0.75)";
    modal.style.display = "flex";
    modal.style.alignItems = "center";
    modal.style.justifyContent = "center";
    modal.style.zIndex = "99999";
    modal.style.padding = "16px";

    const qrImg = data.qr_code_base64
        ? `<img src="data:image/png;base64,${data.qr_code_base64}" alt="QR Code Pix" style="width:260px; max-width:100%; border:1px solid #ddd; border-radius:10px;">`
        : `<p style="color:#b00020;">QR Code em imagem não retornou. Use o Pix Copia e Cola abaixo.</p>`;

    modal.innerHTML = `
        <div style="background:#fff; color:#222; width:100%; max-width:440px; border-radius:16px; padding:24px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,0.35);">
            <h2 style="margin-top:0;">Pagamento via Pix</h2>
            <p style="margin-bottom:14px;">Pedido Nº <strong>${String(idPedido).padStart(5, '0')}</strong></p>
            ${qrImg}
            <p style="font-weight:bold; margin-top:18px;">Pix Copia e Cola</p>
            <textarea id="pix-copia-cola" readonly style="width:100%; height:90px; resize:none; border:1px solid #ccc; border-radius:8px; padding:8px; font-size:12px;">${data.copia_cola || ''}</textarea>
            <div style="display:flex; gap:10px; margin-top:14px; justify-content:center; flex-wrap:wrap;">
                <button onclick="copiarCodigoPix()" style="padding:10px 16px; border:none; border-radius:8px; background:#28a745; color:white; font-weight:bold; cursor:pointer;">Copiar Pix</button>
                <button onclick="consultarPixAgora(${idPedido})" style="padding:10px 16px; border:none; border-radius:8px; background:#222; color:white; font-weight:bold; cursor:pointer;">Já paguei</button>
                <button onclick="cancelarAguardarPix()" style="padding:10px 16px; border:none; border-radius:8px; background:#777; color:white; font-weight:bold; cursor:pointer;">Fechar</button>
            </div>
            <p id="status-pix" style="margin-top:18px; font-weight:bold; color:#856404;">Aguardando pagamento...</p>
            <p style="font-size:12px; color:#666; margin-bottom:0;">A tela verifica automaticamente a cada 5 segundos.</p>
        </div>
    `;

    document.body.appendChild(modal);
}

function copiarCodigoPix() {
    const campo = document.getElementById("pix-copia-cola");
    if (!campo) return;

    campo.select();
    campo.setSelectionRange(0, 99999);

    navigator.clipboard.writeText(campo.value)
        .then(() => exibirMensagem("Código Pix copiado!", "sucesso"))
        .catch(() => {
            document.execCommand('copy');
            exibirMensagem("Código Pix copiado!", "sucesso");
        });
}

function iniciarConsultaPagamentoPix(idPedido) {
    if (intervaloPix) clearInterval(intervaloPix);

    intervaloPix = setInterval(() => {
        consultarPixAgora(idPedido, false);
    }, 5000);
}

async function consultarPixAgora(idPedido, mostrarMensagem = true) {
    const statusEl = document.getElementById("status-pix");

    try {
        const resposta = await fetch(API_CONSULTAR_PIX, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_pedido: idPedido })
        });

        const data = await resposta.json();

        if (!data.success) {
            if (mostrarMensagem) exibirMensagem(data.error || "Erro ao consultar pagamento.", "erro");
            return;
        }

        if (data.pago) {
            if (intervaloPix) clearInterval(intervaloPix);
            intervaloPix = null;

            if (statusEl) {
                statusEl.textContent = "Pagamento aprovado! Enviando para produção...";
                statusEl.style.color = "#28a745";
            }

            exibirMensagem("Pagamento Pix aprovado!", "sucesso");

            setTimeout(() => {
                window.location.href = "monitoramento.html";
            }, 1500);
        } else {
            if (statusEl) {
                statusEl.textContent = `Aguardando pagamento... Status: ${data.status || 'pendente'}`;
                statusEl.style.color = "#856404";
            }

            if (mostrarMensagem) {
                exibirMensagem("Pagamento ainda não aprovado.", "alerta");
            }
        }
    } catch (err) {
        console.error(err);
        if (mostrarMensagem) exibirMensagem("Falha ao consultar pagamento Pix.", "erro");
    }
}

function fecharModalPix() {
    const modal = document.getElementById("modal-pix");
    if (modal) modal.remove();
}

function cancelarAguardarPix() {
    if (intervaloPix) clearInterval(intervaloPix);
    intervaloPix = null;
    fecharModalPix();
    restaurarBotaoFinalizar();
}

// --- MAPEAMENTO DE ÍCONES ---
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

function voltarParaCardapio() {
    document.getElementById('etapa-pagamento').classList.remove('ativa');
    document.getElementById('etapa-cardapio').classList.add('ativa');
}
