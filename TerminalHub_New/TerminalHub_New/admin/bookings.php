
<?php
session_start();

/* ADMIN ACCESS PROTECTION */
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

/* ESCAPE OUTPUT */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* CSRF TOKEN */
if (empty($_SESSION['booking_csrf_token'])) {
    $_SESSION['booking_csrf_token'] = bin2hex(random_bytes(32));
}

$allowedStatuses = [
    'Pending',
    'Confirmed',
    'Rejected',
    'Cancelled',
    'Completed'
];

$error = '';
$success = '';

/* HANDLE BOOKING STATUS UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    $adminReason = trim($_POST['admin_reason'] ?? '');
    $adminMessage = trim($_POST['admin_message'] ?? '');

    $allowedTransitions = [
        'Pending' => ['Confirmed', 'Rejected'],
        'Confirmed' => ['Cancelled', 'Completed']
    ];

    try {
        if (
            !is_string($token) ||
            !hash_equals($_SESSION['booking_csrf_token'], $token)
        ) {
            throw new RuntimeException('Invalid request. Please refresh the page.');
        }

        if (!$bookingId || !in_array($newStatus, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid booking request.');
        }

        /* REASON IS REQUIRED FOR REJECTION AND CANCELLATION */
        if (in_array($newStatus, ['Rejected', 'Cancelled'], true)) {
            if ($adminReason === '') {
                throw new RuntimeException('Please provide a reason for this action.');
            }

            if (mb_strlen($adminReason) > 1000) {
                throw new RuntimeException('The reason must not exceed 1,000 characters.');
            }
        }

        if (mb_strlen($adminMessage) > 1000) {
            throw new RuntimeException('The confirmation message must not exceed 1,000 characters.');
        }

        $pdo->beginTransaction();

        /* GET BOOKING DETAILS AND LOCK THE RECORD */
        $stmt = $pdo->prepare(
            "SELECT
                id,
                user_id,
                booking_reference,
                origin,
                destination,
                travel_date,
                status
             FROM bookings
             WHERE id = :id
             FOR UPDATE"
        );

        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException('Booking not found.');
        }

        $currentStatus = $booking['status'];

        if (
            !isset($allowedTransitions[$currentStatus]) ||
            !in_array($newStatus, $allowedTransitions[$currentStatus], true)
        ) {
            throw new RuntimeException('This status change is not allowed.');
        }

        /* UPDATE BOOKING STATUS */
        $update = $pdo->prepare(
            "UPDATE bookings
             SET status = :status
             WHERE id = :id"
        );

        $update->execute([
            'status' => $newStatus,
            'id' => $bookingId
        ]);

        /* PREPARE NOTIFICATION DETAILS */
        $reference = $booking['booking_reference']
            ?: 'Booking #' . $booking['id'];

        $origin = $booking['origin'];
        $destination = $booking['destination'];

        $travelDate = date(
            'F d, Y',
            strtotime($booking['travel_date'])
        );

        $routeDetails = "{$origin} to {$destination} on {$travelDate}";

        $notificationTitle = '';
        $notificationMessage = '';

        switch ($newStatus) {
            case 'Confirmed':
                $notificationTitle = 'Booking Confirmed!';

                $notificationMessage =
                    "Good news! Your booking {$reference} for {$routeDetails} has been confirmed.";

                if ($adminMessage !== '') {
                    $notificationMessage .= "\n\nMessage from Admin: " . $adminMessage;
                }

                break;

            case 'Rejected':
                $notificationTitle = 'Booking Request Rejected';

                $notificationMessage =
                    "Your booking {$reference} for {$routeDetails} has been rejected.";

                $notificationMessage .=
                    "\n\nReason: " . $adminReason;

                break;

            case 'Cancelled':
                $notificationTitle = 'Booking Cancelled';

                $notificationMessage =
                    "Your booking {$reference} for {$routeDetails} has been cancelled.";

                $notificationMessage .=
                    "\n\nReason: " . $adminReason;

                break;

            case 'Completed':
                $notificationTitle = 'Trip Completed!';

                $notificationMessage =
                    "Your trip for booking {$reference} from {$origin} to {$destination} has been marked as completed.";

                break;
        }

        /* SAVE PASSENGER NOTIFICATION */
        $notificationStmt = $pdo->prepare(
            "INSERT INTO notifications
                (user_id, booking_id, title, message)
             VALUES
                (:user_id, :booking_id, :title, :message)"
        );

        $notificationStmt->execute([
            'user_id' => $booking['user_id'],
            'booking_id' => $booking['id'],
            'title' => $notificationTitle,
            'message' => $notificationMessage
        ]);

        /* SAVE BOTH CHANGES TOGETHER */
        $pdo->commit();

        $_SESSION['booking_message'] =
            "Booking successfully updated to {$newStatus}. Passenger notification has been created.";

        header('Location: bookings.php');
        exit;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Unable to update the booking. Please check your database.';
    }
}

/* SUCCESS MESSAGE */
if (isset($_SESSION['booking_message'])) {
    $success = $_SESSION['booking_message'];
    unset($_SESSION['booking_message']);
}

/* SEARCH AND FILTER */
$search = trim($_GET['search'] ?? '');
$filter = $_GET['status'] ?? 'All';

if ($filter !== 'All' && !in_array($filter, $allowedStatuses, true)) {
    $filter = 'All';
}

/* BOOKING COUNTS */
$countStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'Confirmed') AS confirmed,
        SUM(status = 'Rejected') AS rejected,
        SUM(status = 'Cancelled') AS cancelled,
        SUM(status = 'Completed') AS completed
     FROM bookings"
);

$counts = $countStmt->fetch(PDO::FETCH_ASSOC);

/* GET BOOKINGS */
$sql = "
    SELECT
        b.id,
        b.user_id,
        b.booking_reference,
        b.direction,
        b.origin,
        b.destination,
        b.transport_type,
        b.travel_date,
        b.passengers,
        b.fare_per_passenger,
        b.total_fare,
        b.contact,
        b.passenger_notes,
        b.status,
        b.created_at,
        u.full_name AS passenger_name,
        u.email AS passenger_email
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE 1 = 1
";

$params = [];

if ($filter !== 'All') {
    $sql .= " AND b.status = :status";
    $params['status'] = $filter;
}

if ($search !== '') {
    $sql .= "
        AND (
            b.booking_reference LIKE :search
            OR b.origin LIKE :search
            OR b.destination LIKE :search
            OR b.contact LIKE :search
            OR u.full_name LIKE :search
            OR u.email LIKE :search
        )
    ";

    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* STATUS CSS CLASS */
function statusClass($status) {
    return match ($status) {
        'Pending' => 'pending',
        'Confirmed' => 'confirmed',
        'Rejected' => 'rejected',
        'Cancelled' => 'cancelled',
        'Completed' => 'completed',
        default => 'pending'
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Booking Management | TerminalHub</title>

<style>
:root {
    --forest: #0b3d2a;
    --green: #168653;
    --cream: #f7f6ef;
    --white: #fff;
    --ink: #14291e;
    --muted: #66756a;
    --line: #e3eae2;
    --mint: #e2f3e8;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: var(--cream);
    color: var(--ink);
}

header {
    background: white;
    padding: 20px 7%;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.logo {
    color: var(--forest);
    font-size: 24px;
    font-weight: 800;
}

.logo span {
    color: var(--green);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-actions a {
    color: var(--forest);
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
}

main {
    max-width: 1250px;
    margin: 40px auto;
    padding: 0 22px 50px;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.page-heading h1 {
    color: var(--forest);
    font-size: clamp(27px, 4vw, 36px);
    margin-bottom: 8px;
}

.page-heading p {
    color: var(--muted);
    line-height: 1.5;
}

.back-link {
    background: var(--forest);
    color: white;
    padding: 12px 18px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}

.stat-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
}

.stat-card p {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 10px;
}

.stat-card h2 {
    color: var(--forest);
    font-size: 29px;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 20px;
}

.filters form {
    display: flex;
    gap: 10px;
    flex: 1;
    flex-wrap: wrap;
}

input, select, textarea {
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: white;
    color: var(--ink);
    font-size: 14px;
    outline: none;
    font-family: Arial, sans-serif;
}

input {
    flex: 1;
    min-width: 180px;
}

input:focus, select:focus, textarea:focus {
    border-color: var(--green);
}

.search-btn {
    background: var(--forest);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 12px 18px;
    cursor: pointer;
    font-weight: 700;
}

.alert {
    padding: 14px 17px;
    border-radius: 10px;
    margin-bottom: 18px;
    font-size: 14px;
    line-height: 1.5;
}

.alert.success {
    background: #e2f3e8;
    color: #0b633b;
}

.alert.error {
    background: #fde8e7;
    color: #a32c27;
}

.booking-list {
    display: grid;
    gap: 18px;
}

.booking-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 23px;
    box-shadow: 0 7px 22px rgba(11, 61, 42, 0.035);
}

.booking-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-bottom: 17px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 18px;
}

.booking-ref {
    color: var(--forest);
    font-size: 17px;
    font-weight: 800;
}

.status {
    padding: 7px 12px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 700;
}

.status.pending {
    background: #fff3d8;
    color: #986000;
}

.status.confirmed {
    background: #e2f3e8;
    color: #087344;
}

.status.rejected {
    background: #fde8e7;
    color: #a32c27;
}

.status.cancelled {
    background: #eeeeee;
    color: #555;
}

.status.completed {
    background: #e4edff;
    color: #2856a1;
}

.booking-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
}

.detail-label {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 6px;
}

.detail-value {
    color: var(--ink);
    font-size: 14px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.route {
    color: var(--forest);
    font-size: 17px;
    font-weight: 800;
    line-height: 1.5;
}

.notes {
    background: #f5f8f3;
    border: 1px solid var(--line);
    padding: 15px;
    border-radius: 12px;
    margin-top: 18px;
}

.notes p {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 7px;
    font-weight: 700;
}

.notes div {
    font-size: 14px;
    line-height: 1.6;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.booking-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    border-top: 1px solid var(--line);
    padding-top: 17px;
    margin-top: 18px;
}

.action-form {
    display: flex;
    flex-direction: column;
    gap: 9px;
    width: 100%;
    max-width: 430px;
}

.action-form textarea {
    width: 100%;
    min-height: 75px;
    resize: vertical;
}

.action-form small {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
}

.action-btn {
    border: none;
    border-radius: 9px;
    padding: 11px 16px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    width: fit-content;
}

.confirm-btn {
    background: #168653;
    color: white;
}

.reject-btn {
    background: #fde8e7;
    color: #a32c27;
}

.cancel-btn {
    background: #eeeeee;
    color: #555;
}

.complete-btn {
    background: #e4edff;
    color: #2856a1;
}

.empty {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 45px 20px;
    text-align: center;
    color: var(--muted);
}

.empty h3 {
    color: var(--forest);
    margin-bottom: 8px;
}

@media (max-width: 900px) {
    .stats {
        grid-template-columns: repeat(3, 1fr);
    }

    .booking-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    header {
        padding: 17px 5%;
    }

    main {
        margin-top: 28px;
        padding: 0 15px 35px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .booking-grid {
        grid-template-columns: 1fr;
    }

    .booking-card {
        padding: 17px;
    }

    .filters form {
        width: 100%;
    }

    .filters select {
        width: 100%;
    }

    .action-form {
        max-width: 100%;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo">Terminal<span>Hub</span></div>

    <div class="header-actions">
        <a href="dashboard.php">Dashboard</a>
        <a href="../logout.php">Log out</a>
    </div>
</header>

<main>

    <section class="page-heading">
        <div>
            <h1>Booking Management</h1>
            <p>Review passenger bookings and manage their reservation status.</p>
        </div>

        <a href="dashboard.php" class="back-link">← Admin Dashboard</a>
    </section>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <p>Total Bookings</p>
            <h2><?= (int)($counts['total'] ?? 0) ?></h2>
        </div>

        <div class="stat-card">
            <p>Pending</p>
            <h2><?= (int)($counts['pending'] ?? 0) ?></h2>
        </div>

        <div class="stat-card">
            <p>Confirmed</p>
            <h2><?= (int)($counts['confirmed'] ?? 0) ?></h2>
        </div>

        <div class="stat-card">
            <p>Rejected</p>
            <h2><?= (int)($counts['rejected'] ?? 0) ?></h2>
        </div>

        <div class="stat-card">
            <p>Completed</p>
            <h2><?= (int)($counts['completed'] ?? 0) ?></h2>
        </div>
    </section>

    <section class="filters">
        <form method="GET" action="bookings.php">
            <input
                type="search"
                name="search"
                placeholder="Search passenger, reference, or route..."
                value="<?= e($search) ?>"
            >

            <select name="status">
                <option value="All" <?= $filter === 'All' ? 'selected' : '' ?>>All Bookings</option>

                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filter === $status ? 'selected' : '' ?>>
                        <?= e($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="search-btn">Search</button>
        </form>
    </section>

    <section class="booking-list">

        <?php if (empty($bookings)): ?>

            <div class="empty">
                <h3>No bookings found</h3>
                <p>There are no bookings matching your search or selected filter.</p>
            </div>

        <?php else: ?>

            <?php foreach ($bookings as $booking): ?>

                <article class="booking-card">

                    <div class="booking-top">
                        <div class="booking-ref">
                            <?= e($booking['booking_reference'] ?: 'Booking #' . $booking['id']) ?>
                        </div>

                        <span class="status <?= e(statusClass($booking['status'])) ?>">
                            <?= e($booking['status']) ?>
                        </span>
                    </div>

                    <div class="booking-grid">

                        <div>
                            <p class="detail-label">Passenger</p>
                            <p class="detail-value">
                                <?= e($booking['passenger_name'] ?: 'Unknown passenger') ?>
                            </p>
                        </div>

                        <div>
                            <p class="detail-label">Email</p>
                            <p class="detail-value">
                                <?= e($booking['passenger_email'] ?: 'Not available') ?>
                            </p>
                        </div>

                        <div>
                            <p class="detail-label">Contact</p>
                            <p class="detail-value"><?= e($booking['contact']) ?></p>
                        </div>

                        <div>
                            <p class="detail-label">Route</p>
                            <p class="route">
                                <?= e($booking['origin']) ?>
                                → <?= e($booking['destination']) ?>
                            </p>
                            <p class="detail-label"><?= e($booking['direction']) ?></p>
                        </div>

                        <div>
                            <p class="detail-label">Transport</p>
                            <p class="detail-value"><?= e($booking['transport_type']) ?></p>
                        </div>

                        <div>
                            <p class="detail-label">Travel Date</p>
                            <p class="detail-value">
                                <?= e(date('F d, Y', strtotime($booking['travel_date']))) ?>
                            </p>
                        </div>

                        <div>
                            <p class="detail-label">Passengers</p>
                            <p class="detail-value"><?= (int)$booking['passengers'] ?></p>
                        </div>

                        <div>
                            <p class="detail-label">Fare per Passenger</p>
                            <p class="detail-value">
                                <?= $booking['fare_per_passenger'] !== null
                                    ? '₱' . number_format((float)$booking['fare_per_passenger'], 2)
                                    : 'To be confirmed' ?>
                            </p>
                        </div>

                        <div>
                            <p class="detail-label">Total Fare</p>
                            <p class="detail-value">
                                <?= $booking['total_fare'] !== null
                                    ? '₱' . number_format((float)$booking['total_fare'], 2)
                                    : 'To be confirmed' ?>
                            </p>
                        </div>

                        <div>
                            <p class="detail-label">Date Submitted</p>
                            <p class="detail-value">
                                <?= e(date('M d, Y h:i A', strtotime($booking['created_at']))) ?>
                            </p>
                        </div>

                    </div>

                    <?php if (!empty($booking['passenger_notes'])): ?>
                        <div class="notes">
                            <p>PASSENGER NOTES</p>
                            <div><?= e($booking['passenger_notes']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($booking['status'] === 'Pending'): ?>

                        <div class="booking-actions">

                            <!-- CONFIRM BOOKING -->
                            <form method="POST" class="action-form"
                                  onsubmit="return confirm('Confirm this booking? A notification will be sent to the passenger.');">

                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['booking_csrf_token']) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                <input type="hidden" name="new_status" value="Confirmed">

                                <label class="detail-label" for="message-<?= (int)$booking['id'] ?>">
                                    Optional message to passenger
                                </label>

                                <textarea
                                    id="message-<?= (int)$booking['id'] ?>"
                                    name="admin_message"
                                    maxlength="1000"
                                    placeholder="Example: Please arrive at the terminal 30 minutes before departure."
                                ></textarea>

                                <button class="action-btn confirm-btn" type="submit">
                                    Confirm Booking
                                </button>
                            </form>

                            <!-- REJECT BOOKING -->
                            <form method="POST" class="action-form"
                                  onsubmit="return confirm('Reject this booking? The reason will be sent to the passenger.');">

                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['booking_csrf_token']) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                <input type="hidden" name="new_status" value="Rejected">

                                <label class="detail-label" for="reject-<?= (int)$booking['id'] ?>">
                                    Reason for rejection (required)
                                </label>

                                <textarea
                                    id="reject-<?= (int)$booking['id'] ?>"
                                    name="admin_reason"
                                    maxlength="1000"
                                    required
                                    placeholder="Enter the reason for rejecting this booking..."
                                ></textarea>

                                <button class="action-btn reject-btn" type="submit">
                                    Reject Booking
                                </button>
                            </form>

                        </div>

                    <?php elseif ($booking['status'] === 'Confirmed'): ?>

                        <div class="booking-actions">

                            <!-- COMPLETE TRIP -->
                            <form method="POST"
                                  onsubmit="return confirm('Mark this trip as completed? A notification will be sent to the passenger.');">

                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['booking_csrf_token']) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                <input type="hidden" name="new_status" value="Completed">

                                <button class="action-btn complete-btn" type="submit">
                                    Mark as Completed
                                </button>
                            </form>

                            <!-- CANCEL BOOKING -->
                            <form method="POST" class="action-form"
                                  onsubmit="return confirm('Cancel this booking? The reason will be sent to the passenger.');">

                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['booking_csrf_token']) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                <input type="hidden" name="new_status" value="Cancelled">

                                <label class="detail-label" for="cancel-<?= (int)$booking['id'] ?>">
                                    Reason for cancellation (required)
                                </label>

                                <textarea
                                    id="cancel-<?= (int)$booking['id'] ?>"
                                    name="admin_reason"
                                    maxlength="1000"
                                    required
                                    placeholder="Enter the reason for cancelling this booking..."
                                ></textarea>

                                <button class="action-btn cancel-btn" type="submit">
                                    Cancel Booking
                                </button>
                            </form>

                        </div>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
