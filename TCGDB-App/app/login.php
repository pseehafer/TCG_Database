<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/partials.php';

$pdo = app_db();
$firstRun = !staff_password_set($pdo);
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? '');
$error = null;
// Creating the first password is only allowed on the computer running the site, so a customer can't set it
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

if (isset($_GET['signout'])) {
    staff_sign_out();
    header('Location: login.php');
    exit;
}
if (!$firstRun && staff_signed_in() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    if ($firstRun && !$isLocal) {
        $error = 'Set up the staff password on the store computer that runs the site first.';
    } elseif ($firstRun) {
        if (strlen($pw) < 6) $error = 'Use at least 6 characters.';
        elseif ($pw !== (string)($_POST['confirm'] ?? '')) $error = "The two passwords don't match.";
        else {
            staff_set_password($pdo, $pw);
            staff_sign_in($pdo, $pw);
            header('Location: ' . $next);
            exit;
        }
    } elseif (staff_sign_in($pdo, $pw)) {
        header('Location: ' . $next);
        exit;
    } else {
        $error = "That password isn't right.";
    }
}

page_head('Staff sign in', '', 'auth-page', ['pages.css'], false);
?>
        <h1><?= $firstRun ? 'Create the staff password' : 'Staff sign in' ?></h1>
        <p class="lead"><?= $firstRun
            ? 'Owners and employees will use this password to open Inventory, Import, List Builder and Card Catalog. Customers use the Customer Lookup page, which needs no password.'
            : 'Inventory, Import, List Builder and Card Catalog are for store staff.' ?></p>

        <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <?php if ($firstRun && !$isLocal): ?>
        <div class="notice warn">The staff password hasn't been created yet. Open this page on the store computer that runs the site to create it.</div>
        <?php else: ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <label>Password
                <input type="password" name="password" required autofocus autocomplete="<?= $firstRun ? 'new-password' : 'current-password' ?>" <?= $firstRun ? 'minlength="6"' : '' ?>>
            </label>
            <?php if ($firstRun): ?>
            <label>Type it again
                <input type="password" name="confirm" required minlength="6" autocomplete="new-password">
            </label>
            <?php endif; ?>
            <button type="submit" class="primary"><?= $firstRun ? 'Save password and sign in' : 'Sign in' ?></button>
        </form>
        <?php endif; ?>
        <p class="note">Looking for cards? Use the <a href="kiosk.php">Customer Lookup</a>.</p>
<?php page_foot(); ?>
