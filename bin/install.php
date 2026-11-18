<?php
// Installation / chef de légion.
// Usage : php bin/install.php [Pseudo]
//  - crée la base SQLite et importe seed/membres.json au premier lancement
//  - si un pseudo est donné : le passe chef de légion et affiche un code d'activation pour définir son mot de passe.
//    Il n'y a qu'un chef : l'ancien, s'il y en a un, reste officier. Relancer avec son propre pseudo sert aussi
//    à retrouver l'accès, puisque personne d'autre ne peut réinitialiser celui du chef depuis le site.
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
    echo "Astuce : php bin/install.php TonPseudo  pour devenir chef de légion.\n";
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
$previous = q('SELECT pseudo FROM members WHERE is_leader = 1 AND id <> ?', [$m['id']])->fetchAll(PDO::FETCH_COLUMN);
q('UPDATE members SET is_leader = 0, is_officer = 1 WHERE is_leader = 1 AND id <> ?', [$m['id']]);
q('UPDATE members SET is_leader = 1, is_officer = 1, verifie = 1 WHERE id = ?', [$m['id']]);
$code = issue_activation_code((int) $m['id']);
echo "{$m['pseudo']} est chef de légion.\n";
if ($previous) {
    echo "Ancien chef de légion, qui reste officier : " . implode(', ', $previous) . "\n";
}
echo "Code d'activation (7 jours) : $code\n";
echo "Ouvre activate.php?pseudo=" . rawurlencode($m['pseudo']) . " dans le navigateur pour choisir ton mot de passe.\n";
