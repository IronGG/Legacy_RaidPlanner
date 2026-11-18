<?php
// Sauvegarde manuelle de la base, à récupérer ensuite en SFTP (le site n'offre aucun téléchargement de la base).
//   php bin/backup.php            crée data/backups/manuel-AAAA-MM-JJ-HHMMSS.sqlite (jamais effacée automatiquement)
//   php bin/backup.php chemin     crée la copie à cet endroit
// Les sauvegardes automatiques (toutes les 12 h) sont dans le même dossier, nommées auto-….
// Une sauvegarde contient les mots de passe hachés : ne la diffuse pas.
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

try {
    $file = db_backup('manuel', $argv[1] ?? null);
} catch (UserError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Sauvegarde créée : $file (" . round(filesize($file) / 1024) . " Ko)\n";
