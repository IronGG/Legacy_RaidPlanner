<?php
declare(strict_types=1);

/*
 * Sauvegardes de la base dans data/backups/ : hors de la racine web, jamais téléchargeables depuis le site.
 * On les récupère par SFTP. Automatiques toutes les « backup_every_hours » heures, sans tâche planifiée :
 * la sauvegarde se fait après l'affichage d'une page, quand l'intervalle est écoulé.
 */

function backup_dir(): string
{
    return APP_ROOT . '/data/backups';
}

/** Copie cohérente de la base (même pendant une écriture). Retourne le chemin du fichier créé. */
function db_backup(string $prefix = 'auto', ?string $dest = null): string
{
    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new UserError("Impossible de créer le dossier des sauvegardes ($dir).");
    }
    $dest ??= $dir . '/' . $prefix . '-' . date('Y-m-d-His') . '.sqlite';
    if (file_exists($dest)) {
        throw new UserError("Le fichier existe déjà : $dest");
    }
    try {
        db()->exec('VACUUM INTO ' . db()->quote($dest));
    } catch (PDOException $e) {
        // SQLite trop ancien pour VACUUM INTO : copie du fichier pendant un verrou d'écriture
        tx(fn() => copy(cfg('db_path'), $dest));
    }
    if (!is_file($dest)) {
        throw new UserError('La sauvegarde a échoué.');
    }
    @chmod($dest, 0600);
    return $dest;
}

/** Ne garde que les « backup_keep » sauvegardes automatiques les plus récentes (les manuelles ne sont jamais effacées). */
function backup_prune(): int
{
    $files = glob(backup_dir() . '/auto-*.sqlite') ?: [];
    sort($files); // les noms sont datés : l'ordre alphabétique est l'ordre chronologique
    $old = array_slice($files, 0, max(0, count($files) - max(1, (int) cfg('backup_keep'))));
    foreach ($old as $f) {
        @unlink($f);
    }
    return count($old);
}

/** Réserve la prochaine sauvegarde si l'intervalle est écoulé (une seule requête la fait, même avec plusieurs visiteurs). */
function backup_claim(): bool
{
    $every = (int) cfg('backup_every_hours');
    if ($every <= 0) return false;
    q("INSERT OR IGNORE INTO kv (key, value) VALUES ('backup_last', '0')");
    $now = time();
    return q("UPDATE kv SET value = ? WHERE key = 'backup_last' AND CAST(value AS INTEGER) < ?", [(string) $now, $now - $every * 3600])->rowCount() === 1;
}

function backup_auto(): void
{
    try {
        db_backup('auto');
        backup_prune();
        kv_set('backup_error', '');
    } catch (Throwable $e) {
        kv_set('backup_error', date('d.m.Y H:i') . ' : ' . $e->getMessage());
        // nouvel essai dans une heure plutôt qu'au prochain intervalle
        kv_set('backup_last', (string) (time() - (int) cfg('backup_every_hours') * 3600 + 3600));
    }
}

function backup_status(): array
{
    $auto = glob(backup_dir() . '/auto-*.sqlite') ?: [];
    $last = 0;
    foreach ($auto as $f) {
        $last = max($last, (int) filemtime($f));
    }
    return ['count' => count($auto), 'last' => $last, 'error' => kv_get('backup_error')];
}
