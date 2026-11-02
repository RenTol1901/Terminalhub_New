<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$name = htmlspecialchars($_SESSION['user_name'] ?? 'Traveler', ENT_QUOTES, 'UTF-8');
$userId = (int) $_SESSION['user_id'];

$dbHost = "localhost";
$dbName = "terminalhub_db";
$dbUser = "root";
$dbPass = "";

$message = "";
$pdo = null;

foreach (['notification_csrf_token', 'booking_csrf_token'] as $tokenName) {
    if (empty($_SESSION[$tokenName])) {
        $_SESSION[$tokenName] = bin2hex(random_bytes(32));
    }
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    $message = "Database connection failed. Check your database settings.";
}

$locations = [
    "Puerto Princesa City", "Roxas", "San Vicente", "Taytay",
    "El Nido", "Aborlan", "Narra", "Quezon", "Brooke's Point",
    "Bataraza", "Rio Tuba", "Sofronio Spanola", "Buliluyan", "Rizal"
];

$routeFares = [
    "Puerto Princesa City|Roxas|Van" => 500,
    "Roxas|Puerto Princesa City|Van" => 500,
    "Puerto Princesa City|El Nido|Van" => 700,
    "El Nido|Puerto Princesa City|Van" => 700
];

$northRoutes = ["Roxas", "San Vicente", "Taytay", "El Nido"];
$southRoutes = ["Aborlan", "Narra", "Quezon", "Brooke's Point", "Bataraza", "Rio Tuba"];

$success = isset($_GET["booked"]) && $_GET["booked"] === "1";

function getRouteFare($origin, $destination, $transport, $routeFares) {
    return $routeFares[$origin . "|" . $destination . "|" . $transport] ?? null;
}

/*
 * Returns a normalized notification status:
 * 'pending', 'accepted', 'rejected', or '' (unknown / other).
 */
function getNotificationStatus(array $notification): string {
    $status = strtolower(trim($notification['booking_status'] ?? ''));

    if ($status === '') {
        $text = strtolower(
            ($notification['title'] ?? '') . ' ' . ($notification['message'] ?? '')
        );

        if (strpos($text, 'reject') !== false) {
            $status = 'rejected';
        } elseif (
            strpos($text, 'accept') !== false ||
            strpos($text, 'confirm') !== false
        ) {
            $status = 'accepted';
        } elseif (strpos($text, 'pending') !== false) {
            $status = 'pending';
        }
    }

    if ($status === 'confirmed' || $status === 'accepted') {
        return 'accepted';
    }

    if ($status === 'rejected') {
        return 'rejected';
    }

    if ($status === 'pending') {
        return 'pending';
    }

    return '';
}

/* HANDLE NOTIFICATION ACTIONS */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $pdo) {
    $action = $_POST["action"] ?? "";

    if (in_array($action, [
        "mark_notification_read",
        "delete_notification",
        "clear_all_notifications"
    ], true)) {
        $token = $_POST["notification_csrf_token"] ?? "";

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['notification_csrf_token'], $token)
        ) {
            $message = "Invalid request. Please refresh the page and try again.";
        } else {
            try {
                if ($action === "mark_notification_read") {
                    $notificationId = filter_var(
                        $_POST["notification_id"] ?? null,
                        FILTER_VALIDATE_INT
                    );

                    if ($notificationId && $notificationId > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE notifications
                             SET is_read = 1
                             WHERE id = :id AND user_id = :user_id"
                        );

                        $stmt->execute([
                            ":id" => $notificationId,
                            ":user_id" => $userId
                        ]);
                    }
                }

                if ($action === "delete_notification") {
                    $notificationId = filter_var(
                        $_POST["notification_id"] ?? null,
                        FILTER_VALIDATE_INT
                    );

                    if ($notificationId && $notificationId > 0) {
                        $stmt = $pdo->prepare(
                            "DELETE FROM notifications
                             WHERE id = :id AND user_id = :user_id"
                        );

                        $stmt->execute([
                            ":id" => $notificationId,
                            ":user_id" => $userId
                        ]);
                    }
                }

                if ($action === "clear_all_notifications") {
                    $stmt = $pdo->prepare(
                        "DELETE FROM notifications WHERE user_id = :user_id"
                    );

                    $stmt->execute([":user_id" => $userId]);
                }

                // Redirect without opening the notification panel.
                header("Location: plan.php");
                exit;

            } catch (PDOException $e) {
                $message = "Unable to update notifications. Please try again.";
            }
        }
    }
}

/* SAVE BOOKING */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    !in_array($_POST["action"] ?? "", [
        "mark_notification_read",
        "delete_notification",
        "clear_all_notifications"
    ], true) &&
    $pdo
) {
    $token = $_POST["booking_csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['booking_csrf_token'], $token)
    ) {
        $message = "Invalid or expired request. Please refresh the page and try again.";
    } else {
        $origin = trim($_POST["origin"] ?? "");
        $destination = trim($_POST["destination"] ?? "");
        $transport = $_POST["transport"] ?? "";
        $travelDate = $_POST["travel_date"] ?? "";
        $passengers = (int) ($_POST["passengers"] ?? 1);
        $contact = trim($_POST["contact"] ?? "");
        $passengerNotes = trim($_POST["passenger_notes"] ?? "");

        $dateObject = DateTime::createFromFormat('!Y-m-d', $travelDate);

        $validDate = $dateObject &&
            $dateObject->format('Y-m-d') === $travelDate &&
            $travelDate >= date("Y-m-d");

        if (
            !in_array($origin, $locations, true) ||
            !in_array($destination, $locations, true) ||
            $origin === $destination ||
            !in_array($transport, ["Van", "Bus"], true) ||
            !$validDate ||
            $passengers < 1 || $passengers > 15 ||
            $contact === "" || strlen($contact) > 100 ||
            strlen($passengerNotes) > 2000
        ) {
            $message = "Please check your trip details and try again.";
        } else {
            try {
                $duplicateCheck = $pdo->prepare(
                    "SELECT booking_reference
                     FROM bookings
                     WHERE user_id = :user_id
                     AND origin = :origin
                     AND destination = :destination
                     AND transport_type = :transport
                     AND travel_date = :travel_date
                     AND status IN ('Pending', 'Confirmed')
                     LIMIT 1"
                );

                $duplicateCheck->execute([
                    ":user_id" => $userId,
                    ":origin" => $origin,
                    ":destination" => $destination,
                    ":transport" => $transport,
                    ":travel_date" => $travelDate
                ]);

                if ($duplicateCheck->fetch()) {
                    $message = "You already have a Pending or Confirmed booking for this same trip and travel date. Please check My Bookings before submitting another request.";
                } else {
                    if ($origin === "Puerto Princesa City") {
                        $direction = in_array($destination, $northRoutes, true)
                            ? "Northbound" : "Southbound";
                    } elseif ($destination === "Puerto Princesa City") {
                        $direction = in_array($origin, $northRoutes, true)
                            ? "Southbound" : "Northbound";
                    } else {
                        $direction = "Inter-town";
                    }

                    $farePerPassenger = getRouteFare(
                        $origin,
                        $destination,
                        $transport,
                        $routeFares
                    );

                    $totalFare = $farePerPassenger !== null
                        ? $farePerPassenger * $passengers
                        : null;

                    $reference = "TH" . strtoupper(bin2hex(random_bytes(4)));

                    $stmt = $pdo->prepare(
                        "INSERT INTO bookings
                        (
                            user_id, booking_reference, direction, origin,
                            destination, transport_type, travel_date,
                            passengers, fare_per_passenger, total_fare,
                            contact, passenger_notes, status, created_at
                        )
                        VALUES
                        (
                            :user_id, :reference, :direction, :origin,
                            :destination, :transport, :travel_date,
                            :passengers, :fare_per_passenger, :total_fare,
                            :contact, :passenger_notes, 'Pending', NOW()
                        )"
                    );

                    $stmt->execute([
                        ":user_id" => $userId,
                        ":reference" => $reference,
                        ":direction" => $direction,
                        ":origin" => $origin,
                        ":destination" => $destination,
                        ":transport" => $transport,
                        ":travel_date" => $travelDate,
                        ":passengers" => $passengers,
                        ":fare_per_passenger" => $farePerPassenger,
                        ":total_fare" => $totalFare,
                        ":contact" => $contact,
                        ":passenger_notes" => $passengerNotes
                    ]);

                    $_SESSION['booking_csrf_token'] = bin2hex(random_bytes(32));

                    header("Location: plan.php?booked=1");
                    exit;
                }
            } catch (PDOException $e) {
                $message = "Unable to save your booking request. Please try again.";
            }
        }
    }
}

/*
 * BOOKING FORM VISIBILITY
 * The booking form is hidden by default (the welcome section is shown instead).
 * It only opens when the user clicks "Book a Trip" — or stays open right after a
 * FAILED booking attempt, so the user can read the error and try again.
 * After a successful booking the page redirects, so the form is hidden again.
 */
$isBookingPost = $_SERVER["REQUEST_METHOD"] === "POST" &&
    !in_array($_POST["action"] ?? "", [
        "mark_notification_read",
        "delete_notification",
        "clear_all_notifications"
    ], true);

$showBookingForm = $isBookingPost && $message !== "";

/* BOOKING HISTORY */
$bookings = [];

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT booking_reference, direction, origin, destination,
                    transport_type, travel_date, passengers,
                    fare_per_passenger, total_fare, passenger_notes,
                    status, created_at
             FROM bookings
             WHERE user_id = :user_id
             ORDER BY created_at DESC"
        );

        $stmt->execute([":user_id" => $userId]);
        $bookings = $stmt->fetchAll();

    } catch (PDOException $e) {
        $message = "Unable to load your bookings. Please try again.";
    }
}

/* NOTIFICATIONS WITH RELATED BOOKING STATUS */
$notifications = [];
$unreadCount = 0;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT
                n.id, n.booking_id, n.title, n.message,
                n.is_read, n.created_at,
                b.status AS booking_status
             FROM notifications n
             LEFT JOIN bookings b
                ON n.booking_id = b.id AND b.user_id = n.user_id
             WHERE n.user_id = :user_id
             ORDER BY n.created_at DESC
             LIMIT 100"
        );

        $stmt->execute([":user_id" => $userId]);
        $notifications = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = :user_id AND is_read = 0"
        );

        $stmt->execute([":user_id" => $userId]);
        $unreadCount = (int) $stmt->fetchColumn();

    } catch (PDOException $e) {
        $notifications = [];
        $unreadCount = 0;
    }
}

/*
 * NOTIFICATION FILTER
 * Filtering is now done in the browser (no page reload), so the panel stays open.
 * The value from the URL is only used as the initially selected filter.
 */
$notificationFilter = $_GET['notification_filter'] ?? 'all';

if (!in_array($notificationFilter, ['all', 'pending', 'accepted', 'rejected'], true)) {
    $notificationFilter = 'all';
}

$fareJson = json_encode(
    $routeFares,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$locationsJson = json_encode(
    $locations,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Travel Dashboard | TerminalHub</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;600;700;800&family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --forest:#0b3d2a;
    --green:#168653;
    --dark:#0b5c39;
    --mint:#e2f3e8;
    --cream:#f7f8f4;
    --white:#fff;
    --ink:#14291e;
    --muted:#66756a;
    --line:#e3eae2;
}

*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;scroll-padding-top:20px}
body{background:var(--cream);color:var(--ink);font-family:"Figtree",sans-serif}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
button,a,label{-webkit-tap-highlight-color:transparent}

.layout{min-height:100vh;display:grid;grid-template-columns:245px minmax(0,1fr)}

.sidebar{
    background:var(--forest);
    color:white;
    padding:28px 18px;
    min-height:100vh;
    height:100vh;
    position:sticky;
    top:0;
    display:flex;
    flex-direction:column
}

.brand{display:flex;align-items:center;gap:11px;margin:0 8px 45px}
.brand-mark{
    width:42px;height:42px;border-radius:14px 14px 14px 5px;
    display:grid;place-items:center;background:#65d995;
    color:var(--forest);font-size:25px;font-weight:800;flex-shrink:0
}
.brand-name{font-size:21px;font-weight:800;letter-spacing:-.8px}
.brand-sub{color:#a9c7b4;font-size:10px;letter-spacing:1.5px;margin-top:3px}

.menu-label{color:#91b4a0;font-size:10px;letter-spacing:1.8px;font-weight:800;padding:0 12px;margin-bottom:14px}
.menu{display:grid;gap:7px}
.menu a{
    display:flex;gap:12px;align-items:center;padding:13px;
    border-radius:11px;color:#d0e1d5;font-size:13px;
    font-weight:600;transition:.2s
}
.menu a:hover,.menu a.active{background:#ffffff1c;color:white}
.menu a.active{box-shadow:inset 3px 0 #65d995}

.side-bottom{margin-top:auto}
.tip{margin:25px 2px;padding:17px;border:1px solid #ffffff26;border-radius:15px}
.tip h3{font-size:16px;margin:10px 0 7px}
.tip p{color:#b6d0bf;font-size:12px;line-height:1.6;margin-bottom:12px}
.tip a{color:#8ee8b1;font-size:12px;font-weight:800}

.profile{border-top:1px solid #ffffff24;padding:18px 5px 0;display:flex;align-items:center;gap:10px;min-width:0}
.avatar{
    width:40px;height:40px;border-radius:50%;display:grid;
    place-items:center;background:#d4f2df;color:var(--forest);
    font-weight:800;flex-shrink:0
}
.profile>div:nth-child(2){min-width:0;flex:1}
.profile-name{font-size:12px;font-weight:800;overflow-wrap:anywhere}
.profile-label{color:#a9c7b4;font-size:11px;margin-top:3px}
.logout{
    color:white;margin-left:auto;display:flex;align-items:center;
    justify-content:center;width:42px;height:42px;
    border:1px solid #ffffff20;border-radius:12px
}
.logout:hover{background:#ffffff18;color:#8ee8b1}
.logout svg,.mobile-logout svg,.home-link svg{width:20px;height:20px;display:block;flex-shrink:0}
.mobile-profile-footer{display:none}

main{min-width:0;padding:30px clamp(18px,4vw,52px) 45px}

.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:32px}
.breadcrumb{color:var(--muted);font-size:12px;font-weight:600}
.breadcrumb span{color:var(--green)}
.topbar-actions{display:flex;align-items:center;gap:10px}

.home-link,.notification-link{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:11px 14px;border:1px solid var(--line);border-radius:12px;
    background:white;color:var(--forest);font-size:12px;
    font-weight:800;white-space:nowrap
}

.home-link:hover,.notification-link:hover,
.notification-link[aria-expanded="true"]{
    background:var(--mint);border-color:#b6dec4
}

.notification-wrap{position:relative}
.notification-link{cursor:pointer}
.notification-bell{font-size:16px}

.notification-count{
    min-width:19px;height:19px;padding:0 5px;
    display:inline-flex;align-items:center;justify-content:center;
    border-radius:20px;background:#dc4438;color:white;
    font-size:10px;font-weight:800
}

.notification-panel{
    position:absolute;
    top:calc(100% + 12px);
    right:0;
    width:440px;
    max-width:calc(100vw - 32px);
    max-height:75vh;
    overflow-y:auto;
    background:white;
    border:1px solid var(--line);
    border-radius:17px;
    box-shadow:0 15px 45px #0b3d2a20;
    z-index:1000;
    animation:notificationOpen .2s ease
}

/* IMPORTANT: Keep the notification panel hidden by default. */
.notification-panel[hidden]{
    display:none !important;
}

@keyframes notificationOpen{
    from{opacity:0;transform:translateY(-8px)}
    to{opacity:1;transform:translateY(0)}
}

.notification-panel-head{
    position:sticky;top:0;z-index:2;
    display:flex;justify-content:space-between;align-items:center;
    padding:19px;background:white;border-bottom:1px solid var(--line)
}

.notification-panel-head h3{color:var(--forest);font-family:"Bricolage Grotesque",sans-serif;font-size:19px}
.notification-panel-head p{color:var(--muted);font-size:11px;margin-top:5px}

.notification-close{
    width:32px;height:32px;border:0;border-radius:9px;
    background:var(--cream);color:var(--forest);
    font-size:23px;cursor:pointer
}

.notification-tools{padding:13px 15px;border-bottom:1px solid var(--line);display:grid;gap:12px}
.notification-filters{display:flex;gap:6px;flex-wrap:wrap}

.filter-link{
    padding:7px 10px;border:1px solid var(--line);
    border-radius:20px;font-size:10px;font-weight:800;color:var(--muted);
    background:white;cursor:pointer;font-family:inherit;line-height:1.2
}

.filter-link:hover{background:var(--mint);border-color:#b6dec4}

.filter-link.active{background:var(--forest);color:white;border-color:var(--forest)}

.clear-all-btn{
    border:0;background:#fff0ed;color:#a83225;
    padding:9px 12px;border-radius:9px;
    font-size:11px;font-weight:800;cursor:pointer
}

.notification-list{display:grid}

.notification-item{
    display:flex;align-items:flex-start;gap:10px;
    padding:16px;border-bottom:1px solid var(--line)
}

/* Used by the client-side filter */
.notification-item[hidden]{display:none !important}
.notification-empty[hidden]{display:none !important}

.notification-item.unread{background:#f0f8f1;border-left:4px solid var(--green)}
.notification-item.read{background:white;border-left:4px solid transparent}
.notification-content{min-width:0;flex:1}

.notification-title{
    display:flex;align-items:center;flex-wrap:wrap;gap:7px;
    color:var(--forest);font-family:"Bricolage Grotesque",sans-serif;
    font-size:14px;font-weight:700
}

.new-label{
    padding:4px 7px;border-radius:20px;
    background:#d9f1e1;color:#08713d;
    font-family:"Figtree",sans-serif;font-size:9px;font-weight:800
}

.notification-content p{
    color:var(--muted);font-size:12px;line-height:1.7;
    margin-top:7px;overflow-wrap:anywhere
}

.notification-content small{display:block;color:#89958c;font-size:10px;margin-top:9px}
.notification-actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0}

.read-btn,.delete-btn{
    border-radius:8px;padding:8px 9px;
    font-size:10px;font-weight:800;cursor:pointer;white-space:nowrap
}

.read-btn{border:1px solid #cfe3d3;background:white;color:var(--dark)}
.delete-btn{border:1px solid #f2d2cc;background:white;color:#a83225}
.read-btn:hover{background:var(--mint)}
.delete-btn:hover{background:#fff0ed}
.read-label{color:var(--muted);font-size:10px;font-weight:700}

.notification-empty{
    padding:30px 18px;text-align:center;
    color:var(--muted);font-size:12px;line-height:1.7
}
.notification-empty strong{display:block;color:var(--forest);margin-top:9px;font-size:14px}
.notification-empty p{margin-top:5px}

.eyebrow{color:var(--dark);font-size:10px;font-weight:800;letter-spacing:2px;margin-bottom:12px}

h1,h2,h3{font-family:"Bricolage Grotesque",sans-serif}
h1{font-size:clamp(30px,3vw,43px);line-height:1.1;letter-spacing:-1.5px;overflow-wrap:anywhere}
h1 span{color:var(--green)}
.intro{color:var(--muted);font-size:14px;line-height:1.7;margin-top:10px;max-width:650px}

.hero{
    overflow:hidden;position:relative;
    background:radial-gradient(ellipse at 90% 10%,#1d8255 0%,transparent 40%),linear-gradient(125deg,#0b3d2a,#0b5c39);
    color:white;border-radius:23px;
    padding:clamp(24px,4vw,42px);margin-bottom:28px
}

.hero:after{
    content:"✦";position:absolute;right:7%;top:-50px;
    font-size:230px;color:#ffffff0c;transform:rotate(15deg);
    pointer-events:none
}

.hero .tag{color:#a8edc0;font-size:10px;font-weight:800;letter-spacing:1.5px;margin-bottom:15px;position:relative;z-index:1}
.hero h2{font-size:clamp(27px,3vw,39px);line-height:1.1;max-width:570px;position:relative;z-index:1}
.hero h2 span{color:#7de3a3}
.hero p{color:#c4d8cb;font-size:13px;line-height:1.8;margin-top:13px;max-width:540px;position:relative;z-index:1}

.section-head{margin:30px 0 16px}
.section-head h2{font-size:23px;letter-spacing:-.5px}
.section-head p{color:var(--muted);font-size:12px;line-height:1.6;margin-top:5px}

/* BOOKING FORM HEADER (title + close button) */
.form-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.form-head>div{min-width:0}

.form-close{
    flex-shrink:0;padding:9px 13px;
    border:1px solid var(--line);border-radius:10px;
    background:white;color:var(--forest);
    font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap
}

.form-close:hover{background:var(--mint);border-color:#b6dec4}

/* WELCOME CARD ("Palawan is calling") — shown instead of the booking form */
.welcome-card{
    margin-top:30px;
    display:flex;align-items:center;gap:20px;
    background:white;border:1px solid var(--line);border-radius:20px;
    padding:clamp(20px,3vw,30px)
}

.welcome-icon{
    width:56px;height:56px;border-radius:16px;
    display:grid;place-items:center;
    background:var(--mint);font-size:28px;flex-shrink:0
}

.welcome-text{min-width:0;flex:1}
.welcome-text h2{color:var(--forest);font-size:23px;letter-spacing:-.5px}
.welcome-text p{color:var(--muted);font-size:13px;line-height:1.7;margin-top:6px}

.welcome-btn{
    flex-shrink:0;min-height:48px;padding:14px 22px;
    border:0;border-radius:11px;
    background:var(--dark);color:white;
    font-weight:800;cursor:pointer;white-space:nowrap
}

.welcome-btn:hover{background:var(--forest)}

/* Make sure the hidden attribute always wins over display rules */
.welcome-card[hidden],
#bookingFormWrap[hidden]{display:none !important}

.booking-panel{background:white;border:1px solid var(--line);border-radius:20px;padding:clamp(18px,3vw,30px)}
.fields{display:grid;grid-template-columns:1fr 1fr;gap:17px}
.field{display:flex;flex-direction:column;gap:8px;min-width:0}
.field.full{grid-column:1/-1}
.field>label{font-size:12px;font-weight:800}

.field input,.field select,.field textarea{
    width:100%;min-width:0;border:1px solid var(--line);
    background:white;padding:12px;border-radius:10px;color:var(--ink);outline:none
}

.field input,.field select{min-height:46px}
.field textarea{min-height:125px;resize:vertical;font-size:13px;line-height:1.7}

.field input:focus,.field select:focus,.field textarea:focus{
    border-color:var(--green);box-shadow:0 0 0 3px #16865318
}

.notes-heading{display:flex;align-items:center;gap:7px}
.optional-label{color:var(--muted);font-size:11px;font-weight:500}
.notes-helper{display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:10px;line-height:1.6}
.notes-counter{white-space:nowrap;font-weight:800;color:var(--green)}

.transport-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.transport-option{position:relative;min-width:0}
.transport-option input{position:absolute;opacity:0;pointer-events:none}

.transport-option label{
    border:1px solid var(--line);border-radius:12px;padding:14px;
    display:flex;align-items:center;gap:10px;cursor:pointer;min-width:0
}

.transport-option input:checked+label{
    background:var(--mint);border:2px solid var(--green);padding:13px
}

.transport-option strong{font-size:13px}
.transport-option small{display:block;font-size:10px;color:var(--muted);margin-top:4px}

.fare-box{
    grid-column:1/-1;background:#edf7ef;
    border:1px solid #cfe8d5;border-radius:14px;
    padding:17px;display:grid;gap:9px
}

.fare-heading{color:var(--forest);font-size:12px;font-weight:800}
.fare-line{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:var(--muted)}
.fare-line strong{color:var(--forest);text-align:right}
.fare-total{border-top:1px solid #cfe3d3;padding-top:11px;margin-top:3px;font-size:14px;font-weight:800}
.fare-total strong{color:var(--green);font-size:20px}
.fare-note{color:var(--muted);font-size:10px;line-height:1.6}

.submit-btn{
    margin-top:22px;width:100%;min-height:48px;
    border:0;border-radius:11px;padding:14px;
    background:var(--dark);color:white;font-weight:800;cursor:pointer
}

.submit-btn:hover{background:var(--forest)}
.submit-btn:disabled{opacity:.7;cursor:wait}

.notice{border-radius:10px;padding:13px 15px;margin-bottom:18px;font-size:13px;line-height:1.6}
.notice.error{background:#fff0ed;color:#9a3525}

.success-popup{
    position:fixed;top:25px;right:25px;z-index:9999;
    display:flex;align-items:center;gap:12px;padding:18px 24px;
    background:white;color:#0b5c39;border:1px solid #b8e7c8;
    border-radius:14px;box-shadow:0 10px 35px #00000020;
    font-size:14px;font-weight:800;animation:popupShow .35s ease forwards
}

.success-popup .check{
    display:grid;place-items:center;width:30px;height:30px;
    border-radius:50%;background:#dff5e6;font-size:18px
}

.success-popup.hide{animation:popupHide .35s ease forwards}
@keyframes popupShow{to{opacity:1;transform:translateY(0)}}
@keyframes popupHide{to{opacity:0;transform:translateY(-15px)}}

.booking-toggle{
    width:100%;display:flex;justify-content:space-between;
    align-items:center;gap:12px;padding:17px 19px;
    background:white;border:1px solid var(--line);
    border-radius:14px;color:var(--forest);
    font-weight:800;cursor:pointer;text-align:left
}

.booking-toggle:hover,.booking-toggle[aria-expanded="true"]{
    background:var(--mint);border-color:#b6dec4
}

.booking-toggle small{display:block;color:var(--muted);font-size:11px;font-weight:500;margin-top:5px}
.booking-toggle .arrow{font-size:20px;transition:transform .2s}
.booking-toggle[aria-expanded="true"] .arrow{transform:rotate(180deg)}

.booking-content{margin-top:13px}
.booking-content[hidden]{display:none}

.booking-list{display:grid;gap:12px}
.booking-item{padding:18px;background:white;border:1px solid var(--line);border-radius:14px;min-width:0}
.booking-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.booking-item h3{font-size:17px;overflow-wrap:anywhere}
.booking-item p{color:var(--muted);font-size:12px;line-height:1.8;margin-top:7px;overflow-wrap:anywhere}
.booking-fare{color:var(--green);font-weight:800}

.status{display:inline-block;border-radius:20px;padding:6px 10px;background:#fff1d2;color:#835800;font-size:10px;font-weight:800;flex-shrink:0}
.status.accepted{background:#dff5e6;color:#08713d}
.status.rejected{background:#fff0ed;color:#a83225}

.booking-notes{margin-top:14px;padding:13px 15px;background:#f0f7f1;border:1px solid #dcecdf;border-radius:11px}
.booking-notes strong{display:block;color:var(--forest);font-size:11px;margin-bottom:6px}
.booking-notes p{margin:0}

.empty{background:white;border:1px dashed #cbd9ce;border-radius:15px;padding:24px;color:var(--muted);font-size:13px;line-height:1.7}
.footer-note{margin-top:27px;padding:18px;border-radius:14px;background:#edf5e9;color:var(--muted);font-size:12px;line-height:1.7}

.confirmation-overlay{
    position:fixed;inset:0;z-index:10000;
    background:rgba(5,30,18,.65);
    display:flex;align-items:center;justify-content:center;
    padding:18px;backdrop-filter:blur(4px)
}

.confirmation-overlay[hidden]{display:none}

.confirmation-modal{
    width:100%;max-width:440px;background:white;
    border-radius:20px;padding:27px;box-shadow:0 20px 60px #00000030
}

.confirmation-icon{
    width:48px;height:48px;display:grid;place-items:center;
    background:var(--mint);color:var(--green);
    border-radius:14px;font-size:25px;font-weight:800;margin-bottom:17px
}

.confirmation-modal h2{color:var(--forest);font-size:24px}
.confirmation-modal>p{color:var(--muted);font-size:13px;line-height:1.7;margin-top:9px}

.confirmation-details{
    display:grid;gap:12px;margin-top:21px;padding:17px;
    background:#f5f8f4;border:1px solid var(--line);border-radius:13px
}

.confirmation-details div{display:flex;justify-content:space-between;gap:15px;font-size:12px}
.confirmation-details span{color:var(--muted)}
.confirmation-details strong{color:var(--forest);text-align:right;overflow-wrap:anywhere}

.confirmation-actions{display:grid;grid-template-columns:1fr 1.4fr;gap:10px;margin-top:21px}
.confirmation-actions button{min-height:45px;border-radius:10px;padding:10px;font-size:12px;font-weight:800;cursor:pointer}
#cancelBooking{border:1px solid var(--line);background:white;color:var(--forest)}
#confirmBooking{border:0;background:var(--green);color:white}
#confirmBooking:disabled,#cancelBooking:disabled{opacity:.6;cursor:not-allowed}

@media(max-width:950px){
    .layout{grid-template-columns:205px minmax(0,1fr)}
    main{padding:25px 20px 35px}
}

@media(max-width:720px){
    .layout{display:block}
    .sidebar{width:100%;height:auto;min-height:0;position:relative;padding:15px 17px}
    .brand{margin:0}
    .brand-mark{width:39px;height:39px}
    .sidebar>.menu-label,.sidebar>.menu,.side-bottom{display:none}
    main{width:100%;padding:21px 16px 25px}
    .topbar{margin-bottom:25px;gap:10px}
    .topbar-actions{gap:6px}
    .notification-link{gap:5px;padding:9px;font-size:10px}
    .notification-panel{
        position:fixed;top:75px;right:12px;
        width:440px;max-width:calc(100vw - 24px);max-height:75vh
    }
    .home-link{gap:7px;padding:10px 12px;font-size:11px}
    .hero{border-radius:18px;padding:25px 21px}
    .hero:after{right:-25px;top:-12px;font-size:150px}
    .fields{grid-template-columns:minmax(0,1fr)}
    .field.full,.fare-box{grid-column:auto}
    .field input,.field select{font-size:16px;min-height:48px}
    .field textarea{font-size:14px}
    .booking-panel{padding:17px;border-radius:17px}
    .welcome-card{flex-direction:column;align-items:flex-start;gap:15px;border-radius:17px}
    .welcome-btn{width:100%}
    .mobile-profile-footer{
        display:flex;flex-direction:column;gap:15px;
        margin-top:23px;padding:17px;border-radius:17px;
        background:var(--forest);color:white
    }
    .mobile-profile-info{
        display:flex;align-items:center;gap:12px;
        padding-bottom:15px;border-bottom:1px solid #ffffff24
    }
    .mobile-profile-details{min-width:0;flex:1}
    .mobile-logout{
        display:flex;align-items:center;justify-content:space-between;
        width:100%;min-height:47px;padding:12px 13px;
        border:1px solid #ffffff30;border-radius:11px;
        color:white;font-size:13px;font-weight:700
    }
}

@media(max-width:480px){
    .success-popup{left:15px;right:15px;top:15px;justify-content:center;padding:15px;font-size:13px}
    .booking-top{gap:10px}
    .notes-helper{flex-wrap:wrap}
    .confirmation-modal{padding:21px}
    .confirmation-actions{grid-template-columns:1fr}
}

@media(max-width:380px){
    main{padding-left:12px;padding-right:12px}
    .notification-link{font-size:9px;padding:8px 6px}
    .home-link{font-size:10px;padding:9px}
    .transport-grid{grid-template-columns:1fr}
    .booking-panel{padding:14px}
}

@media(prefers-reduced-motion:reduce){
    *,*::before,*::after{
        scroll-behavior:auto!important;
        animation-duration:.01ms!important;
        transition-duration:.01ms!important
    }
}
</style>
</head>

<body>

<?php if ($success): ?>
<div class="success-popup" id="successPopup" role="status">
    <span class="check">✓</span>
    <span>Booking request submitted!</span>
</div>
<?php endif; ?>

<!-- BOOKING CONFIRMATION -->
<div class="confirmation-overlay" id="confirmationModal" hidden>
    <div class="confirmation-modal" role="dialog" aria-modal="true" aria-labelledby="confirmationTitle">
        <div class="confirmation-icon">?</div>
        <h2 id="confirmationTitle">Confirm Your Booking</h2>
        <p>Please review your trip details before submitting. Are you sure you want to continue?</p>

        <div class="confirmation-details">
            <div><span>Route</span><strong id="confirmRoute">—</strong></div>
            <div><span>Transport</span><strong id="confirmTransport">—</strong></div>
            <div><span>Travel date</span><strong id="confirmDate">—</strong></div>
            <div><span>Passengers</span><strong id="confirmPassengers">—</strong></div>
            <div><span>Estimated total</span><strong id="confirmTotal">—</strong></div>
        </div>

        <div class="confirmation-actions">
            <button type="button" id="cancelBooking">No, Go Back</button>
            <button type="button" id="confirmBooking">Yes, Confirm Booking</button>
        </div>
    </div>
</div>

<div class="layout">

<aside class="sidebar">

    <a href="index.php" class="brand" aria-label="TerminalHub homepage">
        <div class="brand-mark">T</div>
        <div>
            <div class="brand-name">TerminalHub</div>
            <div class="brand-sub">EXPLORE PALAWAN</div>
        </div>
    </a>

    <div class="menu-label">WORKSPACE</div>

    <nav class="menu" aria-label="Dashboard navigation">
        <a href="#dashboard" id="menuDashboard" class="<?= $showBookingForm ? '' : 'active' ?>">⌂ <span>Dashboard</span></a>
        <a href="#book-trip" id="menuBookTrip" class="<?= $showBookingForm ? 'active' : '' ?>">▣ <span>Book a Trip</span></a>
        <a href="#my-bookings" id="sidebarBookings">▤ <span>My Bookings</span></a>
    </nav>

    <div class="side-bottom">

        <div class="profile">
            <div class="avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
            <div>
                <div class="profile-name"><?= $name ?></div>
                <div class="profile-label">Traveler account</div>
            </div>
            <a class="logout" href="logout.php" title="Log out" aria-label="Log out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>
                </svg>
            </a>
        </div>
    </div>

</aside>

<main id="dashboard">

<header class="topbar">

    <div class="breadcrumb">Workspace <span>/ Travel Dashboard</span></div>

    <div class="topbar-actions">

        <div class="notification-wrap">

            <!-- NOTIFICATION BUTTON: CLOSED BY DEFAULT -->
            <button type="button"
                    class="notification-link"
                    id="notificationToggle"
                    aria-expanded="false"
                    aria-controls="notificationPanel">

                <span class="notification-bell">🔔</span>
                <span>Notifications</span>

                <?php if ($unreadCount > 0): ?>
                    <span class="notification-count">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                    </span>
                <?php endif; ?>

            </button>

            <!-- NOTIFICATION PANEL: ALWAYS HIDDEN WHEN PAGE LOADS -->
            <div class="notification-panel" id="notificationPanel" hidden>

                <div class="notification-panel-head">
                    <div>
                        <h3>Notifications</h3>
                        <p>
                            <?= $unreadCount ?> unread notification<?= $unreadCount === 1 ? '' : 's' ?>
                        </p>
                    </div>

                    <button type="button"
                            class="notification-close"
                            id="notificationClose"
                            aria-label="Close notifications">×</button>
                </div>

                <div class="notification-tools">

                    <!-- FILTER BUTTONS: filter instantly without reloading the page -->
                    <div class="notification-filters" id="notificationFilters">

                        <?php
                        $filters = [
                            'all' => 'All',
                            'pending' => 'Pending',
                            'accepted' => 'Accepted',
                            'rejected' => 'Rejected'
                        ];

                        foreach ($filters as $filterKey => $filterLabel):
                        ?>

                            <button type="button"
                                    class="filter-link <?= $notificationFilter === $filterKey ? 'active' : '' ?>"
                                    data-filter="<?= htmlspecialchars($filterKey, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-pressed="<?= $notificationFilter === $filterKey ? 'true' : 'false' ?>">

                                <?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?>

                            </button>

                        <?php endforeach; ?>

                    </div>

                    <?php if (!empty($notifications)): ?>

                        <form method="POST"
                              action="plan.php"
                              onsubmit="return confirm('Are you sure you want to delete all your notifications?');">

                            <input type="hidden" name="action" value="clear_all_notifications">

                            <input type="hidden"
                                   name="notification_csrf_token"
                                   value="<?= htmlspecialchars($_SESSION['notification_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                            <button type="submit" class="clear-all-btn">
                                Clear All Notifications
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

                <?php if (!$pdo): ?>

                    <div class="notification-empty">
                        Notifications are unavailable because the database connection failed.
                    </div>

                <?php elseif (empty($notifications)): ?>

                    <div class="notification-empty">
                        <span style="font-size:28px">🔔</span>
                        <strong>No notifications found.</strong>
                        <p>You don't have any notifications yet.</p>
                    </div>

                <?php else: ?>

                    <div class="notification-list" id="notificationList">

                        <?php foreach ($notifications as $notification): ?>

                            <?php
                            $isUnread = (int)$notification['is_read'] === 0;
                            $notificationStatus = getNotificationStatus($notification);
                            $isVisibleInitially = (
                                $notificationFilter === 'all' ||
                                $notificationFilter === $notificationStatus
                            );
                            ?>

                            <article class="notification-item <?= $isUnread ? 'unread' : 'read' ?>"
                                     data-status="<?= htmlspecialchars($notificationStatus, ENT_QUOTES, 'UTF-8') ?>"
                                     <?= $isVisibleInitially ? '' : 'hidden' ?>>

                                <div class="notification-content">

                                    <div class="notification-title">

                                        <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>

                                        <?php if ($isUnread): ?>
                                            <span class="new-label">NEW</span>
                                        <?php endif; ?>

                                    </div>

                                    <p>
                                        <?= nl2br(htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8')) ?>
                                    </p>

                                    <small>
                                        <?= htmlspecialchars(date('M d, Y h:i A', strtotime($notification['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    </small>

                                </div>

                                <div class="notification-actions">

                                    <?php if ($isUnread): ?>

                                        <form method="POST" action="plan.php">

                                            <input type="hidden" name="action" value="mark_notification_read">

                                            <input type="hidden"
                                                   name="notification_id"
                                                   value="<?= (int)$notification['id'] ?>">

                                            <input type="hidden"
                                                   name="notification_csrf_token"
                                                   value="<?= htmlspecialchars($_SESSION['notification_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                                            <button type="submit" class="read-btn">
                                                Mark Read
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="read-label">Read</span>

                                    <?php endif; ?>

                                    <form method="POST"
                                          action="plan.php"
                                          onsubmit="return confirm('Delete this notification?');">

                                        <input type="hidden" name="action" value="delete_notification">

                                        <input type="hidden"
                                               name="notification_id"
                                               value="<?= (int)$notification['id'] ?>">

                                        <input type="hidden"
                                               name="notification_csrf_token"
                                               value="<?= htmlspecialchars($_SESSION['notification_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                                        <button type="submit" class="delete-btn">
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <!-- Shown by JavaScript when the selected filter has no matches -->
                    <div class="notification-empty" id="notificationFilterEmpty" hidden>
                        <span style="font-size:28px">🔔</span>
                        <strong>No notifications found.</strong>
                        <p>There are no notifications under this filter.</p>
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <a href="index.php" class="home-link">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="m3 10 9-7 9 7"/>
                <path d="M5 9v11h14V9"/>
                <path d="M9 20v-7h6v7"/>
            </svg>

            <span>Homepage</span>

        </a>

    </div>

</header>

<section style="margin-bottom:25px">

    <div class="eyebrow">YOUR TRAVEL SPACE</div>

    <h1>
        Where are you going,<br>
        <span><?= $name ?>?</span>
    </h1>

    <p class="intro">
        Choose your starting point, destination, and preferred transport.
        Check the estimated fare before sending your booking request.
    </p>

</section>

<section class="hero">

    <div class="tag">✦ YOUR NEXT JOURNEY STARTS HERE</div>

    <h2>
        Explore Palawan,<br>
        <span>one trip at a time.</span>
    </h2>

    <p>
        Start your journey from Puerto Princesa or choose another municipality in Palawan as your starting point.
    </p>

</section>

<?php if ($message !== ""): ?>

    <div class="notice error" role="alert">
        <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
    </div>

<?php endif; ?>

<section id="book-trip">

    <!-- WELCOME SECTION: visible by default and after a successful booking -->
    <div class="welcome-card" id="welcomePanel" <?= $showBookingForm ? 'hidden' : '' ?>>

        <div class="welcome-icon">🌴</div>

        <div class="welcome-text">
            <h2>Palawan is calling.</h2>
            <p>Choose your starting point and destination.</p>
        </div>

        <button type="button" class="welcome-btn" id="openBookingForm">
            Book a trip ↗
        </button>

    </div>

    <!-- BOOKING FORM: hidden until the user clicks "Book a Trip" -->
    <div id="bookingFormWrap" <?= $showBookingForm ? '' : 'hidden' ?>>

    <div class="section-head form-head">
        <div>
            <h2>Book your trip</h2>
            <p>Select your origin and destination to view the available fare.</p>
        </div>

        <button type="button" class="form-close" id="closeBookingForm">
            ✕ Close
        </button>
    </div>

    <div class="booking-panel">

        <form method="POST" action="plan.php#book-trip" id="bookingForm">

            <input type="hidden"
                   name="booking_csrf_token"
                   value="<?= htmlspecialchars($_SESSION['booking_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="fields">

                <div class="field full">

                    <label for="origin">From</label>

                    <select name="origin" id="origin" required>

                        <option value="Puerto Princesa City" selected>
                            Puerto Princesa City — Main Starting Point
                        </option>

                        <?php foreach ($locations as $location): ?>

                            <?php if ($location !== "Puerto Princesa City"): ?>

                                <option value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>
                                </option>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="field full">

                    <label for="destination">To</label>

                    <select name="destination" id="destination" required>
                        <option value="">Choose destination</option>
                    </select>

                </div>

                <div class="field full">

                    <label>Transport type</label>

                    <div class="transport-grid">

                        <div class="transport-option">

                            <input type="radio" name="transport" id="van" value="Van" required>

                            <label for="van">
                                <span style="font-size:23px">🚐</span>
                                <span>
                                    <strong>Van</strong>
                                    <small>Shared or operator van</small>
                                </span>
                            </label>

                        </div>

                        <div class="transport-option">

                            <input type="radio" name="transport" id="bus" value="Bus" required>

                            <label for="bus">
                                <span style="font-size:23px">🚌</span>
                                <span>
                                    <strong>Bus</strong>
                                    <small>Bus operator service</small>
                                </span>
                            </label>

                        </div>

                    </div>

                </div>

                <div class="field">

                    <label for="travel_date">Travel date</label>

                    <input type="date"
                           name="travel_date"
                           id="travel_date"
                           min="<?= date('Y-m-d') ?>"
                           required>

                </div>

                <div class="field">

                    <label for="passengers">Passengers</label>

                    <select name="passengers" id="passengers" required>

                        <?php for ($i = 1; $i <= 15; $i++): ?>

                            <option value="<?= $i ?>">
                                <?= $i ?> passenger<?= $i > 1 ? 's' : '' ?>
                            </option>

                        <?php endfor; ?>

                    </select>

                </div>

                <div class="field full">

                    <label for="contact">Contact number or email</label>

                    <input type="text"
                           name="contact"
                           id="contact"
                           maxlength="100"
                           placeholder="Enter your contact details"
                           required>

                </div>

                <div class="field full">

                    <label for="passenger_notes" class="notes-heading">
                        Passenger Notes
                        <span class="optional-label">(Optional)</span>
                    </label>

                    <textarea name="passenger_notes"
                              id="passenger_notes"
                              maxlength="2000"
                              placeholder="Add any special requests or important travel information here...

Example:
• I have extra luggage.
• Please contact me before departure.
• I will be travelling with a child.
• I need assistance during boarding."></textarea>

                    <div class="notes-helper">
                        <span>Share important details to help with your trip.</span>
                        <span class="notes-counter">
                            <span id="notesCount">0</span>/2000
                        </span>
                    </div>

                </div>

                <div class="fare-box" aria-live="polite">

                    <div class="fare-heading">ESTIMATED TRIP FARE</div>

                    <div class="fare-line">
                        <span>Route</span>
                        <strong id="fareRoute">Choose your route</strong>
                    </div>

                    <div class="fare-line">
                        <span>Fare per passenger</span>
                        <strong id="farePerPassenger">—</strong>
                    </div>

                    <div class="fare-line">
                        <span>Number of passengers</span>
                        <strong id="farePassengers">1</strong>
                    </div>

                    <div class="fare-line fare-total">
                        <span>Total fare</span>
                        <strong id="fareTotal">—</strong>
                    </div>

                    <p class="fare-note" id="fareNote">
                        Select your origin, destination, and transport type to view the available fare.
                        Unlisted fares must be confirmed by the transport operator.
                    </p>

                </div>

            </div>

            <button class="submit-btn" id="submitBooking" type="submit">
                Submit Booking Request →
            </button>

            <p class="note" style="color:var(--muted);font-size:11px;line-height:1.7;margin-top:13px">
                Booking requests are marked Pending. The displayed fare is an estimate based on the available route information. The operator must verify the final price, schedule, and seat availability before confirming your trip.
            </p>

        </form>

    </div>

    </div><!-- /#bookingFormWrap -->

</section>

<section id="my-bookings">

    <div class="section-head">
        <h2>My bookings</h2>
        <p>Open this section to view your saved booking requests and trip details.</p>
    </div>

    <button type="button"
            class="booking-toggle"
            id="bookingToggle"
            aria-expanded="false"
            aria-controls="bookingContent">

        <span>
            View My Bookings
            <small>
                <?= count($bookings) ?> booking<?= count($bookings) === 1 ? '' : 's' ?> in your account
            </small>
        </span>

        <span class="arrow">⌄</span>

    </button>

    <div class="booking-content" id="bookingContent" hidden>

        <?php if (!$pdo): ?>

            <div class="empty">
                Connect the dashboard to your MySQL database to display bookings.
            </div>

        <?php elseif (empty($bookings)): ?>

            <div class="empty">
                You don't have any booking requests yet.
                Choose a destination above to get started.
            </div>

        <?php else: ?>

            <div class="booking-list">

                <?php foreach ($bookings as $booking): ?>

                    <?php
                    $bookingStatus = strtolower($booking['status']);

                    $displayStatus = $bookingStatus === 'confirmed'
                        ? 'Accepted'
                        : ucfirst($bookingStatus);

                    $statusClass = $bookingStatus === 'confirmed' || $bookingStatus === 'accepted'
                        ? 'accepted'
                        : ($bookingStatus === 'rejected' ? 'rejected' : '');
                    ?>

                    <article class="booking-item">

                        <div class="booking-top">

                            <div>

                                <h3>
                                    <?= htmlspecialchars($booking['origin'], ENT_QUOTES, 'UTF-8') ?>
                                    →
                                    <?= htmlspecialchars($booking['destination'], ENT_QUOTES, 'UTF-8') ?>
                                </h3>

                                <p>

                                    Reference:
                                    <?= htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?><br>

                                    Route type:
                                    <?= htmlspecialchars($booking['direction'], ENT_QUOTES, 'UTF-8') ?><br>

                                    Transport:
                                    <?= htmlspecialchars($booking['transport_type'], ENT_QUOTES, 'UTF-8') ?><br>

                                    Travel date:
                                    <?= htmlspecialchars($booking['travel_date'], ENT_QUOTES, 'UTF-8') ?><br>

                                    Passengers:
                                    <?= (int)$booking['passengers'] ?><br>

                                    Fare per passenger:
                                    <?= $booking['fare_per_passenger'] !== null
                                        ? '₱' . number_format((float)$booking['fare_per_passenger'], 2)
                                        : 'Fare to be confirmed' ?><br>

                                    Total fare:

                                    <span class="booking-fare">
                                        <?= $booking['total_fare'] !== null
                                            ? '₱' . number_format((float)$booking['total_fare'], 2)
                                            : 'To be confirmed' ?>
                                    </span>

                                </p>

                            </div>

                            <span class="status <?= $statusClass ?>">
                                <?= htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        </div>

                        <?php if (!empty($booking['passenger_notes'])): ?>

                            <div class="booking-notes">

                                <strong>Passenger Notes</strong>

                                <p>
                                    <?= nl2br(htmlspecialchars($booking['passenger_notes'], ENT_QUOTES, 'UTF-8')) ?>
                                </p>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</section>

<div class="footer-note">

    <strong style="color:var(--forest)">TerminalHub · Explore Palawan</strong><br>

    Trip details, operator schedules, fares, and seat availability must be verified before a booking can be confirmed.

</div>

<div class="mobile-profile-footer">

    <div class="mobile-profile-info">

        <div class="avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>

        <div class="mobile-profile-details">
            <div class="profile-name"><?= $name ?></div>
            <div class="profile-label">Traveler account</div>
        </div>

    </div>

    <a class="mobile-logout" href="logout.php" aria-label="Log out">

        <span>Log out</span>

        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 17l5-5-5-5"/>
            <path d="M15 12H3"/>
            <path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>
        </svg>

    </a>

</div>

</main>
</div>

<script>
const locations = <?= $locationsJson ?>;
const routeFares = <?= $fareJson ?>;

const originSelect = document.getElementById('origin');
const destinationSelect = document.getElementById('destination');
const passengersSelect = document.getElementById('passengers');
const transportInputs = document.querySelectorAll('input[name="transport"]');

const fareRoute = document.getElementById('fareRoute');
const farePerPassenger = document.getElementById('farePerPassenger');
const farePassengers = document.getElementById('farePassengers');
const fareTotal = document.getElementById('fareTotal');
const fareNote = document.getElementById('fareNote');

function updateDestinations() {

    const origin = originSelect.value;
    const previousDestination = destinationSelect.value;

    destinationSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose destination';

    destinationSelect.appendChild(placeholder);

    locations.forEach(location => {

        if (location === origin) return;

        const option = document.createElement('option');

        option.value = location;
        option.textContent = location;

        destinationSelect.appendChild(option);

    });

    if (
        previousDestination &&
        previousDestination !== origin &&
        locations.includes(previousDestination)
    ) {
        destinationSelect.value = previousDestination;
    }

    updateFare();
}

function getSelectedTransport() {

    const selected = document.querySelector('input[name="transport"]:checked');

    return selected ? selected.value : '';

}

function formatPeso(amount) {

    return '₱' + Number(amount).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

}

function updateFare() {

    const origin = originSelect.value;
    const destination = destinationSelect.value;
    const transport = getSelectedTransport();
    const passengers = Number(passengersSelect.value || 1);

    farePassengers.textContent = passengers;

    if (!origin || !destination || !transport) {

        fareRoute.textContent = 'Choose your route';
        farePerPassenger.textContent = '—';
        fareTotal.textContent = '—';

        fareNote.textContent =
            'Select your origin, destination, and transport type to view the available fare.';

        return;
    }

    fareRoute.textContent = origin + ' → ' + destination;

    const fareKey = origin + '|' + destination + '|' + transport;
    const fare = routeFares[fareKey];

    if (fare === undefined || fare === null) {

        farePerPassenger.textContent = 'To be confirmed';
        fareTotal.textContent = 'To be confirmed';

        fareNote.textContent =
            'There is no verified fixed fare for this route and transport type yet. Please confirm the price with the transport operator.';

        return;
    }

    farePerPassenger.textContent = formatPeso(fare);

    fareTotal.textContent = formatPeso(
        Number(fare) * passengers
    );

    fareNote.textContent =
        'Estimated fare based on a published operator rate. Actual fare may vary depending on the transport provider.';

}

originSelect.addEventListener('change', updateDestinations);
destinationSelect.addEventListener('change', updateFare);
passengersSelect.addEventListener('change', updateFare);

transportInputs.forEach(input => {
    input.addEventListener('change', updateFare);
});

const passengerNotes = document.getElementById('passenger_notes');
const notesCount = document.getElementById('notesCount');

if (passengerNotes && notesCount) {

    passengerNotes.addEventListener('input', function() {
        notesCount.textContent = this.value.length;
    });

}

updateDestinations();

/* BOOKING CONFIRMATION */
const bookingForm = document.getElementById('bookingForm');
const confirmationModal = document.getElementById('confirmationModal');

const cancelBooking = document.getElementById('cancelBooking');
const confirmBooking = document.getElementById('confirmBooking');
const submitBooking = document.getElementById('submitBooking');

let bookingConfirmed = false;
let bookingSubmitting = false;

function closeBookingModal() {
    confirmationModal.hidden = true;
}

bookingForm.addEventListener('submit', function(event) {

    if (bookingSubmitting) {
        event.preventDefault();
        return;
    }

    if (!bookingConfirmed) {

        event.preventDefault();

        if (!bookingForm.reportValidity()) return;

        const origin = originSelect.value;
        const destination = destinationSelect.value;
        const transport = getSelectedTransport();
        const travelDate = document.getElementById('travel_date').value;
        const passengers = passengersSelect.value;

        const fareKey = origin + '|' + destination + '|' + transport;
        const fare = routeFares[fareKey];

        document.getElementById('confirmRoute').textContent =
            origin + ' → ' + destination;

        document.getElementById('confirmTransport').textContent =
            transport;

        document.getElementById('confirmDate').textContent =
            travelDate;

        document.getElementById('confirmPassengers').textContent =
            passengers;

        document.getElementById('confirmTotal').textContent =
            fare !== undefined
                ? formatPeso(Number(fare) * Number(passengers))
                : 'To be confirmed';

        confirmationModal.hidden = false;
        confirmBooking.focus();

    }

});

cancelBooking.addEventListener('click', closeBookingModal);

confirmBooking.addEventListener('click', function() {

    if (bookingSubmitting) return;

    bookingSubmitting = true;
    bookingConfirmed = true;

    confirmBooking.disabled = true;
    cancelBooking.disabled = true;
    submitBooking.disabled = true;

    confirmBooking.textContent = 'Submitting...';
    submitBooking.textContent = 'Submitting Your Booking...';

    closeBookingModal();

    bookingForm.submit();

});

confirmationModal.addEventListener('click', function(event) {

    if (
        event.target === confirmationModal &&
        !bookingSubmitting
    ) {
        closeBookingModal();
    }

});

document.addEventListener('keydown', function(event) {

    if (
        event.key === 'Escape' &&
        !confirmationModal.hidden &&
        !bookingSubmitting
    ) {
        closeBookingModal();
    }

});

/* WELCOME SECTION <-> BOOKING FORM SWITCH */
const welcomePanel = document.getElementById('welcomePanel');
const bookingFormWrap = document.getElementById('bookingFormWrap');
const openBookingFormBtn = document.getElementById('openBookingForm');
const closeBookingFormBtn = document.getElementById('closeBookingForm');
const menuDashboard = document.getElementById('menuDashboard');
const menuBookTrip = document.getElementById('menuBookTrip');

function setActiveMenu(activeLink) {

    [menuDashboard, menuBookTrip].forEach(link => {

        if (link) {
            link.classList.toggle('active', link === activeLink);
        }

    });

}

/* Show the booking form and hide the welcome section */
function showBookingForm() {

    welcomePanel.hidden = true;
    bookingFormWrap.hidden = false;

    setActiveMenu(menuBookTrip);

    bookingFormWrap.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

}

/* Hide the booking form and show the welcome section again */
function showWelcome() {

    bookingFormWrap.hidden = true;
    welcomePanel.hidden = false;

    setActiveMenu(menuDashboard);

}

openBookingFormBtn.addEventListener('click', showBookingForm);

closeBookingFormBtn.addEventListener('click', function() {

    showWelcome();

    welcomePanel.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });

});

menuBookTrip.addEventListener('click', function(event) {

    event.preventDefault();

    showBookingForm();

});

menuDashboard.addEventListener('click', function(event) {

    event.preventDefault();

    showWelcome();

    document.getElementById('dashboard').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

});

/* NOTIFICATION DROPDOWN */
const notificationToggle = document.getElementById('notificationToggle');
const notificationPanel = document.getElementById('notificationPanel');
const notificationClose = document.getElementById('notificationClose');

function openNotifications() {

    notificationPanel.hidden = false;

    notificationToggle.setAttribute('aria-expanded', 'true');

}

function closeNotifications() {

    notificationPanel.hidden = true;

    notificationToggle.setAttribute('aria-expanded', 'false');

}

function toggleNotifications() {

    if (notificationPanel.hidden) {
        openNotifications();
    } else {
        closeNotifications();
    }

}

/*
   IMPORTANT:
   Do not automatically open the notification panel on page load.
   It will only open after the user clicks the notification button.
*/

notificationToggle.addEventListener('click', function(event) {

    event.stopPropagation();

    toggleNotifications();

});

notificationClose.addEventListener('click', function(event) {

    event.stopPropagation();

    closeNotifications();

});

/* SIDEBAR NOTIFICATION BUTTON (only runs if a sidebar notification link exists) */
document.querySelectorAll('.notification-sidebar-toggle').forEach(link => {

    link.addEventListener('click', function(event) {

        event.preventDefault();

        openNotifications();

    });

});

/* NOTIFICATION FILTERS (no page reload, panel stays open) */
const notificationFilterButtons = document.querySelectorAll('#notificationFilters .filter-link');
const notificationItems = document.querySelectorAll('#notificationList .notification-item');
const notificationFilterEmpty = document.getElementById('notificationFilterEmpty');

function applyNotificationFilter(filter) {

    let visibleCount = 0;

    notificationItems.forEach(item => {

        const matches = filter === 'all' || item.dataset.status === filter;

        item.hidden = !matches;

        if (matches) visibleCount++;

    });

    notificationFilterButtons.forEach(button => {

        const isActive = button.dataset.filter === filter;

        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

    });

    if (notificationFilterEmpty) {
        notificationFilterEmpty.hidden = visibleCount > 0;
    }

}

notificationFilterButtons.forEach(button => {

    button.addEventListener('click', function(event) {

        event.preventDefault();
        event.stopPropagation();

        applyNotificationFilter(this.dataset.filter);

    });

});

/* Apply the initial filter state (defaults to "all") */
applyNotificationFilter(
    document.querySelector('#notificationFilters .filter-link.active')?.dataset.filter || 'all'
);

/* CLOSE WHEN CLICKING OUTSIDE */
document.addEventListener('click', function(event) {

    if (
        !notificationPanel.contains(event.target) &&
        !notificationToggle.contains(event.target)
    ) {

        closeNotifications();

    }

});

/* CLOSE WITH ESCAPE KEY */
document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeNotifications();
    }

});

/* MY BOOKINGS: SHOW ONLY WHEN CLICKED */
const bookingToggle = document.getElementById('bookingToggle');
const bookingContent = document.getElementById('bookingContent');
const sidebarBookings = document.getElementById('sidebarBookings');

function toggleBookings() {

    const isOpen = !bookingContent.hidden;

    bookingContent.hidden = isOpen; 

    bookingToggle.setAttribute('aria-expanded', String(!isOpen));

    if (!isOpen) {

        bookingToggle.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

    }

}

bookingToggle.addEventListener('click', toggleBookings);

sidebarBookings.addEventListener('click', function(event) {

    event.preventDefault();

    if (bookingContent.hidden) {

        bookingContent.hidden = false;

        bookingToggle.setAttribute('aria-expanded', 'true');

    } else {

        bookingContent.hidden = true;

        bookingToggle.setAttribute('aria-expanded', 'false');

    }

    document.getElementById('my-bookings').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

});

/* SUCCESS MESSAGE */
const popup = document.getElementById('successPopup');

if (popup) {

    setTimeout(() => {

        popup.classList.add('hide');

        setTimeout(() => {

            popup.remove();

            const url = new URL(window.location.href);

            url.searchParams.delete('booked');

            window.history.replaceState(
                {},
                '',
                url.pathname + url.search + url.hash
            );

        }, 350);

    }, 3000);

}
</script>

</body>
</html>