<?php
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function render_head(string $title, string $active, array $css = []): void { ?>
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
<?php foreach ($css as $c): ?>    <link rel="stylesheet" href="<?= h($c) ?>">
<?php endforeach; ?>
</head>
<body>
<div id="container">
    <header id="header">
        <a class="brand" href="homePage.html">TCG Database</a>
        <nav id="sub-header" aria-label="Main">
<?php foreach (['inventory' => ['Inventory', 'inventory.php'], 'import' => ['Import', 'import.php'], 'list-builder' => ['List Builder', 'list-builder.php']] as $k => [$label, $href]): ?>
            <a href="<?= $href ?>" id="<?= $k ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
        </nav>
    </header>
<?php }

function render_foot(): void { ?>
    <footer id="footer"><p>&copy; 2026 TCG Database. All rights reserved.</p></footer>
</div>
</body>
</html>
<?php }
