<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_login();
if (!can_create_room($me)) {
    flash('error', 'Seuls les officiers peuvent créer une salle.');
    redirect('index.php');
}

$windows = cfg('join_windows');
$errors = [];
$v = [
    'title' => '', 'etape' => opts('etape')[0] ?? null, 'objectif' => opts('objectif')[0] ?? null, 'starts_at' => '',
    'window' => (string) cfg('default_join_window'), 'capacity' => (int) cfg('default_capacity'),
    'reserve_capacity' => (int) cfg('default_reserve'), 'min_gs' => '', 'allow_cleared' => 0, 'join_self' => 1, 'notes' => '',
];

if (is_post()) {
    $v = [
        'title'            => in_str('title', 80),
        'etape'            => in_choice('etape', opts('etape')),
        'objectif'         => in_choice('objectif', opts('objectif')),
        'starts_at'        => in_str('starts_at', 20),
        'window'           => in_str('window', 10),
        'capacity'         => in_int('capacity', 2, 24) ?? (int) cfg('default_capacity'),
        'reserve_capacity' => in_int('reserve_capacity', 0, 30) ?? 0,
        'min_gs'           => in_int('min_gs', 0, 99999),
        'allow_cleared'    => in_bool('allow_cleared'),
        'join_self'        => in_bool('join_self'),
        'notes'            => in_str('notes', 600),
    ];
    if ($v['title'] === '') $errors[] = 'Donne un nom à la salle.';

    $startsAt = null;
    if ($v['starts_at'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v['starts_at'], new DateTimeZone(cfg('timezone')));
        if (!$d) $errors[] = "L'heure de départ n'est pas valide.";
        else $startsAt = $d->getTimestamp();
    }

    $joinUntil = null;
    if ($v['window'] === 'start') {
        if (!$startsAt || $startsAt <= time()) $errors[] = 'Pour fermer les inscriptions au départ, indique une heure de départ à venir.';
        else $joinUntil = $startsAt;
    } elseif (isset($windows[(int) $v['window']])) {
        $joinUntil = time() + (int) $v['window'] * 60;
    } else {
        $errors[] = 'Choisis une durée d\'inscription.';
    }

    if (!$errors) {
        $slug = room_create([
            'title' => $v['title'], 'etape' => $v['etape'], 'objectif' => $v['objectif'], 'starts_at' => $startsAt,
            'join_until' => $joinUntil, 'capacity' => $v['capacity'], 'reserve_capacity' => $v['reserve_capacity'],
            'min_gs' => $v['min_gs'], 'allow_cleared' => $v['allow_cleared'], 'notes' => $v['notes'] ?: null,
        ], $me, (bool) $v['join_self']);
        flash('ok', 'Salle créée. Partage le lien sur Discord pour que la légion la rejoigne.');
        redirect('room.php?r=' . rawurlencode($slug));
    }
}

$windowOptions = [];
foreach ($windows as $min => $label) $windowOptions[(string) $min] = $label;
$windowOptions['start'] = "Jusqu'au départ";

page_header('Créer une salle', 'rooms');
?>
<p class="back"><a href="index.php">Toutes les salles</a></p>
<h1>Créer une salle</h1>
<?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>

<form method="post" class="form">
  <?= csrf_field() ?>
  <div class="field">
    <label for="f-title">Nom de la salle</label>
    <input id="f-title" name="title" required maxlength="80" value="<?= e($v['title']) ?>" placeholder="Ludra mercredi soir">
  </div>
  <div class="field-row">
    <div class="field"><label for="f-etape">Étape</label><?= select_field('etape', opts('etape'), $v['etape'], '—') ?></div>
    <div class="field"><label for="f-objectif">Objectif</label><?= select_field('objectif', opts('objectif'), $v['objectif'], '—') ?></div>
    <div class="field"><label for="f-starts">Départ du raid <span class="opt">facultatif</span></label>
      <input id="f-starts" type="datetime-local" name="starts_at" value="<?= e($v['starts_at']) ?>"></div>
  </div>
  <fieldset class="field">
    <legend>Les membres peuvent rejoindre pendant</legend>
    <?= chip_group('window', $windowOptions, $v['window']) ?>
    <p class="hint">Après ce délai, la salle reste ouverte pour les départs et les remplacements. Tu pourras prolonger ou clore plus tôt.</p>
  </fieldset>
  <div class="field-row">
    <div class="field"><label for="f-cap">Places dans le groupe</label><input id="f-cap" type="number" name="capacity" min="2" max="24" value="<?= (int) $v['capacity'] ?>"></div>
    <div class="field"><label for="f-res">Places en réserve</label><input id="f-res" type="number" name="reserve_capacity" min="0" max="30" value="<?= (int) $v['reserve_capacity'] ?>"></div>
    <div class="field"><label for="f-gs">GS minimum <span class="opt">facultatif</span></label><input id="f-gs" type="number" name="min_gs" min="0" value="<?= e($v['min_gs']) ?>" placeholder="2900"></div>
  </div>
  <div class="field checks">
    <label class="check"><input type="checkbox" name="allow_cleared" value="1"<?= $v['allow_cleared'] ? ' checked' : '' ?>> Accepter les joueurs déjà clear cette semaine <span class="hint">(helpers)</span></label>
    <label class="check"><input type="checkbox" name="join_self" value="1"<?= $v['join_self'] ? ' checked' : '' ?>> Je rejoins la salle</label>
  </div>
  <div class="field">
    <label for="f-notes">Notes <span class="opt">facultatif</span></label>
    <textarea id="f-notes" name="notes" rows="3" maxlength="600" placeholder="Vocal Discord, consommables, stratégie…"><?= e($v['notes']) ?></textarea>
  </div>
  <p><button class="btn btn-gold" type="submit">Créer la salle</button></p>
</form>
<?php page_footer();
