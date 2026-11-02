<?php
/*
 * check_id_photo.php
 * ------------------------------------------------------------------
 * Checks the quality of ONE ID photo while the passenger is still
 * choosing photos. Nothing is saved and the database is not used.
 * The real submission (verify_id.php / login.php) checks again.
 *
 * Put this file in the project ROOT (same folder as verify_id.php).
 * ------------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/id_helpers.php';

/* Sends the answer to the page as JSON and stops */
function sendJson(int $status, bool $ok, string $message, array $problems = []): void {
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(
        [
            'ok'       => $ok,
            'message'  => $message,
            'problems' => $problems
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* ONLY POST REQUESTS */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendJson(405, false, 'Invalid request method.', ['request']);
}

/* PHOTO BIGGER THAN THE SERVER ALLOWS (PHP then drops everything) */
if (
    empty($_POST) &&
    empty($_FILES) &&
    (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    sendJson(
        413,
        false,
        'This photo is too large. Please use a smaller photo (maximum 5 MB).',
        ['too_large']
    );
}

/* SECURITY TOKEN: the registration page or the ID verification page */
$token = $_POST['csrf_token'] ?? '';

$registerToken = (string) ($_SESSION['csrf_token'] ?? '');
$verifyToken   = (string) ($_SESSION['verify_id_csrf_token'] ?? '');

$allowed = false;

if (is_string($token) && $token !== '') {

    if ($verifyToken !== '' && hash_equals($verifyToken, $token)) {
        /* The ID verification page is only for logged-in passengers */
        $allowed = isset($_SESSION['user_id'])
            && ($_SESSION['user_role'] ?? '') === 'passenger';

    } elseif ($registerToken !== '' && hash_equals($registerToken, $token)) {
        /* The registration page (the person is not logged in yet) */
        $allowed = true;
    }
}

if (!$allowed) {
    sendJson(
        403,
        false,
        'Session expired. Please refresh the page and try again.',
        ['session']
    );
}

/* WHICH SIDE OF THE ID IS BEING CHECKED */
$side = $_POST['side'] ?? 'front';

if ($side === 'front') {
    $label = 'front of your ID';
} elseif ($side === 'back') {
    $label = 'back of your ID';
} else {
    sendJson(400, false, 'Invalid request.', ['request']);
}

/* LIMIT: 40 checks every 10 minutes per visitor */
$now = time();
$windowSeconds = 600;
$maxChecks = 40;

$attempts = $_SESSION['id_check_attempts'] ?? [];

if (!is_array($attempts)) {
    $attempts = [];
}

$attempts = array_values(array_filter(
    $attempts,
    static fn($time) => is_int($time) && $time > $now - $windowSeconds
));

if (count($attempts) >= $maxChecks) {
    session_write_close();

    sendJson(
        429,
        false,
        'You have checked many photos in a short time. Please wait a few minutes and try again.',
        ['rate_limit']
    );
}

$attempts[] = $now;
$_SESSION['id_check_attempts'] = $attempts;

/* Release the session so other pages are not slowed down */
session_write_close();

/* THE PHOTO */
$photo = $_FILES['photo'] ?? null;

if (!isIdFileProvided($photo)) {
    sendJson(400, false, 'Please choose a photo first.', ['no_file']);
}

/* RUN THE QUALITY CHECK (nothing is saved) */
try {
    $result = checkIdPhotoUpload($photo, $label);

    sendJson(
        200,
        (bool) $result['ok'],
        (string) $result['message'],
        (array) $result['problems']
    );

} catch (Throwable $e) {
    error_log('ID photo check error: ' . $e->getMessage());

    sendJson(
        500,
        false,
        'The photo could not be checked right now. Please try again.',
        ['server_error']
    );
}