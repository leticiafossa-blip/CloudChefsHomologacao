# 🍔 Cloud Chefs

Sistema de gestão para lanchonetes: pedidos no caixa, fila da cozinha, estoque com baixa automática por receita, colaboradores com perfis, financeiro, relatórios e pagamento **Pix pelo Mercado Pago**.

**Tecnologias:** PHP 8.3 (API) · PostgreSQL · HTML, CSS e JavaScript puro · hospedado no **Vercel** (runtime [`vercel-php`](https://github.com/vercel-community/php)) com banco **Neon**.

---

## Funcionalidades

| Tela | Quem acessa | O que faz |
|---|---|---|
| **Fila de produção** | todos | Pedidos em preparo, botões *Pronto* → *Entregue*, nível do estoque e resumo do dia. Atualiza sozinha. |
| **Novo pedido** | todos | Cliente → itens do cardápio → forma de pagamento. No Pix, mostra o QR Code e confirma o pagamento sozinho. |
| **Produtos** | gerente | Cadastro e edição do cardápio, com a **receita** de cada produto (quanto de cada ingrediente ele usa). |
| **Estoque** | gerente | Entradas e saídas de ingredientes, estoque mínimo e alerta de reposição. |
| **Colaboradores** | gerente | Cadastro de usuários, perfil (Gerente/Atendente), troca de senha. |
| **Financeiro** | gerente | Pedidos por período e forma de pagamento, total recebido, ticket médio. Exporta Excel/PDF. |
| **Relatórios** | gerente | Vendas por dia e produtos mais vendidos, com gráfico. Exporta Excel/PDF. |
| **Mercado Pago** | gerente | Credenciais do Pix. Sem credenciais, o sistema funciona em **modo demonstração**. |

### Regras de negócio
- Ao vender em dinheiro/cartão, o estoque é baixado na hora, conforme a receita. Se faltar ingrediente, o pedido é recusado.
- No Pix, o pedido fica *Aguardando PIX* e só vai para a cozinha (e baixa o estoque) depois que o pagamento é aprovado.
- Cancelar um pedido devolve os ingredientes ao estoque. O atendente só cancela Pix ainda não pago; o resto é com o gerente.
- Relatórios e financeiro contam apenas pedidos pagos e não cancelados.

---

## Segurança

- **Login validado no servidor:** após o login, a API envia um cookie `HttpOnly` assinado (HMAC-SHA256). Cada endpoint confere a assinatura e busca o perfil do usuário **no banco**; o navegador não consegue se promover a gerente.
- **Permissões no backend:** endpoints de gerente respondem `403` para atendentes, mesmo chamados direto.
- **Valor do pedido calculado no servidor:** o navegador envia só `id_produto` e quantidade; preço e total vêm do banco.
- **Nenhuma senha no código:** banco, segredo da sessão e usuários iniciais vêm de variáveis de ambiente.
- Senhas com `password_hash` (bcrypt), consultas com *prepared statements*, proteção contra CSRF (a API só aceita JSON) e saída HTML escapada contra XSS.
- O webhook do Mercado Pago não confia no aviso recebido: o status é sempre consultado direto na API do Mercado Pago (e a assinatura é conferida se o *Webhook Secret* estiver configurado).

---

## Estrutura

```
cloudchefs/
├── vercel.json            # configuração do Vercel (runtime PHP e rotas)
├── dev-router.php         # para rodar localmente com "php -S"
├── api/                   # cada arquivo .php vira um endpoint /api/...
│   ├── login.php  logout.php  me.php  health.php
│   ├── pedidos.php  produtos.php  estoque.php  usuarios.php
│   ├── financeiro.php  relatorios.php
│   ├── pix_criar.php  pix_consultar.php  pix_webhook.php
│   ├── mp_config.php  mp_testar.php
│   └── _lib/              # código compartilhado (não são endpoints)
│       ├── bootstrap.php  db.php  auth.php  pedidos.php  mercadopago.php
│       ├── schema.sql     # estrutura do banco
│       └── seed.sql       # cardápio, estoque e 14 dias de vendas de exemplo
└── frontend/              # páginas, css/app.css, js/app.js, img/
```

Na **primeira conexão** com um banco vazio, a API cria as tabelas e os dados de exemplo sozinha.

---

## Variáveis de ambiente

| Variável | Obrigatória | Descrição |
|---|---|---|
| `DATABASE_URL` | sim | String de conexão do PostgreSQL. A integração Neon do Vercel cria automaticamente. |
| `APP_SECRET` | recomendada | Texto aleatório longo para assinar as sessões. |
| `ADMIN_PASSWORD` | sim (1º acesso) | Senha do gerente criado no primeiro login. Login: `gerente` (ou `ADMIN_LOGIN`). |
| `ATENDENTE_PASSWORD` | opcional | Cria também o usuário `atendente` (ou `ATENDENTE_LOGIN`) para demonstração. |

Os usuários iniciais só são criados quando a tabela de usuários está vazia. Depois disso, as senhas são trocadas pela tela **Colaboradores**.

---

## Publicar no Vercel

1. Importe o repositório no Vercel e defina **Root Directory = `cloudchefs`** (Framework: *Other*).
2. No projeto, abra **Storage → Create Database → Neon** e conecte ao projeto (isso cria `DATABASE_URL`).
3. Em **Settings → Environment Variables**, crie `APP_SECRET`, `ADMIN_PASSWORD` e, se quiser, `ATENDENTE_PASSWORD`.
4. Faça um novo deploy e acesse `https://SEU-PROJETO.vercel.app/api/health.php` para conferir: deve responder `"banco": "ok"`.
5. Entre com o usuário `gerente` e a senha definida em `ADMIN_PASSWORD`.

## Rodar no computador

Precisa de PHP 8.1+ com a extensão `pdo_pgsql` e um PostgreSQL.

```bash
cd cloudchefs
export DATABASE_URL="postgresql://postgres:senha@localhost:5432/cloudchefs"
export ADMIN_PASSWORD="troque-esta-senha"
export APP_SECRET="qualquer-texto-longo-e-aleatorio"
php -S localhost:8000 dev-router.php
```

Abra <http://localhost:8000>.

---

## Pix com Mercado Pago

1. Crie uma aplicação em [Mercado Pago Developers](https://www.mercadopago.com.br/developers/panel/app) e copie o **Access Token de teste**.
2. No sistema, entre como gerente → **Mercado Pago** → cole o token → **Salvar** → **Testar conexão**.
3. O webhook usa por padrão `https://SEU-PROJETO.vercel.app/api/pix_webhook.php`. Cadastre essa URL no painel do Mercado Pago (evento *Pagamentos*) e, se quiser, salve a assinatura secreta.

Sem credenciais, o Pix fica em **modo demonstração**: nenhuma cobrança real é criada e a tela mostra o botão *Simular pagamento aprovado*.
