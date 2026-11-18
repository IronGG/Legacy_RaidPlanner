<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/room_form.php';
$me = require_login();
if (!can_create_room($me)) {
    flash('error', 'Seuls les raid leads et les officiers peuvent créer une salle.');
    redirect('index.php');
}

$errors = [];
$v = room_form_values(null);
$v['kind'] = in_choice('kind', ['raid', 'donjon'], 'raid', $_GET);
if (is_post()) {
    [$v, $errors, $data] = room_form_read(null);
    if (!$errors) {
        $slug = room_create($data, $me, (bool) $v['join_self']);
        flash('ok', 'Salle créée. Partage le lien sur Discord pour que la légion la rejoigne.');
        redirect('room.php?r=' . rawurlencode($slug));
    }
}

page_header('Créer une salle', 'rooms');
?>
<p class="back"><a href="index.php">Toutes les salles</a></p>
<h1>Créer une salle</h1>
<?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>
<?php room_form_render($v, null); ?>
<?php page_footer();
