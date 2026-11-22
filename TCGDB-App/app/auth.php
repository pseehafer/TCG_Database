<?php
/**
 * Staff sign-in. Owners and employees share one staff password, stored (hashed) in app_meta.
 * Every staff page calls require_staff(). The customer lookup page (kiosk.php) does not.
 */
require_once __DIR__ . '/db.php';

const STAFF_SESSION_HOURS = 12;   // signed-in staff are asked for the password again after this long

function auth_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('tcgdb_staff');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function staff_password_set(PDO $pdo): bool {
    return (string)meta_get($pdo, 'staff_password_hash') !== '';
}

function staff_signed_in(): bool {
    auth_session();
    $at = $_SESSION['staff_at'] ?? 0;
    if ($at && time() - $at > STAFF_SESSION_HOURS * 3600) { $_SESSION = []; return false; }
    return $at > 0;
}

function staff_sign_in(PDO $pdo, string $password): bool {
    $hash = (string)meta_get($pdo, 'staff_password_hash');
    if ($hash === '' || !password_verify($password, $hash)) { usleep(800000); return false; } // slow down guessing
    auth_session();
    session_regenerate_id(true);
    $_SESSION['staff_at'] = time();
    return true;
}

function staff_set_password(PDO $pdo, string $password): void {
    meta_set($pdo, 'staff_password_hash', password_hash($password, PASSWORD_DEFAULT));
}

function staff_sign_out(): void {
    auth_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Stops the request unless staff are signed in. JSON requests get a 401; pages go to the sign-in page. */
function require_staff(bool $json = false): void {
    if (PHP_SAPI === 'cli' || staff_signed_in()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Your staff session ended. Reload the page and sign in again.']);
        exit;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    exit;
}

/** Only allow redirects back into this site after signing in. */
function safe_next(?string $next): string {
    $next = (string)$next;
    if ($next === '' || !preg_match('#^/[^/\\\\]#', $next) || str_contains($next, "\n")) return 'inventory.php';
    return $next;
}
