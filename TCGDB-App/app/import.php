<?php
require __DIR__ . '/db.php';
require __DIR__ . '/backup_lib.php';
require __DIR__ . '/auth.php';
require_staff();
require __DIR__ . '/partials.php';

/* ---------- Spreadsheet readers (stream rows, so big files don't run out of memory) ---------- */

function col_index(string $ref): int {
    preg_match('/^[A-Z]+/', $ref, $m);
    $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n - 1;
}

/** Yields rowNumber => [cells] from the first worksheet of an .xlsx. */
function read_xlsx(string $path): Generator {
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) throw new RuntimeException('Could not open that .xlsx file.');

    $sheet = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n) && ($sheet === null || strnatcmp($n, $sheet) < 0)) $sheet = $n;
    }
    if ($sheet === null) throw new RuntimeException('No worksheet found in that file.');

    $shared = [];
    if ($zip->locateName('xl/sharedStrings.xml') !== false) {
        $r = new XMLReader;
        $r->open('zip://' . $path . '#xl/sharedStrings.xml');
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                $si = simplexml_load_string($r->readOuterXml());
                $t = isset($si->t) ? (string)$si->t : '';
                foreach ($si->r as $run) $t .= (string)$run->t;
                $shared[] = $t;
            }
        }
        $r->close();
    }
    $zip->close();

    $r = new XMLReader;
    $r->open('zip://' . $path . '#' . $sheet);
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') continue;
        $row = simplexml_load_string($r->readOuterXml());
        $cells = [];
        foreach ($row->c as $c) {
            $t = (string)$c['t'];
            if ($t === 's')             $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            else                        $v = (string)$c->v;
            $cells[col_index((string)$c['r'])] = $v;
        }
        if ($cells) {
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            yield (int)$row['r'] => $line;
        }
    }
    $r->close();
}

/** Yields rowNumber => [cells] from a .csv. */
function read_csv(string $path): Generator {
    $h = fopen($path, 'r');
    $n = 0;
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $n++;
        if ($n === 1 && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
        yield $n => $r;
    }
    fclose($h);
}

/* ---------- Import logic ---------- */

/** Spreadsheet headings that mean the same thing as a column. */
function heading_aliases(array $cols): array {
    $a = [];
    foreach (['condition', 'cond', 'cardcondition', 'grade'] as $k)                            $a[$k] = $cols['cardcondition'] ?? null;
    foreach (['finish', 'foil', 'printing', 'variant', 'treatment', 'foiling', 'holo'] as $k) $a[$k] = $cols['finish'] ?? null;
    foreach (['number', 'cardnumber', 'collectornumber', 'collectionnumber', 'setnumber', 'no', 'num'] as $k) $a[$k] = number_col($cols);
    foreach (['set', 'setname', 'expansion', 'edition', 'setcode'] as $k)                     $a[$k] = set_col($cols);
    foreach (['name', 'cardname', 'card'] as $k)                                              $a[$k] = name_col($cols);
    foreach (['quantity', 'qty', 'count', 'copies', 'amount'] as $k)                          $a[$k] = qty_col($cols);
    return array_filter($a);
}

function do_import(PDO $pdo, string $game, iterable $rows, int $batchSize = 500): array {
    $table = GAME_TABLES[$game];
    $cols = table_columns($pdo, $table);
    $res = ['matched' => [], 'ignored' => [], 'added' => 0, 'merged' => 0, 'rows' => 0, 'errors' => [], 'error_count' => 0];
    $fail = function (string $msg) use (&$res) { $res['error_count']++; if (count($res['errors']) < 15) $res['errors'][] = $msg; };

    if (!$cols) { $fail("Table \"$table\" was not found. Check the names in db.php."); return $res; }

    $qtyName = qty_col($cols);
    $nameCol = name_col($cols);
    $condCol = $cols['cardcondition'];
    $finCol  = $cols['finish'];
    $aliases = heading_aliases($cols);

    $map = null;
    $insCols = [];
    $batch = [];

    $flush = function () use (&$batch, &$res, &$insCols, $pdo, $table, $qtyName, $fail) {
        if (!$batch) return;
        $one = '(' . implode(', ', array_fill(0, count($insCols), '?')) . ')';
        $sqlFor = fn(int $n) => 'INSERT INTO `' . $table . '` (`' . implode('`, `', $insCols) . '`) VALUES '
            . implode(', ', array_fill(0, $n, $one))
            . ($qtyName ? " ON DUPLICATE KEY UPDATE `$qtyName` = COALESCE(`$qtyName`, 0) + VALUES(`$qtyName`)" : ' ON DUPLICATE KEY UPDATE id = id');
        try {
            $st = $pdo->prepare($sqlFor(count($batch)));
            $st->execute(array_merge(...array_column($batch, 1)));
            // MySQL counts 1 per inserted row and 2 per updated row
            $merged = $st->rowCount() - count($batch);
            $res['merged'] += max(0, $merged);
            $res['added'] += count($batch) - max(0, $merged);
        } catch (PDOException $e) {
            // Something in this batch is bad: retry one by one to report the exact rows
            $st = $pdo->prepare($sqlFor(1));
            foreach ($batch as [$rn, $vals]) {
                try { $st->execute($vals); $st->rowCount() === 2 ? $res['merged']++ : $res['added']++; }
                catch (PDOException $e) { $fail("Row $rn: " . preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $e->getMessage())); }
            }
        }
        $batch = [];
    };

    $pdo->beginTransaction();
    try {
        foreach ($rows as $rn => $row) {
            if ($map === null) { // first row = headings
                $map = [];
                foreach ($row as $i => $h) {
                    $h = trim((string)$h);
                    if ($h === '') continue;
                    $k = norm($h);
                    $col = $cols[$k] ?? $aliases[$k] ?? null;
                    if ($col !== null && !in_array($col, $map, true)) $map[$i] = $col; else $res['ignored'][] = $h;
                }
                $res['matched'] = array_values($map);
                if (!$map) { $fail('None of the column headings in row 1 match the table. Headings must match the column names.'); break; }
                if ($nameCol && !in_array($nameCol, $map, true)) { $fail("Row 1 needs a \"$nameCol\" column."); break; }
                $insCols = array_values(array_unique(array_merge(array_values($map), [$condCol, $finCol])));
                continue;
            }

            $data = array_fill_keys($insCols, null);
            foreach ($map as $i => $col) {
                $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
                $data[$col] = $v === '' ? null : $v;
            }
            if (!array_filter($data, fn($v) => $v !== null)) continue; // blank row
            $res['rows']++;

            $cond = normalize_condition($data[$condCol]);
            $fin = normalize_finish($data[$finCol], $game);
            if ($cond === null) { $fail("Row $rn: condition \"{$data[$condCol]}\" isn't one of " . implode(', ', array_keys(CONDITIONS)) . '.'); continue; }
            if ($fin === null)  { $fail("Row $rn: finish \"{$data[$finCol]}\" isn't one of " . implode(', ', FINISHES) . '.'); continue; }
            if ($nameCol && $data[$nameCol] === null) { $fail("Row $rn: card name is blank."); continue; }
            $data[$condCol] = $cond;
            $data[$finCol] = $fin;
            if ($qtyName) {
                $q = $data[$qtyName];
                if ($q === null) $q = 1;
                elseif (!preg_match('/^\d+$/', (string)$q) || (int)$q < 1) { $fail("Row $rn: quantity \"$q\" must be a whole number of 1 or more."); continue; }
                $data[$qtyName] = (int)$q;
            }
            $batch[] = [$rn, array_values($data)];
            if (count($batch) >= $batchSize) $flush();
        }
        $flush();
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }

    if ($map !== null && $res['rows'] === 0 && !$res['error_count']) $fail('The file has headings but no cards.');
    if ($map === null && !$res['error_count']) $fail('The file is empty.');
    if ($res['error_count']) $pdo->rollBack(); else $pdo->commit();
    return $res;
}

/* ---------- Request handling ---------- */

$result = null;
$fatal = null;
$game = $_POST['game'] ?? 'pokemon';
$tableInfo = [];

try {
    $pdo = app_db();
    foreach (GAME_TABLES as $g => $t) $tableInfo[$g] = array_values(table_columns($pdo, $t));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        if (!isset(GAME_TABLES[$game])) throw new RuntimeException('Choose a game.');
        $f = $_FILES['sheet'] ?? null;
        if (!$f && !empty($_SERVER['CONTENT_LENGTH'])) throw new RuntimeException('That file is larger than PHP allows. Raise upload_max_filesize and post_max_size in php.ini.');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $big = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new RuntimeException($big ? 'That file is larger than PHP allows. Raise upload_max_filesize and post_max_size in php.ini.' : 'Choose a file to import.');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Only .xlsx and .csv files can be imported.');

        // Snapshot of the data before the import, so a bad spreadsheet can be undone from Backups
        try { make_backup($pdo, 'before-import'); }
        catch (Throwable $e) { throw new RuntimeException('Import stopped: the backup before importing failed (' . $e->getMessage() . '). Nothing was changed.'); }

        $started = microtime(true);
        $result = do_import($pdo, $game, $ext === 'xlsx' ? read_xlsx($f['tmp_name']) : read_csv($f['tmp_name']));
        $result['seconds'] = microtime(true) - $started;
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

page_head('Import', 'import', 'import-page', ['import.css']);
?>
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
                <?php if ($result['error_count']): ?>
                    <div class="notice error" role="alert">
                        <strong>Nothing was imported.</strong> Fix the problems below and upload the file again.
                        <ul>
                            <?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                            <?php if ($result['error_count'] > count($result['errors'])): ?><li>and <?= $result['error_count'] - count($result['errors']) ?> more.</li><?php endif; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="notice success"><strong>Import complete.</strong> <?= number_format($result['rows']) ?> rows in <?= number_format($result['seconds'], 1) ?> seconds.</div>
                    <dl class="stats">
                        <div><dt>New rows added</dt><dd><?= number_format($result['added']) ?></dd></div>
                        <div><dt>Existing cards updated</dt><dd><?= number_format($result['merged']) ?></dd></div>
                    </dl>
                <?php endif; ?>

                <?php if ($result['ignored']): ?>
                    <p class="note">Columns not imported because they don't match the table: <?= h(implode(', ', $result['ignored'])) ?>.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="how-it-works">
            <h2>How importing works</h2>
            <p>Row 1 of your file must be column headings that match your table's column names (capitalization and spacing don't matter). Accepted files: .xlsx (first sheet) and .csv.</p>
            <p>Add a <strong>Condition</strong> column with <?= h(implode(', ', array_keys(CONDITIONS))) ?> (or the full words, like "Lightly Played"). Blank means <?= h(array_key_first(CONDITIONS)) ?>.
               Add a <strong>Finish</strong> or <strong>Foil</strong> column with Normal, Foil, Reverse Holo or Etched. Blank means Normal. For Pokémon, "Holo" counts as the card's normal printing.</p>
            <p>If a card with the same values (including condition and finish) already exists, its <?= h(QTY_COLUMN) ?> goes up instead of adding a new row. If any row has a problem, nothing is imported, so it's safe to fix the file and try again.</p>
            <?php foreach ($tableInfo as $g => $cols): ?>
                <p class="cols"><strong><?= $g === 'mtg' ? 'Magic' : 'Pokémon' ?> columns:</strong> <?= $cols ? h(implode(', ', $cols)) : 'table not found' ?></p>
            <?php endforeach; ?>
        </section>
<?php page_foot(); ?>
