<?php
require __DIR__ . '/auth.php';
require_staff();
require __DIR__ . '/partials.php';

$pdo = app_db();
$msg = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hash = (string)meta_get($pdo, 'staff_password_hash');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), $hash)) { usleep(800000); $error = "The current password isn't right."; }
    elseif (strlen($new) < 6) $error = 'Use at least 6 characters for the new password.';
    elseif ($new !== (string)($_POST['confirm'] ?? '')) $error = "The two new passwords don't match.";
    else { staff_set_password($pdo, $new); $msg = 'Staff password changed. Use the new one next time you sign in.'; }
}

page_head('Staff password', '', 'auth-page');
?>
        <h1>Change staff password</h1>
        <p class="lead">Everyone on staff shares this password.</p>
        <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="notice success" role="status"><?= h($msg) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
            <label>New password <input type="password" name="new" required minlength="6" autocomplete="new-password"></label>
            <label>New password again <input type="password" name="confirm" required minlength="6" autocomplete="new-password"></label>
            <button type="submit" class="primary">Change password</button>
        </form>
        <p class="note">Forgot it? In phpMyAdmin, open the <code>app_meta</code> table and delete the row named <code>staff_password_hash</code>. The next visit to any staff page will ask you to create a new password.</p>
<?php page_foot(); ?>
