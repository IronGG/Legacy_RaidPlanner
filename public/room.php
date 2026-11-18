<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
auto_archive();

$slug = (string) ($_GET['r'] ?? '');
$room = room_by_slug($slug);
if (!$room) {
    http_response_code(404);
    if (isset($_GET['partial'])) exit;
    page_header('Salle introuvable');
    echo '<div class="empty"><p>Cette salle n\'existe pas ou plus.</p><p><a class="btn btn-ghost" href="index.php">Retour aux salles</a></p></div>';
    page_footer();
    exit;
}

if (is_post()) {
    $target = (int) ($_POST['member_id'] ?? 0);
    try {
        $msg = match ((string) ($_POST['action'] ?? '')) {
            'join'       => room_join($slug, $me),
            'leave'      => room_leave($slug, (int) $me['id'], $me),
            'kick'       => room_leave($slug, $target, $me),
            'move'       => room_move($slug, $target, (string) ($_POST['to'] ?? ''), $me),
            'extend'     => room_extend($slug, (int) ($_POST['minutes'] ?? 30), $me),
            'close_join' => room_close_join($slug, $me),
            'finish'     => room_finish($slug, ($_POST['cleared'] ?? '') === '1', $me),
            'cancel'     => room_cancel($slug, $me),
            default      => throw new RoomError('Action inconnue.'),
        };
        flash('ok', $msg);
    } catch (RoomError $e) {
        flash('error', $e->getMessage());
    }
    redirect('room.php?r=' . rawurlencode($slug));
}

if (isset($_GET['partial'])) {
    render_room_live($room, $me);
    exit;
}

page_header($room['title'], 'rooms');
?>
<p class="back"><a href="index.php">Toutes les salles</a></p>
<div class="room" data-live data-version="<?= (int) $room['version'] ?>"
     data-version-url="api.php?r=<?= e(rawurlencode($slug)) ?>" data-partial-url="room.php?r=<?= e(rawurlencode($slug)) ?>&amp;partial=1">
  <?php render_room_live($room, $me); ?>
</div>
<?php page_footer();
