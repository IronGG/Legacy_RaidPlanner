<?php
declare(strict_types=1);

const MEMBER_FIELDS = [
    'pseudo', 'classe', 'role', 'gs', 'cp', 'connaissance', 'meilleure_etape', 'objectif', 'jours',
    'creneau', 'helper', 'inscription', 'clear_semaine', 'shot_caller', 'notes', 'verifie',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = cfg('db_path');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

/** Schéma versionné via PRAGMA user_version : ajoute une étape pour chaque évolution. */
function migrate(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec(<<<SQL
            CREATE TABLE members (
                id                 INTEGER PRIMARY KEY,
                pseudo             TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash      TEXT,
                activation_hash    TEXT,
                activation_expires INTEGER,
                is_officer         INTEGER NOT NULL DEFAULT 0,
                classe             TEXT NOT NULL DEFAULT 'Autre',
                role               TEXT,
                gs                 INTEGER,
                cp                 INTEGER,
                connaissance       TEXT,
                meilleure_etape    TEXT,
                objectif           TEXT,
                jours              TEXT NOT NULL DEFAULT '',
                creneau            TEXT,
                helper             INTEGER NOT NULL DEFAULT 0,
                inscription        TEXT NOT NULL DEFAULT 'À confirmer',
                clear_semaine      INTEGER NOT NULL DEFAULT 0,
                shot_caller        INTEGER NOT NULL DEFAULT 0,
                notes              TEXT,
                verifie            INTEGER NOT NULL DEFAULT 0,
                created_at         INTEGER NOT NULL,
                updated_at         INTEGER NOT NULL
            );
            CREATE TABLE rooms (
                id               INTEGER PRIMARY KEY,
                slug             TEXT NOT NULL UNIQUE,
                title            TEXT NOT NULL,
                etape            TEXT,
                objectif         TEXT,
                leader_id        INTEGER REFERENCES members(id) ON DELETE SET NULL,
                capacity         INTEGER NOT NULL,
                reserve_capacity INTEGER NOT NULL,
                starts_at        INTEGER,
                join_opened_at   INTEGER NOT NULL,
                join_until       INTEGER NOT NULL,
                min_gs           INTEGER,
                allow_cleared    INTEGER NOT NULL DEFAULT 0,
                notes            TEXT,
                status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','done','cancelled')),
                outcome          TEXT,
                version          INTEGER NOT NULL DEFAULT 1,
                created_at       INTEGER NOT NULL,
                closed_at        INTEGER
            );
            CREATE INDEX rooms_status ON rooms(status);
            CREATE TABLE room_members (
                room_id    INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
                member_id  INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                slot       TEXT NOT NULL CHECK (slot IN ('main','reserve')),
                slot_since INTEGER NOT NULL,
                late       INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (room_id, member_id)
            );
            CREATE INDEX room_members_member ON room_members(member_id);
            CREATE TABLE room_log (
                id      INTEGER PRIMARY KEY,
                room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
                ts      INTEGER NOT NULL,
                message TEXT NOT NULL
            );
            CREATE TABLE auth_attempts (
                ip TEXT NOT NULL,
                ts INTEGER NOT NULL
            );
            CREATE INDEX auth_attempts_ip ON auth_attempts(ip, ts);
        SQL);
        seed_members($pdo);
        $pdo->exec('PRAGMA user_version = 1');
        $pdo->exec('COMMIT');
    }
    if ($version < 2) {
        // v2 : listes déroulantes en base, éditables depuis le site
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec(<<<SQL
            CREATE TABLE options (
                id         INTEGER PRIMARY KEY,
                list       TEXT NOT NULL,
                value      TEXT NOT NULL,
                position   INTEGER NOT NULL DEFAULT 0,
                active     INTEGER NOT NULL DEFAULT 1,
                icon       TEXT,
                color      TEXT,
                required   INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                UNIQUE (list, value COLLATE NOCASE)
            );
        SQL);
        seed_options($pdo);
        $pdo->exec('PRAGMA user_version = 2');
        $pdo->exec('COMMIT');
    }
    if ($version < 3) {
        // v3 : lien avec le personnage en jeu (lecture automatique du GS et du CP)
        $pdo->exec('BEGIN IMMEDIATE');
        foreach ([
            'perso TEXT',             // nom du personnage en jeu, si différent du pseudo
            'aion_char_id TEXT',      // identifiant du personnage sur le site officiel
            'aion_level INTEGER',
            'aion_legion TEXT',       // légion indiquée par la fiche officielle
            'synced_at INTEGER',      // dernière lecture réussie
            'sync_status TEXT',       // ok, not_found, error
            'sync_message TEXT',
            'sync_tried_at INTEGER',  // dernière tentative
        ] as $col) {
            $pdo->exec("ALTER TABLE members ADD COLUMN $col");
        }
        $pdo->exec("CREATE TABLE kv (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $pdo->exec("INSERT INTO kv (key, value) VALUES ('aion_tick', '0')");
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->exec('COMMIT');
    }
    if ($version < 4) {
        // v4 : places numérotées dans le groupe, avec un rôle recherché par place (NULL = tous rôles)
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec(<<<SQL
            CREATE TABLE room_slots (
                room_id  INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
                position INTEGER NOT NULL,
                role     TEXT,
                PRIMARY KEY (room_id, position)
            );
            ALTER TABLE room_members ADD COLUMN position INTEGER;
        SQL);
        foreach ($pdo->query('SELECT id, capacity FROM rooms')->fetchAll() as $r) {
            for ($i = 0; $i < (int) $r['capacity']; $i++) {
                $pdo->prepare('INSERT INTO room_slots (room_id, position) VALUES (?, ?)')->execute([$r['id'], $i]);
            }
            $mains = $pdo->prepare("SELECT member_id FROM room_members WHERE room_id = ? AND slot = 'main' ORDER BY slot_since, rowid");
            $mains->execute([$r['id']]);
            foreach ($mains->fetchAll(PDO::FETCH_COLUMN) as $i => $mid) {
                $pdo->prepare('UPDATE room_members SET position = ? WHERE room_id = ? AND member_id = ?')->execute([$i, $r['id'], $mid]);
            }
        }
        $pdo->exec('CREATE UNIQUE INDEX room_members_position ON room_members(room_id, position) WHERE position IS NOT NULL');
        $pdo->exec('PRAGMA user_version = 4');
        $pdo->exec('COMMIT');
    }
    if ($version < 5) {
        // Réserve volontaire : le joueur ne monte pas automatiquement dans le groupe
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec('ALTER TABLE room_members ADD COLUMN voluntary INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('PRAGMA user_version = 5');
        $pdo->exec('COMMIT');
    }
}

/** Remplit les listes avec les valeurs de l'Excel, plus toute valeur déjà utilisée en base. */
function seed_options(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT OR IGNORE INTO options (list, value, position, icon, color, required, updated_at) VALUES (?,?,?,?,?,?,?)');
    foreach (DEFAULT_OPTIONS as $list => $values) {
        $pos = 0;
        foreach ($values as $v) {
            $st->execute([$list, $v, $pos++, $list === 'classe' ? (DEFAULT_CLASS_ICONS[$v] ?? FALLBACK_ICON) : null,
                $list === 'role' ? (DEFAULT_ROLE_COLORS[$v] ?? null) : null,
                ($list === 'role' && in_array($v, DEFAULT_REQUIRED_ROLES, true)) ? 1 : 0, time()]);
        }
        foreach (OPTION_LISTS[$list]['columns'] as [$table, $col]) {
            // Table créée par une migration plus récente : rien à reprendre pour l'instant
            if (!$pdo->query('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = ' . $pdo->quote($table))->fetchColumn()) continue;
            foreach ($pdo->query("SELECT DISTINCT $col FROM $table WHERE $col IS NOT NULL AND $col <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $st->execute([$list, $v, $pos++, $list === 'classe' ? FALLBACK_ICON : null, null, 0, time()]);
            }
        }
    }
}

/** Import initial du recensement (fichier généré depuis l'Excel). */
function seed_members(PDO $pdo): void
{
    $path = cfg('seed_path');
    if (!$path || !is_file($path)) {
        return;
    }
    $rows = json_decode((string) file_get_contents($path), true) ?: [];
    $now = time();
    $st = $pdo->prepare('INSERT OR IGNORE INTO members
        (pseudo, classe, role, gs, cp, connaissance, meilleure_etape, objectif, jours, creneau, helper,
         inscription, clear_semaine, shot_caller, notes, verifie, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $st->execute([
            $r['pseudo'], $r['classe'] ?? 'Autre',
            $r['role'] ?? null, $r['gs'] ?? null, $r['cp'] ?? null, $r['connaissance'] ?? null,
            $r['meilleure_etape'] ?? null, $r['objectif'] ?? null, implode(',', $r['jours'] ?? []),
            $r['creneau'] ?? null, (int) ($r['helper'] ?? 0), $r['inscription'] ?? 'À confirmer',
            (int) ($r['clear_semaine'] ?? 0), (int) ($r['shot_caller'] ?? 0), $r['notes'] ?? null,
            (int) ($r['verifie'] ?? 0), $now, $now,
        ]);
    }
}

/** Transaction en écriture exclusive (évite qu'une salle dépasse sa capacité lors de joins simultanés). */
function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Requête préparée avec typage des paramètres (les entiers restent des entiers pour SQLite). */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        $type = match (true) {
            is_int($v)  => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            $v === null => PDO::PARAM_NULL,
            default     => PDO::PARAM_STR,
        };
        $st->bindValue($i + 1, $v, $type);
    }
    $st->execute();
    return $st;
}

function member_by_id(int $id): ?array
{
    return q('SELECT * FROM members WHERE id = ?', [$id])->fetch() ?: null;
}

function member_by_pseudo(string $pseudo): ?array
{
    return q('SELECT * FROM members WHERE pseudo = ? COLLATE NOCASE', [$pseudo])->fetch() ?: null;
}

function member_days(array $m): array
{
    return array_values(array_filter(explode(',', (string) $m['jours'])));
}

function legion_stats(): array
{
    $s = q("SELECT COUNT(*) AS total,
                   SUM(clear_semaine) AS cleared,
                   SUM(inscription = 'Confirmé' AND clear_semaine = 0) AS ready
            FROM members")->fetch();
    $roles = [];
    foreach (opts('role') as $r) {
        $roles[$r] = ['total' => 0, 'ready' => 0];
    }
    foreach (q("SELECT role, COUNT(*) AS total, SUM(inscription = 'Confirmé' AND clear_semaine = 0) AS ready
                FROM members WHERE role IS NOT NULL GROUP BY role")->fetchAll() as $row) {
        $roles[$row['role']] = ['total' => (int) $row['total'], 'ready' => (int) $row['ready']];
    }
    return ['total' => (int) $s['total'], 'cleared' => (int) $s['cleared'], 'ready' => (int) $s['ready'], 'roles' => $roles];
}

/** Petites valeurs persistantes (horodatages des tâches automatiques). */
function kv_get(string $key, string $default = ''): string
{
    $v = q('SELECT value FROM kv WHERE key = ?', [$key])->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function kv_set(string $key, string $value): void
{
    q('INSERT INTO kv (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
}
