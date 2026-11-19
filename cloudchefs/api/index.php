<?php
/**
 * Ponto único de entrada da API.
 *
 * O plano Hobby do Vercel aceita no máximo 12 funções por deploy, então em vez
 * de uma função por arquivo, o vercel.json encaminha todo /api/<nome>.php para
 * cá e este arquivo executa o handler correspondente em api/_handlers/<nome>.php.
 * As URLs que o frontend usa continuam exatamente as mesmas.
 */
$nome = (string) ($_GET['_ep'] ?? '');
unset($_GET['_ep'], $_REQUEST['_ep']);

$arquivo = preg_match('/^[a-z_]+$/', $nome) ? __DIR__ . '/_handlers/' . $nome . '.php' : null;

if ($arquivo === null || !is_file($arquivo)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Endpoint não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SERVER['SCRIPT_NAME'] = '/api/' . $nome . '.php';
require $arquivo;
