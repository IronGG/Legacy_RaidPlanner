<?php
require __DIR__ . '/../src/bootstrap.php';
if (current_member()) redirect('index.php');

$error = null;
$pseudo = '';
if (is_post()) {
    $pseudo = in_str('pseudo', 40);
    if (rate_limited()) {
        $error = 'Trop de tentatives. Réessaie dans quelques minutes.';
    } elseif ($m = attempt_login($pseudo, (string) ($_POST['password'] ?? ''))) {
        login_member($m);
        redirect(after_login_url());
    } else {
        record_failed_attempt();
        $known = member_by_pseudo($pseudo);
        $error = ($known && !$known['password_hash'])
            ? "Ce profil n'est pas encore activé. Demande un code d'activation à un officier."
            : 'Pseudo ou mot de passe incorrect.';
    }
}
page_header('Connexion', 'login');
?>
<div class="auth">
  <h1>Connexion</h1>
  <?php if ($error): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <div class="field"><label for="f-pseudo">Pseudo en jeu</label><input id="f-pseudo" name="pseudo" required autocomplete="username" value="<?= e($pseudo) ?>"></div>
    <div class="field"><label for="f-pw">Mot de passe</label><input id="f-pw" type="password" name="password" required autocomplete="current-password"></div>
    <p><button class="btn btn-gold" type="submit">Se connecter</button></p>
  </form>
  <p class="muted">Tu es dans le recensement de la légion ? <a href="activate.php">Active ton profil</a> avec le code reçu d'un officier.<br>
  Nouveau dans la légion ? <a href="register.php">Inscris-toi</a>.</p>
</div>
<?php page_footer();
