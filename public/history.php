<?php
// Historique complet des salles terminées ou annulées (« history_days » derniers jours), groupées par jour.
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
auto_archive();

$type = in_choice('type', array_keys(ROOM_FILTERS), 'all', $_GET);
$rooms = rooms_history();
$count = ['all' => count($rooms), 'raid' => 0, 'donjon' => 0];
foreach ($rooms as $r) $count[room_is_raid($r) ? 'raid' : 'donjon']++;
if ($type !== 'all') {
    $rooms = array_filter($rooms, fn($r) => (room_is_raid($r) ? 'raid' : 'donjon') === $type);
}

page_header('Historique des salles', 'rooms');
?>
<p class="back"><a href="index.php<?= $type !== 'all' ? '?type=' . e($type) : '' ?>">Salles</a></p>
<section class="page-head">
  <div>
    <h1>Historique des salles</h1>
    <p class="lede">Toutes les salles terminées ou annulées des <?= (int) cfg('history_days') ?> derniers jours, les plus récentes d'abord.</p>
  </div>
</section>
<nav class="tabs room-filter" aria-label="Type de salle">
  <?php foreach (ROOM_FILTERS as $key => $label): ?>
    <a href="history.php<?= $key !== 'all' ? '?type=' . $key : '' ?>"<?= $key === $type ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-count"><?= $count[$key] ?></span></a>
  <?php endforeach; ?>
</nav>
<?php
if (!$rooms) {
    echo '<div class="empty"><p>Aucune salle terminée ou annulée sur cette période.</p></div>';
}
$day = null;
foreach ($rooms as $room) {
    $d = local_dt(room_date($room))->format('Y-m-d');
    if ($d !== $day) {
        if ($day !== null) echo '</div>';
        echo '<h2 class="history-day">' . e(fmt_day(room_date($room))) . '</h2><div class="past-list">';
        $day = $d;
    }
    echo past_row($room, fmt_time(room_date($room)), true);
}
if ($day !== null) echo '</div>';
page_footer();
