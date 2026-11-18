<?php
// Importe des membres (sans compte activé) depuis un fichier JSON au format de seed/membres.json,
// par exemple le recensement tiré de l'Excel. Les membres déjà présents ne sont jamais modifiés.
//   php bin/import.php --dry-run          montre ce qui serait importé, sans rien changer
//   php bin/import.php                    importe seed/membres.json
//   php bin/import.php fichier.json       importe ce fichier
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));
$path = $args[0] ?? cfg('seed_path');

if (!is_file($path)) {
    fwrite(STDERR, "Fichier introuvable : $path\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($path), true);
if (!is_array($rows)) {
    fwrite(STDERR, "Ce fichier n'est pas un JSON valide : $path\n");
    exit(1);
}

$count = ['ajouté' => 0, 'déjà présent' => 0, 'ignoré' => 0];
$todo = [];
foreach ($rows as $r) {
    $pseudo = trim((string) ($r['pseudo'] ?? ''));
    if ($pseudo === '' || validate_pseudo($pseudo) !== null) {
        $count['ignoré']++;
        echo "  ignoré (pseudo invalide) : $pseudo\n";
    } elseif (member_by_pseudo($pseudo) || isset($todo[mb_strtolower($pseudo)])) {
        $count['déjà présent']++;
        echo "  déjà présent             : $pseudo\n";
    } else {
        $todo[mb_strtolower($pseudo)] = $r + ['pseudo' => $pseudo];
        $count['ajouté']++;
        echo "  " . ($dry ? 'à ajouter' : 'ajouté   ') . "                : $pseudo\n";
    }
}

if (!$dry && $todo) {
    tx(function () use ($todo) {
        $now = time();
        foreach ($todo as $r) {
            q('INSERT INTO members (pseudo, classe, role, gs, cp, connaissance, meilleure_etape, objectif, jours, creneau, helper,
                                    inscription, clear_semaine, shot_caller, notes, verifie, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                trim((string) $r['pseudo']), $r['classe'] ?? 'Autre', $r['role'] ?? null,
                isset($r['gs']) ? (int) $r['gs'] : null, isset($r['cp']) ? (int) $r['cp'] : null,
                $r['connaissance'] ?? null, $r['meilleure_etape'] ?? null, $r['objectif'] ?? null,
                implode(',', array_intersect(DAYS, (array) ($r['jours'] ?? []))), $r['creneau'] ?? null,
                (int) ($r['helper'] ?? 0), in_array($r['inscription'] ?? '', INSCRIPTION, true) ? $r['inscription'] : 'À confirmer',
                (int) ($r['clear_semaine'] ?? 0), (int) ($r['shot_caller'] ?? 0), $r['notes'] ?? null, (int) ($r['verifie'] ?? 0), $now, $now,
            ]);
        }
    });
    $newValues = options_add_used_values();
    if ($newValues) {
        echo "\n$newValues valeur(s) nouvelle(s) ajoutée(s) aux listes du site (Officiers > Listes du site).\n";
    }
}

printf("\n%s : %d %s, %d déjà présent(s), %d ignoré(s).\n", $dry ? 'Simulation' : 'Import terminé',
    $count['ajouté'], $dry ? 'à ajouter' : 'ajouté(s)', $count['déjà présent'], $count['ignoré']);
if ($dry && $count['ajouté']) {
    echo "Relance sans --dry-run pour importer.\n";
} elseif (!$dry && $count['ajouté']) {
    echo "Ensuite : php bin/sync.php pour lier leurs personnages en jeu (GS, CP, classe),\n";
    echo "puis Officiers > Accès des membres pour générer leurs codes d'activation.\n";
}
