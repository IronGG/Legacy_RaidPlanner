<?php
require __DIR__ . '/../src/bootstrap.php';
require_officer();

// Neutralise les formules (=, +, -, @) pour éviter l'injection CSV à l'ouverture dans Excel.
function csv_cell(mixed $v): string
{
    $v = (string) $v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

$yn = fn($v) => (int) $v ? 'Oui' : 'Non';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="recensement-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel ouvre l'UTF-8 correctement
fputcsv($out, ['N°', 'Pseudo', 'Classe', 'Rôle', 'GS', 'CP', 'Connaissance', 'Meilleure étape', 'Objectif', 'Jours dispo',
    'Créneau horaire', 'HELPER', 'Inscription', 'Clear semaine', 'Shot Caller', 'Notes / contraintes', 'Vérifié'], ';');
foreach (q('SELECT * FROM members ORDER BY id')->fetchAll() as $i => $m) {
    fputcsv($out, array_map('csv_cell', [
        $i + 1, $m['pseudo'], $m['classe'], $m['role'], $m['gs'], $m['cp'] !== null ? fmt_cp($m['cp']) : '',
        $m['connaissance'], $m['meilleure_etape'], $m['objectif'], str_replace(',', '/', $m['jours']), $m['creneau'],
        $yn($m['helper']), $m['inscription'], $yn($m['clear_semaine']), $yn($m['shot_caller']), $m['notes'],
        (int) $m['verifie'] ? 'Vérifié' : '',
    ]), ';');
}
