<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/room_form.php';
$me = require_login();

$slug = (string) ($_GET['r'] ?? '');
$room = room_by_slug($slug);
if (!$room || $room['status'] !== 'active' || !can_manage_room($room, $me)) {
    flash('error', !$room ? 'Salle introuvable.' : ($room['status'] !== 'active'
        ? 'Cette salle est terminée : elle ne peut plus être modifiée.'
        : 'Seuls le chef de salle, les raid leads et les officiers peuvent modifier la salle.'));
    redirect($room ? 'room.php?r=' . rawurlencode($slug) : 'index.php');
}

$errors = [];
$v = room_form_values($room);
if (is_post()) {
    [$v, $errors, $data] = room_form_read($room);
    if (!$errors) {
        try {
            flash('ok', room_update($slug, $data, $me));
            redirect('room.php?r=' . rawurlencode($slug));
        } catch (RoomError $e) {
            $errors[] = $e->getMessage();
        }
    }
}
$counts = slot_counts(room_members_of([(int) $room['id']])[(int) $room['id']]);

page_header('Modifier « ' . $room['title'] . ' »', 'rooms');
?>
<p class="back"><a href="room.php?r=<?= e(rawurlencode($slug)) ?>">Retour à la salle</a></p>
<h1>Modifier la salle</h1>
<?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>
<?php room_form_render($v, $room, $counts); ?>
<?php page_footer();
