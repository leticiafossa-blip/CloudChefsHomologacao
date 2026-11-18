<?php
/**
 * POST /api/pix_webhook.php
 * Endereço público que o Mercado Pago chama quando um pagamento muda de status.
 *
 * Segurança: o aviso só serve de "gatilho". O status real é sempre consultado
 * direto na API do Mercado Pago com o nosso Access Token, então um aviso falso
 * não consegue aprovar pedido nenhum. Se o gerente salvar o "Webhook Secret",
 * a assinatura do aviso também é conferida.
 */
require __DIR__ . '/_lib/bootstrap.php';

metodo(['POST']);
$db = db();
$config = mp_config($db);
if (!$config) {
    responder(['success' => true, 'mensagem' => 'Mercado Pago não configurado; aviso ignorado.']);
}

$corpo = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
// O PHP troca "." por "_" nos parâmetros da URL: "data.id" chega como "data_id".
$paymentId = (string) ($corpo['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');
$tipo = (string) ($corpo['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');

if (!empty($config['webhook_secret']) && !validar_assinatura_webhook_mp($config['webhook_secret'], $paymentId)) {
    erro('Assinatura inválida.', 401);
}

if ($paymentId === '' || !ctype_alnum($paymentId) || ($tipo !== '' && $tipo !== 'payment')) {
    responder(['success' => true, 'mensagem' => 'Aviso ignorado.']);
}

$resultado = sincronizar_pagamento_mp($db, $config, $paymentId);
responder(['success' => true, 'resultado' => $resultado]);
