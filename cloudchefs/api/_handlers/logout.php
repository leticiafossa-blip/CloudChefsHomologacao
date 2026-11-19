<?php
// POST /api/logout.php
require __DIR__ . '/../_lib/bootstrap.php';

metodo(['POST']);
encerrar_sessao();
responder(['success' => true]);
