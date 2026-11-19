<?php
/**
 * Configuração do Mercado Pago (somente gerente).
 *   GET  /api/mp_config.php  -> configuração atual (o token aparece mascarado)
 *   POST /api/mp_config.php  -> salva { ambiente, public_key, access_token, webhook_url, webhook_secret }
 *                               Campos sensíveis em branco mantêm o valor atual.
 *   DELETE /api/mp_config.php -> desativa (volta ao modo demonstração)
 */
require __DIR__ . '/../_lib/bootstrap.php';

$metodo = metodo(['GET', 'POST', 'DELETE']);
exigir_gerente();
$db = db();
$atual = mp_config($db);

function mascarar(string $valor): string
{
    return strlen($valor) > 14 ? substr($valor, 0, 8) . '…' . substr($valor, -4) : '••••';
}

if ($metodo === 'GET') {
    responder([
        'success'               => true,
        'configurado'           => (bool) $atual,
        'ambiente'              => $atual['ambiente'] ?? 'teste',
        'public_key'            => $atual['public_key'] ?? '',
        'access_token_mascarado'=> $atual ? mascarar($atual['access_token']) : '',
        'webhook_url'           => $atual['webhook_url'] ?? '',
        'webhook_url_sugerida'  => url_webhook_padrao(),
        'possui_webhook_secret' => !empty($atual['webhook_secret']),
    ]);
}

if ($metodo === 'DELETE') {
    entrada();
    $db->exec('UPDATE mercado_pago_config SET ativo = FALSE, atualizado_em = NOW() WHERE ativo');
    responder(['success' => true]);
}

$dados = entrada();
$ambiente = $dados['ambiente'] ?? 'teste';
if (!in_array($ambiente, ['teste', 'producao'], true)) {
    erro('Ambiente inválido.');
}
$token = texto($dados, 'access_token', false, 500) ?? ($atual['access_token'] ?? null);
if (!$token) {
    erro('Informe o Access Token do Mercado Pago.');
}
$webhookUrl = texto($dados, 'webhook_url', false, 500);
if ($webhookUrl !== null && !preg_match('#^https://\S+$#', $webhookUrl)) {
    erro('A URL do webhook precisa começar com https://');
}

$db->beginTransaction();
$db->exec('UPDATE mercado_pago_config SET ativo = FALSE, atualizado_em = NOW() WHERE ativo');
$db->prepare('
    INSERT INTO mercado_pago_config (public_key, access_token, webhook_url, webhook_secret, ambiente, ativo)
    VALUES (:pk, :token, :url, :secret, :amb, TRUE)
')->execute([
    ':pk'     => texto($dados, 'public_key', false, 500),
    ':token'  => $token,
    ':url'    => $webhookUrl,
    ':secret' => texto($dados, 'webhook_secret', false, 500) ?? ($atual['webhook_secret'] ?? null),
    ':amb'    => $ambiente,
]);
$db->commit();

responder(['success' => true]);
