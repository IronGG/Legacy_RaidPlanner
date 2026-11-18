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
 *
 * Places : le groupe a « capacity » places numérotées. Chaque place peut demander un rôle (Tank, Soin…)
 * ou rester ouverte à tous. Un joueur n'entre dans le groupe que sur une place de son rôle ou une place
 * ouverte ; sinon il va en réserve, et monte dès qu'une place qui lui convient se libère.
 * Le chef de salle peut déplacer librement les joueurs d'une place à l'autre (il peut forcer un rôle).
 */

final class RoomError extends UserError {}

function room_by_slug(string $slug): ?array
{
    return q('SELECT r.*, m.pseudo AS leader_pseudo FROM rooms r LEFT JOIN members m ON m.id = r.leader_id WHERE r.slug = ?', [$slug])->fetch() ?: null;
}

/** Raid (par défaut) ou donjon : un donjon n'a ni étape, ni objectif, ni délai d'inscription, ni clear de la semaine. */
function room_is_raid(array $room): bool
{
    return ($room['kind'] ?? 'raid') !== 'donjon';
}

function room_phase(array $room, ?int $now = null): string
{
    if ($room['status'] !== 'active') {
        return $room['status'];
    }
    if (!room_is_raid($room)) {
        return 'open'; // donjon : on rejoint tant que la salle n'est pas terminée
    }
    return ($now ?? time()) < (int) $room['join_until'] ? 'open' : 'late';
}

function can_manage_room(array $room, array $me): bool
{
    return can_lead($me) || (int) $room['leader_id'] === (int) $me['id'];
}

function can_create_room(array $me): bool
{
    return cfg('room_creation') !== 'officers' || can_lead($me);
}

/** Membres d'une ou plusieurs salles, titulaires d'abord puis réserve dans l'ordre d'arrivée. */
function room_members_of(array $roomIds): array
{
    $out = array_fill_keys($roomIds, []);
    if (!$roomIds) return $out;
    $in = implode(',', array_fill(0, count($roomIds), '?'));
    $rows = q("SELECT rm.room_id, rm.slot, rm.position, rm.slot_since, rm.late, rm.voluntary, m.id, m.pseudo, m.classe, m.role, m.gs, m.cp,
                      m.connaissance, m.inscription, m.clear_semaine, m.helper, m.shot_caller, m.verifie, m.is_officer, m.is_raid_lead
               FROM room_members rm JOIN members m ON m.id = rm.member_id
               WHERE rm.room_id IN ($in)
               ORDER BY rm.slot, rm.position, rm.slot_since, rm.rowid", array_values($roomIds))->fetchAll();
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

/** Rôle recherché pour chaque place du groupe (null = ouverte à tous). */
function room_slot_roles(array $room): array
{
    $roles = array_fill(0, (int) $room['capacity'], null);
    foreach (q('SELECT position, role FROM room_slots WHERE room_id = ?', [$room['id']])->fetchAll() as $r) {
        if ((int) $r['position'] < count($roles)) $roles[(int) $r['position']] = $r['role'] ?: null;
    }
    return $roles;
}

/** Joueurs du groupe par place : position => membre. */
function occupied_positions(array $members): array
{
    $occ = [];
    foreach ($members as $m) {
        if ($m['slot'] === 'main' && $m['position'] !== null) $occ[(int) $m['position']] = $m;
    }
    return $occ;
}

/** Meilleure place libre pour un rôle : d'abord une place demandant ce rôle, sinon une place ouverte à tous. */
function best_free_slot(array $slotRoles, array $occupied, ?string $role): ?int
{
    $open = null;
    foreach ($slotRoles as $pos => $want) {
        if (isset($occupied[$pos])) continue;
        if ($want !== null && $role !== null && $want === $role) return $pos;
        if ($want === null && $open === null) $open = $pos;
    }
    return $open;
}

/** Nombre de places demandées par rôle. */
function role_needs(array $slotRoles): array
{
    $n = [];
    foreach ($slotRoles as $r) {
        if ($r !== null) $n[$r] = ($n[$r] ?? 0) + 1;
    }
    return $n;
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
       WHERE status = 'active' AND MAX(COALESCE(starts_at, 0), join_until) < ?", [time(), $limit]);
}

/**
 * Peut-on rejoindre ? Utilisé à la fois par l'affichage (bouton ou raison) et par room_join (règle appliquée).
 * Retourne ['ok' => bool, 'reason' => string] ou ['ok' => true, 'slot' => main|reserve, 'late' => bool, 'label' => string].
 */
function join_check(array $room, array $me, array $members, ?array $slotRoles = null): array
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
    if (room_is_raid($room) && (int) $me['clear_semaine'] === 1 && !(int) $room['allow_cleared']) {
        return ['ok' => false, 'reason' => "Tu as déjà clear cette semaine et cette salle n'accepte pas les joueurs déjà clear."];
    }
    if ($room['min_gs'] !== null && (int) ($me['gs'] ?? 0) < (int) $room['min_gs']) {
        return ['ok' => false, 'reason' => $me['gs'] === null
            ? 'Cette salle demande ' . fmt_gs($room['min_gs']) . ' de GS. Renseigne ton GS dans ton profil.'
            : 'Cette salle demande ' . fmt_gs($room['min_gs']) . ' de GS (le tien : ' . fmt_gs($me['gs']) . ').'];
    }
    $slotRoles ??= room_slot_roles($room);
    $occupied = occupied_positions($members);
    $pos = best_free_slot($slotRoles, $occupied, $me['role'] ?: null);
    $reserveOpen = $phase === 'open' && $counts['reserve'] < (int) $room['reserve_capacity'];
    if ($pos !== null) {
        return ['ok' => true, 'slot' => 'main', 'position' => $pos, 'late' => $phase === 'late', 'can_reserve' => $reserveOpen,
            'label' => $phase === 'late' ? 'Prendre la place libre' : 'Rejoindre le groupe'];
    }
    $why = count($occupied) < count($slotRoles)
        ? 'Aucune place libre pour ton rôle (' . ($me['role'] ?: 'non renseigné') . ').'
        : 'Le groupe est complet.';
    if ($reserveOpen) {
        return ['ok' => true, 'slot' => 'reserve', 'position' => null, 'late' => false, 'label' => 'Rejoindre la réserve',
            'note' => $why . " Tu monteras dans le groupe dès qu'une place pour ton rôle se libère."];
    }
    return ['ok' => false, 'reason' => $phase === 'open'
        ? $why . ' La réserve est complète.'
        : $why . " Une place se libère si quelqu'un part."];
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
    if ($manage && (!$me || !can_manage_room($room, $me))) throw new RoomError('Seuls le chef de salle, les raid leads et les officiers peuvent faire ça.');
    return $room;
}

function room_create(array $data, array $me, bool $joinSelf): string
{
    $slug = bin2hex(random_bytes(4));
    $now = time();
    q('INSERT INTO rooms (slug, kind, title, etape, objectif, leader_id, capacity, reserve_capacity, starts_at,
                          join_opened_at, join_until, min_gs, allow_cleared, notes, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $slug, $data['kind'], $data['title'], $data['etape'], $data['objectif'], (int) $me['id'], $data['capacity'], $data['reserve_capacity'],
        $data['starts_at'], $now, $data['join_until'] ?? $now, $data['min_gs'], $data['allow_cleared'], $data['notes'], $now,
    ]);
    $id = (int) db()->lastInsertId();
    for ($i = 0; $i < (int) $data['capacity']; $i++) {
        q('INSERT INTO room_slots (room_id, position) VALUES (?, ?)', [$id, $i]);
    }
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

/**
 * Modifie les réglages d'une salle sans retirer personne. Moins de places : les titulaires des places supprimées
 * sont replacés sur des places libres (de leur rôle si possible). Plus de places : la réserve monte.
 */
function room_update(string $slug, array $data, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $data, $me) {
        $room = locked_room($slug, $me, true);
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $counts = slot_counts($members);
        $cap = (int) $data['capacity'];
        $res = (int) $data['reserve_capacity'];
        if ($cap < $counts['main']) {
            throw new RoomError('Le groupe compte ' . plural($counts['main'], 'joueur') . ' : il faut au moins ' . plural($counts['main'], 'place') . ". Passe d'abord des joueurs en réserve.");
        }
        if ($res < $counts['reserve']) {
            throw new RoomError('La réserve compte ' . plural($counts['reserve'], 'joueur') . ' : il faut au moins ' . plural($counts['reserve'], 'place') . ' en réserve.');
        }

        // Ce qui change, pour le journal
        $changes = [];
        if ($data['kind'] !== ($room['kind'] ?? 'raid')) $changes[] = $data['kind'] === 'donjon' ? 'devient un donjon' : 'devient un raid';
        if ($data['title'] !== $room['title']) $changes[] = 'nom « ' . $data['title'] . ' »';
        if ($data['etape'] !== $room['etape']) $changes[] = 'étape ' . ($data['etape'] ?? '—');
        if ($data['objectif'] !== $room['objectif']) $changes[] = 'objectif ' . ($data['objectif'] ?? '—');
        if ($data['starts_at'] !== ($room['starts_at'] === null ? null : (int) $room['starts_at'])) {
            $changes[] = $data['starts_at'] ? 'départ ' . fmt_when($data['starts_at']) : 'sans heure de départ';
        }
        if ($data['join_until'] !== null && $data['kind'] === 'raid') $changes[] = "inscriptions jusqu'à " . fmt_when($data['join_until']);
        if ($cap !== (int) $room['capacity']) $changes[] = "places {$room['capacity']} → $cap";
        if ($res !== (int) $room['reserve_capacity']) $changes[] = "réserve {$room['reserve_capacity']} → $res";
        if ($data['min_gs'] !== ($room['min_gs'] === null ? null : (int) $room['min_gs'])) {
            $changes[] = $data['min_gs'] ? 'GS minimum ' . fmt_gs($data['min_gs']) : 'sans GS minimum';
        }
        if ((int) $data['allow_cleared'] !== (int) $room['allow_cleared']) {
            $changes[] = $data['allow_cleared'] ? 'joueurs déjà clear acceptés' : 'joueurs déjà clear refusés';
        }
        if ($data['notes'] !== $room['notes']) $changes[] = 'notes';
        if (!$changes) return 'Rien à changer.';

        q('UPDATE rooms SET kind = ?, title = ?, etape = ?, objectif = ?, starts_at = ?, capacity = ?, reserve_capacity = ?,
                            min_gs = ?, allow_cleared = ?, notes = ? WHERE id = ?',
            [$data['kind'], $data['title'], $data['etape'], $data['objectif'], $data['starts_at'], $cap, $res,
             $data['min_gs'], $data['allow_cleared'], $data['notes'], $room['id']]);
        if ($data['join_until'] !== null) {
            q('UPDATE rooms SET join_opened_at = ?, join_until = ? WHERE id = ?', [time(), $data['join_until'], $room['id']]);
        }

        // Places du groupe
        $old = (int) $room['capacity'];
        for ($i = $old; $i < $cap; $i++) {
            q('INSERT OR IGNORE INTO room_slots (room_id, position) VALUES (?, ?)', [$room['id'], $i]);
        }
        if ($cap < $old) {
            $slotRoles = array_slice(room_slot_roles($room), 0, $cap, true);
            $occupied = [];
            $moving = [];
            foreach ($members as $m) {
                if ($m['slot'] !== 'main') continue;
                if ((int) $m['position'] < $cap) $occupied[(int) $m['position']] = $m;
                else $moving[] = $m;
            }
            foreach ($moving as $m) {
                q('UPDATE room_members SET position = NULL WHERE room_id = ? AND member_id = ?', [$room['id'], $m['id']]);
            }
            foreach ($moving as $m) {
                $pos = best_free_slot($slotRoles, $occupied, $m['role'] ?: null);
                if ($pos === null) { // aucune place de son rôle : n'importe quelle place libre (il y en a, $cap >= titulaires)
                    for ($pos = 0; isset($occupied[$pos]); $pos++);
                }
                q('UPDATE room_members SET position = ? WHERE room_id = ? AND member_id = ?', [$pos, $room['id'], $m['id']]);
                $occupied[$pos] = $m;
            }
            q('DELETE FROM room_slots WHERE room_id = ? AND position >= ?', [$room['id'], $cap]);
        }

        log_room($pdo, (int) $room['id'], 'Salle modifiée par ' . $me['pseudo'] . ' : ' . implode(', ', $changes) . '.');
        $msg = 'Salle modifiée : ' . implode(', ', $changes) . '.';
        $fresh = q('SELECT * FROM rooms WHERE id = ?', [$room['id']])->fetch();
        if ($up = fill_from_reserve($pdo, $fresh)) {
            $msg .= ' ' . names_list($up) . (count($up) > 1 ? ' montent' : ' monte') . ' de la réserve.';
        }
        return $msg;
    });
}

/** $reserve = true : inscription volontaire en réserve, même s'il reste une place pour son rôle. */
function room_join(string $slug, array $me, bool $reserve = false): string
{
    return tx(function (PDO $pdo) use ($slug, $me, $reserve) {
        $room = locked_room($slug);
        $me = member_by_id((int) $me['id']);
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $check = join_check($room, $me, $members);
        if (!$check['ok']) throw new RoomError($check['reason']);

        if ($reserve) {
            if ($check['slot'] === 'main' && empty($check['can_reserve'])) {
                throw new RoomError(room_phase($room) === 'open' ? 'La réserve est complète.' : "La réserve n'accepte plus d'inscriptions : le délai est passé.");
            }
            q("INSERT INTO room_members (room_id, member_id, slot, position, slot_since, late, voluntary) VALUES (?, ?, 'reserve', NULL, ?, 0, 1)",
                [$room['id'], $me['id'], time()]);
            log_room($pdo, (int) $room['id'], $me['pseudo'] . " s'inscrit en réserve (volontaire).");
            return "Tu es inscrit en réserve. Tu ne montes pas automatiquement : rejoins le groupe quand tu veux, ou le chef te fera monter s'il a besoin de toi.";
        }

        q('INSERT INTO room_members (room_id, member_id, slot, position, slot_since, late) VALUES (?,?,?,?,?,?)',
            [$room['id'], $me['id'], $check['slot'], $check['position'], time(), $check['late'] ? 1 : 0]);
        $what = $check['slot'] === 'reserve' ? 'la réserve' : ($check['late'] ? 'le groupe en remplacement' : 'le groupe');
        log_room($pdo, (int) $room['id'], $me['pseudo'] . ' a rejoint ' . $what . '.');
        return 'Tu as rejoint ' . $what . '.' . ($check['slot'] === 'reserve' && !empty($check['note']) ? ' ' . $check['note'] : '');
    });
}

/** Le joueur choisit de rester en réserve : il laisse sa place du groupe, ou arrête de monter automatiquement. */
function room_standby(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug);
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $row = null;
        foreach ($members as $m) {
            if ((int) $m['id'] === (int) $me['id']) $row = $m;
        }
        if (!$row) throw new RoomError("Tu n'es pas dans cette salle.");
        if ($row['slot'] === 'reserve') {
            if ((int) $row['voluntary']) return 'Tu es déjà en réserve volontaire.';
            q('UPDATE room_members SET voluntary = 1 WHERE room_id = ? AND member_id = ?', [$room['id'], $me['id']]);
            log_room($pdo, (int) $room['id'], $row['pseudo'] . ' reste en réserve (volontaire).');
            return "C'est noté : tu restes en réserve et tu ne monteras pas automatiquement.";
        }
        if (slot_counts($members)['reserve'] >= (int) $room['reserve_capacity']) {
            throw new RoomError('La réserve est complète : tu ne peux pas y passer.');
        }
        q("UPDATE room_members SET slot = 'reserve', position = NULL, slot_since = ?, late = 0, voluntary = 1 WHERE room_id = ? AND member_id = ?",
            [time(), $room['id'], $me['id']]);
        log_room($pdo, (int) $room['id'], $row['pseudo'] . ' laisse sa place et passe en réserve (volontaire).');
        $msg = 'Tu passes en réserve et ta place est libérée.';
        if ($up = fill_from_reserve($pdo, $room)) {
            $msg .= ' ' . names_list($up) . (count($up) > 1 ? ' montent' : ' monte') . ' de la réserve.';
        }
        return $msg;
    });
}

/** Un réserviste volontaire redevient candidat : il monte tout de suite si une place lui convient, sinon dès qu'une se libère. */
function room_step_up(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug);
        $row = q('SELECT rm.*, m.pseudo FROM room_members rm JOIN members m ON m.id = rm.member_id
                  WHERE rm.room_id = ? AND rm.member_id = ?', [$room['id'], $me['id']])->fetch();
        if (!$row) throw new RoomError("Tu n'es pas dans cette salle.");
        if ($row['slot'] === 'main') return 'Tu es déjà dans le groupe.';
        q('UPDATE room_members SET voluntary = 0 WHERE room_id = ? AND member_id = ?', [$room['id'], $me['id']]);
        $up = fill_from_reserve($pdo, $room);
        if (in_array($row['pseudo'], $up, true)) {
            $others = array_values(array_diff($up, [$row['pseudo']]));
            return 'Tu passes dans le groupe.' . ($others ? ' ' . names_list($others) . (count($others) > 1 ? ' montent' : ' monte') . ' aussi de la réserve.' : '');
        }
        log_room($pdo, (int) $room['id'], $row['pseudo'] . ' attend une place dans le groupe.');
        return "Tu monteras dans le groupe dès qu'une place pour ton rôle se libère.";
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
        if ($row['slot'] === 'main' && ($up = fill_from_reserve($pdo, $room))) {
            $msg .= ' ' . names_list($up) . (count($up) > 1 ? ' montent' : ' monte') . ' de la réserve.';
        }
        return $msg;
    });
}

/**
 * Fait monter les joueurs de la réserve, dans l'ordre d'arrivée, sur les places libres de leur rôle
 * (ou ouvertes à tous). Un joueur sans place pour son rôle reste en réserve, les suivants peuvent passer.
 * Les réservistes volontaires sont ignorés.
 */
function fill_from_reserve(PDO $pdo, array $room): array
{
    $promoted = [];
    $slotRoles = room_slot_roles($room);
    $members = room_members_of([(int) $room['id']])[(int) $room['id']];
    $occupied = occupied_positions($members);
    foreach ($members as $m) {
        if ($m['slot'] !== 'reserve' || (int) $m['voluntary']) continue; // réserve volontaire : pas de montée automatique
        $pos = best_free_slot($slotRoles, $occupied, $m['role'] ?: null);
        if ($pos === null) continue;
        q("UPDATE room_members SET slot = 'main', position = ?, slot_since = ?, late = ? WHERE room_id = ? AND member_id = ?",
            [$pos, time(), room_phase($room) === 'late' ? 1 : 0, $room['id'], $m['id']]);
        $occupied[$pos] = $m;
        log_room($pdo, (int) $room['id'], $m['pseudo'] . ' monte de la réserve dans le groupe' . ($slotRoles[$pos] ? ' (place ' . $slotRoles[$pos] . ')' : '') . '.');
        $promoted[] = $m['pseudo'];
    }
    return $promoted;
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
        if ($to === 'reserve' && $counts['reserve'] >= (int) $room['reserve_capacity']) throw new RoomError('La réserve est complète.');
        $pos = null;
        if ($to === 'main') {
            $slotRoles = room_slot_roles($room);
            $occupied = occupied_positions($members);
            $pos = best_free_slot($slotRoles, $occupied, $row['role'] ?: null);
            if ($pos === null) {
                throw new RoomError(count($occupied) < count($slotRoles)
                    ? 'Aucune place libre pour le rôle ' . ($row['role'] ?: 'non renseigné') . '. Glisse-le sur une place précise pour forcer.'
                    : "Le groupe est complet : passe d'abord quelqu'un en réserve.");
            }
        }

        q('UPDATE room_members SET slot = ?, position = ?, slot_since = ?, late = 0, voluntary = 0 WHERE room_id = ? AND member_id = ?', [$to, $pos, time(), $room['id'], $memberId]);
        $label = $to === 'main' ? 'passe dans le groupe' : 'passe en réserve';
        log_room($pdo, (int) $room['id'], $row['pseudo'] . ' ' . $label . ' (' . $me['pseudo'] . ').');
        return $row['pseudo'] . ' ' . $label . '.';
    });
}

/**
 * Le chef de salle place un joueur (du groupe ou de la réserve) sur une place précise.
 * Place occupée : les deux joueurs échangent (celui qui sort du groupe passe en tête de réserve).
 * Le rôle de la place n'est pas imposé ici : le chef peut forcer.
 */
function room_place(string $slug, int $memberId, int $position, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $memberId, $position, $me) {
        $room = locked_room($slug, $me, true);
        if ($position < 0 || $position >= (int) $room['capacity']) throw new RoomError('Place inconnue.');
        $members = room_members_of([(int) $room['id']])[(int) $room['id']];
        $row = null;
        foreach ($members as $m) {
            if ((int) $m['id'] === $memberId) $row = $m;
        }
        if (!$row) throw new RoomError("Ce joueur n'est pas dans la salle.");
        $occupied = occupied_positions($members);
        $from = $row['slot'] === 'main' ? (int) $row['position'] : null;
        if ($from === $position) return 'Rien à changer.';
        $other = $occupied[$position] ?? null;
        $n = $position + 1;

        // Libère la place d'origine pour respecter l'unicité des places pendant l'échange
        q('UPDATE room_members SET position = NULL WHERE room_id = ? AND member_id = ?', [$room['id'], $memberId]);
        if ($other && $from !== null) {
            q('UPDATE room_members SET position = ? WHERE room_id = ? AND member_id = ?', [$from, $room['id'], $other['id']]);
            $msg = "{$row['pseudo']} et {$other['pseudo']} échangent leurs places";
        } elseif ($other) {
            $first = (int) q("SELECT COALESCE(MIN(slot_since), ?) FROM room_members WHERE room_id = ? AND slot = 'reserve' AND member_id <> ?",
                [time(), $room['id'], $memberId])->fetchColumn();
            q("UPDATE room_members SET slot = 'reserve', position = NULL, slot_since = ?, late = 0, voluntary = 0 WHERE room_id = ? AND member_id = ?",
                [$first - 1, $room['id'], $other['id']]);
            $msg = "{$row['pseudo']} prend la place $n, {$other['pseudo']} passe en tête de réserve";
        } else {
            $msg = "{$row['pseudo']} passe en place $n";
        }
        q("UPDATE room_members SET position = ?, slot_since = CASE WHEN slot = 'main' THEN slot_since ELSE ? END,
                                  late = CASE WHEN slot = 'main' THEN late ELSE 0 END, slot = 'main', voluntary = 0
           WHERE room_id = ? AND member_id = ?", [$position, time(), $room['id'], $memberId]);

        $want = room_slot_roles($room)[$position];
        if ($want !== null && $want !== $row['role']) {
            $msg .= " (place $want, hors rôle)";
        }
        log_room($pdo, (int) $room['id'], $msg . ' (' . $me['pseudo'] . ').');
        return $msg . '.';
    });
}

/** Rôle recherché pour une place (null = ouverte à tous). Fait monter un joueur de la réserve s'il convient. */
function room_set_slot_role(string $slug, int $position, ?string $role, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $position, $role, $me) {
        $room = locked_room($slug, $me, true);
        if ($position < 0 || $position >= (int) $room['capacity']) throw new RoomError('Place inconnue.');
        if ($role !== null && !in_array($role, opts('role', null, true), true)) throw new RoomError('Rôle inconnu.');
        q('INSERT INTO room_slots (room_id, position, role) VALUES (?, ?, ?)
           ON CONFLICT(room_id, position) DO UPDATE SET role = excluded.role', [$room['id'], $position, $role]);
        $n = $position + 1;
        $msg = $role ? "Place $n : $role recherché" : "Place $n : ouverte à tous les rôles";
        log_room($pdo, (int) $room['id'], $msg . ' (' . $me['pseudo'] . ').');
        if ($up = fill_from_reserve($pdo, $room)) {
            $msg .= '. ' . names_list($up) . (count($up) > 1 ? ' montent' : ' monte') . ' de la réserve';
        }
        return $msg . '.';
    });
}

function room_extend(string $slug, int $minutes, array $me): string
{
    $minutes = max(5, min(1440, $minutes));
    return tx(function (PDO $pdo) use ($slug, $minutes, $me) {
        $room = locked_room($slug, $me, true);
        if (!room_is_raid($room)) throw new RoomError("Un donjon n'a pas de délai d'inscription.");
        $until = max(time(), (int) $room['join_until']) + $minutes * 60;
        q('UPDATE rooms SET join_until = ? WHERE id = ?', [$until, $room['id']]);
        log_room($pdo, (int) $room['id'], 'Inscriptions prolongées jusqu\'à ' . fmt_when($until) . ' par ' . $me['pseudo'] . '.');
        return 'Inscriptions ouvertes jusqu\'à ' . fmt_when($until) . '.';
    });
}

function room_close_join(string $slug, array $me): string
{
    return tx(function (PDO $pdo) use ($slug, $me) {
        $room = locked_room($slug, $me, true);
        if (!room_is_raid($room)) throw new RoomError("Un donjon n'a pas de délai d'inscription.");
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
        $cleared = $cleared && room_is_raid($room); // le clear de la semaine ne concerne que le raid
        q("UPDATE rooms SET status = 'done', outcome = ?, closed_at = ? WHERE id = ?", [$cleared ? 'clear' : 'no_clear', time(), $room['id']]);
        $msg = 'Salle terminée.';
        if ($cleared) {
            q("UPDATE members SET clear_semaine = 1, updated_at = ?
                           WHERE id IN (SELECT member_id FROM room_members WHERE room_id = ? AND slot = 'main')", [time(), $room['id']]);
            $msg = 'Bravo pour le clear ! Les titulaires sont marqués « clear cette semaine ».';
            if ($left = leave_rooms_after_clear($pdo, $room)) {
                $msg .= ' ' . names_list($left) . (count($left) > 1 ? ' quittent' : ' quitte') . " les autres salles qui n'acceptent pas les joueurs déjà clear.";
            }
        }
        log_room($pdo, (int) $room['id'], ($cleared ? 'Clear réussi' : 'Salle terminée sans clear') . ' (' . $me['pseudo'] . ').');
        return $msg;
    });
}

/**
 * Après un clear : les titulaires de la salle quittent les autres salles actives qui n'acceptent pas les joueurs
 * déjà clear (règle de l'Excel). Leurs places libérées vont aux réservistes du bon rôle. Renvoie les pseudos concernés.
 */
function leave_rooms_after_clear(PDO $pdo, array $room): array
{
    $rows = q("SELECT rm.room_id, rm.member_id, rm.slot, m.pseudo
               FROM room_members rm
               JOIN rooms r ON r.id = rm.room_id AND r.status = 'active' AND r.kind = 'raid' AND r.allow_cleared = 0 AND r.id <> ?
               JOIN members m ON m.id = rm.member_id
               WHERE rm.member_id IN (SELECT member_id FROM room_members WHERE room_id = ? AND slot = 'main')",
        [$room['id'], $room['id']])->fetchAll();
    $who = [];
    $freed = [];
    foreach ($rows as $r) {
        q('DELETE FROM room_members WHERE room_id = ? AND member_id = ?', [$r['room_id'], $r['member_id']]);
        log_room($pdo, (int) $r['room_id'], $r['pseudo'] . ' a clear dans « ' . $room['title'] . ' » et quitte cette salle.');
        $who[$r['pseudo']] = true;
        if ($r['slot'] === 'main') $freed[(int) $r['room_id']] = true;
    }
    foreach (array_keys($freed) as $roomId) {
        if ($other = q('SELECT * FROM rooms WHERE id = ?', [$roomId])->fetch()) fill_from_reserve($pdo, $other);
    }
    return array_keys($who);
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
    $ids = array_map(fn($r) => (int) $r['id'], $rooms);
    $members = room_members_of($ids);
    $slots = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT room_id, position, role FROM room_slots WHERE room_id IN ($in)", $ids)->fetchAll() as $s) {
            $slots[(int) $s['room_id']][(int) $s['position']] = $s['role'] ?: null;
        }
    }
    foreach ($rooms as &$r) {
        $r['members'] = $members[(int) $r['id']];
        $roles = array_fill(0, (int) $r['capacity'], null);
        foreach ($slots[(int) $r['id']] ?? [] as $pos => $role) {
            if ($pos < count($roles)) $roles[$pos] = $role;
        }
        $r['slot_roles'] = $roles;
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
