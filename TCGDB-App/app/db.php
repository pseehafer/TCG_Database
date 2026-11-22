<?php
// ---- Database connection ----
// Packaged version: the TCG Database launcher runs its own database and passes its address in TCGDB_DB_HOST / TCGDB_DB_PORT.
// Without those (for example under XAMPP) this falls back to localhost.
define('DB_HOST', (getenv('TCGDB_DB_HOST') ?: 'localhost') . (getenv('TCGDB_DB_PORT') ? ';port=' . (int)getenv('TCGDB_DB_PORT') : ''));
const DB_NAME = 'tcg_database'; // created by tcg_database.sql
const DB_USER = 'root';       // the bundled database only accepts connections from this computer
const DB_PASS = '';

// The store's time zone, for dates on backups and valuations (XAMPP's PHP defaults to Europe/Berlin)
const APP_TIMEZONE = 'America/Chicago';
date_default_timezone_set(APP_TIMEZONE);

// Game value (from the UI) => table name in MySQL
const GAME_TABLES = [
    'pokemon' => 'pokemon_inv',
    'mtg'     => 'mtg_inv',
];

// Name of the column that stores how many copies you own
const QTY_COLUMN = 'quantity';

// If the card catalog download fails with an SSL certificate error, set this to false (local XAMPP only).
const HTTP_VERIFY_SSL = true;

// Card conditions, best to worst (TCGplayer scale). The first one is the default for blank cells.
const CONDITIONS = ['NM' => 'Near Mint', 'LP' => 'Lightly Played', 'MP' => 'Moderately Played', 'HP' => 'Heavily Played', 'DMG' => 'Damaged'];

// Printings tracked separately. 'normal' is the default for blank cells.
const FINISHES = ['normal' => 'Normal', 'foil' => 'Foil', 'reverse' => 'Reverse Holo', 'etched' => 'Etched Foil'];

// Pokémon set codes printed on cards (what your spreadsheets use) => set name in the card catalog.
// Add a line here if a set's images don't match.
const POKEMON_SET_CODES = [
    'SVI' => 'Scarlet & Violet', 'PAL' => 'Paldea Evolved', 'OBF' => 'Obsidian Flames', 'MEW' => '151',
    'PAR' => 'Paradox Rift', 'PAF' => 'Paldean Fates', 'TEF' => 'Temporal Forces', 'TWM' => 'Twilight Masquerade',
    'SFA' => 'Shrouded Fable', 'SCR' => 'Stellar Crown', 'SSP' => 'Surging Sparks', 'PRE' => 'Prismatic Evolutions',
    'JTG' => 'Journey Together', 'DRI' => 'Destined Rivals', 'BLK' => 'Black Bolt', 'WHT' => 'White Flare',
    'MEG' => 'Mega Evolution', 'PFL' => 'Phantasmal Flames', 'ASC' => 'Ascended Heroes', 'POR' => 'Perfect Order',
    'BRS' => 'Brilliant Stars', 'ASR' => 'Astral Radiance', 'LOR' => 'Lost Origin', 'SIT' => 'Silver Tempest',
    'CRZ' => 'Crown Zenith', 'PGO' => 'Pokémon GO', 'FST' => 'Fusion Strike', 'EVS' => 'Evolving Skies',
    'CRE' => 'Chilling Reign', 'BST' => 'Battle Styles', 'VIV' => 'Vivid Voltage', 'DAA' => 'Darkness Ablaze',
    'RCL' => 'Rebel Clash', 'SSH' => 'Sword & Shield',
];
// -----------------------------------------------------------

const SCHEMA_VERSION = 2;

function db(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
         PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'"]   // database times match the store's time zone
    );
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm(string $s): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
}

/** Matching key for card names: lowercase letters/digits only, front face of double-faced cards. */
function name_key(?string $s): string {
    $s = explode(' // ', (string)$s)[0];
    return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)), 0, 190);
}

/** Matching key for collector numbers: "079/086" => "79", "TG05" => "tg05". */
function num_key(?string $s): string {
    $s = strtolower(trim(explode('/', (string)$s)[0]));
    $t = ltrim($s, '0');
    return $s === '' ? '' : ($t === '' ? '0' : $t);
}

/** Columns of a table as normalized-name => real name. Skips auto-increment, generated and app-managed columns unless $all. */
function table_columns(PDO $pdo, string $table, bool $all = false): array {
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $st->execute([$table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $generated = stripos($c['EXTRA'], 'auto_increment') !== false || stripos($c['EXTRA'], 'generated') !== false
                  || stripos($c['EXTRA'], 'persistent') !== false || stripos($c['EXTRA'], 'stored') !== false;
        if (!$all && ($generated || in_array($c['COLUMN_NAME'], ['image_url', 'row_key'], true))) continue;
        $cols[norm($c['COLUMN_NAME'])] = $c['COLUMN_NAME'];
    }
    return $cols;
}

function first_col(array $cols, array $keys): ?string {
    foreach ($keys as $k) if (isset($cols[$k])) return $cols[$k];
    return null;
}
function name_col(array $cols): ?string   { return first_col($cols, ['name', 'cardname']); }
function qty_col(array $cols): ?string    { return first_col($cols, [norm(QTY_COLUMN), 'qty', 'count']); }
function set_col(array $cols): ?string    { return first_col($cols, ['setname', 'set', 'setcode', 'expansion', 'edition']); }
/** Real column name holding the set/collector number, if the table has one. */
function number_col(array $cols): ?string {
    return first_col($cols, ['setnumber', 'collectornumber', 'collectionnumber', 'cardnumber', 'collectorno', 'number']);
}

/** "Near Mint", "nm", "lightly played"... => 'NM', 'LP'... ; '' => 'NM'; null if unrecognized. */
function normalize_condition(?string $v): ?string {
    $k = norm((string)$v);
    if ($k === '') return array_key_first(CONDITIONS);
    static $map = [
        'nm' => 'NM', 'nearmint' => 'NM', 'mint' => 'NM', 'm' => 'NM', 'nmm' => 'NM',
        'lp' => 'LP', 'lightlyplayed' => 'LP', 'lightplayed' => 'LP', 'slightlyplayed' => 'LP', 'sp' => 'LP', 'excellent' => 'LP', 'ex' => 'LP',
        'mp' => 'MP', 'moderatelyplayed' => 'MP', 'played' => 'MP', 'pl' => 'MP', 'good' => 'MP', 'gd' => 'MP',
        'hp' => 'HP', 'heavilyplayed' => 'HP', 'heavyplayed' => 'HP', 'poor' => 'HP',
        'dmg' => 'DMG', 'damaged' => 'DMG', 'd' => 'DMG', 'dm' => 'DMG',
    ];
    return $map[$k] ?? null;
}

/** "Foil", "Reverse Holo", "yes", "Nonfoil"... => 'foil', 'reverse', 'normal'; null if unrecognized.
 *  For Pokémon, "Holo"/"Holofoil" is the card's regular printing, so it counts as normal. */
function normalize_finish(?string $v, string $game): ?string {
    $k = norm((string)$v);
    if ($k === '') return 'normal';
    if (str_contains($k, 'reverse') || $k === 'rh' || $k === 'revholo') return 'reverse';
    if (str_contains($k, 'etched')) return 'etched';
    if (in_array($k, ['normal', 'nonfoil', 'nonholo', 'regular', 'no', 'n', 'false', '0', 'unlimited', 'standard'], true)) return 'normal';
    if (in_array($k, ['holo', 'holofoil', 'holographic'], true)) return $game === 'pokemon' ? 'normal' : 'foil';
    if (in_array($k, ['foil', 'yes', 'y', 'true', '1', 'f', 'traditionalfoil'], true)) return 'foil';
    return null;
}

function meta_get(PDO $pdo, string $k): ?string {
    $st = $pdo->prepare('SELECT v FROM app_meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function meta_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/** Connection plus one-time setup (columns, indexes, catalog and list tables). Safe to call on every request. */
function app_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (k VARCHAR(64) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (GAME_TABLES as $t) {
        $cols = table_columns($pdo, $t, true);
        if (!$cols) continue; // table missing; pages report it
        $sig = SCHEMA_VERSION . ':' . implode(',', array_values(table_columns($pdo, $t)));
        if (meta_get($pdo, "schema:$t") === $sig) continue;
        migrate_table($pdo, $t);
        meta_set($pdo, "schema:$t", SCHEMA_VERSION . ':' . implode(',', array_values(table_columns($pdo, $t))));
    }

    if (meta_get($pdo, 'images:matcher') !== '4') { // pictures matched by the older, looser rules get re-matched
        foreach (GAME_TABLES as $t) {
            if (table_columns($pdo, $t, true)) $pdo->exec("UPDATE `$t` SET image_url = NULL");
        }
        if (table_columns($pdo, 'card_catalog', true) && !has_index($pdo, 'card_catalog', 'idx_num')) {
            $pdo->exec('ALTER TABLE card_catalog ADD INDEX idx_num (game, num_key)');
        }
        meta_set($pdo, 'images:matcher', '4');
    }

    if (meta_get($pdo, 'schema:pull_log') !== '1') { // history of cards pulled from inventory in the List Builder (allows Undo)
        $pdo->exec("CREATE TABLE IF NOT EXISTS pull_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            list_id INT NULL,
            item_name VARCHAR(255) NOT NULL,
            game VARCHAR(10) NOT NULL,
            card_name VARCHAR(255) NOT NULL,
            details VARCHAR(255) NOT NULL,
            snapshot TEXT NOT NULL,
            quantity INT NOT NULL,
            pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            undone_at TIMESTAMP NULL,
            KEY idx_list (list_id, item_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        meta_set($pdo, 'schema:pull_log', '1');
    }
    if (meta_get($pdo, 'schema:pull_log') === '1') { // v2: pulls made together share a batch, for the pick list
        if (!isset(table_columns($pdo, 'pull_log', true)['batchid'])) {   // tables made from tcg_database.sql already have it
            $pdo->exec("ALTER TABLE pull_log ADD COLUMN batch_id CHAR(16) NULL, ADD INDEX idx_batch (batch_id)");
        }
        meta_set($pdo, 'schema:pull_log', '2');
    }

    if (meta_get($pdo, 'schema:catalog_mana') !== '1' && table_columns($pdo, 'card_catalog', true)) {
        // Magic colors and mana cost, for the List Builder pick list (filled by the catalog download, or looked up as needed)
        if (!isset(table_columns($pdo, 'card_catalog', true)['colors'])) {
            $pdo->exec("ALTER TABLE card_catalog ADD COLUMN colors VARCHAR(10) NULL, ADD COLUMN mana_cost VARCHAR(100) NULL,
                        ADD COLUMN cmc DECIMAL(6,1) NULL, ADD COLUMN type_line VARCHAR(150) NULL");
        }
        meta_set($pdo, 'schema:catalog_mana', '1');
    }

    if (meta_get($pdo, 'schema:catalog_rarity') !== '1' && table_columns($pdo, 'card_catalog', true)) {
        // Rarity of each printing, for the List Builder pick list
        if (!isset(table_columns($pdo, 'card_catalog', true)['rarity'])) $pdo->exec('ALTER TABLE card_catalog ADD COLUMN rarity VARCHAR(40) NULL');
        meta_set($pdo, 'schema:catalog_rarity', '1');
    }

    if (meta_get($pdo, 'schema:kiosk_lists') !== '1' && table_columns($pdo, 'card_lists', true)) {
        // Lists customers send from the Customer Lookup kiosk: where they came from, and whether staff have opened them yet
        if (!isset(table_columns($pdo, 'card_lists', true)['source'])) $pdo->exec('ALTER TABLE card_lists ADD COLUMN source VARCHAR(20) NULL, ADD COLUMN seen_at TIMESTAMP NULL');
        meta_set($pdo, 'schema:kiosk_lists', '1');
    }

    if (meta_get($pdo, 'schema:shared') !== (string)SCHEMA_VERSION) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS card_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game VARCHAR(10) NOT NULL,
            ext_id VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_key VARCHAR(190) NOT NULL,
            set_code VARCHAR(20) NULL,
            set_name VARCHAR(150) NULL,
            set_alt VARCHAR(20) NULL,
            number VARCHAR(20) NULL,
            num_key VARCHAR(20) NULL,
            released DATE NULL,
            image_url VARCHAR(500) NULL,
            UNIQUE KEY uq_ext (game, ext_id),
            KEY idx_name (game, name_key, released),
            KEY idx_num (game, num_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS card_lists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            game VARCHAR(20) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS card_list_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            list_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            KEY idx_list_name (list_id, name),
            FOREIGN KEY (list_id) REFERENCES card_lists(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        meta_set($pdo, 'schema:shared', (string)SCHEMA_VERSION);
    }

    if (meta_get($pdo, 'schema:list_pref') !== '1' && table_columns($pdo, 'card_list_items', true)) {
        // The printing a customer chose at the kiosk (set, number, condition, finish), so staff pull that copy first
        if (!isset(table_columns($pdo, 'card_list_items', true)['pref'])) $pdo->exec('ALTER TABLE card_list_items ADD COLUMN pref VARCHAR(500) NULL');
        meta_set($pdo, 'schema:list_pref', '1');
    }
    return $pdo;
}

/* ---------- A chosen printing (kiosk) ---------- */

/** The printing of an inventory copy, as the kiosk offers it. $r is a row from stock_by_names(). */
function printing_of(array $r): array {
    return ['set' => trim((string)($r['set_name'] ?? '')), 'number' => trim((string)($r['num'] ?? '')),
            'condition' => (string)($r['card_condition'] ?? 'NM'), 'finish' => (string)($r['finish'] ?? 'normal')];
}

/** Compares printings regardless of capitals and spaces around values. */
function printing_key(array $p): string {
    return mb_strtolower(implode('|', [trim((string)($p['set'] ?? '')), trim((string)($p['number'] ?? '')), $p['condition'] ?? '', $p['finish'] ?? '']));
}

/** "Commander Masters #102 · Near Mint · Foil" */
function printing_label(array $p): string {
    $where = trim(($p['set'] ?? '') . (($p['number'] ?? '') !== '' ? ' #' . $p['number'] : ''));
    return implode(' · ', array_filter([$where !== '' ? $where : 'Unknown set', CONDITIONS[$p['condition']] ?? $p['condition'],
        ($p['finish'] ?? 'normal') !== 'normal' ? (FINISHES[$p['finish']] ?? $p['finish']) : '']));
}

/** A printing sent by the kiosk or stored on a list item, checked. Returns null when there is none or it isn't valid. */
function clean_printing($p): ?array {
    if (is_string($p)) $p = json_decode($p, true);
    if (!is_array($p)) return null;
    $out = ['set' => mb_substr(trim((string)($p['set'] ?? '')), 0, 150), 'number' => mb_substr(trim((string)($p['number'] ?? '')), 0, 40),
            'condition' => (string)($p['condition'] ?? ''), 'finish' => (string)($p['finish'] ?? '')];
    if (!isset(CONDITIONS[$out['condition']]) || !isset(FINISHES[$out['finish']])) return null;
    if ($out['set'] === '' && $out['number'] === '') return null;
    return $out;
}

/** Puts the copies matching the chosen printing first; the rest keep their order (best condition first). */
function prefer_printing(array $copies, ?array $pref): array {
    if (!$pref) return $copies;
    $k = printing_key($pref);
    $first = $rest = [];
    foreach ($copies as $c) {
        if (printing_key(printing_of($c)) === $k) { $c['preferred'] = true; $first[] = $c; }
        else $rest[] = $c;
    }
    return array_merge($first, $rest);
}

function has_index(PDO $pdo, string $t, string $idx): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $st->execute([$t, $idx]);
    return (bool)$st->fetchColumn();
}

/** Whole column for short VARCHARs (so ORDER BY can use the index), a prefix for TEXT columns. */
function index_part(PDO $pdo, string $t, string $c): string {
    $st = $pdo->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    [$type, $len] = $st->fetch(PDO::FETCH_NUM);
    return in_array(strtolower($type), ['varchar', 'char'], true) && (int)$len <= 255 ? "`$c`" : (str_contains(strtolower($type), 'text') || str_contains(strtolower($type), 'char') ? "`$c`(100)" : "`$c`");
}

/**
 * Brings a game table up to date:
 *  - image_url, card_condition and finish columns
 *  - row_key: a hash of every card column except quantity, kept by MySQL itself, with a UNIQUE index.
 *    Two rows with the same name/set/number/condition/finish are the same stock, so imports can
 *    add to the quantity with one fast INSERT ... ON DUPLICATE KEY UPDATE.
 *  - indexes for searching by name, set and number
 * Existing exact-duplicate rows are merged (quantities added together) before the unique index is created.
 */
function migrate_table(PDO $pdo, string $t): void {
    $all = table_columns($pdo, $t, true);
    $add = [];
    if (!isset($all['imageurl']))      $add[] = 'ADD COLUMN `image_url` VARCHAR(500) NULL';
    if (!isset($all['cardcondition'])) $add[] = "ADD COLUMN `card_condition` ENUM('" . implode("','", array_keys(CONDITIONS)) . "') NOT NULL DEFAULT '" . array_key_first(CONDITIONS) . "'";
    if (!isset($all['finish']))        $add[] = "ADD COLUMN `finish` ENUM('" . implode("','", array_keys(FINISHES)) . "') NOT NULL DEFAULT 'normal'";
    if ($add) $pdo->exec("ALTER TABLE `$t` " . implode(', ', $add));

    $cols = table_columns($pdo, $t);
    $qty = qty_col($cols);
    $keyCols = array_values(array_filter($cols, fn($c) => $c !== $qty));
    $expr = 'SHA1(CONCAT_WS(0x1F, ' . implode(', ', array_map(fn($c) => "LOWER(TRIM(COALESCE(`$c`, '')))", $keyCols)) . '))';

    if (isset(table_columns($pdo, $t, true)['rowkey'])) {
        if (has_index($pdo, $t, 'uq_row_key')) $pdo->exec("ALTER TABLE `$t` DROP INDEX `uq_row_key`");
        $pdo->exec("ALTER TABLE `$t` DROP COLUMN `row_key`");
    }
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `row_key` CHAR(40) AS ($expr) STORED, ADD INDEX `tmp_row_key` (`row_key`)");

    if ($qty) { // merge duplicates into the lowest id
        $pdo->exec("CREATE TEMPORARY TABLE dup_keys AS
                    SELECT row_key, MIN(id) AS keep_id, SUM(COALESCE(`$qty`, 1)) AS total FROM `$t` GROUP BY row_key HAVING COUNT(*) > 1");
        $pdo->exec("UPDATE `$t` x JOIN dup_keys d ON x.id = d.keep_id SET x.`$qty` = d.total");
        $pdo->exec("DELETE x FROM `$t` x JOIN dup_keys d ON x.row_key = d.row_key AND x.id <> d.keep_id");
        $pdo->exec("DROP TEMPORARY TABLE dup_keys");
    } else {
        $pdo->exec("DELETE x FROM `$t` x JOIN `$t` y ON x.row_key = y.row_key AND x.id > y.id");
    }
    $pdo->exec("ALTER TABLE `$t` DROP INDEX `tmp_row_key`, ADD UNIQUE INDEX `uq_row_key` (`row_key`)");

    $idx = [];
    foreach (['idx_name' => name_col($cols), 'idx_set' => set_col($cols), 'idx_number' => number_col($cols)] as $name => $c) {
        if ($c && !has_index($pdo, $t, $name)) $idx[] = "ADD INDEX `$name` (" . index_part($pdo, $t, $c) . ')';
    }
    if ($idx) $pdo->exec("ALTER TABLE `$t` " . implode(', ', $idx));
}

/** Parses lines like "4 Lightning Bolt", "4x Lightning Bolt (M10) 146" or "3 Mew VMAX FST 114". */
function parse_list(string $text): array {
    $items = [];
    $skipped = [];
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || str_starts_with($line, '//')) continue;
        if (!preg_match('/^(\d+)(?:x\s*|\s+)(.+)$/iu', $line, $m) || (int)$m[1] < 1) { $skipped[] = $line; continue; }
        $name = trim(preg_replace('/\s+(?:\([A-Z0-9]{2,5}\)(?:\s+\S+)?|[A-Z0-9]{2,5}\s+[0-9]+[a-z★]?)$/u', '', $m[2]));
        if ($name === '') { $skipped[] = $line; continue; }
        $k = mb_strtolower($name);
        $items[$k] = [$name, ($items[$k][1] ?? 0) + min(999, (int)$m[1])];
    }
    return ['items' => array_values($items), 'skipped' => $skipped];
}

/**
 * Every copy in stock for the given card names, grouped by name (lowercased).
 * A name also matches the front face of double-faced cards ("Delver of Secrets" finds "Delver of Secrets // Insectile Aberration").
 * Each copy: id, name, set_name, num, card_condition, finish, quantity, image_url. Best condition first.
 */
function stock_by_names(PDO $pdo, string $game, array $names): array {
    $names = array_values(array_unique(array_filter(array_map(fn($n) => trim((string)$n), $names), 'strlen')));
    if (!$names || !isset(GAME_TABLES[$game])) return [];
    $t = GAME_TABLES[$game];
    $c = table_columns($pdo, $t);
    $name = name_col($c); $qty = qty_col($c); $set = set_col($c); $num = number_col($c);
    $wanted = [];
    foreach ($names as $n) $wanted[mb_strtolower($n)] = true;
    $out = [];
    foreach (array_chunk($names, 200) as $chunk) {
        $or = []; $params = [];
        $or[] = "`$name` IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')';
        array_push($params, ...$chunk);
        foreach ($chunk as $n) { $or[] = "`$name` LIKE ?"; $params[] = addcslashes($n, '%_\\') . ' // %'; }
        $st = $pdo->prepare("SELECT id, `$name` AS name, " . ($set ? "`$set`" : 'NULL') . ' AS set_name, ' . ($num ? "`$num`" : 'NULL') . ' AS num,
                card_condition, finish, ' . ($qty ? "`$qty`" : '1') . " AS quantity, image_url FROM `$t`
                WHERE (" . implode(' OR ', $or) . ') ' . ($qty ? "AND `$qty` > 0 " : '') . "
                ORDER BY FIELD(card_condition, 'NM','LP','MP','HP','DMG'), FIELD(finish, 'normal','reverse','foil','etched'), `$name`, id");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $full = mb_strtolower($r['name']);
            $key = isset($wanted[$full]) ? $full : mb_strtolower(explode(' // ', $r['name'])[0]);
            if (!isset($wanted[$key])) continue;
            $r['id'] = (int)$r['id']; $r['quantity'] = (int)$r['quantity'];
            $out[$key][] = $r;
        }
    }
    return $out;
}

/* ---------- Card images from the local catalog ---------- */

/** Fills image_url for rows that have none yet, using card_catalog. Rows need id, name, set_name, num, image_url. */
function resolve_images(PDO $pdo, string $game, array &$rows): void {
    $need = array_filter($rows, fn($r) => $r['image_url'] === null);
    if (!$need) return;
    static $hasCatalog = [];
    if (!isset($hasCatalog[$game])) {
        $st = $pdo->prepare('SELECT 1 FROM card_catalog WHERE game = ? LIMIT 1');
        $st->execute([$game]);
        $hasCatalog[$game] = (bool)$st->fetchColumn();
    }
    if (!$hasCatalog[$game]) return; // leave NULL so they resolve after the catalog is downloaded

    $save = $pdo->prepare('UPDATE `' . GAME_TABLES[$game] . '` SET image_url = ? WHERE id = ?');
    $cache = [];
    foreach ($rows as &$r) {
        if ($r['image_url'] !== null) continue;
        $key = $r['name'] . "\x1F" . $r['set_name'] . "\x1F" . $r['num'];
        if (!isset($cache[$key])) $cache[$key] = match_catalog($pdo, $game, $r['name'], $r['set_name'], $r['num']);
        $r['image_url'] = $cache[$key];
        $save->execute([$r['image_url'], $r['id']]);
    }
}

/** Lowercase words of a card name, for loose comparisons ("Ethan's Magcargo" => ["ethans", "magcargo"]). */
function name_words(?string $s): array {
    return array_values(array_filter(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_strtolower((string)$s)))));
}

/** True when two names are the same card written differently: a small typo, or extra words such as
 *  "Growing Energy" vs "Growing Grass Energy". */
function names_close(?string $a, ?string $b): bool {
    $ka = name_key($a); $kb = name_key($b);
    if ($ka === '' || $kb === '') return false;
    if ($ka === $kb || levenshtein($ka, $kb) <= max(1, intdiv(min(strlen($ka), strlen($kb)), 8))) return true;
    $wa = name_words($a); $wb = name_words($b);
    return count($wa) >= 2 && count($wb) >= 2 && (!array_diff($wa, $wb) || !array_diff($wb, $wa));
}

/** Basic energy names as the catalog may spell them: "Dark Energy" => ["darkenergy", "basicdarkenergy", "darknessenergy", "basicdarknessenergy"]. */
function basic_energy_keys(?string $name): array {
    if (!preg_match('/^(?:basic\s+)?(grass|fire|water|lightning|psychic|fighting|dark|darkness|metal|fairy|dragon)\s+energy$/i', trim((string)$name), $m)) return [];
    $types = [strtolower($m[1])];
    if ($types[0] === 'dark') $types[] = 'darkness';
    if ($types[0] === 'darkness') $types[] = 'dark';
    $keys = [];
    foreach ($types as $t) { $keys[] = name_key("$t Energy"); $keys[] = name_key("Basic $t Energy"); }
    return $keys;
}

/**
 * Picks the catalog picture for one inventory card.
 *  1. Same name, set and number: the exact printing.
 *  2. Same set and number with a slightly different name (a typo, or "Growing Energy" vs
 *     "Growing Grass Energy"): still the exact printing.
 *  3. Same name and number in another set (e.g. the set code is spelled differently).
 *  4. Same name in the same set, regular rarity only: if the card says 086/132, anything numbered above 132
 *     (Ultra Rare, Illustration Rare, Special Illustration Rare, Hyper Rare) is a different card and is never
 *     used as a stand-in.
 *  5. Basic energy: any printing of that energy type (they're interchangeable).
 *  Otherwise no picture, which is better than the wrong rarity.
 */
/** $rule (optional) is set to which rule matched: 1-5 as listed above, 0 = no match. */
function match_catalog(PDO $pdo, string $game, ?string $name, ?string $set, ?string $num, ?int &$rule = null): string {
    $rule = 0;
    static $byName = null, $byNum = null, $byNames = [];
    // Pokémon TCG Pocket (the phone game) cards are in TCGdex too; they are never physical stock
    $byName ??= $pdo->prepare("SELECT name, set_code, set_name, set_alt, num_key, image_url FROM card_catalog
                               WHERE game = ? AND name_key = ? AND image_url NOT LIKE '%/tcgp/%' ORDER BY released DESC LIMIT 400");
    $byNum  ??= $pdo->prepare("SELECT name, set_code, set_name, set_alt, num_key, image_url FROM card_catalog
                               WHERE game = ? AND num_key = ? AND image_url IS NOT NULL AND image_url NOT LIKE '%/tcgp/%' LIMIT 2000");

    $sets = [];
    if ($set !== null && trim($set) !== '') {
        $sets[] = mb_strtolower(trim($set));
        $alias = POKEMON_SET_CODES[strtoupper(trim($set))] ?? null;
        if ($game === 'pokemon' && $alias) $sets[] = mb_strtolower($alias);
    }
    $inSet = fn(array $c) => $sets && array_intersect($sets, array_map('mb_strtolower', array_filter([$c['set_code'], $c['set_name'], $c['set_alt']])));
    $n = num_key($num);
    // "086/132" => set size 132; a numbered card above it is a secret rare
    $setSize = preg_match('#/\s*(\d+)#', (string)$num, $m) ? (int)$m[1] : null;
    $isSecret = fn(string $k) => $setSize && ctype_digit($k) && (int)$k > $setSize;

    // 1, 3, 4: same name
    $byName->execute([$game, name_key($name)]);
    $best = ''; $bestScore = -1; $bestNum = PHP_INT_MAX;
    foreach ($byName->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!$c['image_url']) continue;
        $cNum = ctype_digit((string)$c['num_key']) ? (int)$c['num_key'] : PHP_INT_MAX - 1;
        $setOk = $inSet($c);
        $numOk = $n !== '' && $c['num_key'] === $n;
        if ($setOk && $numOk) { $rule = 1; return $c['image_url']; }   // 1. exact printing
        if ($numOk)                     $score = 3;                     // 3. same number, set spelled differently
        elseif ($n !== '' && $setSize)  $score = $isSecret((string)$c['num_key']) || !$setOk ? -1 : 1; // 4. regular rarity only
        else                            $score = $setOk ? 2 : 0;        // no number to go on (e.g. Magic rows without one)
        // on a tie, the lower number is the regular printing rather than a full art / secret rare
        if ($score > $bestScore || ($score === $bestScore && $cNum < $bestNum)) { $best = $c['image_url']; $bestScore = $score; $bestNum = $cNum; }
    }

    // 2. same set + number, name written a little differently
    if ($n !== '' && $sets) {
        $byNum->execute([$game, $n]);
        foreach ($byNum->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($inSet($c) && names_close($name, $c['name'])) { $rule = 2; return $c['image_url']; }
        }
    }
    if ($bestScore >= 0) { $rule = $bestScore === 3 ? 3 : 4; return $best; }

    // 5. basic energy: any printing, newest first
    if ($game === 'pokemon' && ($keys = basic_energy_keys($name))) {
        foreach ($keys as $k) {
            $byName->execute([$game, $k]);
            foreach ($byName->fetchAll(PDO::FETCH_ASSOC) as $c) if ($c['image_url']) { $rule = 5; return $c['image_url']; }
        }
    }
    return '';
}

/** [colors, mana cost, mana value, type line] of a Scryfall card; double-faced cards use the front face. */
function scryfall_mana(array $c): array {
    $front = $c['card_faces'][0] ?? [];
    $colors = $c['colors'] ?? ($front['colors'] ?? null);
    $cost = ($c['mana_cost'] ?? '') !== '' ? $c['mana_cost'] : ($front['mana_cost'] ?? null);
    $order = array_flip(['W', 'U', 'B', 'R', 'G']);
    if (is_array($colors)) usort($colors, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
    return [is_array($colors) ? implode('', $colors) : null, $cost, isset($c['cmc']) ? (float)$c['cmc'] : null,
            ($front['type_line'] ?? null) ?: ($c['type_line'] ?? null)];
}

/* ---------- HTTP ---------- */

/** GET a URL. Returns [httpCode, body|null, error|null]. Pass $toFile to stream a large download to disk. */
function http_get(string $url, array $headers = [], ?string $toFile = null, int $timeout = 60): array {
    $ch = curl_init($url);
    $fh = $toFile ? fopen($toFile, 'wb') : null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => !$fh,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '', // accept gzip
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: TCGDatabase/1.0', 'Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => HTTP_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => HTTP_VERIFY_SSL ? 2 : 0,
    ]);
    if ($fh) curl_setopt($ch, CURLOPT_FILE, $fh);
    $body = curl_exec($ch);
    $err = $body === false ? curl_error($ch) : null;
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($fh) fclose($fh);
    return [$code, $err || $fh ? null : $body, $err];
}

/** GET a JSON URL. Returns [httpCode, data|null, error|null]. */
function http_get_json(string $url, array $headers = []): array {
    [$code, $body, $err] = http_get($url, $headers);
    return [$code, $body === null ? null : json_decode($body, true), $err];
}
