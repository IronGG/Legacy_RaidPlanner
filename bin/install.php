<?php
// Installation / admins (rang interne « leader »).
// Usage : php bin/install.php [Pseudo]           crée la base si besoin ; le pseudo devient admin
//         php bin/install.php --retirer Pseudo   retire le rang d'admin (le membre reste officier)
//  - crée la base SQLite et importe seed/membres.json au premier lancement
//  - avec un pseudo : le passe admin (les autres admins gardent leur rang) et affiche un code d'activation
//    pour définir son mot de passe. Relancer avec son propre pseudo sert aussi à retrouver l'accès : personne ne
//    peut réinitialiser celui d'un admin depuis le site.
//  - --retirer : un admin ne peut pas retirer le rang d'un autre admin depuis le site, ça se fait ici.
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

db();
$count = (int) q('SELECT COUNT(*) FROM members')->fetchColumn();
echo "Base prête : " . cfg('db_path') . " ($count membres)\n";
if (pepper_ensure()) {
    echo "Clé de poivre des mots de passe créée : " . pepper_path() . "\n";
    echo "  -> Garde-en une copie à part (gestionnaire de mots de passe). Sans elle, plus personne ne peut se connecter.\n";
}
pepper(); // vérifie que la clé est lisible

$remove = ($argv[1] ?? '') === '--retirer';
$pseudo = trim($argv[$remove ? 2 : 1] ?? '');
if ($pseudo === '') {
    echo $remove ? "Usage : php bin/install.php --retirer Pseudo\n" : "Astuce : php bin/install.php TonPseudo  pour devenir admin.\n";
    exit($remove ? 1 : 0);
}
if ($remove) {
    $m = member_by_pseudo($pseudo);
    if (!$m || !is_leader($m)) {
        fwrite(STDERR, "$pseudo n'est pas admin.\n");
        exit(1);
    }
    if (!remove_leader($m)) {
        fwrite(STDERR, "{$m['pseudo']} est le dernier admin : nomme d'abord quelqu'un d'autre.\n");
        exit(1);
    }
    echo "{$m['pseudo']} n'est plus admin. Il ou elle reste officier.\n";
    exit(0);
}
if ($err = validate_pseudo($pseudo)) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}
$m = member_by_pseudo($pseudo);
if (!$m) {
    q('INSERT INTO members (pseudo, created_at, updated_at) VALUES (?, ?, ?)', [$pseudo, time(), time()]);
    $m = member_by_pseudo($pseudo);
    echo "Profil « {$m['pseudo']} » créé.\n";
}
make_leader($m);
q('UPDATE members SET verifie = 1 WHERE id = ?', [$m['id']]);
$code = issue_activation_code((int) $m['id']);
$leaders = q('SELECT pseudo FROM members WHERE is_leader = 1 ORDER BY pseudo COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
echo "{$m['pseudo']} est admin. Admins : " . implode(', ', $leaders) . "\n";
echo "Code d'activation (7 jours) : $code\n";
echo "Ouvre activate.php?pseudo=" . rawurlencode($m['pseudo']) . " dans le navigateur pour choisir ton mot de passe.\n";
