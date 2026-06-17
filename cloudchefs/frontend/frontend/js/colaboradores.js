// colaboradores.js

const API_URL = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/usuarios.php";

console.log("Conectando na API: ", API_URL);

/**
 * Renderiza a tabela de colaboradores buscando os dados do RDS.
 */
async function renderizarTabela() {
    const tabelaBody = document.getElementById('tabela-body');
    tabelaBody.innerHTML = '<tr><td colspan="4">Carregando...</td></tr>';
    
    try {
        const resposta = await fetch(API_URL);
        const colaboradores = await resposta.json();

        tabelaBody.innerHTML = ''; // Limpa o carregando
        const usuarioLogado = getUsuarioLogado(); 
        // No banco usamos 'admin', no seu JS antigo era 'GERENTE'
        const eAdmin = usuarioLogado && (usuarioLogado.tipo_usuario === 'admin' || usuarioLogado.permissao === 'GERENTE');

        colaboradores.forEach(colaborador => {
            const row = tabelaBody.insertRow();
            
            // Coluna 1: Nome
            row.insertCell().textContent = colaborador.nome;
            
            // Coluna 2: Login (Adicionado para facilitar a gestão)
            row.insertCell().textContent = colaborador.login;

            // Coluna 3: Permissão (Select)
            const cellPermissao = row.insertCell();
            const select = document.createElement('select');
            select.className = 'role-select';
            
            const opcoes = [
                { val: 'admin', text: 'Gerente' },
                { val: 'colaborador', text: 'Atendente' }
            ];

            opcoes.forEach(opt => {
                let o = document.createElement('option');
                o.value = opt.val;
                o.textContent = opt.text;
                if (colaborador.tipo_usuario === opt.val) o.selected = true;
                select.appendChild(o);
            });

            select.onchange = (e) => atualizarPermissao(colaborador.id_usuario, e.target.value);
            
            // Só admin pode mudar permissão
            if (!eAdmin) select.disabled = true;
            cellPermissao.appendChild(select);

            // Coluna 4: Ações (Excluir)
            const cellAcoes = row.insertCell();
            if (eAdmin && colaborador.login !== 'admin') {
                const btnExcluir = document.createElement('button');
                btnExcluir.innerHTML = '<i class="fas fa-trash"></i>';
                btnExcluir.className = 'btn-excluir-tabela';
                btnExcluir.onclick = () => excluirColaborador(colaborador.id_usuario, colaborador.nome);
                cellAcoes.appendChild(btnExcluir);
            }
        });
    } catch (error) {
        console.error("Erro ao renderizar tabela:", error);
        tabelaBody.innerHTML = '<tr><td colspan="4">Erro ao carregar dados do servidor.</td></tr>';
    }
}

/**
 * Cadastra um novo colaborador no RDS via POST.
 */
async function cadastrarColaborador() {
    const usuarioLogado = getUsuarioLogado();

    if (!usuarioLogado || (usuarioLogado.tipo_usuario !== 'admin' && usuarioLogado.permissao !== 'GERENTE')) {
        exibirMensagem('Acesso negado. Apenas administradores podem cadastrar.', 'erro');
        return;
    }

    const nomeInput = document.getElementById('nome');
    const loginInput = document.getElementById('login'); 
    const senhaInput = document.getElementById('senha');
    
    const nome = nomeInput.value.trim();
    const login = loginInput.value.trim();
    const senha = senhaInput.value.trim();

    if (!nome || !login || !senha) {
        exibirMensagem('Nome, Login e Senha são obrigatórios.', 'alerta');
        return;
    }
    
    try {
        const resposta = await fetch(API_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                nome: nome,
                login: login,
                senha: senha,
                tipo_usuario: 'colaborador'
            })
        });

        const data = await resposta.json();

        if (data.success) {
            exibirMensagem(`Usuário ${nome} cadastrado!`, 'sucesso');
            nomeInput.value = '';
            loginInput.value = '';
            senhaInput.value = '';
            renderizarTabela(); // Atualiza a lista
        } else {
            exibirMensagem(data.error || "Erro ao cadastrar", "erro");
        }
    } catch (error) {
        exibirMensagem("Erro de conexão com o servidor AWS.", "erro");
    }
}

/**
 * Exclui um colaborador do banco de dados.
 */
async function excluirColaborador(id, nome) {
    if (!confirm(`Tem certeza que deseja excluir ${nome}?`)) return;

    try {
        const resposta = await fetch(API_URL, {
            method: "DELETE",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_usuario: id })
        });

        const data = await resposta.json();
        if (data.success) {
            exibirMensagem("Colaborador removido.", "sucesso");
            renderizarTabela();
        }
    } catch (error) {
        exibirMensagem("Erro ao excluir colaborador.", "erro");
    }
}

/**
 * Função para alternar visibilidade da senha no input de cadastro.
 */
function toggleSenhaInput() {
    const senhaInput = document.getElementById('senha');
    const eyeIcon = document.getElementById('eye-icon-cadastro');
    
    if (senhaInput.type === 'password') {
        senhaInput.type = 'text';
        eyeIcon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        senhaInput.type = 'password';
        eyeIcon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

/**
 * Inicialização da página.
 */
function carregarColaboradoresPagina() {
    if (verificarAcessoPagina('Colaboradores')) { 
        renderizarTabela();
    }
}

// Inicia a carga quando o script é lido
document.addEventListener('DOMContentLoaded', carregarColaboradoresPagina);