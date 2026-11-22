<?php
/**
 * Backups of the store's own data: inventory, lists, pull history, settings and portfolio totals.
 * The picture catalog and price cache are left out: Card Catalog and the price lookups download them again.
 * Each backup is one compressed file (.sql.gz) in BACKUP_DIR. Used by backup.php and import.php.
 */

// Outside the web folder, so the files can't be downloaded from the web without signing in.
// Packaged version: the launcher passes the folder (data\backups) in TCGDB_BACKUP_DIR.
define('BACKUP_DIR', getenv('TCGDB_BACKUP_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tcg_backups');
const BACKUP_KINDS = ['auto' => 'Automatic (daily)', 'manual' => 'Manual', 'before-import' => 'Before an import', 'before-restore' => 'Before a restore'];
const BACKUP_MARK = '-- TCG Database backup';

function backup_tables(PDO $pdo): array {
    $want = array_merge(array_values(GAME_TABLES), ['card_lists', 'card_list_items', 'pull_log', 'app_meta', 'portfolio_snapshots']);
    return array_values(array_filter($want, fn($t) => (bool)table_columns($pdo, $t, true)));
}

function backup_dir(): string {
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0775, true)) throw new RuntimeException('Could not create the backup folder ' . BACKUP_DIR . '.');
    // In case the folder ends up inside the website: refuse web access to it
    if (!is_file(BACKUP_DIR . '/.htaccess')) @file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\n");
    return BACKUP_DIR;
}

/** Makes a backup. Returns ['file' => name, 'bytes' => n, 'rows' => [table => count], 'seconds' => s]. */
function make_backup(PDO $pdo, string $kind): array {
    if (!isset(BACKUP_KINDS[$kind])) $kind = 'manual';
    @set_time_limit(0);
    $got = (int)$pdo->query("SELECT GET_LOCK('tcgdb_backup', 30)")->fetchColumn();
    if (!$got) throw new RuntimeException('Another backup is running. Try again in a minute.');
    $started = microtime(true);
    $dir = backup_dir();
    $name = 'tcgdb-' . date('Y-m-d_His') . "-$kind.sql.gz";
    $tmp = "$dir/$name.part";
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) { $pdo->query("SELECT RELEASE_LOCK('tcgdb_backup')"); throw new RuntimeException("Could not write to $dir."); }
    $rows = [];
    try {
        $tables = backup_tables($pdo);
        gzwrite($gz, BACKUP_MARK . ' ' . json_encode(['made' => date('c'), 'kind' => $kind, 'database' => DB_NAME, 'tables' => $tables]) . "\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . str_replace(["\r", "\n"], ' ', $create) . ";\n");
            // Generated columns (row_key) are rebuilt by the database, so they aren't saved
            $st = $pdo->prepare("SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
            $st->execute([$t]);
            $cols = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (stripos($c['EXTRA'], 'GENERATED') === false) $cols[] = $c['COLUMN_NAME'];
            $colList = '`' . implode('`, `', $cols) . '`';
            $pk = $pdo->query("SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            $pkCol = count($pk) === 1 ? $pk[0]['Column_name'] : null;
            $rows[$t] = 0;
            $last = null;
            do {
                // A page at a time, so even very large tables don't use much memory
                if ($pkCol && $pkCol === 'id') {
                    $q = $pdo->prepare("SELECT $colList FROM `$t` WHERE `id` > ? ORDER BY `id` LIMIT 2000");
                    $q->execute([$last ?? -1]);
                } else {
                    $q = $pdo->query("SELECT $colList FROM `$t`");
                }
                $batch = $q->fetchAll(PDO::FETCH_NUM);
                foreach (array_chunk($batch, 250) as $chunk) {
                    $vals = array_map(fn($r) => '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')', $chunk);
                    gzwrite($gz, "INSERT INTO `$t` ($colList) VALUES " . str_replace(["\r", "\n"], ['\\r', '\\n'], implode(',', $vals)) . ";\n");
                }
                $rows[$t] += count($batch);
                if ($batch && $pkCol === 'id') $last = $batch[count($batch) - 1][array_search('id', $cols, true)];
            } while ($pkCol === 'id' && count($batch) === 2000);
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
        gzclose($gz);
        rename($tmp, "$dir/$name");
    } catch (Throwable $e) {
        @gzclose($gz); @unlink($tmp);
        $pdo->query("SELECT RELEASE_LOCK('tcgdb_backup')");
        throw $e;
    }
    $pdo->query("SELECT RELEASE_LOCK('tcgdb_backup')");
    meta_set($pdo, 'backup:last', date('c'));
    if ($kind === 'auto') meta_set($pdo, 'backup:last_auto', date('c'));
    meta_set($pdo, 'backup:last_rows', json_encode($rows));
    prune_backups();
    return ['file' => $name, 'bytes' => filesize("$dir/$name"), 'rows' => $rows, 'seconds' => round(microtime(true) - $started, 1)];
}

/** Makes the daily automatic backup if the last one is older than $hours. Returns the result, or null if not due. */
function auto_backup_if_due(PDO $pdo, int $hours = 20): ?array {
    $last = meta_get($pdo, 'backup:last_auto');
    if ($last && strtotime($last) > time() - $hours * 3600) return null;
    return make_backup($pdo, 'auto');
}

/** Saved backups, newest first: [['file', 'kind', 'time', 'bytes']] */
function list_backups(): array {
    if (!is_dir(BACKUP_DIR)) return [];
    $out = [];
    foreach (glob(BACKUP_DIR . '/tcgdb-*.sql.gz') ?: [] as $path) {
        if (!preg_match('/^tcgdb-(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})-([a-z-]+)\.sql\.gz$/', basename($path), $m)) continue;
        $out[] = ['file' => basename($path), 'kind' => $m[5], 'time' => strtotime("$m[1] $m[2]:$m[3]:$m[4]"), 'bytes' => filesize($path)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/**
 * Keeps: automatic — one a day for 14 days, then one a week for 8 weeks, then one a month for 12 months;
 * the last 30 manual, 20 before-import and 10 before-restore backups. Deletes the rest.
 */
function prune_backups(): void {
    $keep = [];
    $byKind = [];
    foreach (list_backups() as $b) $byKind[$b['kind']][] = $b;
    foreach (['manual' => 30, 'before-import' => 20, 'before-restore' => 10] as $k => $n) {
        foreach (array_slice($byKind[$k] ?? [], 0, $n) as $b) $keep[$b['file']] = true;
    }
    $days = $weeks = $months = [];
    foreach ($byKind['auto'] ?? [] as $b) {
        $d = date('Y-m-d', $b['time']); $w = date('o-W', $b['time']); $m = date('Y-m', $b['time']);
        if (!isset($days[$d]) && count($days) < 14) { $days[$d] = true; $keep[$b['file']] = true; continue; }
        if (isset($days[$d])) continue;
        if (!isset($weeks[$w]) && count($weeks) < 8) { $weeks[$w] = true; $keep[$b['file']] = true; continue; }
        if (isset($weeks[$w])) continue;
        if (!isset($months[$m]) && count($months) < 12) { $months[$m] = true; $keep[$b['file']] = true; }
    }
    foreach (list_backups() as $b) if (!isset($keep[$b['file']])) @unlink(BACKUP_DIR . '/' . $b['file']);
}

/** Reads the header of a backup file. Returns the info array, or null if it isn't a TCG Database backup. */
function backup_info(string $path): ?array {
    $gz = @gzopen($path, 'rb');
    if (!$gz) return null;
    $line = (string)gzgets($gz, 65536);
    gzclose($gz);
    if (!str_starts_with($line, BACKUP_MARK . ' ')) return null;
    return json_decode(substr($line, strlen(BACKUP_MARK) + 1), true) ?: [];
}

/** Replaces the store's data with a backup. A "before-restore" backup of the current data is made first. */
function restore_backup(PDO $pdo, string $path): array {
    $info = backup_info($path);
    if ($info === null) throw new RuntimeException("That file isn't a TCG Database backup.");
    @set_time_limit(0);
    // Read it all the way through first: a damaged or cut-off file is refused before anything is changed
    $gz = gzopen($path, 'rb'); $complete = false;
    while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) $complete = rtrim($line, "\r\n") === '-- end of backup';
    gzclose($gz);
    if (!$complete) throw new RuntimeException('That backup file is incomplete or damaged (it may have been cut off while copying). Nothing was changed.');
    $safety = make_backup($pdo, 'before-restore');
    $gz = gzopen($path, 'rb');
    $n = 0; $done = false;
    try {
        gzgets($gz, 65536);   // header
        while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '--')) { if ($line === '-- end of backup') $done = true; continue; }
            $pdo->exec(rtrim($line, ';'));
            $n++;
        }
    } catch (Throwable $e) {
        gzclose($gz);
        throw new RuntimeException('The restore stopped partway (' . $e->getMessage() . '). Your data from just before the restore is saved as ' . $safety['file'] . '; restore that to go back.');
    }
    gzclose($gz);
    if (!$done) throw new RuntimeException('That backup file is incomplete (it may have been cut off while copying). Your data from just before the restore is saved as ' . $safety['file'] . '.');
    return ['statements' => $n, 'safety' => $safety['file'], 'made' => $info['made'] ?? null];
}

function human_bytes(int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : ($b >= 1024 ? number_format($b / 1024) . ' KB' : $b . ' bytes');
}
