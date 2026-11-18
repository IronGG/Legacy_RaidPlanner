<?php
declare(strict_types=1);

/**
 * Logique des salles (« rooms »).
 *
 * Phases d'une salle active :
 *  - open : avant la fin du délai d'inscription. On rejoint le groupe si une place est libre,
 *           sinon la réserve (liste d'attente).
 *  - late : délai passé, la salle reste ouverte pour les départs et remplacements.
 *           Un départ fait monter le premier de la réserve ; s'il n'y a personne en réserve,
 *           n'importe quel membre peut prendre la place libre (marqué « remplaçant »).
 *           La réserve n'accepte plus de nouvelles inscriptions.
 * Puis le chef de salle (ou un officier) termine la salle (clear ou non) ou l'annule.
 */

final class RoomError extends UserError {}

function room_by_slug(string $slug): ?array
{
    return q('SELECT r.*, m.pseudo AS leader_pseudo FROM rooms r LEFT JOIN members m ON m.id = r.leader_id WHERE r.slug = ?', [$slug])->fetch() ?: null;
}

function room_phase(array $room, ?int $now = null): string
{
    if ($room['status'] !== 'active') {
        return $room['status'];
    }
    return ($now ?? time()) < (int) $room['join_until'] ? 'open' : 'late';
}

function can_manage_room(array $room, array $me): bool
{
    return (int) $me['is_officer'] === 1 || (int) $room['leader_id'] === (int) $me['id'];
}

function can_create_room(array $me): bool
{
    return cfg('room_creation') !== 'officers' || (int) $me['is_officer'] === 1;
}

/** Membres d'une ou plusieurs salles, titulaires d'abord puis réserve dans l'ordre d'arrivée. */
function room_members_of(array $roomIds): array
{
    $out = array_fill_keys($roomIds, []);
    if (!$roomIds) return $out;
    $in = implode(',', array_fill(0, count($roomIds), '?'));
    $rows = q("SELECT rm.room_id, rm.slot, rm.slot_since, rm.late, m.id, m.pseudo, m.classe, m.role, m.gs, m.cp,
                      m.connaissance, m.inscription, m.clear_semaine, m.helper, m.shot_caller, m.verifie
               FROM room_members rm JOIN members m ON m.id = rm.member_id
               WHERE rm.room_id IN ($in)
               ORDER BY rm.slot, rm.slot_since, rm.rowid", array_values($roomIds))->fetchAll();
    foreach ($rows as $r) {
        $out[(int) $r['room_id']][] = $r;
    }
    return $out;
}

function slot_counts(array $members): array
{
    $c = ['main' => 0, 'reserve' => 0];
    foreach ($members as $m) {
        $c[$m['slot']]++;
    }
    return $c;
}

function active_room_of(int $memberId, int $exceptRoomId = 0): ?array
{
    return q("SELECT r.* FROM rooms r JOIN room_members rm ON rm.room_id = r.id
              WHERE rm.member_id = ? AND r.status = 'active' AND r.id <> ? LIMIT 1", [$memberId, $exceptRoomId])->fetch() ?: null;
}

/** Archive les salles oubliées (départ passé depuis longtemps). */
function auto_archive(): void
{
    $limit = time() - (int) cfg('archive_after_hours') * 3600;
    q("UPDATE rooms SET status = 'done', closed_at = ?, version = version + 1
       WHERE status = 'active' AND COALESCE(starts_at, join_until) < ?", [time(), $limit]);
}

/**
 * Peut-on rejoindre ? Utilisé à la fois par l'affichage (bouton ou raison) et par room_join (règle appliquée).
 * Retourne ['ok' => bool, 'reason' => string] ou ['ok' => true, 'slot' => main|reserve, 'late' => bool, 'label' => string].
 */
function join_check(array $room, array $me, array $members): array
{
    $phase = room_phase($room);
    $counts = slot_counts($members);
    foreach ($members as $m) {
        if ((int) $m['id'] === (int) $me['id']) {
            return ['ok' => false, 'reason' => 'Tu es dans cette salle.'];
        }
    }
    if ($phase !== 'open' && $phase !== 'late') {
        return ['ok' => false, 'reason' => 'Cette salle est fermée.'];
    }
    if (cfg('one_room_per_member') && ($other = active_room_of((int) $me['id'], (int) $room['id']))) {
        return ['ok' => false, 'reason' => 'Tu es déjà dans la salle « ' . $other['title'] . ' ». Quitte-la pour rejoindre celle-ci.', 'other' => $other['slug']];
    }
    if ($me['inscription'] === 'Indisponible') {
        return ['ok' => false, 'reason' => 'Ton statut est « Indisponible ». Change-le dans ton profil pour rejoindre une salle.'];
    }
    if ((int) $me['clear_semaine'] === 1 && !(int) $room['allow_cleared']) {
        return ['ok' => false, 'reason' => "Tu as déjà clear cette semaine et cette salle n'accepte pas les joueurs déjà clear."];
    }
    if ($room['min_gs'] !== null && (int) ($me['gs'] ?? 0) < (int) $room['min_gs']) {
        return ['ok' => false, 'reason' => $me['gs'] === null
            ? 'Cette salle demande ' . fmt_gs($room['min_gs']) . ' de GS. Renseigne ton GS dans ton profil.'
            : 'Cette salle demande ' . fmt_gs($room['min_gs']) . ' de GS (le tien : ' . fmt_gs($me['gs']) . ').'];
    }
    if ($counts['main'] < (int) $room['capacity']) {
        return $phase === 'late'
            ? ['ok' => true, 'slot' => 'main', 'late' => true, 'label' => 'Prendre la place libre']
            : ['ok' => true, 'slot' => 'main', 'late' => false, 'label' => 'Rejoindre le groupe'];
    }
    if ($phase === 'open' && $counts['reserve'] < (int) $room['reserve_capacity']) {
        return ['ok' => true, 'slot' => 'reserve', 'late' => false, 'label' => 'Rejoindre la réserve'];
    }
    return ['ok' => false, 'reason' => $phase === 'open'
        ? 'Le groupe et la réserve sont complets.'
        : "Le groupe est complet. Une place se libère si quelqu'un part."];
}

function log_room(PDO $pdo, int $roomId, string $msg): void
{
    q('INSERT INTO room_log (room_id, ts, message) VALUES (?, ?, ?)', [$roomId, time(), $msg]);
    q('UPDATE rooms SET version = version + 1 WHERE id = ?', [$roomId]);
}

/** Charge la salle à l'intérieur d'une transaction et vérifie les droits. */
function locked_room(string $slug, ?array $me = null, bool $manage = false, bool $mustBeActive = true): array
{
    $room = room_by_slug($slug);
    if (!$room) throw new RoomError('Salle introuvable.');
    if ($mustBeActive && $room['status'] !== 'active') throw new RoomError('Cette salle est fermée.');
    if ($manage && (!$me || !can_manage_room($room, $me))) throw new RoomError('Seuls le chef de salle et les officiers peuvent faire ça.');
    return $room;
}

function room_create(array $data, array $me, bool $joinSelf): string
{
    $slug = bin2hex(random_bytes(4));
    $now = time();
    q('INSERT INTO rooms (slug, title, etape, objectif, leader_id, capacity, reserve_capacity, starts_at,
                          join_opened_at, join_until, min_gs, allow_cleared, notes, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $slug, $data['title'], $data['etape'], $data['objectif'], (int) $me['id'], $data['capacity'], $data['reserve_capacity'],
        $data['starts_at'], $now, $data['join_until'], $data['min_gs'], $data['allow_cleared'], $data['notes'], $now,
    ]);
    $id = (int) db()->lastInsertId();
    log_room(db(), $id, $me['pseudo'] . ' a créé la salle.');
    if ($joinSelf) {
        try {
            room_join($slug, $me);
        } catch (RoomError $e) {
            flash('warn', 'Salle créée, mais tu n\'as pas pu la rejoindre : ' . $e->getMessage());
        }
    }
    return $slug;
}

function room_join(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug);
        $me = member_by_id((int) $me['id']);
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $check = join_check($room, $me, $members);
        if (!$check['ok']) throw new RoomError($check['reason']);

        q('INSERT INTO room_members (room_id, member_id, slot, slot_since, late) VALUES (?,?,?,?,?)', [$room['id'], $me['id'], $check['slot'], time(), $check['late'] ? 1 : 0]);
        $what = $check['slot'] === 'reserve' ? 'la réserve' : ($check['late'] ? 'le groupe en remplacement' : 'le groupe');
        log_room($pdo, (int) $room['id'], $me['pseudo'] . ' a rejoint ' . $what . '.');
        return 'Tu as rejoint ' . $what . '.';
    });
}

/** Départ volontaire ($memberId = soi) ou retrait par le chef/un officier. Fait monter le premier de la réserve. */
function room_leave(string $slug, int $memberId, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $memberId, $me) {
        $self = $memberId === (int) $me['id'];
        $room = locked_room($slug, $me, !$self);
        $row = q('SELECT rm.*, m.pseudo FROM room_members rm JOIN members m ON m.id = rm.member_id
                  WHERE rm.room_id = ? AND rm.member_id = ?', [$room['id'], $memberId])->fetch();
        if (!$row) throw new RoomError($self ? "Tu n'es pas dans cette salle." : "Ce joueur n'est pas dans la salle.");

        q('DELETE FROM room_members WHERE room_id = ? AND member_id = ?', [$room['id'], $memberId]);
        log_room($pdo, (int) $room['id'], $self ? $row['pseudo'] . ' a quitté la salle.' : $row['pseudo'] . ' a été retiré par ' . $me['pseudo'] . '.');

        $msg = $self ? 'Tu as quitté la salle.' : $row['pseudo'] . ' a été retiré de la salle.';
        if ($row['slot'] === 'main' && ($next = promote_first_reserve($pdo, $room))) {
            $msg .= ' ' . $next . ' monte de la réserve.';
        }
        return $msg;
    });
}

function promote_first_reserve(PDO $pdo, array $room): ?string
{
    $next = q("SELECT rm.member_id, m.pseudo FROM room_members rm JOIN members m ON m.id = rm.member_id
               WHERE rm.room_id = ? AND rm.slot = 'reserve' ORDER BY rm.slot_since, rm.rowid LIMIT 1", [$room['id']])->fetch();
    if (!$next) return null;
    q("UPDATE room_members SET slot = 'main', slot_since = ?, late = ? WHERE room_id = ? AND member_id = ?", [time(), room_phase($room) === 'late' ? 1 : 0, $room['id'], $next['member_id']]);
    log_room($pdo, (int) $room['id'], $next['pseudo'] . ' monte de la réserve dans le groupe.');
    return $next['pseudo'];
}

/** Le chef déplace un joueur entre groupe et réserve (pas de promotion automatique : il choisit qui monte). */
function room_move(string $slug, int $memberId, string $to, array $me): string
{
    if (!in_array($to, ['main', 'reserve'], true)) throw new RoomError('Destination inconnue.');
    return tx(function (PDO $pdo) use ($slug, $memberId, $to, $me) {
        $room = locked_room($slug, $me, true);
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $counts = slot_counts($members);
        $row = null;
        foreach ($members as $m) {
            if ((int) $m['id'] === $memberId) $row = $m;
        }
        if (!$row) throw new RoomError("Ce joueur n'est pas dans la salle.");
        if ($row['slot'] === $to) return 'Rien à changer.';
        if ($to === 'main' && $counts['main'] >= (int) $room['capacity']) throw new RoomError('Le groupe est complet : passe d\'abord quelqu\'un en réserve.');
        if ($to === 'reserve' && $counts['reserve'] >= (int) $room['reserve_capacity']) throw new RoomError('La réserve est complète.');

        q('UPDATE room_members SET slot = ?, slot_since = ?, late = 0 WHERE room_id = ? AND member_id = ?', [$to, time(), $room['id'], $memberId]);
        $label = $to === 'main' ? 'passe dans le groupe' : 'passe en réserve';
        log_room($pdo, (int) $room['id'], $row['pseudo'] . ' ' . $label . ' (' . $me['pseudo'] . ').');
        return $row['pseudo'] . ' ' . $label . '.';
    });
}

function room_extend(string $slug, int $minutes, array $me): string
{
    $minutes = max(5, min(1440, $minutes));
    return tx(function (PDO $pdo) use ($slug, $minutes, $me) {
        $room = locked_room($slug, $me, true);
        $until = max(time(), (int) $room['join_until']) + $minutes * 60;
        q('UPDATE rooms SET join_until = ? WHERE id = ?', [$until, $room['id']]);
        log_room($pdo, (int) $room['id'], 'Inscriptions prolongées jusqu\'à ' . fmt_time($until) . ' par ' . $me['pseudo'] . '.');
        return 'Inscriptions ouvertes jusqu\'à ' . fmt_time($until) . '.';
    });
}

function room_close_join(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug, $me, true);
        if (room_phase($room) !== 'open') throw new RoomError('Les inscriptions sont déjà closes.');
        q('UPDATE rooms SET join_until = ? WHERE id = ?', [time(), $room['id']]);
        log_room($pdo, (int) $room['id'], 'Inscriptions closes par ' . $me['pseudo'] . '.');
        return 'Inscriptions closes. La salle reste ouverte pour les remplacements.';
    });
}

/** Termine la salle. Si clear : les titulaires passent « Clear semaine = Oui » (comme l'étape 3 de l'Excel). */
function room_finish(string $slug, bool $cleared, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $cleared, $me) {
        $room = locked_room($slug, $me, true);
        q("UPDATE rooms SET status = 'done', outcome = ?, closed_at = ? WHERE id = ?", [$cleared ? 'clear' : 'no_clear', time(), $room['id']]);
        if ($cleared) {
            q("UPDATE members SET clear_semaine = 1, updated_at = ?
                           WHERE id IN (SELECT member_id FROM room_members WHERE room_id = ? AND slot = 'main')", [time(), $room['id']]);
        }
        log_room($pdo, (int) $room['id'], ($cleared ? 'Clear réussi' : 'Salle terminée sans clear') . ' (' . $me['pseudo'] . ').');
        return $cleared ? 'Bravo pour le clear ! Les titulaires sont marqués « clear cette semaine ».' : 'Salle terminée.';
    });
}

function room_cancel(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug, $me, true);
        q("UPDATE rooms SET status = 'cancelled', closed_at = ? WHERE id = ?", [time(), $room['id']]);
        log_room($pdo, (int) $room['id'], 'Salle annulée par ' . $me['pseudo'] . '.');
        return 'Salle annulée.';
    });
}

/** Salles actives + terminées depuis 3 jours, avec leurs membres. */
function rooms_for_index(): array
{
    $rooms = q("SELECT r.*, m.pseudo AS leader_pseudo FROM rooms r LEFT JOIN members m ON m.id = r.leader_id
                WHERE r.status = 'active' OR r.closed_at > ?
                ORDER BY r.status <> 'active', COALESCE(r.starts_at, r.join_until), r.id", [time() - 3 * 86400])->fetchAll();
    $members = room_members_of(array_map(fn($r) => (int) $r['id'], $rooms));
    foreach ($rooms as &$r) {
        $r['members'] = $members[(int) $r['id']];
    }
    return $rooms;
}

/** Signature de la liste : change dès qu'une salle bouge (sert au rafraîchissement live). */
function rooms_signature(): string
{
    $sig = q("SELECT group_concat(id || ':' || version || ':' || status, ',') FROM
              (SELECT id, version, status FROM rooms WHERE status = 'active' OR closed_at > ? ORDER BY id)", [time() - 3 * 86400])->fetchColumn();
    return substr(hash('sha256', (string) $sig), 0, 16);
}

function room_log_entries(int $roomId, int $limit = 20): array
{
    return q('SELECT * FROM room_log WHERE room_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$roomId])->fetchAll();
}

function role_counts(array $members): array
{
    $c = array_fill_keys(opts('role'), 0);
    foreach ($members as $m) {
        if ($m['slot'] === 'main' && $m['role']) $c[$m['role']] = ($c[$m['role']] ?? 0) + 1;
    }
    return $c;
}

function avg_gs(array $members): ?int
{
    $gs = array_filter(array_map(fn($m) => $m['slot'] === 'main' ? $m['gs'] : null, $members), fn($v) => $v !== null);
    return $gs ? (int) round(array_sum($gs) / count($gs)) : null;
}
