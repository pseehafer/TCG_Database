<?php
require __DIR__ . '/db.php';

/* ---------- Spreadsheet readers (no libraries needed) ---------- */

function col_index(string $ref): int {
    preg_match('/^[A-Z]+/', $ref, $m);
    $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n - 1;
}

/** Reads the first worksheet of an .xlsx. Returns [rowNumber => [cells]] */
function read_xlsx(string $path): array {
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) throw new RuntimeException('Could not open that .xlsx file.');

    $shared = [];
    if (($x = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        foreach (simplexml_load_string($x)->si as $si) {
            if (isset($si->t)) { $shared[] = (string)$si->t; continue; }
            $t = '';
            foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }

    $sheet = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n) && ($sheet === null || strnatcmp($n, $sheet) < 0)) $sheet = $n;
    }
    if ($sheet === null) throw new RuntimeException('No worksheet found in that file.');

    $xml = simplexml_load_string($zip->getFromName($sheet));
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $t = (string)$c['t'];
            if ($t === 's')               $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr')   $v = (string)$c->is->t;
            else                          $v = (string)$c->v;
            $cells[col_index((string)$c['r'])] = $v;
        }
        if ($cells) {
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            $rows[(int)$row['r']] = $line;
        }
    }
    return $rows;
}

function read_csv(string $path): array {
    $rows = [];
    $h = fopen($path, 'r');
    $n = 0;
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $n++;
        if ($n === 1 && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
        $rows[$n] = $r;
    }
    fclose($h);
    return $rows;
}

/* ---------- Import logic ---------- */

function do_import(PDO $pdo, string $table, array $rows): array {
    $cols = table_columns($pdo, $table);
    $res = ['matched' => [], 'ignored' => [], 'added' => 0, 'merged' => 0, 'skipped' => 0, 'errors' => []];

    if (!$cols) { $res['errors'][] = "Table \"$table\" was not found. Check the names in db.php."; return $res; }
    if (!$rows) { $res['errors'][] = 'The file is empty.'; return $res; }

    $first = array_key_first($rows);
    $header = $rows[$first];
    unset($rows[$first]);

    $map = [];
    foreach ($header as $i => $h) {
        $h = trim((string)$h);
        if ($h === '') continue;
        $k = norm($h);
        if (isset($cols[$k])) $map[$i] = $cols[$k]; else $res['ignored'][] = $h;
    }
    $res['matched'] = array_values($map);
    if (!$map) { $res['errors'][] = 'None of the column headings in row 1 match the table. Headings must match the column names.'; return $res; }

    $qtyName = $cols[norm(QTY_COLUMN)] ?? null;

    $pdo->beginTransaction();
    foreach ($rows as $rn => $row) {
        $data = [];
        $qty = 1;
        foreach ($map as $i => $col) {
            $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
            if ($qtyName !== null && $col === $qtyName) { $qty = (is_numeric($v) && (int)$v > 0) ? (int)$v : 1; continue; }
            $data[$col] = $v === '' ? null : $v;
        }
        if (!array_filter($data, fn($v) => $v !== null)) continue; // blank row

        try {
            $where = implode(' AND ', array_map(fn($c) => "`$c` <=> ?", array_keys($data)));
            $params = array_values($data);

            if ($qtyName !== null) {
                $st = $pdo->prepare("UPDATE `$table` SET `$qtyName` = COALESCE(`$qtyName`, 0) + ? WHERE $where LIMIT 1");
                $st->execute(array_merge([$qty], $params));
                if ($st->rowCount() > 0) { $res['merged']++; continue; }
            } else {
                $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE $where LIMIT 1");
                $st->execute($params);
                if ($st->fetchColumn()) { $res['skipped']++; continue; }
            }

            $ins = $data;
            if ($qtyName !== null) $ins[$qtyName] = $qty;
            $st = $pdo->prepare(
                "INSERT INTO `$table` (`" . implode('`, `', array_keys($ins)) . '`) VALUES (' .
                implode(', ', array_fill(0, count($ins), '?')) . ')'
            );
            $st->execute(array_values($ins));
            $res['added']++;
        } catch (PDOException $e) {
            $res['errors'][] = "Row $rn: " . $e->getMessage();
        }
    }
    if ($res['errors']) $pdo->rollBack(); else $pdo->commit();
    return $res;
}

/* ---------- Request handling ---------- */

$result = null;
$fatal = null;
$game = $_POST['game'] ?? 'pokemon';
$tableInfo = [];

try {
    $pdo = db();
    foreach (GAME_TABLES as $g => $t) $tableInfo[$g] = array_values(table_columns($pdo, $t));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Choose a game.');
        $f = $_FILES['sheet'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a file to import.');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Only .xlsx and .csv files can be imported.');

        $rows = $ext === 'xlsx' ? read_xlsx($f['tmp_name']) : read_csv($f['tmp_name']);
        $result = do_import($pdo, GAME_TABLES[$game], $rows);
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import | TCG Database</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="homePage.css">
    <link rel="stylesheet" href="import.css">
</head>
<body>
<div id="container">
    <header id="header">
        <a class="brand" href="homePage.html">TCG Database</a>
        <nav id="sub-header" aria-label="Main">
            <a href="inventory.html" id="inventory">Inventory</a>
            <a href="import.php" id="import" aria-current="page">Import</a>
            <a href="list-builder.html" id="list-builder">List Builder</a>
        </nav>
    </header>

    <main id="content" class="import-page">
        <h1>Import cards</h1>
        <p class="lead">Upload a spreadsheet to add cards to your collection.</p>

        <?php if ($fatal): ?>
            <div class="notice error" role="alert"><?= h($fatal) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="import-form">
            <fieldset class="game-picker">
                <legend class="visually-hidden">Game</legend>
                <label><input type="radio" name="game" value="pokemon" <?= $game === 'pokemon' ? 'checked' : '' ?>><span>Pokémon</span></label>
                <label><input type="radio" name="game" value="mtg" <?= $game === 'mtg' ? 'checked' : '' ?>><span>Magic</span></label>
            </fieldset>

            <div class="file-row">
                <label for="sheet" class="visually-hidden">Spreadsheet file</label>
                <input type="file" id="sheet" name="sheet" accept=".xlsx,.csv" required>
                <button type="submit">Import cards</button>
            </div>
        </form>

        <?php if ($result): ?>
            <section class="results" aria-live="polite">
                <?php if ($result['errors']): ?>
                    <div class="notice error" role="alert">
                        <strong>Nothing was imported.</strong> Fix the problems below and upload the file again.
                        <ul>
                            <?php foreach (array_slice($result['errors'], 0, 15) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                            <?php if (count($result['errors']) > 15): ?><li>and <?= count($result['errors']) - 15 ?> more.</li><?php endif; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="notice success"><strong>Import complete.</strong></div>
                    <dl class="stats">
                        <div><dt>New rows added</dt><dd><?= $result['added'] ?></dd></div>
                        <div><dt>Existing cards updated</dt><dd><?= $result['merged'] ?></dd></div>
                        <?php if ($result['skipped']): ?><div><dt>Duplicates skipped</dt><dd><?= $result['skipped'] ?></dd></div><?php endif; ?>
                    </dl>
                    <?php if ($result['skipped']): ?>
                        <p class="note">Your table has no "<?= h(QTY_COLUMN) ?>" column, so exact duplicates were skipped instead of counted.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($result['ignored']): ?>
                    <p class="note">Columns not imported because they don't match the table: <?= h(implode(', ', $result['ignored'])) ?>.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="how-it-works">
            <h2>How importing works</h2>
            <p>Row 1 of your file must be column headings that match your table's column names (capitalization and spacing don't matter). Accepted files: .xlsx (first sheet) and .csv.</p>
            <p>If a card with exactly the same values already exists, its <?= h(QTY_COLUMN) ?> goes up instead of adding a new row. If any value differs, such as the set or condition, it's added as a new row. If any row fails, nothing is imported, so it's safe to fix the file and try again.</p>
            <?php foreach ($tableInfo as $g => $cols): ?>
                <p class="cols"><strong><?= $g === 'mtg' ? 'Magic' : 'Pokémon' ?> columns:</strong> <?= $cols ? h(implode(', ', $cols)) : 'table not found' ?></p>
            <?php endforeach; ?>
        </section>
    </main>

    <footer id="footer">
        <p>&copy; 2026 TCG Database. All rights reserved.</p>
    </footer>
</div>
</body>
</html>
