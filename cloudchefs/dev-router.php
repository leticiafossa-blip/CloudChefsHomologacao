<?php
/**
 * Roteador para rodar o projeto no seu computador, imitando o Vercel:
 *
 *   php -S localhost:8000 dev-router.php
 *
 * Depois abra http://localhost:8000
 * (as variáveis de ambiente do banco precisam estar definidas; veja o README).
 */
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// /api/arquivo.php -> roda o PHP
if (preg_match('#^/api/([a-z_]+)\.php$#', $caminho, $m)) {
    $_GET['_ep'] = $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}
if (str_starts_with($caminho, '/api/')) {
    http_response_code(404);
    return true;
}

// Qualquer outro caminho -> arquivo da pasta frontend
$arquivo = $caminho === '/' ? '/login.html' : $caminho;
$completo = realpath(__DIR__ . '/frontend' . $arquivo);
if ($completo && str_starts_with($completo, realpath(__DIR__ . '/frontend')) && is_file($completo)) {
    $tipos = ['html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($tipos[pathinfo($completo, PATHINFO_EXTENSION)] ?? 'application/octet-stream') . '; charset=utf-8');
    readfile($completo);
    return true;
}

http_response_code(404);
echo 'Página não encontrada.';
return true;
