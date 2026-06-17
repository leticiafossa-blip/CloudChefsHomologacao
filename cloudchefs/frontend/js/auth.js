// CHAVE PARA O LOCALSTORAGE
const LOGGED_USER_KEY = 'cloudchefs_logged_user';

// 1. ADICIONADO 'Início' na lista de permissões para evitar o loop
const PERMISSOES = {
    'GERENTE': {
        nome: 'Gerente',
        acessos: ['Início', 'Relatórios', 'Novo Pedido', 'Colaboradores', 'Financeiro', 'Atualização de estoque', 'Produtos']
    },
    'ATENDENTE': {
        nome: 'Atendente',
        acessos: ['Início', 'Novo Pedido']
    }
};

function exibirMensagem(texto, tipo = 'alerta') {
    const container = document.getElementById('global-message-container');
    if (!container) return;

    const message = document.createElement('div');
    message.className = `toast-message ${tipo}`;

    let icone = '';
    switch (tipo) {
        case 'sucesso': icone = 'fa-check-circle'; break;
        case 'erro': icone = 'fa-times-circle'; break;
        case 'alerta': default: icone = 'fa-exclamation-triangle'; break;
    }

    message.innerHTML = `<i class="fas ${icone}"></i><span>${texto}</span>`;
    container.appendChild(message);

    setTimeout(() => {
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 500);
    }, 4500);
}

async function fazerLogin() {
    const loginInput = document.getElementById('login-usuario');
    const senhaInput = document.getElementById('senha-usuario');

    const login = loginInput.value.trim();
    const senha = senhaInput.value.trim();

    if (login === "" || senha === "") {
        exibirMensagem("Login e Senha são obrigatórios.", "alerta");
        return;
    }

    try {
        const resposta = await fetch("../backend/login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ login: login, senha: senha })
        });

        const data = await resposta.json();

        if (data.success && data.user) { // Note que mudei para data.user conforme o PHP anterior
            localStorage.setItem(LOGGED_USER_KEY, JSON.stringify(data.user));
            window.location.href = 'monitoramento.html';
        } else {
            exibirMensagem(data.error || 'Usuário ou senha inválidos.', 'erro');
        }
    } catch (err) {
        console.error("Falha na conexão:", err);
        exibirMensagem("Falha ao comunicar com o servidor.", "erro");
    }
}

function getUsuarioLogado() {
    const usuarioSalvo = localStorage.getItem(LOGGED_USER_KEY);
    return usuarioSalvo ? JSON.parse(usuarioSalvo) : null;
}

function fazerLogout() {
    localStorage.removeItem(LOGGED_USER_KEY);
    window.location.href = 'login.html';
}

// 2. CORREÇÃO NO MAPEAMENTO: Agora aceita 'admin' ou 'GERENTE'
function getCargoDoUsuario(tipo_usuario) {
    if (!tipo_usuario) return null;
    const tipo = tipo_usuario.toUpperCase();
    
    if (tipo === 'ADMIN' || tipo === 'GERENTE') {
        return 'GERENTE';
    }
    if (tipo === 'COLABORADOR' || tipo === 'ATENDENTE') {
        return 'ATENDENTE';
    }
    return null;
}

function verificarPermissao(recurso) {
    const usuario = getUsuarioLogado();
    if (!usuario) return false;

    const cargo = getCargoDoUsuario(usuario.tipo_usuario);
    if (!cargo || !PERMISSOES[cargo]) return false;

    return PERMISSOES[cargo].acessos.includes(recurso);
}

function verificarAcessoPagina(nomePagina) {
    const usuario = getUsuarioLogado();

    if (!usuario) {
        window.location.href = 'login.html';
        return false;
    }

    if (!verificarPermissao(nomePagina)) {
        console.error("Acesso negado para o recurso:", nomePagina);
        // Em vez de remover o usuário e expulsar, vamos apenas avisar e travar
        // Para evitar o loop infinito de expulsão
        exibirMensagem("Sem permissão para: " + nomePagina, "erro");
        
        // Se estiver no monitoramento e não tiver acesso ao 'Início', aí sim desloga
        if (nomePagina === 'Início') {
            localStorage.removeItem(LOGGED_USER_KEY);
            window.location.href = 'login.html';
        }
        return false;
    }

    return true;
}