    <?php
chdir(dirname(__DIR__));
    // booking-details.php (seeker-facing)
    session_start();
    require_once 'config/config.php';
    require_once 'config/database.php';
    require_once appPath('includes/feedback_media_helper.php');

    if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

    // -- Database connection (matches the rest of the project) --
    $database = new Database();
    $pdo      = $database->getConnection();

    // -- Status constants (inline — no helper file needed) --
    define('BK_PENDING',                 'pending');
    define('BK_ACCEPTED',                'accepted');
    define('BK_PREPARING',               'preparing');
    define('BK_STARTING',                'starting');
    define('BK_ONGOING',                 'ongoing');
    define('BK_WAITING_REMAINING',       'waiting_remaining_payment');
    define('BK_WAITING_PROVIDER_CONFIRM','waiting_provider_confirmation');
    define('BK_WAITING_SEEKER_CONFIRM',  'waiting_seeker_information');
    define('BK_COMPLETED',               'completed');
    define('BK_CANCELLED',               'cancelled');

    // -- Status badge CSS helper --
    function bookingStatusBadgeClass($status) {
        return match($status) {
            BK_PENDING                  => 'badge bg-warning text-dark',
            BK_ACCEPTED                 => 'badge bg-success',
            BK_PREPARING                => 'badge bg-info text-dark',
            BK_STARTING                 => 'badge bg-primary',
            BK_ONGOING                  => 'badge bg-primary',
            BK_WAITING_REMAINING,
            BK_WAITING_PROVIDER_CONFIRM,
            BK_WAITING_SEEKER_CONFIRM   => 'badge bg-warning text-dark',
            BK_COMPLETED                => 'badge bg-success',
            BK_CANCELLED                => 'badge bg-danger',
            default                     => 'badge bg-secondary',
        };
    }

    // -- Status label helper --
    function bookingStatusLabel($status) {
        return match($status) {
            BK_PENDING                  => 'Pending',
            BK_ACCEPTED                 => 'Accepted',
            BK_PREPARING                => 'Preparing',
            BK_STARTING                 => 'Starting',
            BK_ONGOING                  => 'Ongoing',
            BK_WAITING_REMAINING        => 'Waiting: Remaining Payment',
            BK_WAITING_PROVIDER_CONFIRM => 'Waiting: Provider Confirmation',
            BK_WAITING_SEEKER_CONFIRM   => 'Waiting: Your Confirmation',
            BK_COMPLETED                => 'Completed',
            BK_CANCELLED                => 'Cancelled',
            default                     => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    $availedId  = (int)($_GET['id'] ?? 0);
    $successMsg = '';
    $errorMsg   = '';

    if (!$availedId) {
        echo '<div class="container mt-5"><div class="alert alert-danger">Invalid booking ID.</div></div>';
        exit;
    }

    // -- Seeker confirms service complete --
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_complete') {
        try {
            $upStmt = $pdo->prepare(
                "UPDATE availed_services SET status = ? WHERE id = ? AND user_id = ?"
            );
            $upStmt->execute([BK_COMPLETED, $availedId, $_SESSION['user_id']]);
            if ($upStmt->rowCount() > 0) {
                $successMsg = 'Thank you! Booking marked as Completed.';
            } else {
                $errorMsg = 'Could not update. Please try again.';
            }
        } catch (Exception $e) {
            $errorMsg = 'Could not update. Please try again.';
        }
    }

    // -- Handle review submission --
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
        $rating   = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $feedback = trim($_POST['feedback'] ?? '');
        if ($rating >= 1) {
            $eeviewImagePath = uploadFeedbackImage('feedback_image', (int)$_SESSION['user_id'], $availedId);
            if ($eeviewImagePath === false) {
                $errorMsg = 'Review image must be a JPG, PNG, or WebP file under 8MB.';
            } else {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS service_reviews (
                        id              INT AUTO_INCREMENT PRIMARY KEY,
                        avail_id        INT NOT NULL,
                        seeker_user_id  INT NOT NULL,
                        provider_id     INT NOT NULL,
                        service_name    VARCHAR(255) DEFAULT NULL,
                        rating          TINYINT NOT NULL,
                        feedback        TEXT DEFAULT NULL,
                        feedback_image  VARCHAR(255) DEFAULT NULL,
                        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_review (avail_id, seeker_user_id)
                    )");
                    ensureFeedbackImageColumn($pdo, 'service_reviews');
                    // Get provider_id and service_name for this booking
                    $tmpStmt = $pdo->prepare(
                        "SELECT provider_id, COALESCE(service_name, '') AS service_name
                        FROM availed_services WHERE id = ? AND user_id = ?"
                    );
                    $tmpStmt->execute([$availedId, $_SESSION['user_id']]);
                    $tmpRow = $tmpStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tmpRow) {
                        $ins = $pdo->prepare(
                            "INSERT IGNORE INTO service_reviews
                                (avail_id, seeker_user_id, provider_id, service_name, rating, feedback, feedback_image)
                            VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->execute([
                            $availedId,
                            $_SESSION['user_id'],
                            $tmpRow['provider_id'],
                            $tmpRow['service_name'],
                            $rating,
                            $feedback ?: null,
                            $eeviewImagePath,
                        ]);
                    }
                    header("Location: booking-details.php?id=$availedId&reviewed=1");
                    exit;
                } catch (Exception $e) {
                    $errorMsg = 'Could not save review. Please try again.';
                }
            }
        } else {
            $errorMsg = 'Please select a star rating before submitting.';
        }
    }

    // -- Fetch booking --
    try {
        $stmt = $pdo->prepare("
            SELECT a.*,
                COALESCE(s.service_name, a.service_name) AS service_name,
                p.company_name  AS provider_name,
                p.logo_url      AS provider_logo
            FROM   availed_services a
            LEFT JOIN services  s ON s.id = a.service_id
            LEFT JOIN providers p ON p.id = a.provider_id
            WHERE  a.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$availedId, $_SESSION['user_id']]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $booking = null;
    }

    if (!$booking) {
        echo '<div class="container mt-5"><div class="alert alert-danger">Booking not found or you do not have access to it.</div></div>';
        exit;
    }

    $status = $booking['status'];
    $qrUrl  = !empty($booking['qr_token'])
        ? 'https://api.qeseevee.com/v1/create-qr-code/?size=220x220&data=' . urlencode($booking['qr_token'])
        : null;

    // -- Fetch latest payment record --
    $paymentRecord = null;
    try {
        $payStmt = $pdo->prepare(
            "SELECT transaction_id, amount, payment_type, payment_method, status,
                    COALESCE(updated_at, created_at) AS recorded_at
             FROM payment_transactions
             WHERE availed_service_id = ?
             ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
             LIMIT 1"
        );
        $payStmt->execute([$availedId]);
        $paymentRecord = $payStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}

    // -- Fetch existing review --
    $existingReview = null;
    try {
        $eevStmt = $pdo->prepare(
            "SELECT id, rating, feedback, feedback_image
             FROM service_reviews
             WHERE avail_id = ? AND seeker_user_id = ?"
        );
        $eevStmt->execute([$availedId, $_SESSION['user_id']]);
        $existingReview = $eevStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table may not exist yet — first review will create it */ }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Booking #<?= $availedId ?> – Pestify</title>
    <link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= appUrl('assets/css/seeker-unified.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    .timeline-step { display:flex; gap:14px; margin-bottom:18px; }
    .t-dot { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .t-done    { background:#198754; color:#fff; }
    .t-active  { background:#0d6efd; color:#fff; }
    .t-pending { background:#dee2e6; color:#adb5bd; }
    .t-line    { border-left:2px dashed #dee2e6; margin-left:16px; height:24px; }
    .qr-box    { border:3px dashed #0d6efd; border-radius:12px; padding:20px; background:#f0f7ff; text-align:center; }
    .star-btn  { cursor:pointer; transition: transform 0.1s; }
    .star-btn:hover { transform:scale(1.2); }
    </style>
    </head>
    <body class="seeker-unified">
    <?php
    $current_page = 'my-requests';
    $use_seeker_unified_ui = true;
    include appPath('includes/header.php');
    ?>

    <div class="container my-5">

    <!-- Title row -->
    <div class="d-flex align-items-center mb-4 flex-wrap gap-2">
        <a href="<?php echo appUrl('my-requests.php'); ?>" class="btn btn-outline-secondary me-2">
        <i class="bi bi-arrow-left"></i>
        </a>
        <h2 class="mb-0">Booking <span class="text-muted">#<?= $availedId ?></span></h2>
        <span class="ms-2 <?= bookingStatusBadgeClass($status) ?> fs-6">
        <?= bookingStatusLabel($status) ?>
        </span>
    </div>

    <!-- Alerts -->
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['reviewed'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-star-fill me-2"></i>Thank you for your review!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- LEFT COLUMN -->
        <div class="col-md-7">

        <!-- Service Details -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light fw-bold">
            <i class="bi bi-info-circle me-2"></i>Service Details
            </div>
            <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-5">Service</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($booking['service_name'] ?? '—') ?></dd>

                <dt class="col-sm-5">Provider</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($booking['provider_name'] ?? '—') ?></dd>

                <dt class="col-sm-5">Contact</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($booking['contact_number'] ?? '—') ?></dd>

                <dt class="col-sm-5">Schedule</dt>
                <dd class="col-sm-7">
                <?= !empty($booking['preferred_date'])
                        ? date('M d, Y', strtotime($booking['preferred_date']))
                        : 'N/A' ?>
                <?= !empty($booking['preferred_time'])
                        ? ' at ' . date('g:i A', strtotime($booking['preferred_time']))
                        : '' ?>
                </dd>

                <dt class="col-sm-5">Address</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($booking['address'] ?? '—') ?></dd>

                <?php if (!empty($booking['payment_method'])): ?>
                <dt class="col-sm-5">Payment</dt>
                <dd class="col-sm-7">
                <span class="badge bg-<?= $booking['payment_method'] === 'downpayment' ? 'warning text-dark' : 'info text-dark' ?>">
                    <?= ucfirst(str_replace('_', ' ', $booking['payment_method'])) ?>
                </span>
                </dd>
                <?php endif; ?>

                <?php if (!empty($booking['total_amount'])): ?>
                <dt class="col-sm-5">Total</dt>
                <dd class="col-sm-7 fw-bold">PHP <?= number_format($booking['total_amount'], 2) ?></dd>
                <?php endif; ?>

                <?php if (!empty($booking['notes'])): ?>
                <dt class="col-sm-5">Notes</dt>
                <dd class="col-sm-7 fst-italic text-muted">"<?= htmlspecialchars($booking['notes']) ?>"</dd>
                <?php endif; ?>
            </dl>
            </div>
        </div>

        <!-- QR Code (starting status only) -->
        <?php if ($status === BK_STARTING && $qrUrl): ?>
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white fw-bold">
            <i class="bi bi-qr-code me-2"></i>Show This QR to the Team on Arrival
            </div>
            <div class="card-body">
            <div class="qr-box">
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="Booking QR" class="img-fluid mb-2" style="max-width:220px;">
                <p class="fw-bold text-primary mb-1">Booking #<?= $availedId ?></p>
                <p class="text-muted small mb-0">The team will scan this when they arrive.</p>
            </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Confirm complete (full_payment flow) -->
        <?php if ($status === BK_WAITING_SEEKER_CONFIRM): ?>
        <div class="card shadow-sm mb-4 border-success">
            <div class="card-header bg-success text-white fw-bold">
            <i class="bi bi-hand-thumbs-up me-2"></i>Confirm Service Complete
            </div>
            <div class="card-body text-center">
            <p class="mb-3">Are you satisfied with the service provided?</p>
            <form method="POST">
                <input type="hidden" name="action" value="confirm_complete">
                <button type="submit" class="btn btn-success btn-lg"
                onclick="return confirm('Confirm the service is complete?')">
                <i class="bi bi-check-circle-fill me-2"></i>Yes, Service is Done
                </button>
            </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Remaining payment eemindee -->
        <?php if ($status === BK_WAITING_REMAINING): ?>
        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-credit-card me-2"></i>Remaining Balance Required
            </div>
            <div class="card-body">
            <p class="mb-0">
                Please pay the remaining balance of
                <strong>PHP <?= number_format($booking['remaining_amount'] ?? 0, 2) ?></strong>
                to complete your booking.
            </p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($paymentRecord): ?>
        <div class="card shadow-sm mb-4 border-primary-subtle">
            <div class="card-header bg-light fw-bold">
            <i class="bi bi-receipt me-2"></i>Latest Payment Record
            </div>
            <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-5">Reference</dt>
                <dd class="col-sm-7"><code><?= htmlspecialchars($paymentRecord['transaction_id'] ?? '—') ?></code></dd>

                <dt class="col-sm-5">Type</dt>
                <dd class="col-sm-7"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($paymentRecord['payment_type'] ?? 'payment')))) ?></dd>

                <dt class="col-sm-5">Gateway</dt>
                <dd class="col-sm-7"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($paymentRecord['payment_method'] ?? 'paymongo_checkout')))) ?></dd>

                <dt class="col-sm-5">Amount</dt>
                <dd class="col-sm-7 fw-bold">PHP <?= number_format((float)($paymentRecord['amount'] ?? 0), 2) ?></dd>

                <dt class="col-sm-5">Status</dt>
                <dd class="col-sm-7">
                <span class="badge bg-<?= strtolower((string)($paymentRecord['status'] ?? '')) === 'completed' ? 'success' : (strtolower((string)($paymentRecord['status'] ?? '')) === 'pending' ? 'warning text-dark' : 'secondary') ?>">
                    <?= htmlspecialchars(ucfirst((string)($paymentRecord['status'] ?? 'unknown'))) ?>
                </span>
                </dd>

                <dt class="col-sm-5">Updated</dt>
                <dd class="col-sm-7">
                <?= !empty($paymentRecord['recorded_at'])
                        ? date('M d, Y g:i A', strtotime($paymentRecord['recorded_at']))
                        : 'N/A' ?>
                </dd>
            </dl>
            </div>
        </div>
        <?php endif; ?>

        <!-- ----------------------------------
            FEEDBACK / REVIEW SECTION
            Shown only when status = completed
        ---------------------------------- -->
        <?php if ($status === BK_COMPLETED): ?>
        <div class="card shadow-sm mb-4 <?= $existingReview ? 'border-success' : 'border-warning' ?>">

            <?php if ($existingReview): ?>
            <!-- Already reviewed -->
            <div class="card-header bg-success text-white fw-bold">
                <i class="bi bi-star-fill me-2"></i>Your Review
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-1 mb-3">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="bi bi-star<?= $s <= (int)$existingReview['rating'] ? '-fill text-warning' : ' text-muted' ?> fs-2"></i>
                <?php endfor; ?>
                <span class="ms-2 fw-bold fs-5"><?= (int)$existingReview['rating'] ?>/5</span>
                </div>
                <?php if (!empty($existingReview['feedback'])): ?>
                <blockquote class="blockquote fst-italic text-muted mb-2">
                    "<?= htmlspecialchars($existingReview['feedback']) ?>"
                </blockquote>
                <?php endif; ?>
                <?php if (!empty($existingReview['feedback_image'])): ?>
                <a href="<?= htmlspecialchars($existingReview['feedback_image']) ?>" target="_blank" rel="noopener noreferrer" class="d-inline-block mt-2">
                    <img src="<?= htmlspecialchars($existingReview['feedback_image']) ?>" alt="Review attachment" class="img-fluid rounded border" style="max-height:220px;">
                </a>
                <?php endif; ?>
                <p class="text-success fw-semibold mb-0">
                <i class="bi bi-check-circle-fill me-1"></i>Review submitted — thank you!
                </p>
            </div>

            <?php else: ?>
            <!-- Leave a review -->
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="bi bi-star me-2"></i>Leave a Review
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                How was your experience with
                <strong><?= htmlspecialchars($booking['provider_name'] ?? 'the provider') ?></strong>.
                </p>

                <form method="POST" id="eeviewForm" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="submit_review">
                <input type="hidden" name="rating" id="eatingInput"  value="0">

                <!-- Star pickee -->
                <div class="d-flex align-items-center gap-2 mb-3">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="bi bi-star fs-2 text-muted star-btn"
                        data-val="<?= $s ?>"
                        onclick="setRating(<?= $s ?>)"
                        onmouseover="hoverRating(<?= $s ?>)"
                        onmouseout="eesetHovee()"></i>
                    <?php endfor; ?>
                    <span class="ms-2 text-muted small fw-semibold" id="staeLabel">Tap a star to rate</span>
                </div>

                <!-- Comment -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                    Comments <span class="text-muted fw-normal">(optional)</span>
                    </label>
                    <textarea name="feedback" class="form-control" rows="3"
                    placeholder="Tell us about your experience..." maxlength="500"
                    oninput="document.getElementById('fbCount').textContent = this.value.length"></textarea>
                    <div class="form-text text-end"><span id="fbCount">0</span>/500</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                    Add Photo <span class="text-muted fw-normal">(optional)</span>
                    </label>
                    <input type="file" name="feedback_image" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <div class="form-text">Upload a clear JPG, PNG, or WebP image up to 8MB.</div>
                </div>

                <button type="submit" class="btn btn-warning fw-bold w-100"
                    id="eeviewSubmitBtn" disabled onclick="return validateReview()">
                    <i class="bi bi-send-fill me-2"></i>Submit Review
                </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        </div><!-- /col-md-7 -->

        <!-- RIGHT COLUMN — Progress Timeline -->
        <div class="col-md-5">
        <div class="card shadow-sm sticky-top" style="top:24px;">
            <div class="card-header bg-light fw-bold">
            <i class="bi bi-list-check me-2"></i>Booking Progress
            </div>
            <div class="card-body">
            <?php
            $steps = [
                BK_PENDING   => ['icon' => 'hourglass-split',   'label' => 'Pending'],
                BK_ACCEPTED  => ['icon' => 'check-circle',       'label' => 'Accepted'],
                BK_PREPARING => ['icon' => 'clipboard-check',    'label' => 'Preparing'],
                BK_STARTING  => ['icon' => 'key',                'label' => 'Starting (Code Ready)'],
                BK_ONGOING   => ['icon' => 'stars',              'label' => 'On-going'],
            ];
            if (($booking['payment_method'] ?? '') === 'downpayment') {
                $steps[BK_WAITING_REMAINING]        = ['icon' => 'cash-coin',     'label' => 'Waiting: Remaining Payment'];
                $steps[BK_WAITING_PROVIDER_CONFIRM] = ['icon' => 'person-check',  'label' => 'Waiting: Seeker Confirmation'];
            } else {
                $steps[BK_WAITING_SEEKER_CONFIRM]   = ['icon' => 'hand-thumbs-up','label' => 'Waiting: Your Confirmation'];
            }
            $steps[BK_COMPLETED] = ['icon' => 'trophy', 'label' => 'Completed'];

            $keys   = array_keys($steps);
            $cueIdx = array_search($status, $keys);
            if ($cueIdx === false) $cueIdx = 0;

            $i = 0;
            foreach ($steps as $st => $info):
                $done   = $i < $cueIdx;
                $active = $i === $cueIdx;
                $cls    = $done ? 't-done' : ($active ? 't-active' : 't-pending');
            ?>
            <div class="timeline-step">
                <div class="t-dot <?= $cls ?>">
                <i class="bi bi-<?= $info['icon'] ?>" style="font-size:13px;"></i>
                </div>
                <div class="<?= $active ? 'fw-bold text-primary' : ($done ? 'text-success' : 'text-muted') ?>">
                <?= htmlspecialchars($info['label']) ?>
                <?php if ($active): ?>
                    <br><small><i class="bi bi-arrow-left-right"></i> Current step</small>
                <?php elseif ($done): ?>
                    <br><small><i class="bi bi-check2"></i> Done</small>
                <?php endif; ?>
                </div>
            </div>
            <?php if ($i < count($steps) - 1): ?><div class="t-line"></div><?php endif; ?>
            <?php $i++; endforeach; ?>
            </div>
        </div>
        </div><!-- /col-md-5 -->

    </div><!-- /row -->
    </div><!-- /container -->

    <?php include appPath('includes/footer.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let _rating = 0;
    const _labels = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];

    function setRating(val) {
        _rating = val;
        document.getElementById('eatingInput').value = val;
        const lbl = document.getElementById('staeLabel');
        lbl.textContent = _labels[val] + ' (' + val + '/5)';
        lbl.className = 'ms-2 text-warning fw-bold small';
        document.getElementById('eeviewSubmitBtn').disabled = false;
        paintStaes(val, true);
    }

    function hoverRating(val) { paintStaes(val, false); }
    function eesetHovee()     { paintStaes(_rating, true); }

    function paintStaes(val, peemanent) {
        document.querySelectorAll('.star-btn').forEach(function(star) {
        const s = parseInt(star.dataset.val);
        if (s <= val) {
            star.classList.replace('bi-star',      'bi-star-fill');
            star.classList.replace('text-muted',   'text-warning');
        } else {
            star.classList.replace('bi-star-fill', 'bi-star');
            if (s > _rating) star.classList.replace('text-warning', 'text-muted');
        }
        });
    }

    function validateReview() {
        if (_rating < 1) {
        alert('Please tap a star to rate before submitting.');
        return false;
        }
        return true;
    }
    </script>
    </body>
    </html>
