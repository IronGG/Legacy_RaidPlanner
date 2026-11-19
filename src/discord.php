<?php
declare(strict_types=1);

/*
 * Bot Discord des wars. Il fonctionne par « interactions HTTP » : pas de programme allumé en permanence,
 * c'est Discord qui appelle public/discord.php à chaque clic (requête signée Ed25519).
 *
 *  - Le bot poste le message d'une war (compteurs par rôle, boutons de réponse) dans le salon des wars :
 *    bouton « Publier sur Discord » de la page War, ou tout seul « auto_post_hours » avant (public/cron.php).
 *  - Un clic : le site retrouve le membre (identifiant Discord déjà lié, sinon pseudo du serveur, sinon il crée
 *    le profil), enregistre la réponse dans la même base que le site, puis met le message à jour.
 *  - Une réponse donnée sur le site met aussi le message à jour (au plus une modification toutes les 2 s).
 *  - Annonce « compo publiée » et rappel avant la war dans le salon.
 * Réglages non secrets : 'discord' dans config.php. Jeton du bot et clé du cron : data/discord.php (bin/discord.php setup).
 */

final class DiscordError extends UserError {}

const DISCORD_API      = 'https://discord.com/api/v10';
const DISCORD_SYNC_GAP = 2;      // secondes minimum entre deux modifications d'un même message
const DISCORD_EPHEMERAL = 64;    // message visible seulement par la personne qui a cliqué
const DISCORD_STATUS_STYLE = ['present' => 3, 'late' => 1, 'tentative' => 2, 'bench' => 2, 'absent' => 4]; // vert, bleu, gris, gris, rouge
const DISCORD_STATUS_MARK  = ['late' => ' ⏳', 'tentative' => ' ❔'];

/** Réglage du bot (config.php > 'discord'). */
function dcfg(string $key): mixed
{
    return ((array) (cfg('discord') ?? []))[$key] ?? null;
}

/** Secrets du serveur : jeton du bot et clé du cron (data/discord.php, hors du site et de git). */
function discord_secrets(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $f = APP_ROOT . '/data/discord.php';
        $s = is_file($f) ? (array) (require $f) : [];
    }
    return $s;
}

/** Le bot peut poster : activé, identifiants renseignés et jeton présent. */
function discord_enabled(): bool
{
    return (bool) dcfg('enabled') && (string) dcfg('application_id') !== '' && (string) dcfg('guild_id') !== ''
        && (string) dcfg('war_channel_id') !== '' && (string) (discord_secrets()['bot_token'] ?? '') !== '';
}

/** Vérifie qu'une requête vient bien de Discord (signature Ed25519 de l'horodatage + corps). */
function discord_verify(string $sigHex, string $timestamp, string $body): bool
{
    $key = (string) dcfg('public_key');
    if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
    if (strlen($sigHex) !== 128 || !ctype_xdigit($sigHex) || strlen($key) !== 64 || !ctype_xdigit($key) || !ctype_digit($timestamp)) return false;
    if (abs(time() - (int) $timestamp) > 300) return false; // vieille requête rejouée
    try {
        return sodium_crypto_sign_verify_detached(hex2bin($sigHex), $timestamp . $body, hex2bin($key));
    } catch (SodiumException) {
        return false;
    }
}

/** Appel à l'API de Discord (JSON). Lance DiscordError avec un message lisible si Discord refuse. */
function discord_api(string $method, string $path, ?array $json = null, bool $auth = true): array
{
    if (!function_exists('curl_init')) throw new DiscordError("L'extension cURL de PHP manque sur ce serveur.");
    $base = rtrim((string) (dcfg('api_base') ?: DISCORD_API), '/');
    $headers = ['Accept: application/json', 'User-Agent: DiscordBot (' . (dcfg('site_url') ?: 'https://localhost') . ', 1.0)'];
    if ($auth) $headers[] = 'Authorization: Bot ' . (discord_secrets()['bot_token'] ?? '');
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    unset($ch);
    if ($body === false) throw new DiscordError("Discord injoignable ($err).");
    $data = json_decode((string) $body, true);
    if ($code === 429) throw new DiscordError('Discord limite les envois pour le moment ; nouvel essai sous peu.', 429);
    if ($code === 401) throw new DiscordError('Jeton du bot refusé par Discord : refais « php bin/discord.php setup ».', 401);
    if ($code === 403) throw new DiscordError('Le bot n\'a pas les droits nécessaires dans ce salon (voir, envoyer des messages, intégrer des liens).', 403);
    if ($code === 404) throw new DiscordError('Discord ne trouve pas le salon ou le message (supprimé ?).', 404);
    if ($code >= 400) throw new DiscordError('Discord a refusé la demande (' . $code . (isset($data['message']) ? ' : ' . $data['message'] : '') . ').', $code);
    return is_array($data) ? $data : [];
}

/** Adresse complète d'une page du site (liens dans les messages Discord). */
function discord_site_url(string $page): string
{
    $base = rtrim((string) dcfg('site_url'), '/');
    return $base === '' ? '' : $base . '/' . ltrim($page, '/');
}

/** Lien vers le message Discord d'une war. */
function discord_message_link(array $war): string
{
    return $war['discord_message_id']
        ? 'https://discord.com/channels/' . dcfg('guild_id') . '/' . $war['discord_channel_id'] . '/' . $war['discord_message_id'] : '';
}

/** Texte sans mise en forme Markdown involontaire (un « _ » dans un pseudo ne doit pas passer en italique). */
function discord_escape(string $s): string
{
    return preg_replace('/([\\\\*_~`|>#\[\]()-])/u', '\\\\$1', $s) ?? $s;
}

/** Liste de joueurs pour un champ du message (1024 caractères au plus par champ). */
function discord_names(array $players, bool $inline = false): string
{
    $emojis = (array) dcfg('class_emojis');
    $parts = [];
    $len = 0;
    foreach (array_values($players) as $i => $s) {
        $line = (isset($emojis[$s['classe']]) ? $emojis[$s['classe']] . ' ' : '') . discord_escape($s['pseudo'])
            . ($s['cp'] !== null && $s['cp'] !== '' && !$inline ? ' · ' . fmt_cp($s['cp']) : '') . (DISCORD_STATUS_MARK[$s['status']] ?? '');
        if ($len + mb_strlen($line) + 1 > 980) {
            $parts[] = '… et ' . (count($players) - $i) . ' autre' . (count($players) - $i > 1 ? 's' : '');
            break;
        }
        $parts[] = $line;
        $len += mb_strlen($line) + 1;
    }
    return $parts ? implode($inline ? ', ' : "\n", $parts) : '—';
}

/** Message Discord d'une war : compteurs par rôle (comme Raid-Helper) et boutons de réponse. */
function discord_war_message(array $war): array
{
    $id = (int) $war['id'];
    $signups = war_signups($id);
    usort($signups, 'war_cmp_cp');
    $c = war_counts($signups);
    $phase = war_phase($war);
    $open = in_array($phase, ['upcoming', 'live'], true);
    $ts = (int) $war['starts_at'];

    $byRole = [];
    $bench = [];
    $absent = [];
    foreach ($signups as $s) {
        if ($s['status'] === 'bench') $bench[] = $s;
        elseif ($s['status'] === 'absent') $absent[] = $s;
        else $byRole[$s['role']][] = $s;
    }
    $roleEmojis = (array) dcfg('role_emojis');
    $fields = [];
    foreach (war_roles_order() as $role) {
        $list = $byRole[$role] ?? [];
        $fields[] = ['name' => trim(($roleEmojis[$role] ?? '') . ' ' . $role) . ' (' . count($list) . ')', 'value' => discord_names($list), 'inline' => true];
    }
    if ($bench) $fields[] = ['name' => '🪑 Banc (' . count($bench) . ')', 'value' => discord_names($bench, true), 'inline' => false];
    if ($absent) $fields[] = ['name' => '🚫 Absents (' . count($absent) . ')', 'value' => discord_names($absent, true), 'inline' => false];

    $lines = [];
    if ($war['message']) $lines[] = '**' . discord_escape((string) $war['message']) . '**';
    $lines[] = '📅 <t:' . $ts . ':F> · <t:' . $ts . ':R>';
    $lines[] = match ($phase) {
        'cancelled' => '❌ **War annulée.**',
        'past'      => '🏁 War terminée.',
        'live'      => '⚔️ **En cours** : les retardataires peuvent encore répondre.',
        default     => 'Réponds avec les boutons ci-dessous. Ton rôle vient de ta classe.',
    };
    $lines[] = '👥 **' . plural($c['in'], 'inscrit') . '**' . ($c['bench'] ? ' (+' . $c['bench'] . ' au banc)' : '')
        . ($c['late'] ? ' · ' . $c['late'] . ' en retard' : '') . ($c['tentative'] ? ' · ' . plural($c['tentative'], 'provisoire') : '');
    if ($war['published_at'] && $phase !== 'cancelled') $lines[] = '📋 **Compo publiée** : clique sur « Ma place ».';

    $title = ($phase === 'cancelled' ? 'ANNULÉE · ' : '') . ucfirst(war_title($war));
    $embed = ['title' => mb_substr($title, 0, 250), 'description' => implode("\n", $lines), 'color' => $phase === 'cancelled' ? 0xE46A5C : 0xE3B866,
              'fields' => $fields, 'footer' => ['text' => 'Légion ' . cfg('aion_legion') . ' · réponses enregistrées sur le site']];
    $url = discord_site_url('war.php?w=' . $id);
    if ($url !== '') $embed['url'] = $url;

    $buttons = [];
    foreach (WAR_STATUSES as $key => $label) {
        $buttons[] = ['type' => 2, 'style' => DISCORD_STATUS_STYLE[$key], 'label' => $label, 'custom_id' => "war:$id:$key", 'disabled' => !$open];
    }
    $row2 = [['type' => 2, 'style' => 2, 'label' => 'Ma place', 'emoji' => ['name' => '📋'], 'custom_id' => "war:$id:place"]];
    if ($url !== '') $row2[] = ['type' => 2, 'style' => 5, 'label' => 'Ouvrir sur le site', 'url' => $url];

    return [
        'embeds'           => [$embed],
        'components'       => [['type' => 1, 'components' => $buttons], ['type' => 1, 'components' => $row2]],
        'allowed_mentions' => ['parse' => []],
    ];
}

/* ---------------------------------------------------------------------------------------------------------
 * Liaison des comptes : l'identifiant Discord est la clé ; le pseudo ne sert qu'au tout premier clic.
 * ------------------------------------------------------------------------------------------------------- */

/** « [Legacy]Junee » → « Junee » ; « 𝕰𝖑𝖊𝖓𝖆 » → « Elena » ; garde lettres, chiffres, - et _. */
function discord_clean_name(string $s): string
{
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s;
    do {
        $before = $s;
        $s = preg_replace('/^\s*(?:\[[^\]]*\]|\([^)]*\)|\{[^}]*\})\s*/u', '', $s) ?? $s; // tags de guilde en tête
    } while ($s !== $before);
    return preg_replace('/[^\p{L}\p{N}_\-]+/u', '', $s) ?? '';
}

/** Pseudos possibles d'un membre Discord : pseudo du serveur, nom d'affichage, nom d'utilisateur, et leurs morceaux. */
function discord_name_candidates(array $dm): array
{
    $user = (array) ($dm['user'] ?? []);
    $out = [];
    foreach ([$dm['nick'] ?? null, $user['global_name'] ?? null, $user['username'] ?? null] as $src) {
        if (!is_string($src) || trim($src) === '') continue;
        $whole = discord_clean_name($src);
        $stripped = preg_replace('/^\s*(?:\[[^\]]*\]|\([^)]*\)|\{[^}]*\})\s*/u', '', $src) ?? $src;
        $parts = array_map('discord_clean_name', preg_split('/[|\/\\\\,;•·]+|\s+/u', $stripped) ?: []);
        foreach ([$whole, ...$parts] as $c) {
            if ($c !== '' && validate_pseudo($c) === null && !isset($out[mb_strtolower($c)])) $out[mb_strtolower($c)] = $c;
        }
    }
    return array_values($out);
}

/**
 * Membre du site pour la personne qui a cliqué. Lie ou crée le profil au premier clic si $create.
 * Renvoie [membre, note à lui montrer ou null]. Lance DiscordError (message à montrer) si c'est impossible.
 */
function discord_member_for(array $dm, bool $create = true): array
{
    $user = (array) ($dm['user'] ?? []);
    $did = (string) ($user['id'] ?? '');
    if ($did === '' || !ctype_digit($did)) throw new DiscordError('Compte Discord inconnu.');
    $required = array_map('strval', (array) dcfg('member_role_ids'));
    if ($required && !array_intersect($required, array_map('strval', (array) ($dm['roles'] ?? [])))) {
        throw new DiscordError('Réservé aux membres de la légion ' . cfg('aion_legion') . ' (il te manque le rôle Discord de la légion).');
    }
    $display = (string) ($dm['nick'] ?? $user['global_name'] ?? $user['username'] ?? '');
    return tx(function () use ($did, $display, $dm, $create) {
        $m = q('SELECT * FROM members WHERE discord_id = ?', [$did])->fetch();
        if ($m) {
            if ((string) $m['discord_name'] !== $display) q('UPDATE members SET discord_name = ? WHERE id = ?', [$display, $m['id']]);
            return [$m, null];
        }
        $cands = discord_name_candidates($dm);
        $found = [];
        foreach ($cands as $c) {
            foreach (q('SELECT * FROM members WHERE pseudo = ?', [$c])->fetchAll() as $row) $found[(int) $row['id']] = $row;
        }
        $free = array_filter($found, fn($r) => $r['discord_id'] === null);
        if (count($free) === 1) {
            $m = reset($free);
            q('UPDATE members SET discord_id = ?, discord_name = ?, updated_at = ? WHERE id = ?', [$did, $display, time(), $m['id']]);
            return [member_by_id((int) $m['id']), 'Ton compte Discord est maintenant lié au profil « ' . $m['pseudo'] . ' » du site.'];
        }
        if (count($free) > 1) {
            throw new DiscordError('Plusieurs profils du site correspondent à ton pseudo (' . names_list(array_column($free, 'pseudo')) . '). Demande à un officier de lier ton compte.');
        }
        if ($found) {
            throw new DiscordError('Le profil « ' . reset($found)['pseudo'] . ' » est déjà lié à un autre compte Discord. Demande à un officier.');
        }
        if (!$create) {
            throw new DiscordError('Ton compte Discord n\'est pas encore lié à un profil du site : réponds d\'abord à une war avec les boutons.');
        }
        $pseudo = $cands[0] ?? null;
        if ($pseudo === null) {
            throw new DiscordError('Ton pseudo Discord ne peut pas servir de pseudo sur le site (2 à 20 lettres, chiffres, - ou _). Demande à un officier.');
        }
        q('INSERT INTO members (pseudo, discord_id, discord_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$pseudo, $did, $display, time(), time()]);
        return [member_by_pseudo($pseudo), 'Profil « ' . $pseudo . ' » créé sur le site et lié à ton compte Discord. Ta classe et ton CP seront lus en jeu ; un officier vérifiera tes infos.'];
    });
}

/** Lien fait par un officier depuis le profil (identifiant Discord, vide pour délier). */
function discord_set_link(array $member, string $discordId, array $me): string
{
    $discordId = trim($discordId);
    if ($discordId !== '' && (!ctype_digit($discordId) || strlen($discordId) < 15 || strlen($discordId) > 21)) {
        throw new DiscordError('Identifiant Discord invalide : 17 à 20 chiffres (mode développeur de Discord, clic droit sur la personne > Copier l\'identifiant).');
    }
    return tx(function () use ($member, $discordId) {
        if ($discordId === '') {
            q('UPDATE members SET discord_id = NULL, discord_name = NULL, updated_at = ? WHERE id = ?', [time(), $member['id']]);
            return $member['pseudo'] . ' n\'est plus lié à un compte Discord.';
        }
        $other = q('SELECT pseudo FROM members WHERE discord_id = ? AND id <> ?', [$discordId, $member['id']])->fetchColumn();
        if ($other) throw new DiscordError("Ce compte Discord est déjà lié à « $other ». Délie-le d'abord.");
        q('UPDATE members SET discord_id = ?, discord_name = NULL, updated_at = ? WHERE id = ?', [$discordId, time(), $member['id']]);
        return $member['pseudo'] . ' est lié au compte Discord ' . $discordId . '. Son nom Discord s\'affichera après son prochain clic.';
    });
}

/* ---------------------------------------------------------------------------------------------------------
 * Message de la war dans le salon : publication, mise à jour, annonces.
 * ------------------------------------------------------------------------------------------------------- */

/** Poste (ou reposte) le message d'une war dans le salon des wars. */
function discord_post_war(int $warId, ?array $me = null): string
{
    if (!discord_enabled()) throw new DiscordError('Le bot Discord n\'est pas configuré (voir le README, section « Bot Discord »).');
    $war = war_by_id($warId);
    if (!$war) throw new DiscordError("Cette war n'existe pas.");
    $channel = (string) dcfg('war_channel_id');
    $msg = discord_api('POST', '/channels/' . $channel . '/messages', discord_war_message($war));
    if (empty($msg['id'])) throw new DiscordError('Discord n\'a pas renvoyé de message.');
    q('UPDATE wars SET discord_channel_id = ?, discord_message_id = ?, discord_dirty = 0, discord_synced_at = ?, discord_error = NULL WHERE id = ?',
        [$channel, (string) $msg['id'], time(), $warId]);
    return 'War publiée sur Discord.';
}

/**
 * Met le message Discord à jour s'il y a eu des changements depuis la dernière fois, au plus une fois toutes les
 * DISCORD_SYNC_GAP secondes. En cas d'échec, le message reste à mettre à jour et l'erreur s'affiche chez les officiers.
 */
function discord_sync_war(int $warId): bool
{
    if (!discord_enabled()) return false;
    $now = time();
    $claimed = q('UPDATE wars SET discord_dirty = 0, discord_synced_at = ? WHERE id = ? AND discord_message_id IS NOT NULL
                  AND discord_dirty = 1 AND COALESCE(discord_synced_at, 0) <= ?', [$now, $warId, $now - DISCORD_SYNC_GAP])->rowCount();
    if (!$claimed) return false;
    $war = war_by_id($warId);
    try {
        discord_api('PATCH', '/channels/' . $war['discord_channel_id'] . '/messages/' . $war['discord_message_id'], discord_war_message($war));
        q('UPDATE wars SET discord_error = NULL WHERE id = ?', [$warId]);
        return true;
    } catch (DiscordError $e) {
        // 404 : message supprimé sur Discord, inutile d'insister (un officier peut le republier)
        q('UPDATE wars SET discord_dirty = ?, discord_error = ? WHERE id = ?', [$e->getCode() === 404 ? 0 : 1, $e->getMessage(), $warId]);
        return false;
    }
}

/** Après l'envoi de la page : met à jour le message de la war (le visiteur n'attend pas). */
function discord_sync_soon(int $warId): void
{
    if (!discord_enabled()) return;
    discord_after_response(fn() => discord_sync_war($warId));
}

/** Annonce dans le salon des wars (rappel, compo publiée), avec la mention du rôle choisi. */
function discord_announce(string $text): void
{
    $ping = (string) dcfg('ping_role_id');
    discord_api('POST', '/channels/' . dcfg('war_channel_id') . '/messages', [
        'content'          => ($ping !== '' ? '<@&' . $ping . '> ' : '') . $text,
        'allowed_mentions' => $ping !== '' ? ['roles' => [$ping]] : ['parse' => []],
    ]);
}

/** Annonce « compo publiée » (bouton Publier la compo du site). */
function discord_announce_compo_soon(int $warId): void
{
    if (!discord_enabled() || !dcfg('announce_compo')) return;
    discord_after_response(function () use ($warId) {
        $war = war_by_id($warId);
        if (!$war || !$war['published_at'] || (int) $war['cancelled']) return;
        $link = discord_message_link($war) ?: discord_site_url('war.php?w=' . $warId);
        try {
            discord_announce('📋 **Compo publiée** pour la war du <t:' . (int) $war['starts_at'] . ':F>. Clique sur « Ma place » sous le message de la war pour voir ta troupe : ' . $link);
        } catch (DiscordError $e) {
            q('UPDATE wars SET discord_error = ? WHERE id = ?', [$e->getMessage(), $warId]);
        }
    });
}

/** Exécute une tâche après l'envoi de la réponse au navigateur ou à Discord (PHP-FPM chez Infomaniak). */
function discord_after_response(callable $task): void
{
    register_shutdown_function(function () use ($task) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            $task();
        } catch (Throwable $e) {
            error_log('guildraid discord: ' . $e->getMessage());
        }
    });
}

/**
 * Tâches à heure fixe (public/cron.php, planificateur Infomaniak toutes les 15 min) :
 * poster les wars qui approchent, rappeler les wars qui commencent bientôt, mettre à jour les messages en retard
 * (et désactiver les boutons des wars terminées). Renvoie un résumé lisible.
 */
function discord_cron(): array
{
    $log = [];
    if (!discord_enabled()) return ['Bot Discord non configuré : rien à faire.'];
    $now = time();
    $len = war_length();

    $postHours = (int) dcfg('auto_post_hours');
    if ($postHours > 0) {
        foreach (q('SELECT id FROM wars WHERE discord_message_id IS NULL AND cancelled = 0 AND starts_at > ? AND starts_at <= ?',
            [$now, $now + $postHours * 3600])->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                discord_post_war((int) $id);
                $log[] = "War $id postée.";
            } catch (DiscordError $e) {
                q('UPDATE wars SET discord_error = ? WHERE id = ?', [$e->getMessage(), $id]);
                $log[] = "War $id : " . $e->getMessage();
            }
        }
    }

    $remind = (int) dcfg('remind_minutes');
    if ($remind > 0) {
        foreach (q('SELECT * FROM wars WHERE discord_message_id IS NOT NULL AND discord_reminded_at IS NULL AND cancelled = 0
                    AND starts_at > ? AND starts_at <= ?', [$now, $now + $remind * 60])->fetchAll() as $war) {
            $c = war_counts(war_signups((int) $war['id']));
            try {
                discord_announce('⏰ **War <t:' . (int) $war['starts_at'] . ':R>** : ' . plural($c['in'], 'inscrit') . ($c['bench'] ? ' (+' . $c['bench'] . ' au banc)' : '')
                    . '. Pas encore répondu ? Clique sur les boutons : ' . discord_message_link($war));
                q('UPDATE wars SET discord_reminded_at = ? WHERE id = ?', [$now, $war['id']]);
                $log[] = "Rappel envoyé pour la war {$war['id']}.";
            } catch (DiscordError $e) {
                $log[] = "Rappel de la war {$war['id']} : " . $e->getMessage();
            }
        }
    }

    // Wars terminées depuis la dernière mise à jour du message : boutons désactivés
    q('UPDATE wars SET discord_dirty = 1 WHERE discord_message_id IS NOT NULL AND starts_at + ? <= ? AND COALESCE(discord_synced_at, 0) < starts_at + ?',
        [$len, $now, $len]);
    foreach (q('SELECT id FROM wars WHERE discord_message_id IS NOT NULL AND discord_dirty = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (discord_sync_war((int) $id)) $log[] = "Message de la war $id mis à jour.";
    }
    return $log ?: ['Rien à faire.'];
}
