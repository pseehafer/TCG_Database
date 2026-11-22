<?php
// h() lives in db.php

/** $staffNav: show the staff menu (Inventory, Import...). Off for the sign-in page. */
function page_head(string $title, string $active, string $mainClass = '', array $css = ['pages.css'], bool $staffNav = true): void {
    $nav = ['inventory' => ['Inventory', 'inventory.php'], 'import' => ['Import', 'import.php'], 'list-builder' => ['List Builder', 'list-builder.php'],
            'portfolio' => ['Portfolio', 'portfolio.php'], 'catalog' => ['Card Catalog', 'catalog_sync.php'], 'backup' => ['Backups', 'backup.php'], 'kiosk' => ['Customer Lookup', 'kiosk.php']];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | TCG Database</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="homePage.css">
<?php foreach ($css as $c): ?>
    <link rel="stylesheet" href="<?= h($c) ?>">
<?php endforeach; ?>
</head>
<body>
<div id="container">
    <header id="header">
        <a class="brand" href="<?= $staffNav ? 'homePage.html' : 'kiosk.php' ?>">TCG Database</a>
        <?php if ($staffNav): ?>
        <nav id="sub-header" aria-label="Main">
            <?php foreach ($nav as $key => [$label, $href]): ?>
                <a href="<?= $href ?>" id="<?= $key ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
            <a href="login.php?signout=1" class="signout">Sign out</a>
        </nav>
        <?php endif; ?>
    </header>
    <main id="content" class="<?= h($mainClass) ?>">
<?php
}

function page_foot(bool $staff = true): void { ?>
    </main>
<?php if ($staff && function_exists('staff_signed_in') && staff_signed_in()): ?>
    <script>
    // Daily backup safety net: if the nightly scheduled backup didn't run, make one now (at most one check an hour)
    try { if (!sessionStorage.getItem('bk') || Date.now() - sessionStorage.getItem('bk') > 3600000) { sessionStorage.setItem('bk', Date.now()); fetch('backup.php?action=auto', { method: 'POST', keepalive: true }).catch(() => {}); } } catch (e) {}
    </script>
<?php endif; ?>
    <footer id="footer"><p>TCG Database &copy; 2026 Parker Seehafer. All rights reserved.<?php if ($staff && function_exists('staff_signed_in') && staff_signed_in()): ?> · <a href="license.php">License</a> · <a href="account.php">Change staff password</a><?php endif; ?></p></footer>
</div>
</body>
</html>
<?php
}
