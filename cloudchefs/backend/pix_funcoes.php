<?php
/**
 * Funções compartilhadas da integração Pix Mercado Pago.
 * Este arquivo NÃO deve ser chamado direto pelo navegador.
 */

function mp_json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function mp_get_config(PDO $db) {
    $stmt = $db->query("SELECT * FROM mercado_pago_config WHERE ativo = TRUE ORDER BY id_config DESC LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        throw new Exception("Mercado Pago ainda não foi configurado pelo gerente.");
    }

    return $config;
}

function mp_request($method, $endpoint, $accessToken, $body = null, $idempotencyKey = null) {
    $url = "https://api.mercadopago.com" . $endpoint;

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $accessToken
    ];

    if ($idempotencyKey) {
        $headers[] = "X-Idempotency-Key: " . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Erro de conexão com Mercado Pago: " . $curlError);
    }

    $json = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $json['message'] ?? $json['error'] ?? $response ?? 'Erro desconhecido no Mercado Pago';
        throw new Exception("Mercado Pago retornou erro HTTP {$httpCode}: " . $msg);
    }

    return $json;
}

function baixar_estoque_do_pedido(PDO $db, $idPedido) {
    $stmtPedido = $db->prepare("SELECT estoque_baixado FROM pedido WHERE id_pedido = :id FOR UPDATE");
    $stmtPedido->execute([':id' => $idPedido]);
    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        throw new Exception("Pedido não encontrado para baixar estoque.");
    }

    if ($pedido['estoque_baixado'] === true || $pedido['estoque_baixado'] === 't' || $pedido['estoque_baixado'] === 1 || $pedido['estoque_baixado'] === '1') {
        return;
    }

    $stmtItens = $db->prepare("SELECT nome_produto, quantidade FROM itens_pedido WHERE id_pedido_fk = :id");
    $stmtItens->execute([':id' => $idPedido]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    $stmtReceita = $db->prepare("
        SELECT id_item_estoque, quantidade_necessaria
        FROM composicao_produto
        WHERE id_produto = (SELECT id_produto FROM produto WHERE nome = :nome_prod LIMIT 1)
    ");

    $stmtEstoque = $db->prepare("
        UPDATE estoque
        SET quantidade_atual = quantidade_atual - :qtd_deduzir
        WHERE id_item = :id_item
        AND quantidade_atual >= :qtd_deduzir
    ");

    foreach ($itens as $item) {
        $nomeProduto = $item['nome_produto'];
        $quantidadePedido = (float) $item['quantidade'];

        $stmtReceita->execute([':nome_prod' => $nomeProduto]);
        $ingredientes = $stmtReceita->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ingredientes as $ing) {
            $qtdDeduzir = (float) $ing['quantidade_necessaria'] * $quantidadePedido;

            $stmtEstoque->execute([
                ':qtd_deduzir' => $qtdDeduzir,
                ':id_item' => $ing['id_item_estoque']
            ]);

            if ($stmtEstoque->rowCount() === 0) {
                throw new Exception("Estoque insuficiente para o produto: " . $nomeProduto);
            }
        }
    }

    $stmtUpdate = $db->prepare("UPDATE pedido SET estoque_baixado = TRUE WHERE id_pedido = :id");
    $stmtUpdate->execute([':id' => $idPedido]);
}

function sincronizar_pagamento_mp(PDO $db, $paymentId = null, $idPedido = null) {
    $config = mp_get_config($db);

    if (!$paymentId && $idPedido) {
        $stmt = $db->prepare("SELECT mercado_pago_payment_id FROM pedido WHERE id_pedido = :id");
        $stmt->execute([':id' => $idPedido]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        $paymentId = $pedido['mercado_pago_payment_id'] ?? null;
    }

    if (!$paymentId) {
        throw new Exception("ID do pagamento Mercado Pago não encontrado.");
    }

    $pagamento = mp_request('GET', '/v1/payments/' . urlencode($paymentId), $config['access_token']);

    $status = $pagamento['status'] ?? 'unknown';
    $statusDetalhe = $pagamento['status_detail'] ?? null;
    $externalReference = $pagamento['external_reference'] ?? null;
    $pedidoIdFinal = $idPedido ?: $externalReference;

    if (!$pedidoIdFinal) {
        throw new Exception("Pagamento sem referência de pedido.");
    }

    if ($status === 'approved') {
        $db->beginTransaction();
        try {
            baixar_estoque_do_pedido($db, $pedidoIdFinal);

            $stmt = $db->prepare("
                UPDATE pedido
                SET status_pagamento = 'aprovado',
                    status = 'Pendente',
                    mp_status_detalhe = :detalhe
                WHERE id_pedido = :id
            ");
            $stmt->execute([
                ':detalhe' => $statusDetalhe,
                ':id' => $pedidoIdFinal
            ]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } else {
        $stmt = $db->prepare("
            UPDATE pedido
            SET status_pagamento = :status_pagamento,
                mp_status_detalhe = :detalhe
            WHERE id_pedido = :id
        ");
        $stmt->execute([
            ':status_pagamento' => $status,
            ':detalhe' => $statusDetalhe,
            ':id' => $pedidoIdFinal
        ]);
    }

    return [
        'payment_id' => $paymentId,
        'id_pedido' => $pedidoIdFinal,
        'status' => $status,
        'status_detail' => $statusDetalhe,
        'pago' => $status === 'approved'
    ];
}

function validar_assinatura_webhook_mp($webhookSecret) {
    if (!$webhookSecret) {
        return true;
    }

    $xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

    if (!$xSignature || !$xRequestId) {
        return false;
    }

    $dataID = $_GET['data.id'] ?? $_GET['id'] ?? '';
    if ($dataID !== '' && ctype_alnum(str_replace(['-', '_'], '', $dataID))) {
        $dataID = strtolower($dataID);
    }

    $parts = explode(',', $xSignature);
    $ts = null;
    $hash = null;

    foreach ($parts as $part) {
        $keyValue = explode('=', $part, 2);
        if (count($keyValue) === 2) {
            $key = trim($keyValue[0]);
            $value = trim($keyValue[1]);

            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = $value;
            }
        }
    }

    if (!$ts || !$hash) {
        return false;
    }

    $manifest = "id:$dataID;request-id:$xRequestId;ts:$ts;";
    $sha = hash_hmac('sha256', $manifest, $webhookSecret);

    return hash_equals($sha, $hash);
}
?>
