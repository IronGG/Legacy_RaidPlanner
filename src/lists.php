<?php
declare(strict_types=1);

/*
 * Listes déroulantes du site. Elles sont stockées en base (table `options`) et se gèrent
 * depuis Officiers > Listes. Les valeurs ci-dessous ne servent qu'au premier lancement
 * (reprises des menus déroulants de l'Excel).
 */

// Chaque liste, et les colonnes qui stockent ses valeurs (mises à jour quand on renomme une option).
const OPTION_LISTS = [
    'classe'       => ['label' => 'Classes', 'columns' => [['members', 'classe']]],
    'role'         => ['label' => 'Rôles', 'columns' => [['members', 'role'], ['room_slots', 'role'], ['war_signups', 'role']]],
    'etape'        => ['label' => 'Étapes du raid', 'columns' => [['members', 'meilleure_etape'], ['rooms', 'etape']]],
    'objectif'     => ['label' => 'Objectifs', 'columns' => [['members', 'objectif'], ['rooms', 'objectif']]],
    'connaissance' => ['label' => 'Connaissance du raid', 'columns' => [['members', 'connaissance']]],
];

const DEFAULT_OPTIONS = [
    'classe'       => ['Gladiateur', 'Templier', 'Assassin', 'Rôdeur', 'Sorcier', 'Spiritualiste', 'Clerc', 'Aède', 'Autre'],
    'role'         => ['Tank', 'Soin', 'Support', 'DPS', 'Polyvalent'],
    'etape'        => ['Découverte', 'Rahu', 'Ketu', 'Ludra - try', 'Ludra - proche', 'Ludra - clear'],
    'objectif'     => ['Clear', 'Progression', 'Apprentissage', 'Flexible'],
    'connaissance' => ['Débutant', 'Intermédiaire', 'Confirmé', 'Référent'],
];
const DEFAULT_CLASS_ICONS = [
    'Gladiateur' => 'gladiateur.svg', 'Templier' => 'templier.svg', 'Assassin' => 'assassin.svg', 'Rôdeur' => 'rodeur.svg',
    'Sorcier' => 'sorcier.svg', 'Spiritualiste' => 'spiritualiste.svg', 'Clerc' => 'clerc.svg', 'Aède' => 'aede.svg', 'Autre' => 'autre.svg',
];
const DEFAULT_ROLE_COLORS = ['Tank' => '#72a3e8', 'Soin' => '#84d3a3', 'Support' => '#c49ce8', 'DPS' => '#ee8f6d', 'Polyvalent' => '#d2c68b'];
const DEFAULT_REQUIRED_ROLES = ['Tank', 'Soin'];
const FALLBACK_ICON = 'autre.svg';

// Ces deux listes restent fixes : la logique de l'app en dépend (« Indisponible » bloque les inscriptions, etc.).
const INSCRIPTION = ['Confirmé', 'À confirmer', 'Indisponible'];
const DAYS        = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

function all_options(bool $refresh = false): array
{
    if ($refresh || !isset($GLOBALS['__options'])) {
        $GLOBALS['__options'] = array_fill_keys(array_keys(OPTION_LISTS), []);
        foreach (q('SELECT * FROM options ORDER BY list, position, id')->fetchAll() as $o) {
            $GLOBALS['__options'][$o['list']][] = $o;
        }
    }
    return $GLOBALS['__options'];
}

/**
 * Valeurs proposées dans les formulaires (options actives, dans l'ordre choisi).
 * $keep : valeur actuelle à conserver même si l'option a été masquée depuis.
 */
function opts(string $list, ?string $keep = null, bool $withInactive = false): array
{
    $values = [];
    foreach (all_options()[$list] ?? [] as $o) {
        if ($withInactive || (int) $o['active'] === 1) {
            $values[] = $o['value'];
        }
    }
    if ($keep !== null && $keep !== '' && !in_array($keep, $values, true)) {
        $values[] = $keep;
    }
    return $values;
}

function opt_row(string $list, ?string $value): ?array
{
    foreach (all_options()[$list] ?? [] as $o) {
        if ($o['value'] === $value) return $o;
    }
    return null;
}

/** Fichier d'emblème d'une classe (dans public/assets/classes/). */
function class_icon_file(?string $classe): string
{
    $icon = opt_row('classe', $classe)['icon'] ?? null;
    return ($icon && valid_icon_name($icon)) ? $icon : FALLBACK_ICON;
}

function valid_icon_name(string $icon): bool
{
    return (bool) preg_match('#^(custom/)?[a-z0-9_-]+\.(svg|png|jpe?g|webp|gif)$#', $icon)
        && is_file(APP_ROOT . '/public/assets/classes/' . $icon);
}

/** Emblèmes disponibles : ceux fournis + ceux envoyés par les officiers. */
function available_icons(): array
{
    $dir = APP_ROOT . '/public/assets/classes/';
    $icons = [];
    foreach (array_merge(glob($dir . '*.*') ?: [], glob($dir . 'custom/*.*') ?: []) as $f) {
        $name = substr($f, strlen($dir));
        if (valid_icon_name($name)) $icons[] = $name;
    }
    sort($icons);
    return $icons;
}

/** Classe CSS d'un rôle (sa couleur vient de theme.php). */
function role_class(?string $role): string
{
    $o = opt_row('role', $role);
    return $o ? 'role-o' . (int) $o['id'] : 'role-none';
}

function required_roles(): array
{
    return array_values(array_map(fn($o) => $o['value'], array_filter(all_options()['role'] ?? [], fn($o) => (int) $o['required'] === 1 && (int) $o['active'] === 1)));
}

/** Change dès qu'une option est modifiée : sert à invalider le cache de theme.php. */
function options_version(): string
{
    return (string) q('SELECT COALESCE(MAX(updated_at), 0) || \'-\' || COUNT(*) FROM options')->fetchColumn();
}

/** Nombre de membres / salles qui utilisent chaque valeur d'une liste. */
function option_usage(string $list): array
{
    $usage = [];
    foreach (OPTION_LISTS[$list]['columns'] as [$table, $col]) {
        foreach (q("SELECT $col AS v, COUNT(*) AS n FROM $table WHERE $col IS NOT NULL GROUP BY $col")->fetchAll() as $r) {
            $usage[$r['v']] = ($usage[$r['v']] ?? 0) + (int) $r['n'];
        }
    }
    return $usage;
}
