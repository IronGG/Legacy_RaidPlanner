<?php
declare(strict_types=1);

/** Erreur à afficher telle quelle à l'utilisateur (message déjà rédigé pour lui). */
class UserError extends RuntimeException {}

function cfg(string $key): mixed
{
    return $GLOBALS['config'][$key] ?? null;
}

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Texte saisi, nettoyé et tronqué. */
function in_str(string $key, int $max = 200, ?array $src = null): string
{
    $src ??= $_POST;
    $v = trim((string) ($src[$key] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

/** Entier saisi (accepte « 2 984 »), null si vide ou invalide. */
function in_int(string $key, ?int $min = null, ?int $max = null, ?array $src = null): ?int
{
    $src ??= $_POST;
    $v = preg_replace("/[\s'’\x{202F}\x{00A0}]/u", '', (string) ($src[$key] ?? '')) ?? '';
    if ($v === '' || !preg_match('/^-?\d{1,9}$/', $v)) {
        return null;
    }
    $i = (int) $v;
    if ($min !== null) $i = max($min, $i);
    if ($max !== null) $i = min($max, $i);
    return $i;
}

/** Valeur choisie dans une liste fermée, sinon $default. */
function in_choice(string $key, array $allowed, ?string $default = null, ?array $src = null): ?string
{
    $src ??= $_POST;
    $v = (string) ($src[$key] ?? '');
    return in_array($v, $allowed, true) ? $v : $default;
}

function in_bool(string $key, ?array $src = null): int
{
    $src ??= $_POST;
    return !empty($src[$key]) ? 1 : 0;
}

/** CP saisi comme dans l'Excel : « 174k », « 190,5k » ou « 190500 ». */
function parse_cp(string $raw): ?int
{
    $t = strtolower(str_replace([' ', "\u{202F}", "\u{00A0}", "'", ','], ['', '', '', '', '.'], trim($raw)));
    if ($t === '') return null;
    $mult = str_ends_with($t, 'k') ? 1000 : 1;
    $t = rtrim($t, 'k');
    if (!is_numeric($t)) return null;
    return (int) round((float) $t * $mult);
}

function fmt_cp(mixed $cp): string
{
    if ($cp === null || $cp === '') return '–';
    $cp = (int) $cp;
    if ($cp < 1000) return (string) $cp;
    $k = number_format($cp / 1000, 1, ',', '');
    return (str_ends_with($k, ',0') ? substr($k, 0, -2) : $k) . 'k';
}

function fmt_gs(mixed $gs): string
{
    return ($gs === null || $gs === '') ? '–' : number_format((int) $gs, 0, '', "\u{202F}");
}

function local_dt(int $ts): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(cfg('timezone')));
}

/** « mer. 19 nov. à 21:00 » */
function fmt_dt(mixed $ts): string
{
    if (!$ts) return '';
    $d = local_dt((int) $ts);
    $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $days[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' à ' . $d->format('H:i');
}

function fmt_time(mixed $ts): string
{
    return $ts ? local_dt((int) $ts)->format('H:i') : '';
}

/** « 3 joueurs », « 1 joueur ». */
function plural(int $n, string $word): string
{
    return $n . ' ' . $word . ($n > 1 ? 's' : '');
}

/** « A », « A et B », « A, B et C ». */
function names_list(array $names): string
{
    $names = array_values($names);
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' et ' . $last : (string) $last;
}

/** Heure seule si c'est aujourd'hui, sinon jour et heure (salles ouvertes plusieurs jours). $short : « 18/11 15:38 ». */
function fmt_when(mixed $ts, bool $short = false): string
{
    if (!$ts) return '';
    if (local_dt((int) $ts)->format('Y-m-d') === local_dt(time())->format('Y-m-d')) return fmt_time($ts);
    return $short ? local_dt((int) $ts)->format('d/m H:i') : fmt_dt($ts);
}

/** Durée restante lisible côté serveur (le JS prend le relais à la seconde). */
function fmt_left(int $secs): string
{
    $secs = max(0, $secs);
    if ($secs >= 86400) {
        return intdiv($secs, 86400) . ' j ' . intdiv($secs % 86400, 3600) . ' h';
    }
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

/** Emblème de classe (fichier choisi dans Officiers > Listes). */
function class_icon(?string $classe, int $size = 40, string $cls = ''): string
{
    $classe = $classe ?: 'Autre';
    return '<img class="emblem ' . e($cls) . '" src="assets/classes/' . e(class_icon_file($classe)) . '" width="' . $size . '" height="' . $size
        . '" alt="' . e($classe) . '" title="' . e($classe) . '">';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function insc_class(?string $inscription): string
{
    return match ($inscription) {
        'Confirmé'     => 'insc-ok',
        'Indisponible' => 'insc-off',
        default        => 'insc-wait',
    };
}

/** « il y a 5 min », « il y a 3 h », « il y a 2 j » */
function fmt_ago(mixed $ts): string
{
    if (!$ts) return 'jamais';
    $d = max(0, time() - (int) $ts);
    return match (true) {
        $d < 60    => "à l'instant",
        $d < 3600  => 'il y a ' . intdiv($d, 60) . ' min',
        $d < 86400 => 'il y a ' . intdiv($d, 3600) . ' h',
        default    => 'il y a ' . intdiv($d, 86400) . ' j',
    };
}
