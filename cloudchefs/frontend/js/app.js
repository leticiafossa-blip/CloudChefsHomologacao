/* =============================================================
   Cloud Chefs - funções compartilhadas por todas as telas
   ============================================================= */

const API = '/api';

// Itens do menu lateral. "gerente: true" = só aparece para o gerente.
const MENU = [
    { id: 'monitoramento', titulo: 'Fila de produção', icone: 'fa-fire-burner', href: 'monitoramento.html' },
    { id: 'pedidos', titulo: 'Novo pedido', icone: 'fa-cart-plus', href: 'pedidos.html' },
    { separador: true, gerente: true },
    { id: 'produtos', titulo: 'Produtos', icone: 'fa-burger', href: 'produtos.html', gerente: true },
    { id: 'estoque', titulo: 'Estoque', icone: 'fa-boxes-stacked', href: 'estoque.html', gerente: true },
    { id: 'colaboradores', titulo: 'Colaboradores', icone: 'fa-users', href: 'colaboradores.html', gerente: true },
    { id: 'financeiro', titulo: 'Financeiro', icone: 'fa-sack-dollar', href: 'financeiro.html', gerente: true },
    { id: 'relatorios', titulo: 'Relatórios', icone: 'fa-chart-column', href: 'relatorio.html', gerente: true },
    { id: 'mercado-pago', titulo: 'Mercado Pago', icone: 'fa-qrcode', href: 'mercado_pago.html', gerente: true },
];

/** Chama a API e devolve o JSON. Lança um Error com a mensagem do servidor. */
async function api(caminho, { metodo = 'GET', dados } = {}) {
    const opcoes = { method: metodo, credentials: 'same-origin', headers: {} };
    if (dados !== undefined) {
        opcoes.headers['Content-Type'] = 'application/json';
        opcoes.body = JSON.stringify(dados);
    }

    let resposta;
    try {
        resposta = await fetch(`${API}/${caminho}`, opcoes);
    } catch {
        throw new Error('Sem conexão com o servidor.');
    }

    let json = {};
    try { json = await resposta.json(); } catch { /* resposta sem JSON */ }

    if (resposta.status === 401 && window.paginaProtegida) {
        irParaLogin();
        throw new Error('Sessão expirada.');
    }
    if (!resposta.ok || json.success === false) {
        throw new Error(json.error || `Erro ${resposta.status} no servidor.`);
    }
    return json;
}

function irParaLogin() {
    const voltar = encodeURIComponent(location.pathname.split('/').pop() + location.search);
    location.href = `login.html?voltar=${voltar}`;
}

/** Mostra um aviso no canto da tela. tipo: sucesso | erro | alerta */
function exibirMensagem(texto, tipo = 'alerta') {
    let caixa = document.getElementById('mensagens');
    if (!caixa) {
        caixa = document.createElement('div');
        caixa.id = 'mensagens';
        caixa.setAttribute('role', 'status');
        document.body.appendChild(caixa);
    }
    const icones = { sucesso: 'fa-circle-check', erro: 'fa-circle-xmark', alerta: 'fa-triangle-exclamation' };
    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;
    toast.innerHTML = `<i class="fa-solid ${icones[tipo] || icones.alerta}"></i><span></span>`;
    toast.querySelector('span').textContent = texto;
    caixa.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 4500);
}

/** Escapa texto para colocar dentro de HTML (evita injeção de código). */
function esc(valor) {
    return String(valor ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const formatoMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
function moeda(valor) { return formatoMoeda.format(Number(valor) || 0); }

function numeroBR(valor, casas = 0) {
    return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: casas || 3 });
}

/** Converte a data do banco (ex.: "2026-11-18 19:20:00-03") para Date. */
function paraData(texto) {
    if (!texto) return null;
    let iso = String(texto).replace(' ', 'T');
    if (/[+-]\d{2}$/.test(iso)) iso += ':00';
    const d = new Date(iso);
    return isNaN(d) ? null : d;
}

function dataHora(texto) {
    const d = paraData(texto);
    return d ? d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
}

function hora(texto) {
    const d = paraData(texto);
    return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '--:--';
}

function dataCurta(iso) { // "2026-11-18" -> "18/11"
    const [, m, d] = String(iso).split('-');
    return `${d}/${m}`;
}

function hojeISO(deslocamentoDias = 0) {
    const d = new Date();
    d.setDate(d.getDate() + deslocamentoDias);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function numeroPedido(id) { return '#' + String(id).padStart(5, '0'); }

const NOMES_PAGAMENTO = { Dinheiro: 'Dinheiro', Debito: 'Débito', Credito: 'Crédito', PIX: 'Pix' };

function iconeProduto(categoria) {
    return { lanche: '🍔', acompanhamento: '🍟', bebida: '🥤' }[categoria] || '🍽️';
}

/** Desenha o topo e o menu, confere o login e devolve o usuário. */
async function iniciarPagina({ id, titulo, somenteGerente = false }) {
    window.paginaProtegida = true;
    let usuario;
    try {
        usuario = (await api('me.php')).usuario;
    } catch (e) {
        if (e.message !== 'Sessão expirada.') {
            document.body.innerHTML = `<div class="tela-login"><div class="caixa-login"><h1>Ops!</h1><p>${esc(e.message)}</p>
                <button class="btn btn-primario" onclick="location.reload()">Tentar de novo</button></div></div>`;
        }
        throw e;
    }

    const gerente = usuario.tipo_usuario === 'GERENTE';
    if (somenteGerente && !gerente) {
        location.href = 'monitoramento.html';
        throw new Error('Sem permissão.');
    }

    const conteudo = document.querySelector('main.conteudo');
    const links = MENU.filter(m => gerente || !m.gerente).map(m => m.separador
        ? '<div class="separador"></div>'
        : `<a href="${m.href}" class="${m.id === id ? 'ativo' : ''}"><i class="fa-solid ${m.icone}"></i>${m.titulo}</a>`
    ).join('');

    const topo = document.createElement('header');
    topo.className = 'topo';
    topo.innerHTML = `
        <a class="marca" href="monitoramento.html"><img src="img/logo.png" alt=""><span>Cloud Chefs</span></a>
        <h1>${esc(titulo)}</h1>
        <div class="usuario">
            <span class="nome">${esc(usuario.nome)}</span>
            <span class="perfil">${gerente ? 'GERENTE' : 'ATENDENTE'}</span>
            <button class="btn btn-pequeno btn-sair" onclick="sair()"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
        </div>`;

    const layout = document.createElement('div');
    layout.className = 'layout';
    layout.innerHTML = `<nav class="menu" aria-label="Menu principal">${links}</nav>`;
    conteudo.parentNode.insertBefore(layout, conteudo);
    layout.appendChild(conteudo);
    document.body.insertBefore(topo, layout);
    document.title = `${titulo} · Cloud Chefs`;

    window.usuarioAtual = usuario;
    return usuario;
}

async function sair() {
    try { await api('logout.php', { metodo: 'POST', dados: {} }); } catch { /* ignora */ }
    location.href = 'login.html';
}

/** Desativa um botão enquanto uma ação assíncrona roda. */
async function comBotao(botao, acao) {
    const original = botao.innerHTML;
    botao.disabled = true;
    botao.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Aguarde...';
    try { return await acao(); }
    finally { botao.disabled = false; botao.innerHTML = original; }
}

/** Baixa uma tabela HTML como CSV (abre no Excel). */
function exportarCSV(tabela, nomeArquivo) {
    const linhas = [...tabela.querySelectorAll('tr')].map(tr =>
        [...tr.querySelectorAll('th, td')].map(c => `"${c.innerText.replace(/"/g, '""').trim()}"`).join(';')
    );
    const blob = new Blob(['﻿' + linhas.join('\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = nomeArquivo;
    link.click();
    URL.revokeObjectURL(link.href);
}
