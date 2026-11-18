<?php
declare(strict_types=1);

function page_header(string $title, string $active = '', bool $wide = false): void
{
    $me = current_member();
    $nav = [];
    if ($me) {
        $nav = ['rooms' => ['index.php', 'Salles'], 'members' => ['members.php', 'Membres'], 'profile' => ['profile.php', 'Mon profil']];
        if ((int) $me['is_officer']) {
            $nav['admin'] = ['admin.php', 'Officiers'];
        }
    } else {
        $nav = ['login' => ['login.php', 'Se connecter'], 'register' => ['register.php', "S'inscrire"]];
    }
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(cfg('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
<link rel="stylesheet" href="theme.php?v=<?= e(options_version()) ?>">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script src="<?= e(asset_url('assets/app.js')) ?>" defer></script>
</head>
<body data-poll="<?= (int) cfg('poll_ms') ?>">
<header class="topbar">
  <a class="brand" href="index.php">
    <img src="assets/favicon.svg" width="28" height="28" alt="">
    <span><strong><?= e(cfg('app_name')) ?></strong><small><?= e(cfg('tagline')) ?></small></span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($me): ?>
      <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="linkish" type="submit">Se déconnecter</button></form>
    <?php endif; ?>
  </nav>
</header>
<main class="wrap<?= $wide ? ' wrap-wide' : '' ?>">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <p class="flash flash-<?= e($type) ?>" role="status"><?= e($msg) ?></p>
<?php endforeach;
}

/** Adresse d'un fichier statique avec sa date de modification : le navigateur recharge le fichier après chaque mise à jour. */
function asset_url(string $path): string
{
    $file = APP_ROOT . '/public/' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function page_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
    background_tasks();
}

function select_field(string $name, array $options, ?string $current, string $empty = ''): string
{
    $h = '<select name="' . e($name) . '" id="f-' . e($name) . '">';
    if ($empty !== '') {
        $h .= '<option value="">' . e($empty) . '</option>';
    }
    foreach ($options as $o) {
        $h .= '<option value="' . e($o) . '"' . ($o === $current ? ' selected' : '') . '>' . e($o) . '</option>';
    }
    return $h . '</select>';
}

/** Choix de classe sous forme de grille d'emblèmes. */
function class_picker(string $name, ?string $current): string
{
    $h = '<div class="class-picker" role="radiogroup" aria-label="Classe">';
    $classes = opts('classe', $current);
    $current = $current ?: (in_array('Autre', $classes, true) ? 'Autre' : ($classes[0] ?? null));
    foreach ($classes as $c) {
        $h .= '<label class="class-option"><input type="radio" name="' . e($name) . '" value="' . e($c) . '"'
            . ($c === $current ? ' checked' : '') . '>' . class_icon($c, 44) . '<span>' . e($c) . '</span></label>';
    }
    return $h . '</div>';
}

function chip_group(string $name, array $options, ?string $current, bool $multi = false, array $currentMulti = []): string
{
    $h = '<div class="chips">';
    $isList = array_is_list($options); // liste simple : valeur = libellé ; sinon clé => libellé
    foreach ($options as $value => $label) {
        if ($isList) $value = $label;
        $checked = $multi ? in_array((string) $value, $currentMulti, true) : ((string) $value === (string) $current);
        $h .= '<label class="chip-input"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . e($name) . ($multi ? '[]' : '')
            . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    return $h . '</div>';
}

function member_badges(array $m, bool $withStatus = true): string
{
    $b = [];
    if ((int) ($m['shot_caller'] ?? 0)) $b[] = '<span class="badge badge-gold">Shot caller</span>';
    if ((int) ($m['helper'] ?? 0)) $b[] = '<span class="badge">Helper</span>';
    if ((int) ($m['clear_semaine'] ?? 0)) $b[] = '<span class="badge badge-muted">Clear cette semaine</span>';
    if ($withStatus && ($m['inscription'] ?? '') === 'À confirmer') $b[] = '<span class="badge badge-muted">À confirmer</span>';
    if ($withStatus && ($m['inscription'] ?? '') === 'Indisponible') $b[] = '<span class="badge badge-danger">Indisponible</span>';
    return implode('', $b);
}

/** Onglets de l'espace officiers. */
function admin_tabs(string $active): void
{
    $tabs = ['admin' => ['admin.php', 'Accès et semaine'], 'lists' => ['lists.php', 'Listes du site']];
    echo '<nav class="tabs" aria-label="Espace officiers">';
    foreach ($tabs as $key => [$href, $label]) {
        echo '<a href="' . e($href) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    echo '</nav>';
}
