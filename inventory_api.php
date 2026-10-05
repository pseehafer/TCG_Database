<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = app_db();
    $game = $_GET['game'] ?? 'all';
    $q = trim($_GET['q'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 60)));

    if ($game !== 'all' && !isset(GAME_TABLES[$game])) throw new RuntimeException('Unknown game.');
    $games = $game === 'all' ? array_keys(GAME_TABLES) : [$game];

    $parts = [];
    foreach ($games as $g) {
        $t = GAME_TABLES[$g];
        $cols = table_columns($pdo, $t);
        $num = number_col($cols);
        $numSql = $num ? "`$num`" : 'NULL';
        $setSql = isset($cols['setname']) ? "`{$cols['setname']}`" : 'NULL';
        $qty = $cols[norm(QTY_COLUMN)] ?? null;
        $qtySql = $qty ? "`$qty`" : '1';
        $parts[] = "SELECT '$g' AS game, id, name, $setSql AS set_name, $numSql AS number, $qtySql AS quantity, image_url FROM `$t`";
    }
    $base = '(' . implode(' UNION ALL ', $parts) . ') t';

    $where = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where = ' WHERE (name LIKE ? OR set_name LIKE ?)';
        $params = [$like, $like];
    }

    $out = [];
    if ($offset === 0) {
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(quantity), 0) FROM $base$where");
        $st->execute($params);
        [$out['total_rows'], $out['total_qty']] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
    }

    $st = $pdo->prepare("SELECT * FROM $base$where ORDER BY name, set_name LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $out['cards'] = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['quantity'] = (int)$r['quantity'];
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
