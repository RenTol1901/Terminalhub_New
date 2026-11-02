<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/id_helpers.php';

/* MUST BE LOGGED IN */
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';

$submissionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$side = $_GET['side'] ?? 'front';

/* Release the session so other pages are not slowed down */
session_write_close();

if (!in_array($role, ['admin', 'staff', 'passenger'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

if (!$submissionId || $submissionId < 1 || !in_array($side, ['front', 'back'], true)) {
    http_response_code(404);
    exit('Not found');
}

try {
    $stmt = $pdo->prepare(
        "SELECT user_id, front_image, back_image
         FROM passenger_ids
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([$submissionId]);
    $row = $stmt->fetch();

    /* A passenger may only open their OWN ID photos */
    if (
        !$row ||
        ($role === 'passenger' && (int) $row['user_id'] !== $userId)
    ) {
        http_response_code(404);
        exit('Not found');
    }

    $filename = $side === 'front' ? $row['front_image'] : $row['back_image'];

    if (!outputIdImage($filename)) {
        http_response_code(404);
        exit('Not found');
    }

} catch (PDOException $e) {
    error_log('ID image error: ' . $e->getMessage());
    http_response_code(500);
    exit('Error');
}