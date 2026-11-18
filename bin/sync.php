<?php
// Relit les fiches officielles AION 2 (GS, CP, classe, niveau, légion).
//   php bin/sync.php           les fiches à relire (jamais lues, trop anciennes, ou en erreur)
//   php bin/sync.php --all     toute la légion
//   php bin/sync.php Pseudo    un seul membre, avec le détail
// Pour une tâche planifiée (facultatif : l'app relit déjà les fiches en arrière-plan) : php bin/sync.php
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}
if (!aion_enabled()) {
    fwrite(STDERR, "La lecture automatique est désactivée ('aion_sync' dans config.php).\n");
    exit(1);
}

$arg = trim($argv[1] ?? '');
if ($arg !== '' && $arg !== '--all') {
    $m = member_by_pseudo($arg);
    if (!$m) {
        fwrite(STDERR, "Membre inconnu : $arg\n");
        exit(1);
    }
    $r = aion_sync_member($m);
    echo strtoupper($r['status']) . ' : ' . $r['message'] . "\n";
    foreach ($r['candidates'] ?? [] as $c) {
        echo "  nom proche : {$c['name']} (niveau {$c['level']})\n";
    }
    exit($r['status'] === 'ok' ? 0 : 2);
}

$sum = aion_sync_batch(1000, 3600.0, $arg === '--all', function (array $m, array $r) {
    echo str_pad($m['pseudo'], 16) . ' ' . str_pad($r['status'], 10) . ' ' . $r['message'] . "\n";
});
printf("\n%d à jour, %d introuvable(s), %d erreur(s)%s\n", $sum['ok'], $sum['not_found'], $sum['error'],
    $sum['remaining'] ? ", {$sum['remaining']} restant(s)" : '');
