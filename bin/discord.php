<?php
// Bot Discord, en SSH.
//   php bin/discord.php setup        enregistre le jeton du bot (saisi sans l'afficher) et crée la clé du cron
//   php bin/discord.php test         vérifie la configuration : jeton, serveur, salon, rôles
//   php bin/discord.php post ID      poste (ou reposte) le message de la war ID dans le salon des wars
//   php bin/discord.php cron         lance les tâches du cron maintenant (postes, rappels, mises à jour)
// Le jeton est écrit dans data/discord.php (hors du site, jamais dans git). Les autres réglages sont dans config.php.
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

$cmd = $argv[1] ?? '';
$ok = fn(string $s) => print("  OK   $s\n");
$ko = function (string $s) use (&$failed) { $failed = true; print("  !!   $s\n"); };
$failed = false;

switch ($cmd) {
    case 'setup':
        $file = APP_ROOT . '/data/discord.php';
        $secrets = discord_secrets();
        echo "Jeton du bot (Portail développeur Discord > Bot > Reset Token).\n";
        echo $secrets ? "Laisse vide pour garder le jeton actuel.\n" : '';
        echo 'Jeton : ';
        $tty = stream_isatty(STDIN);
        if ($tty) system('stty -echo');
        $token = trim((string) fgets(STDIN));
        if ($tty) system('stty echo');
        echo "\n";
        if ($token === '' && empty($secrets['bot_token'])) {
            fwrite(STDERR, "Aucun jeton saisi.\n");
            exit(1);
        }
        $secrets['bot_token'] = $token !== '' ? $token : $secrets['bot_token'];
        $secrets['cron_key'] ??= bin2hex(random_bytes(24));
        $tmp = $file . '.new';
        file_put_contents($tmp, "<?php\n// Secrets du bot Discord : ne pas versionner, ne pas copier ailleurs.\nreturn " . var_export($secrets, true) . ";\n", LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, $file);
        echo "Enregistré dans $file.\n\n";
        echo "Planificateur de tâches Infomaniak (toutes les 15 minutes), adresse à appeler :\n  "
            . (discord_site_url('cron.php') ?: 'https://ton-site/cron.php') . '?key=' . $secrets['cron_key'] . "\n\n";
        echo "Ensuite : php bin/discord.php test\n";
        break;

    case 'test':
        discord_secrets(true);
        echo "Configuration :\n";
        (bool) dcfg('enabled') ? $ok("'enabled' => true") : $ko("'enabled' est à false dans config.php");
        function_exists('sodium_crypto_sign_verify_detached') ? $ok('extension sodium (signatures)') : $ko('extension sodium absente : les clics ne pourront pas être vérifiés');
        function_exists('curl_init') ? $ok('extension cURL') : $ko('extension cURL absente');
        preg_match('/^[0-9a-f]{64}$/i', (string) dcfg('public_key')) ? $ok('clé publique') : $ko('public_key manquante ou invalide (64 caractères hexadécimaux)');
        ctype_digit((string) dcfg('application_id')) ? $ok('application_id') : $ko('application_id manquant');
        ctype_digit((string) dcfg('guild_id')) ? $ok('guild_id') : $ko('guild_id manquant');
        ctype_digit((string) dcfg('war_channel_id')) ? $ok('war_channel_id') : $ko('war_channel_id manquant');
        (discord_secrets()['bot_token'] ?? '') !== '' ? $ok('jeton du bot (data/discord.php)') : $ko('jeton absent : php bin/discord.php setup');
        (string) dcfg('site_url') !== '' ? $ok('site_url : ' . dcfg('site_url')) : $ko("site_url vide : les liens vers le site n'apparaîtront pas");
        echo "Discord :\n";
        try {
            $me = discord_api('GET', '/users/@me');
            $ok('jeton accepté, bot « ' . ($me['username'] ?? '?') . ' »');
            $ch = discord_api('GET', '/channels/' . dcfg('war_channel_id'));
            ((string) ($ch['guild_id'] ?? '') === (string) dcfg('guild_id')) ? $ok('salon #' . ($ch['name'] ?? '?') . ' sur le bon serveur')
                : $ko('le salon n\'appartient pas au serveur guild_id');
            $roles = array_column(discord_api('GET', '/guilds/' . dcfg('guild_id') . '/roles'), 'name', 'id');
            foreach ((array) dcfg('member_role_ids') as $r) isset($roles[(string) $r]) ? $ok('rôle autorisé « ' . $roles[(string) $r] . ' »') : $ko("rôle $r introuvable sur le serveur");
            if ((string) dcfg('ping_role_id') !== '') isset($roles[(string) dcfg('ping_role_id')]) ? $ok('rôle mentionné « ' . $roles[(string) dcfg('ping_role_id')] . ' »') : $ko('ping_role_id introuvable sur le serveur');
            if (!dcfg('member_role_ids')) echo "  --   member_role_ids vide : tout le serveur peut répondre aux wars\n";
        } catch (DiscordError $e) {
            $ko($e->getMessage());
        }
        echo $failed ? "\nÀ corriger avant d'utiliser le bot.\n" : "\nTout est prêt. Poste une war avec le bouton du site ou : php bin/discord.php post ID\n";
        exit($failed ? 1 : 0);

    case 'post':
        $id = (int) ($argv[2] ?? 0);
        try {
            echo discord_post_war($id), "\n";
            echo discord_message_link(war_by_id($id)), "\n";
        } catch (DiscordError $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'cron':
        wars_ensure_upcoming();
        echo implode("\n", discord_cron()), "\n";
        break;

    default:
        echo "Usage : php bin/discord.php setup | test | post ID | cron\n";
        exit($cmd === '' ? 0 : 1);
}
