<?php
declare(strict_types=1);

/*
 * Lecture du GS (niveau d'objet) et du CP depuis le site officiel d'AION 2.
 *
 * NCSOFT ne publie pas d'API documentée : on interroge les mêmes adresses JSON que la page
 * « recherche de personnage » du site officiel (comme shugo.gg, MetaBot ou Questlog).
 * Elles peuvent changer sans prévenir : en cas d'échec, l'app garde les dernières valeurs
 * et la saisie manuelle reste possible.
 */

final class AionError extends UserError {}

const AION_SEARCH_URL = 'https://api-search.plaync.com/aion2global/search/v2/character';
const AION_INFO_URL   = 'https://aion2.plaync.com/api/character/info';
const AION_PAUSE_US   = 400000;   // pause entre deux requêtes (0,4 s) : on reste discret
const AION_RETRY_ERR  = 3600;     // après une erreur réseau, nouvel essai au bout d'une heure

function aion_enabled(): bool
{
    return (bool) cfg('aion_sync');
}

/** GET JSON sur le site officiel (cURL si disponible, sinon flux PHP). */
function aion_get(string $url, array $query): array
{
    $full = $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headers = ['Accept: application/json', 'User-Agent: ' . cfg('aion_user_agent')];

    if (function_exists('curl_init')) {
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            // PHP sous Windows n'a pas de liste de certificats : on utilise celle de Windows
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($body === false) {
            throw new AionError("Impossible de joindre le site officiel d'AION 2 ($err).");
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents($full, false, $ctx);
        if ($body === false) {
            throw new AionError("Impossible de joindre le site officiel d'AION 2.");
        }
        $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $mm) ? (int) $mm[1] : 0;
    }

    if ($code === 429) {
        throw new AionError("Le site officiel limite les requêtes pour l'instant. Nouvel essai plus tard.", 429);
    }
    if ($code !== 200) {
        throw new AionError("Le site officiel d'AION 2 a répondu avec le code $code.", $code);
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new AionError("Réponse inattendue du site officiel d'AION 2.");
    }
    return $data;
}

/** Nom sans accents ni majuscules, pour rattraper les fautes de saisie (Élyä / Elya). */
function aion_fold(string $s): string
{
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
    } else {
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C']);
    }
    return mb_strtolower($s);
}

function aion_clean_name(string $s): string
{
    // La recherche officielle surligne la partie trouvée : « <strong>Ely</strong>a »
    return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Personnages du serveur configuré dont le nom contient $name (3 pages au plus). */
function aion_search(string $name): array
{
    $rows = [];
    for ($page = 1; $page <= 3; $page++) {
        $d = aion_get(AION_SEARCH_URL, [
            'keyword' => $name, 'page' => $page, 'size' => 40, 'serverId' => (int) cfg('aion_server_id'),
            'region' => cfg('aion_region'), 'localeInfo' => cfg('aion_lang'),
        ]);
        if (!isset($d['list']) || !is_array($d['list'])) {
            throw new AionError("Format de recherche inattendu : le site officiel a peut-être changé.");
        }
        foreach ($d['list'] as $r) {
            if ((int) ($r['serverId'] ?? 0) !== (int) cfg('aion_server_id') || empty($r['characterId'])) continue;
            $rows[] = ['id' => rawurldecode((string) $r['characterId']), 'name' => aion_clean_name((string) ($r['name'] ?? '')), 'level' => (int) ($r['level'] ?? 0)];
        }
        $found = array_filter($rows, fn($r) => mb_strtolower($r['name']) === mb_strtolower($name));
        if ($found || $page >= (int) ($d['pagination']['endPage'] ?? 1)) break;
        usleep(AION_PAUSE_US);
    }
    return $rows;
}

/** Fiche officielle d'un personnage, ou null s'il n'existe pas (ou plus). */
function aion_info(string $characterId): ?array
{
    $d = aion_get(AION_INFO_URL, [
        'lang' => cfg('aion_lang'), 'region' => cfg('aion_region'),
        'serverId' => (int) cfg('aion_server_id'), 'characterId' => $characterId,
    ]);
    $p = $d['profile'] ?? null;
    if (!is_array($p)) {
        throw new AionError("Format de fiche inattendu : le site officiel a peut-être changé.");
    }
    if (($p['characterId'] ?? '') === '') {
        return null;
    }
    $itemLevel = null;
    foreach ($d['stat']['statList'] ?? [] as $s) {
        if (($s['type'] ?? '') === 'ItemLevel') $itemLevel = (int) $s['value'];
    }
    return [
        'name'   => (string) ($p['characterName'] ?? ''),
        'cp'     => isset($p['combatPower']) ? (int) $p['combatPower'] : null,
        'gs'     => $itemLevel,
        'level'  => (int) ($p['characterLevel'] ?? 0),
        'class'  => (string) ($p['className'] ?? ''),
        'legion' => (string) ($p['regionName'] ?? ''), // NCSOFT range le nom de légion dans « regionName »
    ];
}

/** Nom du personnage en jeu : celui saisi par le membre, sinon son pseudo. */
function aion_char_name(array $m): string
{
    return trim((string) ($m['perso'] ?? '')) ?: (string) $m['pseudo'];
}

/**
 * Liens vers les sites qui détaillent l'équipement du personnage (kisk.app, shugo.gg…), libellé => URL.
 * Seulement pour un personnage trouvé en jeu : un nom inconnu mènerait à une page vide.
 */
function aion_armory_links(array $m): array
{
    if (aion_enabled() && ($m['sync_status'] ?? '') !== 'ok') return [];
    // Identifiant officiel (base64url, p. ex. « uKNAI_…Lb4= ») : gardé tel quel, Questlog l'attend avec son « = ».
    $charId = (string) ($m['aion_char_id'] ?? '');
    $vars = [
        '{region}' => rawurlencode(strtolower((string) cfg('aion_region'))),
        '{server}' => rawurlencode(mb_strtolower((string) cfg('aion_server_name'))),
        '{name}'   => rawurlencode(aion_char_name($m)),
        '{id}'     => preg_match('/^[A-Za-z0-9_\-]+=*$/', $charId) ? $charId : '',
    ];
    $links = [];
    foreach ((array) cfg('aion_armory_links') as $label => $tpl) {
        if (str_contains((string) $tpl, '{id}') && $vars['{id}'] === '') continue; // pas encore lié au site officiel
        $links[(string) $label] = strtr((string) $tpl, $vars);
    }
    return $links;
}

/** Les liens ci-dessus en HTML, en boutons sur une ligne (nouvel onglet). */
function armory_links_html(array $m, string $class = 'armory'): string
{
    $links = aion_armory_links($m);
    if (!$links) return '';
    $h = [];
    foreach ($links as $label => $url) {
        $h[] = '<a class="armory-btn" href="' . e($url) . '" target="_blank" rel="noopener noreferrer" title="Équipement de '
            . e(aion_char_name($m)) . ' sur ' . e($label) . ' (nouvel onglet)">' . e($label) . '</a>';
    }
    return '<span class="' . e($class) . '">' . implode('', $h) . '</span>';
}

/**
 * Lit la fiche officielle d'un membre et met à jour GS, CP, classe et niveau.
 * Retourne ['status' => ok|not_found|error, 'message' => ..., 'candidates' => [...]].
 */
function aion_sync_member(array $m): array
{
    $name = aion_char_name($m);
    $now = time();
    try {
        $info = null;
        $charId = (string) ($m['aion_char_id'] ?? '');
        if ($charId !== '') {
            $info = aion_info($charId);
            if ($info && aion_fold($info['name']) !== aion_fold($name)) {
                $info = null; // le membre a changé de personnage : on recherche le nouveau nom
            }
        }
        if (!$info) {
            $rows = aion_search($name);
            $exact = array_values(array_filter($rows, fn($r) => mb_strtolower($r['name']) === mb_strtolower($name)));
            if (!$exact && !$rows && mb_strlen($name) > 4) {
                // Rien ne contient le nom (souvent un accent différent) : on élargit aux 4 premières lettres
                usleep(AION_PAUSE_US);
                $rows = aion_search(mb_substr($name, 0, 4));
            }
            if (!$exact) {
                // Un seul personnage au même nom aux accents près : c'est lui
                $folded = array_values(array_filter($rows, fn($r) => aion_fold($r['name']) === aion_fold($name)));
                if (count($folded) === 1) $exact = $folded;
            }
            if (!$exact) {
                q("UPDATE members SET aion_char_id = NULL, sync_status = 'not_found', sync_message = ?, sync_tried_at = ? WHERE id = ?",
                    ['Aucun personnage « ' . $name . ' » sur ' . cfg('aion_server_name') . '.', $now, $m['id']]);
                return ['status' => 'not_found', 'message' => 'Aucun personnage « ' . $name . ' » trouvé sur ' . cfg('aion_server_name') . '.',
                    'candidates' => array_slice($rows, 0, 12)];
            }
            usleep(AION_PAUSE_US);
            $charId = $exact[0]['id'];
            $info = aion_info($charId);
            if (!$info) {
                throw new AionError('Le personnage a été trouvé mais sa fiche est indisponible.');
            }
        }

        // La classe vient du jeu si elle existe dans la liste du site (noms identiques en fr-FR)
        $classe = in_array($info['class'], opts('classe', null, true), true) ? $info['class'] : $m['classe'];
        // On garde l'orthographe exacte du jeu si elle diffère du pseudo (accents, majuscules…)
        $perso = mb_strtolower($info['name']) === mb_strtolower((string) $m['pseudo']) ? null : $info['name'];
        q("UPDATE members SET gs = ?, cp = ?, classe = ?, perso = ?, aion_char_id = ?, aion_level = ?, aion_legion = ?,
                              synced_at = ?, sync_status = 'ok', sync_message = NULL, sync_tried_at = ? WHERE id = ?",
            [$info['gs'] ?? $m['gs'], $info['cp'] ?? $m['cp'], $classe, $perso, $charId, $info['level'], $info['legion'] ?: null, $now, $now, $m['id']]);
        return ['status' => 'ok', 'message' => $info['name'] . ' : GS ' . fmt_gs($info['gs']) . ', CP ' . fmt_cp($info['cp']) . '.', 'info' => $info];
    } catch (AionError $e) {
        q("UPDATE members SET sync_status = 'error', sync_message = ?, sync_tried_at = ? WHERE id = ?", [$e->getMessage(), $now, $m['id']]);
        return ['status' => 'error', 'message' => $e->getMessage(), 'code' => $e->getCode()];
    }
}

/** Membres dont la fiche est à relire (jamais lue, trop ancienne, ou erreur à réessayer). */
function aion_due_members(int $limit, bool $all = false): array
{
    if ($all) {
        return q('SELECT * FROM members ORDER BY sync_tried_at IS NOT NULL, sync_tried_at, id LIMIT ?', [$limit])->fetchAll();
    }
    $fresh = time() - (int) cfg('aion_refresh_hours') * 3600;
    return q("SELECT * FROM members
              WHERE sync_tried_at IS NULL
                 OR (sync_status = 'error' AND sync_tried_at < ?)
                 OR (sync_status <> 'error' AND sync_tried_at < ?)
              ORDER BY sync_tried_at IS NOT NULL, sync_tried_at, id LIMIT ?", [time() - AION_RETRY_ERR, $fresh, $limit])->fetchAll();
}

/** Relit plusieurs fiches dans un temps donné. S'arrête si le site officiel limite les requêtes. */
function aion_sync_batch(int $max, float $budgetSeconds, bool $all = false, ?callable $onEach = null): array
{
    $sum = ['ok' => 0, 'not_found' => 0, 'error' => 0, 'remaining' => 0];
    if (!aion_enabled()) return $sum;
    $start = microtime(true);
    $members = aion_due_members($max, $all);
    foreach ($members as $i => $m) {
        if (microtime(true) - $start > $budgetSeconds) {
            $sum['remaining'] = count($members) - $i;
            break;
        }
        $r = aion_sync_member($m);
        $sum[$r['status']]++;
        if ($onEach) $onEach($m, $r);
        if (($r['code'] ?? 0) === 429) {
            $sum['remaining'] = count($members) - $i - 1;
            break;
        }
        usleep(AION_PAUSE_US);
    }
    return $sum;
}

/** Au plus un petit lot de relectures toutes les 10 minutes (voir background_tasks()). */
function aion_claim_tick(): bool
{
    if (!aion_enabled()) return false;
    $now = time();
    return q("UPDATE kv SET value = ? WHERE key = 'aion_tick' AND CAST(value AS INTEGER) < ?", [(string) $now, $now - 600])->rowCount() === 1;
}

function aion_stats(): array
{
    $s = ['ok' => 0, 'not_found' => 0, 'error' => 0, 'never' => 0];
    foreach (q("SELECT COALESCE(sync_status, 'never') AS st, COUNT(*) AS n FROM members GROUP BY st")->fetchAll() as $r) {
        $s[$r['st']] = (int) $r['n'];
    }
    $s['last'] = (int) q('SELECT MAX(synced_at) FROM members')->fetchColumn();
    return $s;
}

function aion_legion_mismatch(array $m): bool
{
    return cfg('aion_legion') && ($m['sync_status'] ?? '') === 'ok'
        && mb_strtolower((string) $m['aion_legion']) !== mb_strtolower((string) cfg('aion_legion'));
}
