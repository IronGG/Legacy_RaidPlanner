<?php
declare(strict_types=1);

/*
 * Reset hebdomadaire automatique : « clear cette semaine » repasse à non pour toute la légion,
 * chaque semaine au jour et à l'heure réglés dans config.php (mercredi 10:00 par défaut).
 * Il s'exécute au début de la première requête qui suit ce moment, avant tout le reste :
 * un clear validé après l'heure du reset ne peut donc jamais être effacé par erreur.
 */

function weekly_reset_enabled(): bool
{
    return (int) cfg('weekly_reset_day') >= 1 && (int) cfg('weekly_reset_day') <= 7;
}

/** Dernier moment de reset prévu, au plus tard maintenant (fuseau de config.php, heure d'été comprise). */
function weekly_reset_moment(?int $now = null): int
{
    $now ??= time();
    $d = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(cfg('timezone')));
    [$h, $m] = array_map('intval', explode(':', (string) cfg('weekly_reset_time')) + [0, 0]);
    $back = ((int) $d->format('N') - (int) cfg('weekly_reset_day') + 7) % 7;
    $moment = $d->modify("-$back days")->setTime($h, $m);
    if ($moment->getTimestamp() > $now) {
        $moment = $moment->modify('-7 days')->setTime($h, $m);
    }
    return $moment->getTimestamp();
}

function weekly_reset_next(): int
{
    $last = (new DateTimeImmutable('@' . weekly_reset_moment()))->setTimezone(new DateTimeZone(cfg('timezone')));
    [$h, $m] = array_map('intval', explode(':', (string) cfg('weekly_reset_time')) + [0, 0]);
    return $last->modify('+7 days')->setTime($h, $m)->getTimestamp();
}

function weekly_reset_if_due(): void
{
    if (!weekly_reset_enabled()) return;
    $due = weekly_reset_moment();
    $last = kv_get('weekly_reset_last');
    if ($last === '') {
        // Première fois (installation, mise à jour) : on considère le dernier reset prévu comme fait,
        // pour ne pas effacer les clears de la semaine en cours.
        q("INSERT OR IGNORE INTO kv (key, value) VALUES ('weekly_reset_last', ?)", [(string) $due]);
        return;
    }
    if ((int) $last >= $due) return; // cas courant : simple lecture, aucun verrou d'écriture
    tx(function () use ($due) {
        // Une seule requête fait le reset, même si plusieurs arrivent en même temps
        if (q("UPDATE kv SET value = ? WHERE key = 'weekly_reset_last' AND CAST(value AS INTEGER) < ?", [(string) $due, $due])->rowCount() !== 1) {
            return;
        }
        $n = q('UPDATE members SET clear_semaine = 0, updated_at = ? WHERE clear_semaine = 1', [time()])->rowCount();
        kv_set('weekly_reset_info', fmt_dt($due) . ' : ' . $n . ' joueur' . ($n > 1 ? 's' : '') . ' remis à « pas encore clear »');
    });
}
