<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();

// --- Liste des wars ---------------------------------------------------------------------------------
if (!isset($_GET['w'])) {
    wars_ensure_upcoming();
    page_header('War', 'war');
    ?>
<section class="page-head">
  <div>
    <h1>War</h1>
    <p class="lede">Les wars de légion ont lieu <?= e(war_schedule_text()) ?>. Réponds pour chaque war : les officiers composent les troupes, puis publient la compo pour que chacun voie sa place.</p>
  </div>
</section>
<?php
    render_war_index($me);
    page_footer();
    exit;
}

// --- Une war ----------------------------------------------------------------------------------------
$id = (int) $_GET['w'];
$war = war_by_id($id);
if (!$war) {
    http_response_code(404);
    if (isset($_GET['partial'])) exit;
    page_header('War introuvable', 'war');
    echo '<div class="empty"><p>Cette war n\'existe pas.</p><p><a class="btn btn-ghost" href="war.php">Toutes les wars</a></p></div>';
    page_footer();
    exit;
}

if (is_post()) {
    $int = fn(string $k) => (int) ($_POST[$k] ?? 0);
    try {
        $msg = match ((string) ($_POST['action'] ?? '')) {
            'signup'        => war_signup($id, $me, in_choice('role', opts('role', null, true)), (string) ($_POST['status'] ?? ''), $me),
            'signup_for'    => war_signup($id, member_by_id($int('member_id')) ?? throw new WarError('Choisis un membre.'),
                                   in_choice('role', opts('role', null, true)), (string) ($_POST['status'] ?? ''), $me),
            'place'         => war_place($id, $int('member_id'), $int('group_id'), $int('slot'), $me),
            'unplace'       => war_unplace($id, $int('member_id'), $me),
            'group_add'     => war_group_add($id, $int('troop_id'), $me),
            'group_resize'  => war_group_resize($id, $int('group_id'), $int('delta'), $me),
            'group_delete'  => war_group_delete($id, $int('group_id'), $me),
            'troop_add'     => war_troop_add($id, $me),
            'troop_rename'  => war_troop_rename($id, $int('troop_id'), in_str('name', 60), $me),
            'troop_move'    => war_troop_move($id, $int('troop_id'), $int('dir'), $me),
            'troop_delete'  => war_troop_delete($id, $int('troop_id'), $me),
            'publish'       => war_set_published($id, ($_POST['on'] ?? '') === '1', $me),
            'cancel'        => war_set_cancelled($id, ($_POST['on'] ?? '') === '1', $me),
            'message'       => war_set_message($id, in_str('message', 300), $me),
            'clear'         => war_clear_places($id, $me),
            'copy_previous' => war_copy_previous($id, $me),
            default         => throw new WarError('Action inconnue.'),
        };
        $result = ['ok' => true, 'message' => $msg];
    } catch (UserError $e) {
        $result = ['ok' => false, 'message' => $e->getMessage()];
    }
    // Officiers : actions envoyées sans recharger la page (glisser-déposer, boutons des troupes)
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }
    flash($result['ok'] ? 'ok' : 'error', $result['message']);
    redirect('war.php?w=' . $id);
}

if (isset($_GET['partial'])) {
    render_war_live($war, $me);
    exit;
}

$open = war_signup_open($war);
page_header(war_title($war), 'war', can_manage_war($me));
?>
<p class="back"><a href="war.php">Toutes les wars</a></p>
<section class="page-head war-head">
  <div>
    <h1><?= e(ucfirst(war_title($war))) ?></h1>
    <p class="meta"><?= war_phase_html($war) ?><?php if (!$open && !(int) $war['cancelled']): ?><span>Réponses closes</span><?php endif; ?></p>
  </div>
</section>
<?php if ($open) render_war_answer($war, $me); ?>
<p class="toast" role="status" aria-live="polite" hidden></p>
<div class="war" data-live data-poll="<?= (int) cfg('war_poll_ms') ?>" data-version="<?= (int) $war['version'] ?>" data-csrf="<?= e(csrf_token()) ?>"
     data-action="war.php?w=<?= $id ?>" data-version-url="api.php?w=<?= $id ?>" data-partial-url="war.php?w=<?= $id ?>&amp;partial=1">
  <?php render_war_live($war, $me); ?>
</div>
<?php page_footer();
