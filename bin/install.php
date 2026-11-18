<?php
// Installation / premier officier.
// Usage : php bin/install.php [PseudoOfficier]
//  - crée la base SQLite et importe seed/membres.json au premier lancement
//  - si un pseudo est donné : le passe officier et affiche un code d'activation pour définir son mot de passe
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

$pseudo = trim($argv[1] ?? '');
if ($pseudo === '') {
    echo "Astuce : php bin/install.php TonPseudo  pour devenir officier.\n";
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
q('UPDATE members SET is_officer = 1, verifie = 1 WHERE id = ?', [$m['id']]);
$code = issue_activation_code((int) $m['id']);
echo "{$m['pseudo']} est officier.\n";
echo "Code d'activation (7 jours) : $code\n";
echo "Ouvre activate.php?pseudo=" . rawurlencode($m['pseudo']) . " dans le navigateur pour choisir ton mot de passe.\n";
