<?php
require __DIR__ . '/../src/bootstrap.php';
if (current_member()) redirect('profile.php');

$errors = [];
$v = ['pseudo' => '', 'classe' => 'Autre', 'role' => null];
if (is_post()) {
    $v = ['pseudo' => in_str('pseudo', 40), 'classe' => in_choice('classe', opts('classe'), 'Autre'), 'role' => in_choice('role', opts('role'))];
    $pw = (string) ($_POST['password'] ?? '');
    if (rate_limited()) $errors[] = 'Trop de tentatives. Réessaie dans quelques minutes.';
    record_failed_attempt(); // chaque tentative compte : limite aussi la création de comptes en rafale
    if ($err = validate_pseudo($v['pseudo'])) $errors[] = $err;
    if ($err = validate_password($pw)) $errors[] = $err;
    if (!$v['role']) $errors[] = 'Choisis ton rôle.';
    if (!$errors && ($existing = member_by_pseudo($v['pseudo']))) {
        $errors[] = $existing['password_hash']
            ? 'Ce pseudo a déjà un compte. Connecte-toi, ou demande à un officier de réinitialiser ton accès.'
            : "Ce pseudo est déjà dans le recensement. Demande un code d'activation à un officier puis passe par « Activer mon profil ».";
    }
    if (!$errors) {
        $now = time();
        q('INSERT INTO members (pseudo, password_hash, classe, role, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [$v['pseudo'], password_hash($pw, PASSWORD_DEFAULT), $v['classe'], $v['role'], $now, $now]);
        $created = member_by_pseudo($v['pseudo']);
        login_member($created);
        flash('ok', 'Bienvenue ! Complète ton profil pour que les chefs de salle te connaissent.');
        if (aion_enabled()) {
            $r = aion_sync_member($created);
            if ($r['status'] !== 'ok') flash('warn', $r['message'] . ' Corrige le nom de ton personnage plus bas.');
            if (!empty($r['candidates'])) $_SESSION['aion_candidates'][(int) $created['id']] = $r['candidates'];
        }
        redirect('profile.php');
    }
}
page_header('Inscription', 'register');
?>
<div class="auth auth-wide">
  <h1>Rejoindre la légion</h1>
  <?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <div class="field-row">
      <div class="field"><label for="f-pseudo">Pseudo en jeu</label><input id="f-pseudo" name="pseudo" required maxlength="20" autocomplete="username" value="<?= e($v['pseudo']) ?>"></div>
      <div class="field"><label for="f-pw">Mot de passe <span class="opt">8 caractères min.</span></label><input id="f-pw" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
    </div>
    <fieldset class="field"><legend>Classe</legend><?= class_picker('classe', $v['classe']) ?></fieldset>
    <fieldset class="field"><legend>Rôle</legend><?= chip_group('role', opts('role'), $v['role']) ?></fieldset>
    <p><button class="btn btn-gold" type="submit">Créer mon compte</button></p>
  </form>
</div>
<?php page_footer();
