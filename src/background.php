<?php
declare(strict_types=1);

/*
 * Tâches de fond déclenchées par les visites (pas besoin de tâche planifiée) :
 * sauvegarde de la base toutes les 12 h, relecture des fiches AION 2.
 * Avec PHP-FPM (Infomaniak), elles tournent après l'envoi de la page : le visiteur n'attend pas.
 */
function background_tasks(): void
{
    if (PHP_SAPI === 'cli') return;
    $fpm = function_exists('fastcgi_finish_request');
    $tasks = [];
    if (backup_claim()) {
        $tasks[] = 'backup_auto'; // quelques millisecondes : se fait aussi en local
    }
    if ($fpm && aion_claim_tick()) {
        $tasks[] = fn() => aion_sync_batch(5, 15.0); // requêtes réseau : seulement si le visiteur n'attend pas
    }
    if (!$tasks) return;
    register_shutdown_function(function () use ($tasks, $fpm) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if ($fpm) fastcgi_finish_request();
        foreach ($tasks as $task) {
            try {
                $task();
            } catch (Throwable $e) {
                error_log('guildraid background: ' . $e->getMessage());
            }
        }
    });
}
