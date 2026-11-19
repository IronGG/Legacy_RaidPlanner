<?php
// Point d'entrée des interactions Discord (« Interactions Endpoint URL » du portail développeur).
// Discord y envoie chaque clic sur un bouton du message d'une war, signé avec la clé de l'application.
// Pas de session ni de jeton CSRF ici : c'est la signature Ed25519 qui prouve que la requête vient de Discord.
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function discord_reply(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Réponse visible seulement par la personne qui a cliqué. */
function discord_private(string $text): array
{
    return ['type' => 4, 'data' => ['content' => $text, 'flags' => DISCORD_EPHEMERAL, 'allowed_mentions' => ['parse' => []]]];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    discord_reply(['error' => 'POST only'], 405);
    exit;
}
$body = (string) file_get_contents('php://input');
if (!discord_verify((string) ($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? ''), (string) ($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? ''), $body)) {
    discord_reply(['error' => 'invalid request signature'], 401);
    exit;
}
$in = json_decode($body, true);
if (!is_array($in)) {
    discord_reply(['error' => 'bad json'], 400);
    exit;
}

// 1 = PING : Discord vérifie l'adresse quand on l'enregistre dans le portail
if ((int) ($in['type'] ?? 0) === 1) {
    discord_reply(['type' => 1]);
    exit;
}

// 3 = clic sur un bouton ; le reste (commandes…) n'est pas utilisé
if ((int) ($in['type'] ?? 0) !== 3) {
    discord_reply(discord_private('Ce bot ne répond qu\'aux boutons des messages de war.'));
    exit;
}

$note = null;
try {
    if ((string) ($in['guild_id'] ?? '') !== (string) dcfg('guild_id') || !isset($in['member'])) {
        throw new DiscordError('Ce bot ne sert que sur le serveur Discord de la légion ' . cfg('aion_legion') . '.');
    }
    if (!preg_match('/^war:(\d+):(present|late|tentative|bench|absent|place)$/', (string) ($in['data']['custom_id'] ?? ''), $m)) {
        throw new DiscordError('Bouton inconnu.');
    }
    $warId = (int) $m[1];
    $action = $m[2];
    $war = war_by_id($warId);
    if (!$war) throw new DiscordError("Cette war n'existe plus sur le site.");

    if ($action === 'place') {
        [$member] = discord_member_for($in['member'], false);
        $layout = war_layout($warId);
        $mine = $layout['placed'][(int) $member['id']] ?? null;
        $s = war_signup_of($warId, (int) $member['id']);
        $text = match (true) {
            !$war['published_at'] => 'La compo de cette war n\'est pas encore publiée.',
            (bool) $mine          => '📋 Ta place : **' . discord_escape($mine['troop']) . ' · Groupe ' . (int) $mine['group'] . '**',
            $s && $s['status'] !== 'absent' => 'Tu n\'es pas placé·e dans la compo pour l\'instant.',
            default               => 'Tu n\'as pas répondu présent à cette war.',
        };
        $url = discord_site_url('war.php?w=' . $warId);
        discord_reply(discord_private($text . ($url !== '' ? "\nToute la compo : " . $url : '')));
        exit;
    }

    [$member, $note] = discord_member_for($in['member'], true);
    war_signup($warId, $member, $action, $member);
    // Réponse « mettre à jour le message » : la liste se rafraîchit tout de suite chez tout le monde
    $war = war_by_id($warId);
    discord_reply(['type' => 7, 'data' => discord_war_message($war)]);
    if ((string) ($in['message']['id'] ?? '') === (string) $war['discord_message_id']) {
        q('UPDATE wars SET discord_dirty = 0, discord_synced_at = ?, discord_error = NULL WHERE id = ?', [time(), $warId]);
    }
} catch (UserError $e) {
    discord_reply(discord_private('⚠️ ' . $e->getMessage()));
    exit;
}

// Premier clic : message privé qui confirme la liaison ou la création du profil (envoyé après la réponse)
if ($note !== null) {
    $appId = (string) ($in['application_id'] ?? dcfg('application_id'));
    $token = (string) ($in['token'] ?? '');
    if ($token !== '' && ctype_digit($appId) && preg_match('/^[A-Za-z0-9_.\-]+$/', $token)) {
        discord_after_response(fn() => discord_api('POST', '/webhooks/' . $appId . '/' . $token,
            ['content' => '✅ ' . $note, 'flags' => DISCORD_EPHEMERAL, 'allowed_mentions' => ['parse' => []]], false));
    }
}
