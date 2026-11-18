<?php
// GET /api/mp_testar.php  -> confere se o Access Token salvo funciona (somente gerente)
require __DIR__ . '/_lib/bootstrap.php';

metodo(['GET']);
exigir_gerente();
$config = mp_config(db());
if (!$config) {
    erro('O Mercado Pago ainda não foi configurado.', 409);
}

$conta = mp_request('GET', '/users/me', $config['access_token']);
responder([
    'success'  => true,
    'nickname' => $conta['nickname'] ?? null,
    'site_id'  => $conta['site_id'] ?? null,
]);
