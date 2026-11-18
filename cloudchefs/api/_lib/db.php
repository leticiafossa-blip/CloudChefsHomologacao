<?php
/**
 * Conexão com o PostgreSQL.
 *
 * As credenciais NUNCA ficam no código: elas vêm de variáveis de ambiente.
 * - No Vercel com Neon, a integração cria DATABASE_URL automaticamente.
 * - Localmente, você pode usar DATABASE_URL ou DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS.
 *
 * Na primeira conexão com um banco vazio, as tabelas e os dados de
 * exemplo são criados automaticamente (schema.sql + seed.sql).
 */
declare(strict_types=1);

function env(string $nome): ?string
{
    $valor = getenv($nome);
    if ($valor === false || $valor === '') {
        $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? null;
    }
    return ($valor === null || $valor === '') ? null : (string) $valor;
}

/** Monta os dados de conexão a partir das variáveis de ambiente. */
function config_banco(): array
{
    // A conexão direta (sem pooler) é preferida quando existir.
    $url = env('DATABASE_URL_UNPOOLED') ?? env('POSTGRES_URL_NON_POOLING')
        ?? env('DATABASE_URL') ?? env('POSTGRES_URL');

    if ($url) {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            throw new RuntimeException('DATABASE_URL inválida.');
        }
        parse_str($p['query'] ?? '', $query);
        return [
            'host'    => $p['host'],
            'port'    => (string) ($p['port'] ?? 5432),
            'dbname'  => ltrim($p['path'] ?? '/postgres', '/') ?: 'postgres',
            'user'    => urldecode($p['user'] ?? ''),
            'pass'    => urldecode($p['pass'] ?? ''),
            'sslmode' => $query['sslmode'] ?? null,
        ];
    }

    if (env('DB_HOST')) {
        return [
            'host'    => env('DB_HOST'),
            'port'    => env('DB_PORT') ?? '5432',
            'dbname'  => env('DB_NAME') ?? 'postgres',
            'user'    => env('DB_USER') ?? 'postgres',
            'pass'    => env('DB_PASS') ?? '',
            'sslmode' => env('DB_SSLMODE'),
        ];
    }

    throw new RuntimeException('Banco não configurado: defina a variável de ambiente DATABASE_URL.');
}

/** Devolve a conexão PDO (uma por requisição). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = config_banco();
    $local = in_array($c['host'], ['localhost', '127.0.0.1'], true) || str_starts_with($c['host'], '/');
    $sslmode = $c['sslmode'] ?? ($local ? 'prefer' : 'require');

    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']};sslmode={$sslmode}";

    // O Neon identifica o banco pelo nome do servidor (SNI). Bibliotecas antigas
    // não enviam essa informação, então passamos o "endpoint" explicitamente.
    if (preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\.[^.]+\..*neon\.tech$/i', $c['host'], $m)) {
        $dsn .= ";options='endpoint={$m[1]}'";
    }

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");

    preparar_banco($pdo);
    return $pdo;
}

/** Cria as tabelas e os dados de exemplo se o banco ainda estiver vazio. */
function preparar_banco(PDO $pdo): void
{
    if ($pdo->query("SELECT to_regclass('public.pedido') IS NOT NULL")->fetchColumn()) {
        return;
    }

    // Trava para duas requisições simultâneas não criarem tudo em dobro.
    $pdo->exec('SELECT pg_advisory_lock(727001)');
    try {
        if (!$pdo->query("SELECT to_regclass('public.pedido') IS NOT NULL")->fetchColumn()) {
            $pdo->beginTransaction();
            $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
            $pdo->exec(file_get_contents(__DIR__ . '/seed.sql'));
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SELECT pg_advisory_unlock(727001)');
    }
}
