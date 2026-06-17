// colaboradores.js
const API_URL = "http://3.141.192.46/Cloudchefs2026/cloudchefs/backend/usuarios.php";

console.log("Iniciando script de colaboradores...");

// Função global para renderizar a tabela
window.renderizarTabela = async function() {
    const tabelaBody = document.getElementById('tabela-body');
    if (!tabelaBody) return;

    tabelaBody.innerHTML = '<tr><td colspan="4">Buscando dados no RDS...</td></tr>';
    
    try {
        console.log("Chamando API em:", API_URL);
        const resposta = await fetch(API_URL);
        const colaboradores = await resposta.json();

        tabelaBody.innerHTML = ''; 
        
        if (!colaboradores || colaboradores.length === 0) {
            tabelaBody.innerHTML = '<tr><td colspan="4">Nenhum usuário no banco.</td></tr>';
            return;
        }

        colaboradores.forEach(col => {
            const row = tabelaBody.insertRow();
            row.innerHTML = `
                <td>${col.nome}</td>
                <td>${col.login}</td>
                <td>${col.tipo_usuario || 'colaborador'}</td>
                <td>
                    <button class="btn-excluir-tabela" onclick="excluirColaborador(${col.id_usuario})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
        });
    } catch (err) {
        console.error("Erro na carga:", err);
        tabelaBody.innerHTML = '<tr><td colspan="4" style="color:red">Erro ao conectar com AWS.</td></tr>';
    }
};

// Função global para cadastrar
window.cadastrarColaborador = async function() {
    console.log("Botão de cadastro clicado!");
    const nome = document.getElementById('nome').value.trim();
    const login = document.getElementById('login').value.trim();
    const senha = document.getElementById('senha').value.trim();

    if (!nome || !login || !senha) {
        alert("Preencha todos os campos!");
        return;
    }

    try {
        const res = await fetch(API_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ nome, login, senha, tipo_usuario: 'colaborador' })
        });
        const data = await res.json();

        if (data.success) {
            alert("Sucesso! Salvo no RDS.");
            document.getElementById('nome').value = '';
            document.getElementById('login').value = '';
            document.getElementById('senha').value = '';
            renderizarTabela();
        } else {
            alert("Erro do PHP: " + data.error);
        }
    } catch (err) {
        console.error("Erro no cadastro:", err);
        alert("Falha de conexão. Veja o console.");
    }
};

window.excluirColaborador = async function(id) {
    if (!confirm("Deseja excluir?")) return;
    try {
        await fetch(API_URL, {
            method: "DELETE",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id_usuario: id })
        });
        renderizarTabela();
    } catch (err) {
        alert("Erro ao excluir.");
    }
};

// Força a carga da página
function carregarColaboradoresPagina() {
    renderizarTabela();
}

// Garante que o script está vivo
console.log("Script carregado com sucesso.");