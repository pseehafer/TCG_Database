<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

function find_mtg_image(string $name, ?string $set, ?string $num): array {
    usleep(100000); // Scryfall asks for ~100ms between requests
    $url = 'https://api.scryfall.com/cards/search?' . http_build_query([
        'q' => '!"' . str_replace('"', '', $name) . '"', 'unique' => 'prints', 'order' => 'released',
    ]);
    [$code, $data, $err] = http_get_json($url, ['User-Agent: TCGDatabase/1.0', 'Accept: application/json']);
    if ($code === 404) return ['status' => 'notfound'];
    if ($err || !is_array($data) || $code >= 400) return ['status' => 'error', 'message' => $err ?: "Scryfall returned $code"];

    $setMatch = $first = null;
    foreach ($data['data'] ?? [] as $c) {
        $img = $c['image_uris']['normal'] ?? ($c['card_faces'][0]['image_uris']['normal'] ?? null);
        if (!$img) continue;
        $sameSet = $set && strcasecmp($c['set_name'] ?? '', $set) === 0;
        $sameNum = $num !== null && $num !== '' && strcasecmp(ltrim($c['collector_number'] ?? '', '0'), ltrim($num, '0')) === 0;
        if ($sameSet && $sameNum) return ['status' => 'ok', 'url' => $img];
        if ($sameSet && !$setMatch) $setMatch = $img;
        if (!$first) $first = $img;
    }
    $pick = $setMatch ?? $first;
    return $pick ? ['status' => 'ok', 'url' => $pick] : ['status' => 'notfound'];
}

function find_pokemon_image(string $name, ?string $set, ?string $num): array {
    $n = trim(preg_replace('#/.*$#', '', (string)$num));
    if ($n !== '') $n = ltrim($n, '0') === '' ? '0' : ltrim($n, '0');
    $name = str_replace('"', '', $name);
    $set = $set ? str_replace('"', '', $set) : null;

    $tries = [];
    if ($set && $n !== '') $tries[] = "name:\"$name\" set.name:\"$set\" number:\"$n\"";
    if ($set)              $tries[] = "name:\"$name\" set.name:\"$set\"";
    if ($n !== '')         $tries[] = "name:\"$name\" number:\"$n\"";
    $tries[] = "name:\"$name\"";

    $headers = ['Accept: application/json'];
    if (POKEMON_API_KEY !== '') $headers[] = 'X-Api-Key: ' . POKEMON_API_KEY;

    foreach (array_unique($tries) as $q) {
        $url = 'https://api.pokemontcg.io/v2/cards?' . http_build_query(['q' => $q, 'pageSize' => 1, 'select' => 'id,images']);
        [$code, $data, $err] = http_get_json($url, $headers);
        if ($err || !is_array($data) || $code >= 400) return ['status' => 'error', 'message' => $err ?: "Pokémon TCG API returned $code"];
        $img = $data['data'][0]['images']['large'] ?? ($data['data'][0]['images']['small'] ?? null);
        if ($img) return ['status' => 'ok', 'url' => $img];
    }
    return ['status' => 'notfound'];
}

try {
    $pdo = app_db();
    $game = $_GET['game'] ?? '';
    if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Unknown game.');
    $t = GAME_TABLES[$game];

    $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Card not found.');

    if ($row['image_url'] !== null) { // already looked up ('' means no image exists)
        echo json_encode(['url' => $row['image_url'] ?: null]);
        exit;
    }

    $cols = table_columns($pdo, $t);
    $set = isset($cols['setname']) ? $row[$cols['setname']] : null;
    $numCol = number_col($cols);
    $num = $numCol ? $row[$numCol] : null;

    $res = $game === 'mtg' ? find_mtg_image($row['name'], $set, $num) : find_pokemon_image($row['name'], $set, $num);

    if ($res['status'] === 'error') { // don't cache failures, so it retries next time
        echo json_encode(['url' => null, 'error' => $res['message']]);
        exit;
    }
    $url = $res['status'] === 'ok' ? $res['url'] : '';
    $pdo->prepare("UPDATE `$t` SET image_url = ? WHERE id = ?")->execute([$url, $row['id']]);
    echo json_encode(['url' => $url ?: null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['url' => null, 'error' => $e->getMessage()]);
}
