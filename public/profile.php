<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
$isOfficer = (int) $me['is_officer'] === 1;

// Un officier peut éditer n'importe quel membre via ?id=, les autres seulement eux-mêmes.
$id = (int) ($_GET['id'] ?? $me['id']);
if ($id !== (int) $me['id'] && !$isOfficer) {
    redirect('profile.php');
}
$m = member_by_id($id);
if (!$m) {
    flash('error', 'Membre introuvable.');
    redirect('members.php');
}
$self = (int) $m['id'] === (int) $me['id'];
$selfUrl = 'profile.php' . ($self ? '' : '?id=' . (int) $m['id']);
$errors = [];

if (is_post() && ($_POST['form'] ?? '') === 'delete' && $isOfficer && !$self) {
    q('DELETE FROM members WHERE id = ?', [$m['id']]);
    flash('ok', $m['pseudo'] . ' a été retiré de la légion.');
    redirect('members.php');
}

if (is_post() && ($_POST['form'] ?? '') === 'password' && $self) {
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $m['password_hash'])) $errors[] = 'Mot de passe actuel incorrect.';
    elseif ($err = validate_password($new)) $errors[] = $err;
    else {
        q('UPDATE members SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), time(), $m['id']]);
        login_member(member_by_id((int) $m['id'])); // garde cette session, invalide les autres
        flash('ok', 'Mot de passe changé.');
        redirect($selfUrl);
    }
}

// Lier le personnage en jeu et relire sa fiche officielle (GS, CP, classe)
if (is_post() && ($_POST['form'] ?? '') === 'sync' && aion_enabled()) {
    $perso = in_str('perso', 40);
    if ($perso !== '' && !preg_match('/^[\p{L}\p{N}_\-]{2,20}$/u', $perso)) {
        flash('error', 'Nom de personnage invalide : 2 à 20 lettres ou chiffres.');
        redirect($selfUrl);
    }
    $persoValue = ($perso === '' || mb_strtolower($perso) === mb_strtolower($m['pseudo'])) ? null : $perso;
    if ($persoValue === $m['perso'] && (int) $m['sync_tried_at'] > time() - 20) {
        flash('warn', 'Fiche relue il y a quelques secondes. Réessaie dans un instant.');
        redirect($selfUrl);
    }
    if ($persoValue !== $m['perso']) {
        q('UPDATE members SET perso = ?, aion_char_id = NULL, updated_at = ? WHERE id = ?', [$persoValue, time(), $m['id']]);
    }
    $r = aion_sync_member(member_by_id((int) $m['id']));
    flash(match ($r['status']) { 'ok' => 'ok', 'not_found' => 'warn', default => 'error' }, ($r['status'] === 'ok' ? 'Fiche officielle lue. ' : '') . $r['message']);
    if (!empty($r['candidates'])) {
        $_SESSION['aion_candidates'][(int) $m['id']] = $r['candidates'];
    }
    redirect($selfUrl);
}

// GS, CP et classe lus en jeu : plus modifiables à la main tant que la fiche officielle est liée
$synced = aion_enabled() && ($m['sync_status'] ?? '') === 'ok';

if (is_post() && ($_POST['form'] ?? '') === 'profile') {
    $cpRaw = in_str('cp', 20);
    $new = [
        'classe'          => in_choice('classe', opts('classe', $m['classe']), 'Autre'),
        'role'            => in_choice('role', opts('role', $m['role'])),
        'gs'              => in_int('gs', 0, 99999),
        'cp'              => $cpRaw === '' ? null : parse_cp($cpRaw),
        'connaissance'    => in_choice('connaissance', opts('connaissance', $m['connaissance'])),
        'meilleure_etape' => in_choice('meilleure_etape', opts('etape', $m['meilleure_etape'])),
        'objectif'        => in_choice('objectif', opts('objectif', $m['objectif'])),
        'jours'           => implode(',', array_values(array_intersect(DAYS, (array) ($_POST['jours'] ?? [])))),
        'creneau'         => in_str('creneau', 120) ?: null,
        'helper'          => in_bool('helper'),
        'inscription'     => in_choice('inscription', INSCRIPTION, 'À confirmer'),
        'clear_semaine'   => in_bool('clear_semaine'),
        'notes'           => in_str('notes', 600) ?: null,
    ];
    if ($synced) {
        unset($new['classe'], $new['gs'], $new['cp']);
    } elseif ($cpRaw !== '' && $new['cp'] === null) {
        $errors[] = 'CP non reconnu : écris par exemple 174k, 190,5k ou 190500.';
    }

    if ($isOfficer) {
        $new['shot_caller'] = in_bool('shot_caller');
        $new['verifie'] = in_bool('verifie');
        if (!$self) $new['is_officer'] = in_bool('is_officer'); // pas de risque de se retirer ses propres droits
    } else {
        // Comme la colonne « Vérifié » de l'Excel : changer ses stats demande une nouvelle vérification.
        foreach (['classe', 'role', 'gs', 'cp', 'connaissance', 'meilleure_etape'] as $k) {
            if (array_key_exists($k, $new) && (string) $new[$k] !== (string) $m[$k]) {
                $new['verifie'] = 0;
                break;
            }
        }
    }

    if (!$errors) {
        $new['updated_at'] = time();
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($new)));
        q("UPDATE members SET $set WHERE id = ?", [...array_values($new), $m['id']]);
        flash('ok', $self ? 'Profil enregistré.' : 'Profil de ' . $m['pseudo'] . ' enregistré.');
        redirect($selfUrl);
    }
    $m = array_merge($m, $new);
}

page_header($self ? 'Mon profil' : $m['pseudo'], $self ? 'profile' : 'members');
?>
<?php if (!$self): ?><p class="back"><a href="members.php">Membres</a></p><?php endif; ?>
<div class="profile-head">
  <?= class_icon($m['classe'], 64) ?>
  <div>
    <h1><?= e($m['pseudo']) ?></h1>
    <p class="meta"><span><?= e($m['classe']) ?></span><?= $m['role'] ? '<span>' . e($m['role']) . '</span>' : '' ?>
      <span><?= (int) $m['verifie'] ? 'Infos vérifiées par un officier' : 'Infos pas encore vérifiées' ?></span></p>
  </div>
</div>
<?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>

<?php if (aion_enabled()):
    $cands = $_SESSION['aion_candidates'][(int) $m['id']] ?? [];
    unset($_SESSION['aion_candidates'][(int) $m['id']]); ?>
  <section class="panel game-link">
    <h2>Personnage en jeu</h2>
    <?php if ($synced): ?>
      <p class="meta game-line"><span><strong><?= e(aion_char_name($m)) ?></strong></span><span><?= e($m['classe']) ?> niveau <?= (int) $m['aion_level'] ?></span>
        <span>Serveur <?= e(cfg('aion_server_name')) ?></span><span>Légion <?= e($m['aion_legion'] ?: 'aucune') ?></span></p>
      <p class="game-stats"><span>GS <b><?= e(fmt_gs($m['gs'])) ?></b></span><span>CP <b><?= e(fmt_cp($m['cp'])) ?></b></span></p>
      <p class="muted small">Lu sur le site officiel d'AION 2 <?= e(fmt_ago($m['synced_at'])) ?>, relu automatiquement toutes les <?= (int) cfg('aion_refresh_hours') ?> h.</p>
      <?php if (aion_legion_mismatch($m)): ?><p class="flash flash-warn">En jeu, ce personnage n'est pas dans la légion <?= e(cfg('aion_legion')) ?>.</p><?php endif; ?>
    <?php elseif (($m['sync_status'] ?? '') === 'not_found'): ?>
      <p>Aucun personnage « <?= e(aion_char_name($m)) ?> » trouvé sur <?= e(cfg('aion_server_name')) ?>. Indique le nom exact de ton personnage en jeu.</p>
    <?php elseif (($m['sync_status'] ?? '') === 'error'): ?>
      <p>La dernière lecture a échoué : <?= e($m['sync_message']) ?> Nouvel essai automatique plus tard.</p>
    <?php else: ?>
      <p class="muted">Ton GS et ton CP seront lus sur le site officiel d'AION 2 à partir du nom de ton personnage.</p>
    <?php endif; ?>
    <?php if ($cands): ?>
      <p>Personnages proches sur <?= e(cfg('aion_server_name')) ?> :</p>
      <ul class="cands">
        <?php foreach ($cands as $c): ?>
          <li><form method="post" action="<?= e($selfUrl) ?>"><?= csrf_field() ?><input type="hidden" name="form" value="sync"><input type="hidden" name="perso" value="<?= e($c['name']) ?>">
            <button class="mini" type="submit">C'est moi</button></form><span><?= e($c['name']) ?></span><span class="muted small">niveau <?= (int) $c['level'] ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="post" action="<?= e($selfUrl) ?>" class="game-form">
      <?= csrf_field() ?><input type="hidden" name="form" value="sync">
      <label for="f-perso">Nom du personnage sur <?= e(cfg('aion_server_name')) ?></label>
      <div class="inline-row">
        <input id="f-perso" name="perso" value="<?= e(aion_char_name($m)) ?>" maxlength="20" autocomplete="off">
        <button class="btn btn-ghost" type="submit"><?= $synced ? 'Relire maintenant' : 'Lier mon personnage' ?></button>
      </div>
    </form>
  </section>
<?php endif; ?>

<form method="post" class="form" action="<?= e($selfUrl) ?>">
  <?= csrf_field() ?><input type="hidden" name="form" value="profile">
  <?php if ($synced): ?>
    <div class="field"><p class="field-label">Classe <span class="opt">lue en jeu</span></p><p class="locked"><?= class_icon($m['classe'], 40) ?><span><?= e($m['classe']) ?></span></p></div>
  <?php else: ?>
    <fieldset class="field"><legend>Classe</legend><?= class_picker('classe', $m['classe']) ?></fieldset>
  <?php endif; ?>
  <fieldset class="field"><legend>Rôle</legend><?= chip_group('role', opts('role', $m['role']), $m['role']) ?></fieldset>
  <div class="field-row">
    <?php if ($synced): ?>
      <div class="field"><label for="f-gs">GS <span class="opt">lu en jeu</span></label><input id="f-gs" value="<?= e(fmt_gs($m['gs'])) ?>" readonly></div>
      <div class="field"><label for="f-cp">CP <span class="opt">lu en jeu</span></label><input id="f-cp" value="<?= e(fmt_cp($m['cp'])) ?>" readonly></div>
    <?php else: ?>
      <div class="field"><label for="f-gs">GS</label><input id="f-gs" name="gs" inputmode="numeric" value="<?= e($m['gs']) ?>" placeholder="2984"></div>
      <div class="field"><label for="f-cp">CP</label><input id="f-cp" name="cp" value="<?= e($m['cp'] !== null ? fmt_cp($m['cp']) : '') ?>" placeholder="174k"></div>
    <?php endif; ?>
    <div class="field"><label for="f-connaissance">Connaissance du raid</label><?= select_field('connaissance', opts('connaissance', $m['connaissance']), $m['connaissance'], '—') ?></div>
  </div>
  <div class="field-row">
    <div class="field"><label for="f-meilleure_etape">Meilleure étape</label><?= select_field('meilleure_etape', opts('etape', $m['meilleure_etape']), $m['meilleure_etape'], '—') ?></div>
    <div class="field"><label for="f-objectif">Objectif</label><?= select_field('objectif', opts('objectif', $m['objectif']), $m['objectif'], '—') ?></div>
  </div>
  <fieldset class="field"><legend>Jours dispo</legend><?= chip_group('jours', DAYS, null, true, member_days($m)) ?></fieldset>
  <div class="field"><label for="f-creneau">Créneau horaire</label><input id="f-creneau" name="creneau" maxlength="120" value="<?= e($m['creneau']) ?>" placeholder="21h - 00h, week-end 15h-18h"></div>
  <fieldset class="field"><legend>Inscription cette semaine</legend><?= chip_group('inscription', INSCRIPTION, $m['inscription']) ?></fieldset>
  <div class="field checks">
    <label class="check"><input type="checkbox" name="helper" value="1"<?= (int) $m['helper'] ? ' checked' : '' ?>> Helper : je peux aider des joueurs moins avancés</label>
    <label class="check"><input type="checkbox" name="clear_semaine" value="1"<?= (int) $m['clear_semaine'] ? ' checked' : '' ?>> J'ai déjà clear cette semaine</label>
    <?php if ($isOfficer): ?>
      <label class="check"><input type="checkbox" name="shot_caller" value="1"<?= (int) $m['shot_caller'] ? ' checked' : '' ?>> Shot caller</label>
      <label class="check"><input type="checkbox" name="verifie" value="1"<?= (int) $m['verifie'] ? ' checked' : '' ?>> Infos vérifiées</label>
      <?php if (!$self): ?><label class="check"><input type="checkbox" name="is_officer" value="1"<?= (int) $m['is_officer'] ? ' checked' : '' ?>> Officier</label><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="field"><label for="f-notes">Notes / contraintes</label><textarea id="f-notes" name="notes" rows="3" maxlength="600"><?= e($m['notes']) ?></textarea></div>
  <?php if (!$isOfficer): ?><p class="hint">Changer ton rôle, ta connaissance ou ta meilleure étape<?= $synced ? '' : ', ta classe, ton GS ou ton CP' ?> remet tes infos en attente de vérification par un officier.</p><?php endif; ?>
  <p><button class="btn btn-gold" type="submit">Enregistrer le profil</button></p>
</form>

<?php if ($self): ?>
  <form method="post" class="form form-narrow">
    <?= csrf_field() ?><input type="hidden" name="form" value="password">
    <h2>Changer de mot de passe</h2>
    <div class="field"><label for="f-cur">Mot de passe actuel</label><input id="f-cur" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label for="f-new">Nouveau mot de passe</label><input id="f-new" type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
    <p><button class="btn btn-ghost" type="submit">Changer le mot de passe</button></p>
  </form>
<?php elseif ($isOfficer): ?>
  <form method="post" class="form form-narrow" action="<?= e($selfUrl) ?>" data-confirm="Retirer <?= e($m['pseudo']) ?> de la légion ? Son profil et ses inscriptions seront supprimés.">
    <?= csrf_field() ?><input type="hidden" name="form" value="delete">
    <p><button class="btn btn-danger" type="submit">Retirer de la légion</button></p>
  </form>
<?php endif; ?>
<?php page_footer();
