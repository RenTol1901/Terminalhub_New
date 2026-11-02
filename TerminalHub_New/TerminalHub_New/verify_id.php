<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/id_helpers.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatIdDate($value) {
    return $value ? date('M d, Y h:i A', strtotime($value)) : '';
}

/* ACCESS: LOGGED-IN PASSENGERS ONLY */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? '';

if ($role === 'admin') {
    header('Location: admin/dashboard.php');
    exit;
}

if ($role === 'staff') {
    header('Location: staff/dashboard.php');
    exit;
}

if ($role !== 'passenger') {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* CSRF TOKEN (also used by check_id_photo.php) */
if (empty($_SESSION['verify_id_csrf_token'])) {
    $_SESSION['verify_id_csrf_token'] = bin2hex(random_bytes(32));
}

/* MESSAGE SHOWN AFTER A SUCCESSFUL SUBMISSION */
$success = $_SESSION['verify_id_success'] ?? '';
unset($_SESSION['verify_id_success']);

$error = '';
$oldIdType = '';

$idSides = [
    'front' => ['label' => 'Front of your ID', 'required' => true],
    'back'  => ['label' => 'Back of your ID', 'required' => false]
];

/* PHOTOS BIGGER THAN THE SERVER ALLOWS */
$postTooLarge = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty($_POST) &&
    empty($_FILES) &&
    (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
);

/* SUBMIT A NEW ID */
if ($postTooLarge) {

    $error = 'The photos you selected are too large to upload. Please use smaller photos (maximum 5 MB each).';

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $oldIdType = (string) ($_POST['id_type'] ?? '');
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['verify_id_csrf_token'], $token)
    ) {
        $error = 'Session expired. Please refresh the page and try again.';

    } else {

        try {
            /*
             * Checks the ID type, the photo quality (again, on the server)
             * and saves everything. A photo with poor quality is NOT saved
             * and the ID is NOT marked as Rejected.
             */
            processIdSubmission($pdo, $userId, $_POST, $_FILES);

            $_SESSION['verify_id_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['verify_id_success'] = 'Your ID was submitted. Staff will review it soon and you will get a notification.';

            header('Location: verify_id.php');
            exit;

        } catch (PDOException $e) {
            error_log('ID submission error: ' . $e->getMessage());
            $error = 'We could not save your ID. Please try again.';

        } catch (RuntimeException $e) {
            // Friendly messages, including photo quality problems
            $error = $e->getMessage();
        }
    }
}

/* LOAD THE CURRENT ID STATUS */
$latest = null;
$status = 'None';
$loadFailed = false;

try {
    $latest = getLatestPassengerId($pdo, $userId);
    $status = $latest ? (string) $latest['status'] : 'None';

} catch (PDOException $e) {
    error_log('ID status error: ' . $e->getMessage());
    $loadFailed = true;

    if ($error === '') {
        $error = 'ID verification is not available right now. Please try again later.';
    }
}

$statusKey = strtolower($status);
$canSubmit = !$loadFailed && in_array($status, ['None', 'Rejected'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b3d2a">

<title>ID Verification | TerminalHub</title>

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
    --ink:#14291e;
    --muted:#66756a;
    --line:#e3eae2;
}

*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--cream);color:var(--ink);font-family:"Figtree",sans-serif}
a{text-decoration:none;color:inherit}
button,input,select{font:inherit}
h1,h2,h3{font-family:"Bricolage Grotesque",sans-serif}

.topbar{
    display:flex;justify-content:space-between;align-items:center;gap:15px;
    padding:16px clamp(18px,5vw,52px);background:var(--forest);color:white
}

.brand{display:flex;align-items:center;gap:11px}
.brand-mark{
    width:40px;height:40px;border-radius:13px 13px 13px 5px;
    display:grid;place-items:center;background:#65d995;
    color:var(--forest);font-size:23px;font-weight:800;flex-shrink:0
}
.brand-name{font-size:20px;font-weight:800;letter-spacing:-.8px}

.top-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.top-link{
    padding:10px 14px;border:1px solid #ffffff30;border-radius:11px;
    color:#d0e1d5;font-size:12px;font-weight:700;white-space:nowrap
}
.top-link:hover{background:#ffffff18;color:white}

.wrap{max-width:760px;margin:0 auto;padding:34px clamp(18px,4vw,28px) 55px}

.eyebrow{color:var(--dark);font-size:10px;font-weight:800;letter-spacing:2px;margin-bottom:12px}
h1{font-size:clamp(30px,5vw,40px);line-height:1.1;letter-spacing:-1.4px}
.intro{color:var(--muted);font-size:14px;line-height:1.7;margin-top:10px;max-width:600px}

.notice{border-radius:12px;padding:13px 15px;margin-top:22px;font-size:13px;line-height:1.6}
.notice.error{background:#fff0ed;color:#9a3525;border:1px solid #f2d2cc}
.notice.success{background:#e8f6ec;color:#0b5c39;border:1px solid #b8e7c8;font-weight:700}

/* STATUS CARD */
.status-card{
    display:flex;gap:18px;align-items:flex-start;
    margin-top:24px;padding:clamp(18px,3vw,26px);
    background:white;border:1px solid var(--line);border-radius:20px
}

.status-icon{
    width:52px;height:52px;border-radius:16px;flex-shrink:0;
    display:grid;place-items:center;
    background:var(--mint);color:var(--dark);
    font-size:24px;font-weight:800
}

.status-card.pending .status-icon{background:#fff1d2;color:#835800}
.status-card.approved .status-icon{background:#dff5e6;color:#08713d}
.status-card.rejected .status-icon{background:#fff0ed;color:#a83225}

.status-body{min-width:0;flex:1}

.status-pill{
    display:inline-block;padding:5px 11px;border-radius:20px;
    background:var(--mint);color:var(--dark);font-size:10px;font-weight:800
}

.status-card.pending .status-pill{background:#fff1d2;color:#835800}
.status-card.approved .status-pill{background:#dff5e6;color:#08713d}
.status-card.rejected .status-pill{background:#fff0ed;color:#a83225}

.status-body h2{margin-top:11px;color:var(--forest);font-size:22px;letter-spacing:-.5px}
.status-body p{margin-top:7px;color:var(--muted);font-size:13px;line-height:1.7}

.reason-box{
    margin-top:14px;padding:13px 15px;border-radius:12px;
    background:#fff6f4;border:1px solid #f2d2cc
}
.reason-box strong{display:block;color:#a83225;font-size:11px;margin-bottom:5px}
.reason-box p{margin:0;color:#7a2c20;overflow-wrap:anywhere}

.thumbs{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px}
.thumb{display:block;width:150px}
.thumb img{
    display:block;width:100%;height:100px;object-fit:cover;
    border:1px solid var(--line);border-radius:10px;background:#f1f4f0
}
.thumb span{display:block;margin-top:6px;color:var(--muted);font-size:11px;font-weight:700}

.action-link{
    display:inline-flex;align-items:center;gap:8px;margin-top:16px;
    padding:12px 18px;border-radius:11px;background:var(--dark);
    color:white;font-size:13px;font-weight:800
}
.action-link:hover{background:var(--forest)}

/* FORM CARD */
.form-card{
    margin-top:22px;padding:clamp(18px,3vw,28px);
    background:white;border:1px solid var(--line);border-radius:20px
}

.form-card h2{color:var(--forest);font-size:22px;letter-spacing:-.5px}
.form-card .help{margin:8px 0 18px;color:var(--muted);font-size:13px;line-height:1.7}

.tips{
    margin:0 0 22px;padding:14px 16px;border-radius:12px;
    background:#f3f8f4;border:1px solid #dcecdf
}
.tips strong{display:block;margin-bottom:7px;color:var(--forest);font-size:12px}
.tips ul{margin-left:18px;color:var(--muted);font-size:12px;line-height:1.8}

.field{margin-bottom:22px}
.field>label{display:block;margin-bottom:9px;color:var(--forest);font-size:12px;font-weight:800}

.field select{
    width:100%;min-height:48px;padding:12px;
    border:1px solid var(--line);border-radius:10px;
    background:white;color:var(--ink);outline:none
}

.field select:focus{border-color:var(--green);box-shadow:0 0 0 3px #16865318}

.id-tag{
    display:inline-block;margin-left:6px;padding:2px 8px;border-radius:20px;
    background:#eef3ee;color:#66756a;font-size:10px;font-weight:700;vertical-align:middle
}
.id-tag.required{background:var(--mint);color:var(--dark)}

.id-box{
    padding:14px;border:1px dashed #b9d4c1;border-radius:12px;
    background:#f8fbf7;text-align:center
}
.id-empty{padding:16px 0 4px;color:#7c8a7f;font-size:12px}
.id-preview img{
    display:block;width:100%;max-height:230px;object-fit:contain;
    border:1px solid var(--line);border-radius:10px;background:white
}

.id-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:12px}

.id-btn{
    padding:11px 16px;border:1px solid var(--green);border-radius:10px;
    background:var(--green);color:white;font-size:12px;font-weight:800;cursor:pointer
}
.id-btn:hover{background:var(--dark)}
.id-btn.light{background:white;color:var(--dark)}
.id-btn.light:hover{background:var(--mint)}
.id-btn.remove{border-color:#f0c9c3;background:white;color:#a52626}
.id-btn.remove:hover{background:#fff3f1}

/* Photo quality result shown under each photo */
.id-status{
    margin-top:9px;padding:10px 13px;border:1px solid transparent;
    border-radius:10px;font-size:12px;line-height:1.7
}
.id-status.checking{background:#f1f6f2;border-color:#dce6dc;color:var(--muted)}
.id-status.ok{background:#e8f6ec;border-color:#b8e7c8;color:#0b5c39;font-weight:700}
.id-status.error{background:#fff0ed;border-color:#f2d2cc;color:#a52626}
.id-status.warn{background:#fff8e6;border-color:#f0dfa8;color:#835800}

.id-preview[hidden],.id-empty[hidden],.id-btn[hidden],.id-status[hidden]{display:none !important}

.privacy{margin:4px 0 0;color:#718075;font-size:11px;line-height:1.7}

.submit-btn{
    margin-top:20px;width:100%;min-height:50px;padding:14px;
    border:0;border-radius:12px;background:var(--dark);
    color:white;font-weight:800;cursor:pointer
}
.submit-btn:hover{background:var(--forest)}

@media(max-width:560px){
    .topbar{padding:14px 16px}
    .brand-name{font-size:18px}
    .top-link{padding:9px 11px;font-size:11px}
    .status-card{flex-direction:column;gap:14px}
    .thumb{width:calc(50% - 6px)}
    .id-actions{flex-direction:column}
    .id-btn{width:100%;min-height:46px}
    .field select{font-size:16px}
    .action-link{width:100%;justify-content:center}
}
</style>
</head>

<body>

<header class="topbar">
    <a href="plan.php" class="brand" aria-label="Back to dashboard">
        <div class="brand-mark">T</div>
        <div class="brand-name">TerminalHub</div>
    </a>

    <div class="top-actions">
        <a href="plan.php" class="top-link">&larr; Dashboard</a>
        <a href="logout.php" class="top-link">Log out</a>
    </div>
</header>

<main class="wrap">

    <div class="eyebrow">YOUR ACCOUNT</div>
    <h1>ID verification</h1>
    <p class="intro">
        TerminalHub checks every passenger's ID before trips can be booked.
        This keeps passengers and drivers safe.
    </p>

    <?php if ($success !== ''): ?>
        <div class="notice success" role="status"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$loadFailed): ?>

        <!-- CURRENT STATUS -->
        <section class="status-card <?= e($statusKey) ?>">

            <div class="status-icon" aria-hidden="true">
                <?php if ($status === 'Approved'): ?>&#10003;
                <?php elseif ($status === 'Rejected'): ?>!
                <?php elseif ($status === 'Pending'): ?>&hellip;
                <?php else: ?>ID
                <?php endif; ?>
            </div>

            <div class="status-body">

                <span class="status-pill"><?= e(getIdStatusLabel($status)) ?></span>

                <?php if ($status === 'Approved'): ?>

                    <h2>Your ID is verified</h2>
                    <p>
                        Your <?= e($latest['id_type']) ?> was accepted
                        <?= $latest['reviewed_at'] ? 'on ' . e(formatIdDate($latest['reviewed_at'])) : '' ?>.
                        You can now book your trips.
                    </p>

                    <a href="plan.php" class="action-link">Go to dashboard &rarr;</a>

                <?php elseif ($status === 'Pending'): ?>

                    <h2>Your ID is under review</h2>
                    <p>
                        Your <?= e($latest['id_type']) ?> was submitted on
                        <?= e(formatIdDate($latest['submitted_at'])) ?>.
                        Staff will check it soon and you will receive a notification.
                        You can book trips once it is accepted.
                    </p>

                <?php elseif ($status === 'Rejected'): ?>

                    <h2>Your ID was not accepted</h2>
                    <p>Please submit a new, clearer photo of your ID below.</p>

                    <?php if (!empty($latest['rejection_reason'])): ?>
                        <div class="reason-box">
                            <strong>REASON FROM STAFF</strong>
                            <p><?= nl2br(e($latest['rejection_reason'])) ?></p>
                        </div>
                    <?php endif; ?>

                <?php else: ?>

                    <h2>You have not submitted an ID yet</h2>
                    <p>
                        Submit a valid ID below. You can book trips after staff
                        accepts it.
                    </p>

                <?php endif; ?>

                <?php if ($latest && in_array($status, ['Pending', 'Rejected'], true)): ?>
                    <div class="thumbs">
                        <a class="thumb"
                           href="id_image.php?id=<?= (int) $latest['id'] ?>&amp;side=front"
                           target="_blank" rel="noopener">
                            <img src="id_image.php?id=<?= (int) $latest['id'] ?>&amp;side=front"
                                 alt="Front of your submitted ID">
                            <span>Front</span>
                        </a>

                        <?php if (!empty($latest['back_image'])): ?>
                            <a class="thumb"
                               href="id_image.php?id=<?= (int) $latest['id'] ?>&amp;side=back"
                               target="_blank" rel="noopener">
                                <img src="id_image.php?id=<?= (int) $latest['id'] ?>&amp;side=back"
                                     alt="Back of your submitted ID">
                                <span>Back</span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </section>

        <!-- SUBMIT FORM (only when no ID yet, or the last one was rejected) -->
        <?php if ($canSubmit): ?>

            <section class="form-card">

                <h2><?= $status === 'Rejected' ? 'Submit a new ID' : 'Submit your ID' ?></h2>

                <p class="help">
                    Take a photo of a valid ID or upload one from your phone
                    (JPG, JPEG or PNG, up to 5 MB). Every photo is checked for
                    quality before it is sent to staff.
                </p>

                <div class="tips">
                    <strong>Tips for a photo that is accepted</strong>
                    <ul>
                        <li>Place the ID on a flat surface in good, even light.</li>
                        <li>Fill most of the photo with the ID and show all four corners.</li>
                        <li>Hold the phone steady and tap the screen to focus.</li>
                        <li>Avoid glare, shadows and the camera flash.</li>
                    </ul>
                </div>

                <form id="idForm" action="verify_id.php" method="POST" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['verify_id_csrf_token']) ?>"
                    >

                    <div class="field">
                        <label for="id_type">ID type</label>

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

                    <p class="privacy">
                        Your ID photos are stored privately and are only viewed by
                        TerminalHub staff to verify your identity. The photo check
                        only looks at image quality. Staff still verify that your
                        ID is genuine.
                    </p>

                    <button type="submit" class="submit-btn">Submit ID for review</button>

                </form>

            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>

<?php if ($canSubmit): ?>
<script>
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

/* FORM SUBMIT */
const idForm = document.getElementById('idForm');
const submitButton = idForm.querySelector('.submit-btn');
const submitText = submitButton.textContent;

idForm.addEventListener('submit', function (event) {

    if (idForm.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    const pickers = document.querySelectorAll('.id-picker');

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

    /* Prevent double clicks while the photos are uploading */
    idForm.dataset.submitting = '1';
    submitButton.textContent = 'Uploading, please wait...';
});

/* If the person comes back with the Back button, allow submitting again */
window.addEventListener('pageshow', function () {
    idForm.dataset.submitting = '0';
    submitButton.textContent = submitText;
});
</script>
<?php endif; ?>

</body>
</html>