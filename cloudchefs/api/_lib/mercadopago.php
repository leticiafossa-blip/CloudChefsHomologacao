<?php
/**
 * Integração com a API do Mercado Pago (Pix).
 */
declare(strict_types=1);

/** Configuração ativa do Mercado Pago, ou null se o gerente ainda não configurou. */
function mp_config(PDO $db): ?array
{
    $config = $db->query('SELECT * FROM mercado_pago_config WHERE ativo ORDER BY id_config DESC LIMIT 1')->fetch();
    return ($config && !empty($config['access_token'])) ? $config : null;
}

/** Faz uma chamada à API do Mercado Pago e devolve o JSON da resposta. */
function mp_request(string $metodo, string $endpoint, string $accessToken, ?array $corpo = null, ?string $idempotencia = null): array
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];
    if ($idempotencia) {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencia;
    }

    $ch = curl_init('https://api.mercadopago.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }

    $resposta = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falha = curl_error($ch);
    curl_close($ch);

    if ($falha) {
        throw new ErroNegocio('Não foi possível falar com o Mercado Pago: ' . $falha);
    }
    $json = json_decode((string) $resposta, true) ?: [];
    if ($http < 200 || $http >= 300) {
        $msg = $json['message'] ?? $json['error'] ?? 'erro desconhecido';
        throw new ErroNegocio("O Mercado Pago recusou a operação (HTTP $http): $msg");
    }
    return $json;
}

/** Consulta o pagamento no Mercado Pago e atualiza o pedido. */
function sincronizar_pagamento_mp(PDO $db, array $config, string $paymentId, ?int $idPedido = null): array
{
    $pagamento = mp_request('GET', '/v1/payments/' . rawurlencode($paymentId), $config['access_token']);

    $status = $pagamento['status'] ?? 'unknown';
    $detalhe = $pagamento['status_detail'] ?? null;
    $idPedido = $idPedido ?: (int) ($pagamento['external_reference'] ?? 0);
    if (!$idPedido) {
        throw new ErroNegocio('Pagamento sem referência de pedido.');
    }

    if ($status === 'approved') {
        aprovar_pagamento_pix($db, $idPedido, $detalhe);
    } else {
        $db->prepare('UPDATE pedido SET status_pagamento = :s, mp_status_detalhe = :d WHERE id_pedido = :id AND status = \'Aguardando PIX\'')
           ->execute([':s' => $status, ':d' => $detalhe, ':id' => $idPedido]);
    }

    return ['id_pedido' => $idPedido, 'status' => $status, 'pago' => $status === 'approved'];
}

/**
 * Confere a assinatura enviada pelo Mercado Pago no webhook.
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
 */
function validar_assinatura_webhook_mp(string $segredo, string $dataId): bool
{
    $assinatura = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if ($assinatura === '' || $requestId === '') {
        return false;
    }

    $ts = $hash = null;
    foreach (explode(',', $assinatura) as $parte) {
        [$chave, $valor] = array_pad(array_map('trim', explode('=', $parte, 2)), 2, '');
        if ($chave === 'ts') $ts = $valor;
        if ($chave === 'v1') $hash = $valor;
    }
    if (!$ts || !$hash) {
        return false;
    }

    if (ctype_alnum($dataId)) {
        $dataId = strtolower($dataId);
    }
    $manifesto = "id:$dataId;request-id:$requestId;ts:$ts;";
    return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $hash);
}

/** Endereço público do webhook deste site. */
function url_webhook_padrao(): string
{
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'seu-site.vercel.app';
    return (conexao_https() ? 'https' : 'http') . '://' . $host . '/api/pix_webhook.php';
}
