<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_officer();

$list = array_key_exists($_GET['l'] ?? '', OPTION_LISTS) ? (string) $_GET['l'] : 'etape';

if (is_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $msg = match ((string) ($_POST['do'] ?? '')) {
            'add'        => option_add($list),
            'save'       => option_save($list, $id),
            'up', 'down' => option_move($list, $id, (string) $_POST['do']),
            'delete'     => option_delete($list, $id),
            default      => throw new UserError('Action inconnue.'),
        };
        flash('ok', $msg);
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('lists.php?l=' . rawurlencode($list));
}

$rows = all_options()[$list];
$usage = option_usage($list);
$icons = $list === 'classe' ? available_icons() : [];
$action = 'lists.php?l=' . e(rawurlencode($list));

function icon_select(array $icons, ?string $current): string
{
    $h = '<select name="icon" aria-label="Icône">';
    foreach ($icons as $i) {
        $label = str_starts_with($i, 'custom/') ? 'Image envoyée (' . substr($i, 7, 6) . '…)' : pathinfo($i, PATHINFO_FILENAME);
        $h .= '<option value="' . e($i) . '"' . ($i === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h . '</select>';
}

page_header('Listes du site', 'admin');
admin_tabs('lists');
?>
<h1>Listes du site</h1>
<p class="lede">Les choix proposés dans les profils et à la création des salles. Renommer une option met à jour les profils et les salles qui l'utilisent. Une option masquée n'est plus proposée, mais reste affichée là où elle sert déjà.</p>

<nav class="tabs tabs-sub" aria-label="Listes">
  <?php foreach (OPTION_LISTS as $key => $def): ?>
    <a href="lists.php?l=<?= e($key) ?>"<?= $key === $list ? ' aria-current="page"' : '' ?>><?= e($def['label']) ?> <span class="muted"><?= count(all_options()[$key]) ?></span></a>
  <?php endforeach; ?>
</nav>

<section class="panel opt-panel list-<?= e($list) ?>">
  <?php foreach ($rows as $i => $o): $used = $usage[$o['value']] ?? 0; ?>
    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="opt-row<?= (int) $o['active'] ? '' : ' is-hidden' ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <?php if ($list === 'classe'): ?><?= class_icon($o['value'], 34) ?><?php endif; ?>
      <?php if ($list === 'role'): ?><input type="color" name="color" value="<?= e($o['color'] ?: '#9aa2c0') ?>" aria-label="Couleur de <?= e($o['value']) ?>"><?php endif; ?>
      <input class="opt-name" name="value" value="<?= e($o['value']) ?>" maxlength="40" required aria-label="Nom">
      <?php if ($list === 'classe'): ?>
        <span class="opt-icon"><?= icon_select($icons, $o['icon']) ?>
          <label class="file-pick"><input type="file" name="icon_upload" accept="image/png,image/jpeg,image/webp,image/gif"><span>Envoyer une image</span></label></span>
      <?php endif; ?>
      <?php if ($list === 'role'): ?>
        <label class="check"><input type="checkbox" name="required" value="1"<?= (int) $o['required'] ? ' checked' : '' ?>> Alerte si absent d'un groupe</label>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $o['active'] ? ' checked' : '' ?>> Proposée</label>
      <span class="opt-usage muted small"><?= $used ? 'Utilisée ' . $used . ' fois' : 'Pas utilisée' ?></span>
      <span class="opt-actions">
        <button class="mini" type="submit" name="do" value="save">Enregistrer</button>
        <button class="mini" type="submit" name="do" value="up"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Monter <?= e($o['value']) ?>">↑</button>
        <button class="mini" type="submit" name="do" value="down"<?= $i === count($rows) - 1 ? ' disabled' : '' ?> aria-label="Descendre <?= e($o['value']) ?>">↓</button>
        <button class="mini mini-danger" type="submit" name="do" value="delete"<?= $used ? ' disabled title="Utilisée : masque-la plutôt"' : '' ?> data-confirm="Supprimer « <?= e($o['value']) ?> » ?">Supprimer</button>
      </span>
    </form>
  <?php endforeach; ?>

  <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="opt-row opt-add">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <?php if ($list === 'role'): ?><input type="color" name="color" value="#9aa2c0" aria-label="Couleur"><?php endif; ?>
    <input class="opt-name" name="value" maxlength="40" required placeholder="Nouvelle option" aria-label="Nouvelle option">
    <?php if ($list === 'classe'): ?>
      <span class="opt-icon"><?= icon_select($icons, FALLBACK_ICON) ?>
        <label class="file-pick"><input type="file" name="icon_upload" accept="image/png,image/jpeg,image/webp,image/gif"><span>Envoyer une image</span></label></span>
    <?php endif; ?>
    <?php if ($list === 'role'): ?><label class="check"><input type="checkbox" name="required" value="1"> Alerte si absent d'un groupe</label><?php endif; ?>
    <button class="btn btn-gold" type="submit">Ajouter</button>
  </form>
  <?php if ($list === 'classe'): ?>
    <p class="hint">Icônes : PNG, JPG, WebP ou GIF, 300 Ko maximum, idéalement carrées (64×64 ou plus).</p>
  <?php endif; ?>
</section>
<?php page_footer();
