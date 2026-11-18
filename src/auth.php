<?php
declare(strict_types=1);

const MAX_AUTH_ATTEMPTS = 10;      // par IP
const AUTH_WINDOW       = 15 * 60; // sur 15 minutes
const ACTIVATION_TTL    = 7 * 86400;

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function send_security_headers(): void
{
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
}

function start_session(): void
{
    $https = is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (30 * 86400));
    session_name('guildraid');
    session_set_cookie_params([
        'lifetime' => 30 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('Jeton de sécurité expiré. Recharge la page puis recommence.');
    }
}

/** Empreinte du mot de passe gardée en session : changer le mot de passe déconnecte les autres sessions. */
function pw_fingerprint(?string $hash): string
{
    return hash('sha256', (string) $hash);
}

function current_member(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    $id = (int) ($_SESSION['mid'] ?? 0);
    if ($id > 0) {
        $m = member_by_id($id);
        if ($m && $m['password_hash'] && hash_equals((string) ($_SESSION['pwv'] ?? ''), pw_fingerprint($m['password_hash']))) {
            $cache = $m;
        } else {
            unset($_SESSION['mid'], $_SESSION['pwv']);
        }
    }
    return $cache;
}

function require_login(): array
{
    $me = current_member();
    if (!$me) {
        if (!is_post()) {
            $_SESSION['after_login'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }
        redirect('login.php');
    }
    return $me;
}

function require_officer(): array
{
    $me = require_login();
    if (!(int) $me['is_officer']) {
        http_response_code(403);
        page_header('Accès réservé');
        echo '<p class="empty">Cette page est réservée aux officiers de la légion.</p>';
        page_footer();
        exit;
    }
    return $me;
}

function login_member(array $m): void
{
    session_regenerate_id(true);
    $_SESSION['mid'] = (int) $m['id'];
    $_SESSION['pwv'] = pw_fingerprint($m['password_hash']);
}

function after_login_url(): string
{
    $url = (string) ($_SESSION['after_login'] ?? '');
    unset($_SESSION['after_login']);
    // Seulement un chemin local (pas de redirection ouverte vers un autre site)
    return (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) ? $url : 'index.php';
}

function attempt_login(string $pseudo, string $password): ?array
{
    $m = member_by_pseudo($pseudo);
    $hash = $m['password_hash'] ?? null;
    if (!$hash) {
        // Même coût de calcul que pour un vrai compte : ne révèle pas quels pseudos sont activés
        password_verify($password, '$2y$10$H0uYxyI4MukluN8kJyCH9u1s9MUECUa2p48nxkH6bU3p61N1aebrO');
        return null;
    }
    return password_verify($password, $hash) ? $m : null;
}

function rate_limited(): bool
{
    q('DELETE FROM auth_attempts WHERE ts < ?', [time() - AUTH_WINDOW]);
    return (int) q('SELECT COUNT(*) FROM auth_attempts WHERE ip = ?', [client_ip()])->fetchColumn() >= MAX_AUTH_ATTEMPTS;
}

function record_failed_attempt(): void
{
    q('INSERT INTO auth_attempts (ip, ts) VALUES (?, ?)', [client_ip(), time()]);
}

function validate_password(string $pw): ?string
{
    if (mb_strlen($pw) < 8) return 'Le mot de passe doit faire au moins 8 caractères.';
    if (mb_strlen($pw) > 200) return 'Le mot de passe est trop long.';
    return null;
}

function validate_pseudo(string $pseudo): ?string
{
    if ($pseudo === '') return 'Indique ton pseudo en jeu.';
    if (!preg_match('/^[\p{L}\p{N}_\-]{2,20}$/u', $pseudo)) return 'Le pseudo doit faire 2 à 20 caractères (lettres, chiffres, - ou _).';
    return null;
}

/** Code d'activation à usage unique, transmis par un officier (Discord, MP…). Seul son hash est stocké. */
function issue_activation_code(int $memberId): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    q('UPDATE members SET activation_hash = ?, activation_expires = ? WHERE id = ?',
        [hash('sha256', $code), time() + ACTIVATION_TTL, $memberId]);
    return substr($code, 0, 5) . '-' . substr($code, 5);
}

function check_activation_code(array $m, string $code): bool
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    return $m['activation_hash'] && (int) $m['activation_expires'] > time()
        && hash_equals((string) $m['activation_hash'], hash('sha256', $code));
}
