<?php
/**
 * License page: shows the software license agreement in LICENSE.md (the file in this folder is the agreement;
 * edit it there and this page shows the change). Staff only, with a Print button.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/partials.php';
require_staff();

/** Just enough Markdown for the agreement: # headings, paragraphs, - lists, **bold**, ---. */
function render_agreement(string $md): string {
    $inline = fn(string $s) => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', h($s));
    $html = ''; $para = []; $list = [];
    $flush = function () use (&$html, &$para, &$list, $inline) {
        if ($para) { $html .= '<p>' . implode('<br>', array_map($inline, $para)) . "</p>\n"; $para = []; }
        if ($list) { $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . $inline($l) . '</li>', $list)) . "</ul>\n"; $list = []; }
    };
    foreach (preg_split('/\r?\n/', $md) as $line) {
        $t = trim($line);
        if ($t === '') { $flush(); continue; }
        if ($t === '---') { $flush(); $html .= "<hr>\n"; continue; }
        if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) { $flush(); $n = strlen($m[1]) + 1; $html .= "<h$n>" . $inline($m[2]) . "</h$n>\n"; continue; }
        if (preg_match('/^-\s+(.*)$/', $t, $m)) { if ($para) $flush(); $list[] = $m[1]; continue; }
        if ($list) $flush();
        $para[] = $t;
    }
    $flush();
    return $html;
}

$file = __DIR__ . '/LICENSE.md';
$md = is_file($file) ? (string)file_get_contents($file) : '';

page_head('License', 'license', 'portfolio-page license-page');
?>
        <div class="page-top">
            <div>
                <h1>License</h1>
                <p class="lead">The agreement under which this store uses TCG Database.</p>
            </div>
            <div class="portfolio-actions">
                <button type="button" onclick="window.print()">Print</button>
            </div>
        </div>
        <?php if ($md === ''): ?>
            <div class="notice error">The license file (LICENSE.md) is missing from the <code>app</code> folder.</div>
        <?php else: ?>
            <?php if (str_contains($md, '[STORE NAME]')): ?>
                <div class="notice warn">The store's name hasn't been filled in yet. Open <code>LICENSE.md</code> in the <code>app</code> folder (inside the TCG Database folder) with Notepad and replace both <code>[STORE NAME]</code> with the store's name.</div>
            <?php endif; ?>
            <article class="agreement"><?= render_agreement($md) ?></article>
        <?php endif; ?>
<?php page_foot(); ?>
