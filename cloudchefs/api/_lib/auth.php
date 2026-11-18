<?php
/**
 * Autenticação.
 *
 * Depois do login, o servidor entrega um "token" assinado num cookie HttpOnly
 * (o JavaScript da página não consegue ler nem alterar esse cookie).
 * Em toda requisição, a API confere a assinatura e busca o usuário no banco,
 * então o perfil (GERENTE/ATENDENTE) é sempre o que está gravado no banco,
 * e não o que o navegador diz.
 *
 * Usamos token assinado em vez de session_start() porque no Vercel cada
 * requisição pode rodar numa máquina diferente, sem arquivos de sessão.
 */
declare(strict_types=1);

const COOKIE_SESSAO = 'cc_sessao';
const DURACAO_SESSAO = 12 * 3600; // 12 horas

function segredo_app(): string
{
    $segredo = env('APP_SECRET');
    if ($segredo) {
        return $segredo;
    }
    // Sem APP_SECRET, derivamos um segredo da senha do banco (que já é secreta).
    $c = config_banco();
    return hash('sha256', 'cloudchefs|' . $c['host'] . '|' . $c['user'] . '|' . $c['pass']);
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $txt): string
{
    return (string) base64_decode(strtr($txt, '-_', '+/'));
}

function conexao_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function iniciar_sessao(array $usuario): void
{
    $expira = time() + DURACAO_SESSAO;
    $payload = b64url(json_encode(['id' => (int) $usuario['id_usuario'], 'exp' => $expira]));
    $assinatura = b64url(hash_hmac('sha256', $payload, segredo_app(), true));

    setcookie(COOKIE_SESSAO, "$payload.$assinatura", [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => conexao_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function encerrar_sessao(): void
{
    setcookie(COOKIE_SESSAO, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => conexao_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Devolve o usuário logado (ou null), sempre lido do banco. */
function usuario_atual(): ?array
{
    static $usuario = false;
    if ($usuario !== false) {
        return $usuario;
    }
    $usuario = null;

    $token = $_COOKIE[COOKIE_SESSAO] ?? '';
    if (!str_contains($token, '.')) {
        return null;
    }
    [$payload, $assinatura] = explode('.', $token, 2);
    $esperada = b64url(hash_hmac('sha256', $payload, segredo_app(), true));
    if (!hash_equals($esperada, $assinatura)) {
        return null;
    }
    $dados = json_decode(b64url_decode($payload), true);
    if (!is_array($dados) || ($dados['exp'] ?? 0) < time()) {
        return null;
    }

    $stmt = db()->prepare('SELECT id_usuario, nome, login, tipo_usuario FROM usuario WHERE id_usuario = :id AND ativo');
    $stmt->execute([':id' => (int) ($dados['id'] ?? 0)]);
    $usuario = $stmt->fetch() ?: null;
    return $usuario;
}

function exigir_login(): array
{
    $usuario = usuario_atual();
    if (!$usuario) {
        erro('Sessão expirada. Faça login novamente.', 401);
    }
    return $usuario;
}

function exigir_gerente(): array
{
    $usuario = exigir_login();
    if ($usuario['tipo_usuario'] !== 'GERENTE') {
        erro('Apenas o gerente pode fazer isso.', 403);
    }
    return $usuario;
}

/**
 * Se ainda não existe nenhum usuário, cria o gerente inicial (e, se configurado,
 * um atendente de demonstração) a partir das variáveis de ambiente.
 * Assim nenhuma senha fica escrita no código.
 */
function garantir_usuarios_iniciais(): void
{
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM usuario')->fetchColumn() > 0) {
        return;
    }

    $iniciais = [];
    if (env('ADMIN_PASSWORD')) {
        $iniciais[] = [env('ADMIN_NAME') ?? 'Gerente', env('ADMIN_LOGIN') ?? 'gerente', env('ADMIN_PASSWORD'), 'GERENTE'];
    }
    if (env('ATENDENTE_PASSWORD')) {
        $iniciais[] = ['Atendente', env('ATENDENTE_LOGIN') ?? 'atendente', env('ATENDENTE_PASSWORD'), 'ATENDENTE'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO usuario (nome, login, senha, tipo_usuario) VALUES (:nome, :login, :senha, :tipo)
         ON CONFLICT (login) DO NOTHING'
    );
    foreach ($iniciais as [$nome, $login, $senha, $tipo]) {
        $stmt->execute([
            ':nome'  => $nome,
            ':login' => strtolower($login),
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ':tipo'  => $tipo,
        ]);
    }
}
