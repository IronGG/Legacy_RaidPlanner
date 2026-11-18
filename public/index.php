<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
auto_archive();
$rooms = rooms_for_index();

// Filtre raid / donjon, gardé pour la session
$type = in_choice('type', array_keys(ROOM_FILTERS), null, $_GET) ?? ($_SESSION['room_filter'] ?? 'all');
if (!isset(ROOM_FILTERS[$type])) $type = 'all';
if (isset($_GET['type'])) $_SESSION['room_filter'] = $type;

if (isset($_GET['partial'])) {
    render_room_index($rooms, $me, $type);
    exit;
}

$stats = legion_stats();
page_header('Salles', 'rooms');
?>
<section class="page-head">
  <div>
    <h1>Salles</h1>
    <p class="lede">Rejoins un raid tant que ses inscriptions sont ouvertes : une fois le délai passé, il reste ouvert pour les départs et les remplacements. Un donjon reste ouvert jusqu'à ce que son chef le termine.</p>
  </div>
  <?php if (can_create_room($me)): ?><a class="btn btn-gold" href="room_new.php<?= $type === 'donjon' ? '?kind=donjon' : '' ?>">Créer une salle</a><?php endif; ?>
</section>

<div data-live data-version="<?= e(rooms_signature()) ?>" data-version-url="api.php" data-partial-url="index.php?partial=1&amp;type=<?= e($type) ?>">
  <?php render_room_index($rooms, $me, $type); ?>
</div>

<section class="legion">
  <h2 class="section-title">La légion cette semaine</h2>
  <dl class="legion-stats">
    <div><dt>Membres</dt><dd><?= $stats['total'] ?></dd></div>
    <div><dt>Confirmés, pas encore clear</dt><dd><?= $stats['ready'] ?></dd></div>
    <div><dt>Déjà clear</dt><dd><?= $stats['cleared'] ?></dd></div>
    <?php foreach ($stats['roles'] as $role => $n): ?>
      <div class="<?= e(role_class($role)) ?>"><dt><?= e($role) ?></dt><dd><?= $n['ready'] ?><small>/<?= $n['total'] ?></small></dd></div>
    <?php endforeach; ?>
  </dl>
  <p class="muted small">Par rôle : joueurs confirmés et pas encore clear, sur le total inscrit.</p>
</section>
<?php page_footer();
