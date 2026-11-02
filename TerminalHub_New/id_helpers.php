<?php
/*
 * id_helpers.php
 * ------------------------------------------------------------------
 * Shared functions for the TerminalHub Passenger ID system.
 * Used by: login.php (register), verify_id.php, check_id_photo.php,
 *          id_image.php, plan.php, admin/id_verifications.php, staff pages.
 *
 * Put this file in the project ROOT (same folder as plan.php).
 *
 * PHOTO QUALITY CHECKS (what they can and cannot do)
 *   They CAN detect: too small, blank, too dark, too bright, blurry photos.
 *   They CANNOT prove an ID is real, or that the details are correct, or
 *   that the photo really shows an ID. Staff must always verify that.
 * ------------------------------------------------------------------
 */

/* Thrown when a photo is not good enough (nothing is saved in that case) */
class IdPhotoQualityException extends RuntimeException {}

const ID_MAX_UPLOAD_BYTES = 5242880;   // 5 MB per photo
const ID_MAX_PIXELS       = 30000000;  // reject unrealistically large images
const ID_MAX_SIDE_SAVED   = 2000;      // saved photos are scaled down to this

/* ---------------------------------------------------------------
   PHOTO QUALITY SETTINGS (you can fine-tune these later)
---------------------------------------------------------------- */
const ID_MIN_SHORT_SIDE      = 600;   // shorter side must be at least this many pixels
const ID_MIN_LONG_SIDE       = 800;   // longer side must be at least this many pixels
const ID_ANALYSIS_SIZE       = 800;   // photos are measured at this size
const ID_BLUR_MIN_SHARPNESS  = 90;    // below this = blurry (higher = stricter)
const ID_DARK_MAX_LEVEL      = 85;    // if even the brightest areas are below this = too dark
const ID_BRIGHT_MIN_MEAN     = 190;   // average brightness above this ...
const ID_BRIGHT_MAX_INK      = 0.001; // ... AND almost no dark pixels = washed out
const ID_BLANK_MAX_STDDEV    = 4;     // almost no variation at all = blank
const ID_BLANK_MIN_RANGE     = 12;    // almost no light/dark difference = blank
const ID_FLAT_MAX_STDDEV     = 15;    // a soft AND flat picture is treated as blank
const ID_QUALITY_DEBUG       = false; // true = write measured values to the PHP error log

/*
 * Where ID photos are stored. This folder is blocked from direct web access
 * with an .htaccess file (created automatically).
 */
if (!defined('ID_STORAGE_DIR')) {
    define('ID_STORAGE_DIR', __DIR__ . '/private_uploads/passenger_ids');
}

/* ---------------------------------------------------------------
   BASIC LISTS AND LABELS
---------------------------------------------------------------- */

/* ID types the passenger can choose from */
function getIdTypes(): array {
    return [
        'Philippine National ID (PhilSys)',
        "Driver's License",
        'Passport',
        'UMID',
        'Postal ID',
        'PRC ID',
        'Student ID',
        'Senior Citizen ID',
        'PWD ID',
        'Other Government-Issued ID'
    ];
}

/* Friendly text for each status */
function getIdStatusLabel(string $status): string {
    return match ($status) {
        'Approved' => 'Verified',
        'Pending'  => 'Under review',
        'Rejected' => 'Rejected',
        default    => 'Not submitted',
    };
}

/* ---------------------------------------------------------------
   STORAGE FOLDER
---------------------------------------------------------------- */

/* Creates the private folder and blocks direct web access to it */
function ensureIdStorage(): bool {
    $dir = ID_STORAGE_DIR;

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $rules = "<IfModule mod_authz_core.c>\n"
           . "    Require all denied\n"
           . "</IfModule>\n"
           . "<IfModule !mod_authz_core.c>\n"
           . "    Order allow,deny\n"
           . "    Deny from all\n"
           . "</IfModule>\n";

    foreach ([$dir, dirname($dir)] as $folder) {
        $htaccess = $folder . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, $rules);
        }
    }

    $index = $dir . '/index.html';

    if (!is_file($index)) {
        @file_put_contents($index, '');
    }

    return is_writable($dir);
}

/* Returns the full safe path of a stored photo, or null if the name is invalid */
function getIdFilePath(?string $filename): ?string {
    if ($filename === null || !preg_match('/^[a-f0-9]{32}\.jpg$/', $filename)) {
        return null;
    }

    return ID_STORAGE_DIR . '/' . $filename;
}

/* Deletes one stored photo (used when something fails) */
function deleteIdFile(?string $filename): void {
    $path = getIdFilePath($filename);

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/* ---------------------------------------------------------------
   READING A PASSENGER'S ID STATUS
---------------------------------------------------------------- */

/* The newest ID submission of a passenger (or null if none) */
function getLatestPassengerId(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, id_type, front_image, back_image, status,
                rejection_reason, reviewed_by, reviewed_at, submitted_at
         FROM passenger_ids
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );

    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/* 'None', 'Pending', 'Approved' or 'Rejected' */
function getPassengerIdStatus(PDO $pdo, int $userId): string {
    $latest = getLatestPassengerId($pdo, $userId);

    return $latest ? (string) $latest['status'] : 'None';
}

/* A passenger can only book when the newest ID is Approved */
function passengerCanBook(PDO $pdo, int $userId): bool {
    return getPassengerIdStatus($pdo, $userId) === 'Approved';
}

/* Number of IDs waiting for review (for the admin dashboard) */
function countPendingIdVerifications(PDO $pdo): int {
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM passenger_ids WHERE status = 'Pending'"
    )->fetchColumn();
}

/* ---------------------------------------------------------------
   UPLOAD HELPERS
---------------------------------------------------------------- */

function isIdFileProvided($file): bool {
    return is_array($file)
        && isset($file['error'])
        && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
}

function idUploadErrorMessage(int $code, string $label): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            "The photo of the {$label} is too large. Maximum size is 5 MB.",
        UPLOAD_ERR_PARTIAL =>
            "The photo of the {$label} was only partly uploaded. Please try again.",
        UPLOAD_ERR_NO_FILE =>
            "Please add a photo of the {$label}.",
        default =>
            "The photo of the {$label} could not be uploaded. Please try again.",
    };
}

/*
 * Checks that an upload is a real JPG/JPEG/PNG file and opens it.
 * Returns a GD image (already turned the right way up).
 * Throws RuntimeException with a friendly message if anything is wrong.
 */
function loadIdImageFromUpload(array $file, string $label) {

    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'The server cannot process images (PHP GD extension is off). Please contact the administrator.'
        );
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(idUploadErrorMessage($error, $label));
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException("The photo of the {$label} is not a valid upload.");
    }

    if ((int) ($file['size'] ?? 0) > ID_MAX_UPLOAD_BYTES) {
        throw new RuntimeException(
            "The photo of the {$label} is too large. Maximum size is 5 MB."
        );
    }

    /* The real file content is checked (the file name is never trusted) */
    $info = @getimagesize($tmp);

    if ($info === false) {
        throw new RuntimeException(
            "The file for the {$label} is not a valid image. Please use a JPG, JPEG or PNG photo."
        );
    }

    $width  = (int) $info[0];
    $height = (int) $info[1];
    $mime   = (string) ($info['mime'] ?? '');

    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException(
            "The photo of the {$label} must be a JPG, JPEG or PNG image."
        );
    }

    if ($width < 1 || $height < 1 || $width * $height > ID_MAX_PIXELS) {
        throw new RuntimeException(
            "The photo of the {$label} has too many pixels. Please use the camera option or a smaller photo."
        );
    }

    @ini_set('memory_limit', '512M');

    /* Opening the image also destroys any hidden code inside the file */
    $image = ($mime === 'image/jpeg')
        ? @imagecreatefromjpeg($tmp)
        : @imagecreatefrompng($tmp);

    if (!$image) {
        throw new RuntimeException(
            "The photo of the {$label} could not be read. Please try another photo."
        );
    }

    /* Fix phone photos that are saved sideways */
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated) {
                $image = $rotated;
            }
        }
    }

    return $image;
}

/* ---------------------------------------------------------------
   PHOTO QUALITY CHECK
---------------------------------------------------------------- */

/*
 * Measures a photo: brightness, contrast and sharpness.
 * The photo is shrunk to about 800 pixels first so the result does not
 * depend on how many megapixels the camera has.
 */
function measureIdImage($image): array {

    $sourceWidth  = imagesx($image);
    $sourceHeight = imagesy($image);

    $scale  = min(1, ID_ANALYSIS_SIZE / max($sourceWidth, $sourceHeight));
    $width  = max(8, (int) round($sourceWidth * $scale));
    $height = max(8, (int) round($sourceHeight * $scale));

    /* White background so transparent PNG areas do not look dark */
    $small = imagecreatetruecolor($width, $height);
    imagefilledrectangle(
        $small, 0, 0, $width, $height,
        imagecolorallocate($small, 255, 255, 255)
    );

    imagecopyresampled(
        $small, $image,
        0, 0, 0, 0,
        $width, $height,
        $sourceWidth, $sourceHeight
    );

    /* Convert to grey levels (0 = black, 255 = white) */
    $gray = [];
    $index = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($small, $x, $y);

            $gray[$index++] = (int) (
                (($rgb >> 16) & 255) * 0.299 +
                (($rgb >> 8) & 255) * 0.587 +
                ($rgb & 255) * 0.114
            );
        }
    }

    $total = $width * $height;

    /* Brightness and contrast */
    $histogram = array_fill(0, 256, 0);
    $sum = 0;
    $sumSquares = 0;

    foreach ($gray as $level) {
        $histogram[$level]++;
        $sum += $level;
        $sumSquares += $level * $level;
    }

    $mean = $sum / $total;
    $std  = sqrt(max(0, $sumSquares / $total - $mean * $mean));

    $percentile = static function (float $fraction) use ($histogram, $total): int {
        $target = $fraction * $total;
        $running = 0;

        for ($level = 0; $level < 256; $level++) {
            $running += $histogram[$level];

            if ($running >= $target) {
                return $level;
            }
        }

        return 255;
    };

    $darkPixels = 0;

    for ($level = 0; $level < 100; $level++) {
        $darkPixels += $histogram[$level];
    }

    /*
     * Sharpness: how strong the edges are (text and borders).
     * The picture is split into 4 x 4 tiles because an ID often fills only
     * part of the photo. The sharpest tiles decide. A small correction removes
     * the "grain" of noisy photos so noise is not mistaken for sharpness.
     */
    $tileSum = array_fill(0, 16, 0.0);
    $tileSquares = array_fill(0, 16, 0.0);
    $tileCount = array_fill(0, 16, 0);

    $columnTile = [];

    for ($x = 0; $x < $width; $x++) {
        $columnTile[$x] = intdiv($x * 4, $width);
    }

    $noiseTotal = 0.0;

    for ($y = 1; $y < $height - 1; $y++) {

        $rowBase = intdiv($y * 4, $height) * 4;
        $rowStart = $y * $width;

        for ($x = 1; $x < $width - 1; $x++) {

            $i = $rowStart + $x;

            $center = $gray[$i];
            $up = $gray[$i - $width];
            $down = $gray[$i + $width];
            $left = $gray[$i - 1];
            $right = $gray[$i + 1];

            $edge = 4 * $center - $up - $down - $left - $right;

            $noise = $gray[$i - $width - 1] - 2 * $up + $gray[$i - $width + 1]
                   - 2 * $left + 4 * $center - 2 * $right
                   + $gray[$i + $width - 1] - 2 * $down + $gray[$i + $width + 1];

            $noiseTotal += abs($noise);

            $tile = $rowBase + $columnTile[$x];
            $tileSum[$tile] += $edge;
            $tileSquares[$tile] += $edge * $edge;
            $tileCount[$tile]++;
        }
    }

    $noiseLevel = sqrt(M_PI / 2) * $noiseTotal / (6 * ($width - 2) * ($height - 2));
    $noiseCorrection = 20 * $noiseLevel * $noiseLevel;

    $tileSharpness = [];

    for ($t = 0; $t < 16; $t++) {
        if ($tileCount[$t] < 1) {
            $tileSharpness[] = 0.0;
            continue;
        }

        $tileMean = $tileSum[$t] / $tileCount[$t];
        $variance = $tileSquares[$t] / $tileCount[$t] - $tileMean * $tileMean;

        $tileSharpness[] = max(0.0, $variance - $noiseCorrection);
    }

    rsort($tileSharpness);

    $sharpness = array_sum(array_slice($tileSharpness, 0, 4)) / 4;

    return [
        'mean'      => $mean,
        'std'       => $std,
        'p95'       => $percentile(0.95),
        'range'     => $percentile(0.98) - $percentile(0.02),
        'ink'       => $darkPixels / $total,
        'sharpness' => $sharpness,
    ];
}

/* One short sentence for each problem the passenger can fix */
function idQualityProblemText(string $code): string {
    return match ($code) {
        'small'  => 'It is too small or low resolution (at least 800 x 600 pixels is needed). Move a little closer so the ID fills most of the photo.',
        'blank'  => 'It looks blank or unreadable. Make sure the whole ID is in the picture.',
        'dark'   => 'It is too dark. Move to a brighter place or turn on a light.',
        'bright' => 'It is too bright or washed out. Move away from glare and turn off the flash.',
        'blurry' => 'It is blurry or out of focus. Hold the phone steady and tap the screen to focus on the ID.',
        default  => 'It could not be checked.',
    };
}

/*
 * Checks the quality of one photo.
 * Returns: ['ok' => bool, 'problems' => [...], 'message' => string]
 */
function assessIdImageQuality($image, string $label): array {

    $width  = imagesx($image);
    $height = imagesy($image);

    $problems = [];

    if (min($width, $height) < ID_MIN_SHORT_SIDE || max($width, $height) < ID_MIN_LONG_SIDE) {
        $problems[] = 'small';
    }

    $m = measureIdImage($image);

    if ($m['std'] < ID_BLANK_MAX_STDDEV || $m['range'] < ID_BLANK_MIN_RANGE) {

        $problems[] = 'blank';

    } elseif ($m['p95'] < ID_DARK_MAX_LEVEL) {

        $problems[] = 'dark';

    } elseif ($m['mean'] > ID_BRIGHT_MIN_MEAN && $m['ink'] < ID_BRIGHT_MAX_INK) {

        $problems[] = 'bright';

    } elseif ($m['sharpness'] < ID_BLUR_MIN_SHARPNESS) {

        /* Sharpness is only judged when the lighting is acceptable */
        $problems[] = ($m['std'] < ID_FLAT_MAX_STDDEV) ? 'blank' : 'blurry';
    }

    if (ID_QUALITY_DEBUG) {
        error_log(sprintf(
            'ID photo quality [%s] %dx%d mean=%.1f std=%.1f p95=%d range=%d ink=%.4f sharp=%.1f problems=%s',
            $label, $width, $height,
            $m['mean'], $m['std'], $m['p95'], $m['range'], $m['ink'], $m['sharpness'],
            $problems ? implode(',', $problems) : 'none'
        ));
    }

    if (!$problems) {
        return ['ok' => true, 'problems' => [], 'message' => ''];
    }

    $sentences = array_map('idQualityProblemText', $problems);

    $message = "The photo of the {$label} was not accepted. "
             . implode(' ', $sentences)
             . ' Please take a new photo or upload another one.';

    return ['ok' => false, 'problems' => $problems, 'message' => $message];
}

/*
 * Checks an uploaded photo WITHOUT saving it (used while the passenger is
 * still choosing photos). Returns: ['ok' => bool, 'message' => string, 'problems' => [...]]
 */
function checkIdPhotoUpload(array $file, string $label): array {
    try {
        $image = loadIdImageFromUpload($file, $label);
        $result = assessIdImageQuality($image, $label);

        return [
            'ok'       => $result['ok'],
            'message'  => $result['ok'] ? 'This photo looks good.' : $result['message'],
            'problems' => $result['problems']
        ];

    } catch (RuntimeException $e) {
        return [
            'ok'       => false,
            'message'  => $e->getMessage(),
            'problems' => ['invalid']
        ];
    }
}

/* ---------------------------------------------------------------
   SAVING ID PHOTOS
---------------------------------------------------------------- */

/*
 * Checks one uploaded photo (file type, size AND quality), cleans it, and
 * saves it privately. Returns the new file name.
 * Throws IdPhotoQualityException if the photo quality is not good enough,
 * or RuntimeException for other problems. Nothing is saved when it throws.
 */
function storeIdImage(array $file, string $label): string {

    if (!ensureIdStorage()) {
        throw new RuntimeException(
            'The server cannot save ID photos right now. Please contact the administrator.'
        );
    }

    $source = loadIdImageFromUpload($file, $label);

    /* The quality check is repeated here on the server, every time */
    $quality = assessIdImageQuality($source, $label);

    if (!$quality['ok']) {
        throw new IdPhotoQualityException($quality['message']);
    }

    $sourceWidth  = imagesx($source);
    $sourceHeight = imagesy($source);

    $scale = min(1, ID_MAX_SIDE_SAVED / max($sourceWidth, $sourceHeight));
    $newWidth  = max(1, (int) round($sourceWidth * $scale));
    $newHeight = max(1, (int) round($sourceHeight * $scale));

    /* White background (handles PNG transparency) */
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    $white  = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);

    imagecopyresampled(
        $canvas, $source,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $sourceWidth, $sourceHeight
    );

    /* Save as a clean JPG with a random, unguessable name */
    $filename = bin2hex(random_bytes(16)) . '.jpg';
    $destination = ID_STORAGE_DIR . '/' . $filename;

    if (!@imagejpeg($canvas, $destination, 85)) {
        throw new RuntimeException(
            'The photo could not be saved. Please try again.'
        );
    }

    return $filename;
}

/*
 * Handles a whole ID submission (ID type + front photo + optional back photo).
 * Both photos must pass the quality check. Only then are they saved and a
 * "Pending" row is added to passenger_ids.
 * A photo that fails the check is NEVER saved and NEVER marked "Rejected".
 * Returns the new submission id.
 * Throws RuntimeException (friendly message) or PDOException.
 */
function processIdSubmission(PDO $pdo, int $userId, array $post, array $files): int {

    $idType = trim((string) ($post['id_type'] ?? ''));

    if (!in_array($idType, getIdTypes(), true)) {
        throw new RuntimeException('Please choose a valid ID type.');
    }

    $currentStatus = getPassengerIdStatus($pdo, $userId);

    if ($currentStatus === 'Pending') {
        throw new RuntimeException('Your ID is already being reviewed. Please wait for the result.');
    }

    if ($currentStatus === 'Approved') {
        throw new RuntimeException('Your ID has already been verified.');
    }

    $front = $files['id_front'] ?? null;

    if (!isIdFileProvided($front)) {
        throw new RuntimeException('Please take or upload a photo of the front of your ID.');
    }

    $frontName = null;
    $backName  = null;

    try {
        $frontName = storeIdImage($front, 'front of your ID');

        $back = $files['id_back'] ?? null;

        if (isIdFileProvided($back)) {
            $backName = storeIdImage($back, 'back of your ID');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO passenger_ids
                (user_id, id_type, front_image, back_image, status)
             VALUES (?, ?, ?, ?, 'Pending')"
        );

        $stmt->execute([$userId, $idType, $frontName, $backName]);

        return (int) $pdo->lastInsertId();

    } catch (Throwable $e) {
        /* Do not leave unused photos on the server */
        deleteIdFile($frontName);
        deleteIdFile($backName);

        throw $e;
    }
}

/* ---------------------------------------------------------------
   SHOWING A PHOTO (only call this AFTER checking who is logged in)
---------------------------------------------------------------- */

function outputIdImage(?string $filename): bool {
    $path = getIdFilePath($filename);

    if ($path === null || !is_file($path)) {
        return false;
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Disposition: inline; filename="id-photo.jpg"');

    readfile($path);

    return true;
}

/* ---------------------------------------------------------------
   NOTIFICATIONS AND REVIEW (admin / staff)
---------------------------------------------------------------- */

/* Adds a notification for a passenger (not linked to any booking) */
function addPassengerNotification(PDO $pdo, int $userId, string $title, string $message): void {
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, booking_id, title, message)
         VALUES (?, NULL, ?, ?)"
    );

    $stmt->execute([$userId, $title, $message]);
}

/*
 * Approves or rejects a Pending ID and notifies the passenger.
 * $decision must be 'Approved' or 'Rejected'.
 * A reason is required when rejecting.
 * Returns the passenger's user id.
 */
function reviewPassengerId(
    PDO $pdo,
    int $submissionId,
    string $decision,
    int $reviewerId,
    string $reason = ''
): int {

    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new RuntimeException('Invalid decision.');
    }

    $reason = trim($reason);

    if ($decision === 'Rejected' && $reason === '') {
        throw new RuntimeException('Please enter a reason for rejecting this ID.');
    }

    if (strlen($reason) > 500) {
        throw new RuntimeException('The reason must be 500 characters or fewer.');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, status
             FROM passenger_ids
             WHERE id = ?
             FOR UPDATE"
        );

        $stmt->execute([$submissionId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('This ID submission was not found.');
        }

        if ($row['status'] !== 'Pending') {
            throw new RuntimeException('This ID has already been reviewed.');
        }

        $update = $pdo->prepare(
            "UPDATE passenger_ids
             SET status = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?"
        );

        $update->execute([
            $decision,
            $decision === 'Rejected' ? $reason : null,
            $reviewerId,
            $submissionId
        ]);

        /*
         * The words "accepted" and "rejected" are used on purpose so these
         * notifications also appear under the Accepted / Rejected filters
         * of the notification panel in plan.php.
         */
        if ($decision === 'Approved') {
            $title = 'ID Accepted';
            $message = 'Your passenger ID has been accepted. You can now book your trips.';
        } else {
            $title = 'ID Rejected';
            $message = "Your passenger ID was rejected.\n\nReason: " . $reason
                     . "\n\nPlease submit a clearer photo from your dashboard.";
        }

        addPassengerNotification($pdo, (int) $row['user_id'], $title, $message);

        $pdo->commit();

        return (int) $row['user_id'];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}