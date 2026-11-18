<?php
declare(strict_types=1);

/*
 * Formulaire de salle, partagé entre la création (room_new.php) et la modification (room_edit.php).
 * $room = null : création ; sinon la salle modifiée (les joueurs déjà dedans restent).
 */

function room_form_values(?array $room): array
{
    if (!$room) {
        return [
            'title' => '', 'etape' => opts('etape')[0] ?? null, 'objectif' => opts('objectif')[0] ?? null, 'starts_at' => '',
            'window' => (string) cfg('default_join_window'), 'capacity' => (int) cfg('default_capacity'),
            'reserve_capacity' => (int) cfg('default_reserve'), 'min_gs' => '', 'allow_cleared' => 0, 'join_self' => 1, 'notes' => '',
        ];
    }
    return [
        'title' => $room['title'], 'etape' => $room['etape'], 'objectif' => $room['objectif'],
        'starts_at' => $room['starts_at'] ? local_dt((int) $room['starts_at'])->format('Y-m-d\TH:i') : '',
        'window' => 'keep', 'capacity' => (int) $room['capacity'], 'reserve_capacity' => (int) $room['reserve_capacity'],
        'min_gs' => $room['min_gs'] === null ? '' : (string) $room['min_gs'], 'allow_cleared' => (int) $room['allow_cleared'],
        'join_self' => 0, 'notes' => (string) $room['notes'],
    ];
}

/** Lit le formulaire envoyé. Renvoie [valeurs pour réafficher, erreurs, données prêtes pour la base]. */
function room_form_read(?array $room): array
{
    $v = [
        'title'            => in_str('title', 80),
        'etape'            => in_choice('etape', opts('etape', $room['etape'] ?? null)),
        'objectif'         => in_choice('objectif', opts('objectif', $room['objectif'] ?? null)),
        'starts_at'        => in_str('starts_at', 20),
        'window'           => in_str('window', 10),
        'capacity'         => in_int('capacity', 2, 24) ?? (int) ($room['capacity'] ?? cfg('default_capacity')),
        'reserve_capacity' => in_int('reserve_capacity', 0, 30) ?? 0,
        'min_gs'           => in_int('min_gs', 0, 99999),
        'allow_cleared'    => in_bool('allow_cleared'),
        'join_self'        => in_bool('join_self'),
        'notes'            => in_str('notes', 600),
    ];
    $errors = [];
    if ($v['title'] === '') $errors[] = 'Donne un nom à la salle.';

    $startsAt = null;
    if ($v['starts_at'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v['starts_at'], new DateTimeZone(cfg('timezone')));
        if (!$d) $errors[] = "L'heure de départ n'est pas valide.";
        else $startsAt = $d->getTimestamp();
    }

    $joinUntil = null; // null en modification = inscriptions inchangées
    $windows = cfg('join_windows');
    if ($v['window'] === 'keep' && $room) {
        $joinUntil = null;
    } elseif ($v['window'] === 'start') {
        if (!$startsAt || $startsAt <= time()) $errors[] = 'Pour fermer les inscriptions au départ, indique une heure de départ à venir.';
        else $joinUntil = $startsAt;
    } elseif (isset($windows[(int) $v['window']])) {
        $joinUntil = time() + (int) $v['window'] * 60;
    } else {
        $errors[] = "Choisis une durée d'inscription.";
    }

    $data = [
        'title' => $v['title'], 'etape' => $v['etape'], 'objectif' => $v['objectif'], 'starts_at' => $startsAt,
        'join_until' => $joinUntil, 'capacity' => $v['capacity'], 'reserve_capacity' => $v['reserve_capacity'],
        'min_gs' => $v['min_gs'], 'allow_cleared' => $v['allow_cleared'], 'notes' => $v['notes'] ?: null,
    ];
    return [$v, $errors, $data];
}

/** $counts : titulaires et réservistes actuels (modification), pour borner les places. */
function room_form_render(array $v, ?array $room, array $counts = ['main' => 0, 'reserve' => 0]): void
{
    $windowOptions = [];
    if ($room) {
        $windowOptions['keep'] = 'Ne pas changer';
    }
    foreach (cfg('join_windows') as $min => $label) $windowOptions[(string) $min] = $label;
    $windowOptions['start'] = "Jusqu'au départ";
    $minCap = max(2, (int) $counts['main']);
    $minRes = (int) $counts['reserve'];
    ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <div class="field">
    <label for="f-title">Nom de la salle</label>
    <input id="f-title" name="title" required maxlength="80" value="<?= e($v['title']) ?>" placeholder="Ludra mercredi soir">
  </div>
  <div class="field-row">
    <div class="field"><label for="f-etape">Étape</label><?= select_field('etape', opts('etape', $room['etape'] ?? null), $v['etape'], '—') ?></div>
    <div class="field"><label for="f-objectif">Objectif</label><?= select_field('objectif', opts('objectif', $room['objectif'] ?? null), $v['objectif'], '—') ?></div>
    <div class="field"><label for="f-starts">Départ du raid <span class="opt">facultatif</span></label>
      <input id="f-starts" type="datetime-local" name="starts_at" value="<?= e($v['starts_at']) ?>"></div>
  </div>
  <fieldset class="field">
    <legend><?= $room ? 'Inscriptions' : 'Les membres peuvent rejoindre pendant' ?></legend>
    <?= chip_group('window', $windowOptions, $v['window']) ?>
    <p class="hint"><?php if ($room): ?>
      <?= room_phase($room) === 'open'
          ? "Actuellement ouvertes jusqu'à " . e(fmt_when($room['join_until'])) . '.'
          : 'Actuellement closes depuis ' . e(fmt_when($room['join_until'])) . '.' ?>
      Une durée compte à partir de maintenant et rouvre les inscriptions si elles étaient closes.
    <?php else: ?>
      Après ce délai, la salle reste ouverte pour les départs et les remplacements. Tu pourras prolonger ou clore plus tôt.
    <?php endif; ?></p>
  </fieldset>
  <div class="field-row">
    <div class="field"><label for="f-cap">Places dans le groupe</label><input id="f-cap" type="number" name="capacity" min="<?= $minCap ?>" max="24" value="<?= (int) $v['capacity'] ?>"></div>
    <div class="field"><label for="f-res">Places en réserve</label><input id="f-res" type="number" name="reserve_capacity" min="<?= $minRes ?>" max="30" value="<?= (int) $v['reserve_capacity'] ?>"></div>
    <div class="field"><label for="f-gs">GS minimum <span class="opt">facultatif</span></label><input id="f-gs" type="number" name="min_gs" min="0" value="<?= e($v['min_gs']) ?>" placeholder="2900"></div>
  </div>
  <?php if ($room): ?>
    <p class="hint form-note">Personne n'est retiré de la salle. Le groupe compte <?= (int) $counts['main'] ?> joueur<?= $counts['main'] > 1 ? 's' : '' ?> et la réserve <?= (int) $counts['reserve'] ?> : il faut au moins autant de places.
      Le GS minimum et le refus des joueurs déjà clear ne s'appliquent qu'aux prochaines arrivées.</p>
  <?php endif; ?>
  <div class="field checks">
    <label class="check"><input type="checkbox" name="allow_cleared" value="1"<?= $v['allow_cleared'] ? ' checked' : '' ?>> Accepter les joueurs déjà clear cette semaine <span class="hint">(helpers)</span></label>
    <?php if (!$room): ?>
      <label class="check"><input type="checkbox" name="join_self" value="1"<?= $v['join_self'] ? ' checked' : '' ?>> Je rejoins la salle</label>
    <?php endif; ?>
  </div>
  <div class="field">
    <label for="f-notes">Notes <span class="opt">facultatif</span></label>
    <textarea id="f-notes" name="notes" rows="3" maxlength="600" placeholder="Vocal Discord, consommables, stratégie…"><?= e($v['notes']) ?></textarea>
  </div>
  <p class="form-actions">
    <button class="btn btn-gold" type="submit"><?= $room ? 'Enregistrer' : 'Créer la salle' ?></button>
    <?php if ($room): ?><a class="btn btn-ghost" href="room.php?r=<?= e(rawurlencode($room['slug'])) ?>">Annuler</a><?php endif; ?>
  </p>
</form>
    <?php
}
