
<?php
session_start();

// ADMIN ACCESS PROTECTION
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// DATABASE CONNECTION
require_once __DIR__ . '/../db.php';

// HELPER: SAFE OUTPUT
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ADMIN NAME
$adminName = e($_SESSION['user_name'] ?? 'Admin');

// DEFAULT STATISTICS
$userCount = 0;
$bookingCount = 0;
$pendingCount = 0;
$confirmedCount = 0;
$loginCount = 0;
$recentBookings = [];

$dashboardError = false;

// DASHBOARD DATA
try {

    // Registered users
    $userCount = (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'user'
    ")->fetchColumn();

    // Total bookings
    $bookingCount = (int) $pdo->query("
        SELECT COUNT(*)
        FROM bookings
    ")->fetchColumn();

    // Pending bookings
    $pendingCount = (int) $pdo->query("
        SELECT COUNT(*)
        FROM bookings
        WHERE status = 'Pending'
    ")->fetchColumn();

    // Confirmed bookings
    $confirmedCount = (int) $pdo->query("
        SELECT COUNT(*)
        FROM bookings
        WHERE status = 'Confirmed'
    ")->fetchColumn();

    // Login records
    $loginCount = (int) $pdo->query("
        SELECT COUNT(*)
        FROM login_logs
    ")->fetchColumn();

    // Recent bookings
    $recentQuery = $pdo->query("
        SELECT
            b.id,
            b.booking_reference,
            b.origin,
            b.destination,
            b.travel_date,
            b.transport_type,
            b.status,
            b.created_at,
            u.full_name AS passenger_name
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.id
        ORDER BY b.created_at DESC
        LIMIT 5
    ");

    $recentBookings = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {

    error_log('Dashboard error: ' . $ex->getMessage());
    $dashboardError = true;
}

// CURRENT DATE
$currentDate = date('l, F d, Y');

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b3d2a">

    <title>Admin Dashboard | TerminalHub</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>

        :root {
            --forest: #0b3d2a;
            --forest-light: #124d36;
            --green: #168653;
            --green-light: #e6f4eb;
            --cream: #f7f7f1;
            --white: #ffffff;
            --ink: #192c22;
            --muted: #849087;
            --line: #e9eee8;

            --orange: #e99a36;
            --red: #dc6262;
            --blue: #648dc9;
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

        a {
            text-decoration: none;
        }

        /* MAIN LAYOUT */

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            width: 255px;
            background: var(--forest);
            color: white;
            padding: 30px 19px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            min-height: 100vh;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 12px;
            margin-bottom: 48px;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            background: rgba(255,255,255,0.13);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .brand-name {
            font-family: 'Manrope', sans-serif;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .brand-name span {
            color: #8bd5a8;
        }

        .nav-label {
            color: #91b09c;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            padding: 0 13px;
            margin-bottom: 13px;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 14px;
            border-radius: 11px;
            color: #c4d7ca;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
        }

        .nav-links a.active {
            background: #168653;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.12);
        }

        .nav-icon {
            width: 21px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 30px;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255,255,255,0.12);
            margin-bottom: 20px;
        }

        .sidebar-bottom a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #c4d7ca;
            padding: 12px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 10px;
            transition: 0.2s;
        }

        .sidebar-bottom a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
        }

        .sidebar-bottom .logout-link {
            color: #ffb8b8;
        }

        /* MAIN CONTENT */

        .main-content {
            flex: 1;
            min-width: 0;
        }

        /* TOP BAR */

        .topbar {
            min-height: 82px;
            padding: 18px 38px;
            background: var(--white);
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .topbar-left p {
            font-size: 12px;
            color: var(--muted);
        }

        .topbar-left strong {
            display: block;
            font-size: 14px;
            color: var(--forest);
            margin-bottom: 4px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .avatar {
            width: 39px;
            height: 39px;
            border-radius: 50%;
            background: var(--green-light);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            color: var(--ink);
        }

        .profile-info span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-top: 3px;
        }

        /* PAGE CONTENT */

        .content {
            padding: 35px 38px 45px;
            max-width: 1500px;
            margin: auto;
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-bottom: 29px;
        }

        .welcome-section h1 {
            font-family: 'Manrope', sans-serif;
            font-size: 30px;
            color: var(--forest);
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 8px;
        }

        .welcome-section p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .date-label {
            font-size: 12px;
            color: var(--muted);
            background: white;
            border: 1px solid var(--line);
            padding: 11px 15px;
            border-radius: 10px;
            white-space: nowrap;
        }

        /* STATISTICS */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            padding: 21px;
            border: 1px solid var(--line);
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(11,61,42,0.025);
            transition: 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 9px 24px rgba(11,61,42,0.07);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .stat-icon {
            width: 39px;
            height: 39px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .icon-green {
            background: #e4f4e9;
        }

        .icon-orange {
            background: #fff1dd;
        }

        .icon-blue {
            background: #e9f0fc;
        }

        .icon-red {
            background: #ffeded;
        }

        .stat-label {
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
        }

        .stat-card h2 {
            font-family: 'Manrope', sans-serif;
            color: var(--forest);
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .stat-description {
            font-size: 10px;
            color: #9aa59c;
            margin-top: 5px;
        }

        /* SECTION HEADINGS */

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 17px;
        }

        .section-heading h2 {
            font-family: 'Manrope', sans-serif;
            font-size: 18px;
            color: var(--forest);
            font-weight: 800;
        }

        .section-heading p {
            font-size: 11px;
            color: var(--muted);
            margin-top: 5px;
        }

        .view-all {
            color: var(--green);
            font-size: 12px;
            font-weight: 700;
        }

        .view-all:hover {
            text-decoration: underline;
        }

        /* RECENT BOOKINGS */

        .table-container {
            background: white;
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 35px;
            box-shadow: 0 5px 20px rgba(11,61,42,0.025);
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        thead {
            background: #f8faf7;
        }

        th {
            padding: 15px 19px;
            text-align: left;
            font-size: 10px;
            color: #829087;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            white-space: nowrap;
        }

        td {
            padding: 16px 19px;
            border-top: 1px solid #f0f3ef;
            font-size: 12px;
            color: #536258;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fbfdfb;
        }

        .booking-ref {
            font-weight: 800;
            color: var(--forest);
        }

        .passenger-name {
            color: var(--ink);
            font-weight: 700;
        }

        .route-name {
            color: var(--ink);
            font-weight: 600;
        }

        .route-arrow {
            color: var(--green);
            padding: 0 4px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pending {
            background: #fff2dc;
            color: #b7791f;
        }

        .status-confirmed {
            background: #e3f5e9;
            color: #16804b;
        }

        .status-rejected {
            background: #ffe9e9;
            color: #c34e4e;
        }

        .status-cancelled {
            background: #edf0f2;
            color: #697780;
        }

        .status-completed {
            background: #e8efff;
            color: #476cba;
        }

        .empty-state {
            text-align: center;
            padding: 45px 20px;
            color: var(--muted);
        }

        .empty-state .empty-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .empty-state strong {
            display: block;
            color: var(--forest);
            font-size: 14px;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 12px;
        }

        /* MANAGEMENT */

        .management-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .management-card {
            display: block;
            padding: 22px;
            border-radius: 15px;
            background: white;
            border: 1px solid var(--line);
            transition: 0.2s ease;
        }

        .management-card:hover {
            border-color: #a7d5b5;
            transform: translateY(-3px);
            box-shadow: 0 9px 24px rgba(11,61,42,0.06);
        }

        .management-icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            background: var(--green-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }

        .management-card h3 {
            color: var(--forest);
            font-size: 13px;
            margin-bottom: 7px;
        }

        .management-card p {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

        .card-arrow {
            display: block;
            color: var(--green);
            font-size: 12px;
            font-weight: 700;
            margin-top: 16px;
        }

        /* FOOTER */

        footer {
            padding: 25px 38px;
            border-top: 1px solid var(--line);
            color: #9aa59c;
            font-size: 11px;
            text-align: center;
        }

        /* ERROR MESSAGE */

        .error-message {
            background: #fff0ed;
            border: 1px solid #f3c5bb;
            color: #a94438;
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 12px;
            margin-bottom: 22px;
        }

        /* RESPONSIVE */

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .management-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 850px) {
            .sidebar {
                width: 215px;
            }

            .topbar {
                padding: 18px 22px;
            }

            .content {
                padding: 28px 22px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                min-height: auto;
                padding: 17px;
            }

            .brand {
                margin-bottom: 20px;
            }

            .nav-label {
                margin-bottom: 9px;
            }

            .nav-links {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            .nav-links a {
                padding: 11px 9px;
                font-size: 11px;
                gap: 7px;
            }

            .nav-icon {
                font-size: 15px;
                width: 17px;
            }

            .sidebar-bottom {
                padding-top: 15px;
            }

            .sidebar-divider {
                margin-bottom: 10px;
            }

            .sidebar-bottom a {
                display: inline-flex;
                padding: 10px;
                margin-right: 8px;
            }

            .topbar {
                min-height: 68px;
                padding: 13px 17px;
            }

            .profile-info {
                display: none;
            }

            .content {
                padding: 25px 16px 35px;
            }

            .welcome-section {
                display: block;
            }

            .welcome-section h1 {
                font-size: 25px;
            }

            .date-label {
                display: inline-block;
                margin-top: 15px;
            }

            .stats-grid {
                gap: 10px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-card h2 {
                font-size: 25px;
            }

            .stat-label {
                font-size: 10px;
            }

            .management-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .management-card {
                padding: 16px;
            }

            .management-card h3 {
                font-size: 12px;
            }

            .management-card p {
                font-size: 10px;
            }

            .section-heading h2 {
                font-size: 16px;
            }

            footer {
                padding: 20px 12px;
            }
        }

    </style>

</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="brand">
            <div class="brand-icon">🚌</div>

            <div class="brand-name">
                Terminal<span>Hub</span>
            </div>
        </div>

        <div class="nav-label">MAIN MENU</div>

        <nav class="nav-links">

            <a href="dashboard.php" class="active">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <a href="users.php">
                <span class="nav-icon">♙</span>
                User Management
            </a>

            <a href="bookings.php">
                <span class="nav-icon">▤</span>
                Booking Management
            </a>

            <a href="activity.php">
                <span class="nav-icon">◷</span>
                Login Activity
            </a>

        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-divider"></div>

            <a href="../index.php">
                <span class="nav-icon">↗</span>
                Visit Website
            </a>

            <a href="../logout.php" class="logout-link">
                <span class="nav-icon">⇥</span>
                Log Out
            </a>

        </div>

    </aside>


    <!-- MAIN CONTENT -->

    <div class="main-content">

        <!-- TOP BAR -->

        <header class="topbar">

            <div class="topbar-left">
                <strong>Administration Portal</strong>
                <p>TerminalHub Management System</p>
            </div>

            <div class="admin-profile">

                <div class="avatar">A</div>

                <div class="profile-info">
                    <strong><?= $adminName ?></strong>
                    <span>Administrator</span>
                </div>

            </div>

        </header>


        <!-- DASHBOARD CONTENT -->

        <main class="content">

            <?php if ($dashboardError): ?>
                <div class="error-message">
                    Some dashboard information could not be loaded.
                    Please check the database connection and try again.
                </div>
            <?php endif; ?>


            <!-- WELCOME -->

            <section class="welcome-section">

                <div>
                    <h1>Dashboard Overview</h1>

                    <p>
                        Welcome back, <?= $adminName ?>!
                        Here's what's happening with your TerminalHub system.
                    </p>
                </div>

                <div class="date-label">
                    📅 <?= e($currentDate) ?>
                </div>

            </section>


            <!-- STATISTICS -->

            <section class="stats-grid">

                <div class="stat-card">

                    <div class="stat-top">
                        <span class="stat-label">REGISTERED USERS</span>
                        <div class="stat-icon icon-green">♙</div>
                    </div>

                    <h2><?= number_format($userCount) ?></h2>
                    <div class="stat-description">Passenger accounts</div>

                </div>


                <div class="stat-card">

                    <div class="stat-top">
                        <span class="stat-label">TOTAL BOOKINGS</span>
                        <div class="stat-icon icon-blue">▤</div>
                    </div>

                    <h2><?= number_format($bookingCount) ?></h2>
                    <div class="stat-description">All recorded bookings</div>

                </div>


                <div class="stat-card">

                    <div class="stat-top">
                        <span class="stat-label">PENDING BOOKINGS</span>
                        <div class="stat-icon icon-orange">◷</div>
                    </div>

                    <h2><?= number_format($pendingCount) ?></h2>
                    <div class="stat-description">Waiting for admin review</div>

                </div>


                <div class="stat-card">

                    <div class="stat-top">
                        <span class="stat-label">CONFIRMED BOOKINGS</span>
                        <div class="stat-icon icon-green">✓</div>
                    </div>

                    <h2><?= number_format($confirmedCount) ?></h2>
                    <div class="stat-description">Approved reservations</div>

                </div>


                <div class="stat-card">

                    <div class="stat-top">
                        <span class="stat-label">LOGIN RECORDS</span>
                        <div class="stat-icon icon-red">↪</div>
                    </div>

                    <h2><?= number_format($loginCount) ?></h2>
                    <div class="stat-description">Recorded login activity</div>

                </div>

            </section>


            <!-- RECENT BOOKINGS -->

            <section>

                <div class="section-heading">

                    <div>
                        <h2>Recent Bookings</h2>
                        <p>Latest passenger reservations in TerminalHub.</p>
                    </div>

                    <a href="bookings.php" class="view-all">
                        View All Bookings →
                    </a>

                </div>


                <div class="table-container">

                    <div class="table-scroll">

                        <table>

                            <thead>
                                <tr>
                                    <th>Booking Reference</th>
                                    <th>Passenger</th>
                                    <th>Route</th>
                                    <th>Travel Date</th>
                                    <th>Transport</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php if (!empty($recentBookings)): ?>

                                <?php foreach ($recentBookings as $booking): ?>

                                    <?php
                                        $status = strtolower(
                                            trim($booking['status'] ?? 'pending')
                                        );

                                        $statusClass = 'status-pending';

                                        if ($status === 'confirmed') {
                                            $statusClass = 'status-confirmed';
                                        } elseif ($status === 'rejected') {
                                            $statusClass = 'status-rejected';
                                        } elseif ($status === 'cancelled') {
                                            $statusClass = 'status-cancelled';
                                        } elseif ($status === 'completed') {
                                            $statusClass = 'status-completed';
                                        }

                                        $travelDate = !empty($booking['travel_date'])
                                            ? date('M d, Y', strtotime($booking['travel_date']))
                                            : 'Not specified';
                                    ?>

                                    <tr>

                                        <td>
                                            <span class="booking-ref">
                                                <?= e($booking['booking_reference'] ?? 'N/A') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="passenger-name">
                                                <?= e($booking['passenger_name'] ?? 'Unknown Passenger') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="route-name">
                                                <?= e($booking['origin'] ?? 'N/A') ?>
                                                <span class="route-arrow">→</span>
                                                <?= e($booking['destination'] ?? 'N/A') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e($travelDate) ?>
                                        </td>

                                        <td>
                                            <?= e(ucfirst($booking['transport_type'] ?? 'N/A')) ?>
                                        </td>

                                        <td>
                                            <span class="status <?= e($statusClass) ?>">
                                                <?= e(ucfirst($status)) ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="6">

                                        <div class="empty-state">

                                            <div class="empty-icon">📋</div>

                                            <strong>No bookings yet</strong>

                                            <p>
                                                Passenger reservations will appear here once submitted.
                                            </p>

                                        </div>

                                    </td>
                                </tr>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>


            <!-- MANAGEMENT -->

            <section>

                <div class="section-heading">

                    <div>
                        <h2>Management</h2>
                        <p>Manage and monitor your TerminalHub system.</p>
                    </div>

                </div>


                <div class="management-grid">

                    <a href="users.php" class="management-card">

                        <div class="management-icon">♙</div>

                        <h3>User Management</h3>

                        <p>
                            View and manage registered passenger accounts.
                        </p>

                        <span class="card-arrow">
                            Manage Users →
                        </span>

                    </a>


                    <a href="bookings.php" class="management-card">

                        <div class="management-icon">🚌</div>

                        <h3>Booking Management</h3>

                        <p>
                            Review, confirm, reject, and monitor passenger bookings.
                        </p>

                        <span class="card-arrow">
                            Manage Bookings →
                        </span>

                    </a>


                    <a href="activity.php" class="management-card">

                        <div class="management-icon">◷</div>

                        <h3>Login Activity</h3>

                        <p>
                            Review recorded account login activities.
                        </p>

                        <span class="card-arrow">
                            View Activity →
                        </span>

                    </a>


                    <a href="../index.php" class="management-card">

                        <div class="management-icon">🌐</div>

                        <h3>Visit Website</h3>

                        <p>
                            Open the public TerminalHub website.
                        </p>

                        <span class="card-arrow">
                            Visit Website →
                        </span>

                    </a>

                </div>

            </section>

        </main>


        <!-- FOOTER -->

        <footer>
            © 2026 TerminalHub. Explore Palawan with ease.
            <br>
            Administrator Dashboard
        </footer>

    </div>

</div>

</body>
</html>
