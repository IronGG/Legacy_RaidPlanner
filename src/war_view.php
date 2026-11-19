<?php
declare(strict_types=1);

const WAR_DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

function war_title(array $war): string
{
    return 'War du ' . fmt_dt($war['starts_at']);
}

/** « chaque jeudi et samedi à 21:00 » d'après war_schedule. */
function war_schedule_text(): string
{
    $byTime = [];
    foreach ((array) cfg('war_schedule') as $entry) {
        [$day, $time] = array_values((array) $entry) + [null, null];
        if (isset(WAR_DAY_NAMES[(int) $day])) $byTime[(string) $time][] = WAR_DAY_NAMES[(int) $day];
    }
    $parts = [];
    foreach ($byTime as $time => $days) $parts[] = names_list($days) . ' à ' . $time;
    return $parts ? 'chaque ' . implode(', et chaque ', $parts) : '';
}

/** Phase d'une war en une ligne, avec compte à rebours avant le début. */
function war_phase_html(array $war): string
{
    return match (war_phase($war)) {
        'upcoming'  => '<span data-deadline="' . (int) $war['starts_at'] . '">Début dans <b data-left>' . e(fmt_left((int) $war['starts_at'] - time())) . '</b></span>',
        'live'      => '<span class="war-live">En cours</span>',
        'past'      => '<span>Terminée</span>',
        'cancelled' => '<span class="war-cancelled">Annulée</span>',
    };
}

function war_status_badge(?string $status): string
{
    return isset(WAR_STATUS_BADGES[$status]) ? '<span class="badge war-st war-st-' . e($status) . '">' . e(WAR_STATUS_BADGES[$status]) . '</span>' : '';
}

/** Page de liste : wars à venir et en cours, puis les dernières passées. */
function render_war_index(array $me): void
{
    [$current, $past] = wars_for_index();
    $counts = war_counts_for(array_map(fn($w) => (int) $w['id'], array_merge($current, $past)));
    $mine = [];
    foreach (q('SELECT war_id, status FROM war_signups WHERE member_id = ?', [$me['id']])->fetchAll() as $r) $mine[(int) $r['war_id']] = $r['status'];

    if (!$current) {
        echo '<div class="empty"><p>Aucune war prévue pour le moment.</p></div>';
    } else {
        echo '<div class="war-list">';
        foreach ($current as $w) {
            $id = (int) $w['id'];
            $c = $counts[$id];
            $my = $mine[$id] ?? null;
            echo '<a class="war-card' . ((int) $w['cancelled'] ? ' is-cancelled' : '') . '" href="war.php?w=' . $id . '">'
                . '<div class="war-card-main"><h2>' . e(ucfirst(fmt_dt($w['starts_at']))) . '</h2>'
                . '<p class="meta">' . war_phase_html($w) . '<span>' . plural($c['in'], 'inscrit') . ($c['bench'] ? ' (+' . $c['bench'] . ' au banc)' : '') . '</span>'
                . ($c['absent'] ? '<span>' . plural($c['absent'], 'absent') . '</span>' : '') . '</p>'
                . ($w['message'] ? '<p class="war-card-msg">' . e($w['message']) . '</p>' : '') . '</div>'
                . '<div class="war-card-side">'
                . ($my ? '<span class="war-my war-my-' . e($my) . '">' . e(WAR_STATUSES[$my]) . '</span>'
                       : ((int) $w['cancelled'] ? '' : '<span class="war-my war-my-none">Pas encore répondu</span>'))
                . ($w['published_at'] ? '<span class="badge badge-aether">Compo publiée</span>' : '')
                . '</div></a>';
        }
        echo '</div>';
    }

    if ($past) {
        echo '<h2 class="section-title">Wars passées</h2><ul class="war-past">';
        foreach ($past as $w) {
            $c = $counts[(int) $w['id']];
            $my = $mine[(int) $w['id']] ?? null;
            echo '<li><a href="war.php?w=' . (int) $w['id'] . '">' . e(ucfirst(fmt_dt($w['starts_at']))) . '</a>'
                . '<span class="muted">' . ((int) $w['cancelled'] ? 'Annulée' : plural($c['in'], 'inscrit')) . '</span>'
                . ($my ? '<span class="muted">Ta réponse : ' . e(WAR_STATUSES[$my]) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
    }
}

/** Formulaire de réponse du membre connecté (hors de la zone rafraîchie, pour ne pas perdre son choix). */
function render_war_answer(array $war, array $me): void
{
    $s = war_signup_of((int) $war['id'], (int) $me['id']);
    $role = $s['role'] ?? ($me['role'] ?: null);
    $roles = opts('role', $role);
    ?>
  <section class="panel war-answer" aria-labelledby="answer-title">
    <h2 id="answer-title">Ta réponse
      <?php if ($s): ?><span class="war-my war-my-<?= e($s['status']) ?>"><?= e(WAR_STATUSES[$s['status']]) ?><?= $s['role'] && $s['status'] !== 'absent' ? ' · ' . e($s['role']) : '' ?></span>
      <?php else: ?><span class="war-my war-my-none">Pas encore répondu</span><?php endif; ?>
    </h2>
    <form method="post" action="war.php?w=<?= (int) $war['id'] ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="signup">
      <fieldset class="field"><legend>Rôle pour cette war</legend><?= chip_group('role', $roles, $role ?? ($roles[0] ?? null)) ?></fieldset>
      <div class="war-status-btns">
        <?php foreach (WAR_STATUSES as $key => $label): ?>
          <button class="btn war-btn war-btn-<?= e($key) ?><?= ($s['status'] ?? '') === $key ? ' is-current' : '' ?>" type="submit" name="status" value="<?= e($key) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="hint">Choisis ton rôle, puis clique sur ta réponse. Tu peux la changer jusqu'à la fin de la war.</p>
    </form>
  </section>
    <?php
}

/** Zone rafraîchie en direct : message, outils des officiers, compo, réponses. */
function render_war_live(array $war, array $me): void
{
    $id = (int) $war['id'];
    $manage = can_manage_war($me);
    $signups = war_signups($id);
    $byMember = [];
    foreach ($signups as $s) $byMember[(int) $s['member_id']] = $s;
    $layout = war_layout($id);
    $published = (bool) $war['published_at'];

    if ($war['message']) echo '<p class="war-message">' . nl2br(e($war['message'])) . '</p>';
    if ($manage) render_war_tools($war, $signups, $me);

    if ($manage) {
        render_war_board($war, $layout, $byMember, $me, true);
    } elseif (!(int) $war['cancelled']) {
        $mine = $layout['placed'][(int) $me['id']] ?? null;
        $s = $byMember[(int) $me['id']] ?? null;
        echo '<section class="war-mine">';
        if (!$published) {
            echo '<p class="muted">La compo n\'est pas encore publiée. Tu verras ici ta troupe et ton groupe dès que les officiers l\'auront publiée.</p>';
        } elseif ($mine) {
            echo '<p>Ta place : <strong>' . e($mine['troop']) . ' · Groupe ' . (int) $mine['group'] . '</strong></p>';
        } elseif ($s && $s['status'] !== 'absent') {
            echo '<p>Tu n\'es pas placé·e dans la compo pour l\'instant.</p>';
        } else {
            echo '<p class="muted">Réponds présent pour être placé·e dans une troupe.</p>';
        }
        echo '</section>';
        if ($published) render_war_board($war, $layout, $byMember, $me, false);
    }
    render_war_summary($war, $signups, $manage);
}

function render_war_tools(array $war, array $signups, array $me): void
{
    $id = (int) $war['id'];
    $prev = war_previous_with_places($war);
    $members = q('SELECT id, pseudo FROM members ORDER BY pseudo COLLATE NOCASE')->fetchAll();
    $answers = [];
    foreach ($signups as $s) $answers[(int) $s['member_id']] = WAR_STATUSES[$s['status']];
    ?>
  <section class="panel war-tools" aria-label="Outils des officiers">
    <div class="war-tools-row">
      <p class="war-pub"><?= $war['published_at']
          ? '<span class="badge badge-aether">Compo publiée</span> <span class="muted small">depuis ' . e(fmt_when($war['published_at'])) . ' : les membres voient leur place et les changements en direct.</span>'
          : '<span class="badge badge-muted">Compo pas publiée</span> <span class="muted small">Les membres ne voient que les réponses.</span>' ?></p>
      <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="on" value="<?= $war['published_at'] ? '0' : '1' ?>">
        <button class="btn <?= $war['published_at'] ? 'btn-ghost' : 'btn-gold' ?>" type="submit"><?= $war['published_at'] ? 'Masquer la compo' : 'Publier la compo' ?></button></form>
    </div>
    <div class="war-tools-row">
      <?php if ($prev): ?>
        <form method="post" data-ajax data-confirm="Remplacer les troupes de cette war par la compo du <?= e(fmt_dt($prev['starts_at'])) ?> ? Les joueurs qui n'ont pas répondu à cette war laissent leur place vide.">
          <?= csrf_field() ?><input type="hidden" name="action" value="copy_previous"><button class="mini" type="submit">Reprendre la compo du <?= e(fmt_dt($prev['starts_at'])) ?></button></form>
      <?php endif; ?>
      <form method="post" data-ajax data-confirm="Remettre tous les joueurs dans « À placer » ? Les troupes et les groupes restent."><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="mini" type="submit">Tout remettre à placer</button></form>
      <details class="war-pop">
        <summary class="mini">Message</summary>
        <form method="post" data-ajax class="war-pop-body"><?= csrf_field() ?><input type="hidden" name="action" value="message">
          <label for="f-war-msg" class="small muted">Affiché en haut de la war, pour tout le monde</label>
          <textarea id="f-war-msg" name="message" rows="2" maxlength="300"><?= e($war['message']) ?></textarea>
          <button class="mini" type="submit">Enregistrer</button></form>
      </details>
      <details class="war-pop">
        <summary class="mini">Répondre pour un membre</summary>
        <form method="post" data-ajax class="war-pop-body"><?= csrf_field() ?><input type="hidden" name="action" value="signup_for">
          <label for="f-war-member" class="small muted">Pour quelqu'un qui a répondu sur Discord, par exemple</label>
          <select name="member_id" id="f-war-member" required>
            <option value="">Choisis un membre</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= (int) $m['id'] ?>"><?= e($m['pseudo']) ?><?= isset($answers[(int) $m['id']]) ? ' (' . e($answers[(int) $m['id']]) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?= select_field('role', opts('role'), null, 'Rôle de son profil') ?>
          <span class="war-status-btns">
            <?php foreach (WAR_STATUSES as $key => $label): ?><button class="mini war-btn-<?= e($key) ?>" type="submit" name="status" value="<?= e($key) ?>"><?= e($label) ?></button><?php endforeach; ?>
          </span>
        </form>
      </details>
      <form method="post" data-ajax data-confirm="<?= (int) $war['cancelled'] ? 'Rétablir cette war ?' : 'Annuler cette war ? Les membres ne pourront plus répondre.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="on" value="<?= (int) $war['cancelled'] ? '0' : '1' ?>">
        <button class="mini<?= (int) $war['cancelled'] ? '' : ' mini-danger' ?>" type="submit"><?= (int) $war['cancelled'] ? 'Rétablir la war' : 'Annuler la war' ?></button></form>
    </div>
    <?php if ($war['updated_by']): ?><p class="muted small war-last">Dernier changement : <?= e($war['updated_by']) ?>, <?= e(fmt_ago($war['updated_at'])) ?>.</p><?php endif; ?>
  </section>
    <?php
}

/** Troupes et groupes. $edit : outils et glisser-déposer des officiers ; sinon lecture seule. */
function render_war_board(array $war, array $layout, array $byMember, array $me, bool $edit): void
{
    $placed = $layout['placed'];
    echo '<div class="war-board' . ($edit ? ' war-editing' : '') . '"' . ($edit ? ' data-editor' : '') . '>';
    if ($edit) render_war_pool($byMember, $placed);
    echo '<div class="war-troops">';
    if (!$layout['troops']) echo '<p class="empty">Aucune troupe. Ajoute-en une pour commencer.</p>';
    $tcount = count($layout['troops']);
    foreach ($layout['troops'] as $ti => $t) {
        $cap = array_sum(array_map(fn($g) => (int) $g['size'], $t['groups']));
        $filled = array_sum(array_map(fn($g) => count($g['slots']), $t['groups']));
        $tid = (int) $t['id'];
        echo '<section class="war-troop"><header class="war-troop-head"><h2><span>' . e($t['name']) . '</span> <small>' . $filled . '/' . $cap . '</small></h2>';
        if ($edit) {
            echo '<div class="war-tools-inline">'
                . '<details class="war-pop"><summary class="mini" title="Renommer la troupe">Renommer</summary>'
                . '<form method="post" data-ajax class="war-pop-body">' . csrf_field() . '<input type="hidden" name="action" value="troop_rename"><input type="hidden" name="troop_id" value="' . $tid . '">'
                . '<input name="name" value="' . e($t['name']) . '" maxlength="40" required aria-label="Nom de la troupe"><button class="mini" type="submit">OK</button></form></details>'
                . ($ti > 0 ? war_mini_form('troop_move', ['troop_id' => $tid, 'dir' => -1], '↑', 'Monter la troupe') : '')
                . ($ti < $tcount - 1 ? war_mini_form('troop_move', ['troop_id' => $tid, 'dir' => 1], '↓', 'Descendre la troupe') : '')
                . war_mini_form('group_add', ['troop_id' => $tid], '+ Groupe', 'Ajouter un groupe')
                . war_mini_form('troop_delete', ['troop_id' => $tid], 'Supprimer', 'Supprimer la troupe', 'Supprimer « ' . $t['name'] . ' » ? Ses joueurs repassent dans « À placer ».', true)
                . '</div>';
        }
        echo '</header><div class="war-groups">';
        foreach ($t['groups'] as $g) {
            $gid = (int) $g['id'];
            echo '<section class="war-group"><header><h3>Groupe ' . (int) $g['number'] . ' <small>' . count($g['slots']) . '/' . (int) $g['size'] . '</small></h3>';
            if ($edit) {
                echo '<div class="war-group-tools">'
                    . war_mini_form('group_resize', ['group_id' => $gid, 'delta' => -1], '−', 'Une place de moins', null, false, 'icon-btn')
                    . war_mini_form('group_resize', ['group_id' => $gid, 'delta' => 1], '+', 'Une place de plus', null, false, 'icon-btn')
                    . war_mini_form('group_delete', ['group_id' => $gid], '×', 'Supprimer le groupe', 'Supprimer le groupe ' . (int) $g['number'] . ' ? Ses joueurs repassent dans « À placer ».', true, 'icon-btn')
                    . '</div>';
            }
            echo '</header><ol class="war-slots">';
            for ($i = 0; $i < (int) $g['size']; $i++) {
                $mid = $g['slots'][$i] ?? null;
                $s = $mid ? ($byMember[$mid] ?? null) : null;
                if ($mid && $s) {
                    $isMe = $mid === (int) $me['id'];
                    echo '<li class="war-slot ' . e(role_class($s['role'])) . ($isMe ? ' is-me' : '') . ($edit ? ' pickable' : '') . '"'
                        . ' data-group="' . $gid . '" data-slot="' . $i . '"' . ($edit ? ' data-member="' . $mid . '" draggable="true"' : '')
                        . ' title="' . e($s['pseudo'] . ' · ' . ($s['role'] ?: 'sans rôle') . ' · ' . WAR_STATUSES[$s['status']]) . '">'
                        . class_icon($s['classe'], 24) . '<span class="war-name">' . e($s['pseudo']) . '</span>'
                        . war_status_badge($s['status']) . '</li>';
                } else {
                    echo '<li class="war-slot war-slot-empty" data-group="' . $gid . '" data-slot="' . $i . '"><span class="sr">Place libre</span></li>';
                }
            }
            echo '</ol></section>';
        }
        echo '</div></section>';
    }
    if ($edit) {
        echo '<p class="war-add-troop">' . war_mini_form('troop_add', [], 'Ajouter une troupe de ' . WAR_TROOP_PLAYERS, 'Ajouter une troupe') . '</p>';
    }
    echo '</div></div>';
}

/** Petit formulaire d'un bouton, envoyé sans recharger la page. */
function war_mini_form(string $action, array $fields, string $label, string $title, ?string $confirm = null, bool $danger = false, string $cls = 'mini'): string
{
    $h = '<form method="post" data-ajax' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button class="' . e($cls) . ($danger ? ' mini-danger' : '') . '" type="submit" title="' . e($title) . '" aria-label="' . e($title) . '">' . e($label) . '</button></form>';
}

/** Joueurs inscrits pas encore placés, par rôle ; on y dépose un joueur pour le sortir de sa troupe. */
function render_war_pool(array $byMember, array $placed): void
{
    $order = array_flip(array_keys(WAR_STATUSES));
    $todo = array_filter($byMember, fn($s) => in_array($s['status'], WAR_PLACEABLE, true) && !isset($placed[(int) $s['member_id']]));
    usort($todo, fn($a, $b) => [$order[$a['status']], mb_strtolower($a['pseudo'])] <=> [$order[$b['status']], mb_strtolower($b['pseudo'])]);
    $byRole = [];
    foreach ($todo as $s) $byRole[$s['role'] ?: ''][] = $s;
    $roles = array_values(array_unique(array_merge(opts('role', null, true), array_keys($byRole))));
    echo '<aside class="war-pool" data-pool-drop aria-label="Joueurs à placer"><h2>À placer <small>' . count($todo) . '</small></h2>'
        . '<p class="hint">Glisse un joueur sur une place, ou clique sur lui puis sur la place. Sur une place occupée, les deux échangent. Dépose un joueur ici pour le sortir de sa troupe.</p>';
    if (!$todo) echo '<p class="muted small">Tous les inscrits sont placés.</p>';
    foreach ($roles as $role) {
        if (empty($byRole[$role])) continue;
        echo '<h3 class="' . e(role_class($role ?: null)) . '">' . e($role ?: 'Sans rôle') . ' <small>' . count($byRole[$role]) . '</small></h3><ul class="war-chips">';
        foreach ($byRole[$role] as $s) {
            echo '<li class="war-chip ' . e(role_class($s['role'])) . ' pickable" data-member="' . (int) $s['member_id'] . '" draggable="true">'
                . class_icon($s['classe'], 22) . '<span class="war-name">' . e($s['pseudo']) . '</span>' . war_status_badge($s['status']) . '</li>';
        }
        echo '</ul>';
    }
    echo '</aside>';
}

/** Réponses façon Raid-Helper : colonnes par rôle, puis banc, absents (et sans réponse pour les officiers). */
function render_war_summary(array $war, array $signups, bool $manage): void
{
    $c = war_counts($signups);
    $byRole = [];
    $bench = [];
    $absent = [];
    foreach ($signups as $s) {
        if ($s['status'] === 'bench') $bench[] = $s;
        elseif ($s['status'] === 'absent') $absent[] = $s;
        else $byRole[$s['role'] ?: ''][] = $s;
    }
    $roles = array_values(array_unique(array_merge(opts('role', null, true), array_keys($byRole))));
    $name = fn($s) => '<li>' . class_icon($s['classe'], 20) . '<span>' . e($s['pseudo']) . '</span>' . war_status_badge($s['status']) . '</li>';
    echo '<section class="war-summary"><h2>Réponses <small>' . plural($c['in'], 'inscrit') . ($c['bench'] ? ' (+' . $c['bench'] . ' au banc)' : '')
        . ($c['late'] ? ' · ' . $c['late'] . ' en retard' : '') . ($c['tentative'] ? ' · ' . plural($c['tentative'], 'provisoire') : '') . '</small></h2>';
    if (!$signups) echo '<p class="muted">Personne n\'a encore répondu.</p>';
    echo '<div class="war-roles">';
    foreach ($roles as $role) {
        if (empty($byRole[$role])) continue;
        echo '<div class="war-role ' . e(role_class($role ?: null)) . '"><h3>' . e($role ?: 'Sans rôle') . ' <b>' . count($byRole[$role]) . '</b></h3><ul class="war-names">'
            . implode('', array_map($name, $byRole[$role])) . '</ul></div>';
    }
    echo '</div>';
    $line = fn(string $label, array $list) => $list ? '<div class="war-line"><h3>' . e($label) . ' <b>' . count($list) . '</b></h3><ul class="war-names war-names-inline">'
        . implode('', array_map($name, $list)) . '</ul></div>' : '';
    echo $line('Banc', $bench) . $line('Absents', $absent);
    if ($manage) {
        $none = war_no_answer((int) $war['id']);
        if ($none) {
            echo '<details class="war-noanswer" data-keep="noanswer"><summary>Pas encore répondu <b>' . count($none) . '</b></summary><ul class="war-names war-names-inline">'
                . implode('', array_map(fn($m) => '<li>' . class_icon($m['classe'], 20) . '<span>' . e($m['pseudo']) . '</span></li>', $none)) . '</ul></details>';
        }
    }
    echo '</section>';
}
