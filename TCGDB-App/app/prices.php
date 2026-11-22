<?php
/**
 * Current TCGplayer prices for cards, looked up live and kept for a few hours.
 *  - Magic: Scryfall (TCGplayer market price for normal, foil and etched; Scryfall refreshes it daily).
 *  - Pokémon: TCGdex (TCGplayer market / low / mid price for each printing; refreshed daily).
 * A card's printing is the catalog card whose picture it shows, so the price always matches the picture.
 * This file only holds functions; inventory.php uses them.
 */

const PRICE_CACHE_HOURS = 6;

function price_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // The flag alone isn't enough: when a backup is restored on a new computer the flag comes back with the
    // settings, but price_cache (not part of a backup) isn't there. So check for the table itself too.
    if (meta_get($pdo, 'schema:prices') === '1' && table_columns($pdo, 'price_cache', true)) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_cache (
        game VARCHAR(10) NOT NULL,
        ext_id VARCHAR(64) NOT NULL,
        data TEXT NULL,
        fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (game, ext_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!has_index($pdo, 'card_catalog', 'idx_img')) $pdo->exec('ALTER TABLE card_catalog ADD INDEX idx_img (image_url(191))');
    meta_set($pdo, 'schema:prices', '1');
}

/**
 * Which catalog printing to price an inventory card as. $joinedExt = the printing whose picture the row already shows.
 * Basic energies matched only by "any printing of that energy" aren't priced: that printing can be a special one
 * worth far more than the card in stock.
 */
function priced_ext(PDO $pdo, string $game, string $name, ?string $set, ?string $num, ?string $joinedExt): ?string {
    static $byImg = null, $numOf = null;
    $byImg ??= $pdo->prepare('SELECT ext_id FROM card_catalog WHERE game = ? AND image_url = ? LIMIT 1');
    $numOf ??= $pdo->prepare('SELECT num_key FROM card_catalog WHERE game = ? AND ext_id = ?');
    $isEnergy = $game === 'pokemon' && basic_energy_keys($name);
    if ($joinedExt) {
        if (!$isEnergy) return $joinedExt;
        $numOf->execute([$game, $joinedExt]);
        if ($numOf->fetchColumn() === num_key($num)) return $joinedExt;   // same number: the printing in stock
    }
    $img = match_catalog($pdo, $game, $name, $set, $num, $rule);
    if ($img === '' || $rule === 5) return null;
    $byImg->execute([$game, $img]);
    return $byImg->fetchColumn() ?: null;
}

/** POST JSON. Returns [httpCode, data|null, error|null]. */
function http_post_json(string $url, array $payload, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['User-Agent: TCGDatabase/1.0', 'Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => HTTP_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => HTTP_VERIFY_SSL ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $err = $body === false ? curl_error($ch) : null;
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : json_decode($body, true), $err];
}

/** GET several JSON URLs at once (a few at a time). Returns [key => data|null]. */
function http_get_many(array $urls, int $parallel = 8, int $timeout = 15): array {
    $out = array_fill_keys(array_keys($urls), null);
    $queue = $urls;
    $mh = curl_multi_init();
    $running = [];
    $start = function () use (&$queue, &$running, $mh, $timeout) {
        $key = array_key_first($queue);
        $ch = curl_init($queue[$key]);
        unset($queue[$key]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['User-Agent: TCGDatabase/1.0', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => HTTP_VERIFY_SSL, CURLOPT_SSL_VERIFYHOST => HTTP_VERIFY_SSL ? 2 : 0,
        ]);
        curl_multi_add_handle($mh, $ch);
        $running[(int)$ch] = [$key, $ch];
    };
    while ($queue && count($running) < $parallel) $start();
    do {
        curl_multi_exec($mh, $active);
        while ($info = curl_multi_info_read($mh)) {
            [$key, $ch] = $running[(int)$info['handle']];
            unset($running[(int)$info['handle']]);
            if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $out[$key] = json_decode((string)curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($queue) $start();
        }
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running);
    curl_multi_close($mh);
    return $out;
}

/** Downloads current prices for catalog printings. Returns [ext_id => data] for the ones found. */
function fetch_prices(string $game, array $extIds): array {
    $found = [];
    if ($game === 'mtg') {
        foreach (array_chunk($extIds, 75) as $chunk) {   // Scryfall takes up to 75 cards per request
            [$code, $d] = http_post_json('https://api.scryfall.com/cards/collection', ['identifiers' => array_map(fn($id) => ['id' => $id], $chunk)]);
            if ($code !== 200 || !is_array($d)) continue;
            foreach ($d['data'] ?? [] as $c) {
                $p = $c['prices'] ?? [];
                $found[$c['id']] = [
                    'usd' => $p['usd'] ?? null, 'usd_foil' => $p['usd_foil'] ?? null, 'usd_etched' => $p['usd_etched'] ?? null,
                    'tcg' => $c['tcgplayer_id'] ?? null, 'tcg_etched' => $c['tcgplayer_etched_id'] ?? null,
                ];
            }
            usleep(100000);   // Scryfall asks for a short pause between requests
        }
    } else {
        $urls = [];
        foreach ($extIds as $id) $urls[$id] = 'https://api.tcgdex.net/v2/en/cards/' . rawurlencode($id);
        foreach (http_get_many($urls) as $id => $d) {
            if (!is_array($d) || !isset($d['id'])) continue;
            $tp = $d['pricing']['tcgplayer'] ?? [];
            $variants = [];
            foreach ($tp as $k => $v) {
                if (!is_array($v)) continue;
                $variants[$k] = ['market' => $v['marketPrice'] ?? null, 'low' => $v['lowPrice'] ?? null, 'mid' => $v['midPrice'] ?? null, 'tcg' => $v['productId'] ?? null];
            }
            $found[$id] = ['variants' => $variants, 'updated' => $tp['updated'] ?? null];
        }
    }
    return $found;
}

/** Cached price data for catalog printings, downloading what's missing or older than PRICE_CACHE_HOURS. [ext_id => data|null] */
function price_data(PDO $pdo, string $game, array $extIds, int $maxAgeHours = PRICE_CACHE_HOURS): array {
    price_tables($pdo);
    $extIds = array_values(array_unique(array_filter($extIds)));
    if (!$extIds) return [];
    $out = []; $stale = [];
    $st = $pdo->prepare('SELECT ext_id, data, fetched_at > NOW() - INTERVAL ' . max(1, $maxAgeHours) . ' HOUR AS fresh FROM price_cache WHERE game = ? AND ext_id IN ('
        . implode(',', array_fill(0, count($extIds), '?')) . ')');
    $st->execute([$game, ...$extIds]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['ext_id']] = $r['data'] === null ? null : json_decode($r['data'], true);
        if (!$r['fresh']) $stale[] = $r['ext_id'];
    }
    $need = array_merge(array_diff($extIds, array_keys($out)), $stale);
    if ($need) {
        $got = fetch_prices($game, $need);
        $save = $pdo->prepare('INSERT INTO price_cache (game, ext_id, data, fetched_at) VALUES (?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = NOW()');
        foreach ($need as $id) {
            if (isset($got[$id])) {
                $out[$id] = $got[$id];
                $save->execute([$game, $id, json_encode($got[$id])]);
            }
            // not found / site unreachable: keep any older price, and try again next time
        }
    }
    return $out;
}

/** The price to show for one finish of a printing. */
function price_for_finish(string $game, ?array $d, string $finish): ?array {
    if (!$d) return null;
    $usd = fn($x) => $x === null || $x === '' ? null : round((float)$x, 2);
    if ($game === 'mtg') {
        $order = ['etched' => ['usd_etched', 'usd_foil', 'usd'], 'foil' => ['usd_foil', 'usd_etched', 'usd']][$finish] ?? ['usd', 'usd_foil', 'usd_etched'];
        $labels = ['usd' => 'Normal', 'usd_foil' => 'Foil', 'usd_etched' => 'Etched foil'];
        foreach ($order as $i => $k) {
            if (($m = $usd($d[$k] ?? null)) === null) continue;
            $tcg = $k === 'usd_etched' && !empty($d['tcg_etched']) ? $d['tcg_etched'] : ($d['tcg'] ?? null);
            return ['market' => $m, 'low' => null, 'mid' => null, 'printing' => $labels[$k], 'other_finish' => $i > 0,
                    'updated' => null, 'url' => $tcg ? 'https://www.tcgplayer.com/product/' . (int)$tcg : null];
        }
        return null;
    }
    $v = $d['variants'] ?? [];
    if (!$v) return null;
    $keys = array_keys($v);
    // Unlimited before 1st Edition when both exist
    usort($keys, fn($a, $b) => (str_contains($a, '1st') <=> str_contains($b, '1st')));
    $rev = array_values(array_filter($keys, fn($k) => str_contains(strtolower($k), 'reverse')));
    $holo = array_values(array_filter($keys, fn($k) => !str_contains(strtolower($k), 'reverse') && str_contains(strtolower($k), 'holo')));
    $norm = array_values(array_filter($keys, fn($k) => !str_contains(strtolower($k), 'holo')));
    // "Holo" counts as a Pokémon card's normal printing (same as on Import)
    $want = $finish === 'reverse' ? $rev : ($finish === 'normal' ? array_merge($norm, $holo) : $holo);
    $key = $want[0] ?? $keys[0];
    $x = $v[$key];
    if ($x['market'] === null && $x['mid'] === null && $x['low'] === null) return null;
    // "reverse-holofoil" => "Reverse holo", "1stEditionHolofoil" => "1st edition holo"
    $label = ucfirst(str_replace('holofoil', 'holo', strtolower(trim(preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', str_replace('-', ' ', $key))))));
    return ['market' => $usd($x['market']), 'low' => $usd($x['low']), 'mid' => $usd($x['mid']), 'printing' => $label,
            'other_finish' => !$want, 'updated' => $d['updated'] ? substr($d['updated'], 0, 10) : null,
            'url' => !empty($x['tcg']) ? 'https://www.tcgplayer.com/product/' . (int)$x['tcg'] : null];
}

/**
 * Prices for a batch of requests: [{k, game, id?, catalog_id?, finish?}, ...]
 *  id = an inventory row (its picture says which printing; its finish is used unless one is given)
 *  catalog_id = a printing picked in the Add card window
 * Returns [k => price|null].
 */
function prices_for(PDO $pdo, array $items): array {
    price_tables($pdo);
    $items = array_slice($items, 0, 120);
    $resolved = [];   // k => [game, ext_id, finish]
    foreach (GAME_TABLES as $game => $t) {
        $mine = array_filter($items, fn($it) => ($it['game'] ?? '') === $game);
        if (!$mine) continue;
        $rowIds = array_values(array_unique(array_filter(array_map(fn($it) => (int)($it['id'] ?? 0), $mine))));
        $catIds = array_values(array_unique(array_filter(array_map(fn($it) => (int)($it['catalog_id'] ?? 0), $mine))));
        $rows = []; $cats = [];
        if ($rowIds) {
            $c = table_columns($pdo, $t);
            $nameC = name_col($c); $setC = set_col($c); $numC = number_col($c);
            $st = $pdo->prepare("SELECT t.id, t.finish, c.ext_id, t.`$nameC` AS name, " . ($setC ? "t.`$setC`" : 'NULL') . ' AS set_name, ' . ($numC ? "t.`$numC`" : 'NULL') . " AS num
                                 FROM `$t` t LEFT JOIN card_catalog c ON c.game = ? AND c.image_url = t.image_url
                                 WHERE t.id IN (" . implode(',', array_fill(0, count($rowIds), '?')) . ')');
            $st->execute([$game, ...$rowIds]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // If the picture isn't a catalog picture (e.g. a link that came with the spreadsheet), the printing is found by name, set and number
                $r['ext_id'] = priced_ext($pdo, $game, (string)$r['name'], $r['set_name'], $r['num'], $r['ext_id']);
                $rows[(int)$r['id']] = $r;
            }
        }
        if ($catIds) {
            $st = $pdo->prepare('SELECT id, ext_id FROM card_catalog WHERE game = ? AND id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')');
            $st->execute([$game, ...$catIds]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cats[(int)$r['id']] = $r['ext_id'];
        }
        foreach ($mine as $it) {
            $k = (string)($it['k'] ?? '');
            $finish = isset(FINISHES[$it['finish'] ?? '']) ? $it['finish'] : null;
            if (!empty($it['catalog_id'])) $ext = $cats[(int)$it['catalog_id']] ?? null;
            else { $r = $rows[(int)($it['id'] ?? 0)] ?? null; $ext = $r['ext_id'] ?? null; $finish ??= $r['finish'] ?? 'normal'; }
            $resolved[$k] = [$game, $ext, $finish ?? 'normal'];
        }
    }
    $data = [];
    foreach (array_keys(GAME_TABLES) as $game) {
        $ids = array_map(fn($x) => $x[1], array_filter($resolved, fn($x) => $x[0] === $game && $x[1]));
        $data[$game] = $ids ? price_data($pdo, $game, $ids) : [];
    }
    $out = [];
    foreach ($resolved as $k => [$game, $ext, $finish]) $out[$k] = $ext ? price_for_finish($game, $data[$game][$ext] ?? null, $finish) : null;
    return $out;
}

/* ---------- Pick list details: rarity (both games), colors and mana cost (Magic) ---------- */

/** The catalog printing (ext_id) an inventory card is: by its picture, otherwise by name, set and number. */
function printing_ext(PDO $pdo, string $game, string $name, ?string $set, ?string $num, ?string $image): ?string {
    static $byImg = null;
    $byImg ??= $pdo->prepare('SELECT ext_id FROM card_catalog WHERE game = ? AND image_url = ? LIMIT 1');
    $joined = null;
    if ($image) { $byImg->execute([$game, $image]); $joined = $byImg->fetchColumn() ?: null; }
    return priced_ext($pdo, $game, $name, $set, $num, $joined);
}

/**
 * Rarity and (Magic) colors / mana cost for the cards on a pick list.
 * $cards = [key => ['game', 'name', 'set', 'number', 'image']]. Returns [key => ['rarity' => label|null, 'mana' => array|null, 'set_code' => 'DSC'|null, 'cat_number' => string|null]].
 * Uses the card catalog; anything it doesn't have yet is looked up once (Scryfall / TCGdex) and saved into the catalog.
 */
function card_details(PDO $pdo, array $cards): array {
    $cols = 'ext_id, name_key, colors, mana_cost, cmc, type_line, rarity, set_code, set_alt, number';
    $hasMana = fn($r) => $r && ($r['colors'] !== null || $r['mana_cost'] !== null || $r['type_line'] !== null);
    $ext = []; $rows = []; $out = [];
    foreach ($cards as $k => $c) $ext[$k] = printing_ext($pdo, $c['game'], $c['name'], $c['set'] ?: null, $c['number'] ?: null, $c['image'] ?: null);
    $get = $pdo->prepare("SELECT $cols FROM card_catalog WHERE game = ? AND ext_id = ?");
    foreach ($cards as $k => $c) {
        if ($ext[$k] === null) continue;
        $get->execute([$c['game'], $ext[$k]]);
        $rows[$k] = $get->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    // Magic cards with no matching printing: colors and mana cost are the same for every printing, so any printing will do
    $byName = [];
    $noExt = array_filter(array_keys($cards), fn($k) => $cards[$k]['game'] === 'mtg' && empty($rows[$k]));
    if ($noExt) {
        $keys = array_values(array_unique(array_map(fn($k) => name_key($cards[$k]['name']), $noExt)));
        $st = $pdo->prepare("SELECT $cols FROM card_catalog WHERE game = 'mtg' AND name_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ')
                             ORDER BY (colors IS NULL AND mana_cost IS NULL)');
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byName[$r['name_key']] ??= $r;
    }

    // What still has to be looked up
    $askMtg = []; $askPkm = [];
    foreach ($cards as $k => $c) {
        $r = $rows[$k] ?? null;
        if ($c['game'] === 'mtg') {
            if ($r && (!$hasMana($r) || $r['rarity'] === null)) $askMtg[$k] = ['id' => $r['ext_id']];
            elseif (!$r && !$hasMana($byName[name_key($c['name'])] ?? null)) $askMtg[$k] = ['name' => explode(' // ', $c['name'])[0]];
        } elseif ($r && $r['rarity'] === null) $askPkm[$k] = $r['ext_id'];
    }
    $found = [];
    if ($askMtg) {
        $save = $pdo->prepare("UPDATE card_catalog SET colors = ?, mana_cost = ?, cmc = ?, type_line = ?, rarity = ? WHERE game = 'mtg' AND ext_id = ?");
        $idents = array_values(array_unique(array_map('json_encode', $askMtg)));
        foreach (array_chunk($idents, 75) as $chunk) {
            [$code, $d] = http_post_json('https://api.scryfall.com/cards/collection', ['identifiers' => array_map(fn($j) => json_decode($j, true), $chunk)]);
            if ($code !== 200 || !is_array($d)) continue;
            foreach ($d['data'] ?? [] as $c) {
                [$colors, $cost, $cmc, $type] = scryfall_mana($c);
                $rar = (string)($c['rarity'] ?? '');
                $info = ['ext_id' => $c['id'], 'colors' => $colors, 'mana_cost' => $cost, 'cmc' => $cmc, 'type_line' => $type, 'rarity' => $rar];
                $save->execute([$colors, $cost, $cmc, $type, $rar, $c['id']]);
                $found['id:' . $c['id']] = $info;
                $found['name:' . name_key($c['name'])] = $info;
                $found['name:' . name_key(explode(' // ', $c['name'])[0])] = $info;
            }
            usleep(100000);
        }
    }
    if ($askPkm) {
        $save = $pdo->prepare("UPDATE card_catalog SET rarity = ? WHERE game = 'pokemon' AND ext_id = ?");
        $urls = [];
        foreach (array_unique($askPkm) as $id) $urls[$id] = 'https://api.tcgdex.net/v2/en/cards/' . rawurlencode($id);
        foreach (http_get_many($urls) as $id => $d) {
            if (!is_array($d) || !isset($d['id'])) continue;   // unreachable: try again next time
            $rar = trim((string)($d['rarity'] ?? ''));
            if (strcasecmp($rar, 'None') === 0) $rar = '';
            $save->execute([$rar, $id]);
            $found['pkm:' . $id] = $rar;
        }
    }

    foreach ($cards as $k => $c) {
        $r = $rows[$k] ?? null;
        // Set code and number of this exact printing (as printed on the card), when the catalog has it
        $setCode = $r ? strtoupper((string)($r['set_alt'] ?: $r['set_code'])) : null;
        $catNum = $r['number'] ?? null;
        if ($c['game'] === 'mtg') {
            if (isset($askMtg[$k])) {
                $a = $askMtg[$k];
                $f = isset($a['id']) ? ($found['id:' . $a['id']] ?? null) : ($found['name:' . name_key($a['name'])] ?? null);
                if ($f && isset($a['name'])) $f['rarity'] = null;          // some printing of the card, not necessarily this one
                $r = $f ?? ($r ?: null);
            } elseif (!$r) {
                $r = $byName[name_key($c['name'])] ?? null;
                if ($r) $r['rarity'] = null;
            }
            $out[$k] = ['mana' => $hasMana($r) ? mana_display($r) : null, 'rarity' => rarity_label('mtg', $r['rarity'] ?? null), 'set_code' => $setCode ?: null, 'cat_number' => $catNum];
        } else {
            $rar = isset($askPkm[$k]) ? ($found['pkm:' . $askPkm[$k]] ?? null) : ($r['rarity'] ?? null);
            $out[$k] = ['mana' => null, 'rarity' => rarity_label('pokemon', $rar), 'set_code' => $setCode ?: null, 'cat_number' => $catNum];
        }
    }
    return $out;
}

/** "mythic" => ['label' => 'Mythic rare', 'class' => 'mythic'] */
function rarity_label(string $game, ?string $r): ?array {
    if ($r === null || trim($r) === '') return null;
    if ($game === 'mtg') {
        $map = ['common' => 'Common', 'uncommon' => 'Uncommon', 'rare' => 'Rare', 'mythic' => 'Mythic rare', 'special' => 'Special', 'bonus' => 'Bonus'];
        $k = strtolower($r);
        return ['label' => $map[$k] ?? ucfirst($k), 'class' => $k];
    }
    $l = strtolower($r);
    $class = match (true) {
        str_contains($l, 'hyper') || str_contains($l, 'secret') || str_contains($l, 'special illustration') || str_contains($l, 'mega hyper') => 'mythic',
        str_contains($l, 'illustration') || str_contains($l, 'ultra') || str_contains($l, 'double') || str_contains($l, 'ace') || str_contains($l, 'shiny') => 'rare-plus',
        str_contains($l, 'rare') => 'rare',
        str_contains($l, 'uncommon') => 'uncommon',
        str_contains($l, 'common') => 'common',
        default => 'special',
    };
    return ['label' => ucfirst($l), 'class' => $class];
}

/** What the pick list shows: color group (for sorting and the Color column), mana symbols and mana value. */
function mana_display(array $r): array {
    $names = ['W' => 'White', 'U' => 'Blue', 'B' => 'Black', 'R' => 'Red', 'G' => 'Green'];
    $colors = str_split((string)$r['colors']);
    $colors = array_values(array_filter($colors, fn($x) => isset($names[$x])));
    usort($colors, fn($a, $b) => array_search($a, array_keys($names)) <=> array_search($b, array_keys($names)));   // W U B R G order
    $isLand = stripos((string)$r['type_line'], 'land') !== false && !$colors;
    if ($isLand)              { $group = 'Land';       $order = 8; }
    elseif (!$colors)         { $group = 'Colorless';  $order = 7; }
    elseif (count($colors) > 1) { $group = 'Multicolor'; $order = 6; }
    else                      { $group = $names[$colors[0]]; $order = array_search($colors[0], array_keys($names)) + 1; }
    preg_match_all('/\{([^}]+)\}/', (string)$r['mana_cost'], $m);
    return [
        'group' => $group, 'order' => $order,
        'color_label' => count($colors) > 1 ? 'Multicolor (' . implode(', ', array_map(fn($x) => $names[$x], $colors)) . ')' : $group,
        'symbols' => $m[1],                       // ["2", "U", "U"], ["W/U"], ["X"]
        'cmc' => $r['cmc'] === null ? null : (float)$r['cmc'],
        'type' => (string)$r['type_line'],
    ];
}


/* ---------- Card details for the Inventory card window (rules text etc.), kept for 30 days ---------- */

/** Details for one catalog printing: catalog fields right away, rules text from Scryfall / TCGdex (cached). */
function card_info(PDO $pdo, string $game, ?string $ext): array {
    if (!$ext) return ['matched' => false];
    $pdo->exec("CREATE TABLE IF NOT EXISTS card_info_cache (
        game VARCHAR(10) NOT NULL, ext_id VARCHAR(64) NOT NULL, data MEDIUMTEXT NULL,
        fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (game, ext_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare('SELECT name, set_code, set_name, set_alt, number, released, image_url, colors, mana_cost, cmc, type_line, rarity FROM card_catalog WHERE game = ? AND ext_id = ?');
    $st->execute([$game, $ext]);
    $cat = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $info = ['matched' => (bool)$cat, 'name' => $cat['name'] ?? null, 'set_name' => $cat['set_name'] ?? null, 'set_code' => $cat['set_alt'] ?: ($cat['set_code'] ?? null),
             'number' => $cat['number'] ?? null, 'released' => $cat['released'] ?? null, 'rarity' => $cat['rarity'] ?? null, 'image' => $cat['image_url'] ?? null,
             'type' => $cat['type_line'] ?? null, 'mana_cost' => $cat['mana_cost'] ?? null, 'cmc' => isset($cat['cmc']) ? (float)$cat['cmc'] : null,
             'colors' => $cat['colors'] ?? null, 'details' => null];

    $st = $pdo->prepare('SELECT data, fetched_at > NOW() - INTERVAL 30 DAY AS fresh FROM card_info_cache WHERE game = ? AND ext_id = ?');
    $st->execute([$game, $ext]);
    $cached = $st->fetch(PDO::FETCH_ASSOC);
    $details = $cached && $cached['data'] ? json_decode($cached['data'], true) : null;
    if (!$cached || !$cached['fresh']) {
        $fresh = fetch_card_details($game, $ext);
        if ($fresh) {
            $details = $fresh;
            $pdo->prepare('REPLACE INTO card_info_cache (game, ext_id, data, fetched_at) VALUES (?, ?, ?, NOW())')->execute([$game, $ext, json_encode($fresh, JSON_UNESCAPED_UNICODE)]);
        }
    }
    $info['details'] = $details;
    if ($details) {
        foreach (['type', 'mana_cost', 'colors', 'rarity', 'set_name', 'number'] as $k) if (!$info[$k] && !empty($details[$k])) $info[$k] = $details[$k];
        if ($info['cmc'] === null && isset($details['cmc'])) $info['cmc'] = (float)$details['cmc'];
        if (!empty($details['image_large'])) $info['image_large'] = $details['image_large'];
    }
    return $info;
}

/** Rules text and other details from Scryfall (Magic) or TCGdex (Pokémon). Null if the site can't be reached. */
function fetch_card_details(string $game, string $ext): ?array {
    if ($game === 'mtg') {
        [$code, $c] = http_get_json('https://api.scryfall.com/cards/' . rawurlencode($ext));
        if ($code !== 200 || !is_array($c) || empty($c['name'])) return null;
        $faces = [];
        foreach (!empty($c['card_faces']) ? $c['card_faces'] : [$c] as $f) {
            $faces[] = ['name' => $f['name'] ?? $c['name'], 'mana_cost' => $f['mana_cost'] ?? ($c['mana_cost'] ?? ''), 'type' => $f['type_line'] ?? ($c['type_line'] ?? ''),
                        'text' => $f['oracle_text'] ?? '', 'pt' => isset($f['power']) ? $f['power'] . '/' . ($f['toughness'] ?? '') : null,
                        'loyalty' => $f['loyalty'] ?? null, 'flavor' => $f['flavor_text'] ?? null];
        }
        [$colors] = scryfall_mana($c);
        return ['faces' => $faces, 'type' => $c['type_line'] ?? null, 'mana_cost' => $faces[0]['mana_cost'] ?: null, 'cmc' => $c['cmc'] ?? null,
                'colors' => $colors, 'rarity' => $c['rarity'] ?? null, 'set_name' => $c['set_name'] ?? null, 'number' => $c['collector_number'] ?? null,
                'artist' => $c['artist'] ?? null, 'image_large' => $c['image_uris']['large'] ?? ($c['card_faces'][0]['image_uris']['large'] ?? null)];
    }
    [$code, $d] = http_get_json('https://api.tcgdex.net/v2/en/cards/' . rawurlencode($ext));
    if ($code !== 200 || !is_array($d) || empty($d['name'])) return null;
    return ['category' => $d['category'] ?? null, 'hp' => $d['hp'] ?? null, 'types' => $d['types'] ?? [], 'stage' => $d['stage'] ?? null,
            'evolves_from' => $d['evolveFrom'] ?? null, 'abilities' => $d['abilities'] ?? [], 'attacks' => $d['attacks'] ?? [],
            'effect' => $d['effect'] ?? null, 'trainer_type' => $d['trainerType'] ?? null, 'energy_type' => $d['energyType'] ?? null,
            'weaknesses' => $d['weaknesses'] ?? [], 'resistances' => $d['resistances'] ?? [], 'retreat' => $d['retreat'] ?? null,
            'regulation' => $d['regulationMark'] ?? null, 'artist' => $d['illustrator'] ?? null, 'flavor' => $d['description'] ?? null,
            'rarity' => $d['rarity'] ?? null, 'set_name' => $d['set']['name'] ?? null, 'number' => $d['localId'] ?? null,
            'image_large' => !empty($d['image']) ? $d['image'] . '/high.webp' : null];
}
