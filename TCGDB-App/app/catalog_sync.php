<?php
/**
 * Downloads every printed card (name, set, number, picture link) into the card_catalog table,
 * so the inventory can show pictures without asking an outside API for each card.
 *
 *   Magic:    Scryfall's free "Default Cards" bulk file (one download, ~500 MB).
 *   Pokémon:  TCGdex (free, no key), one request per set. Re-runs only fetch sets that changed.
 *
 * Run it from the browser (Card catalog page) or from a command prompt in the XAMPP folder:
 *   php\php.exe htdocs\<your folder>\catalog_sync.php mtg
 *   php\php.exe htdocs\<your folder>\catalog_sync.php pokemon
 * Re-run every month or so to pick up new sets.
 */
require __DIR__ . '/db.php';

const SCRYFALL_BULK = 'https://api.scryfall.com/bulk-data'; // list of Scryfall's bulk files
const TCGDEX_API = 'https://api.tcgdex.net/v2/en';

/* ---------- Saving ---------- */

class CatalogWriter {
    private array $rows = [];
    public int $count = 0;
    public function __construct(private PDO $pdo, private string $game) {}

    /** $mana = Magic only: [colors "WU", mana cost "{1}{W}{U}", mana value, type line, rarity] */
    public function add(string $extId, string $name, ?string $setCode, ?string $setName, ?string $setAlt, ?string $number, ?string $released, ?string $image, array $mana = [null, null, null, null, null]): void {
        if (!$image || $name === '') return;
        $this->rows[] = [$this->game, mb_substr($extId, 0, 64), mb_substr($name, 0, 255), name_key($name),
            $setCode ? mb_substr($setCode, 0, 20) : null, $setName ? mb_substr($setName, 0, 150) : null, $setAlt ? mb_substr($setAlt, 0, 20) : null,
            $number !== null ? mb_substr($number, 0, 20) : null, $number !== null ? mb_substr(num_key($number), 0, 20) : null,
            $released && preg_match('/^\d{4}[-\/]\d{2}[-\/]\d{2}$/', $released) ? str_replace('/', '-', $released) : null, mb_substr($image, 0, 500),
            $mana[0], $mana[1] !== null ? mb_substr($mana[1], 0, 100) : null, $mana[2], $mana[3] !== null ? mb_substr($mana[3], 0, 150) : null, $mana[4] ?? null];
        if (count($this->rows) >= 1000) $this->flush();
    }

    public function flush(): void {
        if (!$this->rows) return;
        $one = '(' . implode(',', array_fill(0, 16, '?')) . ')';
        $this->pdo->prepare('INSERT INTO card_catalog (game, ext_id, name, name_key, set_code, set_name, set_alt, number, num_key, released, image_url, colors, mana_cost, cmc, type_line, rarity) VALUES '
            . implode(',', array_fill(0, count($this->rows), $one))
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), name_key = VALUES(name_key), set_code = VALUES(set_code), set_name = VALUES(set_name),
                set_alt = VALUES(set_alt), number = VALUES(number), num_key = VALUES(num_key), released = VALUES(released), image_url = VALUES(image_url),
                colors = COALESCE(VALUES(colors), colors), mana_cost = COALESCE(VALUES(mana_cost), mana_cost), cmc = COALESCE(VALUES(cmc), cmc), type_line = COALESCE(VALUES(type_line), type_line), rarity = COALESCE(VALUES(rarity), rarity)')
            ->execute(array_merge(...$this->rows));
        $this->count += count($this->rows);
        $this->rows = [];
    }
}

/* ---------- Magic (Scryfall bulk data) ---------- */

/** Reads a big JSON file of card objects one at a time (a JSON array or JSON Lines, gzipped or not), without loading it all. */
function scryfall_cards(string $file): Generator {
    // compress.zlib:// reads .gz files and plain files alike (Scryfall's JSON Lines files may be gzipped)
    $h = fopen('compress.zlib://' . $file, 'r');
    if (!$h) throw new RuntimeException('Could not open the downloaded Scryfall file.');
    $depth = 0; $inStr = false; $skip = 0; $obj = '';
    while (!feof($h)) {
        $chunk = fread($h, 1 << 20);
        $len = strlen($chunk);
        $i = $skip; $skip = 0;                 // an escaped character carried over from the last chunk
        $start = $depth > 0 ? 0 : null;
        while ($i < $len) {
            if ($inStr) {
                $i += strcspn($chunk, "\"\\", $i);   // jump to the next quote or backslash
                if ($i >= $len) break;
                if ($chunk[$i] === '\\') { $i += 2; if ($i > $len) $skip = $i - $len; continue; }
                $inStr = false; $i++; continue;
            }
            $i += strcspn($chunk, '{}"', $i);
            if ($i >= $len) break;
            $ch = $chunk[$i];
            if ($ch === '"') $inStr = true;
            elseif ($ch === '{') { if ($depth++ === 0) $start = $i; }
            elseif (--$depth === 0) {
                $obj .= substr($chunk, $start, $i - $start + 1);
                $card = json_decode($obj, true);
                if (is_array($card)) yield $card;
                $obj = ''; $start = null;
            }
            $i++;
        }
        if ($depth > 0 && $start !== null) $obj .= substr($chunk, $start);
    }
    fclose($h);
}

function import_scryfall_file(PDO $pdo, string $file, callable $say): int {
    $w = new CatalogWriter($pdo, 'mtg');
    foreach (scryfall_cards($file) as $c) {
        if (!empty($c['digital'])) continue; // Arena/MTGO-only cards
        $img = $c['image_uris']['normal'] ?? ($c['card_faces'][0]['image_uris']['normal'] ?? null);
        $w->add($c['id'] ?? '', $c['name'] ?? '', $c['set'] ?? null, $c['set_name'] ?? null, null,
                $c['collector_number'] ?? null, $c['released_at'] ?? null, $img, [...scryfall_mana($c), isset($c['rarity']) ? mb_substr((string)$c['rarity'], 0, 40) : null]);
        if ($w->count && $w->count % 20000 === 0) $say(number_format($w->count) . ' Magic cards saved...');
    }
    $w->flush();
    return $w->count;
}

/** Finds the "Default Cards" entry (every English printing) in Scryfall's list of bulk files. */
function scryfall_default_cards(?array $list): ?array {
    if (!is_array($list)) return null;
    $isDefault = fn($b) => is_array($b) && (
        str_replace('-', '_', strtolower((string)($b['type'] ?? ''))) === 'default_cards'
        || stripos((string)($b['name'] ?? ''), 'default') !== false);
    if ($isDefault($list)) return $list;                      // a single bulk object
    foreach ($list['data'] ?? [] as $b) if ($isDefault($b)) return $b;
    return null;
}

/** Describes what Scryfall sent, for error messages. */
function scryfall_describe($list): string {
    if (!is_array($list)) return 'The reply could not be read as JSON (' . json_last_error_msg() . ').';
    $items = [];
    foreach ($list['data'] ?? [] as $b) {
        if (is_array($b)) $items[] = ($b['type'] ?? '?') . ' [' . implode(', ', array_keys($b)) . ']';
    }
    return $items ? 'Files listed: ' . implode('; ', $items) : 'Keys in reply: ' . implode(', ', array_keys($list));
}

function sync_mtg(PDO $pdo, callable $say): int {
    $say('Asking Scryfall for the latest bulk file...');
    [$code, $body, $err] = http_get(SCRYFALL_BULK);
    if ($err || $code !== 200 || $body === null) throw new RuntimeException('Scryfall bulk data is unavailable: ' . ($err ?: "HTTP $code"));
    $list = json_decode($body, true, 512, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);
    $info = scryfall_default_cards($list);
    $link = fn($i) => $i['download_uri'] ?? ($i['jsonl_download_uri'] ?? null);
    if ($info && !$link($info) && !empty($info['uri'])) {
        // The list may leave out the download link; the file's own entry has it
        [$code2, $one, $err2] = http_get_json($info['uri']);
        if (!$err2 && $code2 === 200 && is_array($one)) $info = array_merge($info, $one);
    }
    if (!$info || !$link($info)) {
        throw new RuntimeException('Could not find the Default Cards file in Scryfall\'s list. ' . scryfall_describe($list));
    }
    $tmp = tempnam(sys_get_temp_dir(), 'scry');
    $size = $info['compressed_size'] ?? ($info['size'] ?? null);
    $say('Downloading ' . ($size ? round($size / 1e6) . ' MB' : 'the card file') . ' (this takes a few minutes)...');
    [$code, , $err] = http_get($link($info), [], $tmp, 1800);
    if ($err || $code !== 200) { @unlink($tmp); throw new RuntimeException('Download failed: ' . ($err ?: "HTTP $code")); }
    $say('Saving cards to the catalog...');
    try { return import_scryfall_file($pdo, $tmp, $say); } finally { @unlink($tmp); }
}

/* ---------- Pokémon (TCGdex) ---------- */

/** Adds one TCGdex set (the JSON from /sets/{id}) to the catalog. */
function import_tcgdex_set(CatalogWriter $w, array $set): void {
    if (($set['serie']['id'] ?? '') === 'tcgp') return;   // Pokémon TCG Pocket (phone game), not physical cards
    $alt = $set['abbreviation']['official'] ?? ($set['tcgOnline'] ?? null);
    foreach ($set['cards'] ?? [] as $c) {
        $img = !empty($c['image']) ? $c['image'] . '/high.webp' : null;
        $w->add($c['id'] ?? '', $c['name'] ?? '', $set['id'] ?? null, $set['name'] ?? null, $alt,
                isset($c['localId']) ? (string)$c['localId'] : null, $set['releaseDate'] ?? null, $img);
    }
    $w->flush();
}

function sync_pokemon(PDO $pdo, callable $say): int {
    $say('Getting the list of Pokémon sets from TCGdex...');
    [$code, $sets, $err] = http_get_json(TCGDEX_API . '/sets');
    if ($err || $code !== 200 || !is_array($sets)) throw new RuntimeException('TCGdex is unavailable: ' . ($err ?: "HTTP $code"));

    $have = $pdo->query("SELECT set_code, COUNT(*) FROM card_catalog WHERE game = 'pokemon' GROUP BY set_code")->fetchAll(PDO::FETCH_KEY_PAIR);
    $w = new CatalogWriter($pdo, 'pokemon');
    $done = $skipped = 0;
    foreach ($sets as $s) {
        $id = $s['id'] ?? null;
        if (!$id) continue;
        $expected = $s['cardCount']['total'] ?? null;
        if ($expected !== null && isset($have[$id]) && (int)$have[$id] >= (int)$expected) { $skipped++; continue; }
        [$code, $set, $err] = http_get_json(TCGDEX_API . '/sets/' . rawurlencode($id));
        if ($err || $code !== 200 || !is_array($set)) { $say("Skipped set $id: " . ($err ?: "HTTP $code")); continue; }
        import_tcgdex_set($w, $set);
        $done++;
        if ($done % 10 === 0) $say("$done sets downloaded (" . number_format($w->count) . ' cards)...');
        usleep(100000);
    }
    $say("$done sets downloaded, $skipped already up to date.");
    return $w->count;
}

/** After a sync, cards that previously had no match get another try. */
function retry_missing_images(PDO $pdo, string $game): void {
    $pdo->exec('UPDATE `' . GAME_TABLES[$game] . "` SET image_url = NULL WHERE image_url = ''");
}

function run_sync(PDO $pdo, string $game, callable $say): void {
    set_time_limit(0);
    ini_set('memory_limit', '512M');
    $t = microtime(true);
    $n = $game === 'mtg' ? sync_mtg($pdo, $say) : sync_pokemon($pdo, $say);
    retry_missing_images($pdo, $game);
    meta_set($pdo, "catalog:$game", date('Y-m-d H:i'));
    $say('Done: ' . number_format($n) . ' cards saved in ' . round(microtime(true) - $t) . ' seconds.');
}

/* ---------- Command line ---------- */
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $game = $argv[1] ?? '';
    if (!isset(GAME_TABLES[$game])) { fwrite(STDERR, "Usage: php catalog_sync.php mtg|pokemon\n"); exit(1); }
    try { run_sync(app_db(), $game, fn($m) => print($m . PHP_EOL)); }
    catch (Throwable $e) { fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL); exit(1); }
    exit;
}
if (PHP_SAPI === 'cli') return; // included by a test script

/* ---------- Web page ---------- */
require __DIR__ . '/auth.php';
require_staff();
require __DIR__ . '/partials.php';
$pdo = app_db();
$run = $_SERVER['REQUEST_METHOD'] === 'POST' && isset(GAME_TABLES[$_POST['game'] ?? '']) ? $_POST['game'] : null;

page_head('Card catalog', 'catalog', 'inventory-page');
?>
        <h1>Card catalog</h1>
        <p class="lead">Card pictures come from a local copy of every printed card. Download it once, then again whenever new sets come out.</p>
<?php
if ($run) {
    echo '<pre class="sync-log">';
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
    ignore_user_abort(true);
    $say = function ($m) { echo h($m), "\n<!--", str_repeat(' ', 1024), "-->"; flush(); }; // padding makes browsers show each line right away
    try { run_sync($pdo, $run, $say); }
    catch (Throwable $e) { $say('Error: ' . $e->getMessage()); }
    echo '</pre><p><a href="inventory.php">Back to inventory</a></p>';
}
$counts = $pdo->query('SELECT game, COUNT(*) FROM card_catalog GROUP BY game')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Game</th><th class="num">Cards in catalog</th><th>Last updated</th><th></th></tr></thead>
            <tbody>
            <?php foreach (['mtg' => 'Magic (from Scryfall)', 'pokemon' => 'Pokémon (from TCGdex)'] as $g => $label): ?>
                <tr>
                    <td><?= h($label) ?></td>
                    <td class="num"><?= number_format($counts[$g] ?? 0) ?></td>
                    <td><?= h(meta_get($pdo, "catalog:$g") ?? 'Never') ?></td>
                    <td class="actions">
                        <form method="post"><input type="hidden" name="game" value="<?= $g ?>"><button type="submit" class="primary">Download now</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="note">Magic downloads one large file (about 500 MB) and takes a few minutes. Pokémon downloads one set at a time and skips sets it already has. Keep this page open until it says Done.</p>
<?php page_foot(); ?>
