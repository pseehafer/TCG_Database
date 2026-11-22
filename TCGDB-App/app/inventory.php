<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/prices.php';
require_staff(isset($_GET['action']));

/* ---------- JSON endpoint: inventory.php?action=card_info&game=mtg&id=12  (details for the card window) ---------- */
if (($_GET['action'] ?? '') === 'card_info') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = app_db();
        $game = $_GET['game'] ?? '';
        if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Unknown game.');
        $t = GAME_TABLES[$game];
        $c = table_columns($pdo, $t);
        $nameC = name_col($c); $setC = set_col($c); $numC = number_col($c);
        $st = $pdo->prepare("SELECT t.`$nameC` AS name, " . ($setC ? "t.`$setC`" : 'NULL') . ' AS set_name, ' . ($numC ? "t.`$numC`" : 'NULL') . " AS num, t.image_url, c.ext_id
                             FROM `$t` t LEFT JOIN card_catalog c ON c.game = ? AND c.image_url = t.image_url WHERE t.id = ?");
        $st->execute([$game, (int)($_GET['id'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('That card is no longer in the inventory.');
        $ext = priced_ext($pdo, $game, (string)$row['name'], $row['set_name'], $row['num'], $row['ext_id']);
        echo json_encode(['info' => card_info($pdo, $game, $ext)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- JSON endpoint: inventory.php?action=prices  {items: [{k, game, id | catalog_id, finish?}]} ---------- */
if (($_GET['action'] ?? '') === 'prices') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        echo json_encode(['prices' => (object)prices_for(app_db(), (array)($in['items'] ?? []))]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Prices are unavailable right now.']);
    }
    exit;
}

/**
 * One page of one game's table, sorted by name then id, starting after $after = [name, id].
 * Uses the idx_name index, so it stays fast no matter how far you scroll.
 */
function page_rows(PDO $pdo, string $game, array $f, ?array $after, int $limit): array {
    $t = GAME_TABLES[$game];
    $c = table_columns($pdo, $t);
    $name = name_col($c); $qty = qty_col($c); $set = set_col($c); $num = number_col($c);
    if (!$name) throw new RuntimeException("The $t table needs a name column.");

    [$where, $params] = filters_sql($c, $f);
    $filtered = (bool)$where;
    if ($after) {
        $where[] = "(`$name` > ? OR (`$name` = ? AND id > ?))";
        array_push($params, $after[0], $after[0], (int)$after[1]);
    }
    $sql = "SELECT id, `$name` AS name, " . ($qty ? "`$qty`" : '1') . ' AS quantity, '
         . ($set ? "`$set`" : 'NULL') . ' AS set_name, ' . ($num ? "`$num`" : 'NULL') . ' AS num, '
         . "card_condition, finish, image_url FROM `$t`"
         // With a search/filter, scanning the table beats walking the name index row by row
         . ($filtered ? ' IGNORE INDEX FOR ORDER BY (idx_name)' : '')
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY `$name`, id LIMIT " . ($limit + 1);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['game'] = $game; $r['id'] = (int)$r['id']; $r['quantity'] = (int)$r['quantity']; }
    return $rows;
}

/** WHERE pieces for the search box and the condition/finish filters. */
function filters_sql(array $c, array $f): array {
    $where = []; $params = [];
    $name = name_col($c); $set = set_col($c); $num = number_col($c);
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $or = ["`$name` LIKE ?"]; $params[] = $like;
        if ($set) { $or[] = "`$set` LIKE ?"; $params[] = $like; }
        if ($num) { $or[] = "`$num` LIKE ?"; $params[] = addcslashes($f['q'], '%_\\') . '%'; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if ($f['cond'] !== '')   { $where[] = 'card_condition = ?'; $params[] = $f['cond']; }
    if ($f['finish'] !== '') { $where[] = 'finish = ?'; $params[] = $f['finish']; }
    return [$where, $params];
}

/* ---------- JSON endpoint: inventory.php?action=list ---------- */
/** Which columns of a game's table hold what. 'extra' = any other columns the store added (e.g. regulation). */
function card_fields(PDO $pdo, string $game): array {
    $c = table_columns($pdo, GAME_TABLES[$game]);
    $f = ['name' => name_col($c), 'set' => set_col($c), 'number' => number_col($c), 'qty' => qty_col($c)];
    $f['extra'] = array_values(array_filter($c, fn($col) => !in_array($col, [$f['name'], $f['set'], $f['number'], $f['qty'], 'card_condition', 'finish'], true)));
    return $f;
}

/** One inventory row in the same shape the grid uses. */
function card_out(PDO $pdo, string $game, int $id): ?array {
    $f = card_fields($pdo, $game);
    $st = $pdo->prepare('SELECT id, `' . $f['name'] . '` AS name, ' . ($f['qty'] ? '`' . $f['qty'] . '`' : '1') . ' AS quantity, '
        . ($f['set'] ? '`' . $f['set'] . '`' : 'NULL') . ' AS set_name, ' . ($f['number'] ? '`' . $f['number'] . '`' : 'NULL') . ' AS num,
        card_condition, finish, image_url FROM `' . GAME_TABLES[$game] . '` WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $rows = [$r];
    resolve_images($pdo, $game, $rows);
    $r = $rows[0];
    $r['game'] = $game; $r['id'] = (int)$r['id']; $r['quantity'] = (int)$r['quantity'];
    return $r;
}

/* ---------- JSON endpoints for adding / editing single cards ---------- */
$act = $_GET['action'] ?? '';
if (in_array($act, ['catalog_search', 'get_card', 'save_card', 'delete_card'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = app_db();
        $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
        $game = $in['game'] ?? '';
        if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Choose a game.');
        $t = GAME_TABLES[$game];
        $f = card_fields($pdo, $game);

        if ($act === 'catalog_search') {
            // Printings in the card catalog whose name starts with what was typed, newest first
            $key = name_key((string)($in['q'] ?? ''));
            if (mb_strlen($key) < 2) { echo json_encode(['results' => []]); exit; }
            $st = $pdo->prepare("SELECT id, name, set_code, set_name, set_alt, number, released, image_url FROM card_catalog
                                 WHERE game = ? AND name_key LIKE ? AND image_url NOT LIKE '%/tcgp/%'   -- no Pokémon TCG Pocket (phone game) cards
                                 ORDER BY (name_key = ?) DESC, name, released DESC LIMIT 60");
            $st->execute([$game, addcslashes($key, '%_\\') . '%', $key]);
            $denoms = [];
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // Fill set and number the way this store's spreadsheets write them
                if ($game === 'pokemon') {
                    $setValue = $r['set_alt'] ?: $r['set_name'];
                    $num = (string)$r['number'];
                    if ($f['set'] && $f['number'] && !array_key_exists($setValue, $denoms)) {   // "086" -> "086/132" using another card from that set
                        $d = $pdo->prepare('SELECT `' . $f['number'] . '` FROM `' . $t . '` WHERE `' . $f['set'] . '` = ? AND `' . $f['number'] . '` LIKE ? LIMIT 1');
                        $d->execute([$setValue, '%/%']);
                        $x = $d->fetchColumn();
                        $denoms[$setValue] = $x ? explode('/', $x, 2)[1] : null;
                    }
                    $numValue = $denoms[$setValue] ?? null ? $num . '/' . $denoms[$setValue] : $num;
                } else {
                    $setValue = $r['set_name'] ?: $r['set_code'];
                    $numValue = (string)$r['number'];
                }
                // Other details (e.g. regulation mark) copied from a copy of this printing already in stock
                $extra = new stdClass();
                if ($f['extra'] && $f['set'] && $f['number']) {
                    $x = $pdo->prepare('SELECT `' . implode('`, `', $f['extra']) . '` FROM `' . $t . '` WHERE `' . $f['set'] . '` = ? AND (`' . $f['number'] . '` = ? OR `' . $f['number'] . '` LIKE ?) LIMIT 1');
                    $x->execute([$setValue, $numValue, explode('/', $numValue)[0] . '/%']);
                    $extra = $x->fetch(PDO::FETCH_ASSOC) ?: new stdClass();
                }
                $out[] = ['catalog_id' => (int)$r['id'], 'name' => $r['name'], 'extra' => $extra, 'set_label' => trim(($r['set_name'] ?? '') . ($r['set_alt'] ? ' (' . $r['set_alt'] . ')' : '')),
                          'set' => (string)$setValue, 'number' => $numValue, 'released' => $r['released'], 'image' => $r['image_url']];
            }
            echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'get_card') {
            $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
            $st->execute([(int)($in['id'] ?? 0)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('That card is no longer in the inventory. Refresh the page.');
            echo json_encode(['card' => [
                'id' => (int)$row['id'], 'name' => $row[$f['name']], 'set' => $f['set'] ? $row[$f['set']] : null, 'number' => $f['number'] ? $row[$f['number']] : null,
                'condition' => $row['card_condition'], 'finish' => $row['finish'], 'quantity' => $f['qty'] ? (int)$row[$f['qty']] : 1,
                'extra' => array_combine($f['extra'], array_map(fn($c) => $row[$c], $f['extra'])) ?: new stdClass(),
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'delete_card') {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ? FOR UPDATE");
            $st->execute([(int)($in['id'] ?? 0)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $pdo->rollBack(); throw new RuntimeException('That card is no longer in the inventory.'); }
            $snap = $row; unset($snap['id'], $snap['row_key'], $snap[$f['qty']]);
            $pdo->prepare('INSERT INTO pull_log (list_id, item_name, game, card_name, details, snapshot, quantity) VALUES (NULL, ?, ?, ?, ?, ?, ?)')
                ->execute(['Inventory page (removed)', $game, $row[$f['name']], mb_substr(implode(' · ', array_filter([$f['set'] ? $row[$f['set']] : '', $f['number'] ? $row[$f['number']] : '', $row['card_condition']])), 0, 255),
                           json_encode($snap, JSON_UNESCAPED_UNICODE), $f['qty'] ? (int)$row[$f['qty']] : 1]);
            $logId = (int)$pdo->lastInsertId();
            $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$row['id']]);
            $pdo->commit();
            echo json_encode(['removed' => true, 'quantity' => $f['qty'] ? (int)$row[$f['qty']] : 1, 'log_id' => $logId]);
            exit;
        }

        // ---- save_card: add a new card (or add to an identical one), or edit an existing card ----
        $v = $in['values'] ?? [];
        $name = trim((string)($v['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Enter the card name.');
        $cond = normalize_condition((string)($v['condition'] ?? ''));
        $fin = normalize_finish((string)($v['finish'] ?? ''), $game);
        if ($cond === null || !isset(CONDITIONS[$cond])) throw new RuntimeException('Choose a condition.');
        if ($fin === null || !isset(FINISHES[$fin])) throw new RuntimeException('Choose a finish.');
        $qtyVal = (int)($v['quantity'] ?? 0);
        if ($qtyVal < 1 || $qtyVal > 9999) throw new RuntimeException('Quantity must be between 1 and 9999.');
        $cols = [$f['name'] => mb_substr($name, 0, 255), 'card_condition' => $cond, 'finish' => $fin];
        $clean = fn($x) => ($x = trim((string)$x)) === '' ? null : mb_substr($x, 0, 255);
        if ($f['set']) $cols[$f['set']] = $clean($v['set'] ?? '');
        if ($f['number']) $cols[$f['number']] = $clean($v['number'] ?? '');
        foreach ($f['extra'] as $c) if (array_key_exists($c, (array)($v['extra'] ?? []))) $cols[$c] = $clean($v['extra'][$c]);
        if (!empty($in['image'])) $cols['image_url'] = mb_substr((string)$in['image'], 0, 500);   // picture of the printing chosen from the catalog
        if (!$f['qty']) throw new RuntimeException("The $t table has no quantity column.");
        $qty = $f['qty'];

        $pdo->beginTransaction();
        $id = (int)($in['id'] ?? 0);
        $merged = false;
        if ($id) {
            $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) { $pdo->rollBack(); throw new RuntimeException('That card is no longer in the inventory. Refresh the page.'); }
            // If the name, set or number changed, its picture is matched again
            $changedIdentity = $old[$f['name']] !== $cols[$f['name']] || ($f['set'] && $old[$f['set']] !== $cols[$f['set']]) || ($f['number'] && $old[$f['number']] !== $cols[$f['number']]);
            if ($changedIdentity && !isset($cols['image_url'])) $cols['image_url'] = null;
            $cols[$qty] = $qtyVal;
            try {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
                $pdo->prepare("UPDATE `$t` SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) != 1062) throw $e;
                // Now identical to another copy already in stock: combine them into that one
                $where = implode(' AND ', array_map(fn($c) => "LOWER(TRIM(COALESCE(`$c`, ''))) = LOWER(TRIM(COALESCE(?, '')))", array_keys(array_diff_key($cols, [$qty => 1, 'image_url' => 1]))));
                $st = $pdo->prepare("SELECT id FROM `$t` WHERE id <> ? AND $where LIMIT 1");
                $st->execute([$id, ...array_values(array_diff_key($cols, [$qty => 1, 'image_url' => 1]))]);
                $other = (int)$st->fetchColumn();
                if (!$other) throw $e;
                $pdo->prepare("UPDATE `$t` SET `$qty` = `$qty` + ? WHERE id = ?")->execute([$qtyVal, $other]);
                $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$id]);
                $id = $other; $merged = true;
            }
        } else {
            $cols[$qty] = $qtyVal;
            $ins = $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ")
                           ON DUPLICATE KEY UPDATE `$qty` = `$qty` + VALUES(`$qty`), id = LAST_INSERT_ID(id)");
            $ins->execute(array_values($cols));
            $id = (int)$pdo->lastInsertId();
            $merged = $ins->rowCount() === 2;   // 2 = added to a copy that was already in stock
        }
        $pdo->commit();
        echo json_encode(['card' => card_out($pdo, $game, $id), 'merged' => $merged], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e instanceof PDOException ? 'The database refused that change: ' . $e->getMessage() : $e->getMessage()]);
    }
    exit;
}

/* ---------- JSON endpoint: inventory.php?action=adjust  {game, id, delta: 1 | -1} ---------- */
if (($_GET['action'] ?? '') === 'adjust' || ($_GET['action'] ?? '') === 'undo_adjust') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Use POST.');
        $pdo = app_db();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $game = $in['game'] ?? '';
        if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Unknown game.');
        $t = GAME_TABLES[$game];
        $c = table_columns($pdo, $t);
        $qty = qty_col($c); $name = name_col($c); $set = set_col($c); $num = number_col($c);
        if (!$qty) throw new RuntimeException("The $t table has no quantity column.");

        $pdo->beginTransaction();
        if ($_GET['action'] === 'undo_adjust') {
            // Put back one removal made on this page (it may have deleted the row)
            $st = $pdo->prepare("SELECT * FROM pull_log WHERE id = ? AND list_id IS NULL AND game = ? AND undone_at IS NULL FOR UPDATE");
            $st->execute([(int)($in['log_id'] ?? 0), $game]);
            $log = $st->fetch(PDO::FETCH_ASSOC);
            if (!$log) { $pdo->rollBack(); throw new RuntimeException('That change was already undone.'); }
            $all = table_columns($pdo, $t, true);
            $snap = json_decode($log['snapshot'], true) ?: [];
            $cols = array_values(array_filter(array_keys($snap), fn($k) => in_array($k, $all, true) && !in_array($k, ['id', 'row_key', $qty], true)));
            $vals = array_map(fn($k) => $snap[$k], $cols);
            $cols[] = $qty; $vals[] = (int)$log['quantity'];
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ")
                           ON DUPLICATE KEY UPDATE `$qty` = `$qty` + VALUES(`$qty`)")->execute($vals);
            $pdo->prepare('UPDATE pull_log SET undone_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$log['id']]);
            $pdo->commit();
            // find the row again (its id is new if it had been deleted)
            $where = []; $params = [];
            foreach ($cols as $i => $k) { if ($k === $qty || $k === 'image_url') continue; $where[] = "`$k` <=> ?"; $params[] = $vals[$i]; }
            $st = $pdo->prepare("SELECT id, `$qty` AS quantity FROM `$t` WHERE " . implode(' AND ', $where) . ' LIMIT 1');
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['id' => (int)($row['id'] ?? 0), 'quantity' => (int)($row['quantity'] ?? 0), 'restored' => (int)$log['quantity']]);
            exit;
        }

        $delta = (int)($in['delta'] ?? 0);
        if ($delta !== 1 && $delta !== -1) throw new RuntimeException('Change the count by one at a time.');
        $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ? FOR UPDATE");
        $st->execute([(int)($in['id'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); throw new RuntimeException('That card is no longer in the inventory. Refresh the page.'); }
        $new = (int)$row[$qty] + $delta;
        $logId = null;
        if ($delta < 0) {
            // Record the removal so it can be undone, even if it was the last copy
            $snap = $row;
            unset($snap['id'], $snap['row_key'], $snap[$qty]);
            $details = implode(' · ', array_filter([$set ? $row[$set] : '', $num ? $row[$num] : '', $row['card_condition'],
                ($row['finish'] ?? 'normal') !== 'normal' ? (FINISHES[$row['finish']] ?? $row['finish']) : '']));
            $pdo->prepare('INSERT INTO pull_log (list_id, item_name, game, card_name, details, snapshot, quantity) VALUES (NULL, ?, ?, ?, ?, ?, 1)')
                ->execute(['Inventory page', $game, $row[$name], mb_substr($details, 0, 255), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
            $logId = (int)$pdo->lastInsertId();
        }
        if ($new <= 0) $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$row['id']]);
        else $pdo->prepare("UPDATE `$t` SET `$qty` = ? WHERE id = ?")->execute([$new, $row['id']]);
        $pdo->commit();
        echo json_encode(['id' => (int)$row['id'], 'quantity' => max(0, $new), 'removed' => $new <= 0, 'log_id' => $logId]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = app_db();
        $game = $_GET['game'] ?? 'all';
        $games = $game === 'all' ? array_keys(GAME_TABLES) : (isset(GAME_TABLES[$game]) ? [$game] : []);
        if (!$games) throw new RuntimeException('Unknown game.');
        $f = [
            'q' => trim($_GET['q'] ?? ''),
            'cond' => isset(CONDITIONS[$_GET['cond'] ?? '']) ? $_GET['cond'] : '',
            'finish' => isset(FINISHES[$_GET['finish'] ?? '']) ? $_GET['finish'] : '',
        ];
        $limit = 60;
        // cursor = where each game's table left off: {"pokemon": [name, id] | null, "mtg": ...}; false = finished
        $cursor = json_decode($_GET['cursor'] ?? '', true) ?: [];

        $out = ['limit' => $limit];
        if (!$cursor) { // first page: totals
            $out['total'] = $out['copies'] = 0;
            foreach ($games as $g) {
                $c = table_columns($pdo, GAME_TABLES[$g]);
                $qty = qty_col($c);
                [$where, $params] = filters_sql($c, $f);
                $nameC = name_col($c); $setC = set_col($c); $numC = number_col($c);
                // printings = same card name + set + number (copies in different conditions/finishes share one picture)
                $distinct = 'LOWER(TRIM(`' . $nameC . '`))' . ($setC ? ", LOWER(TRIM(IFNULL(`$setC`, '')))" : '') . ($numC ? ", LOWER(TRIM(IFNULL(`$numC`, '')))" : '');
                $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(' . ($qty ? "`$qty`" : '1') . "), 0), COUNT(DISTINCT $distinct) FROM `" . GAME_TABLES[$g] . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
                $st->execute($params);
                [$n, $copies, $printings] = $st->fetch(PDO::FETCH_NUM);
                $out['total'] += (int)$n; $out['copies'] += (int)$copies; $out['printings'] = ($out['printings'] ?? 0) + (int)$printings;
            }
        }

        // Fetch the next page from each game, then merge them in name order
        $streams = [];
        foreach ($games as $g) {
            if (($cursor[$g] ?? null) === false) continue;
            $streams[$g] = page_rows($pdo, $g, $f, $cursor[$g] ?? null, $limit);
        }
        $page = [];
        while (count($page) < $limit) {
            $pick = null;
            foreach ($streams as $g => $rows) {
                if (!$rows) continue;
                if ($pick === null || strcasecmp($rows[0]['name'], $streams[$pick][0]['name']) < 0) $pick = $g;
            }
            if ($pick === null) break;
            $r = array_shift($streams[$pick]);
            $page[] = $r;
            $cursor[$pick] = [$r['name'], $r['id']];
        }
        foreach ($games as $g) if (isset($streams[$g]) && !$streams[$g]) $cursor[$g] = false; // nothing left in this game

        foreach ($games as $g) {
            $mine = array_keys(array_filter($page, fn($r) => $r['game'] === $g));
            if (!$mine) continue;
            $sub = array_intersect_key($page, array_flip($mine));
            resolve_images($pdo, $g, $sub);
            foreach ($sub as $i => $r) $page[$i] = $r;
        }

        $out['cards'] = $page;
        $out['cursor'] = $cursor;
        $out['done'] = !array_filter($games, fn($g) => ($cursor[$g] ?? null) !== false);
        $out['catalog'] = (bool)$pdo->query('SELECT 1 FROM card_catalog LIMIT 1')->fetchColumn();
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- Page ---------- */
$game = $_GET['game'] ?? 'all';
if (!in_array($game, ['all', 'pokemon', 'mtg'], true)) $game = 'all';
$q = trim($_GET['q'] ?? '');

$pdoPage = app_db();
$gameFields = [];
foreach (GAME_TABLES as $g => $t) {
    if (!table_columns($pdoPage, $t)) continue;
    $cf = card_fields($pdoPage, $g);
    $gameFields[$g] = ['set' => (bool)$cf['set'], 'number' => (bool)$cf['number'],
                       'extra' => array_map(fn($c) => ['col' => $c, 'label' => ucfirst(str_replace('_', ' ', $c))], $cf['extra'])];
}

page_head('Inventory', 'inventory', 'inventory-page');
?>
        <div class="page-top">
            <h1>Inventory</h1>
            <button type="button" id="addCardBtn" class="primary">Add card</button>
        </div>
        <p id="summary" class="lead" aria-live="polite">Loading your cards...</p>

        <form id="filters" class="filters" role="search">
            <fieldset class="game-picker">
                <legend class="visually-hidden">Game</legend>
                <label><input type="radio" name="game" value="all" <?= $game === 'all' ? 'checked' : '' ?>><span>All games</span></label>
                <label><input type="radio" name="game" value="pokemon" <?= $game === 'pokemon' ? 'checked' : '' ?>><span>Pokémon</span></label>
                <label><input type="radio" name="game" value="mtg" <?= $game === 'mtg' ? 'checked' : '' ?>><span>Magic</span></label>
            </fieldset>
            <label for="q" class="visually-hidden">Filter by card name, set or number</label>
            <input type="search" id="q" value="<?= h($q) ?>" placeholder="Filter by card name, set or number" autocomplete="off">
            <label for="cond" class="visually-hidden">Condition</label>
            <select id="cond" name="cond">
                <option value="">Any condition</option>
                <?php foreach (CONDITIONS as $k => $label): ?><option value="<?= h($k) ?>"><?= h("$k – $label") ?></option><?php endforeach; ?>
            </select>
            <label for="finish" class="visually-hidden">Finish</label>
            <select id="finish" name="finish">
                <option value="">Any finish</option>
                <?php foreach (FINISHES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
            </select>
        </form>

        <div id="notice" class="notice error" role="alert" hidden></div>
        <p id="nocatalog" class="notice warn" hidden>Card pictures aren't set up yet. Open <a href="catalog_sync.php">Card catalog</a> to download them.</p>
        <ul id="grid" class="grid"></ul>
        <p id="empty" class="note" hidden>No cards match. Try a different search, or add cards from <a href="import.php">Import</a>.</p>
        <div id="sentinel" aria-hidden="true"></div>

        <dialog id="cardDlg" aria-labelledby="dlgTitle">
            <form class="dlg card-dlg" id="cardForm" novalidate>
                <h2 id="dlgTitle">Add a card</h2>
                <div class="field" id="dlgGameWrap">
                    <span id="dlgGameLabel">Game</span>
                    <div class="game-picker" role="radiogroup" aria-labelledby="dlgGameLabel">
                        <label><input type="radio" name="dlgGame" value="pokemon" checked><span>Pokémon</span></label>
                        <label><input type="radio" name="dlgGame" value="mtg"><span>Magic</span></label>
                    </div>
                </div>
                <div id="catBox">
                    <label>Find the card in the catalog
                        <input type="search" id="catSearch" placeholder="Start typing the card name" autocomplete="off">
                    </label>
                    <p class="note" id="catHint">Pick the exact printing below and the details fill in for you. Can't find it? Type the details yourself.</p>
                    <ul id="catResults" class="cat-results" aria-label="Printings"></ul>
                </div>
                <div class="dlg-grid">
                    <label class="span2">Card name <input type="text" id="fName" required maxlength="255"></label>
                    <label id="fSetWrap">Set <input type="text" id="fSet" maxlength="255"></label>
                    <label id="fNumWrap">Number <input type="text" id="fNum" maxlength="255"></label>
                    <div id="fExtra" class="dlg-grid-inner"></div>
                    <label>Condition
                        <select id="fCond"><?php foreach (CONDITIONS as $k => $label): ?><option value="<?= h($k) ?>"><?= h("$k – $label") ?></option><?php endforeach; ?></select>
                    </label>
                    <label>Finish
                        <select id="fFinish"><?php foreach (FINISHES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
                    </label>
                    <label>Quantity <input type="number" id="fQty" min="1" max="9999" value="1"></label>
                </div>
                <div id="dlgPrice" class="price-box" aria-live="polite" hidden></div>
                <div id="dlgError" class="notice error" role="alert" hidden></div>
                <div class="dlg-actions">
                    <button type="button" id="dlgRemove" class="link danger" hidden>Remove from inventory</button>
                    <span class="spacer"></span>
                    <button type="button" id="dlgCancel">Cancel</button>
                    <button type="submit" id="dlgSave" class="primary">Add to inventory</button>
                </div>
            </form>
        </dialog>

        <dialog id="viewDlg" class="view-dlg" aria-labelledby="cvName">
            <div class="cv">
                <button type="button" class="cv-x" id="cvClose" aria-label="Close">×</button>
                <div class="cv-top">
                    <div class="cv-art"><img id="cvImg" alt=""><span class="ph" id="cvPh" hidden></span></div>
                    <div class="cv-info">
                        <h2 id="cvName"></h2>
                        <p class="cv-sub" id="cvSub"></p>
                        <div id="cvDetails"></div>
                        <div id="cvPriceLink" class="cv-link"></div>
                    </div>
                </div>
                <h3 class="cv-h">Copies in stock <span id="cvTotal" class="cv-total"></span></h3>
                <p class="note" id="cvFilterNote" hidden>Only copies matching your condition / finish filter are listed.</p>
                <div class="table-wrap">
                    <table class="cv-copies">
                        <thead><tr><th>Condition</th><th>Finish</th><th class="num">In stock</th><th class="num">Price each</th><th class="cv-actions-h">Change</th></tr></thead>
                        <tbody id="cvRows"></tbody>
                    </table>
                </div>
                <p class="note" id="cvPriceNote" hidden>* TCGplayer has no price for that finish, so the closest finish's price is shown.</p>
                <div class="dlg-actions">
                    <button type="button" id="cvAdd">Add copies…</button>
                    <span class="spacer"></span>
                    <button type="button" id="cvCloseBtn" class="primary">Close</button>
                </div>
            </div>
        </dialog>

<script>
const $ = id => document.getElementById(id);
const grid = $('grid'), summary = $('summary'), empty = $('empty'), notice = $('notice'), sentinel = $('sentinel'), form = $('filters'), search = $('q');
const FINISH_LABELS = <?= json_encode(FINISHES) ?>;
const state = { game: '<?= h($game) ?>', q: search.value, cond: '', finish: '', cursor: null, loading: false, done: false, gen: 0 };

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined) n.textContent = text;
  return n;
}
function showError(msg) { notice.textContent = msg; notice.hidden = false; }

/* ---------- Grid: one tile per printing (same card, set and number); its copies are listed in the card window ---------- */
const COND_ORDER = ['NM', 'LP', 'MP', 'HP', 'DMG'], FIN_ORDER = ['normal', 'foil', 'reverse', 'etched'];
const COND_LABELS = <?= json_encode(CONDITIONS) ?>;
const groups = new Map();   // printing key => {key, game, name, set_name, num, image_url, copies: [], li}
const copies = new Map();   // "game:id" => copy (one inventory row)
const norm = v => (v ?? '').toString().trim().toLowerCase();
const keyOf = c => [c.game, norm(c.name), norm(c.set_name), norm(c.num)].join('|');
const copyId = c => c.game + ':' + c.id;
function sortedCopies(g) {
  return [...g.copies].sort((a, b) => COND_ORDER.indexOf(a.card_condition) - COND_ORDER.indexOf(b.card_condition) || FIN_ORDER.indexOf(a.finish) - FIN_ORDER.indexOf(b.finish));
}
const groupQty = g => g.copies.reduce((n, c) => n + c.quantity, 0);

/** Adds or updates one inventory row, putting it in its printing's tile. */
function upsert(card, { top = false, fromPage = false } = {}) {
  const id = copyId(card), key = keyOf(card);
  let c = copies.get(id);
  if (c && c.gkey !== key) { detach(c, true); c = null; }
  if (c) Object.assign(c, card); else { c = card; copies.set(id, c); }
  c.gkey = key;
  let g = groups.get(key);
  if (!g) {
    g = { key, game: c.game, name: c.name, set_name: c.set_name, num: c.num, image_url: c.image_url, copies: [] };
    groups.set(key, g);
    g.li = el('li', 'card group-card');
    g.li.tabIndex = 0; g.li.setAttribute('role', 'button');
    g.li.addEventListener('click', () => openView(g));
    g.li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openView(g); } });
    if (top) grid.prepend(g.li); else grid.appendChild(g.li);
    if (!fromPage) totals.printings += 1;
  }
  if (!g.copies.includes(c)) g.copies.push(c);
  if (!g.image_url && c.image_url) g.image_url = c.image_url;
  renderTile(g);
  if (view.g === g) renderView();
  return c;
}
/** Takes a row out of its tile (it was deleted, or moved to another printing). The tile goes when its last copy goes. */
function detach(c, moving) {
  const g = groups.get(c.gkey);
  copies.delete(copyId(c));
  if (!g) return;
  g.copies = g.copies.filter(x => x !== c);
  if (!g.copies.length) {
    g.li.remove(); groups.delete(g.key); totals.printings -= 1;
    if (view.g === g && !moving) viewDlg.close();
  } else {
    renderTile(g);
    if (view.g === g) renderView();
  }
}
function renderTile(g) {
  const li = g.li, list = sortedCopies(g), lead = list[0], qtyTotal = groupQty(g);
  li.setAttribute('aria-label', `${g.name}, ${[g.set_name, g.num].filter(Boolean).join(' ')}, ${qtyTotal} in stock. Open details`);
  const art = el('div', 'art'); art.appendChild(el('span', 'ph', g.name));
  li.classList.toggle('noimg', !g.image_url);
  if (g.image_url) {
    const img = document.createElement('img');
    img.alt = ''; img.loading = 'lazy'; img.src = g.image_url;
    img.onerror = () => { img.remove(); li.classList.add('noimg'); };
    art.prepend(img);
  }
  const meta = el('div', 'meta');
  const nameRow = el('div', 'name-row');
  const qty = el('span', 'qty', '×' + qtyTotal); qty.setAttribute('aria-label', 'Quantity ' + qtyTotal);
  nameRow.append(el('strong', '', g.name), qty);
  meta.append(nameRow, el('span', '', [g.set_name, g.num].filter(Boolean).join(' ')));
  const price = el('span', 'price');
  if (lead.priceState === 'loading') { price.textContent = 'Checking price…'; price.classList.add('muted'); }
  else if (lead.priceState) { price.textContent = lead.price ? priceText(lead.price) : (lead.priceState === 'error' ? 'Price unavailable' : 'No price found'); price.classList.toggle('muted', !lead.price); }
  meta.appendChild(price);
  // Condition summary: NM 2 · LP 2 (finishes listed after)
  const tags = el('div', 'tags');
  const byCond = {};
  list.forEach(c => byCond[c.card_condition] = (byCond[c.card_condition] || 0) + c.quantity);
  COND_ORDER.filter(k => byCond[k]).forEach(k => tags.appendChild(el('span', 'tag cond cond-' + k.toLowerCase(), list.length > 1 || byCond[k] > 1 ? `${k} ${byCond[k]}` : k)));
  [...new Set(list.filter(c => c.finish !== 'normal').map(c => FINISH_LABELS[c.finish]))].forEach(f => tags.appendChild(el('span', 'tag finish', f)));
  tags.appendChild(el('span', 'tag game-tag', g.game === 'mtg' ? 'Magic' : 'Pokémon'));
  meta.appendChild(tags);
  li.replaceChildren(art, meta);
}

/* ---------- Card window: picture, details, and every copy with − / + / Edit / Remove ---------- */
const viewDlg = $('viewDlg');
const view = { g: null, info: null, infoFor: null, req: 0 };
const COLOR_NAMES = { W: 'White', U: 'Blue', B: 'Black', R: 'Red', G: 'Green' };
function pip(sym) {
  const parts = sym.toUpperCase().split('/');
  const known = parts.filter(p => ['W', 'U', 'B', 'R', 'G', 'C'].includes(p));
  const s = el('span', 'pip ' + (known.length ? 'pip-' + known.join('').toLowerCase() : 'pip-generic') + (parts.length > 1 ? ' pip-hybrid' : ''), sym.toUpperCase().replace('/P', 'ᵖ'));
  return s;
}
function symbolText(text) {            // "Add {G}." with the {G} drawn as a mana symbol
  const frag = document.createDocumentFragment();
  (text || '').split(/(\{[^}]+\})/).forEach(part => {
    const m = part.match(/^\{([^}]+)\}$/);
    frag.appendChild(m ? pip(m[1]) : document.createTextNode(part));
  });
  return frag;
}
function paragraphs(text, cls) {
  const box = el('div', cls || 'cv-text');
  (text || '').split('\n').filter(Boolean).forEach(line => { const p = el('p'); p.appendChild(symbolText(line)); box.appendChild(p); });
  return box;
}
function fact(dl, label, value) {
  if (value === null || value === undefined || value === '') return;
  const dt = el('dt', '', label), dd = el('dd');
  if (value instanceof Node) dd.appendChild(value); else dd.textContent = value;
  dl.append(dt, dd);
}
function colorNode(letters) {
  const box = el('span', 'cv-colors');
  const list = (letters || '').split('').filter(x => COLOR_NAMES[x]);
  if (!list.length) { box.appendChild(pip('C')); box.appendChild(document.createTextNode(' Colorless')); return box; }
  list.forEach(x => box.appendChild(pip(x)));
  box.appendChild(document.createTextNode(' ' + (list.length > 1 ? 'Multicolor (' + list.map(x => COLOR_NAMES[x]).join(', ') + ')' : COLOR_NAMES[list[0]])));
  return box;
}
const cap = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : s;

async function openView(g) {
  view.g = g;
  if (view.infoFor !== g.key) { view.info = null; view.infoFor = g.key; }
  renderView();
  if (!viewDlg.open) viewDlg.showModal();
  if (!view.info) {
    const n = ++view.req, lead = sortedCopies(g)[0];
    try {
      const d = await fetch('inventory.php?' + new URLSearchParams({ action: 'card_info', game: g.game, id: lead.id })).then(r => r.json());
      if (n !== view.req || view.g !== g) return;
      view.info = d.error ? { error: d.error } : d.info;
    } catch (e) { if (n === view.req) view.info = { error: 'Card details are unavailable right now.' }; }
    if (view.g === g) renderView();
  }
}
function renderView() {
  const g = view.g; if (!g) return;
  const info = view.info, det = info && info.details, list = sortedCopies(g);
  $('cvName').textContent = g.name;
  $('cvSub').textContent = [info && info.set_name ? info.set_name + (info.set_code ? ` (${String(info.set_code).toUpperCase()})` : '') : g.set_name,
                            g.num || (info && info.number) ? '#' + (g.num || info.number) : '', g.game === 'mtg' ? 'Magic' : 'Pokémon'].filter(Boolean).join(' · ');
  const src = (info && (info.image_large || info.image)) || g.image_url;
  const img = $('cvImg');
  if (src) { if (img.getAttribute('src') !== src) img.src = src; img.hidden = false; $('cvPh').hidden = true; }
  else { img.hidden = true; $('cvPh').hidden = false; $('cvPh').textContent = g.name; }
  img.onerror = () => { if (g.image_url && img.src !== g.image_url) img.src = g.image_url; else { img.hidden = true; $('cvPh').hidden = false; } };

  const box = $('cvDetails');
  if (!info) { box.replaceChildren(el('p', 'note', 'Loading card details…')); }
  else if (info.error) { box.replaceChildren(el('p', 'note', info.error)); }
  else if (!info.matched) { box.replaceChildren(el('p', 'note', "This card isn't matched to a printing in the card catalog, so its details aren't available. Use Edit on a copy below to correct its set or number.")); }
  else {
    const parts = [];
    if (g.game === 'mtg') {
      const faces = det && det.faces ? det.faces : [{ name: info.name, mana_cost: info.mana_cost, type: info.type, text: null }];
      faces.forEach(f => {
        const face = el('div', 'cv-face');
        if (faces.length > 1) face.appendChild(el('h3', 'cv-face-name', f.name));
        const line = el('div', 'cv-typeline');
        if (f.mana_cost) { const m = el('span', 'cv-mana'); m.appendChild(symbolText(f.mana_cost)); line.appendChild(m); }
        if (f.type) line.appendChild(el('span', 'cv-type', f.type));
        face.appendChild(line);
        if (f.text) face.appendChild(paragraphs(f.text));
        if (f.pt) face.appendChild(el('p', 'cv-pt', f.pt));
        if (f.loyalty) face.appendChild(el('p', 'cv-pt', 'Loyalty ' + f.loyalty));
        parts.push(face);
      });
      if (!det) parts.push(el('p', 'note', "Rules text couldn't be loaded right now (no internet?). It will show next time."));
      const dl = el('dl', 'cv-facts');
      fact(dl, 'Mana value', info.cmc !== null && info.cmc !== undefined ? String(Number(info.cmc)) : null);
      fact(dl, 'Color', info.colors !== null && info.colors !== undefined ? colorNode(info.colors) : null);
      fact(dl, 'Rarity', cap(info.rarity === 'mythic' ? 'mythic rare' : info.rarity));
      fact(dl, 'Set', info.set_name ? info.set_name + (info.set_code ? ` (${String(info.set_code).toUpperCase()})` : '') : null);
      fact(dl, 'Collector number', g.num || info.number);
      fact(dl, 'Artist', det && det.artist);
      parts.push(dl);
    } else {
      const dl = el('dl', 'cv-facts');
      if (det) {
        const pretty = v => String(v).replace(/^Pokemon$/, 'Pokémon').replace(/([A-Za-z])(\d)/, '$1 $2');
        fact(dl, 'Card type', [det.category, det.stage, det.trainer_type, det.energy_type].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).map(pretty).join(' · '));
        fact(dl, 'HP', det.hp);
        fact(dl, 'Type', (det.types || []).join(', '));
        if (det.evolves_from) fact(dl, 'Evolves from', det.evolves_from);
      }
      fact(dl, 'Rarity', info.rarity);
      fact(dl, 'Set', info.set_name ? info.set_name + (info.set_code ? ` (${String(info.set_code).toUpperCase()})` : '') : null);
      fact(dl, 'Number', g.num || info.number);
      if (det) {
        fact(dl, 'Regulation', det.regulation);
        if ((det.weaknesses || []).length) fact(dl, 'Weakness', det.weaknesses.map(w => `${w.type} ${w.value || ''}`.trim()).join(', '));
        if ((det.resistances || []).length) fact(dl, 'Resistance', det.resistances.map(w => `${w.type} ${w.value || ''}`.trim()).join(', '));
        if (det.retreat !== null && det.retreat !== undefined) fact(dl, 'Retreat', String(det.retreat));
        fact(dl, 'Illustrator', det.artist);
      }
      const text = el('div', 'cv-face');
      if (det) {
        (det.abilities || []).forEach(a => { const b = el('div', 'cv-move'); b.append(el('strong', '', (a.type ? a.type + ': ' : '') + a.name)); if (a.effect) b.appendChild(paragraphs(a.effect)); text.appendChild(b); });
        (det.attacks || []).forEach(a => {
          const b = el('div', 'cv-move');
          const head = el('div', 'cv-move-head');
          head.append(el('span', 'cv-cost', (a.cost || []).join(' · ')), el('strong', '', a.name), el('span', 'cv-dmg', a.damage ? String(a.damage) : ''));
          b.appendChild(head); if (a.effect) b.appendChild(paragraphs(a.effect)); text.appendChild(b);
        });
        if (det.effect) text.appendChild(paragraphs(det.effect));
      } else text.appendChild(el('p', 'note', "Card text couldn't be loaded right now (no internet?). It will show next time."));
      parts.push(text, dl);
    }
    box.replaceChildren(...parts);
  }

  // TCGplayer link for the main copy
  const lead = list[0];
  const link = $('cvPriceLink'); link.replaceChildren();
  if (lead && lead.price && lead.price.url) { const a = el('a', 'link', 'Open on TCGplayer'); a.href = lead.price.url; a.target = '_blank'; a.rel = 'noopener'; link.appendChild(a); }

  // Copies in stock
  const total = groupQty(g);
  $('cvTotal').textContent = `· ${total} ${total === 1 ? 'card' : 'cards'}` + (list.length > 1 ? ` in ${list.length} conditions / finishes` : '');
  $('cvFilterNote').hidden = !(state.cond || state.finish);
  $('cvRows').replaceChildren(...list.map(c => {
    const tr = el('tr');
    const cond = el('td'); cond.appendChild(el('span', 'tag cond cond-' + c.card_condition.toLowerCase(), c.card_condition + ' – ' + (COND_LABELS[c.card_condition] || ''))); tr.appendChild(cond);
    tr.appendChild(el('td', '', FINISH_LABELS[c.finish] || c.finish));
    tr.appendChild(el('td', 'num cv-qty', String(c.quantity)));
    tr.appendChild(el('td', 'num', c.price ? money(c.price.market ?? c.price.mid) + (c.price.other_finish ? '*' : '') : (c.priceState === 'loading' ? '…' : '–')));
    const act = el('td', 'cv-actions');
    const minus = el('button', 'link adj-btn', '−'); minus.type = 'button'; minus.setAttribute('aria-label', `Remove one ${c.card_condition} ${FINISH_LABELS[c.finish]} copy`);
    const plus = el('button', 'link adj-btn', '+'); plus.type = 'button'; plus.setAttribute('aria-label', `Add one ${c.card_condition} ${FINISH_LABELS[c.finish]} copy`);
    const edit = el('button', 'link', 'Edit'); edit.type = 'button';
    const rm = el('button', 'link danger', 'Remove'); rm.type = 'button'; rm.setAttribute('aria-label', `Remove all ${c.card_condition} ${FINISH_LABELS[c.finish]} copies`);
    minus.onclick = () => adjustCopy(c, -1, tr);
    plus.onclick = () => adjustCopy(c, 1, tr);
    edit.onclick = () => openEdit(c);
    rm.onclick = () => removeCopy(c, tr);
    act.append(minus, plus, edit, rm);
    tr.appendChild(act);
    return tr;
  }));
  $('cvPriceNote').hidden = !list.some(c => c.price && c.price.other_finish);
}
$('cvClose').addEventListener('click', () => viewDlg.close());
$('cvCloseBtn').addEventListener('click', () => viewDlg.close());
viewDlg.addEventListener('close', () => { view.g = null; if (toastBox.parentNode === viewDlg) document.body.appendChild(toastBox); });
viewDlg.addEventListener('click', e => { if (e.target === viewDlg) viewDlg.close(); });   // click outside the window
$('cvAdd').addEventListener('click', async () => {
  const g = view.g; if (!g) return;
  let extra = {};
  try { const d = await fetch('inventory.php?' + new URLSearchParams({ action: 'get_card', game: g.game, id: sortedCopies(g)[0].id })).then(r => r.json()); if (d.card) extra = d.card.extra || {}; } catch (e) {}
  openAdd({ game: g.game, name: g.name, set: g.set_name, number: g.num, image: g.image_url, extra });
});

/* ---------- TCGplayer prices (looked up live, kept for a few hours) ---------- */
const money = n => n === null || n === undefined ? '' : '$' + Number(n).toFixed(2);
async function fetchPrices(items) {
  const r = await fetch('inventory.php?action=prices', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items }) });
  const d = await r.json();
  if (d.error) throw new Error(d.error);
  return d.prices || {};
}
function priceText(p) {
  if (!p || p.market === null && p.mid === null) return 'No price found';
  return 'TCGplayer ' + money(p.market ?? p.mid) + (p.other_finish ? ' (' + p.printing.toLowerCase() + ' price)' : '');
}
// Prices for grid cards (one request per page of cards); the tile shows its main copy's price
async function loadPrices(cards) {
  const list = cards.map(c => copies.get(copyId(c)) || c);
  if (!list.length) return;
  const touched = new Set();
  list.forEach(c => { c.priceState = 'loading'; touched.add(groups.get(c.gkey)); });
  touched.forEach(g => g && renderTile(g));
  let prices = null;
  try { prices = await fetchPrices(list.map(c => ({ k: copyId(c), game: c.game, id: c.id }))); } catch (e) {}
  list.forEach(c => { c.price = prices ? prices[copyId(c)] || null : null; c.priceState = prices ? 'done' : 'error'; });
  touched.forEach(g => { if (g && groups.get(g.key) === g) { renderTile(g); if (view.g === g) renderView(); } });
}
// The price panel in the Add / Edit window
let priceReq = 0;
async function showDlgPrice(item) {
  const box = $('dlgPrice');
  if (!item) { box.hidden = true; box.replaceChildren(); return; }
  const n = ++priceReq;
  box.hidden = false; box.replaceChildren(el('span', 'note', 'Checking the TCGplayer price…'));
  let p;
  try { p = (await fetchPrices([{ k: 'd', ...item }])).d; }
  catch (e) { if (n === priceReq) box.replaceChildren(el('span', 'note', 'Prices are unavailable right now.')); return; }
  if (n !== priceReq) return;
  if (!p) { box.replaceChildren(el('span', 'note', 'No TCGplayer price found for this printing.')); return; }
  const top = el('div', 'price-main');
  top.append(el('span', 'price-label', 'TCGplayer market'), el('strong', 'price-big', money(p.market ?? p.mid)));
  const parts = [];
  if (p.low !== null) parts.push('Low ' + money(p.low));
  if (p.mid !== null) parts.push('Mid ' + money(p.mid));
  parts.push(p.printing + (p.updated ? ' · updated ' + new Date(p.updated + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ' · updated daily'));
  const info = el('div', 'price-info', parts.join(' · '));
  box.replaceChildren(top, info);
  if (p.other_finish) box.appendChild(el('div', 'note', `No ${FINISH_LABELS[$('fFinish').value].toLowerCase()} price is listed for this printing, so this is the ${p.printing.toLowerCase()} price.`));
  if (p.url) {
    const a = el('a', 'link', 'Open on TCGplayer'); a.href = p.url; a.target = '_blank'; a.rel = 'noopener';
    box.appendChild(a);
  }
}
function dlgPriceItem() {
  const finish = $('fFinish').value, g = dlgGame();
  if (dlgState.mode === 'edit') return dlgState.identityChanged ? null : { game: g, id: dlgState.card.id, finish };
  return dlgState.catalogId ? { game: g, catalog_id: dlgState.catalogId, finish } : null;
}
function refreshDlgPrice() {
  const it = dlgPriceItem();
  if (!it && dlgState.mode === 'edit' && dlgState.identityChanged) {
    $('dlgPrice').hidden = false; $('dlgPrice').replaceChildren(el('span', 'note', 'Save your changes to see the price for the new printing.'));
    return;
  }
  showDlgPrice(it);
}
$('fFinish').addEventListener('change', refreshDlgPrice);

/* ---------- Add a card / edit a card ---------- */
const GAME_FIELDS = <?= json_encode($gameFields, JSON_UNESCAPED_UNICODE) ?>;
const dlg = $('cardDlg'), dlgErr = $('dlgError');
const dlgState = { mode: 'add', game: 'pokemon', card: null, image: null, catalogId: null, identityChanged: false };
const dlgGame = () => document.querySelector('input[name=dlgGame]:checked').value;
function dlgError(msg) { dlgErr.textContent = msg || ''; dlgErr.hidden = !msg; }

function layoutFields(game, extraValues = {}) {
  const gf = GAME_FIELDS[game] || { set: true, number: true, extra: [] };
  $('fSetWrap').hidden = !gf.set;
  $('fNumWrap').hidden = !gf.number;
  $('fExtra').replaceChildren(...gf.extra.map(x => {
    const l = el('label', '', x.label + ' ');
    const i = el('input'); i.type = 'text'; i.maxLength = 255; i.dataset.col = x.col; i.value = extraValues[x.col] ?? '';
    l.appendChild(i); return l;
  }));
  $('fSet').placeholder = game === 'pokemon' ? 'e.g. MEG' : 'e.g. Modern Horizons 2';
  $('fNum').placeholder = game === 'pokemon' ? 'e.g. 086/132' : 'e.g. 244';
}
function fillFields(v) {
  $('fName').value = v.name ?? ''; $('fSet').value = v.set ?? ''; $('fNum').value = v.number ?? '';
  $('fCond').value = v.condition || 'NM'; $('fFinish').value = v.finish || 'normal'; $('fQty').value = v.quantity ?? 1;
}

function openAdd(pre) {
  Object.assign(dlgState, { mode: 'add', card: null, image: null, catalogId: null, identityChanged: false });
  const g = pre ? pre.game : (state.game === 'all' ? dlgGame() : state.game);
  document.querySelector(`input[name=dlgGame][value=${g}]`).checked = true;
  $('dlgTitle').textContent = pre ? 'Add copies of ' + pre.name : 'Add a card';
  $('dlgGameWrap').hidden = !!pre; $('catBox').hidden = !!pre; $('dlgRemove').hidden = true;
  $('dlgSave').textContent = 'Add to inventory';
  layoutFields(g, pre ? pre.extra : {}); fillFields(pre ? { name: pre.name, set: pre.set, number: pre.number } : {});
  $('catSearch').value = ''; $('catResults').replaceChildren(); catLast = ''; dlgError(''); showDlgPrice(null);
  if (pre) dlgState.image = pre.image || null;
  dlg.showModal(); (pre ? $('fCond') : $('catSearch')).focus();
}
async function openEdit(c) {
  try {
    const d = await fetch('inventory.php?' + new URLSearchParams({ action: 'get_card', game: c.game, id: c.id })).then(r => r.json());
    if (d.error) throw new Error(d.error);
    Object.assign(dlgState, { mode: 'edit', card: c, image: null, catalogId: null, identityChanged: false });
    document.querySelector(`input[name=dlgGame][value=${c.game}]`).checked = true;
    $('dlgTitle').textContent = 'Edit card';
    $('dlgGameWrap').hidden = true; $('catBox').hidden = true; $('dlgRemove').hidden = false;
    $('dlgSave').textContent = 'Save changes';
    layoutFields(c.game, d.card.extra || {}); fillFields(d.card); dlgError('');
    dlg.showModal(); $('fName').focus();
    refreshDlgPrice();
  } catch (e) { showError(e.message); }
}

// Catalog search while typing
let catTimer, catLast = '';
async function catSearch() {
  const q = $('catSearch').value.trim(), g = dlgGame();
  if (q.length < 2) { $('catResults').replaceChildren(); return; }
  if (q + g === catLast) return;
  catLast = q + g;
  try {
    const d = await fetch('inventory.php?' + new URLSearchParams({ action: 'catalog_search', game: g, q })).then(r => r.json());
    if (d.error) throw new Error(d.error);
    if ($('catSearch').value.trim() !== q) return;
    $('catResults').replaceChildren(...(d.results.length ? d.results.map(r => {
      const b = el('button', 'cat-pick'); b.type = 'button';
      const pic = el('span', 'cat-pic');
      if (r.image) { const img = el('img'); img.src = r.image; img.alt = ''; img.loading = 'lazy'; img.onerror = () => img.remove(); pic.appendChild(img); }
      const txt = el('span', 'cat-text');
      txt.append(el('strong', '', r.name), el('span', '', r.set_label || r.set), el('span', '', [r.number ? '#' + r.number : '', (r.released || '').slice(0, 4)].filter(Boolean).join(' · ')));
      b.append(pic, txt);
      b.onclick = () => {
        $('fName').value = r.name; $('fSet').value = r.set; $('fNum').value = r.number;
        $('fExtra').querySelectorAll('input').forEach(i => { i.value = (r.extra && r.extra[i.dataset.col]) ?? ''; });
        dlgState.image = r.image; dlgState.catalogId = r.catalog_id;
        document.querySelectorAll('.cat-pick.selected').forEach(x => x.classList.remove('selected'));
        b.classList.add('selected');
        $('fCond').focus();
        refreshDlgPrice();
      };
      const li = el('li'); li.appendChild(b); return li;
    }) : [el('li', 'note', 'No printings found in the catalog. Type the details below instead.')]));
  } catch (e) { dlgError(e.message); }
}
$('catSearch').addEventListener('input', () => { clearTimeout(catTimer); catTimer = setTimeout(catSearch, 250); });
document.querySelectorAll('input[name=dlgGame]').forEach(r => r.addEventListener('change', () => {
  layoutFields(dlgGame()); catLast = ''; dlgState.image = null; dlgState.catalogId = null; showDlgPrice(null); catSearch();
}));
// Typing over the details after picking a printing means it's no longer that printing's picture
['fName', 'fSet', 'fNum'].forEach(id => $(id).addEventListener('input', () => {
  dlgState.image = null; dlgState.catalogId = null; document.querySelectorAll('.cat-pick.selected').forEach(x => x.classList.remove('selected'));
  if (dlgState.mode === 'edit') dlgState.identityChanged = true;
  refreshDlgPrice();
}));

$('addCardBtn').addEventListener('click', () => openAdd());
$('dlgCancel').addEventListener('click', () => dlg.close());
dlg.addEventListener('close', () => { if (toastBox.parentNode === dlg) (document.querySelector('dialog[open]') || document.body).appendChild(toastBox); });

$('cardForm').addEventListener('submit', async e => {
  e.preventDefault();
  dlgError('');
  const g = dlgGame();
  const extra = {};
  $('fExtra').querySelectorAll('input').forEach(i => extra[i.dataset.col] = i.value);
  const values = { name: $('fName').value, set: $('fSet').value, number: $('fNum').value, condition: $('fCond').value,
                   finish: $('fFinish').value, quantity: parseInt($('fQty').value, 10) || 0, extra };
  if (!values.name.trim()) return dlgError('Enter the card name.');
  if (values.quantity < 1) return dlgError('Quantity must be at least 1.');
  $('dlgSave').disabled = true;
  try {
    const body = { game: g, values, image: dlgState.image };
    if (dlgState.mode === 'edit') body.id = dlgState.card.id;
    const d = await post('save_card', body);
    const card = d.card;
    if (dlgState.mode === 'add') {
      totals.copies += values.quantity;
      const c = upsert(card, { top: true });
      toast(d.merged ? `Added ${values.quantity} to the ${card.name} already in stock · ${card.quantity} now` : `Added ${values.quantity} × ${card.name} to the inventory`);
      loadPrices([c]);
    } else {
      const old = dlgState.card;
      totals.copies += values.quantity - old.quantity;
      if (d.merged) detach(old, true);      // the edited copy was combined into another one
      const c = upsert(card, { top: !groups.has(keyOf(card)) });
      toast(d.merged ? `Saved. It now matches a copy already in stock, so they were combined (${card.quantity} total).` : `Saved changes to ${card.name}`);
      loadPrices([c]);
    }
    showTotals(); empty.hidden = true;
    dlg.close();
  } catch (err) { dlgError(err.message); }
  finally { $('dlgSave').disabled = false; }
});

$('dlgRemove').addEventListener('click', async () => {
  const c = dlgState.card;
  if (await removeCopy(c)) dlg.close();
});

/* Changing counts from the card window */
let totals = { printings: 0, copies: 0 };
function showTotals() {
  summary.textContent = `${totals.printings.toLocaleString()} different ${totals.printings === 1 ? 'card' : 'cards'}, ${totals.copies.toLocaleString()} total ${totals.copies === 1 ? 'copy' : 'copies'}`;
}
async function post(action, body) {
  const r = await fetch('inventory.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const d = await r.json();
  if (d.error) throw new Error(d.error);
  return d;
}
const copyLabel = c => `${c.name} (${[c.set_name, c.num].filter(Boolean).join(' ')}, ${c.card_condition}${c.finish !== 'normal' ? ', ' + FINISH_LABELS[c.finish] : ''})`;
async function adjustCopy(c, delta, row) {
  if (row && row.classList.contains('busy')) return;
  if (delta < 0 && c.quantity <= 1 && !confirm(`This is the last ${copyLabel(c)}. Remove it from the inventory?`)) return;
  if (row) row.classList.add('busy');
  try {
    const d = await post('adjust', { game: c.game, id: c.id, delta });
    totals.copies += delta;
    if (d.removed) detach(c);
    else upsert({ ...c, quantity: d.quantity });
    showTotals();
    if (delta < 0) toast(`Removed 1 × ${c.name} ${c.card_condition}` + (d.removed ? ' (last copy)' : ` · ${d.quantity} left`), () => undoRemoval(c, d.log_id));
    else toast(`Added 1 × ${c.name} ${c.card_condition} · ${d.quantity} in stock`);
  } catch (e) { showError(e.message); toast(e.message); }
  finally { if (row) row.classList.remove('busy'); }
}
/** Removes every copy of one condition/finish (one inventory row). Returns true if removed. */
async function removeCopy(c, row) {
  if (!confirm(`Remove all ${c.quantity} of ${copyLabel(c)} from the inventory?`)) return false;
  if (row) row.classList.add('busy');
  try {
    const d = await post('delete_card', { game: c.game, id: c.id });
    detach(c); totals.copies -= d.quantity; showTotals();
    toast(`Removed ${d.quantity} × ${c.name} ${c.card_condition} from the inventory`, () => undoRemoval(c, d.log_id));
    return true;
  } catch (e) { showError(e.message); toast(e.message); return false; }
  finally { if (row) row.classList.remove('busy'); }
}
async function undoRemoval(c, logId) {
  try {
    const d = await post('undo_adjust', { game: c.game, log_id: logId });
    totals.copies += d.restored;
    const back = { ...c, id: d.id, quantity: d.quantity };
    delete back.gkey;
    upsert(back, { top: !groups.has(keyOf(back)) });
    showTotals();
    toast(`Put back ${d.restored} × ${c.name} ${c.card_condition}`);
  } catch (e) { showError(e.message); }
}
/* Small message at the bottom of the screen, with an optional Undo */
const toastBox = el('div', 'toast'); toastBox.setAttribute('role', 'status'); toastBox.hidden = true; document.body.appendChild(toastBox);
let toastTimer;
function toast(msg, undo) {
  // Inside the open window (if any), so Undo can be clicked while the card window is open
  const host = [...document.querySelectorAll('dialog[open]')].pop() || document.body;
  if (toastBox.parentNode !== host) host.appendChild(toastBox);
  toastBox.replaceChildren(el('span', '', msg));
  if (undo) {
    const b = el('button', 'link', 'Undo'); b.type = 'button';
    b.onclick = () => { toastBox.hidden = true; clearTimeout(toastTimer); undo(); };
    toastBox.appendChild(b);
  }
  toastBox.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastBox.hidden = true; }, undo ? 8000 : 3000);
}

/* Infinite scroll */
async function load(reset) {
  if (!reset && (state.loading || state.done)) return;
  if (reset) { state.cursor = null; state.done = false; state.gen++; grid.innerHTML = ''; groups.clear(); copies.clear(); notice.hidden = true; }
  const gen = state.gen; state.loading = true;
  try {
    const p = new URLSearchParams({ action: 'list', game: state.game, q: state.q, cond: state.cond, finish: state.finish });
    if (state.cursor) p.set('cursor', JSON.stringify(state.cursor));
    const r = await fetch('inventory.php?' + p);
    const d = await r.json();
    if (gen !== state.gen) return;
    if (d.error) throw new Error(d.error);
    d.cards.forEach(c => upsert(c, { fromPage: true }));
    loadPrices(d.cards);
    state.cursor = d.cursor;
    state.done = d.done;
    if (d.total !== undefined) {
      totals = { printings: d.printings ?? d.total, copies: d.copies };
      showTotals();
      empty.hidden = d.total > 0;
    }
    $('nocatalog').hidden = d.catalog;
  } catch (e) { showError(e.message); state.done = true; }
  finally {
    if (gen === state.gen) state.loading = false;
    scrollObserver.unobserve(sentinel); scrollObserver.observe(sentinel); // re-check if still in view
  }
}
const scrollObserver = new IntersectionObserver(e => { if (e[0].isIntersecting) load(false); }, { rootMargin: '600px' });

form.addEventListener('submit', e => e.preventDefault());
form.addEventListener('change', e => {
  if (e.target.name === 'game') state.game = e.target.value;
  else if (e.target.name === 'cond') state.cond = e.target.value;
  else if (e.target.name === 'finish') state.finish = e.target.value;
  else return;
  load(true);
});
let timer;
search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.q = search.value.trim(); load(true); }, 300); });

load(true);
</script>
<?php page_foot(); ?>
