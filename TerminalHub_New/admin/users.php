
<?php
session_start();
require_once __DIR__ . '/../db.php';

/* ADMIN ACCESS PROTECTION */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];
$adminName = $_SESSION['user_name'] ?? 'Administrator';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* CSRF TOKEN */
if (empty($_SESSION['users_csrf_token'])) {
    $_SESSION['users_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['users_csrf_token'];

$message = $_SESSION['users_message'] ?? '';
$messageType = $_SESSION['users_message_type'] ?? 'success';

unset($_SESSION['users_message'], $_SESSION['users_message_type']);

function setMessage($message, $type = 'success') {
    $_SESSION['users_message'] = $message;
    $_SESSION['users_message_type'] = $type;
}

function redirectUsers() {
    header('Location: users.php');
    exit;
}

/* PROCESS USER MANAGEMENT ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['users_csrf_token'], $token)
    ) {
        setMessage('Invalid request. Please refresh the page.', 'error');
        redirectUsers();
    }

    $action = $_POST['action'] ?? '';

    try {

        /* ADD STAFF ACCOUNT */
        if ($action === 'add_staff') {

            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';

            if ($fullName === '' || $email === '' || $password === '') {
                throw new Exception('Please complete all required fields.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if (strlen($password) < 8) {
                throw new Exception('Password must contain at least 8 characters.');
            }

            if (strlen($fullName) > 150) {
                throw new Exception('Full name is too long.');
            }

            $check = $pdo->prepare(
                "SELECT id FROM users WHERE email = ? LIMIT 1"
            );
            $check->execute([$email]);

            if ($check->fetch()) {
                throw new Exception('This email address is already registered.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            /* SAVE THE ADMIN WHO CREATED THIS STAFF ACCOUNT */
            $stmt = $pdo->prepare(
                "INSERT INTO users
                (full_name, email, password_hash, role, status, created_by)
                VALUES (?, ?, ?, 'staff', 'active', ?)"
            );

            $stmt->execute([
                $fullName,
                $email,
                $passwordHash,
                $adminId
            ]);

            setMessage('Staff account created successfully.');
            redirectUsers();
        }

        /* EDIT USER INFORMATION */
        elseif ($action === 'edit_user') {

            $userId = (int) ($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));

            if ($userId <= 0 || $fullName === '' || $email === '') {
                throw new Exception('Please complete all required fields.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if (strlen($fullName) > 150) {
                throw new Exception('Full name is too long.');
            }

            $checkUser = $pdo->prepare(
                "SELECT id, role FROM users WHERE id = ? LIMIT 1"
            );
            $checkUser->execute([$userId]);
            $existingUser = $checkUser->fetch();

            if (!$existingUser) {
                throw new Exception('User account not found.');
            }

            if ($existingUser['role'] === 'admin') {
                throw new Exception('Admin account details cannot be edited here.');
            }

            $checkEmail = $pdo->prepare(
                "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1"
            );
            $checkEmail->execute([$email, $userId]);

            if ($checkEmail->fetch()) {
                throw new Exception('That email address is already being used.');
            }

            $stmt = $pdo->prepare(
                "UPDATE users
                 SET full_name = ?, email = ?
                 WHERE id = ? AND role != 'admin'"
            );

            $stmt->execute([
                $fullName,
                $email,
                $userId
            ]);

            setMessage('User information updated successfully.');
            redirectUsers();
        }

        /* ACTIVATE / DEACTIVATE USER */
        elseif ($action === 'toggle_status') {

            $userId = (int) ($_POST['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new Exception('Invalid user account.');
            }

            if ($userId === $adminId) {
                throw new Exception('You cannot deactivate your own account.');
            }

            $stmt = $pdo->prepare(
                "SELECT id, role, status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                throw new Exception('User account not found.');
            }

            if ($targetUser['role'] === 'admin') {
                throw new Exception('Admin accounts cannot be deactivated here.');
            }

            $newStatus = $targetUser['status'] === 'active'
                ? 'inactive'
                : 'active';

            $update = $pdo->prepare(
                "UPDATE users
                 SET status = ?
                 WHERE id = ? AND role != 'admin'"
            );

            $update->execute([$newStatus, $userId]);

            setMessage(
                $newStatus === 'active'
                    ? 'User account activated successfully.'
                    : 'User account deactivated successfully.'
            );

            redirectUsers();
        }

        else {
            throw new Exception('Invalid action.');
        }

    } catch (Exception $ex) {
        setMessage($ex->getMessage(), 'error');
        redirectUsers();

    } catch (PDOException $ex) {
        error_log('User Management Error: ' . $ex->getMessage());

        setMessage(
            'Unable to process your request. Please check the information and try again.',
            'error'
        );

        redirectUsers();
    }
}

/* SEARCH AND FILTER */
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

$allowedRoles = ['all', 'admin', 'staff', 'passenger'];
$allowedStatuses = ['all', 'active', 'inactive'];

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

/* USER STATISTICS */
$stats = [
    'total' => 0,
    'admin' => 0,
    'staff' => 0,
    'passenger' => 0,
    'active' => 0,
    'inactive' => 0
];

$statsQuery = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(role = 'admin') AS admin,
        SUM(role = 'staff') AS staff,
        SUM(role = 'passenger') AS passenger,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive
     FROM users"
);

$statsResult = $statsQuery->fetch();

if ($statsResult) {
    foreach ($stats as $key => $value) {
        $stats[$key] = (int) ($statsResult[$key] ?? 0);
    }
}

/* EDIT USER RECORD */
$editUser = null;
$editId = (int) ($_GET['edit'] ?? 0);

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        "SELECT id, full_name, email, role, status, created_at
         FROM users
         WHERE id = ? AND role != 'admin'
         LIMIT 1"
    );

    $editStmt->execute([$editId]);
    $editUser = $editStmt->fetch();
}

/* FETCH USERS WITH CREATOR INFORMATION */
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.status,
        u.created_at,
        u.created_by,
        creator.full_name AS created_by_name
    FROM users u
    LEFT JOIN users creator ON creator.id = u.created_by
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($roleFilter !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC, u.id DESC";

$userStmt = $pdo->prepare($sql);
$userStmt->execute($params);
$users = $userStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b3d2a">

    <title>User Management | TerminalHub Admin</title>

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
            --muted: #718076;
            --line: #e3eae2;
            --red: #bd4037;
            --red-bg: #fff0ed;
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
            background: var(--cream);
            color: var(--ink);
            font-family: var(--body);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button, input, select {
            font: inherit;
        }

        .topbar {
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 14px clamp(18px, 5vw, 65px);
            background: white;
            border-bottom: 1px solid var(--line);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            font-family: var(--display);
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 14px 14px 14px 5px;
            background: linear-gradient(145deg, #209e65, #0b5c39);
            color: white;
        }

        .brand span:last-child b {
            color: var(--green);
        }

        .top-right {
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--muted);
            font-size: .88rem;
        }

        .admin-label {
            padding: 8px 12px;
            border-radius: 30px;
            background: var(--mint);
            color: var(--green-dark);
            font-size: .75rem;
            font-weight: 800;
        }

        .page {
            width: min(1440px, 100%);
            margin: auto;
            padding: 35px clamp(16px, 4vw, 48px) 60px;
        }

        .breadcrumb {
            margin-bottom: 13px;
            color: var(--muted);
            font-size: .82rem;
        }

        .breadcrumb a {
            color: var(--green-dark);
            font-weight: 700;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-bottom: 28px;
        }

        h1, h2, h3 {
            font-family: var(--display);
        }

        h1 {
            font-size: clamp(2rem, 5vw, 2.7rem);
            line-height: 1.1;
            letter-spacing: -1.5px;
        }

        .subtitle {
            margin-top: 9px;
            color: var(--muted);
            line-height: 1.6;
            font-size: .93rem;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 15px;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: white;
            color: var(--forest);
            font-size: .85rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .back-button:hover {
            border-color: var(--green);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            min-height: 115px;
            padding: 19px;
            border: 1px solid var(--line);
            border-radius: 17px;
            background: white;
            box-shadow: 0 5px 20px #0b3d2a06;
        }

        .stat-label {
            color: var(--muted);
            font-size: .82rem;
            font-weight: 700;
        }

        .stat-value {
            margin-top: 9px;
            font-family: var(--display);
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .stat-detail {
            margin-top: 3px;
            color: var(--muted);
            font-size: .75rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
            align-items: start;
            gap: 20px;
        }

        .panel {
            min-width: 0;
            padding: 23px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: white;
            box-shadow: 0 5px 20px #0b3d2a06;
        }

        .panel-heading {
            margin-bottom: 19px;
        }

        .panel-heading h2 {
            font-size: 1.35rem;
            letter-spacing: -.4px;
        }

        .panel-heading p {
            margin-top: 6px;
            color: var(--muted);
            font-size: .83rem;
            line-height: 1.5;
        }

        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: .83rem;
            font-weight: 800;
        }

        .field input,
        .search-input,
        .filter-select {
            width: 100%;
            min-height: 45px;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: 10px;
            outline: none;
            background: #fbfcfa;
            color: var(--ink);
            font-size: .88rem;
            transition: border-color .2s, box-shadow .2s;
        }

        .field input:focus,
        .search-input:focus,
        .filter-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px #16865316;
        }

        .field small {
            display: block;
            margin-top: 6px;
            color: var(--muted);
            font-size: .73rem;
            line-height: 1.5;
        }

        .primary-button,
        .secondary-button,
        .danger-button,
        .status-button {
            min-height: 40px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 7px;
            padding: 10px 14px;
            border: 0;
            border-radius: 10px;
            font-size: .82rem;
            font-weight: 800;
            cursor: pointer;
            transition: .2s;
        }

        .primary-button {
            width: 100%;
            min-height: 47px;
            background: var(--green);
            color: white;
        }

        .primary-button:hover {
            background: var(--forest);
        }

        .secondary-button {
            background: #edf3ed;
            color: var(--forest);
        }

        .secondary-button:hover {
            background: #dfece1;
        }

        .danger-button {
            background: var(--red-bg);
            color: var(--red);
        }

        .danger-button:hover {
            background: #ffe2dc;
        }

        .status-button {
            background: var(--mint);
            color: var(--green-dark);
        }

        .status-button.inactive {
            background: var(--red-bg);
            color: var(--red);
        }

        .cancel-link {
            display: block;
            margin-top: 14px;
            color: var(--muted);
            font-size: .83rem;
            font-weight: 700;
            text-align: center;
        }

        .notice {
            margin-bottom: 20px;
            padding: 13px 16px;
            border: 1px solid #b9dfc6;
            border-radius: 12px;
            background: #effaf2;
            color: #17633a;
            font-size: .86rem;
            line-height: 1.5;
        }

        .notice.error {
            border-color: #efc6c0;
            background: #fff3f1;
            color: #92362e;
        }

        .table-panel {
            padding: 23px;
        }

        .table-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 18px;
        }

        .table-top h2 {
            font-size: 1.35rem;
        }

        .table-top p {
            margin-top: 5px;
            color: var(--muted);
            font-size: .82rem;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(150px, 1fr) 145px 145px auto;
            gap: 9px;
            margin-bottom: 18px;
        }

        .filter-button {
            padding: 10px 15px;
            border: 0;
            border-radius: 10px;
            background: var(--forest);
            color: white;
            font-size: .82rem;
            font-weight: 800;
            cursor: pointer;
        }

        .clear-filter {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            color: var(--green-dark);
            font-size: .8rem;
            font-weight: 800;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            padding: 14px 13px;
            background: #f5f8f3;
            color: #637268;
            font-size: .73rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        td {
            padding: 14px 13px;
            border-top: 1px solid #edf1eb;
            font-size: .82rem;
            vertical-align: middle;
        }

        .user-name {
            font-weight: 800;
        }

        .user-email {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: .76rem;
            overflow-wrap: anywhere;
        }

        .role-badge,
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 9px;
            border-radius: 30px;
            font-size: .7rem;
            font-weight: 800;
            text-transform: capitalize;
        }

        .role-admin {
            background: #eee9ff;
            color: #6044a1;
        }

        .role-staff {
            background: #e5efff;
            color: #315c9c;
        }

        .role-passenger {
            background: #eaf4e9;
            color: #377044;
        }

        .status-active {
            background: var(--mint);
            color: var(--green-dark);
        }

        .status-inactive {
            background: var(--red-bg);
            color: var(--red);
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .actions form {
            display: inline;
        }

        .actions button {
            white-space: nowrap;
        }

        .protected {
            color: var(--muted);
            font-size: .75rem;
            font-weight: 700;
        }

        .empty {
            padding: 35px 15px;
            color: var(--muted);
            text-align: center;
            line-height: 1.6;
        }

        .empty strong {
            display: block;
            margin-bottom: 5px;
            color: var(--ink);
        }

        .footer {
            margin-top: 30px;
            color: var(--muted);
            font-size: .78rem;
            text-align: center;
        }

        @media (max-width: 1050px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 13px 16px;
            }

            .top-right {
                font-size: .75rem;
            }

            .admin-label {
                display: none;
            }

            .page {
                padding-top: 25px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .stat-card {
                min-height: 100px;
                padding: 14px;
            }

            .stat-value {
                font-size: 1.7rem;
            }

            .panel,
            .table-panel {
                padding: 17px;
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .filters .search-input {
                grid-column: 1 / -1;
            }

            .table-top {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <span class="brand-mark">T</span>
        <span>Terminal<b>Hub</b></span>
    </a>

    <div class="top-right">
        <span>Welcome, <?= e($adminName) ?></span>
        <span class="admin-label">ADMIN</span>
    </div>
</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Admin Dashboard</a>
        / User Management
    </div>

    <section class="page-heading">
        <div>
            <h1>User Management</h1>
            <p class="subtitle">
                Manage TerminalHub administrators, staff, and passenger accounts.
            </p>
        </div>

        <a href="dashboard.php" class="back-button">
            ← Back to Dashboard
        </a>
    </section>

    <?php if ($message !== ''): ?>
        <div class="notice <?= $messageType === 'error' ? 'error' : '' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- USER STATISTICS -->
    <section class="stats">

        <div class="stat-card">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-detail">All registered accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Passengers</div>
            <div class="stat-value"><?= $stats['passenger'] ?></div>
            <div class="stat-detail">Passenger accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Staff</div>
            <div class="stat-value"><?= $stats['staff'] ?></div>
            <div class="stat-detail">Terminal staff accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Administrators</div>
            <div class="stat-value"><?= $stats['admin'] ?></div>
            <div class="stat-detail">Protected admin accounts</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Active Accounts</div>
            <div class="stat-value"><?= $stats['active'] ?></div>
            <div class="stat-detail">Accounts currently active</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Inactive Accounts</div>
            <div class="stat-value"><?= $stats['inactive'] ?></div>
            <div class="stat-detail">Deactivated accounts</div>
        </div>

    </section>

    <section class="content-grid">

        <!-- ADD STAFF / EDIT USER -->
        <aside class="panel">

            <div class="panel-heading">
                <h2><?= $editUser ? 'Edit User' : 'Add Staff Account' ?></h2>

                <p>
                    <?php if ($editUser): ?>
                        Update the selected user's name and email address.
                    <?php else: ?>
                        Create a new account for an authorized TerminalHub staff member.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($editUser): ?>

                <form method="POST" action="users.php">

                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">

                    <div class="field">
                        <label for="edit_name">Full Name</label>
                        <input
                            type="text"
                            id="edit_name"
                            name="full_name"
                            maxlength="150"
                            value="<?= e($editUser['full_name']) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="edit_email">Email Address</label>
                        <input
                            type="email"
                            id="edit_email"
                            name="email"
                            value="<?= e($editUser['email']) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Account Role</label>
                        <input type="text" value="<?= e(ucfirst($editUser['role'])) ?>" disabled>
                        <small>Account roles cannot be changed from this form.</small>
                    </div>

                    <button type="submit" class="primary-button">
                        Save Changes
                    </button>

                    <a href="users.php" class="cancel-link">Cancel Editing</a>

                </form>

            <?php else: ?>

                <form method="POST" action="users.php">

                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_staff">

                    <div class="field">
                        <label for="staff_name">Full Name</label>
                        <input
                            type="text"
                            id="staff_name"
                            name="full_name"
                            placeholder="Enter staff full name"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="staff_email">Email Address</label>
                        <input
                            type="email"
                            id="staff_email"
                            name="email"
                            placeholder="staff@example.com"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="staff_password">Temporary Password</label>
                        <input
                            type="password"
                            id="staff_password"
                            name="password"
                            placeholder="At least 8 characters"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <small>
                            Give the staff member their login credentials securely.
                        </small>
                    </div>

                    <button type="submit" class="primary-button">
                        + Create Staff Account
                    </button>

                </form>

            <?php endif; ?>

        </aside>

        <!-- USER TABLE -->
        <section class="panel table-panel">

            <div class="table-top">
                <div>
                    <h2>Registered Users</h2>
                    <p><?= count($users) ?> account(s) found</p>
                </div>
            </div>

            <form method="GET" action="users.php" class="filters">

                <input
                    type="search"
                    name="search"
                    class="search-input"
                    placeholder="Search name or email..."
                    value="<?= e($search) ?>"
                >

                <select name="role" class="filter-select">
                    <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>
                        All Roles
                    </option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>
                        Staff
                    </option>
                    <option value="passenger" <?= $roleFilter === 'passenger' ? 'selected' : '' ?>>
                        Passenger
                    </option>
                </select>

                <select name="status" class="filter-select">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                        All Status
                    </option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                </select>

                <button type="submit" class="filter-button">
                    Search
                </button>

                <a href="users.php" class="clear-filter">
                    Clear Filters
                </a>

            </form>

            <div class="table-wrap">

                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Date Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($users) > 0): ?>

                        <?php foreach ($users as $user): ?>

                            <tr>
                                <td>
                                    <span class="user-name">
                                        <?= e($user['full_name']) ?>
                                    </span>

                                    <span class="user-email">
                                        <?= e($user['email']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="role-badge role-<?= e($user['role']) ?>">
                                        <?= e($user['role']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge status-<?= e($user['status']) ?>">
                                        <?= e($user['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($user['role'] === 'staff'): ?>
                                        <?= $user['created_by_name']
                                            ? e($user['created_by_name'])
                                            : 'Not recorded' ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(date('M d, Y', strtotime($user['created_at']))) ?>
                                </td>

                                <td>

                                    <?php if ($user['role'] === 'admin'): ?>

                                        <span class="protected">Protected</span>

                                    <?php else: ?>

                                        <div class="actions">

                                            <a
                                                href="users.php?edit=<?= (int) $user['id'] ?>"
                                                class="secondary-button"
                                            >
                                                Edit
                                            </a>

                                            <form
                                                method="POST"
                                                action="users.php"
                                                onsubmit="return confirm('Are you sure you want to <?= $user['status'] === 'active' ? 'deactivate' : 'activate' ?> this account?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int) $user['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="status-button <?= $user['status'] === 'inactive' ? 'inactive' : '' ?>"
                                                >
                                                    <?= $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                                </button>

                                            </form>

                                        </div>

                                    <?php endif; ?>

                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <strong>No users found.</strong>
                                    Try another search term or change your filters.
                                </div>
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>
                </table>

            </div>

        </section>

    </section>

    <footer class="footer">
        © <?= date('Y') ?> TerminalHub. Explore Palawan with ease.
    </footer>

</main>

</body>
</html>
