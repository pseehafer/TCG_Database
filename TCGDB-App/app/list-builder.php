<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_staff(isset($_GET['suggest']) || isset($_GET['kiosk_waiting']));
auth_session(); // flash messages use the staff session
require __DIR__ . '/partials.php';
require __DIR__ . '/prices.php';

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function go(?int $id = null, ?string $batch = null): void {
    header('Location: list-builder.php' . ($id ? "?list=$id" . ($batch ? "&picklist=$batch" : '') : ''));
    exit;
}

/** Copies you own of each name (every printing, condition and finish added together). Returns lowercase name => count. */
function owned_many(PDO $pdo, string $game, array $names): array {
    if (!$names) return [];
    $t = GAME_TABLES[$game];
    $c = table_columns($pdo, $t);
    $name = name_col($c); $qty = qty_col($c);
    $out = [];
    foreach (array_chunk(array_values(array_unique($names)), 500) as $chunk) {
        $st = $pdo->prepare("SELECT `$name`, SUM(" . ($qty ? "`$qty`" : '1') . ") FROM `$t` WHERE `$name` IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") GROUP BY `$name`");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$n, $sum]) $out[mb_strtolower($n)] = ($out[mb_strtolower($n)] ?? 0) + (int)$sum;
    }
    return $out;
}

function owned(PDO $pdo, string $game, string $name): int {
    return owned_many($pdo, $game, [$name])[mb_strtolower($name)] ?? 0;
}

/** Adds to a list (or raises the quantity if it's already there). Returns the new quantity. */
function add_item(PDO $pdo, int $lid, string $name, int $qty): int {
    $name = mb_substr($name, 0, 255);
    $st = $pdo->prepare('UPDATE card_list_items SET quantity = quantity + ? WHERE list_id = ? AND name = ? LIMIT 1');
    $st->execute([$qty, $lid, $name]);
    if ($st->rowCount() === 0) {
        $pdo->prepare('INSERT INTO card_list_items (list_id, name, quantity) VALUES (?, ?, ?)')->execute([$lid, $name, $qty]);
    }
    $st = $pdo->prepare('SELECT quantity FROM card_list_items WHERE list_id = ? AND name = ?');
    $st->execute([$lid, $name]);
    return (int)$st->fetchColumn();
}

/** "Magic 2010 · 146 · NM · Foil" */
function copy_details(array $r): string {
    return implode(' · ', array_filter([$r['set_name'] ?? '', $r['num'] ?? '', $r['card_condition'] ?? '',
        ($r['finish'] ?? 'normal') !== 'normal' ? (FINISHES[$r['finish']] ?? $r['finish']) : '']));
}

/**
 * Takes copies out of the inventory for one list item. $pulls = [inventory row id => how many].
 * Each pull is recorded in pull_log with a snapshot of the card so it can be undone.
 * Returns [pulled count, lines for the message, rows that had less stock than asked].
 */
function pull_cards(PDO $pdo, array $list, array $item, array $pulls, string $batch, bool $ownTransaction = true): array {
    $game = $list['game'];
    $t = GAME_TABLES[$game];
    $c = table_columns($pdo, $t);
    $name = name_col($c); $qty = qty_col($c); $set = set_col($c); $num = number_col($c);
    if (!$qty) throw new RuntimeException("The $t table has no quantity column, so cards can't be pulled.");
    $front = fn($n) => mb_strtolower(explode(' // ', (string)$n)[0]);

    $total = 0; $lines = []; $short = 0;
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT * FROM `$t` WHERE id = ? FOR UPDATE");
        $log = $pdo->prepare('INSERT INTO pull_log (list_id, item_name, game, card_name, details, snapshot, quantity, batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($pulls as $id => $want) {
            $want = (int)$want;
            if ($want <= 0) continue;
            $sel->execute([(int)$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            // only copies of this list item's card can be pulled from its row
            if (!$row || ($front($row[$name]) !== $front($item['name']) && mb_strtolower($row[$name]) !== mb_strtolower($item['name']))) { $short++; continue; }
            $have = (int)$row[$qty];
            $n = min($want, $have);
            if ($n < $want) $short++;
            if ($n <= 0) continue;
            if ($have - $n <= 0) $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$row['id']]);
            else $pdo->prepare("UPDATE `$t` SET `$qty` = `$qty` - ? WHERE id = ?")->execute([$n, $row['id']]);
            $snap = $row;
            unset($snap['id'], $snap['row_key'], $snap[$qty]);
            $details = copy_details(['set_name' => $set ? $row[$set] : '', 'num' => $num ? $row[$num] : '', 'card_condition' => $row['card_condition'], 'finish' => $row['finish']]);
            $log->execute([$list['id'], $item['name'], $game, $row[$name], mb_substr($details, 0, 255), json_encode($snap, JSON_UNESCAPED_UNICODE), $n, $batch]);
            $total += $n;
            $lines[] = "$n × {$row[$name]} ($details)";
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction) $pdo->rollBack();
        throw $e;
    }
    return [$total, $lines, $short];
}

/**
 * Pulls everything still needed on the list that is in stock: the printing the customer chose at the kiosk first
 * (when there is one), then best condition first.
 * All in one transaction and one batch, so it can be shown as one pick list and undone together.
 */
function pull_all(PDO $pdo, array $list, string $batch): array {
    $st = $pdo->prepare('SELECT * FROM card_list_items WHERE list_id = ?');
    $st->execute([$list['id']]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT LOWER(item_name), SUM(quantity) FROM pull_log WHERE list_id = ? AND undone_at IS NULL GROUP BY LOWER(item_name)');
    $st->execute([$list['id']]);
    $pulled = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $total = 0; $cards = 0; $short = 0;
    $pdo->beginTransaction();
    try {
        $stock = stock_by_names($pdo, $list['game'], array_column($items, 'name'));
        foreach ($items as $it) {
            $left = (int)$it['quantity'] - (int)($pulled[mb_strtolower($it['name'])] ?? 0);
            if ($left <= 0) continue;
            $pulls = [];
            foreach (prefer_printing($stock[mb_strtolower($it['name'])] ?? [], clean_printing($it['pref'] ?? null)) as $cp) {
                if ($left <= 0) break;
                $n = min($left, $cp['quantity']);
                $pulls[$cp['id']] = $n;
                $left -= $n;
            }
            if (!$pulls) continue;
            [$n, , $s] = pull_cards($pdo, $list, $it, $pulls, $batch, false);
            $total += $n; $cards += $n > 0 ? 1 : 0; $short += $s;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$total, $cards, $short];
}

/** The pick list for one batch: what to take off the shelf, sorted by set and number. */
/* ---------- Printed pick list: compact sheet, 150 cards per page (3 columns of 50) ---------- */
const PICK_PRINT_PER_PAGE = 150;

/** Short rarity code for the printed sheet: Magic C/U/R/M; Pokémon RR, IR, SIR... */
function rarity_code(?array $r, string $game): array {
    if (!$r) return ['', ''];
    if ($game === 'mtg') {
        $c = ['common' => 'C', 'uncommon' => 'U', 'rare' => 'R', 'mythic' => 'M', 'special' => 'S', 'bonus' => 'B'][$r['class']] ?? strtoupper(substr($r['label'], 0, 1));
        return [$c, $c];
    }
    $l = strtolower($r['label']);
    $map = ['common' => 'C', 'uncommon' => 'U', 'rare' => 'R', 'rare holo' => 'RH', 'double rare' => 'RR', 'ultra rare' => 'UR', 'illustration rare' => 'IR',
            'special illustration rare' => 'SIR', 'hyper rare' => 'HR', 'secret rare' => 'SR', 'shiny rare' => 'SH', 'shiny ultra rare' => 'SSR', 'ace spec rare' => 'ACE',
            'mega hyper rare' => 'MHR', 'black white rare' => 'BWR', 'promo' => 'PR'];
    $code = $map[$l] ?? strtoupper(implode('', array_map(fn($w) => $w[0], array_filter(explode(' ', $l)))));
    $cls = ['common' => 'C', 'uncommon' => 'U', 'rare' => 'R', 'rare-plus' => 'R', 'mythic' => 'M'][$r['class']] ?? 'S';
    return [$code, $cls];
}

/** Set and collector number for the printed sheet, as printed on the card: "DSC #114". Full set name in the tooltip-free fallback. */
function pick_set_label(array $p): string {
    $set = trim((string)($p['set_code'] ?? ''));
    $inv = trim((string)$p['set']);
    // No catalog match: the inventory's own set field (already a code for most Pokémon sheets)
    if ($set === '') $set = (mb_strlen($inv) <= 6 && !str_contains($inv, ' ')) ? strtoupper($inv) : $inv;
    $num = trim((string)$p['number']);
    if ($num === '') $num = trim((string)($p['cat_number'] ?? ''));
    $num = preg_replace('~/.*$~', '', $num);   // "114/198" -> "114"
    return trim($set . ($num !== '' ? ' #' . $num : ''));
}

/** Color letters for the printed sheet: ['W'], ['W','G'], ['C'] colorless, ['L'] land. */
function color_codes(?array $m): array {
    if (!$m) return [];
    $letters = ['White' => 'W', 'Blue' => 'U', 'Black' => 'B', 'Red' => 'R', 'Green' => 'G'];
    if ($m['group'] === 'Land') return ['L'];
    if ($m['group'] === 'Colorless') return ['C'];
    if (isset($letters[$m['group']])) return [$letters[$m['group']]];
    preg_match_all('/White|Blue|Black|Red|Green/', $m['color_label'], $x);
    return array_map(fn($n) => $letters[$n], $x[0]);
}

/** Short finish mark for the printed sheet: F foil, E etched foil, RH reverse holo, '' for normal. */
function finish_code(string $finish): string {
    return ['foil' => 'F', 'etched' => 'E', 'reverse' => 'RH'][$finish] ?? '';
}

/** The printed version of a pick list: name, set and number, condition, finish (Magic), rarity and color, 150 per letter page. */
function pick_print_html(array $picks, string $listName, bool $byColor): string {
    $rows = array_values(array_filter($picks, fn($p) => !$p['undone']));
    if (!$rows) return '';
    $magic = (bool)array_filter($rows, fn($p) => $p['game'] === 'mtg');
    $hasRH = (bool)array_filter($rows, fn($p) => ($p['finish'] ?? '') === 'reverse');   // Pokémon reverse holos on a mixed list
    $copies = array_sum(array_column($rows, 'quantity'));
    $pages = array_chunk($rows, PICK_PRINT_PER_PAGE);
    $date = date('D, M j, Y');
    $order = $magic && $byColor ? 'sorted by color, then mana value' : 'sorted by set and number';
    // Page size and margins for the printed sheet (only on a page that shows a pick list)
    $out = '<style>@media print { @page { size: Letter; margin: 0.45in 0.45in 0.4in; } }</style>';
    $out .= '<div class="pick-print' . ($magic ? '' : ' no-color') . '" aria-hidden="true">';
    foreach ($pages as $i => $page) {
        $out .= '<div class="pp-page"><header class="pp-head"><h1>Pick list · ' . h($listName) . '</h1><span>' . count($rows) . ' ' . (count($rows) === 1 ? 'card' : 'cards')
              . ' (' . $copies . ' ' . ($copies === 1 ? 'copy' : 'copies') . ') · ' . h($date) . ' · ' . $order . ' · Page ' . ($i + 1) . ' of ' . count($pages) . '</span></header><div class="pp-grid">';
        $prev = null;
        foreach ($page as $p) {
            $grp = $p['mana']['order'] ?? null;
            $cls = 'pp-row' . ($magic && $byColor && $prev !== null && $grp !== $prev ? ' pp-new' : '');
            $prev = $grp;
            [$rc, $rcls] = rarity_code($p['rarity'] ?? null, $p['game']);
            $rar = $rc === '' ? '<span></span>' : '<span class="pp-rar ' . (strlen($rc) > 1 ? 'pp-long ' : '') . 'r-' . h($rcls) . '"><span>' . h($rc) . '</span></span>';
            $col = '';
            foreach (color_codes($p['mana'] ?? null) as $c) $col .= '<span class="pp-chip c-' . $c . '">' . $c . '</span>';
            $cond = (string)$p['condition'];
            // Magic sheets only: F foil, E etched foil (blank = normal)
            $fin = $magic ? '<span class="pp-fin">' . (($fc = finish_code($p['finish'] ?? 'normal')) !== '' ? '<i>' . h($fc) . '</i>' : '') . '</span>' : '';
            $out .= '<div class="' . $cls . '"><span class="pp-box"></span><span class="pp-name">' . ($p['quantity'] > 1 ? '<b>' . (int)$p['quantity'] . '×</b>' : '') . h($p['name']) . '</span>'
                  . '<span class="pp-set">' . h(pick_set_label($p)) . '</span>'
                  . '<span class="pp-cond' . ($cond !== 'NM' ? ' played' : '') . '">' . h($cond) . '</span>' . $fin . $rar . '<span class="pp-col' . (count(color_codes($p['mana'] ?? null)) > 3 ? ' pp-many' : '') . '">' . $col . '</span></div>';
        }
        $out .= '</div><footer class="pp-foot"><span><b>Condition</b> NM · <i class="pp-cond played">LP</i> <i class="pp-cond played">MP</i> <i class="pp-cond played">HP</i> = played</span>'
              . ($magic ? '<span><b>Finish</b> <i class="pp-fin"><i>F</i></i> foil <i class="pp-fin"><i>E</i></i> etched'
                        . ($hasRH ? ' <i class="pp-fin"><i>RH</i></i> reverse holo' : '') . '</span>' : '')
              . ($magic ? '<span><b>Rarity</b> <i class="pp-rar r-C"><span>C</span></i> common <i class="pp-rar r-U"><span>U</span></i> uncommon <i class="pp-rar r-R"><span>R</span></i> rare <i class="pp-rar r-M"><span>M</span></i> mythic</span>'
                        . '<span><b>Color</b> <i class="pp-chip c-W">W</i><i class="pp-chip c-U">U</i><i class="pp-chip c-B">B</i><i class="pp-chip c-R">R</i><i class="pp-chip c-G">G</i> <i class="pp-chip c-C">C</i> colorless <i class="pp-chip c-L">L</i> land</span>'
                      : '<span><b>Rarity</b> C common · U uncommon · R rare · RR double rare · IR illustration rare · UR ultra rare · SIR special illustration rare · HR hyper rare</span>')
              . '</footer></div>';
    }
    return $out . '</div>';
}

/** One mana symbol as a small colored circle with its letter, readable in black-and-white print too. */
function mana_pip(string $sym): string {
    $parts = explode('/', strtoupper($sym));
    $known = ['W', 'U', 'B', 'R', 'G', 'C'];
    $cls = array_values(array_filter($parts, fn($p) => in_array($p, $known, true)));
    $class = 'pip ' . ($cls ? 'pip-' . strtolower(implode('', $cls)) : 'pip-generic') . (count($parts) > 1 ? ' pip-hybrid' : '');
    return '<span class="' . h($class) . '">' . h(str_replace('/P', 'ᵖ', strtoupper($sym))) . '</span>';
}

function pick_list(PDO $pdo, int $listId, string $batch, string $sort = 'color'): array {
    $st = $pdo->prepare('SELECT * FROM pull_log WHERE list_id = ? AND batch_id = ? ORDER BY id');
    $st->execute([$listId, $batch]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $snap = json_decode($r['snapshot'], true) ?: [];
        $cols = [];
        foreach (array_keys($snap) as $k) $cols[norm($k)] = $k;
        $setK = set_col($cols); $numK = number_col($cols);
        $out[] = [
            'id' => (int)$r['id'], 'name' => $r['card_name'], 'quantity' => (int)$r['quantity'], 'undone' => $r['undone_at'] !== null,
            'set' => $setK ? (string)$snap[$setK] : '', 'number' => $numK ? (string)$snap[$numK] : '',
            'condition' => (string)($snap['card_condition'] ?? ''), 'finish' => (string)($snap['finish'] ?? 'normal'),
            'image' => $snap['image_url'] ?? null, 'game' => $r['game'], 'mana' => null, 'rarity' => null, 'set_code' => null, 'cat_number' => null,
        ];
    }
    // Rarity (both games); Magic also color and mana cost, because the shelves are sorted that way
    $magic = array_filter($out, fn($p) => $p['game'] === 'mtg');
    if ($out) {
        try {
            $info = card_details($pdo, array_map(fn($p) => ['game' => $p['game'], 'name' => $p['name'], 'set' => $p['set'], 'number' => $p['number'], 'image' => $p['image']], $out));
            foreach ($info as $i => $d) { $out[$i]['mana'] = $d['mana']; $out[$i]['rarity'] = $d['rarity']; $out[$i]['set_code'] = $d['set_code'] ?? null; $out[$i]['cat_number'] = $d['cat_number'] ?? null; }
        } catch (Throwable $e) { /* the pick list still works without them */ }
    }
    if ($magic && $sort === 'color') {
        // White, Blue, Black, Red, Green, Multicolor, Colorless, Land; then mana value, then name
        usort($out, fn($a, $b) => ($a['mana']['order'] ?? 9) <=> ($b['mana']['order'] ?? 9)
            ?: (($a['mana']['group'] ?? '') === 'Multicolor' ? strcmp($a['mana']['color_label'], $b['mana']['color_label']) : 0)
            ?: ($a['mana']['cmc'] ?? 99) <=> ($b['mana']['cmc'] ?? 99)
            ?: strcasecmp($a['name'], $b['name'])
            ?: strcasecmp($a['set'], $b['set']));
        return $out;
    }
    usort($out, fn($a, $b) => strcasecmp($a['set'], $b['set'])
        ?: ((int)$a['number'] <=> (int)$b['number'])
        ?: strnatcasecmp($a['number'], $b['number'])
        ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Puts the cards from one pull back into the inventory. */
function undo_pull(PDO $pdo, int $logId, int $listId): ?string {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM pull_log WHERE id = ? AND list_id = ? AND undone_at IS NULL FOR UPDATE');
        $st->execute([$logId, $listId]);
        $log = $st->fetch(PDO::FETCH_ASSOC);
        if (!$log || !isset(GAME_TABLES[$log['game']])) { $pdo->rollBack(); return null; }
        $t = GAME_TABLES[$log['game']];
        $all = table_columns($pdo, $t, true);
        $qty = qty_col(table_columns($pdo, $t));
        $snap = json_decode($log['snapshot'], true) ?: [];
        $cols = array_values(array_filter(array_keys($snap), fn($k) => in_array($k, $all, true) && !in_array($k, ['id', 'row_key', $qty], true)));
        $vals = array_map(fn($k) => $snap[$k], $cols);
        $cols[] = $qty; $vals[] = (int)$log['quantity'];
        $pdo->prepare('INSERT INTO `' . $t . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ")
                       ON DUPLICATE KEY UPDATE `$qty` = `$qty` + VALUES(`$qty`)")->execute($vals);
        $pdo->prepare('UPDATE pull_log SET undone_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$logId]);
        $pdo->commit();
        return "{$log['quantity']} × {$log['card_name']} ({$log['details']})";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function import_file(PDO $pdo, int $lid, string $path): void {
    import_text($pdo, $lid, (string)file_get_contents($path, false, null, 0, 1048576), 'that file');
}

/** Adds every "4 Lightning Bolt" line of a typed/pasted or uploaded list to the list. */
function import_text(PDO $pdo, int $lid, string $text, string $source = 'the list you typed'): void {
    $r = parse_list($text);
    $copies = 0;
    foreach ($r['items'] as [$name, $qty]) { add_item($pdo, $lid, $name, $qty); $copies += $qty; }
    $n = count($r['items']);
    if ($n) flash('ok', "Added $n " . ($n === 1 ? 'card' : 'different cards') . " ($copies " . ($copies === 1 ? 'copy' : 'copies') . ") from $source.");
    else flash('err', "No cards found in $source. Each line should look like \"4 Lightning Bolt\".");
    if ($r['skipped']) {
        flash('warn', 'Skipped ' . count($r['skipped']) . (count($r['skipped']) === 1 ? ' line' : ' lines') . ' that did not start with a number: '
            . implode(' | ', array_slice($r['skipped'], 0, 5)) . (count($r['skipped']) > 5 ? ' ...' : ''));
    }
}

/* ---------- JSON endpoint: card name suggestions while typing ---------- */
/* ---------- JSON endpoint: kiosk lists staff haven't opened yet (checked every few seconds by the page) ---------- */
function kiosk_waiting(PDO $pdo): array {
    if (!isset(table_columns($pdo, 'card_lists', true)['source'])) return [];
    $rows = $pdo->query("SELECT l.id, l.name, l.game, l.created_at, (SELECT COUNT(*) FROM card_list_items i WHERE i.list_id = l.id) AS n
                         FROM card_lists l WHERE l.source = 'kiosk' AND l.seen_at IS NULL ORDER BY l.id")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => preg_replace('/^Kiosk: /', '', $r['name']), 'game' => $r['game'] === 'mtg' ? 'Magic' : 'Pokémon',
                                'cards' => (int)$r['n'], 'time' => date('g:i a', strtotime($r['created_at']))], $rows);
}
if (isset($_GET['kiosk_waiting'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try { echo json_encode(['lists' => kiosk_waiting(app_db())], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = app_db();
        $game = $_GET['game'] ?? '';
        $q = trim($_GET['suggest']);
        if (!isset(GAME_TABLES[$game]) || mb_strlen($q) < 2) { echo '[]'; exit; }
        $name = name_col(table_columns($pdo, GAME_TABLES[$game]));
        $st = $pdo->prepare("SELECT DISTINCT `$name` FROM `" . GAME_TABLES[$game] . "` WHERE `$name` LIKE ? ORDER BY `$name` LIMIT 15");
        $st->execute([addcslashes($q, '%_\\') . '%']);
        echo json_encode($st->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$fatal = null;
try {
    $pdo = app_db();

    /* ---------- Actions ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = $_POST['do'] ?? '';
        $lid = (int)($_POST['list_id'] ?? 0);
        $hasFile = !empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

        if ($do === 'create') {
            $name = trim($_POST['name'] ?? '');
            $game = $_POST['game'] ?? '';
            if ($name === '' || !isset(GAME_TABLES[$game])) { flash('err', 'Enter a list name and choose a game.'); go(); }
            $notes = trim($_POST['notes'] ?? '');
            $pdo->prepare('INSERT INTO card_lists (name, game, notes) VALUES (?, ?, ?)')
                ->execute([mb_substr($name, 0, 150), $game, $notes !== '' ? $notes : null]);
            $lid = (int)$pdo->lastInsertId();
            flash('ok', "Created \"$name\".");
            if (trim($_POST['cards'] ?? '') !== '') import_text($pdo, $lid, (string)$_POST['cards']);
            if ($hasFile) import_file($pdo, $lid, $_FILES['file']['tmp_name']);
            go($lid);
        }

        $st = $pdo->prepare('SELECT * FROM card_lists WHERE id = ?');
        $st->execute([$lid]);
        $list = $st->fetch(PDO::FETCH_ASSOC);
        if (!$list) { flash('err', 'That list no longer exists.'); go(); }

        if ($do === 'add') {
            $name = trim($_POST['card'] ?? '');
            $qty = max(1, min(999, (int)($_POST['qty'] ?? 1)));
            if ($name === '') { flash('err', 'Enter a card name.'); go($lid); }
            $want = add_item($pdo, $lid, $name, $qty);
            $have = owned($pdo, $list['game'], $name);
            if ($have >= $want) flash('ok', "Added $qty × $name. You own $have, which covers the $want on this list.");
            elseif ($have > 0) flash('warn', "Added $qty × $name. You own $have of the $want on this list, short by " . ($want - $have) . '.');
            else flash('warn', "Added $qty × $name, but it isn't in your inventory.");
        } elseif ($do === 'import') {
            $typed = trim($_POST['cards'] ?? '');
            if ($typed !== '') import_text($pdo, $lid, $typed);
            if ($hasFile) import_file($pdo, $lid, $_FILES['file']['tmp_name']);
            if ($typed === '' && !$hasFile) flash('err', 'Type a list or choose a .txt file first.');
        } elseif ($do === 'remove') {
            $pdo->prepare('DELETE FROM card_list_items WHERE id = ? AND list_id = ?')->execute([(int)($_POST['item_id'] ?? 0), $lid]);
        } elseif ($do === 'pull') {
            $st = $pdo->prepare('SELECT * FROM card_list_items WHERE id = ? AND list_id = ?');
            $st->execute([(int)($_POST['item_id'] ?? 0), $lid]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) { flash('err', 'That card is no longer on this list.'); go($lid); }
            $pulls = array_filter(array_map('intval', (array)($_POST['pull'] ?? [])), fn($n) => $n > 0);
            if (!$pulls) { flash('warn', 'Enter how many copies to pull first.'); go($lid); }
            $batch = bin2hex(random_bytes(8));
            [$n, $lines, $short] = pull_cards($pdo, $list, $item, $pulls, $batch);
            if ($short) flash('warn', 'Some copies were no longer in stock (someone may have just pulled them), so fewer were pulled than entered.');
            if ($n) go($lid, $batch);
            flash('warn', 'Nothing was pulled.');
        } elseif ($do === 'pull_all') {
            $batch = bin2hex(random_bytes(8));
            [$n, $cards, $short] = pull_all($pdo, $list, $batch);
            if ($short) flash('warn', 'Some copies were no longer in stock (someone may have just pulled them), so fewer were pulled.');
            if ($n) go($lid, $batch);
            flash('warn', 'Nothing on this list is in stock to pull.');
        } elseif ($do === 'undo_batch') {
            $st = $pdo->prepare('SELECT id FROM pull_log WHERE list_id = ? AND batch_id = ? AND undone_at IS NULL');
            $st->execute([$lid, (string)($_POST['batch'] ?? '')]);
            $done = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $logId) if (undo_pull($pdo, (int)$logId, $lid)) $done++;
            flash($done ? 'ok' : 'warn', $done ? "Put everything from that pick list back into inventory ($done " . ($done === 1 ? 'line' : 'lines') . ').' : 'That pick list was already put back.');
        } elseif ($do === 'undo') {
            $what = undo_pull($pdo, (int)($_POST['log_id'] ?? 0), $lid);
            flash($what ? 'ok' : 'warn', $what ? "Put back into inventory: $what." : 'That pull was already undone.');
        } elseif ($do === 'delete') {
            $pdo->prepare('DELETE FROM card_lists WHERE id = ?')->execute([$lid]);
            flash('ok', "Deleted \"{$list['name']}\".");
            go();
        }
        go($lid);
    }

    /* ---------- Data for the page ---------- */
    $lists = $pdo->query('SELECT l.*, (SELECT COUNT(*) FROM card_list_items i WHERE i.list_id = l.id) AS n
                          FROM card_lists l ORDER BY l.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $sel = (int)($_GET['list'] ?? 0);
    $cur = null;
    foreach ($lists as $l) if ((int)$l['id'] === $sel) $cur = $l;
    if (!$cur && !$sel && $lists) $cur = $lists[0];
    // Opening a list sent from the kiosk marks it as seen
    if ($cur && ($cur['source'] ?? null) === 'kiosk' && $cur['seen_at'] === null) {
        $pdo->prepare('UPDATE card_lists SET seen_at = NOW() WHERE id = ?')->execute([$cur['id']]);
        foreach ($lists as &$l) if ((int)$l['id'] === (int)$cur['id']) $l['seen_at'] = date('Y-m-d H:i:s');
        unset($l);
    }
    $kioskWaiting = kiosk_waiting($pdo);

    $items = []; $history = []; $images = []; $picks = []; $batch = null; $pullable = 0;
    $shortCards = $shortCopies = 0;
    if ($cur) {
        $st = $pdo->prepare('SELECT * FROM card_list_items WHERE list_id = ? ORDER BY name');
        $st->execute([$cur['id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $stock = stock_by_names($pdo, $cur['game'], array_column($rows, 'name'));
        $allCopies = array_merge([], ...array_values($stock));
        resolve_images($pdo, $cur['game'], $allCopies);
        $images = array_column($allCopies, 'image_url', 'id');
        $st = $pdo->prepare('SELECT LOWER(item_name), SUM(quantity) FROM pull_log WHERE list_id = ? AND undone_at IS NULL GROUP BY LOWER(item_name)');
        $st->execute([$cur['id']]);
        $pulled = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $it) {
            $k = mb_strtolower($it['name']);
            $it['pref'] = clean_printing($it['pref'] ?? null);              // printing chosen at the kiosk, if any
            $it['copies'] = prefer_printing($stock[$k] ?? [], $it['pref']);
            $it['pref_stock'] = array_sum(array_map(fn($c) => !empty($c['preferred']) ? $c['quantity'] : 0, $it['copies']));
            $it['owned'] = array_sum(array_column($it['copies'], 'quantity'));
            $it['pulled'] = (int)($pulled[$k] ?? 0);
            $it['remaining'] = max(0, $it['quantity'] - $it['pulled']);
            if ($it['owned'] < $it['remaining']) { $shortCards++; $shortCopies += $it['remaining'] - $it['owned']; }
            $items[] = $it;
        }
        // Cards you have first: in stock, then partly in stock, then already pulled, then not in stock at the bottom
        $rank = fn($i) => $i['remaining'] === 0 ? 2 : ($i['owned'] >= $i['remaining'] ? 0 : ($i['owned'] > 0 ? 1 : 3));
        usort($items, fn($a, $b) => $rank($a) <=> $rank($b) ?: strcasecmp($a['name'], $b['name']));
        $pullable = array_sum(array_map(fn($i) => min($i['remaining'], $i['owned']), $items));
        $batch = preg_match('/^[0-9a-f]{16}$/', $_GET['picklist'] ?? '') ? $_GET['picklist'] : null;
        $pickSort = ($_GET['sort'] ?? '') === 'set' ? 'set' : 'color';
        $picks = $batch ? pick_list($pdo, (int)$cur['id'], $batch, $pickSort) : [];
        $pickMagic = (bool)array_filter($picks, fn($p) => $p['game'] === 'mtg');
        $st = $pdo->prepare('SELECT * FROM pull_log WHERE list_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$cur['id']]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$gameLabel = fn($g) => $g === 'mtg' ? 'Magic' : 'Pokémon';

page_head('List Builder', 'list-builder', 'lists-page');
?>
        <div class="page-top">
            <h1>List builder</h1>
            <button type="button" id="openNew" class="primary">Create list</button>
        </div>

        <?php if ($fatal): ?><div class="notice error" role="alert"><?= h($fatal) ?></div><?php endif; ?>
        <?php foreach ($flashes as [$type, $msg]): ?>
            <div class="notice <?= $type === 'ok' ? 'success' : ($type === 'warn' ? 'warn' : 'error') ?>" role="status"><?= h($msg) ?></div>
        <?php endforeach; ?>

        <div id="kioskBanner" class="kiosk-banner" role="status" aria-live="polite"<?= empty($kioskWaiting) ? ' hidden' : '' ?>>
            <strong id="kioskTitle"></strong>
            <div id="kioskLinks" class="kiosk-links"></div>
        </div>

        <div class="lists-layout">
            <nav class="list-index" aria-label="Your lists">
                <?php if (!$lists): ?>
                    <p class="note">No lists yet. Create one to get started.</p>
                <?php endif; ?>
                <?php foreach ($lists as $l): ?>
                    <a href="list-builder.php?list=<?= (int)$l['id'] ?>"<?= $cur && $cur['id'] === $l['id'] ? ' aria-current="true"' : '' ?>>
                        <strong><?= h($l['name']) ?><?php if (($l['source'] ?? null) === 'kiosk' && $l['seen_at'] === null): ?> <span class="tag-new">New</span><?php endif; ?></strong>
                        <span><?= $gameLabel($l['game']) ?>, <?= (int)$l['n'] ?> cards<?= ($l['source'] ?? null) === 'kiosk' ? ' · from the kiosk' : '' ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($cur): ?>
            <section class="list-detail">
                <h2><?= h($cur['name']) ?></h2>
                <p class="note"><?= $gameLabel($cur['game']) ?> list<?= $cur['notes'] ? ': ' . h($cur['notes']) : '' ?></p>

                <form method="post" class="add-form">
                    <input type="hidden" name="do" value="add">
                    <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                    <label for="card" class="visually-hidden">Card name</label>
                    <input type="text" id="card" name="card" list="owned-names" placeholder="Card name" required autocomplete="off">
                    <datalist id="owned-names"></datalist>
                    <label for="qty" class="visually-hidden">Quantity</label>
                    <input type="number" id="qty" name="qty" value="1" min="1" max="999">
                    <button type="submit" class="primary">Add card</button>
                </form>

                <details class="paste list-paste"<?= isset($_GET['paste']) ? ' open' : '' ?>>
                    <summary>Add several cards at once</summary>
                    <form method="post" enctype="multipart/form-data" class="bulk-form">
                        <input type="hidden" name="do" value="import">
                        <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                        <label for="cards">Type or paste a list, one card per line, starting with how many</label>
                        <textarea id="cards" name="cards" rows="7" placeholder="4 Lightning Bolt&#10;2 Counterspell&#10;3 Charizard ex OBF 125"></textarea>
                        <div class="bulk-file">
                            <label for="file">Or import a .txt file</label>
                            <input type="file" id="file" name="file" accept=".txt,text/plain">
                        </div>
                        <button type="submit" class="primary">Add to list</button>
                    </form>
                </details>

                <?php if ($picks): $pickTotal = array_sum(array_map(fn($p) => $p['undone'] ? 0 : $p['quantity'], $picks)); ?>
                <section class="picklist" aria-labelledby="picklist-title">
                    <div class="picklist-top">
                        <div>
                            <h3 id="picklist-title">Pick list</h3>
                            <p class="note"><?= h($cur['name']) ?> · <?= $pickTotal ?> <?= $pickTotal === 1 ? 'card' : 'cards' ?> taken out of inventory. Take these off the shelf<?= $pickMagic && $pickSort === 'color' ? ' (sorted by color, then mana value)' : ' (sorted by set and number)' ?>.</p>
                            <?php if ($pickMagic): $pickUrl = 'list-builder.php?list=' . (int)$cur['id'] . '&picklist=' . $batch; ?>
                            <p class="pick-sort">Sort by:
                                <?php if ($pickSort === 'color'): ?><strong>Color &amp; mana value</strong> · <a href="<?= h($pickUrl) ?>&amp;sort=set">Set &amp; number</a>
                                <?php else: ?><a href="<?= h($pickUrl) ?>">Color &amp; mana value</a> · <strong>Set &amp; number</strong><?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="picklist-actions">
                            <button type="button" class="primary" onclick="window.print()">Print</button>
                            <a class="link" href="list-builder.php?list=<?= (int)$cur['id'] ?>">Done</a>
                        </div>
                    </div>
                    <div class="table-wrap">
                    <table class="picklist-table">
                        <thead><tr><th class="check" aria-label="Picked"></th><th class="num">Qty</th><th>Card</th><th>Rarity</th><?php if ($pickMagic): ?><th>Color</th><th>Mana cost</th><th class="num">MV</th><?php endif; ?><th>Set</th><th>Number</th><th>Condition</th><th>Finish</th></tr></thead>
                        <tbody>
                        <?php foreach ($picks as $pk): ?>
                            <tr<?= $pk['undone'] ? ' class="undone"' : '' ?>>
                                <td class="check"><input type="checkbox" aria-label="Picked <?= h($pk['name']) ?>"<?= $pk['undone'] ? ' disabled' : '' ?>></td>
                                <td class="num"><strong><?= $pk['quantity'] ?></strong></td>
                                <td><?= h($pk['name']) ?><?= $pk['undone'] ? ' <span class="note">(put back)</span>' : '' ?></td>
                                <td><?= $pk['rarity'] ? '<span class="rarity rarity-' . h($pk['rarity']['class']) . '">' . h($pk['rarity']['label']) . '</span>' : '–' ?></td>
                                <?php if ($pickMagic): $mn = $pk['mana']; ?>
                                <td><?= $mn ? '<span class="color-chip color-' . strtolower(h($mn['group'])) . '">' . h($mn['color_label']) . '</span>' : '–' ?></td>
                                <td class="mana"><?php if ($mn && $mn['symbols']): foreach ($mn['symbols'] as $sym): ?><?= mana_pip($sym) ?><?php endforeach; elseif ($mn): ?><span class="note">none</span><?php else: ?>–<?php endif; ?></td>
                                <td class="num"><?= $mn && $mn['cmc'] !== null ? h(rtrim(rtrim(number_format($mn['cmc'], 1), '0'), '.')) : '–' ?></td>
                                <?php endif; ?>
                                <td><?= h($pk['set'] ?: '–') ?></td>
                                <td><?= h($pk['number'] ?: '–') ?></td>
                                <td><span class="tag cond cond-<?= strtolower(h($pk['condition'])) ?>"><?= h(CONDITIONS[$pk['condition']] ?? $pk['condition']) ?></span></td>
                                <td><?= h(FINISHES[$pk['finish']] ?? $pk['finish']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($pickTotal): ?>
                    <form method="post" class="picklist-undo" onsubmit="return confirm('Put every card on this pick list back into the inventory?');">
                        <input type="hidden" name="do" value="undo_batch">
                        <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                        <input type="hidden" name="batch" value="<?= h($batch) ?>">
                        <button type="submit" class="link danger">Undo this whole pull</button>
                    </form>
                    <?php endif; ?>
                    <?= pick_print_html($picks, $cur['name'], $pickSort === 'color') ?>
                </section>
                <?php endif; ?>

                <?php if ($items): ?>
                    <div class="pull-all">
                        <form method="post" onsubmit="return confirm('Take <?= $pullable ?> <?= $pullable === 1 ? 'card' : 'cards' ?> out of the inventory and make a pick list?');">
                            <input type="hidden" name="do" value="pull_all">
                            <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                            <button type="submit" class="primary"<?= $pullable ? '' : ' disabled' ?>>Pull all available<?= $pullable ? " ($pullable)" : '' ?></button>
                        </form>
                        <span class="note"><?= $pullable ? 'Takes everything still needed that is in stock (printings customers chose first, then best condition) and shows a pick list.' : 'Nothing still needed is in stock.' ?></span>
                    </div>
                    <p class="summary-line">
                        <?php if ($shortCards): ?>
                            <strong>Short on <?= $shortCards ?> cards</strong> (<?= $shortCopies ?> copies still needed).
                        <?php elseif (!array_sum(array_column($items, 'remaining'))): ?>
                            <strong>Everything on this list has been pulled.</strong>
                        <?php else: ?>
                            <strong>Everything still needed is in stock.</strong>
                        <?php endif; ?>
                    </p>
                    <div class="table-wrap">
                    <table class="list-table">
                        <thead><tr><th>Card</th><th class="num">Wanted</th><th class="num">Pulled</th><th class="num">In stock</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it):
                            $rem = $it['remaining'];
                            $status = $rem === 0 ? ['ok', 'All pulled'] : ($it['owned'] >= $rem ? ['ok', 'In stock'] : ($it['owned'] > 0 ? ['warn', 'Short by ' . ($rem - $it['owned'])] : ['bad', 'Not in stock']));
                            $iid = (int)$it['id']; ?>
                            <tr>
                                <td><?= h($it['name']) ?>
                                    <?php if ($it['pref']): ?>
                                    <div class="pref-note"><span class="tag pref">Customer chose</span> <?= h(printing_label($it['pref'])) ?><?php
                                        if ($rem > 0 && !$it['pref_stock']): ?> <span class="pref-gone">· none left in stock, ask the customer</span><?php
                                        elseif ($rem > 0 && $it['pref_stock'] < $rem): ?> <span class="pref-some">· <?= (int)$it['pref_stock'] ?> of these in stock, the rest any printing</span><?php endif; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int)$it['quantity'] ?></td>
                                <td class="num"><?= $it['pulled'] ?: '–' ?></td>
                                <td class="num"><?= (int)$it['owned'] ?></td>
                                <td><span class="status <?= $status[0] ?>"><?= $status[1] ?></span></td>
                                <td class="actions">
                                    <?php if ($it['copies']): ?>
                                    <button type="button" class="link pull-toggle" aria-expanded="false" aria-controls="pull-<?= $iid ?>">Pull…</button>
                                    <?php endif; ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="do" value="remove">
                                        <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                                        <input type="hidden" name="item_id" value="<?= $iid ?>">
                                        <button type="submit" class="link" aria-label="Remove <?= h($it['name']) ?> from the list">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            <?php if ($it['copies']): ?>
                            <tr class="pull-row" id="pull-<?= $iid ?>" hidden>
                                <td colspan="6">
                                    <form method="post" class="pull-form" data-remaining="<?= $rem ?>">
                                        <input type="hidden" name="do" value="pull">
                                        <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                                        <input type="hidden" name="item_id" value="<?= $iid ?>">
                                        <p class="note">Choose how many to take out of each copy in stock. The inventory goes down when you click Pull.<?= $it['pref'] ? ' The copy the customer chose is at the top, and "Fill" uses it first.' : '' ?></p>
                                        <ul class="pull-copies">
                                        <?php foreach ($it['copies'] as $cp): $img = $images[$cp['id']] ?? null; ?>
                                            <li class="pull-copy<?= !empty($cp['preferred']) ? ' preferred' : '' ?>" data-image="<?= h($img ?: '') ?>" data-name="<?= h($cp['name']) ?>"
                                                data-details="<?= h(implode(' · ', array_filter([$cp['set_name'], $cp['num']]))) ?>"
                                                data-cond="<?= h($cp['card_condition']) ?>" data-finish="<?= h($cp['finish'] !== 'normal' ? (FINISHES[$cp['finish']] ?? $cp['finish']) : '') ?>"
                                                data-qty="<?= (int)$cp['quantity'] ?>">
                                                <?php if (!empty($cp['preferred'])): ?><span class="tag pref">Customer's pick</span><?php endif; ?>
                                                <span class="tag cond cond-<?= strtolower(h($cp['card_condition'])) ?>"><?= h($cp['card_condition']) ?></span>
                                                <?php if ($cp['finish'] !== 'normal'): ?><span class="tag finish"><?= h(FINISHES[$cp['finish']] ?? $cp['finish']) ?></span><?php endif; ?>
                                                <span class="copy-set"><?= h(implode(' · ', array_filter([$cp['set_name'], $cp['num']]))) ?><?= $cp['name'] !== $it['name'] ? ' — ' . h($cp['name']) : '' ?></span>
                                                <span class="copy-have"><?= (int)$cp['quantity'] ?> in stock</span>
                                                <label class="visually-hidden" for="pull-<?= $iid ?>-<?= (int)$cp['id'] ?>">How many to pull</label>
                                                <input type="number" id="pull-<?= $iid ?>-<?= (int)$cp['id'] ?>" name="pull[<?= (int)$cp['id'] ?>]" value="0" min="0" max="<?= (int)$cp['quantity'] ?>">
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                        <div class="pull-actions">
                                            <?php if ($rem > 0): ?><button type="button" class="link fill">Fill the <?= $rem ?> still needed</button><?php endif; ?>
                                            <span class="pull-total" aria-live="polite"></span>
                                            <button type="submit" class="primary">Pull from inventory</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($history): ?>
                    <details class="pull-history"<?= isset($_GET['history']) ? ' open' : '' ?>>
                        <summary>Recently pulled from this list</summary>
                        <table>
                            <thead><tr><th>When</th><th>Card</th><th>Copy</th><th class="num">Qty</th><th></th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($history as $hrow): ?>
                                <tr<?= $hrow['undone_at'] ? ' class="undone"' : '' ?>>
                                    <td><?= h(date('M j, g:i a', strtotime($hrow['pulled_at']))) ?></td>
                                    <td><?= h($hrow['card_name']) ?></td>
                                    <td><?= h($hrow['details']) ?></td>
                                    <td class="num"><?= (int)$hrow['quantity'] ?></td>
                                    <td><?php if (!empty($hrow['batch_id'])): ?><a class="link" href="list-builder.php?list=<?= (int)$cur['id'] ?>&amp;picklist=<?= h($hrow['batch_id']) ?>">Pick list</a><?php endif; ?></td>
                                    <td class="actions">
                                        <?php if ($hrow['undone_at']): ?>
                                            <span class="note">Put back</span>
                                        <?php else: ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="do" value="undo">
                                            <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                                            <input type="hidden" name="log_id" value="<?= (int)$hrow['id'] ?>">
                                            <button type="submit" class="link">Undo</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="note">This list is empty. Add a card above, or use "Add several cards at once" to type, paste or import a list with lines like "4 Lightning Bolt".</p>
                <?php endif; ?>

                <form method="post" class="delete-form" onsubmit="return confirm('Delete this list? This cannot be undone.');">
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="list_id" value="<?= (int)$cur['id'] ?>">
                    <button type="submit" class="link danger">Delete this list</button>
                </form>
            </section>
            <?php endif; ?>
        </div>

        <dialog id="newList">
            <form method="post" enctype="multipart/form-data" class="dlg">
                <h2>Create a list</h2>
                <label>List name
                    <input type="text" name="name" required maxlength="150" placeholder="Standard deck, trade binder...">
                </label>
                <div class="field" role="radiogroup" aria-labelledby="gameLabel">
                    <span id="gameLabel">Which game is this list for?</span>
                    <div class="game-picker">
                        <label><input type="radio" name="game" value="pokemon" checked><span>Pokémon</span></label>
                        <label><input type="radio" name="game" value="mtg"><span>Magic</span></label>
                    </div>
                </div>
                <label>Notes (optional)
                    <textarea name="notes" rows="3"></textarea>
                </label>
                <label>Cards (optional): type or paste a list, one per line
                    <textarea name="cards" rows="6" placeholder="4 Lightning Bolt&#10;2 Counterspell"></textarea>
                </label>
                <label>Or import a .txt file (optional)
                    <input type="file" name="file" accept=".txt,text/plain">
                </label>
                <div class="dlg-actions">
                    <button type="button" id="cancelNew">Cancel</button>
                    <button type="submit" name="do" value="create" class="primary">Create list</button>
                </div>
            </form>
        </dialog>

<script>
/* Lists sent from the customer kiosk: shown at the top as soon as they arrive (checked every 10 seconds) */
(() => {
  const banner = document.getElementById('kioskBanner'), title = document.getElementById('kioskTitle'), links = document.getElementById('kioskLinks');
  const current = <?= $cur ? (int)$cur['id'] : 0 ?>;
  let known = null;
  function show(lists) {
    lists = lists.filter(l => l.id !== current);
    banner.hidden = !lists.length;
    if (!lists.length) { known = ''; document.title = 'List Builder | TCG Database'; return; }
    title.textContent = lists.length === 1 ? 'A customer sent a list from the kiosk:' : `${lists.length} customers sent lists from the kiosk:`;
    links.replaceChildren(...lists.map(l => {
      const a = document.createElement('a');
      a.className = 'link'; a.href = 'list-builder.php?list=' + l.id;
      a.textContent = `#${l.id} ${l.name} · ${l.game}, ${l.cards} ${l.cards === 1 ? 'card' : 'cards'} · ${l.time}`;
      return a;
    }));
    const ids = lists.map(l => l.id).join(',');
    if (known !== null && lists.some(l => !known.split(',').includes(String(l.id)))) {   // a new one just arrived
      banner.classList.remove('flash'); void banner.offsetWidth; banner.classList.add('flash');
    }
    document.title = '(' + lists.length + ') List Builder | TCG Database';
    known = ids;
  }
  show(<?= json_encode($kioskWaiting, JSON_UNESCAPED_UNICODE) ?>);
  setInterval(async () => {
    try {
      const d = await fetch('list-builder.php?kiosk_waiting=1').then(r => r.json());
      if (d.lists) show(d.lists);
    } catch (e) { /* try again next time */ }
  }, 10000);
})();

const dlg = document.getElementById('newList');

// Pull panels: open/close, "fill what's still needed", running total
document.querySelectorAll('.pull-toggle').forEach(btn => btn.addEventListener('click', () => {
  const row = document.getElementById(btn.getAttribute('aria-controls'));
  row.hidden = !row.hidden;
  btn.setAttribute('aria-expanded', String(!row.hidden));
  btn.textContent = row.hidden ? 'Pull…' : 'Close';
}));
document.querySelectorAll('.pull-form').forEach(form => {
  const inputs = [...form.querySelectorAll('input[type=number]')];
  const total = form.querySelector('.pull-total'), submit = form.querySelector('button[type=submit]');
  const update = () => {
    const n = inputs.reduce((s, i) => s + Math.max(0, Math.min(+i.max, parseInt(i.value, 10) || 0)), 0);
    total.textContent = n ? `${n} ${n === 1 ? 'card' : 'cards'} will be taken out of inventory` : '';
    submit.disabled = n === 0;
  };
  inputs.forEach(i => i.addEventListener('input', update));
  const fill = form.querySelector('.fill');
  if (fill) fill.addEventListener('click', () => {
    let left = +form.dataset.remaining;               // customer's pick first, then best condition (the list is already in that order)
    inputs.forEach(i => { const n = Math.min(left, +i.max); i.value = n; left -= n; });
    update();
  });
  form.addEventListener('submit', e => {
    const n = inputs.reduce((s, i) => s + (parseInt(i.value, 10) || 0), 0);
    if (!n || !confirm(`Take ${n} ${n === 1 ? 'card' : 'cards'} out of the inventory?`)) e.preventDefault();
  });
  update();
});

document.getElementById('openNew').addEventListener('click', () => dlg.showModal());
document.getElementById('cancelNew').addEventListener('click', () => dlg.close());

// Suggest names from the inventory while typing (searches the whole collection, not just the first few thousand)
const cardInput = document.getElementById('card'), names = document.getElementById('owned-names');
if (cardInput) {
  let timer, last = '';
  cardInput.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = cardInput.value.trim();
      if (q.length < 2 || q === last) return;
      last = q;
      try {
        const r = await fetch('list-builder.php?' + new URLSearchParams({ suggest: q, game: <?= json_encode($cur['game'] ?? '') ?> }));
        const list = await r.json();
        if (!Array.isArray(list) || cardInput.value.trim() !== q) return;
        names.replaceChildren(...list.map(n => Object.assign(document.createElement('option'), { value: n })));
      } catch (e) { /* suggestions are optional */ }
    }, 200);
  });
}
</script>
<?php page_foot(); ?>
