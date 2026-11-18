<?php
/**
 * POST /api/pix_consultar.php  { id_pedido, simular? }
 * Consulta se o Pix de um pedido já foi pago (a tela chama a cada poucos segundos).
 * Com "simular": true e o Mercado Pago NÃO configurado, aprova o pagamento em
 * modo demonstração — útil para apresentar o fluxo sem dinheiro real.
 */
require __DIR__ . '/_lib/bootstrap.php';

metodo(['POST']);
exigir_login();
$db = db();
$dados = entrada();
$idPedido = id($dados, 'id_pedido');

$stmt = $db->prepare('SELECT status, forma_pagamento, mercado_pago_payment_id FROM pedido WHERE id_pedido = :id');
$stmt->execute([':id' => $idPedido]);
$pedido = $stmt->fetch();
if (!$pedido || $pedido['forma_pagamento'] !== 'PIX') {
    erro('Pedido Pix não encontrado.', 404);
}
if ($pedido['status'] === 'Cancelado') {
    responder(['success' => true, 'pago' => false, 'status' => 'cancelado']);
}
if ($pedido['status'] !== 'Aguardando PIX') {
    responder(['success' => true, 'pago' => true, 'status' => 'approved']);
}

$config = mp_config($db);

if (!empty($dados['simular'])) {
    if ($config) {
        erro('A simulação só funciona em modo demonstração (sem Mercado Pago configurado).', 409);
    }
    aprovar_pagamento_pix($db, $idPedido, 'simulado (modo demonstração)');
    responder(['success' => true, 'pago' => true, 'status' => 'approved', 'demo' => true]);
}

if (!$config || empty($pedido['mercado_pago_payment_id'])) {
    responder(['success' => true, 'pago' => false, 'status' => 'pending']);
}

$resultado = sincronizar_pagamento_mp($db, $config, $pedido['mercado_pago_payment_id'], $idPedido);
responder(['success' => true, 'pago' => $resultado['pago'], 'status' => $resultado['status']]);
