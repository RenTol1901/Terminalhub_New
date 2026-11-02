
<?php
session_start();
require_once __DIR__ . '/../db.php';

$adminEmail = 'admin@gmail.com';
$error = '';

// Generate CSRF token
if (empty($_SESSION['admin_login_token'])) {
    $_SESSION['admin_login_token'] = bin2hex(random_bytes(32));
}

// Process admin login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['admin_login_token'], $token)
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $password = $_POST['password'] ?? '';

        try {
            $stmt = $pdo->prepare(
                "SELECT id, full_name, email, password, role
                 FROM users
                 WHERE email = ? AND role = 'admin'
                 LIMIT 1"
            );

            $stmt->execute([$adminEmail]);
            $admin = $stmt->fetch();

            if (
                $admin &&
                password_verify($password, $admin['password'])
            ) {
                // Start a fresh admin session
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $admin['id'];
                $_SESSION['user_name'] = $admin['full_name'];
                $_SESSION['user_email'] = $admin['email'];
                $_SESSION['user_role'] = 'admin';

                // Record admin login activity
                $log = $pdo->prepare(
                    "INSERT INTO login_logs
                     (user_id, full_name, email, role)
                     VALUES (?, ?, ?, 'admin')"
                );

                $log->execute([
                    $admin['id'],
                    $admin['full_name'],
                    $admin['email']
                ]);

                unset($_SESSION['admin_login_token']);

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Incorrect password or admin account has not been set up.';

        } catch (PDOException $e) {
            error_log('Admin login error: ' . $e->getMessage());
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b3d2a">

    <title>Admin Login | TerminalHub</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;600;700;800&family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --forest: #0b3d2a;
            --green: #168653;
            --green-dark: #0b5c39;
            --mint: #e2f3e8;
            --cream: #f7f6ef;
            --white: #ffffff;
            --ink: #14291e;
            --muted: #66756a;
            --line: #e3eae2;
            --display: "Bricolage Grotesque", sans-serif;
            --body: "Figtree", sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: var(--body);
            color: var(--ink);
            background: var(--cream);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .navbar {
            min-height: 82px;
            padding: 15px clamp(20px, 6vw, 88px);
            display: flex;
            align-items: center;
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid var(--line);
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            font-family: var(--display);
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -1.2px;
        }

        .logo-mark {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 15px 15px 15px 5px;
            background: linear-gradient(145deg, #209e65, #0b5c39);
            color: white;
            font-size: 1.35rem;
            box-shadow: 0 6px 16px #16865330;
        }

        .logo b,
        .footer-brand span {
            color: var(--green);
        }

        main {
            flex: 1;
            display: grid;
            place-items: center;
            padding: 55px 20px;
            background:
                radial-gradient(ellipse at 50% 0%, #d3eed9cc, transparent 55%),
                linear-gradient(180deg, #f7f8f0, var(--cream));
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            padding: clamp(26px, 6vw, 42px);
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255,255,255,.94);
            box-shadow: 0 24px 65px #0b3d2a12;
            animation: fadeUp .6s ease both;
        }

        .card-icon {
            width: 58px;
            height: 58px;
            display: grid;
            place-items: center;
            margin-bottom: 25px;
            border-radius: 18px 18px 18px 6px;
            background: var(--mint);
            color: var(--green-dark);
            font-size: 26px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            color: var(--green-dark);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .12em;
        }

        .eyebrow-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
        }

        h1 {
            margin-bottom: 12px;
            font-family: var(--display);
            font-size: clamp(2rem, 7vw, 2.6rem);
            font-weight: 800;
            line-height: 1.08;
            letter-spacing: -.055em;
        }

        .description {
            margin-bottom: 28px;
            color: var(--muted);
            font-size: .94rem;
            line-height: 1.8;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: .86rem;
            font-weight: 700;
        }

        .email-box,
        input[type="password"] {
            width: 100%;
            min-height: 52px;
            padding: 14px 15px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #f8faf7;
            color: var(--ink);
            font-family: var(--body);
            font-size: .94rem;
        }

        .email-box {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 23px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .email-icon {
            color: var(--green);
        }

        input[type="password"] {
            margin-bottom: 20px;
            outline: none;
            background: white;
            transition: border-color .2s, box-shadow .2s;
        }

        input[type="password"]:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px #16865318;
        }

        button {
            width: 100%;
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 14px 20px;
            border: 0;
            border-radius: 12px;
            background: var(--green);
            color: white;
            font-family: var(--body);
            font-size: .96rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 22px #16865325;
            transition: background .2s, transform .2s;
        }

        button:hover {
            background: var(--forest);
            transform: translateY(-2px);
        }

        .error {
            margin-bottom: 20px;
            padding: 13px 14px;
            border: 1px solid #f0c9c3;
            border-radius: 12px;
            background: #fff3f1;
            color: #8b3027;
            font-size: .86rem;
            line-height: 1.6;
        }

        .back-link {
            display: block;
            margin-top: 22px;
            color: var(--green-dark);
            font-size: .88rem;
            font-weight: 700;
            text-align: center;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .security-note {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
            color: var(--muted);
            font-size: .77rem;
            line-height: 1.6;
            text-align: center;
        }

        .footer {
            padding: 24px clamp(20px, 6vw, 88px);
            border-top: 1px solid var(--line);
            background: #ffffffb8;
            text-align: center;
        }

        .footer-brand {
            font-family: var(--display);
            font-weight: 800;
        }

        .footer p {
            margin-top: 5px;
            color: var(--muted);
            font-size: .8rem;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 600px) {
            .navbar {
                min-height: 72px;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .logo {
                font-size: 1.32rem;
            }

            .logo-mark {
                width: 37px;
                height: 37px;
            }

            main {
                padding: 35px 18px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation: none !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>

<header class="navbar">
    <a class="logo" href="../index.php" aria-label="TerminalHub Home">
        <span class="logo-mark">T</span>
        <span>Terminal<b>Hub</b></span>
    </a>
</header>

<main>
    <section class="login-card">
        <div class="card-icon" aria-hidden="true">♙</div>

        <div class="eyebrow">
            <span class="eyebrow-dot"></span>
            ADMINISTRATION PORTAL
        </div>

        <h1>Welcome back.</h1>

        <p class="description">
            Sign in to manage TerminalHub users, bookings, and login activity.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label>Admin Email</label>

            <div class="email-box">
                <span class="email-icon" aria-hidden="true">✉</span>
                <span><?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your admin password"
                autocomplete="current-password"
                required
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['admin_login_token'], ENT_QUOTES, 'UTF-8') ?>"
            >

            <button type="submit">
                Sign In <span aria-hidden="true">→</span>
            </button>
        </form>

        <a class="back-link" href="../login.php">
            ← Back to User Login
        </a>

        <p class="security-note">
            <span aria-hidden="true">✓</span>
            Restricted access for authorized administrators only.
        </p>
    </section>
</main>

<footer class="footer">
    <a class="footer-brand" href="../index.php">
        Terminal<span>Hub</span>
    </a>
    <p>© <?= date('Y') ?> TerminalHub. Explore Palawan with ease.</p>
</footer>

</body>
</html>
