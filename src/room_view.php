<?php
declare(strict_types=1);

function phase_label(array $room): string
{
    return match (room_phase($room)) {
        'open'      => 'Inscriptions ouvertes',
        'late'      => 'Remplacements',
        'done'      => $room['outcome'] === 'clear' ? 'Clear réussi' : 'Terminée',
        'cancelled' => 'Annulée',
        default     => '',
    };
}

function room_meta(array $room): string
{
    $parts = [];
    if ($room['etape']) $parts[] = '<span>' . e($room['etape']) . '</span>';
    if ($room['objectif']) $parts[] = '<span>Objectif ' . e(mb_strtolower($room['objectif'])) . '</span>';
    if ($room['starts_at']) $parts[] = '<span>Départ ' . e(fmt_dt($room['starts_at'])) . '</span>';
    if ($room['min_gs']) $parts[] = '<span>GS ' . e(fmt_gs($room['min_gs'])) . ' min.</span>';
    $parts[] = '<span>Chef : ' . e($room['leader_pseudo'] ?? 'parti') . '</span>';
    return '<p class="meta">' . implode('', $parts) . '</p>';
}

function join_form(array $room, array $check, string $btnClass = 'btn-gold'): string
{
    return '<form method="post" action="room.php?r=' . e(rawurlencode($room['slug'])) . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="action" value="join"><button class="btn ' . e($btnClass) . '" type="submit">' . e($check['label']) . '</button></form>';
}

function render_room_list(array $rooms, array $me): void
{
    $active = array_filter($rooms, fn($r) => $r['status'] === 'active');
    $past = array_filter($rooms, fn($r) => $r['status'] !== 'active');
    if (!$active) {
        echo '<div class="empty"><p>Aucune salle ouverte pour le moment.</p>';
        if (can_create_room($me)) echo '<p><a class="btn btn-gold" href="room_new.php">Créer une salle</a></p>';
        echo '</div>';
    }
    foreach ($active as $room) {
        render_room_card($room, $me);
    }
    if ($past) {
        echo '<h2 class="section-title">Salles récentes</h2><div class="past-list">';
        foreach ($past as $room) {
            $c = slot_counts($room['members']);
            echo '<a class="past-row" href="room.php?r=' . e(rawurlencode($room['slug'])) . '"><span class="past-title">' . e($room['title']) . '</span>'
                . '<span class="muted">' . e($room['etape'] ?? '') . '</span><span class="muted">' . $c['main'] . '/' . (int) $room['capacity'] . '</span>'
                . '<span class="status status-' . e(room_phase($room)) . ($room['outcome'] === 'clear' ? ' status-clear' : '') . '">' . e(phase_label($room)) . '</span></a>';
        }
        echo '</div>';
    }
}

function render_room_card(array $room, array $me): void
{
    $phase = room_phase($room);
    $members = $room['members'];
    $counts = slot_counts($members);
    $slotRoles = $room['slot_roles'] ?? room_slot_roles($room);
    $occupied = occupied_positions($members);
    $mine = (bool) array_filter($members, fn($m) => (int) $m['id'] === (int) $me['id']);
    $check = join_check($room, $me, $members, $slotRoles);
    $url = 'room.php?r=' . rawurlencode($room['slug']);
    ?>
  <article class="room-card phase-<?= e($phase) ?><?= $mine ? ' is-mine' : '' ?>">
    <div class="rc-head">
      <h2><a href="<?= e($url) ?>"><?= e($room['title']) ?></a><?= $mine ? ' <span class="badge badge-aether">Tu y es</span>' : '' ?></h2>
      <?= room_meta($room) ?>
    </div>
    <ol class="pips" aria-label="<?= $counts['main'] ?> joueurs sur <?= (int) $room['capacity'] ?>">
      <?php for ($i = 0; $i < (int) $room['capacity']; $i++): $m = $occupied[$i] ?? null; $want = $slotRoles[$i] ?? null; ?>
        <?php if ($m): ?>
          <li class="pip filled"><?= class_icon($m['classe'], 30) ?><span class="sr"><?= e($m['pseudo']) ?></span></li>
        <?php else: ?>
          <li class="pip<?= $want ? ' pip-role ' . e(role_class($want)) : '' ?>" title="<?= e($want ? "Place libre : $want" : 'Place libre') ?>"><span class="sr"><?= e($want ? "Place libre : $want" : 'Place libre') ?></span></li>
        <?php endif; ?>
      <?php endfor; ?>
    </ol>
    <div class="rc-side">
      <div class="rc-stat">
        <p class="rc-count"><strong><?= $counts['main'] ?></strong>/<?= (int) $room['capacity'] ?>
          <?php if ($counts['reserve']): ?><span class="muted">+<?= $counts['reserve'] ?> en réserve</span><?php endif; ?></p>
        <?php if ($phase === 'open'): ?>
          <p class="timer" data-deadline="<?= (int) $room['join_until'] ?>">encore <b data-left><?= e(fmt_left((int) $room['join_until'] - time())) ?></b></p>
        <?php else: ?>
          <p class="timer timer-late">Remplacements</p>
        <?php endif; ?>
      </div>
      <?= $check['ok'] ? join_form($room, $check) : '<a class="btn btn-ghost" href="' . e($url) . '">Voir la salle</a>' ?>
    </div>
  </article>
    <?php
}

/** Tout le contenu dynamique d'une salle (rechargé par le navigateur à chaque changement). */
function render_room_live(array $room, array $me): void
{
    $phase = room_phase($room);
    $members = room_members_of([(int) $room['id']])[(int) $room['id']];
    $counts = slot_counts($members);
    $reserve = array_values(array_filter($members, fn($m) => $m['slot'] === 'reserve'));
    $slotRoles = room_slot_roles($room);
    $occupied = occupied_positions($members);
    $needs = role_needs($slotRoles);
    $mine = null;
    foreach ($members as $m) {
        if ((int) $m['id'] === (int) $me['id']) $mine = $m;
    }
    $check = join_check($room, $me, $members, $slotRoles);
    $manage = can_manage_room($room, $me) && $room['status'] === 'active';
    $roles = role_counts($members);
    $avg = avg_gs($members);
    $action = 'room.php?r=' . e(rawurlencode($room['slug']));
    ?>
  <header class="room-head">
    <div>
      <h1><?= e($room['title']) ?></h1>
      <?= room_meta($room) ?>
      <?php if ($room['notes']): ?><p class="room-notes"><?= nl2br(e($room['notes'])) ?></p><?php endif; ?>
    </div>
  </header>

  <?php if ($phase === 'open'): ?>
    <section class="phase phase-open" data-deadline="<?= (int) $room['join_until'] ?>" data-start="<?= (int) $room['join_opened_at'] ?>">
      <p><strong>Inscriptions ouvertes</strong> encore <b data-left><?= e(fmt_left((int) $room['join_until'] - time())) ?></b>
        <span class="muted">jusqu'à <?= e(fmt_when($room['join_until'])) ?></span></p>
      <div class="bar"><i data-bar></i></div>
    </section>
  <?php elseif ($phase === 'late'): ?>
    <section class="phase phase-late">
      <p><strong>Inscriptions closes depuis <?= e(fmt_when($room['join_until'])) ?></strong>
        La salle reste ouverte : si quelqu'un part, le premier de la réserve qui a le bon rôle monte, sinon n'importe qui du bon rôle peut prendre la place.</p>
    </section>
  <?php else: ?>
    <section class="phase phase-<?= e($phase) ?>">
      <p><strong><?= e(phase_label($room)) ?></strong> <?= $room['closed_at'] ? e(fmt_dt($room['closed_at'])) : '' ?></p>
    </section>
  <?php endif; ?>

  <section class="party" aria-label="Groupe">
    <div class="party-head">
      <h2>Groupe <span class="muted"><?= $counts['main'] ?>/<?= (int) $room['capacity'] ?></span></h2>
      <ul class="comp">
        <?php foreach ($roles as $role => $n): ?>
          <?php $need = $needs[$role] ?? 0; $missing = ($need > 0 && $n < $need) || ($n === 0 && in_array($role, required_roles(), true)); ?>
          <li class="<?= e(role_class($role)) ?><?= $missing ? ' missing' : '' ?>"><span><?= e($role) ?></span><b><?= $n ?><?= $need ? '<small>/' . $need . '</small>' : '' ?></b></li>
        <?php endforeach; ?>
        <li class="comp-gs"><span>GS moyen</span><b><?= e(fmt_gs($avg)) ?></b></li>
      </ul>
    </div>
    <?php if ($manage): ?>
      <p class="hint manage-hint">Glisse un joueur sur une place (ou clique sur lui puis sur la place) pour le déplacer ; sur une place occupée, les deux échangent.
        Clique sur une place libre pour choisir le rôle recherché.</p>
    <?php endif; ?>
    <ol class="slots">
      <?php for ($i = 0; $i < (int) $room['capacity']; $i++): $m = $occupied[$i] ?? null; $want = $slotRoles[$i]; ?>
        <?php if ($m): $off = $want !== null && $want !== $m['role']; ?>
          <li class="slot <?= e(role_class($m['role'])) ?><?= (int) $m['id'] === (int) $me['id'] ? ' is-me' : '' ?><?= $manage ? ' pickable' : '' ?>" data-pos="<?= $i ?>"<?= $manage ? ' data-member="' . (int) $m['id'] . '" draggable="true"' : '' ?>>
            <span class="slot-num" aria-hidden="true"><?= $i + 1 ?></span>
            <?= class_icon($m['classe'], 52) ?>
            <div class="slot-body">
              <p class="slot-name"><?= e($m['pseudo']) ?><?= (int) $m['id'] === (int) $room['leader_id'] ? ' <span class="badge badge-gold">Chef</span>' : '' ?></p>
              <p class="slot-sub"><span class="role-tag"><?= e($m['role'] ?? '—') ?></span> <span class="num"><?= e(fmt_gs($m['gs'])) ?></span></p>
              <p class="slot-badges"><?= $off ? '<span class="badge badge-danger" title="Cette place demande : ' . e($want) . '">Place ' . e($want) . '</span>' : '' ?><?= (int) $m['late'] ? '<span class="badge badge-aether">Remplaçant</span>' : '' ?><?= member_badges($m) ?></p>
            </div>
            <?php if ($manage && (int) $m['id'] !== (int) $me['id']): ?>
              <div class="slot-actions">
                <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="to" value="reserve"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>"><button class="mini" type="submit">En réserve</button></form>
                <form method="post" action="<?= $action ?>" data-confirm="Retirer <?= e($m['pseudo']) ?> de la salle ?"><?= csrf_field() ?><input type="hidden" name="action" value="kick"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>"><button class="mini mini-danger" type="submit">Retirer</button></form>
              </div>
            <?php endif; ?>
          </li>
        <?php else: ?>
          <li class="slot slot-empty<?= $want ? ' slot-wants ' . e(role_class($want)) : '' ?>" data-pos="<?= $i ?>">
            <span class="slot-num" aria-hidden="true"><?= $i + 1 ?></span>
            <span class="slot-need"><?= $want ? e($want) . ' recherché' : 'Tous rôles' ?></span>
            <?php if ($check['ok'] && $check['slot'] === 'main' && $check['position'] === $i): ?>
              <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="join"><button class="slot-join" type="submit"><?= e($check['label']) ?></button></form>
            <?php endif; ?>
            <?php if ($manage): ?>
              <details class="slot-role">
                <summary>Changer le rôle</summary>
                <form method="post" action="<?= $action ?>" class="slot-role-menu"><?= csrf_field() ?><input type="hidden" name="action" value="slot_role"><input type="hidden" name="position" value="<?= $i ?>">
                  <button type="submit" name="role" value=""<?= $want === null ? ' aria-pressed="true"' : '' ?>>Tous rôles</button>
                  <?php foreach (opts('role', $want) as $r): ?>
                    <button type="submit" name="role" value="<?= e($r) ?>" class="<?= e(role_class($r)) ?>"<?= $want === $r ? ' aria-pressed="true"' : '' ?>><?= e($r) ?></button>
                  <?php endforeach; ?>
                </form>
              </details>
            <?php endif; ?>
          </li>
        <?php endif; ?>
      <?php endfor; ?>
    </ol>
  </section>

  <div class="room-grid">
    <section class="reserve"<?= $manage ? ' data-reserve-drop' : '' ?>>
      <h2>Réserve <span class="muted"><?= $counts['reserve'] ?>/<?= (int) $room['reserve_capacity'] ?></span></h2>
      <?php if (!$reserve): ?>
        <p class="muted"><?= $phase === 'open' ? "Personne en réserve. Les joueurs sans place libre pour leur rôle arrivent ici, ainsi que ceux qui s'y inscrivent volontairement." : 'Personne en réserve.' ?></p>
      <?php else: ?>
        <ol class="reserve-list">
          <?php foreach ($reserve as $m): ?>
            <li class="<?= (int) $m['id'] === (int) $me['id'] ? 'is-me' : '' ?><?= $manage ? ' pickable' : '' ?>"<?= $manage ? ' data-member="' . (int) $m['id'] . '" draggable="true"' : '' ?>>
              <?= class_icon($m['classe'], 30) ?>
              <span class="reserve-name"<?= (int) $m['voluntary'] ? ' title="En réserve volontairement : ne monte pas automatiquement"' : '' ?>><?= e($m['pseudo']) ?></span>
              <span class="role-tag <?= e(role_class($m['role'])) ?>"><?= e($m['role'] ?? '—') ?></span>
              <span class="num"><?= e(fmt_gs($m['gs'])) ?></span>
              <?php if ($manage): ?>
                <span class="reserve-actions">
                  <?php if (count($occupied) < (int) $room['capacity']): ?>
                    <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="to" value="main"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>"><button class="mini" type="submit">Dans le groupe</button></form>
                  <?php endif; ?>
                  <?php if ((int) $m['id'] !== (int) $me['id']): ?>
                    <form method="post" action="<?= $action ?>" data-confirm="Retirer <?= e($m['pseudo']) ?> de la salle ?"><?= csrf_field() ?><input type="hidden" name="action" value="kick"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>"><button class="mini mini-danger" type="submit">Retirer</button></form>
                  <?php endif; ?>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>

    <aside class="side">
      <section class="me-box">
        <?php if ($mine): $active = $room['status'] === 'active'; ?>
          <?php if ($mine['slot'] === 'main'): ?>
            <p>Tu es dans le groupe.</p>
            <?php if ($active && $counts['reserve'] < (int) $room['reserve_capacity']): ?>
              <form method="post" action="<?= $action ?>" data-confirm="Laisser ta place et passer en réserve ? Tu ne remonteras pas automatiquement."><?= csrf_field() ?><input type="hidden" name="action" value="standby"><button class="btn btn-ghost" type="submit">Passer en réserve</button></form>
            <?php endif; ?>
          <?php elseif ((int) $mine['voluntary']): ?>
            <p>Tu es en réserve volontaire.</p>
            <p class="muted small">Tu ne montes pas automatiquement : le chef peut te faire monter s'il a besoin de toi.</p>
            <?php if ($active): $fits = best_free_slot($slotRoles, $occupied, $mine['role'] ?: null) !== null; ?>
              <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="step_up"><button class="btn <?= $fits ? 'btn-gold' : 'btn-ghost' ?>" type="submit"><?= $fits ? 'Rejoindre le groupe' : "Monter dès qu'une place se libère" ?></button></form>
            <?php endif; ?>
          <?php else: ?>
            <p>Tu es en réserve.</p>
            <p class="muted small">Tu monteras dans le groupe dès qu'une place pour ton rôle se libère.</p>
            <?php if ($active): ?>
              <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="standby"><button class="btn btn-ghost" type="submit">Ne pas monter automatiquement</button></form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($active): ?>
            <form method="post" action="<?= $action ?>" data-confirm="Quitter la salle ?"><?= csrf_field() ?><input type="hidden" name="action" value="leave"><button class="btn btn-ghost" type="submit">Quitter la salle</button></form>
          <?php endif; ?>
        <?php elseif ($check['ok']): ?>
          <?php if (!empty($check['note'])): ?><p class="muted small"><?= e($check['note']) ?></p><?php endif; ?>
          <?= join_form($room, $check) ?>
          <?php if (!empty($check['can_reserve'])): ?>
            <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="join_reserve"><button class="btn btn-ghost" type="submit">M'inscrire en réserve</button></form>
            <p class="muted small">En réserve, tu ne prends pas de place : le chef te fait monter s'il a besoin de toi.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted"><?= e($check['reason']) ?></p>
          <?php if (!empty($check['other'])): ?><p><a href="room.php?r=<?= e(rawurlencode($check['other'])) ?>">Aller à ma salle</a></p><?php endif; ?>
        <?php endif; ?>
      </section>

      <?php if ($manage): ?>
        <section class="manage">
          <h2>Gestion</h2>
          <div class="manage-row">
            <a class="btn btn-ghost span-2" href="room_edit.php?r=<?= e(rawurlencode($room['slug'])) ?>">Modifier la salle</a>
          </div>
          <div class="manage-row">
            <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="minutes" value="30"><button class="btn btn-ghost" type="submit"><?= $phase === 'open' ? 'Prolonger de 30 min' : 'Rouvrir 30 min' ?></button></form>
            <?php if ($phase === 'open'): ?>
              <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="close_join"><button class="btn btn-ghost" type="submit">Clore les inscriptions</button></form>
            <?php endif; ?>
          </div>
          <div class="manage-row">
            <form class="span-2" method="post" action="<?= $action ?>" data-confirm="Marquer le clear ? Les titulaires seront notés « clear cette semaine »."><?= csrf_field() ?><input type="hidden" name="action" value="finish"><input type="hidden" name="cleared" value="1"><button class="btn btn-gold" type="submit">Clear réussi</button></form>
            <form method="post" action="<?= $action ?>" data-confirm="Terminer la salle sans clear ?"><?= csrf_field() ?><input type="hidden" name="action" value="finish"><input type="hidden" name="cleared" value="0"><button class="btn btn-ghost" type="submit">Finir sans clear</button></form>
            <form method="post" action="<?= $action ?>" data-confirm="Annuler la salle ?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-danger" type="submit">Annuler</button></form>
          </div>
        </section>
      <?php endif; ?>

      <section class="journal">
        <h2>Journal</h2>
        <ol>
          <?php foreach (room_log_entries((int) $room['id']) as $l): ?>
            <li><time><?= e(fmt_when($l['ts'], true)) ?></time> <?= e($l['message']) ?></li>
          <?php endforeach; ?>
        </ol>
      </section>
    </aside>
  </div>
    <?php
}
