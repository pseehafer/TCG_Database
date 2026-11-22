<?php
/**
 * Read-only data for the Customer Lookup page. No sign-in needed, and nothing here can change the inventory.
 *   GET  kiosk_api.php?game=mtg&suggest=ligh         card names in stock starting with the text
 *   POST kiosk_api.php  {"game":"mtg","items":[["Lightning Bolt",4]]}  or  {"game":"mtg","text":"4 Lightning Bolt\n..."}
 *   POST kiosk_api.php  {"action":"send","game":"mtg","customer":"Sam","items":[["Lightning Bolt",4,{"set":"Magic 2010","number":"146","condition":"NM","finish":"foil"}]]}
 *        the one thing the kiosk can save: the customer's list, sent to the staff List Builder.
 *        The third value is optional: the printing the customer chose (null or left out = any printing).
 */
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = app_db();

    if (isset($_GET['suggest'])) {
        $game = $_GET['game'] ?? '';
        $q = trim((string)$_GET['suggest']);
        if (!isset(GAME_TABLES[$game]) || mb_strlen($q) < 2) { echo '[]'; exit; }
        $c = table_columns($pdo, GAME_TABLES[$game]);
        $name = name_col($c); $qty = qty_col($c);
        $st = $pdo->prepare("SELECT DISTINCT `$name` FROM `" . GAME_TABLES[$game] . "` WHERE `$name` LIKE ?" . ($qty ? " AND `$qty` > 0" : '') . " ORDER BY `$name` LIMIT 12");
        $st->execute([addcslashes($q, '%_\\') . '%']);
        echo json_encode($st->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Nothing to look up.');
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 200000), true) ?: [];
    $game = $in['game'] ?? '';
    if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Choose a game.');

    if (($in['action'] ?? '') === 'send') {
        $customer = trim(preg_replace('/\s+/u', ' ', (string)($in['customer'] ?? '')));
        if ($customer === '') throw new RuntimeException('Type your name so the staff know whose list it is.');
        $customer = mb_substr($customer, 0, 40);
        $items = [];
        foreach ((array)($in['items'] ?? []) as $it) {
            $n = trim((string)($it[0] ?? ''));
            if ($n === '') continue;
            $k = mb_strtolower($n);
            $pref = $items[$k][2] ?? clean_printing($it[2] ?? null);   // the same card twice: the first choice counts
            $items[$k] = [mb_substr($n, 0, 255), min(999, ($items[$k][1] ?? 0) + max(1, min(999, (int)($it[1] ?? 1)))), $pref];
        }
        $items = array_slice(array_values($items), 0, 200);
        if (!$items) throw new RuntimeException('Add some cards first.');
        // Limits so the kiosk can't flood the List Builder: one send every 15 seconds, and at most 30 unopened kiosk lists
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $last = meta_get($pdo, "kiosk:last_send:$ip");
        if ($last && (int)$last > time() - 15) throw new RuntimeException('Your list was just sent. Wait a few seconds before sending another.');
        $waiting = (int)$pdo->query("SELECT COUNT(*) FROM card_lists WHERE source = 'kiosk' AND seen_at IS NULL")->fetchColumn();
        if ($waiting >= 30) throw new RuntimeException('The counter has a lot of lists waiting. Please ask a staff member for help.');
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO card_lists (name, game, notes, source) VALUES (?, ?, ?, 'kiosk')")
            ->execute(['Kiosk: ' . $customer, $game, 'Sent from the Customer Lookup on ' . date('M j \a\t g:i a') . '.']);
        $lid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO card_list_items (list_id, name, quantity, pref) VALUES (?, ?, ?, ?)');
        foreach ($items as [$n, $q, $pref]) $ins->execute([$lid, $n, $q, $pref ? json_encode($pref, JSON_UNESCAPED_UNICODE) : null]);
        $pdo->commit();
        meta_set($pdo, "kiosk:last_send:$ip", (string)time());
        echo json_encode(['sent' => true, 'list' => $lid, 'customer' => $customer, 'cards' => count($items),
                          'chosen' => count(array_filter(array_column($items, 2)))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = []; $skipped = [];
    if (isset($in['text'])) {
        $parsed = parse_list((string)$in['text']);
        $items = $parsed['items'];
        $skipped = array_slice($parsed['skipped'], 0, 20);
    } else {
        foreach ((array)($in['items'] ?? []) as $it) {
            $n = trim((string)($it[0] ?? ''));
            if ($n !== '') $items[] = [mb_substr($n, 0, 255), max(1, min(999, (int)($it[1] ?? 1)))];
        }
    }
    $items = array_slice($items, 0, 200);

    $stock = stock_by_names($pdo, $game, array_column($items, 0));

    $out = [];
    foreach ($items as [$n, $want]) {
        $copies = $stock[mb_strtolower($n)] ?? [];
        // One entry per printing (set, number, condition, finish), best condition first, so the customer can pick one
        $printings = [];
        foreach ($copies as $r) {
            $p = printing_of($r);
            $k = printing_key($p);
            if (isset($printings[$k])) { $printings[$k]['quantity'] += $r['quantity']; continue; }
            $printings[$k] = $p + [
                'key' => $k, 'label' => printing_label($p), 'quantity' => $r['quantity'],
                'condition_label' => CONDITIONS[$p['condition']] ?? $p['condition'], 'finish_label' => FINISHES[$p['finish']] ?? $p['finish'],
            ];
        }
        $out[] = [
            'name' => $copies ? $copies[0]['name'] : $n,   // as spelled in the inventory
            'wanted' => $want,
            'in_stock' => array_sum(array_column($copies, 'quantity')),
            'copies' => array_values($printings),
        ];
    }
    echo json_encode(['items' => $out, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong looking that up.']);
}
