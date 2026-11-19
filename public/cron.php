<?php
// Tâches à heure fixe, appelées par le planificateur d'Infomaniak (toutes les 15 minutes) :
// créer les wars de la semaine, les poster sur Discord, envoyer les rappels, rattraper les mises à jour.
// Adresse protégée par une clé : https://…/cron.php?key=… (affichée par « php bin/discord.php setup »).
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$key = (string) (discord_secrets()['cron_key'] ?? '');
if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Clé absente ou fausse.\n");
}
@set_time_limit(60);
wars_ensure_upcoming();
echo implode("\n", discord_cron()), "\n";
