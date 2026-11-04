<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin', 'school_admin']);

$db = getDb();
$settings = getSchoolSettings();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $schoolName = trim($_POST['school_name'] ?? '');
    $motto = trim($_POST['motto'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $currency = trim($_POST['currency'] ?? 'GH₵');
    $academicYearId = !empty($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : null;
    $termId = !empty($_POST['term_id']) ? (int)$_POST['term_id'] : null;
    $gradingSystem = trim($_POST['grading_system'] ?? 'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39');
    $paystackPublicKey = trim($_POST['paystack_public_key'] ?? '');
    $paystackSecretKey = trim($_POST['paystack_secret_key'] ?? '');
    $paystackTestMode = isset($_POST['paystack_test_mode']) ? 1 : 0;

    if (empty($schoolName)) {
        $error = 'School name cannot be empty.';
    } else {
        $logoPath = $settings['school_logo'] ?? 'assets/images/logo-placeholder.svg';

        // Handle logo upload
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../assets/images/';
            $ext = strtolower(pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];

            if (in_array($ext, $allowed)) {
                $newLogoName = 'school_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $uploadDir . $newLogoName)) {
                    $logoPath = 'assets/images/' . $newLogoName;
                }
            } else {
                $error = 'Invalid logo format. Please upload PNG, JPG, WEBP, or SVG.';
            }
        }

        if (empty($error)) {
            // Update or Insert
            $settingId = (int)($settings['id'] ?? 0);
            if ($settingId > 0) {
                $stmt = $db->prepare('UPDATE school_settings SET 
                    school_name = :school_name,
                    motto = :motto,
                    school_logo = :school_logo,
                    address = :address,
                    phone = :phone,
                    email = :email,
                    website = :website,
                    currency = :currency,
                    academic_year_id = :academic_year_id,
                    term_id = :term_id,
                    grading_system = :grading_system,
                    paystack_public_key = :paystack_public_key,
                    paystack_secret_key = :paystack_secret_key,
                    paystack_test_mode = :paystack_test_mode
                    WHERE id = :id');
                $stmt->execute([
                    'school_name' => $schoolName,
                    'motto' => $motto,
                    'school_logo' => $logoPath,
                    'address' => $address,
                    'phone' => $phone,
                    'email' => $email,
                    'website' => $website,
                    'currency' => $currency,
                    'academic_year_id' => $academicYearId,
                    'term_id' => $termId,
                    'grading_system' => $gradingSystem,
                    'paystack_public_key' => $paystackPublicKey ?: null,
                    'paystack_secret_key' => $paystackSecretKey ?: null,
                    'paystack_test_mode' => $paystackTestMode,
                    'id' => $settingId
                ]);
            } else {
                $stmt = $db->prepare('INSERT INTO school_settings (school_name, motto, school_logo, address, phone, email, website, currency, academic_year_id, term_id, grading_system, paystack_public_key, paystack_secret_key, paystack_test_mode) VALUES (:school_name, :motto, :school_logo, :address, :phone, :email, :website, :currency, :academic_year_id, :term_id, :grading_system, :paystack_public_key, :paystack_secret_key, :paystack_test_mode)');
                $stmt->execute([
                    'school_name' => $schoolName,
                    'motto' => $motto,
                    'school_logo' => $logoPath,
                    'address' => $address,
                    'phone' => $phone,
                    'email' => $email,
                    'website' => $website,
                    'currency' => $currency,
                    'academic_year_id' => $academicYearId,
                    'term_id' => $termId,
                    'grading_system' => $gradingSystem,
                    'paystack_public_key' => $paystackPublicKey ?: null,
                    'paystack_secret_key' => $paystackSecretKey ?: null,
                    'paystack_test_mode' => $paystackTestMode
                ]);
            }

            // Sync active academic year and term
            if ($academicYearId) {
                $db->query('UPDATE academic_years SET is_current = 0');
                $db->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id')->execute(['id' => $academicYearId]);
            }
            if ($termId) {
                $db->query('UPDATE terms SET is_current = 0');
                $db->prepare('UPDATE terms SET is_current = 1 WHERE id = :id')->execute(['id' => $termId]);
            }

            $settings = getSchoolSettings();
            $success = 'Institutional settings & branding updated successfully.';
        }
    }
}

$academicYears = $db->query('SELECT * FROM academic_years ORDER BY start_date DESC')->fetchAll();
$terms = $db->query('SELECT * FROM terms ORDER BY start_date DESC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-gear me-2"></i>Administration</p>
        <h2 class="fw-bold mb-0">Institutional Profile & Settings</h2>
        <small class="text-muted">Configure school branding, crest logo, currency, active sessions, and grading scales.</small>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-check fs-5 me-2"></i>
        <div><?php echo e($success); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4">
        <i class="fa-solid fa-triangle-exclamation fs-5 me-2"></i>
        <div><?php echo e($error); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card p-4 border-0 shadow-sm">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">

        <!-- Section 1: Branding & Identity -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-4"><i class="fa-solid fa-landmark text-primary me-2"></i>1. School Identity & Branding</h5>
        <div class="row g-4 mb-4">
            <div class="col-md-3 text-center">
                <label class="form-label fw-bold d-block">Current School Crest</label>
                <div class="p-3 bg-light rounded-3 border d-inline-block mb-2">
                    <?php if (!empty($settings['school_logo']) && file_exists(__DIR__ . '/../' . $settings['school_logo'])): ?>
                        <img src="<?php echo baseUrl() . '/' . e($settings['school_logo']); ?>" alt="Crest" style="max-height: 90px; max-width: 90px; object-fit: contain;">
                    <?php else: ?>
                        <div class="fs-1 text-primary"><i class="fa-solid fa-graduation-cap"></i></div>
                    <?php endif; ?>
                </div>
                <input type="file" name="school_logo" class="form-control form-control-sm mt-2" accept=".png,.jpg,.jpeg,.svg,.webp">
                <small class="text-muted d-block mt-1">Upload PNG or SVG logo</small>
            </div>
            <div class="col-md-9">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">School / Institution Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="school_name" value="<?php echo e($settings['school_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">School Motto / Slogan</label>
                        <input type="text" class="form-control" name="motto" value="<?php echo e($settings['motto'] ?? 'Excellence Through Education'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Official Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo e($settings['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Phone Contact</label>
                        <input type="text" class="form-control" name="phone" value="<?php echo e($settings['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Campus Address / Location</label>
                        <input type="text" class="form-control" name="address" value="<?php echo e($settings['address'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Financial & Session Settings -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-4 mt-2"><i class="fa-solid fa-calendar-days text-primary me-2"></i>2. Currency & Academic Session</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label fw-bold">Currency Symbol</label>
                <input type="text" class="form-control" name="currency" value="<?php echo e($settings['currency'] ?? 'GH₵'); ?>" placeholder="GH₵, $, €, etc.">
                <small class="text-muted">Symbol used across fee statements and invoices</small>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Active Academic Year</label>
                <select class="form-select" name="academic_year_id">
                    <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php echo ($year['is_current'] || (int)$settings['academic_year_id'] === (int)$year['id']) ? 'selected' : ''; ?>>
                            <?php echo e($year['name']); ?> <?php echo $year['is_current'] ? '(Current)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Active Term / Semester</label>
                <select class="form-select" name="term_id">
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term['id']; ?>" <?php echo ($term['is_current'] || (int)$settings['term_id'] === (int)$term['id']) ? 'selected' : ''; ?>>
                            <?php echo e($term['name']); ?> <?php echo $term['is_current'] ? '(Current)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Section 3: Grading Scale -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-4"><i class="fa-solid fa-square-poll-vertical text-primary me-2"></i>3. Academic Grading System</h5>
        <div class="mb-4">
            <label class="form-label fw-bold">Grading Ranges</label>
            <input type="text" class="form-control font-monospace" name="grading_system" value="<?php echo e($settings['grading_system'] ?? 'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39'); ?>">
            <small class="text-muted">Format: Grade:Min-Max comma separated (e.g. A:80-100,B:70-79,C:60-69,D:50-59,F:0-49)</small>
        </div>

        <!-- Section 4: Paystack Payment Gateway -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-4"><i class="fa-solid fa-credit-card text-primary me-2"></i>4. Paystack Payment Gateway</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-bold">Paystack Public Key</label>
                <input type="text" class="form-control font-monospace" name="paystack_public_key" value="<?php echo e($settings['paystack_public_key'] ?? ''); ?>" placeholder="pk_test_...">
                <small class="text-muted">Public key used on client-side checkout. Starts with <code>pk_test_</code> in Test Mode.</small>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Paystack Secret Key</label>
                <input type="password" class="form-control font-monospace" name="paystack_secret_key" value="<?php echo e($settings['paystack_secret_key'] ?? ''); ?>" placeholder="sk_test_..." autocomplete="off">
                <small class="text-muted">Kept strictly secret on server backend. Never exposed to browser code.</small>
            </div>
            <div class="col-12">
                <div class="form-check form-switch p-3 bg-light rounded-3 border">
                    <input class="form-check-input ms-0 me-3" type="checkbox" role="switch" id="paystackTestMode" name="paystack_test_mode" value="1" <?php echo !empty($settings['paystack_test_mode'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold text-dark" for="paystackTestMode">
                        <i class="fa-solid fa-flask-vial text-warning me-1"></i> Paystack Test Mode Enabled
                    </label>
                    <div class="small text-muted mt-1 ps-4">
                        When enabled, payments run in sandbox test mode using official Paystack test cards and Mobile Money simulators without charging real money.
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="p-3 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25 small">
                    <div class="fw-bold text-primary mb-1"><i class="fa-solid fa-circle-info me-1"></i>Paystack Webhook & Channels</div>
                    <div class="text-dark">Webhook URL: <code><?php echo e(absoluteUrl('/api/paystack_webhook.php')); ?></code></div>
                    <div class="text-muted mt-1">Supported Channels: MTN Mobile Money, Telecel Cash, AT Money (AirtelTigo), and Visa / Mastercard.</div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="index.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-2"></i>Save All Settings</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
