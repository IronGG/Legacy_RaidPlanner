<?php
// Copie cohérente de la base, à récupérer ensuite en SFTP (le site n'offre aucun téléchargement de la base).
//   php bin/backup.php            crée data/backup-AAAA-MM-JJ-HHMM.sqlite
//   php bin/backup.php chemin     crée la copie à cet endroit
// La copie contient les mots de passe hachés : supprime-la du serveur une fois téléchargée.
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

$dest = $argv[1] ?? APP_ROOT . '/data/backup-' . date('Y-m-d-Hi') . '.sqlite';
if (file_exists($dest)) {
    fwrite(STDERR, "Le fichier existe déjà : $dest\n");
    exit(1);
}
try {
    db()->exec('VACUUM INTO ' . db()->quote($dest));
} catch (PDOException $e) {
    // SQLite trop ancien pour VACUUM INTO : copie du fichier pendant un verrou d'écriture
    tx(fn() => copy(cfg('db_path'), $dest));
}
@chmod($dest, 0600);
echo "Sauvegarde créée : $dest (" . round(filesize($dest) / 1024) . " Ko)\n";
