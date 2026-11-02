<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/id_helpers.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Redirect users according to their account role
function redirectByRole($role) {
    if ($role === 'admin') {
        header("Location: admin/dashboard.php");
    } elseif ($role === 'staff') {
        header("Location: staff/dashboard.php");
    } else {
        header("Location: plan.php");
    }
    exit;
}

// Redirect already logged-in users
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    redirectByRole($_SESSION['user_role']);
}

// CSRF protection (also used by check_id_photo.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mode = (isset($_GET['mode']) && $_GET['mode'] === 'register')
    ? 'register'
    : 'login';

$error = '';

// Values kept in the form after an error (never the password or photos)
$oldName = '';
$oldEmail = '';
$oldIdType = '';

// The two sides of the ID shown in the registration form
$idSides = [
    'front' => ['label' => 'Front of your ID', 'required' => true],
    'back'  => ['label' => 'Back of your ID', 'required' => false]
];

// Detect photos that are bigger than the server allows
$postTooLarge = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty($_POST) &&
    empty($_FILES) &&
    (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
);

if ($postTooLarge) {

    $mode = 'register';
    $error = 'The photos you selected are too large to upload. Please use smaller photos (maximum 5 MB each).';

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode = $_POST['mode'] ?? 'login';

    if (!in_array($mode, ['login', 'register'], true)) {
        $mode = 'login';
    }

    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Session expired. Please refresh the page and try again.';

    } else {

        $email = trim($_POST['email'] ?? '');
        $passwordInput = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';

        } elseif ($passwordInput === '') {
            $error = 'Please enter your password.';

        } elseif ($mode === 'register') {

            $fullName = trim($_POST['full_name'] ?? '');

            $oldName = $fullName;
            $oldEmail = $email;
            $oldIdType = (string) ($_POST['id_type'] ?? '');

            if ($fullName === '') {
                $error = 'Please enter your full name.';

            } elseif (strlen($passwordInput) < 8) {
                $error = 'Password must be at least 8 characters.';

            } else {

                try {
                    $check = $pdo->prepare(
                        "SELECT id FROM users WHERE email = ? LIMIT 1"
                    );
                    $check->execute([$email]);

                    if ($check->fetch()) {
                        $error = 'This email is already registered.';

                    } else {

                        $hashedPassword = password_hash(
                            $passwordInput,
                            PASSWORD_DEFAULT
                        );

                        // Save the account AND the ID together.
                        // If the ID is not valid (for example the photo quality
                        // is too low), the account is not created.
                        $pdo->beginTransaction();

                        try {
                            $insert = $pdo->prepare("
                                INSERT INTO users
                                (full_name, email, password_hash, role, status)
                                VALUES (?, ?, ?, 'passenger', 'active')
                            ");

                            $insert->execute([
                                $fullName,
                                $email,
                                $hashedPassword
                            ]);

                            $newUserId = (int) $pdo->lastInsertId();

                            // Checks the ID photos (including the quality check
                            // on the server) and saves them
                            processIdSubmission(
                                $pdo,
                                $newUserId,
                                $_POST,
                                $_FILES
                            );

                            $pdo->commit();

                        } catch (Throwable $inner) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            throw $inner;
                        }

                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $newUserId;
                        $_SESSION['user_name'] = $fullName;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_role'] = 'passenger';

                        header("Location: plan.php?id_submitted=1");
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log("Registration error: " . $e->getMessage());
                    $error = 'Registration failed. Please try again.';

                } catch (RuntimeException $e) {
                    // Friendly messages from the ID checks (including photo quality)
                    $error = $e->getMessage()
                           . ' Your account was not created yet. Please enter your password and add your photos again.';
                }
            }

        } else {

            try {

                $stmt = $pdo->prepare("
                    SELECT id, full_name, email, password_hash, role, status
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (
                    !$user ||
                    !password_verify($passwordInput, $user['password_hash'])
                ) {
                    $error = 'Incorrect email or password.';

                } elseif (($user['status'] ?? '') !== 'active') {
                    $error = 'Your account is inactive. Please contact the administrator.';

                } elseif (!in_array(
                    $user['role'],
                    ['admin', 'staff', 'passenger'],
                    true
                )) {
                    $error = 'Invalid account role. Please contact the administrator.';

                } else {

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];

                    redirectByRole($user['role']);
                }

            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'Login failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $mode === 'register' ? 'Create Account' : 'Sign In' ?> | TerminalHub</title>

<style>
:root {
    --navy: #0b3d2a;
    --blue: #168653;
    --cyan: #168653;
    --white: #ffffff;
    --muted: #66756a;
    --line: rgba(11, 61, 42, 0.16);

    --forest: #0b3d2a;
    --green: #168653;
    --green-dark: #0b5c39;
    --mint: #e2f3e8;
    --cream: #f7f6ef;
    --ink: #14291e;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--ink);
    background:
        radial-gradient(
            ellipse at 12% 15%,
            rgba(22, 134, 83, 0.12),
            transparent 35%
        ),
        radial-gradient(
            ellipse at 88% 85%,
            rgba(226, 243, 232, 0.7),
            transparent 35%
        ),
        linear-gradient(
            135deg,
            #f7f6ef 0%,
            #ffffff 55%,
            #e2f3e8 100%
        );
    overflow-x: hidden;
}

/* Decorative background */
.background-glow {
    position: fixed;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    border: 1px solid rgba(22, 134, 83, 0.10);
    pointer-events: none;
}

.glow-one {
    top: -240px;
    right: -80px;
    box-shadow:
        0 0 0 55px rgba(22, 134, 83, 0.025),
        0 0 0 110px rgba(22, 134, 83, 0.018);
}

.glow-two {
    bottom: -310px;
    left: -130px;
    box-shadow:
        0 0 0 60px rgba(22, 134, 83, 0.035),
        0 0 0 120px rgba(22, 134, 83, 0.02);
}

/* Top navigation */
.topbar {
    width: 100%;
    min-height: 88px;
    padding: 20px 7%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    border-bottom: 1px solid rgba(11, 61, 42, 0.10);
    position: relative;
    z-index: 2;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: var(--forest);
}

.logo-symbol {
    width: 43px;
    height: 43px;
    display: grid;
    place-items: center;
    border-radius: 13px;
    background: linear-gradient(140deg, #168653, #70c99a);
    color: #ffffff;
    font-size: 22px;
    font-weight: 900;
    box-shadow: 0 8px 24px rgba(22, 134, 83, 0.17);
}

.logo-name {
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.7px;
}

.logo-name span {
    color: var(--green);
}

.top-link {
    text-decoration: none;
    color: var(--forest);
    font-size: 13px;
    font-weight: 600;
    transition: color 0.2s;
}

.top-link:hover {
    color: var(--green);
}

/* Main layout */
.main {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 1200px;
    min-height: calc(100vh - 89px);
    margin: auto;
    padding: 65px 8% 45px;
    display: grid;
    grid-template-columns: 1fr minmax(320px, 410px);
    align-items: center;
    gap: clamp(45px, 9vw, 130px);
}

/* Intro section */
.intro {
    max-width: 510px;
    animation: fadeUp 0.7s ease both;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 28px;
    color: var(--green);
    text-transform: uppercase;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2.7px;
}

.eyebrow::before {
    content: "";
    width: 30px;
    height: 1px;
    background: var(--green);
}

.intro h1 {
    max-width: 500px;
    font-size: clamp(42px, 5vw, 65px);
    line-height: 1.08;
    letter-spacing: -2.8px;
    font-weight: 750;
    color: var(--forest);
}

.intro h1 span {
    display: block;
    color: var(--green);
}

.intro p {
    max-width: 410px;
    margin-top: 24px;
    color: var(--muted);
    font-size: 15px;
    line-height: 1.9;
}

.intro-line {
    width: 65px;
    height: 3px;
    margin-top: 32px;
    border-radius: 10px;
    background: linear-gradient(90deg, var(--forest), #70c99a);
}

/* Form */
.auth {
    width: 100%;
    animation: fadeUp 0.7s 0.12s ease both;
}

.form-heading {
    margin-bottom: 32px;
}

.form-heading .small-label {
    color: var(--green);
    font-size: 10px;
    letter-spacing: 2.5px;
    font-weight: 800;
    text-transform: uppercase;
}

.form-heading h2 {
    margin-top: 12px;
    font-size: 33px;
    font-weight: 750;
    letter-spacing: -1.1px;
    color: var(--forest);
}

.form-heading p {
    margin-top: 10px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 27px;
    margin-bottom: 30px;
    border-bottom: 1px solid var(--line);
}

.tabs a {
    position: relative;
    padding: 0 0 15px;
    color: #7c8a7f;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: color 0.2s;
}

.tabs a:hover,
.tabs a.active {
    color: var(--forest);
}

.tabs a.active::after {
    content: "";
    position: absolute;
    bottom: -1px;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--green);
}

.message {
    margin-bottom: 22px;
    padding: 12px 14px;
    border-radius: 8px;
    font-size: 12px;
    line-height: 1.6;
}

.error {
    color: #a52626;
    background: rgba(255, 85, 85, 0.07);
    border: 1px solid rgba(190, 55, 55, 0.20);
}

.field {
    margin-bottom: 23px;
}

.field label {
    display: block;
    margin-bottom: 10px;
    color: var(--forest);
    font-size: 12px;
    font-weight: 650;
}

.input-wrap {
    position: relative;
}

.input-wrap input {
    width: 100%;
    min-height: 51px;
    padding: 13px 15px;
    border: 1px solid #dce6dc;
    border-radius: 8px;
    outline: none;
    background: rgba(255, 255, 255, 0.78);
    color: var(--ink);
    font-family: inherit;
    font-size: 13px;
    transition:
        border-color 0.2s,
        background 0.2s,
        box-shadow 0.2s;
}

.input-wrap input::placeholder {
    color: #9aa79b;
}

.input-wrap input:focus {
    border-color: var(--green);
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(22, 134, 83, 0.09);
}

.password-input {
    padding-right: 65px !important;
}

.toggle-password {
    position: absolute;
    top: 50%;
    right: 13px;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: var(--green);
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.toggle-password:hover {
    color: var(--green-dark);
}

/* ID type dropdown (same look as the text inputs) */
.input-wrap select {
    width: 100%;
    min-height: 51px;
    padding: 13px 15px;
    border: 1px solid #dce6dc;
    border-radius: 8px;
    outline: none;
    background: rgba(255, 255, 255, 0.78);
    color: var(--ink);
    font-family: inherit;
    font-size: 13px;
    transition:
        border-color 0.2s,
        background 0.2s,
        box-shadow 0.2s;
}

.input-wrap select:focus {
    border-color: var(--green);
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(22, 134, 83, 0.09);
}

/* ID verification box (registration only) */
.id-section {
    margin: 6px 0 24px;
    padding: 20px 18px 4px;
    border: 1px solid #dce6dc;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.6);
}

.id-section-title {
    color: var(--forest);
    font-size: 14px;
    font-weight: 750;
}

.id-section-help {
    margin: 8px 0 14px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.id-tips {
    margin: 0 0 18px;
    padding: 12px 14px;
    border: 1px solid #dcecdf;
    border-radius: 10px;
    background: #f3f8f4;
}

.id-tips strong {
    display: block;
    margin-bottom: 6px;
    color: var(--forest);
    font-size: 12px;
}

.id-tips ul {
    margin-left: 17px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.8;
}

.id-tag {
    display: inline-block;
    margin-left: 6px;
    padding: 2px 8px;
    border-radius: 20px;
    background: #eef3ee;
    color: #66756a;
    font-size: 10px;
    font-weight: 700;
    vertical-align: middle;
}

.id-tag.required {
    background: #e2f3e8;
    color: var(--green-dark);
}

.id-box {
    padding: 14px;
    border: 1px dashed #b9d4c1;
    border-radius: 10px;
    background: #f8fbf7;
    text-align: center;
}

.id-empty {
    padding: 16px 0 4px;
    color: #7c8a7f;
    font-size: 12px;
}

.id-preview img {
    display: block;
    width: 100%;
    max-height: 220px;
    object-fit: contain;
    border: 1px solid #dce6dc;
    border-radius: 8px;
    background: #ffffff;
}

.id-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin-top: 12px;
}

.id-btn {
    padding: 10px 15px;
    border: 1px solid var(--green);
    border-radius: 8px;
    background: var(--green);
    color: #ffffff;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s;
}

.id-btn:hover {
    background: var(--green-dark);
}

.id-btn.light {
    background: #ffffff;
    color: var(--green-dark);
}

.id-btn.light:hover {
    background: var(--mint);
}

.id-btn.remove {
    border-color: #f0c9c3;
    background: #ffffff;
    color: #a52626;
}

.id-btn.remove:hover {
    background: #fff3f1;
}

/* Photo quality result shown under each photo */
.id-status {
    margin-top: 9px;
    padding: 10px 13px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 12px;
    line-height: 1.7;
}

.id-status.checking {
    background: #f1f6f2;
    border-color: #dce6dc;
    color: var(--muted);
}

.id-status.ok {
    background: #e8f6ec;
    border-color: #b8e7c8;
    color: #0b5c39;
    font-weight: 700;
}

.id-status.error {
    background: rgba(255, 85, 85, 0.07);
    border-color: rgba(190, 55, 55, 0.20);
    color: #a52626;
}

.id-status.warn {
    background: #fff8e6;
    border-color: #f0dfa8;
    color: #835800;
}

.id-privacy {
    margin: 0 0 18px;
    color: #718075;
    font-size: 11px;
    line-height: 1.7;
}

.id-preview[hidden],
.id-empty[hidden],
.id-btn[hidden],
.id-status[hidden] {
    display: none !important;
}

/* Button */
.submit-btn {
    width: 100%;
    min-height: 52px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 9px;
    border: none;
    border-radius: 8px;
    color: #ffffff;
    background: linear-gradient(100deg, #168653, #0b5c39);
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 9px 25px rgba(22, 134, 83, 0.15);
    transition:
        transform 0.2s,
        box-shadow 0.2s,
        filter 0.2s;
}

.submit-btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.06);
    box-shadow: 0 12px 28px rgba(22, 134, 83, 0.22);
}

.submit-btn span {
    font-size: 18px;
}

.form-bottom {
    margin-top: 25px;
    color: #718075;
    font-size: 12px;
    line-height: 1.8;
    text-align: center;
}

.form-bottom a {
    color: var(--green);
    text-decoration: none;
    font-weight: 700;
}

.form-bottom a:hover {
    color: var(--green-dark);
    text-decoration: underline;
}

/* Footer */
.footer {
    position: relative;
    z-index: 1;
    padding: 18px 7%;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    border-top: 1px solid rgba(11, 61, 42, 0.10);
    color: #718075;
    font-size: 10px;
}

.footer a {
    color: var(--forest);
    text-decoration: none;
}

.footer a:hover {
    color: var(--green);
}

/* Animation */
@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(16px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Tablet */
@media (max-width: 850px) {
    .main {
        grid-template-columns: 1fr;
        max-width: 530px;
        gap: 48px;
        padding: 50px 28px;
    }

    .intro {
        max-width: 100%;
    }

    .intro h1 {
        font-size: clamp(38px, 8vw, 54px);
    }

    .intro p {
        margin-top: 15px;
    }

    .intro-line {
        margin-top: 22px;
    }

    .form-heading {
        margin-bottom: 25px;
    }
}

/* Mobile */
@media (max-width: 480px) {
    .topbar {
        min-height: 75px;
        padding: 15px 20px;
    }

    .logo-name {
        font-size: 18px;
    }

    .logo-symbol {
        width: 38px;
        height: 38px;
    }

    .main {
        padding: 38px 24px;
        gap: 40px;
    }

    .intro h1 {
        font-size: 39px;
        letter-spacing: -1.8px;
    }

    .form-heading h2 {
        font-size: 29px;
    }

    .id-section {
        padding: 18px 14px 2px;
    }

    .id-actions {
        flex-direction: column;
    }

    .id-btn {
        width: 100%;
        min-height: 46px;
    }

    .input-wrap select {
        font-size: 16px;
    }

    .footer {
        padding: 17px 22px;
    }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
</head>

<body>

<div class="background-glow glow-one"></div>
<div class="background-glow glow-two"></div>

<header class="topbar">
    <a href="index.php" class="logo">
        <div class="logo-symbol">T</div>
        <div class="logo-name">Terminal<span>Hub</span></div>
    </a>

    <a href="index.php" class="top-link">&larr; Back to Home</a>
</header>

<main class="main">

    <section class="intro">
        <div class="eyebrow">Your journey starts here</div>

        <?php if ($mode === 'register'): ?>
            <h1>Discover more.<span>Travel further.</span></h1>

            <p>
                Create your TerminalHub account and get ready to explore
                Palawan. Your next destination is closer than you think.
            </p>
        <?php else: ?>
            <h1>Every journey<span>begins somewhere.</span></h1>

            <p>
                Welcome to TerminalHub, your starting point for exploring
                destinations and transportation options across Palawan.
                Sign in and plan where your next adventure takes you.
            </p>
        <?php endif; ?>

        <div class="intro-line"></div>
    </section>

    <section class="auth">

        <div class="form-heading">
            <div class="small-label">TerminalHub Account</div>

            <h2>
                <?= $mode === 'register' ? 'Create account' : 'Welcome back' ?>
            </h2>

            <p>
                <?= $mode === 'register'
                    ? 'Fill in your details to get started.'
                    : 'Enter your details to access your account.' ?>
            </p>
        </div>

        <nav class="tabs" aria-label="Account options">
            <a href="login.php"
               class="<?= $mode === 'login' ? 'active' : '' ?>">
                Sign In
            </a>

            <a href="login.php?mode=register"
               class="<?= $mode === 'register' ? 'active' : '' ?>">
                Create Account
            </a>
        </nav>

        <?php if ($error !== ''): ?>
            <div class="message error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form
            id="authForm"
            action="login.php<?= $mode === 'register' ? '?mode=register' : '' ?>"
            method="POST"
            <?= $mode === 'register' ? 'enctype="multipart/form-data"' : '' ?>
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="mode"
                value="<?= e($mode) ?>"
            >

            <?php if ($mode === 'register'): ?>
                <div class="field">
                    <label for="full_name">Full name</label>

                    <div class="input-wrap">
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter your full name"
                            maxlength="100"
                            autocomplete="name"
                            value="<?= e($oldName) ?>"
                            required
                        >
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="email">Email address</label>

                <div class="input-wrap">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        maxlength="150"
                        autocomplete="email"
                        value="<?= e($oldEmail) ?>"
                        required
                    >
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>

                <div class="input-wrap">
                    <input
                        class="password-input"
                        type="password"
                        id="password"
                        name="password"
                        placeholder="<?= $mode === 'register'
                            ? 'At least 8 characters'
                            : 'Enter your password' ?>"
                        minlength="<?= $mode === 'register' ? '8' : '1' ?>"
                        autocomplete="<?= $mode === 'register'
                            ? 'new-password'
                            : 'current-password' ?>"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        SHOW
                    </button>
                </div>
            </div>

            <?php if ($mode === 'register'): ?>

                <!-- ID VERIFICATION -->
                <div class="id-section">

                    <div class="id-section-title">Verify your identity</div>

                    <p class="id-section-help">
                        Take a photo of a valid ID or upload one from your phone
                        (JPG, JPEG or PNG, up to 5 MB). Every photo is checked for
                        quality before it is sent to staff.
                    </p>

                    <div class="id-tips">
                        <strong>Tips for a photo that is accepted</strong>
                        <ul>
                            <li>Place the ID on a flat surface in good, even light.</li>
                            <li>Fill most of the photo with the ID and show all four corners.</li>
                            <li>Hold the phone steady and tap the screen to focus.</li>
                            <li>Avoid glare, shadows and the camera flash.</li>
                        </ul>
                    </div>

                    <div class="field">
                        <label for="id_type">ID type</label>

                        <div class="input-wrap">
                            <select id="id_type" name="id_type" required>
                                <option value="">Choose your ID type</option>

                                <?php foreach (getIdTypes() as $idType): ?>
                                    <option
                                        value="<?= e($idType) ?>"
                                        <?= $oldIdType === $idType ? 'selected' : '' ?>
                                    >
                                        <?= e($idType) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php foreach ($idSides as $side => $sideInfo): ?>

                        <div class="field id-picker" data-side="<?= e($side) ?>" data-state="empty">

                            <label>
                                <?= e($sideInfo['label']) ?>
                                <?php if ($sideInfo['required']): ?>
                                    <span class="id-tag required">Required</span>
                                <?php else: ?>
                                    <span class="id-tag">Optional</span>
                                <?php endif; ?>
                            </label>

                            <div class="id-box">

                                <div class="id-preview" hidden>
                                    <img alt="Preview of the <?= e(strtolower($sideInfo['label'])) ?>">
                                </div>

                                <div class="id-empty">No photo selected yet.</div>

                                <div class="id-actions">
                                    <button type="button" class="id-btn" data-action="camera">
                                        Take photo
                                    </button>

                                    <button type="button" class="id-btn light" data-action="upload">
                                        Upload photo
                                    </button>

                                    <button type="button" class="id-btn remove" data-action="remove" hidden>
                                        Remove
                                    </button>
                                </div>

                            </div>

                            <input
                                type="file"
                                name="id_<?= e($side) ?>"
                                id="id_<?= e($side) ?>"
                                accept="image/jpeg,image/png"
                                hidden
                            >

                            <div class="id-status" role="status" aria-live="polite" hidden></div>

                        </div>

                    <?php endforeach; ?>

                    <p class="id-privacy">
                        Your ID photos are stored privately and are only viewed by
                        TerminalHub staff to verify your identity. The photo check
                        only looks at image quality. Staff still verify that your
                        ID is genuine. You can book trips after your ID is accepted.
                    </p>

                </div>

            <?php endif; ?>

            <button type="submit" class="submit-btn">
                <?= $mode === 'register'
                    ? 'Create My Account'
                    : 'Sign In to TerminalHub' ?>

                <span aria-hidden="true">&rarr;</span>
            </button>
        </form>

        <div class="form-bottom">
            <?php if ($mode === 'register'): ?>
                Already have an account?
                <a href="login.php">Sign in</a>
            <?php else: ?>
                New to TerminalHub?
                <a href="login.php?mode=register">Create an account</a>
            <?php endif; ?>
        </div>

    </section>

</main>

<footer class="footer">
    <span>
        &copy; <?= date('Y') ?> TerminalHub. Explore Palawan with confidence.
    </span>

    <a href="index.php">Return to homepage</a>
</footer>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', function () {
    const isPassword = passwordInput.type === 'password';

    passwordInput.type = isPassword ? 'text' : 'password';
    togglePassword.textContent = isPassword ? 'HIDE' : 'SHOW';

    togglePassword.setAttribute(
        'aria-label',
        isPassword ? 'Hide password' : 'Show password'
    );
});

/* ID PHOTO PICKERS (only exist on the registration form) */
const MAX_PHOTO_SIDE = 1600;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const CHECK_URL = 'check_id_photo.php';

/* Problems that mean "the check itself could not run" (not a bad photo) */
const SOFT_PROBLEMS = ['session', 'rate_limit', 'server_error', 'request'];

function isAllowedImage(file) {
    const allowed = ['image/jpeg', 'image/png'];

    if (file.type) {
        return allowed.indexOf(file.type) !== -1;
    }

    return /\.(jpe?g|png)$/i.test(file.name || '');
}

function getCheckToken() {
    const field = document.querySelector('input[name="csrf_token"]');
    return field ? field.value : '';
}

/* Shows a message under a photo: type = checking | ok | error | warn */
function setPickerStatus(picker, type, text) {
    const box = picker.querySelector('.id-status');

    box.className = 'id-status ' + type;
    box.textContent = text;
    box.hidden = false;
}

function hidePickerStatus(picker) {
    const box = picker.querySelector('.id-status');

    box.textContent = '';
    box.hidden = true;
}

function loadImageFromFile(file) {
    return new Promise(function (resolve, reject) {
        const url = URL.createObjectURL(file);
        const img = new Image();

        img.onload = function () {
            URL.revokeObjectURL(url);
            resolve(img);
        };

        img.onerror = function () {
            URL.revokeObjectURL(url);
            reject(new Error('Image could not be read'));
        };

        img.src = url;
    });
}

/* Shrinks a photo so it uploads quickly and stays under the 5 MB limit */
async function compressPhoto(file) {
    const img = await loadImageFromFile(file);

    const scale = Math.min(
        1,
        MAX_PHOTO_SIDE / Math.max(img.naturalWidth, img.naturalHeight)
    );

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(img.naturalWidth * scale);
    canvas.height = Math.round(img.naturalHeight * scale);

    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

    return new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
            if (blob) {
                resolve(blob);
            } else {
                reject(new Error('Photo could not be compressed'));
            }
        }, 'image/jpeg', 0.85);
    });
}

document.querySelectorAll('.id-picker').forEach(function (picker) {

    const side = picker.dataset.side;
    const input = picker.querySelector('input[type="file"]');
    const preview = picker.querySelector('.id-preview');
    const previewImg = preview.querySelector('img');
    const emptyText = picker.querySelector('.id-empty');
    const removeButton = picker.querySelector('[data-action="remove"]');

    let previewUrl = null;
    let runId = 0;   // used to ignore results of an older photo

    function showPreview(file) {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        previewUrl = URL.createObjectURL(file);
        previewImg.src = previewUrl;

        preview.hidden = false;
        emptyText.hidden = true;
        removeButton.hidden = false;
    }

    /* Empties the picker so the passenger can choose another photo */
    function clearPhoto(keepMessage) {
        runId++;

        input.value = '';

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }

        previewImg.removeAttribute('src');
        preview.hidden = true;
        emptyText.hidden = false;
        removeButton.hidden = true;

        picker.dataset.state = 'empty';

        if (!keepMessage) {
            hidePickerStatus(picker);
        }
    }

    picker.querySelectorAll('.id-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.dataset.action;

            if (action === 'camera') {
                /* Opens the phone's back camera (ignored on computers) */
                input.setAttribute('capture', 'environment');
                input.click();

            } else if (action === 'upload') {
                input.removeAttribute('capture');
                input.click();

            } else {
                clearPhoto(false);
            }
        });
    });

    input.addEventListener('change', async function () {

        const original = input.files && input.files[0];

        if (!original) {
            return;
        }

        const myRun = ++runId;

        if (!isAllowedImage(original)) {
            clearPhoto(true);
            setPickerStatus(picker, 'error', 'Please choose a JPG, JPEG or PNG photo.');
            return;
        }

        picker.dataset.state = 'checking';
        setPickerStatus(picker, 'checking', 'Checking photo quality...');

        /* 1. Shrink the photo */
        let finalFile = original;

        try {
            const blob = await compressPhoto(original);

            finalFile = new File(
                [blob],
                'id-' + side + '.jpg',
                { type: 'image/jpeg' }
            );

            const transfer = new DataTransfer();
            transfer.items.add(finalFile);
            input.files = transfer.files;

        } catch (err) {
            /* If shrinking is not supported, the original photo is used */
            finalFile = original;
        }

        if (myRun !== runId) {
            return;
        }

        if (finalFile.size > MAX_PHOTO_BYTES) {
            clearPhoto(true);
            setPickerStatus(
                picker,
                'error',
                'This photo is too large. Please take a new photo or choose a smaller one (maximum 5 MB).'
            );
            return;
        }

        showPreview(finalFile);

        /* 2. Ask the server to check the photo quality */
        try {
            const formData = new FormData();
            formData.append('csrf_token', getCheckToken());
            formData.append('side', side);
            formData.append('photo', finalFile, finalFile.name);

            const response = await fetch(CHECK_URL, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            let result = null;

            try {
                result = await response.json();
            } catch (parseError) {
                result = null;
            }

            if (myRun !== runId) {
                return;
            }

            if (!result || typeof result.ok !== 'boolean') {
                picker.dataset.state = 'unchecked';
                setPickerStatus(
                    picker,
                    'warn',
                    'The photo could not be checked right now. It will be checked again when you submit.'
                );

            } else if (result.ok) {
                picker.dataset.state = 'ok';
                setPickerStatus(picker, 'ok', 'This photo looks good.');

            } else {
                const problems = Array.isArray(result.problems) ? result.problems : [];
                const onlySoft = problems.length > 0 && problems.every(function (p) {
                    return SOFT_PROBLEMS.indexOf(p) !== -1;
                });

                if (onlySoft) {
                    /* The check could not run: keep the photo, server checks on submit */
                    picker.dataset.state = 'unchecked';
                    setPickerStatus(picker, 'warn', result.message || 'The photo could not be checked right now.');

                } else {
                    /* Poor quality: remove it so the passenger can retake it now */
                    clearPhoto(true);
                    setPickerStatus(picker, 'error', result.message);
                }
            }

        } catch (networkError) {
            if (myRun !== runId) {
                return;
            }

            picker.dataset.state = 'unchecked';
            setPickerStatus(
                picker,
                'warn',
                'The photo could not be checked right now. It will be checked again when you submit.'
            );
        }
    });
});

/* FORM SUBMIT: the front photo is required when creating an account */
const authForm = document.getElementById('authForm');
const submitButton = authForm.querySelector('.submit-btn');
const submitLabel = submitButton.firstChild.textContent;

authForm.addEventListener('submit', function (event) {

    if (authForm.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    const pickers = document.querySelectorAll('.id-picker');

    if (pickers.length > 0) {

        /* Wait until every photo has finished its quality check */
        for (const picker of pickers) {
            if (picker.dataset.state === 'checking') {
                event.preventDefault();
                setPickerStatus(picker, 'checking', 'Please wait, this photo is still being checked...');
                return;
            }
        }

        /* The front photo is required */
        const frontPicker = document.querySelector('.id-picker[data-side="front"]');
        const frontInput = frontPicker.querySelector('input[type="file"]');

        if (!frontInput.files || frontInput.files.length === 0) {
            event.preventDefault();

            /* Keep a quality message if there is one; otherwise explain what is missing */
            const statusBox = frontPicker.querySelector('.id-status');

            if (statusBox.hidden || !statusBox.classList.contains('error')) {
                setPickerStatus(frontPicker, 'error', 'Please take or upload a photo of the front of your ID.');
            }

            frontPicker.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        submitButton.firstChild.textContent = 'Uploading, please wait... ';
    }

    /* Prevent double clicks while the photos are uploading */
    authForm.dataset.submitting = '1';
});

/* If the person comes back with the Back button, allow submitting again */
window.addEventListener('pageshow', function () {
    authForm.dataset.submitting = '0';
    submitButton.firstChild.textContent = submitLabel;
});
</script>

</body>
</html>