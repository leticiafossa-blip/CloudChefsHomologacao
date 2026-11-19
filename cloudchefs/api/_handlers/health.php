<?php
// GET /api/health.php  -> confere se a API e o banco estão respondendo
require __DIR__ . '/../_lib/bootstrap.php';

metodo(['GET']);
db()->query('SELECT 1');
responder(['success' => true, 'banco' => 'ok', 'php' => PHP_VERSION]);
