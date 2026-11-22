<?php
/**
 * Portfolio: what the whole inventory is worth at current TCGplayer prices, Pokémon and Magic separately.
 * For insurance records. "Update values now" walks the inventory in small batches (so it works with 300,000 cards),
 * prices every copy, and saves the result as a dated valuation that can be printed or downloaded as a spreadsheet (CSV).
 */
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/prices.php';
require_staff(isset($_GET['action']));

const PORTFOLIO_GAMES = ['pokemon' => 'Pokémon', 'mtg' => 'Magic'];
const PORTFOLIO_KEEP_ITEMS = 6;      // card-by-card detail is kept for the latest valuations; older ones keep their totals
const PORTFOLIO_PRICE_AGE = 24;      // prices up to a day old are used as they are (the sources update daily)

function portfolio_tables(PDO $pdo): void {
    // The flag alone isn't enough: when a backup is restored on a new computer the flag comes back with the
    // settings, but portfolio_items (not part of a backup) isn't there. So check for the table itself too.
    $hadItems = (bool)table_columns($pdo, 'portfolio_items', true);
    if ($hadItems && meta_get($pdo, 'schema:portfolio') === '1') return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        finished_at TIMESTAMP NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'running',
        state TEXT NULL,
        totals TEXT NULL,
        has_items TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        game VARCHAR(10) NOT NULL,
        name VARCHAR(255) NOT NULL,
        set_name VARCHAR(255) NULL,
        number VARCHAR(255) NULL,
        card_condition VARCHAR(5) NOT NULL,
        finish VARCHAR(10) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NULL,
        total DECIMAL(12,2) NULL,
        printing VARCHAR(40) NULL,
        KEY idx_snap (snapshot_id, game, total)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Valuations that came from a backup keep their totals, but their card-by-card lists weren't in the backup
    if (!$hadItems) $pdo->exec('UPDATE portfolio_snapshots SET has_items = 0 WHERE has_items = 1');
    meta_set($pdo, 'schema:portfolio', '1');
}

function empty_totals(): array {
    $t = [];
    foreach (PORTFOLIO_GAMES as $g => $_) $t[$g] = ['rows' => 0, 'copies' => 0, 'priced_copies' => 0, 'unpriced_copies' => 0, 'unpriced_rows' => 0, 'value' => 0.0];
    return $t;
}

/** Prices the next batch of inventory rows. Returns the updated state. */
function portfolio_step(PDO $pdo, array $snap, float $seconds = 12.0): array {
    $state = json_decode($snap['state'], true);
    $totals = json_decode($snap['totals'], true) ?: empty_totals();
    $deadline = microtime(true) + $seconds;
    $ins = null;
    while (microtime(true) < $deadline && $state['gi'] < count($state['games'])) {
        $game = $state['games'][$state['gi']];
        $t = GAME_TABLES[$game];
        if (!table_columns($pdo, $t)) { $state['gi']++; $state['after'] = 0; continue; }
        $c = table_columns($pdo, $t);
        $name = name_col($c); $qty = qty_col($c); $set = set_col($c); $num = number_col($c);
        $st = $pdo->prepare("SELECT t.id, t.`$name` AS name, " . ($qty ? "t.`$qty`" : '1') . ' AS quantity, ' . ($set ? "t.`$set`" : 'NULL') . ' AS set_name, '
            . ($num ? "t.`$num`" : 'NULL') . " AS num, t.card_condition, t.finish, c.ext_id
            FROM `$t` t LEFT JOIN card_catalog c ON c.game = ? AND c.image_url = t.image_url
            WHERE t.id > ? ORDER BY t.id LIMIT 250");
        $st->execute([$game, (int)$state['after']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $state['gi']++; $state['after'] = 0; continue; }

        // Which printing to price each row as (by its picture, otherwise by name, set and number)
        $cache = [];
        foreach ($rows as &$r) {
            $key = $r['ext_id'] . "\x1F" . $r['name'] . "\x1F" . $r['set_name'] . "\x1F" . $r['num'];
            $cache[$key] ??= priced_ext($pdo, $game, (string)$r['name'], $r['set_name'], $r['num'], $r['ext_id']) ?? false;
            $r['ext_id'] = $cache[$key] ?: null;
        }
        unset($r);

        $data = price_data($pdo, $game, array_column($rows, 'ext_id'), PORTFOLIO_PRICE_AGE);
        $vals = [];
        $T = &$totals[$game];
        foreach ($rows as $r) {
            $q = max(0, (int)$r['quantity']);
            $p = $r['ext_id'] ? price_for_finish($game, $data[$r['ext_id']] ?? null, (string)$r['finish']) : null;
            $unit = $p ? ($p['market'] ?? $p['mid']) : null;
            $line = $unit !== null ? round($unit * $q, 2) : null;
            $T['rows']++; $T['copies'] += $q;
            if ($line !== null) { $T['priced_copies'] += $q; $T['value'] += $line; }
            else { $T['unpriced_copies'] += $q; $T['unpriced_rows']++; }
            array_push($vals, $snap['id'], $game, mb_substr((string)$r['name'], 0, 255), $r['set_name'], $r['num'], (string)$r['card_condition'], (string)$r['finish'],
                       $q, $unit, $line, $p ? mb_substr($p['printing'], 0, 40) : null);
        }
        unset($T);
        $pdo->prepare('INSERT INTO portfolio_items (snapshot_id, game, name, set_name, number, card_condition, finish, quantity, unit_price, total, printing) VALUES '
            . implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?)')))->execute($vals);
        $state['after'] = (int)end($rows)['id'];
        $state['done'][$game] = ($state['done'][$game] ?? 0) + count($rows);
    }
    $finished = $state['gi'] >= count($state['games']);
    foreach ($totals as &$g) $g['value'] = round($g['value'], 2);
    unset($g);
    $pdo->prepare('UPDATE portfolio_snapshots SET state = ?, totals = ?, status = ?, finished_at = ' . ($finished ? 'NOW()' : 'NULL') . ' WHERE id = ?')
        ->execute([json_encode($state), json_encode($totals), $finished ? 'done' : 'running', $snap['id']]);
    if ($finished) {
        // Keep card-by-card detail only for the latest valuations (each one can be hundreds of thousands of rows)
        $old = $pdo->query("SELECT id FROM portfolio_snapshots WHERE status = 'done' AND has_items = 1 ORDER BY id DESC LIMIT 100 OFFSET " . PORTFOLIO_KEEP_ITEMS)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $id) {
            $pdo->prepare('DELETE FROM portfolio_items WHERE snapshot_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE portfolio_snapshots SET has_items = 0 WHERE id = ?')->execute([$id]);
        }
    }
    return ['state' => $state, 'totals' => $totals, 'finished' => $finished];
}

function progress_out(array $state, array $totals): array {
    $done = array_sum($state['done'] ?? []); $all = max(1, array_sum($state['rows']));
    $game = $state['games'][min($state['gi'], count($state['games']) - 1)];
    return ['done' => $done, 'total' => array_sum($state['rows']), 'percent' => min(100, round($done * 100 / $all, 1)),
            'game' => PORTFOLIO_GAMES[$game], 'value' => round(array_sum(array_column($totals, 'value')), 2)];
}

$pdo = app_db();
portfolio_tables($pdo);
$act = $_GET['action'] ?? '';

/* ---------- Running a valuation: start / step / cancel (JSON) ---------- */
if (in_array($act, ['start', 'step', 'cancel'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Use POST.');
        @set_time_limit(120);
        $running = $pdo->query("SELECT * FROM portfolio_snapshots WHERE status = 'running' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($act === 'cancel') {
            if ($running) {
                $pdo->prepare('DELETE FROM portfolio_items WHERE snapshot_id = ?')->execute([$running['id']]);
                $pdo->prepare('DELETE FROM portfolio_snapshots WHERE id = ?')->execute([$running['id']]);
            }
            echo json_encode(['cancelled' => true]);
            exit;
        }
        if ($act === 'start' && !$running) {
            $rows = [];
            foreach (PORTFOLIO_GAMES as $g => $_) $rows[$g] = table_columns($pdo, GAME_TABLES[$g]) ? (int)$pdo->query('SELECT COUNT(*) FROM `' . GAME_TABLES[$g] . '`')->fetchColumn() : 0;
            $state = ['games' => array_keys(PORTFOLIO_GAMES), 'gi' => 0, 'after' => 0, 'rows' => $rows, 'done' => []];
            $pdo->prepare("INSERT INTO portfolio_snapshots (status, state, totals) VALUES ('running', ?, ?)")->execute([json_encode($state), json_encode(empty_totals())]);
            $running = $pdo->query('SELECT * FROM portfolio_snapshots WHERE id = ' . (int)$pdo->lastInsertId())->fetch(PDO::FETCH_ASSOC);
        }
        if (!$running) throw new RuntimeException('No valuation is running.');
        $r = $act === 'step' ? portfolio_step($pdo, $running) : ['state' => json_decode($running['state'], true), 'totals' => json_decode($running['totals'], true), 'finished' => false];
        echo json_encode(['finished' => $r['finished'], 'snapshot' => (int)$running['id']] + progress_out($r['state'], $r['totals']));
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- Spreadsheet download: portfolio.php?action=csv&id=N&game=pokemon|mtg|all ---------- */
if ($act === 'csv') {
    $id = (int)($_GET['id'] ?? 0);
    $snap = $pdo->prepare("SELECT * FROM portfolio_snapshots WHERE id = ? AND status = 'done' AND has_items = 1");
    $snap->execute([$id]);
    $snap = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$snap) { http_response_code(404); exit('That valuation is no longer available.'); }
    $game = isset(PORTFOLIO_GAMES[$_GET['game'] ?? '']) ? $_GET['game'] : 'all';
    $date = date('Y-m-d', strtotime($snap['finished_at']));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-valuation-' . ($game === 'all' ? 'all' : $game) . "-$date.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads é and other accents correctly
    fputcsv($out, ['Game', 'Card', 'Set', 'Number', 'Condition', 'Finish', 'Quantity', 'Unit price (USD)', 'Total (USD)', 'Price for printing', 'Valued on']);
    $st = $pdo->prepare('SELECT * FROM portfolio_items WHERE snapshot_id = ?' . ($game === 'all' ? '' : ' AND game = ?') . ' ORDER BY game DESC, name, set_name, number');
    $st->execute($game === 'all' ? [$id] : [$id, $game]);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [PORTFOLIO_GAMES[$r['game']] ?? $r['game'], $r['name'], $r['set_name'], $r['number'], CONDITIONS[$r['card_condition']] ?? $r['card_condition'],
                       FINISHES[$r['finish']] ?? $r['finish'], $r['quantity'], $r['unit_price'], $r['total'], $r['printing'], $date]);
    }
    exit;
}

/* ---------- Page ---------- */
$snapId = (int)($_GET['id'] ?? 0);
$st = $snapId ? $pdo->prepare("SELECT * FROM portfolio_snapshots WHERE id = ? AND status = 'done'") : $pdo->prepare("SELECT * FROM portfolio_snapshots WHERE status = 'done' ORDER BY id DESC LIMIT 1");
$st->execute($snapId ? [$snapId] : []);
$snap = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$totals = $snap ? json_decode($snap['totals'], true) : null;
$running = $pdo->query("SELECT id, state, totals FROM portfolio_snapshots WHERE status = 'running' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$history = $pdo->query("SELECT id, finished_at, totals, has_items FROM portfolio_snapshots WHERE status = 'done' ORDER BY id DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
$top = [];
if ($snap && $snap['has_items']) {
    foreach (PORTFOLIO_GAMES as $g => $_) {
        $t = $pdo->prepare('SELECT name, set_name, number, card_condition, finish, quantity, unit_price, total FROM portfolio_items WHERE snapshot_id = ? AND game = ? AND total IS NOT NULL ORDER BY total DESC LIMIT 15');
        $t->execute([$snap['id'], $g]);
        $top[$g] = $t->fetchAll(PDO::FETCH_ASSOC);
    }
}
$money = fn($n) => '$' . number_format((float)$n, 2);
$when = fn($ts) => date('F j, Y \a\t g:i a', strtotime($ts));
$isLatest = $snap && $history && (int)$history[0]['id'] === (int)$snap['id'];

page_head('Portfolio', 'portfolio', 'portfolio-page');
?>
        <div class="page-top">
            <div>
                <h1>Portfolio</h1>
                <p class="lead">What the inventory is worth at current TCGplayer market prices, for insurance records.</p>
            </div>
            <div class="portfolio-actions">
                <?php if ($snap): ?><button type="button" onclick="window.print()">Print</button><?php endif; ?>
                <button type="button" id="runBtn" class="primary"><?= $running ? 'Continue valuation' : 'Update values now' ?></button>
            </div>
        </div>

        <section id="runBox" class="run-box" <?= $running ? '' : 'hidden' ?> aria-live="polite">
            <div class="run-top">
                <strong id="runTitle">Valuing the inventory…</strong>
                <button type="button" id="cancelBtn" class="link danger">Cancel</button>
            </div>
            <div class="bar"><span id="bar"></span></div>
            <p class="note" id="runNote">Keep this page open. Prices are looked up a batch at a time; a large inventory takes several minutes the first time.</p>
            <div id="runError" class="notice error" hidden></div>
        </section>

        <?php if (!$snap): ?>
            <div class="empty-state">
                <h2>No valuation yet</h2>
                <p class="note">Click <strong>Update values now</strong> to price every card in the inventory. The result is saved with today's date so you can print it or download it for your insurer.</p>
            </div>
        <?php else: $grand = array_sum(array_column($totals, 'value')); ?>
            <p class="valued-on">
                Valued on <strong><?= h($when($snap['finished_at'])) ?></strong>
                <?php if (!$isLatest): ?> · <span class="tag">Older valuation</span> <a class="link" href="portfolio.php">See the latest</a><?php endif; ?>
            </p>

            <div class="value-tiles">
                <div class="tile total">
                    <span class="tile-label">Total inventory value</span>
                    <strong class="tile-value"><?= $money($grand) ?></strong>
                    <span class="tile-sub"><?= number_format(array_sum(array_column($totals, 'copies'))) ?> cards</span>
                </div>
                <?php foreach (PORTFOLIO_GAMES as $g => $label): $T = $totals[$g]; ?>
                <div class="tile">
                    <span class="tile-label"><?= h($label) ?></span>
                    <strong class="tile-value"><?= $money($T['value']) ?></strong>
                    <span class="tile-sub"><?= number_format($T['copies']) ?> cards · <?= number_format($T['rows']) ?> different printings</span>
                    <?php if ($T['unpriced_copies']): ?>
                        <span class="tile-warn"><?= number_format($T['unpriced_copies']) ?> <?= $T['unpriced_copies'] === 1 ? 'card has' : 'cards have' ?> no price and <?= $T['unpriced_copies'] === 1 ? 'is' : 'are' ?> not counted</span>
                    <?php endif; ?>
                    <?php if ($snap['has_items']): ?><a class="link" href="portfolio.php?action=csv&amp;id=<?= (int)$snap['id'] ?>&amp;game=<?= $g ?>">Download <?= h($label) ?> list</a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($snap['has_items']): ?>
            <p class="downloads">
                <a class="link" href="portfolio.php?action=csv&amp;id=<?= (int)$snap['id'] ?>&amp;game=all">Download full list (all games)</a>
                <span class="note">Spreadsheet (CSV) with every card: set, number, condition, finish, quantity, unit price and total. Opens in Excel.</span>
            </p>
            <?php endif; ?>

            <p class="note method">
                Prices are TCGplayer market prices for near-mint copies (Magic from Scryfall, Pokémon from TCGdex), matched to each card's exact printing and finish.
                Played or damaged copies are counted at the near-mint price. Cards with no TCGplayer price aren't counted in the totals; they're listed in the download with a blank price.
            </p>

            <?php if ($top): ?>
            <div class="top-grid">
                <?php foreach (PORTFOLIO_GAMES as $g => $label): if (empty($top[$g])) continue; ?>
                <section>
                    <h2>Most valuable <?= h($label) ?> cards</h2>
                    <div class="table-wrap">
                    <table class="top-table">
                        <thead><tr><th>Card</th><th>Condition</th><th class="num">Qty</th><th class="num">Each</th><th class="num">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($top[$g] as $r): ?>
                            <tr>
                                <td><strong><?= h($r['name']) ?></strong><span class="sub"><?= h(trim($r['set_name'] . ' ' . $r['number'])) ?><?= $r['finish'] !== 'normal' ? ' · ' . h(FINISHES[$r['finish']] ?? $r['finish']) : '' ?></span></td>
                                <td><?= h($r['card_condition']) ?></td>
                                <td class="num"><?= (int)$r['quantity'] ?></td>
                                <td class="num"><?= $money($r['unit_price']) ?></td>
                                <td class="num"><strong><?= $money($r['total']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (count($history) > 1 || ($history && !$isLatest)): ?>
        <section class="history">
            <h2>Past valuations</h2>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><?php foreach (PORTFOLIO_GAMES as $label): ?><th class="num"><?= h($label) ?></th><?php endforeach; ?><th class="num">Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($history as $hrow): $ht = json_decode($hrow['totals'], true); ?>
                    <tr<?= $snap && (int)$hrow['id'] === (int)$snap['id'] ? ' class="current"' : '' ?>>
                        <td><?= h(date('M j, Y g:i a', strtotime($hrow['finished_at']))) ?></td>
                        <?php foreach (PORTFOLIO_GAMES as $g => $_): ?><td class="num"><?= $money($ht[$g]['value'] ?? 0) ?></td><?php endforeach; ?>
                        <td class="num"><strong><?= $money(array_sum(array_column($ht, 'value'))) ?></strong></td>
                        <td><?php if ($snap && (int)$hrow['id'] === (int)$snap['id']): ?><span class="note">Showing</span><?php else: ?><a class="link" href="portfolio.php?id=<?= (int)$hrow['id'] ?>">View</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="note">Card-by-card lists are kept for the latest <?= PORTFOLIO_KEEP_ITEMS ?> valuations; older ones keep their totals.</p>
        </section>
        <?php endif; ?>

<script>
const $ = id => document.getElementById(id);
let busy = false;
async function call(action) {
  const r = await fetch('portfolio.php?action=' + action, { method: 'POST' });
  const d = await r.json().catch(() => ({ error: 'The server stopped responding. Click Continue valuation to pick up where it left off.' }));
  if (d.error) throw new Error(d.error);
  return d;
}
function show(d) {
  $('bar').style.width = d.percent + '%';
  $('runTitle').textContent = `Valuing the inventory… ${d.percent}% (${d.done.toLocaleString()} of ${d.total.toLocaleString()} printings, now ${d.game})`;
}
async function run() {
  if (busy) return;
  busy = true;
  $('runBox').hidden = false; $('runError').hidden = true; $('runBtn').disabled = true;
  try {
    let d = await call('start');
    show(d);
    while (!d.finished) { d = await call('step'); show(d); }
    busy = false;
    $('runTitle').textContent = 'Done. Loading the new valuation…';
    location.href = 'portfolio.php';
  } catch (e) {
    busy = false;
    $('runError').textContent = e.message; $('runError').hidden = false;
    $('runBtn').disabled = false; $('runBtn').textContent = 'Continue valuation';
  }
}
$('runBtn').addEventListener('click', run);
$('cancelBtn').addEventListener('click', async () => {
  if (!confirm('Stop this valuation? What has been priced so far is thrown away. Saved valuations are not affected.')) return;
  busy = false;
  await call('cancel').catch(() => {});
  location.href = 'portfolio.php';
});
window.addEventListener('beforeunload', e => { if (busy) { e.preventDefault(); e.returnValue = ''; } });
<?php if ($running): ?>
show(<?= json_encode(progress_out(json_decode($running['state'], true), json_decode($running['totals'], true) ?: empty_totals())) ?>);
$('runTitle').textContent += ' — paused. Click Continue valuation.';
<?php endif; ?>
</script>
<?php page_foot(); ?>
