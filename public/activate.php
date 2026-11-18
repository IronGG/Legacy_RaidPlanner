<?php
require __DIR__ . '/../src/bootstrap.php';
if (current_member()) redirect('profile.php');

$error = null;
$pseudo = in_str('pseudo', 40, $_GET);
if (is_post()) {
    $pseudo = in_str('pseudo', 40);
    $pw = (string) ($_POST['password'] ?? '');
    $m = member_by_pseudo($pseudo);
    if (rate_limited()) {
        $error = 'Trop de tentatives. Réessaie dans quelques minutes.';
    } elseif (!$m || !check_activation_code($m, (string) ($_POST['code'] ?? ''))) {
        record_failed_attempt();
        $error = "Code invalide ou expiré. Vérifie le pseudo et le code, ou demande-en un nouveau à un officier.";
    } elseif ($err = validate_password($pw)) {
        $error = $err;
    } else {
        q('UPDATE members SET password_hash = ?, activation_hash = NULL, activation_expires = NULL, updated_at = ? WHERE id = ?',
            [pw_hash($pw), time(), $m['id']]);
        login_member(member_by_id((int) $m['id']));
        flash('ok', 'Profil activé. Vérifie que tes infos sont à jour.');
        if (aion_enabled() && ($m['sync_status'] ?? '') !== 'ok') {
            $r = aion_sync_member(member_by_id((int) $m['id']));
            if ($r['status'] !== 'ok') flash('warn', $r['message'] . ' Corrige le nom de ton personnage plus bas.');
            if (!empty($r['candidates'])) $_SESSION['aion_candidates'][(int) $m['id']] = $r['candidates'];
        }
        redirect('profile.php');
    }
}
page_header('Activer mon profil');
?>
<div class="auth">
  <h1>Activer mon profil</h1>
  <p class="lede">Ton profil a été importé depuis le recensement de la légion. Un officier te donne un code à usage unique (valable 7 jours) : choisis ton mot de passe avec.</p>
  <?php if ($error): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <div class="field"><label for="f-pseudo">Pseudo en jeu</label><input id="f-pseudo" name="pseudo" required autocomplete="username" value="<?= e($pseudo) ?>"></div>
    <div class="field"><label for="f-code">Code d'activation</label><input id="f-code" name="code" required autocomplete="one-time-code" placeholder="ABCDE-12345" class="code-input"></div>
    <div class="field"><label for="f-pw">Nouveau mot de passe <span class="opt">8 caractères min.</span></label><input id="f-pw" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
    <p><button class="btn btn-gold" type="submit">Activer et me connecter</button></p>
  </form>
</div>
<?php page_footer();
