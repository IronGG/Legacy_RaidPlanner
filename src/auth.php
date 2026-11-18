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

/*
 * Permissions. Officier : tout (salles, membres, accès, listes du site). Raid lead : gère toutes les salles
 * et consulte les profils des membres, sans rien modifier. Les deux sont attribués par un officier.
 */
function is_officer(array $m): bool
{
    return (int) ($m['is_officer'] ?? 0) === 1;
}

function is_raid_lead(array $m): bool
{
    return (int) ($m['is_raid_lead'] ?? 0) === 1;
}

/** Gérer toutes les salles et voir les profils : officiers et raid leads. */
function can_lead(array $m): bool
{
    return is_officer($m) || is_raid_lead($m);
}

/** Le rang le plus élevé du membre, pour son tag : 'officer', 'raid_lead' ou null. */
function member_rank(array $m): ?string
{
    return is_officer($m) ? 'officer' : (is_raid_lead($m) ? 'raid_lead' : null);
}

function require_officer(): array
{
    $me = require_login();
    if (!is_officer($me)) {
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

/*
 * Poivre : clé secrète gardée hors de la base (data/pepper.php). Chaque mot de passe passe dans un HMAC-SHA256
 * avec cette clé avant bcrypt (password_hash, avec son sel). Une copie de la base seule ne permet donc pas de
 * tester des mots de passe. Sans ce fichier, plus personne ne peut se connecter : il faut en garder une copie.
 */
function pepper_path(): string
{
    return (string) (cfg('pepper_path') ?: __DIR__ . '/../data/pepper.php');
}

function pepper_fail(string $msg): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration incomplète : la clé de sécurité des mots de passe est introuvable ou invalide.\nUn officier doit restaurer data/pepper.php (voir le README, section Sauvegarde).");
}

/** Crée la clé si elle n'existe pas encore. Renvoie true si elle vient d'être créée. */
function pepper_ensure(): bool
{
    $path = pepper_path();
    if (is_file($path)) return false;
    // Des comptes existent déjà : une nouvelle clé les rendrait tous inutilisables. On refuse.
    if ((int) q('SELECT EXISTS(SELECT 1 FROM members WHERE password_hash IS NOT NULL OR activation_hash IS NOT NULL)')->fetchColumn()) {
        pepper_fail('Clé de poivre introuvable (' . $path . ") alors que des comptes existent déjà.\n"
            . "Restaure la copie de data/pepper.php, ou repars d'une base vide.");
    }
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $fh = @fopen($path, 'x'); // 'x' : échoue si une autre requête vient de la créer
    if ($fh === false) return false;
    fwrite($fh, pepper_file_content());
    fclose($fh);
    @chmod($path, 0600);
    return true;
}

/** Contenu d'un fichier de clé neuf (32 octets aléatoires, en base64). */
function pepper_file_content(): string
{
    $key = base64_encode(random_bytes(32));
    return "<?php\n// Poivre des mots de passe de GuildRaid. NE PAS MODIFIER NI SUPPRIMER : sans cette clé, plus personne\n"
        . "// ne peut se connecter. Garde-en une copie à part (gestionnaire de mots de passe), pas avec les sauvegardes.\n"
        . "// Pour en changer : php bin/pepper.php rotate\n"
        . "return '$key';\n";
}

/** Empreinte courte de la clé : sert à vérifier qu'elle n'a pas changé, sans la montrer. */
function pepper_fingerprint(): string
{
    return substr(hash('sha256', 'guildraid-pepper-id' . pepper()), 0, 12);
}

/** $reload : relit le fichier (après un changement de clé dans le même processus). */
function pepper(bool $reload = false): string
{
    static $key = null;
    if ($reload) $key = null;
    if ($key !== null) return $key;
    pepper_ensure();
    $path = pepper_path();
    $raw = is_file($path) ? (static fn(string $f): mixed => require $f)($path) : null;
    $bin = is_string($raw) ? base64_decode($raw, true) : false;
    if ($bin === false || strlen($bin) < 32) pepper_fail('Clé de poivre invalide : ' . $path);
    return $key = $bin;
}

/** Mot de passe poivré, encodé en base64 (44 caractères : sous la limite de 72 octets de bcrypt, sans octet nul). */
function pw_peppered(string $password): string
{
    return base64_encode(hash_hmac('sha256', $password, pepper(), true));
}

function pw_hash(string $password): string
{
    return password_hash(pw_peppered($password), PASSWORD_DEFAULT);
}

function pw_verify(string $password, string $hash): bool
{
    return password_verify(pw_peppered($password), $hash);
}

function attempt_login(string $pseudo, string $password): ?array
{
    $m = member_by_pseudo($pseudo);
    $hash = $m['password_hash'] ?? null;
    if (!$hash) {
        // Même coût de calcul que pour un vrai compte : ne révèle pas quels pseudos sont activés
        pw_verify($password, '$2y$10$H0uYxyI4MukluN8kJyCH9u1s9MUECUa2p48nxkH6bU3p61N1aebrO');
        return null;
    }
    if (!pw_verify($password, $hash)) return null;
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        // Coût de bcrypt relevé par une version plus récente de PHP : on met l'empreinte à niveau
        q('UPDATE members SET password_hash = ? WHERE id = ?', [pw_hash($password), $m['id']]);
        $m = member_by_id((int) $m['id']);
    }
    return $m;
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

/** Code d'activation à usage unique, transmis par un officier (Discord, MP…). Seule son empreinte est stockée. */
function issue_activation_code(int $memberId): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    q('UPDATE members SET activation_hash = ?, activation_expires = ? WHERE id = ?',
        [code_hash($code), time() + ACTIVATION_TTL, $memberId]);
    return substr($code, 0, 5) . '-' . substr($code, 5);
}

function check_activation_code(array $m, string $code): bool
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    return $m['activation_hash'] && (int) $m['activation_expires'] > time()
        && hash_equals((string) $m['activation_hash'], code_hash($code));
}

/** Empreinte d'un code d'activation (HMAC avec le poivre : la base seule ne permet pas de les retrouver). */
function code_hash(string $code): string
{
    return hash_hmac('sha256', $code, pepper());
}
