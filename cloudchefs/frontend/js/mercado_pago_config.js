const BASE_BACKEND = "../backend";
const API_OBTER_CONFIG_MP = `${BASE_BACKEND}/obter_config_mercado_pago.php`;
const API_SALVAR_CONFIG_MP = `${BASE_BACKEND}/salvar_config_mercado_pago.php`;
const API_TESTAR_CONFIG_MP = `${BASE_BACKEND}/testar_config_mercado_pago.php`;

document.addEventListener('DOMContentLoaded', () => {
    if (!verificarAcessoPagina('Mercado Pago')) return;

    carregarConfigMercadoPago();

    const form = document.getElementById('form-mercado-pago');
    form.addEventListener('submit', salvarConfigMercadoPago);
});

async function carregarConfigMercadoPago() {
    const statusEl = document.getElementById('status-config');
    const tokenAtual = document.getElementById('token-atual');
    const secretAtual = document.getElementById('secret-atual');

    try {
        const resposta = await fetch(API_OBTER_CONFIG_MP);
        const data = await resposta.json();

        if (!data.success) {
            statusEl.className = 'status-config status-pendente';
            statusEl.textContent = data.error || 'Erro ao carregar configuração.';
            return;
        }

        if (!data.configurado) {
            statusEl.className = 'status-config status-pendente';
            statusEl.textContent = 'Mercado Pago ainda não configurado.';
            tokenAtual.textContent = 'Nenhum token salvo ainda.';
            secretAtual.textContent = 'Nenhuma assinatura secreta salva ainda.';
            return;
        }

        document.getElementById('ambiente').value = data.ambiente || 'teste';
        document.getElementById('public-key').value = data.public_key || '';
        document.getElementById('webhook-url').value = data.webhook_url || '';

        statusEl.className = 'status-config status-ok';
        statusEl.textContent = 'Mercado Pago configurado.';

        tokenAtual.textContent = data.possui_access_token
            ? `Token atual salvo: ${data.access_token_mascarado}`
            : 'Nenhum token salvo ainda.';

        secretAtual.textContent = data.possui_webhook_secret
            ? 'Assinatura secreta já salva. Deixe em branco para manter.'
            : 'Nenhuma assinatura secreta salva ainda.';

    } catch (err) {
        console.error(err);
        statusEl.className = 'status-config status-pendente';
        statusEl.textContent = 'Falha ao comunicar com o servidor.';
    }
}

async function salvarConfigMercadoPago(event) {
    event.preventDefault();

    const payload = {
        ambiente: document.getElementById('ambiente').value,
        public_key: document.getElementById('public-key').value.trim(),
        access_token: document.getElementById('access-token').value.trim(),
        webhook_url: document.getElementById('webhook-url').value.trim(),
        webhook_secret: document.getElementById('webhook-secret').value.trim()
    };

    try {
        const resposta = await fetch(API_SALVAR_CONFIG_MP, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await resposta.json();

        if (data.success) {
            exibirMensagem('Configuração salva com sucesso!', 'sucesso');
            limparCamposSensiveis();
            carregarConfigMercadoPago();
        } else {
            exibirMensagem(data.error || 'Erro ao salvar configuração.', 'erro');
        }

        mostrarResultado(data);

    } catch (err) {
        console.error(err);
        exibirMensagem('Falha ao salvar configuração.', 'erro');
    }
}

async function testarConexaoMercadoPago() {
    try {
        const resposta = await fetch(API_TESTAR_CONFIG_MP);
        const data = await resposta.json();

        if (data.success) {
            exibirMensagem('Conexão com Mercado Pago funcionando!', 'sucesso');
        } else {
            exibirMensagem(data.error || 'Erro ao testar Mercado Pago.', 'erro');
        }

        mostrarResultado(data);

    } catch (err) {
        console.error(err);
        exibirMensagem('Falha ao testar conexão.', 'erro');
    }
}

function limparCamposSensiveis() {
    document.getElementById('access-token').value = '';
    document.getElementById('webhook-secret').value = '';
}

function mostrarResultado(data) {
    const resultado = document.getElementById('resultado');
    resultado.style.display = 'block';
    resultado.textContent = JSON.stringify(data, null, 2);
}
