
<?php
session_start();
require_once __DIR__ . '/../db.php';

$email = 'admin@gmail.com';
$message = '';

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$existingUser = $stmt->fetch();

if ($existingUser) {
    $message = 'May account na gamit ang admin email. Walang binagong account.';
} else {
    $password = 'YourSecurePassword123!';
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, password, role)
         VALUES (?, ?, ?, 'admin')"
    );

    $stmt->execute([
        'TerminalHub Admin',
        $email,
        $hashedPassword
    ]);

    $message = 'Admin account created. Delete setup_admin_once.php now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Setup</title>
</head>
<body>
    <h2><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></h2>
</body>
</html>
