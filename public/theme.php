<?php
// Couleurs des rôles, générées depuis Officiers > Listes. L'URL est versionnée (theme.php?v=…), d'où le cache long.
define('NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
foreach (all_options()['role'] ?? [] as $o) {
    if ($o['color'] && preg_match('/^#[0-9a-f]{6}$/i', (string) $o['color'])) {
        echo '.role-o' . (int) $o['id'] . ' { --role: ' . $o['color'] . "; }\n";
    }
}
