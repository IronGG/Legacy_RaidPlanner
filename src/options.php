<?php
declare(strict_types=1);

// Gestion des listes déroulantes depuis Officiers > Listes.

const MAX_ICON_BYTES = 300 * 1024;

function option_find(string $list, int $id): array
{
    $o = q('SELECT * FROM options WHERE id = ? AND list = ?', [$id, $list])->fetch();
    if (!$o) throw new UserError('Option introuvable. Recharge la page.');
    return $o;
}

function option_value_input(): string
{
    $v = in_str('value', 40);
    if ($v === '') throw new UserError('Le nom ne peut pas être vide.');
    return $v;
}

function option_taken(string $list, string $value, int $exceptId = 0): bool
{
    return (bool) q('SELECT 1 FROM options WHERE list = ? AND value = ? COLLATE NOCASE AND id <> ?', [$list, $value, $exceptId])->fetchColumn();
}

/** Icône envoyée par un officier : images matricielles seulement (pas de SVG, qui peut contenir du script). */
function save_uploaded_icon(): ?string
{
    $f = $_FILES['icon_upload'] ?? null;
    if (!$f || (int) $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new UserError("L'envoi de l'image a échoué.");
    if ((int) $f['size'] > MAX_ICON_BYTES) throw new UserError('Image trop lourde : 300 Ko maximum.');
    $type = @getimagesize($f['tmp_name'])[2] ?? 0;
    $ext = match ($type) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', default => null };
    if (!$ext) throw new UserError('Formats acceptés pour une icône : PNG, JPG, WebP ou GIF.');
    $dir = APP_ROOT . '/public/assets/classes/custom';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new UserError("Impossible de créer le dossier des icônes sur le serveur.");
    $name = bin2hex(random_bytes(6)) . '.' . $ext; // nom et extension choisis par l'app, jamais par l'utilisateur
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new UserError("Impossible d'enregistrer l'image sur le serveur.");
    return 'custom/' . $name;
}

/** Champs propres à certaines listes : icône des classes, couleur et alerte des rôles. */
function option_extras(string $list, array $current = []): array
{
    $x = ['icon' => $current['icon'] ?? null, 'color' => $current['color'] ?? null, 'required' => (int) ($current['required'] ?? 0)];
    if ($list === 'classe') {
        $picked = in_str('icon', 80);
        $x['icon'] = save_uploaded_icon() ?? (valid_icon_name($picked) ? $picked : ($x['icon'] ?: FALLBACK_ICON));
    }
    if ($list === 'role') {
        $color = in_str('color', 7);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $x['color'] = strtolower($color);
        $x['required'] = in_bool('required');
    }
    return $x;
}

function option_add(string $list): string
{
    $value = option_value_input();
    if (option_taken($list, $value)) throw new UserError("« $value » existe déjà dans cette liste.");
    $x = option_extras($list);
    $pos = (int) q('SELECT COALESCE(MAX(position), -1) + 1 FROM options WHERE list = ?', [$list])->fetchColumn();
    q('INSERT INTO options (list, value, position, active, icon, color, required, updated_at) VALUES (?,?,?,1,?,?,?,?)',
        [$list, $value, $pos, $x['icon'], $x['color'], $x['required'], time()]);
    return "« $value » ajouté.";
}

/** Enregistre une option. Un renommage met à jour les profils et les salles qui utilisent l'ancien nom. */
function option_save(string $list, int $id): string
{
    return tx(function () use ($list, $id) {
        $o = option_find($list, $id);
        $value = option_value_input();
        $updated = 0;
        if ($value !== $o['value']) {
            if (option_taken($list, $value, $id)) throw new UserError("« $value » existe déjà dans cette liste.");
            foreach (OPTION_LISTS[$list]['columns'] as [$table, $col]) {
                $updated += q("UPDATE $table SET $col = ? WHERE $col = ?", [$value, $o['value']])->rowCount();
            }
        }
        $x = option_extras($list, $o);
        q('UPDATE options SET value = ?, active = ?, icon = ?, color = ?, required = ?, updated_at = ? WHERE id = ?',
            [$value, in_bool('active'), $x['icon'], $x['color'], $x['required'], time(), $id]);
        return $value !== $o['value']
            ? "« {$o['value']} » renommé en « $value »" . ($updated ? " ($updated profil" . ($updated > 1 ? 's' : '') . ' ou salle' . ($updated > 1 ? 's' : '') . ' mis à jour).' : '.')
            : "« $value » enregistré.";
    });
}

function option_move(string $list, int $id, string $dir): string
{
    return tx(function () use ($list, $id, $dir) {
        $ids = array_map('intval', q('SELECT id FROM options WHERE list = ? ORDER BY position, id', [$list])->fetchAll(PDO::FETCH_COLUMN));
        $i = array_search($id, $ids, true);
        if ($i === false) throw new UserError('Option introuvable. Recharge la page.');
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        foreach ($ids as $pos => $oid) {
            q('UPDATE options SET position = ?, updated_at = ? WHERE id = ?', [$pos, time(), $oid]);
        }
        return 'Ordre mis à jour.';
    });
}

function option_delete(string $list, int $id): string
{
    return tx(function () use ($list, $id) {
        $o = option_find($list, $id);
        $used = option_usage($list)[$o['value']] ?? 0;
        if ($used > 0) {
            throw new UserError("« {$o['value']} » est utilisé par $used profil" . ($used > 1 ? 's' : '') . " ou salle" . ($used > 1 ? 's' : '')
                . ". Décoche « Proposée » pour le masquer, ou renomme-le.");
        }
        if ((int) q('SELECT COUNT(*) FROM options WHERE list = ?', [$list])->fetchColumn() <= 1) {
            throw new UserError('Une liste doit garder au moins une option.');
        }
        q('DELETE FROM options WHERE id = ?', [$id]);
        // Supprime l'icône envoyée si plus aucune classe ne l'utilise
        if ($o['icon'] && str_starts_with($o['icon'], 'custom/') && valid_icon_name($o['icon'])
            && !q('SELECT 1 FROM options WHERE icon = ?', [$o['icon']])->fetchColumn()) {
            @unlink(APP_ROOT . '/public/assets/classes/' . $o['icon']);
        }
        return "« {$o['value']} » supprimé.";
    });
}

/** Ajoute aux listes toute valeur utilisée par un profil ou une salle mais absente des listes (après un import). */
function options_add_used_values(): int
{
    $added = 0;
    foreach (OPTION_LISTS as $list => $def) {
        $pos = (int) q('SELECT COALESCE(MAX(position), -1) + 1 FROM options WHERE list = ?', [$list])->fetchColumn();
        foreach ($def['columns'] as [$table, $col]) {
            foreach (q("SELECT DISTINCT $col FROM $table WHERE $col IS NOT NULL AND $col <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $n = q('INSERT OR IGNORE INTO options (list, value, position, icon, updated_at) VALUES (?,?,?,?,?)',
                    [$list, $v, $pos, $list === 'classe' ? FALLBACK_ICON : null, time()])->rowCount();
                $pos += $n;
                $added += $n;
            }
        }
    }
    all_options(true);
    return $added;
}
