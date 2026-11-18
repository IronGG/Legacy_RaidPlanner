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
            'join_reserve' => room_join($slug, $me, true),
            'standby'    => room_standby($slug, $me),
            'step_up'    => room_step_up($slug, $me),
            'leave'      => room_leave($slug, (int) $me['id'], $me),
            'kick'       => room_leave($slug, $target, $me),
            'move'       => room_move($slug, $target, (string) ($_POST['to'] ?? ''), $me),
            'place'      => room_place($slug, $target, (int) ($_POST['position'] ?? -1), $me),
            'slot_role'  => room_set_slot_role($slug, (int) ($_POST['position'] ?? -1), ($_POST['role'] ?? '') === '' ? null : (string) $_POST['role'], $me),
            'extend'     => room_extend($slug, (int) ($_POST['minutes'] ?? 30), $me),
            'close_join' => room_close_join($slug, $me),
            'finish'     => room_finish($slug, ($_POST['cleared'] ?? '') === '1', $me),
            'cancel'     => room_cancel($slug, $me),
            default      => throw new RoomError('Action inconnue.'),
        };
        $result = ['ok' => true, 'message' => $msg];
    } catch (RoomError $e) {
        $result = ['ok' => false, 'message' => $e->getMessage()];
    }
    // Déplacement par glisser-déposer : réponse JSON, la page se met à jour sans rechargement
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }
    flash($result['ok'] ? 'ok' : 'error', $result['message']);
    redirect('room.php?r=' . rawurlencode($slug));
}

if (isset($_GET['partial'])) {
    render_room_live($room, $me);
    exit;
}

page_header($room['title'], 'rooms');
?>
<p class="back"><a href="index.php">Toutes les salles</a></p>
<p class="toast" role="status" aria-live="polite" hidden></p>
<div class="room" data-live data-version="<?= (int) $room['version'] ?>" data-csrf="<?= e(csrf_token()) ?>" data-action="room.php?r=<?= e(rawurlencode($slug)) ?>"
     data-version-url="api.php?r=<?= e(rawurlencode($slug)) ?>" data-partial-url="room.php?r=<?= e(rawurlencode($slug)) ?>&amp;partial=1">
  <?php render_room_live($room, $me); ?>
</div>
<?php page_footer();
