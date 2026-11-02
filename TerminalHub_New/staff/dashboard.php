<?php
session_start();

// STAFF ACCESS PROTECTION
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'staff'
) {
    header('Location: ../login.php');
    exit;
}

// STAFF INFORMATION
$staffName = htmlspecialchars(
    $_SESSION['user_name'] ?? 'Staff',
    ENT_QUOTES,
    'UTF-8'
);

$currentDate = date('l, F d, Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#0b3d2a">

    <title>Staff Dashboard | TerminalHub</title>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --forest: #0b3d2a;
            --green: #168653;
            --cream: #f7f7f1;
            --white: #ffffff;
            --ink: #192c22;
            --muted: #849087;
            --line: #e9eee8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--ink);
            min-height: 100vh;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--forest);
            color: white;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 50px;
            padding: 0 10px;
        }

        .brand-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: rgba(255,255,255,0.13);
            font-size: 22px;
        }

        .brand-name {
            font-family: 'Manrope', sans-serif;
            font-size: 21px;
            font-weight: 800;
        }

        .brand-name span {
            color: #8bd5a8;
        }

        .nav-label {
            color: #91b09c;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin: 0 12px 14px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px;
            margin-bottom: 7px;
            border-radius: 10px;
            color: #c4d7ca;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .nav-link.active,
        .nav-link:hover {
            background: var(--green);
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
        }

        .sidebar-bottom a {
            display: block;
            padding: 13px;
            color: #ffb8b8;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            min-height: 82px;
            padding: 18px 38px;
            background: white;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .topbar strong {
            display: block;
            color: var(--forest);
            font-size: 14px;
        }

        .topbar p {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .role-label {
            padding: 8px 13px;
            border-radius: 20px;
            background: #e6f4eb;
            color: var(--green);
            font-size: 11px;
            font-weight: 800;
        }

        .content {
            max-width: 1400px;
            margin: auto;
            padding: 38px;
        }

        .welcome {
            margin-bottom: 28px;
        }

        .welcome h1 {
            color: var(--forest);
            font-family: 'Manrope', sans-serif;
            font-size: 30px;
            font-weight: 800;
        }

        .welcome p {
            margin-top: 9px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .date-card {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 15px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 9px;
            color: var(--muted);
            font-size: 12px;
        }

        .welcome-card {
            padding: 30px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(11,61,42,0.025);
        }

        .welcome-card h2 {
            color: var(--forest);
            font-family: 'Manrope', sans-serif;
            font-size: 20px;
            margin-bottom: 12px;
        }

        .welcome-card p {
            max-width: 650px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.8;
        }

        .staff-badge {
            display: inline-block;
            margin-top: 20px;
            padding: 9px 14px;
            border-radius: 8px;
            background: #e6f4eb;
            color: var(--green);
            font-size: 11px;
            font-weight: 800;
        }

        footer {
            padding: 22px;
            border-top: 1px solid var(--line);
            color: #9aa59c;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 650px) {
            .layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                padding: 18px;
            }

            .brand {
                margin-bottom: 22px;
            }

            .content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 17px;
            }

            .welcome h1 {
                font-size: 25px;
            }

            .welcome-card {
                padding: 22px;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <div class="brand">
            <div class="brand-icon">🚌</div>

            <div class="brand-name">
                Terminal<span>Hub</span>
            </div>
        </div>

        <div class="nav-label">STAFF MENU</div>

        <a href="dashboard.php" class="nav-link active">
            ▦ Dashboard
        </a>

        <div class="sidebar-bottom">
            <a href="../logout.php">
                ⇥ Log Out
            </a>
        </div>

    </aside>

    <div class="main-content">

        <header class="topbar">

            <div>
                <strong>Staff Portal</strong>
                <p>TerminalHub Operations</p>
            </div>

            <div class="role-label">
                STAFF ACCOUNT
            </div>

        </header>

        <main class="content">

            <section class="welcome">

                <h1>Welcome, <?= $staffName ?>!</h1>

                <p>
                    Welcome to your TerminalHub staff workspace.
                    Manage your assigned terminal responsibilities here.
                </p>

                <div class="date-card">
                    📅 <?= htmlspecialchars($currentDate, ENT_QUOTES, 'UTF-8') ?>
                </div>

            </section>

            <section class="welcome-card">

                <h2>Staff Dashboard</h2>

                <p>
                    You have successfully accessed the TerminalHub Staff Portal.
                    This dashboard is your workspace for terminal operations.
                    Additional staff features will be added here.
                </p>

                <div class="staff-badge">
                    ✓ STAFF ACCESS VERIFIED
                </div>

            </section>

        </main>

        <footer>
            © 2026 TerminalHub. Explore Palawan with ease.
            <br><br>
            Staff Dashboard
        </footer>

    </div>

</div>

</body>
</html>