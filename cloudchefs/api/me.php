<?php
// GET /api/me.php  -> usuário da sessão atual (usado pelas páginas para saber quem está logado)
require __DIR__ . '/_lib/bootstrap.php';

metodo(['GET']);
responder(['success' => true, 'usuario' => exigir_login()]);
