<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_officer();

if (is_post()) {
    switch ($_POST['form'] ?? '') {
        case 'weekly_reset':
            $n = q('UPDATE members SET clear_semaine = 0, updated_at = ? WHERE clear_semaine = 1', [time()])->rowCount();
            flash('ok', "Reset hebdomadaire fait : $n joueur" . ($n > 1 ? 's' : '') . ' remis à « pas encore clear ».');
            break;
        case 'aion_sync':
        case 'aion_sync_all':
            @set_time_limit(60);
            $sum = aion_sync_batch(200, 20.0, $_POST['form'] === 'aion_sync_all');
            $total = $sum['ok'] + $sum['not_found'] + $sum['error'];
            flash($sum['error'] ? 'warn' : 'ok', $total === 0 ? 'Toutes les fiches sont déjà à jour.'
                : "$total fiche" . ($total > 1 ? 's' : '') . " relue" . ($total > 1 ? 's' : '') . " : {$sum['ok']} à jour, {$sum['not_found']} introuvable" . ($sum['not_found'] > 1 ? 's' : '') . ", {$sum['error']} erreur" . ($sum['error'] > 1 ? 's' : '') . '.'
                . ($sum['remaining'] ? " Il en reste {$sum['remaining']} : relance pour continuer." : ''));
            break;
        case 'code':
            $m = member_by_id((int) ($_POST['member_id'] ?? 0));
            if ($m) {
                $code = issue_activation_code((int) $m['id']);
                // Affiché une seule fois : seul le hash est stocké.
                $_SESSION['last_code'] = ['pseudo' => $m['pseudo'], 'code' => $code];
            }
            break;
    }
    redirect('admin.php');
}

$lastCode = $_SESSION['last_code'] ?? null;
unset($_SESSION['last_code']);
$members = q('SELECT id, pseudo, classe, password_hash IS NOT NULL AS active, activation_expires FROM members ORDER BY active, pseudo COLLATE NOCASE')->fetchAll();
$stats = legion_stats();
$aion = aion_stats();
$backups = backup_status();
$aionIssues = q("SELECT id, pseudo, perso, classe, sync_status, sync_message FROM members WHERE sync_status IN ('not_found', 'error') ORDER BY pseudo COLLATE NOCASE")->fetchAll();

page_header('Officiers', 'admin');
admin_tabs('admin');
?>
<h1>Espace officiers</h1>

<?php if ($lastCode): ?>
  <section class="code-box" role="status">
    <p>Code d'activation pour <strong><?= e($lastCode['pseudo']) ?></strong>, valable 7 jours :</p>
    <p class="code"><?= e($lastCode['code']) ?></p>
    <p class="muted small">Envoie-le en message privé avec le lien de la page « Activer mon profil ». Il ne sera plus affiché ensuite.</p>
  </section>
<?php endif; ?>

<section class="panel">
    <h2>Reset hebdomadaire</h2>
    <p><?= $stats['cleared'] ?> joueur<?= $stats['cleared'] > 1 ? 's ont' : ' a' ?> clear cette semaine.
      <?php if (weekly_reset_enabled()): ?>
        Tout le monde repasse automatiquement à « pas encore clear » chaque semaine. Prochain reset : <?= e(fmt_dt(weekly_reset_next())) ?>.
      <?php else: ?>
        Au reset du jeu, remets tout le monde à « pas encore clear » pour qu'ils puissent rejoindre les salles.
      <?php endif; ?></p>
    <?php if (weekly_reset_enabled() && kv_get('weekly_reset_info') !== ''): ?><p class="muted small">Dernier reset automatique : <?= e(kv_get('weekly_reset_info')) ?>.</p><?php endif; ?>
    <form method="post" data-confirm="Remettre « clear cette semaine » à non pour toute la légion ?">
      <?= csrf_field() ?><input type="hidden" name="form" value="weekly_reset">
      <button class="btn <?= weekly_reset_enabled() ? 'btn-ghost' : 'btn-gold' ?>" type="submit">Faire le reset maintenant</button>
    </form>
</section>

<?php if ((int) cfg('backup_every_hours') > 0): ?>
<section class="panel">
  <h2>Sauvegardes automatiques</h2>
  <p>Toutes les <?= (int) cfg('backup_every_hours') ?> h, dans <code>data/backups/</code> sur le serveur (hors du site : à récupérer par SFTP).
    <?= $backups['count'] ?> sauvegarde<?= $backups['count'] > 1 ? 's' : '' ?> conservée<?= $backups['count'] > 1 ? 's' : '' ?> sur <?= (int) cfg('backup_keep') ?>,
    dernière <?= e(fmt_ago($backups['last'])) ?>.</p>
  <?php if ($backups['error']): ?><p class="flash flash-error">Dernier échec : <?= e($backups['error']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if (aion_enabled()): ?>
<section class="panel">
  <h2>GS et CP lus en jeu</h2>
  <p>Les fiches sont lues sur le site officiel d'AION 2 (serveur <?= e(cfg('aion_server_name')) ?>) et relues toutes les <?= (int) cfg('aion_refresh_hours') ?> h, en arrière-plan.
    <?= $aion['ok'] ?> à jour, <?= $aion['not_found'] ?> introuvable<?= $aion['not_found'] > 1 ? 's' : '' ?>, <?= $aion['error'] ?> en erreur, <?= $aion['never'] ?> jamais lue<?= $aion['never'] > 1 ? 's' : '' ?>.
    Dernière lecture : <?= e(fmt_ago($aion['last'])) ?>.</p>
  <div class="manage-row">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="form" value="aion_sync"><button class="btn btn-gold" type="submit">Relire les fiches à mettre à jour</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="form" value="aion_sync_all"><button class="btn btn-ghost" type="submit">Tout relire</button></form>
  </div>
  <?php if ($aionIssues): ?>
    <ul class="access-list">
      <?php foreach ($aionIssues as $m): ?>
        <li><?= class_icon($m['classe'], 26) ?><span class="access-name"><a href="profile.php?id=<?= (int) $m['id'] ?>"><?= e($m['pseudo']) ?></a></span>
          <span class="muted small"><?= e($m['sync_message']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Accès des membres</h2>
  <p class="muted">Les profils importés de l'Excel n'ont pas encore de mot de passe. Génère un code pour qu'un joueur active son profil, ou pour réinitialiser l'accès d'un joueur qui a oublié son mot de passe.</p>
  <ul class="access-list">
    <?php foreach ($members as $m): ?>
      <li>
        <?= class_icon($m['classe'], 26) ?>
        <span class="access-name"><a href="profile.php?id=<?= (int) $m['id'] ?>"><?= e($m['pseudo']) ?></a></span>
        <span class="muted small"><?= ($m['active'] ? 'Compte actif' : 'Pas encore activé')
            . (($m['activation_expires'] && $m['activation_expires'] > time()) ? ', code en attente jusqu\'au ' . e(fmt_dt($m['activation_expires'])) : '') ?></span>
        <form method="post" class="inline"<?= $m['active'] ? ' data-confirm="Générer un code de réinitialisation pour ' . e($m['pseudo']) . ' ?"' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="form" value="code"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
          <button class="mini" type="submit"><?= $m['active'] ? 'Réinitialiser l\'accès' : 'Générer un code' ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php page_footer();
