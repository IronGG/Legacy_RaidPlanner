<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
$isOfficer = (int) $me['is_officer'] === 1;

$f = [
    'classe'      => in_choice('classe', opts('classe', null, true), null, $_GET),
    'role'        => in_choice('role', opts('role', null, true), null, $_GET),
    'inscription' => in_choice('inscription', INSCRIPTION, null, $_GET),
    'q'           => in_str('q', 40, $_GET),
];
$sorts = ['pseudo' => 'pseudo COLLATE NOCASE', 'gs' => 'gs IS NULL, gs DESC', 'cp' => 'cp IS NULL, cp DESC', 'classe' => 'classe, pseudo COLLATE NOCASE'];
$sort = array_key_exists($_GET['tri'] ?? '', $sorts) ? $_GET['tri'] : 'pseudo';

$where = [];
$params = [];
foreach (['classe', 'role', 'inscription'] as $k) {
    if ($f[$k]) { $where[] = "$k = ?"; $params[] = $f[$k]; }
}
if ($f['q'] !== '') { $where[] = "pseudo LIKE ? ESCAPE '\\'"; $params[] = '%' . addcslashes($f['q'], '%_\\') . '%'; }
$members = q('SELECT * FROM members' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $sorts[$sort], $params)->fetchAll();

function sort_link(string $key, string $label, string $current, array $f): string
{
    $qs = http_build_query(array_filter($f + ['tri' => $key], fn($v) => $v !== null && $v !== ''));
    return '<a href="members.php?' . e($qs) . '"' . ($key === $current ? ' aria-current="true"' : '') . '>' . e($label) . '</a>';
}

page_header('Membres', 'members', true);
?>
<section class="page-head">
  <div>
    <h1>Membres de la légion</h1>
    <p class="lede"><?= count($members) ?> membre<?= count($members) > 1 ? 's' : '' ?><?= array_filter($f) ? ' avec ces filtres' : '' ?>.</p>
  </div>
</section>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Pseudo" aria-label="Rechercher un pseudo">
  <?= select_field('classe', opts('classe', null, true), $f['classe'], 'Toutes les classes') ?>
  <?= select_field('role', opts('role', null, true), $f['role'], 'Tous les rôles') ?>
  <?= select_field('inscription', INSCRIPTION, $f['inscription'], 'Toutes les disponibilités') ?>
  <input type="hidden" name="tri" value="<?= e($sort) ?>">
  <button class="btn btn-ghost" type="submit">Filtrer</button>
  <?php if (array_filter($f)): ?><a href="members.php">Effacer</a><?php endif; ?>
</form>

<div class="table-wrap">
<table class="roster">
  <thead>
    <tr>
      <th><?= sort_link('pseudo', 'Pseudo', $sort, $f) ?> <span class="muted">/</span> <?= sort_link('classe', 'classe', $sort, $f) ?></th>
      <th>Rôle</th>
      <th class="num"><?= sort_link('gs', 'GS', $sort, $f) ?></th>
      <th class="num"><?= sort_link('cp', 'CP', $sort, $f) ?></th>
      <th>Connaissance</th>
      <th>Meilleure étape</th>
      <th>Objectif</th>
      <th>Jours</th>
      <th>Créneau</th>
      <th>Disponibilité</th>
      <th>Vérifié</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($members as $m): $days = member_days($m); ?>
    <tr class="<?= (int) $m['clear_semaine'] ? 'is-cleared' : '' ?>">
      <td>
        <div class="who">
          <?= class_icon($m['classe'], 32) ?>
          <div>
            <span class="who-name"><?= $isOfficer ? '<a href="profile.php?id=' . (int) $m['id'] . '">' . e($m['pseudo']) . '</a>' : e($m['pseudo']) ?></span>
            <span class="who-sub"><?= e($m['classe']) ?><?= member_badges($m, false) ?><?= ($isOfficer && !$m['password_hash']) ? '<span class="badge badge-muted">Pas activé</span>' : '' ?><?= ($isOfficer && aion_legion_mismatch($m)) ? '<span class="badge badge-danger">Hors légion en jeu</span>' : '' ?><?= ($isOfficer && aion_enabled() && $m['sync_status'] === 'not_found') ? '<span class="badge badge-danger">Introuvable en jeu</span>' : '' ?></span>
          </div>
        </div>
      </td>
      <td><?= $m['role'] ? '<span class="role-tag ' . e(role_class($m['role'])) . '">' . e($m['role']) . '</span>' : '—' ?></td>
      <?php $auto = aion_enabled() && $m['sync_status'] === 'ok'; $tip = $auto ? ' title="Lu sur le site officiel ' . e(fmt_ago($m['synced_at'])) . '"' : ' title="Saisi à la main"'; ?>
      <td class="num<?= $auto ? ' synced' : '' ?>"<?= $tip ?>><?= e(fmt_gs($m['gs'])) ?></td>
      <td class="num<?= $auto ? ' synced' : '' ?>"<?= $tip ?>><?= e(fmt_cp($m['cp'])) ?></td>
      <td><?= e($m['connaissance'] ?? '—') ?></td>
      <td><?= e($m['meilleure_etape'] ?? '—') ?></td>
      <td><?= e($m['objectif'] ?? '—') ?></td>
      <td><span class="days" aria-label="<?= e($days ? implode(', ', $days) : 'Non renseigné') ?>"><?php foreach (DAYS as $d): ?><i class="<?= in_array($d, $days, true) ? 'on' : '' ?>" title="<?= e($d) ?>"><?= e(mb_substr($d, 0, 1)) ?></i><?php endforeach; ?></span></td>
      <td class="creneau"><?= e($m['creneau'] ?? '') ?></td>
      <td><span class="insc <?= e(insc_class($m['inscription'])) ?>"><?= e($m['inscription']) ?></span></td>
      <td><?= (int) $m['verifie'] ? '<span class="ok-mark" title="Vérifié">Oui</span>' : '<span class="muted">Non</span>' ?></td>
    </tr>
    <?php if ($m['notes']): ?>
      <tr class="notes-row<?= (int) $m['clear_semaine'] ? ' is-cleared' : '' ?>"><td></td><td colspan="10" class="muted small"><?= e($m['notes']) ?></td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$members): ?><p class="empty">Aucun membre ne correspond à ces filtres.</p><?php endif; ?>
<?php page_footer();
