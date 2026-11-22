<?php
/**
 * Backups page: back up now, download or restore saved backups.
 * Also run by the TCG Database launcher about once an hour (it only backs up when one is due):
 *   runtime\php\php.exe app\backup.php auto          (backs up if the last automatic one is 20+ hours old)
 *   runtime\php\php.exe app\backup.php now           (backs up right away)
 */
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/backup_lib.php';

if (PHP_SAPI === 'cli') {
    try {
        $pdo = app_db();
        $r = ($argv[1] ?? 'auto') === 'now' ? make_backup($pdo, 'manual') : auto_backup_if_due($pdo);
        echo $r ? "Backed up to {$r['file']} (" . human_bytes($r['bytes']) . ", {$r['seconds']} s)\n" : "Not due yet: the last automatic backup is less than 20 hours old.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

require __DIR__ . '/partials.php';
$act = $_GET['action'] ?? '';
require_staff($act === 'auto');
$pdo = app_db();

/* Called quietly by staff pages: makes the daily backup if the scheduled one didn't run (computer was off, etc.) */
if ($act === 'auto') {
    header('Content-Type: application/json; charset=utf-8');
    ignore_user_abort(true);
    try { $r = auto_backup_if_due($pdo, 24); echo json_encode(['made' => $r['file'] ?? null]); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

$safeName = fn($f) => preg_match('/^tcgdb-[0-9_-]+-[a-z-]+\.sql\.gz$/', (string)$f) && is_file(BACKUP_DIR . '/' . $f) ? $f : null;

if ($act === 'download') {
    $f = $safeName($_GET['f'] ?? '');
    if (!$f) { http_response_code(404); exit('That backup no longer exists.'); }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(BACKUP_DIR . '/' . $f));
    readfile(BACKUP_DIR . '/' . $f);
    exit;
}

$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_session();
    try {
        $do = $_POST['do'] ?? '';
        if ($do === 'backup') {
            $r = make_backup($pdo, 'manual');
            $_SESSION['flash_backup'] = ['ok', 'Backup saved: ' . $r['file'] . ' (' . human_bytes($r['bytes']) . ', ' . number_format(array_sum($r['rows'])) . ' rows, ' . $r['seconds'] . ' s).'];
        } elseif ($do === 'restore' || $do === 'restore_upload') {
            if (($_POST['confirm'] ?? '') !== 'RESTORE') throw new RuntimeException('Type RESTORE in the box to confirm.');
            if ($do === 'restore') {
                $f = $safeName($_POST['file'] ?? '');
                if (!$f) throw new RuntimeException('That backup no longer exists.');
                $path = BACKUP_DIR . '/' . $f; $label = $f;
            } else {
                $u = $_FILES['backup'] ?? null;
                if (!$u || $u['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a backup file (.sql.gz) to restore. If it is large, raise upload_max_filesize and post_max_size in php.ini.');
                $path = $u['tmp_name']; $label = $u['name'];
            }
            $r = restore_backup($pdo, $path);
            $_SESSION['flash_backup'] = ['ok', 'Restored ' . $label . ($r['made'] ? ' (made ' . date('M j, Y g:i a', strtotime($r['made'])) . ')' : '') . '. Your data from just before the restore was saved as ' . $r['safety'] . ', in case you need to go back.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_backup'] = ['error', $e->getMessage()];
    }
    header('Location: backup.php');
    exit;
}
auth_session();
$flash = $_SESSION['flash_backup'] ?? null;
unset($_SESSION['flash_backup']);

$backups = list_backups();
$lastAuto = meta_get($pdo, 'backup:last_auto');
$counts = [];
foreach (GAME_TABLES as $g => $t) $counts[$g] = table_columns($pdo, $t) ? (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() : 0;
$ago = function (int $ts): string {
    $s = time() - $ts;
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' min ago';
    if ($s < 86400) return intdiv($s, 3600) . ' h ago';
    return intdiv($s, 86400) . ' days ago';
};
$autoOk = $lastAuto && strtotime($lastAuto) > time() - 36 * 3600;

page_head('Backups', 'backup', 'portfolio-page backup-page');
?>
        <div class="page-top">
            <div>
                <h1>Backups</h1>
                <p class="lead">Copies of the inventory, lists, pull history, settings and portfolio totals.</p>
            </div>
            <form method="post" class="portfolio-actions">
                <input type="hidden" name="do" value="backup">
                <button type="submit" class="primary" onclick="this.disabled=true;this.textContent='Backing up…';this.form.submit();">Back up now</button>
            </form>
        </div>

        <?php if ($flash): ?><div class="notice <?= $flash[0] === 'ok' ? 'success' : 'error' ?>" role="status"><?= h($flash[1]) ?></div><?php endif; ?>

        <div class="value-tiles">
            <div class="tile <?= $autoOk ? '' : 'warn-tile' ?>">
                <span class="tile-label">Last automatic backup</span>
                <strong class="tile-value small"><?= $lastAuto ? h(date('M j, g:i a', strtotime($lastAuto))) : 'Not yet' ?></strong>
                <span class="tile-sub"><?= $lastAuto ? h($ago(strtotime($lastAuto))) . ' · ' : '' ?>runs every night, and before every import</span>
                <?php if (!$autoOk): ?><span class="tile-warn">The nightly backup hasn't run in the last day and a half. See "Nightly backups" below.</span><?php endif; ?>
            </div>
            <div class="tile">
                <span class="tile-label">Saved backups</span>
                <strong class="tile-value small"><?= count($backups) ?></strong>
                <span class="tile-sub"><?= human_bytes(array_sum(array_column($backups, 'bytes'))) ?> in total</span>
            </div>
            <div class="tile">
                <span class="tile-label">In the inventory now</span>
                <strong class="tile-value small"><?= number_format(array_sum($counts)) ?> rows</strong>
                <span class="tile-sub"><?= number_format($counts['pokemon'] ?? 0) ?> Pokémon · <?= number_format($counts['mtg'] ?? 0) ?> Magic</span>
            </div>
        </div>

        <p class="note method">Saved in <code><?= h(BACKUP_DIR) ?></code> on this computer. Kept: one a day for 14 days, then one a week for 2 months, then one a month for a year,
            plus the last 30 manual and 20 before-import backups. Older ones are deleted automatically. Card pictures and prices aren't included; they download again by themselves.</p>

        <section class="history">
            <h2>Saved backups</h2>
            <?php if (!$backups): ?>
                <p class="note">No backups yet. Click <strong>Back up now</strong> to make the first one.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Made</th><th>Type</th><th class="num">Size</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?= h(date('D, M j, Y g:i a', $b['time'])) ?> <span class="note">· <?= h($ago($b['time'])) ?></span></td>
                        <td><?= h(BACKUP_KINDS[$b['kind']] ?? $b['kind']) ?></td>
                        <td class="num"><?= h(human_bytes($b['bytes'])) ?></td>
                        <td class="row-actions">
                            <a class="link" href="backup.php?action=download&amp;f=<?= h(urlencode($b['file'])) ?>">Download</a>
                            <button type="button" class="link danger restore-btn" data-file="<?= h($b['file']) ?>" data-when="<?= h(date('M j, Y g:i a', $b['time'])) ?>">Restore…</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="history">
            <h2>Restore from a file</h2>
            <p class="note">For a backup you downloaded or copied somewhere else (a .sql.gz file from this page).</p>
            <form method="post" enctype="multipart/form-data" class="upload-restore" id="uploadForm">
                <input type="hidden" name="do" value="restore_upload">
                <input type="hidden" name="confirm" value="">
                <input type="file" name="backup" accept=".gz" required>
                <button type="submit" class="link danger">Restore this file…</button>
            </form>
        </section>

        <section class="history">
            <h2>Nightly backups</h2>
            <ol class="steps">
                <li>Nothing to set up: while TCG Database is running (its icon is in the corner of the taskbar, by the clock), it makes a backup once a day by itself.</li>
                <li>Leave the computer on with TCG Database running. Turn on <strong>Start when Windows starts</strong> from that icon so it comes back after a restart.</li>
                <li>If a day is missed, a backup is made automatically the next time staff open any page.</li>
            </ol>
            <p class="note">Tip: every so often, download the newest backup to a USB drive or another computer. A backup that only lives on this computer won't help if the computer itself is lost.</p>
        </section>

        <dialog id="restoreDlg">
            <form method="post" class="dlg">
                <h2>Restore this backup?</h2>
                <p>This replaces <strong>everything</strong> in the inventory, lists and history with the backup from <strong id="rWhen"></strong>. Changes made since then will be gone.</p>
                <p class="note">A backup of the data as it is right now is made first, so you can undo this by restoring that one.</p>
                <input type="hidden" name="do" value="restore">
                <input type="hidden" name="file" id="rFile">
                <label>Type RESTORE to confirm <input type="text" name="confirm" autocomplete="off" required pattern="RESTORE"></label>
                <div class="dlg-actions">
                    <span class="spacer"></span>
                    <button type="button" onclick="this.closest('dialog').close()">Cancel</button>
                    <button type="submit" class="primary danger-bg">Restore</button>
                </div>
            </form>
        </dialog>
<script>
const dlg = document.getElementById('restoreDlg');
document.querySelectorAll('.restore-btn').forEach(b => b.addEventListener('click', () => {
  document.getElementById('rFile').value = b.dataset.file;
  document.getElementById('rWhen').textContent = b.dataset.when;
  dlg.querySelector('[name=confirm]').value = '';
  dlg.showModal();
}));
document.getElementById('uploadForm').addEventListener('submit', e => {
  const typed = prompt('This replaces everything in the inventory, lists and history with the uploaded backup. A backup of the current data is made first.\n\nType RESTORE to continue.');
  if (typed !== 'RESTORE') { e.preventDefault(); return; }
  e.target.querySelector('[name=confirm]').value = 'RESTORE';
});
</script>
<?php page_foot(); ?>
