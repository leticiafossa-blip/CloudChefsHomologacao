<?php
/**
 * Arquivo incluído no início de todo endpoint da API.
 * Centraliza: cabeçalhos, leitura do JSON, respostas e tratamento de erros.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pedidos.php';
require_once __DIR__ . '/mercadopago.php';

/** Envia uma resposta JSON e encerra o script. */
function responder(array $dados, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Envia uma resposta de erro padronizada. */
function erro(string $mensagem, int $status = 400): never
{
    responder(['success' => false, 'error' => $mensagem], $status);
}

/** Garante que o método HTTP é um dos permitidos e devolve qual foi usado. */
function metodo(array $permitidos): string
{
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($metodo, $permitidos, true)) {
        header('Allow: ' . implode(', ', $permitidos));
        erro('Método não permitido.', 405);
    }
    return $metodo;
}

/**
 * Lê o corpo JSON da requisição.
 * Requisições que alteram dados precisam vir como application/json:
 * isso impede que outro site envie formulários em nome do usuário (CSRF),
 * porque o navegador não deixa um site de fora mandar JSON sem permissão.
 */
function entrada(): array
{
    $tipo = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($tipo, 'application/json') === false) {
        erro('Envie os dados em formato JSON.', 415);
    }
    $dados = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($dados)) {
        erro('JSON inválido.');
    }
    return $dados;
}

/** Lê um texto obrigatório (ou opcional) da entrada, já sem espaços nas pontas. */
function texto(array $dados, string $campo, bool $obrigatorio = true, int $max = 120): ?string
{
    $valor = isset($dados[$campo]) ? trim((string) $dados[$campo]) : '';
    if ($valor === '') {
        if ($obrigatorio) {
            erro("O campo \"$campo\" é obrigatório.");
        }
        return null;
    }
    if (mb_strlen($valor) > $max) {
        erro("O campo \"$campo\" pode ter no máximo $max caracteres.");
    }
    return $valor;
}

/** Lê um número da entrada e valida o intervalo. */
function numero(array $dados, string $campo, float $min = 0, ?float $max = null): float
{
    $valor = $dados[$campo] ?? null;
    if (is_string($valor)) {
        $valor = str_replace(',', '.', trim($valor));
    }
    if (!is_numeric($valor)) {
        erro("O campo \"$campo\" precisa ser um número.");
    }
    $valor = (float) $valor;
    if ($valor < $min || ($max !== null && $valor > $max)) {
        erro("Valor inválido para \"$campo\".");
    }
    return $valor;
}

/** Lê um id inteiro positivo. */
function id(array $dados, string $campo): int
{
    $valor = (string) ($dados[$campo] ?? '');
    if (!ctype_digit($valor) || (int) $valor <= 0) {
        erro("O campo \"$campo\" é inválido.");
    }
    return (int) $valor;
}

/** Valida uma data AAAA-MM-DD vinda da URL. */
function data_param(string $campo): ?string
{
    $valor = trim((string) ($_GET[$campo] ?? ''));
    if ($valor === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    if (!$d || $d->format('Y-m-d') !== $valor) {
        erro("Data inválida em \"$campo\". Use o formato AAAA-MM-DD.");
    }
    return $valor;
}

// Qualquer erro não tratado vira uma resposta JSON amigável.
// O detalhe técnico vai para o log do servidor, nunca para o navegador.
set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ErroNegocio) {
        erro($e->getMessage(), 409);
    }
    if ($e instanceof PDOException) {
        $estado = $e->errorInfo[0] ?? $e->getCode();
        if ($estado === '23505') {
            erro('Já existe um registro com esse nome ou login.', 409);
        }
        if ($estado === '23503') {
            erro('Esse registro está sendo usado em outro lugar e não pode ser removido.', 409);
        }
        if (str_starts_with((string) $estado, '08') || str_contains($e->getMessage(), 'connection')) {
            error_log('[cloudchefs] Falha de conexão: ' . $e->getMessage());
            erro('Não foi possível conectar ao banco de dados.', 503);
        }
    }
    if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'Banco não configurado')) {
        erro($e->getMessage(), 503);
    }
    error_log('[cloudchefs] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    erro('Erro interno no servidor. Tente novamente.', 500);
});
