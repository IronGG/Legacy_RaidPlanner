<?php
// Clé de poivre des mots de passe (data/pepper.php).
//   php bin/pepper.php             état : clé présente, empreinte, comptes concernés
//   php bin/pepper.php rotate      nouvelle clé. TOUS les mots de passe et codes d'activation deviennent invalides :
//                                  chaque membre devra réactiver son profil avec un nouveau code.
// Avant le changement, une sauvegarde de la base est créée (data/backups/avant-poivre-….sqlite) ;
// elle ne pourra resservir qu'avec l'ANCIENNE clé.
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

db();
$path = pepper_path();
$accounts = (int) q('SELECT COUNT(*) FROM members WHERE password_hash IS NOT NULL')->fetchColumn();
$codes = (int) q('SELECT COUNT(*) FROM members WHERE activation_hash IS NOT NULL')->fetchColumn();
$cmd = $argv[1] ?? 'status';

if ($cmd === 'status') {
    if (!is_file($path)) {
        echo "Aucune clé : $path\n";
        echo $accounts || $codes
            ? "ATTENTION : $accounts compte(s) et $codes code(s) existent : restaure ta copie de la clé (voir le README).\n"
            : "Elle sera créée par php bin/install.php ou au premier compte.\n";
        exit($accounts || $codes ? 1 : 0);
    }
    echo "Clé       : $path (droits " . substr(sprintf('%o', fileperms($path)), -4) . ")\n";
    echo "Empreinte : " . pepper_fingerprint() . "  (change seulement si la clé change)\n";
    echo "Comptes   : $accounts avec mot de passe, $codes code(s) d'activation en attente\n";
    exit(0);
}

if ($cmd !== 'rotate') {
    fwrite(STDERR, "Commande inconnue. Usage : php bin/pepper.php [rotate]\n");
    exit(1);
}

echo "Changer la clé de poivre rend invalides les $accounts mot(s) de passe et $codes code(s) d'activation.\n";
echo "Tout le monde est déconnecté et devra réactiver son profil avec un nouveau code.\n";
if (!in_array('--yes', $argv, true)) {
    echo "Tape CHANGER pour confirmer : ";
    if (trim((string) fgets(STDIN)) !== 'CHANGER') {
        echo "Annulé, rien n'a changé.\n";
        exit(1);
    }
}

// 1. Sauvegarde de la base telle qu'elle est (utilisable seulement avec l'ancienne clé)
try {
    $backup = db_backup('avant-poivre');
    echo "Sauvegarde : $backup\n";
} catch (UserError $e) {
    fwrite(STDERR, "Sauvegarde impossible, rien n'a changé : " . $e->getMessage() . "\n");
    exit(1);
}

// 2. Plus aucun mot de passe ni code : l'ancienne clé ne sert plus à rien
tx(fn() => q('UPDATE members SET password_hash = NULL, activation_hash = NULL, activation_expires = NULL, updated_at = ?', [time()]));

// 3. Nouvelle clé, écrite à côté puis mise en place d'un coup
$tmp = $path . '.new';
if (file_put_contents($tmp, pepper_file_content(), LOCK_EX) === false || !rename($tmp, $path)) {
    @unlink($tmp);
    fwrite(STDERR, "Impossible d'écrire la nouvelle clé dans $path. Les comptes sont déjà vidés :\n"
        . "supprime $path puis lance php bin/install.php TonPseudo pour repartir.\n");
    exit(1);
}
@chmod($path, 0600);
pepper(true);
echo "Nouvelle clé en place. Empreinte : " . pepper_fingerprint() . "\n\n";

// 4. Codes pour les officiers, pour qu'ils puissent se reconnecter et distribuer les autres
foreach (q('SELECT id, pseudo FROM members WHERE is_officer = 1 OR is_leader = 1 ORDER BY pseudo COLLATE NOCASE')->fetchAll() as $o) {
    echo "Code d'activation de {$o['pseudo']} (7 jours) : " . issue_activation_code((int) $o['id']) . "\n";
}
echo "\nÀ faire maintenant :\n";
echo " 1. Copie le contenu de $path dans ton gestionnaire de mots de passe (remplace l'ancienne clé).\n";
echo " 2. Active ton profil : activate.php?pseudo=TonPseudo avec le code ci-dessus.\n";
echo " 3. Officiers > Accès et semaine : génère un code pour chaque membre et envoie-les sur Discord.\n";
