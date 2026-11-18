<?php
// Téléchargement de la base complète (membres, comptes, salles, listes, journal).
require __DIR__ . '/../src/bootstrap.php';
require_officer();
session_write_close();

$tmp = APP_ROOT . '/data/backup-' . bin2hex(random_bytes(8)) . '.sqlite';
ignore_user_abort(true);
register_shutdown_function(fn() => is_file($tmp) && @unlink($tmp));

try {
    // Copie cohérente même si quelqu'un écrit au même moment
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
} catch (PDOException $e) {
    // SQLite trop ancien pour VACUUM INTO : copie du fichier pendant un verrou d'écriture
    tx(fn() => copy(cfg('db_path'), $tmp));
}

header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="guildraid-' . date('Y-m-d-Hi') . '.sqlite"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
