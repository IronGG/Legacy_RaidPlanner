<?php
declare(strict_types=1);

/*
 * Wars de légion (onglet War).
 *  - Créées toutes seules d'après « war_schedule » (jeudi et samedi 21:00), « war_days_ahead » jours avant.
 *  - Chaque membre répond avec un rôle et un statut (présent, en retard, provisoire, banc, absent),
 *    jusqu'à la fin de la war (« war_length_minutes ») pour les retardataires.
 *  - Les officiers et admins organisent les troupes : groupes de « war_group_size » places, à plusieurs en direct.
 *  - Les autres membres ne voient la compo qu'une fois publiée.
 * Chaque changement incrémente wars.version : les pages ouvertes se mettent à jour (api.php?w=…).
 */

final class WarError extends UserError {}

const WAR_STATUSES = ['present' => 'Présent', 'late' => 'En retard', 'tentative' => 'Provisoire', 'bench' => 'Banc', 'absent' => 'Absent'];
const WAR_STATUS_BADGES = ['late' => 'Retard', 'tentative' => 'Provisoire', 'bench' => 'Banc'];
const WAR_PLACEABLE = ['present', 'late', 'tentative', 'bench']; // tout le monde sauf les absents
const WAR_TROOP_PLAYERS = 20;                                       // taille d'une troupe ajoutée par un officier

function can_manage_war(array $me): bool
{
    return is_officer($me); // officiers et admins
}

function war_length(): int
{
    return max(15, (int) cfg('war_length_minutes')) * 60;
}

function war_group_size(): int
{
    return min(12, max(1, (int) (cfg('war_group_size') ?: 5)));
}

/** Crée les wars prévues d'ici « war_days_ahead » jours (et celle en cours), avec leurs troupes de départ. Sans effet si elles existent. */
function wars_ensure_upcoming(?int $now = null): void
{
    $now ??= time();
    $tz = new DateTimeZone(cfg('timezone'));
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $ahead = max(1, (int) cfg('war_days_ahead'));
    foreach (range(-1, $ahead) as $d) {
        $day = $today->modify(sprintf('%+d day', $d));
        foreach ((array) cfg('war_schedule') as $entry) {
            [$weekday, $time] = array_values((array) $entry) + [null, null];
            if ((int) $day->format('N') !== (int) $weekday || !preg_match('/^(\d{1,2}):(\d{2})$/', (string) $time, $t)) continue;
            $ts = $day->setTime((int) $t[1], (int) $t[2])->getTimestamp();
            if ($ts + war_length() <= $now || $ts > $now + $ahead * 86400) continue;
            if (q('SELECT 1 FROM wars WHERE starts_at = ?', [$ts])->fetchColumn()) continue;
            tx(function () use ($ts) {
                if (q('SELECT 1 FROM wars WHERE starts_at = ?', [$ts])->fetchColumn()) return; // créée entre-temps par une autre visite
                q('INSERT INTO wars (starts_at, updated_at, created_at) VALUES (?, ?, ?)', [$ts, time(), time()]);
                war_default_layout((int) db()->lastInsertId());
            });
        }
    }
}

/** Troupes et groupes de départ (« war_troops » dans config.php). */
function war_default_layout(int $warId): void
{
    foreach (array_values((array) cfg('war_troops')) as $i => $t) {
        [$name, $groups] = array_values((array) $t) + ['Troupe ' . ($i + 1), 4];
        war_insert_troop($warId, $i, (string) $name, (int) $groups, war_group_size());
    }
}

function war_insert_troop(int $warId, int $position, string $name, int $groups, int $size): int
{
    q('INSERT INTO war_troops (war_id, position, name) VALUES (?, ?, ?)', [$warId, $position, mb_substr(trim($name) ?: 'Troupe', 0, 40)]);
    $tid = (int) db()->lastInsertId();
    for ($g = 0; $g < max(1, min(20, $groups)); $g++) {
        q('INSERT INTO war_groups (troop_id, position, size) VALUES (?, ?, ?)', [$tid, $g, $size]);
    }
    return $tid;
}

function war_by_id(int $id): ?array
{
    return q('SELECT * FROM wars WHERE id = ?', [$id])->fetch() ?: null;
}

/** 'upcoming' (à venir), 'live' (en cours), 'past' (terminée) ou 'cancelled'. */
function war_phase(array $war, ?int $now = null): string
{
    $now ??= time();
    if ((int) $war['cancelled']) return 'cancelled';
    if ($now < (int) $war['starts_at']) return 'upcoming';
    return $now < (int) $war['starts_at'] + war_length() ? 'live' : 'past';
}

/** Un membre répond lui-même jusqu'à la fin de la war (retardataires compris), si elle n'est pas annulée. */
function war_signup_open(array $war): bool
{
    return in_array(war_phase($war), ['upcoming', 'live'], true);
}

/** Wars à venir ou en cours (dans l'ordre), puis les dernières passées. */
function wars_for_index(int $pastLimit = 8): array
{
    $now = time();
    return [
        q('SELECT * FROM wars WHERE starts_at + ? > ? ORDER BY starts_at', [war_length(), $now])->fetchAll(),
        q('SELECT * FROM wars WHERE starts_at + ? <= ? ORDER BY starts_at DESC LIMIT ?', [war_length(), $now, $pastLimit])->fetchAll(),
    ];
}

/** Réponses à une war, avec le profil de chaque membre. */
function war_signups(int $warId): array
{
    return q("SELECT s.member_id, s.role, s.status, s.signed_at, s.updated_at,
                     m.id, m.pseudo, m.classe, m.gs, m.cp, m.is_officer, m.is_raid_lead, m.is_leader
              FROM war_signups s JOIN members m ON m.id = s.member_id
              WHERE s.war_id = ?
              ORDER BY s.signed_at, m.pseudo COLLATE NOCASE", [$warId])->fetchAll();
}

function war_signup_of(int $warId, int $memberId): ?array
{
    return q('SELECT * FROM war_signups WHERE war_id = ? AND member_id = ?', [$warId, $memberId])->fetch() ?: null;
}

/** Nombre de réponses par statut. */
function war_counts(array $signups): array
{
    $c = array_fill_keys(array_keys(WAR_STATUSES), 0);
    foreach ($signups as $s) $c[$s['status']]++;
    $c['in'] = $c['present'] + $c['late'] + $c['tentative'];
    return $c;
}

/** Comptes de plusieurs wars d'un coup (page de liste). */
function war_counts_for(array $warIds): array
{
    $out = [];
    foreach ($warIds as $id) $out[$id] = war_counts([]);
    if (!$warIds) return $out;
    $in = implode(',', array_fill(0, count($warIds), '?'));
    foreach (q("SELECT war_id, status, COUNT(*) AS n FROM war_signups WHERE war_id IN ($in) GROUP BY war_id, status", array_values($warIds))->fetchAll() as $r) {
        $out[(int) $r['war_id']][$r['status']] = (int) $r['n'];
    }
    foreach ($out as &$c) $c['in'] = $c['present'] + $c['late'] + $c['tentative'];
    return $out;
}

/** Membres qui n'ont pas encore répondu. */
function war_no_answer(int $warId): array
{
    return q('SELECT id, pseudo, classe, role FROM members
              WHERE id NOT IN (SELECT member_id FROM war_signups WHERE war_id = ?)
              ORDER BY pseudo COLLATE NOCASE', [$warId])->fetchAll();
}

/**
 * Troupes, groupes et places d'une war.
 * Renvoie ['troops' => [ ['id','name','groups' => [ ['id','number','size','slots' => [slot => member_id]] ]] ],
 *          'placed' => [member_id => ['troop' => nom, 'group' => numéro, 'group_id' => id, 'slot' => n]]].
 * Les groupes sont numérotés à la suite sur toutes les troupes (Groupe 1, 2, 3…), comme sur les captures de la légion.
 */
function war_layout(int $warId): array
{
    $troops = [];
    foreach (q('SELECT * FROM war_troops WHERE war_id = ? ORDER BY position, id', [$warId])->fetchAll() as $t) {
        $troops[(int) $t['id']] = $t + ['groups' => []];
    }
    $groups = q('SELECT g.* FROM war_groups g JOIN war_troops t ON t.id = g.troop_id
                 WHERE t.war_id = ? ORDER BY t.position, t.id, g.position, g.id', [$warId])->fetchAll();
    $byGroup = [];
    foreach (q('SELECT group_id, slot, member_id FROM war_places WHERE war_id = ?', [$warId])->fetchAll() as $p) {
        $byGroup[(int) $p['group_id']][(int) $p['slot']] = (int) $p['member_id'];
    }
    $placed = [];
    $n = 0;
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $g['number'] = ++$n;
        $g['slots'] = $byGroup[$gid] ?? [];
        $troops[(int) $g['troop_id']]['groups'][] = $g;
        foreach ($g['slots'] as $slot => $mid) {
            $placed[$mid] = ['troop' => $troops[(int) $g['troop_id']]['name'], 'group' => $n, 'group_id' => $gid, 'slot' => $slot];
        }
    }
    return ['troops' => array_values($troops), 'placed' => $placed];
}

/** Numéro affiché d'un groupe (Groupe N). */
function war_group_number(int $warId, int $groupId): int
{
    foreach (war_layout($warId)['troops'] as $t) {
        foreach ($t['groups'] as $g) {
            if ((int) $g['id'] === $groupId) return (int) $g['number'];
        }
    }
    return 0;
}

/** Nouvelle version : les pages ouvertes se mettent à jour, et on sait qui a fait le dernier changement. */
function war_touch(int $warId, ?array $me): void
{
    q('UPDATE wars SET version = version + 1, updated_at = ?, updated_by = ? WHERE id = ?', [time(), $me['pseudo'] ?? null, $warId]);
}

/** War lue dans une transaction d'écriture (tx) ; $manage : réservé aux officiers. */
function locked_war(int $warId, ?array $me = null, bool $manage = false): array
{
    $war = war_by_id($warId);
    if (!$war) throw new WarError("Cette war n'existe pas.");
    if ($manage && (!$me || !can_manage_war($me))) throw new WarError('Seuls les officiers et les admins organisent les wars.');
    return $war;
}

function war_group_of(int $warId, int $groupId): array
{
    $g = q('SELECT g.*, t.war_id, t.name AS troop_name FROM war_groups g JOIN war_troops t ON t.id = g.troop_id WHERE g.id = ?', [$groupId])->fetch();
    if (!$g || (int) $g['war_id'] !== $warId) throw new WarError('Ce groupe n\'existe plus. La page va se mettre à jour.');
    return $g;
}

function war_troop_of(int $warId, int $troopId): array
{
    $t = q('SELECT * FROM war_troops WHERE id = ? AND war_id = ?', [$troopId, $warId])->fetch();
    if (!$t) throw new WarError('Cette troupe n\'existe plus. La page va se mettre à jour.');
    return $t;
}

/**
 * Réponse d'un membre (rôle + statut). $me agit ; $member répond (un officier peut répondre pour un membre).
 * Un membre qui passe « Absent » quitte sa place dans la compo.
 */
function war_signup(int $warId, array $member, ?string $role, string $status, array $me): string
{
    return tx(function () use ($warId, $member, $role, $status, $me) {
        $self = (int) $member['id'] === (int) $me['id'];
        $war = locked_war($warId, $me, !$self);
        if (!isset(WAR_STATUSES[$status])) throw new WarError('Réponse inconnue.');
        if ((int) $war['cancelled']) throw new WarError('Cette war est annulée.');
        if ($self && !war_signup_open($war)) throw new WarError('Cette war est terminée : les réponses sont closes.');
        if ($role !== null && !in_array($role, opts('role', null, true), true)) throw new WarError('Rôle inconnu.');
        $prev = war_signup_of($warId, (int) $member['id']);
        $role ??= $prev['role'] ?? ($member['role'] ?: null);
        $now = time();
        if ($prev) {
            q('UPDATE war_signups SET role = ?, status = ?, updated_at = ? WHERE war_id = ? AND member_id = ?', [$role, $status, $now, $warId, $member['id']]);
        } else {
            q('INSERT INTO war_signups (war_id, member_id, role, status, signed_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$warId, $member['id'], $role, $status, $now, $now]);
        }
        $msg = ($self ? 'Ta réponse : ' : $member['pseudo'] . ' : ') . WAR_STATUSES[$status] . ($role && $status !== 'absent' ? ', ' . $role : '');
        if ($status === 'absent' && q('DELETE FROM war_places WHERE war_id = ? AND member_id = ?', [$warId, $member['id']])->rowCount()) {
            $msg .= $self ? '. Ta place dans la compo est libérée' : '. Sa place dans la compo est libérée';
        }
        war_touch($warId, $me);
        return $msg . '.';
    });
}

/** Place un joueur inscrit ; sur une place occupée, les deux échangent (ou l'autre repasse « à placer »). */
function war_place(int $warId, int $memberId, int $groupId, int $slot, array $me): string
{
    return tx(function () use ($warId, $memberId, $groupId, $slot, $me) {
        locked_war($warId, $me, true);
        $s = war_signup_of($warId, $memberId);
        if (!$s) throw new WarError("Ce joueur n'a pas répondu à cette war.");
        if (!in_array($s['status'], WAR_PLACEABLE, true)) throw new WarError('Ce joueur a répondu absent à cette war.');
        $g = war_group_of($warId, $groupId);
        if ($slot < 0 || $slot >= (int) $g['size']) throw new WarError('Cette place n\'existe plus. La page va se mettre à jour.');
        $from = q('SELECT group_id, slot FROM war_places WHERE war_id = ? AND member_id = ?', [$warId, $memberId])->fetch() ?: null;
        if ($from && (int) $from['group_id'] === $groupId && (int) $from['slot'] === $slot) return 'Rien à changer.';
        $other = q('SELECT member_id FROM war_places WHERE group_id = ? AND slot = ?', [$groupId, $slot])->fetchColumn();
        $name = fn(int $id) => (string) q('SELECT pseudo FROM members WHERE id = ?', [$id])->fetchColumn();
        q('DELETE FROM war_places WHERE war_id = ? AND member_id = ?', [$warId, $memberId]);
        if ($other) {
            q('DELETE FROM war_places WHERE group_id = ? AND slot = ?', [$groupId, $slot]);
            if ($from) {
                q('INSERT INTO war_places (war_id, group_id, slot, member_id) VALUES (?, ?, ?, ?)', [$warId, $from['group_id'], $from['slot'], $other]);
                $msg = $name($memberId) . ' et ' . $name((int) $other) . ' échangent leurs places';
            } else {
                $msg = $name($memberId) . ' prend la place de ' . $name((int) $other) . ', qui repasse dans « À placer »';
            }
        }
        q('INSERT INTO war_places (war_id, group_id, slot, member_id) VALUES (?, ?, ?, ?)', [$warId, $groupId, $slot, $memberId]);
        war_touch($warId, $me);
        return ($msg ?? $name($memberId) . ' placé·e dans le groupe ' . war_group_number($warId, $groupId)) . '.';
    });
}

function war_unplace(int $warId, int $memberId, array $me): string
{
    return tx(function () use ($warId, $memberId, $me) {
        locked_war($warId, $me, true);
        if (!q('DELETE FROM war_places WHERE war_id = ? AND member_id = ?', [$warId, $memberId])->rowCount()) return 'Rien à changer.';
        war_touch($warId, $me);
        return q('SELECT pseudo FROM members WHERE id = ?', [$memberId])->fetchColumn() . ' repasse dans « À placer ».';
    });
}

function war_group_add(int $warId, int $troopId, array $me): string
{
    return tx(function () use ($warId, $troopId, $me) {
        locked_war($warId, $me, true);
        $t = war_troop_of($warId, $troopId);
        if ((int) q('SELECT COUNT(*) FROM war_groups WHERE troop_id = ?', [$troopId])->fetchColumn() >= 20) throw new WarError('20 groupes au plus par troupe.');
        $pos = (int) q('SELECT COALESCE(MAX(position), -1) + 1 FROM war_groups WHERE troop_id = ?', [$troopId])->fetchColumn();
        q('INSERT INTO war_groups (troop_id, position, size) VALUES (?, ?, ?)', [$troopId, $pos, war_group_size()]);
        war_touch($warId, $me);
        return "Groupe ajouté à « {$t['name']} ».";
    });
}

/** Une place de plus ou de moins dans un groupe ; on ne retire que la dernière place, et seulement si elle est libre. */
function war_group_resize(int $warId, int $groupId, int $delta, array $me): string
{
    return tx(function () use ($warId, $groupId, $delta, $me) {
        locked_war($warId, $me, true);
        $g = war_group_of($warId, $groupId);
        $size = (int) $g['size'] + ($delta > 0 ? 1 : -1);
        if ($size < 1) throw new WarError('Un groupe garde au moins une place. Supprime-le plutôt.');
        if ($size > 12) throw new WarError('12 places au plus par groupe.');
        if ($delta < 0 && q('SELECT 1 FROM war_places WHERE group_id = ? AND slot >= ?', [$groupId, $size])->fetchColumn()) {
            throw new WarError('La dernière place du groupe est occupée : déplace d\'abord ce joueur.');
        }
        q('UPDATE war_groups SET size = ? WHERE id = ?', [$size, $groupId]);
        war_touch($warId, $me);
        return 'Groupe ' . war_group_number($warId, $groupId) . " : $size places.";
    });
}

function war_group_delete(int $warId, int $groupId, array $me): string
{
    return tx(function () use ($warId, $groupId, $me) {
        locked_war($warId, $me, true);
        war_group_of($warId, $groupId);
        $n = war_group_number($warId, $groupId);
        $freed = (int) q('SELECT COUNT(*) FROM war_places WHERE group_id = ?', [$groupId])->fetchColumn();
        q('DELETE FROM war_groups WHERE id = ?', [$groupId]); // ses places partent avec lui (ON DELETE CASCADE)
        war_touch($warId, $me);
        return "Groupe $n supprimé" . ($freed ? ', ' . plural($freed, 'joueur') . ' à replacer' : '') . '.';
    });
}

function war_troop_add(int $warId, array $me): string
{
    return tx(function () use ($warId, $me) {
        locked_war($warId, $me, true);
        $count = (int) q('SELECT COUNT(*) FROM war_troops WHERE war_id = ?', [$warId])->fetchColumn();
        if ($count >= 10) throw new WarError('10 troupes au plus.');
        $pos = (int) q('SELECT COALESCE(MAX(position), -1) + 1 FROM war_troops WHERE war_id = ?', [$warId])->fetchColumn();
        $name = 'Troupe ' . ($count + 1);
        war_insert_troop($warId, $pos, $name, (int) ceil(WAR_TROOP_PLAYERS / war_group_size()), war_group_size());
        war_touch($warId, $me);
        return "« $name » ajoutée.";
    });
}

function war_troop_rename(int $warId, int $troopId, string $name, array $me): string
{
    return tx(function () use ($warId, $troopId, $name, $me) {
        locked_war($warId, $me, true);
        war_troop_of($warId, $troopId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) throw new WarError('Le nom de la troupe doit faire 1 à 40 caractères.');
        q('UPDATE war_troops SET name = ? WHERE id = ?', [$name, $troopId]);
        war_touch($warId, $me);
        return "Troupe renommée « $name ».";
    });
}

function war_troop_delete(int $warId, int $troopId, array $me): string
{
    return tx(function () use ($warId, $troopId, $me) {
        locked_war($warId, $me, true);
        $t = war_troop_of($warId, $troopId);
        $freed = (int) q('SELECT COUNT(*) FROM war_places p JOIN war_groups g ON g.id = p.group_id WHERE g.troop_id = ?', [$troopId])->fetchColumn();
        q('DELETE FROM war_troops WHERE id = ?', [$troopId]); // groupes et places supprimés avec elle
        war_touch($warId, $me);
        return "« {$t['name']} » supprimée" . ($freed ? ', ' . plural($freed, 'joueur') . ' à replacer' : '') . '.';
    });
}

/** Monte ou descend une troupe d'un cran (l'ordre fixe la numérotation des groupes). */
function war_troop_move(int $warId, int $troopId, int $dir, array $me): string
{
    return tx(function () use ($warId, $troopId, $dir, $me) {
        locked_war($warId, $me, true);
        war_troop_of($warId, $troopId);
        $ids = q('SELECT id FROM war_troops WHERE war_id = ? ORDER BY position, id', [$warId])->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($troopId, array_map('intval', $ids), true);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) return 'Rien à changer.';
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach (array_values($ids) as $pos => $id) q('UPDATE war_troops SET position = ? WHERE id = ?', [$pos, $id]);
        war_touch($warId, $me);
        return 'Ordre des troupes changé.';
    });
}

function war_set_published(int $warId, bool $on, array $me): string
{
    return tx(function () use ($warId, $on, $me) {
        locked_war($warId, $me, true);
        q('UPDATE wars SET published_at = ? WHERE id = ?', [$on ? time() : null, $warId]);
        war_touch($warId, $me);
        return $on ? 'Compo publiée : les membres voient leur place.' : 'Compo masquée aux membres.';
    });
}

function war_set_cancelled(int $warId, bool $on, array $me): string
{
    return tx(function () use ($warId, $on, $me) {
        locked_war($warId, $me, true);
        q('UPDATE wars SET cancelled = ? WHERE id = ?', [$on ? 1 : 0, $warId]);
        war_touch($warId, $me);
        return $on ? 'War annulée.' : 'War rétablie.';
    });
}

function war_set_message(int $warId, string $message, array $me): string
{
    return tx(function () use ($warId, $message, $me) {
        locked_war($warId, $me, true);
        q('UPDATE wars SET message = ? WHERE id = ?', [$message === '' ? null : $message, $warId]);
        war_touch($warId, $me);
        return $message === '' ? 'Message retiré.' : 'Message enregistré.';
    });
}

function war_clear_places(int $warId, array $me): string
{
    return tx(function () use ($warId, $me) {
        locked_war($warId, $me, true);
        $n = q('DELETE FROM war_places WHERE war_id = ?', [$warId])->rowCount();
        war_touch($warId, $me);
        return $n ? plural($n, 'joueur') . ' remis dans « À placer ».' : 'Personne n\'était placé.';
    });
}

/** Dernière war avant celle-ci qui a une compo (au moins un joueur placé). */
function war_previous_with_places(array $war): ?array
{
    return q('SELECT w.* FROM wars w WHERE w.starts_at < ? AND EXISTS (SELECT 1 FROM war_places p WHERE p.war_id = w.id)
              ORDER BY w.starts_at DESC LIMIT 1', [$war['starts_at']])->fetch() ?: null;
}

/**
 * Reprend les troupes, groupes et places de la war précédente. Les joueurs qui n'ont pas répondu présent
 * (ou en retard, provisoire, banc) à cette war restent hors compo : leur place est laissée vide.
 */
function war_copy_previous(int $warId, array $me): string
{
    return tx(function () use ($warId, $me) {
        $war = locked_war($warId, $me, true);
        $prev = war_previous_with_places($war);
        if (!$prev) throw new WarError('Aucune war précédente n\'a de compo à reprendre.');
        $signed = [];
        foreach (war_signups($warId) as $s) {
            if (in_array($s['status'], WAR_PLACEABLE, true)) $signed[(int) $s['member_id']] = true;
        }
        q('DELETE FROM war_troops WHERE war_id = ?', [$warId]); // groupes et places partent avec
        $placed = 0;
        $missing = 0;
        foreach (war_layout((int) $prev['id'])['troops'] as $tpos => $t) {
            q('INSERT INTO war_troops (war_id, position, name) VALUES (?, ?, ?)', [$warId, $tpos, $t['name']]);
            $tid = (int) db()->lastInsertId();
            foreach ($t['groups'] as $gpos => $g) {
                q('INSERT INTO war_groups (troop_id, position, size) VALUES (?, ?, ?)', [$tid, $gpos, $g['size']]);
                $gid = (int) db()->lastInsertId();
                foreach ($g['slots'] as $slot => $mid) {
                    if (isset($signed[$mid])) {
                        q('INSERT INTO war_places (war_id, group_id, slot, member_id) VALUES (?, ?, ?, ?)', [$warId, $gid, $slot, $mid]);
                        $placed++;
                    } else {
                        $missing++;
                    }
                }
            }
        }
        war_touch($warId, $me);
        return 'Compo de la war du ' . fmt_dt($prev['starts_at']) . ' reprise : ' . plural($placed, 'joueur') . ' replacé' . ($placed > 1 ? 's' : '')
            . ($missing ? ', ' . $missing . ' place' . ($missing > 1 ? 's' : '') . ' laissée' . ($missing > 1 ? 's' : '') . ' vide' . ($missing > 1 ? 's' : '') . ' (absents ou sans réponse à cette war)' : '') . '.';
    });
}

function war_version(int $warId): string
{
    return (string) (q('SELECT version FROM wars WHERE id = ?', [$warId])->fetchColumn() ?: '0');
}
