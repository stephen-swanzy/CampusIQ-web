<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/security_archive.php';
require_once __DIR__ . '/../includes/security_restore_service.php';

requireLogin(['super_admin', 'school_admin']);
$currentUser = currentUser();
if (!in_array($currentUser['role'] ?? '', ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    die('Access Denied: Only administrators can access the cryptographic demonstration.');
}

$db = getDb();
$archiveService = new CampusSecurityArchive();
$restoreService = new CampusSecurityRestoreService();

$success = '';
$error = '';
$activeTab = $_POST['tab'] ?? ($_GET['tab'] ?? 'simulator');

// --- Simulator State Variables ---
$simPassword = trim($_POST['sim_password'] ?? ($_GET['sim_password'] ?? 'Student@2024'));
if ($simPassword === '') {
    $simPassword = 'Student@2024';
}
$simPepper = campusPasswordPepper();
$simHmac = hash_hmac('sha256', $simPassword, $simPepper);
$simHash1 = campusPasswordHash($simPassword);
$simHash2 = campusPasswordHash($simPassword); // Second hash of the exact same password to prove salt difference
$simSalt1 = substr($simHash1, 7, 22);
$simSalt2 = substr($simHash2, 7, 22);

$demoUsers = [];
try {
    $demoUsers = $db->query('SELECT id, name, email, role, password_hash FROM users ORDER BY id ASC LIMIT 5')->fetchAll();
} catch (\Throwable $t) {}

$verifyInput = $_POST['verify_input'] ?? '';
$verifyResult = null;
$verifyTime = null;

// --- Handle Form Submissions ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    // 1. Password Verification Test
    if ($action === 'verify_test') {
        $activeTab = 'simulator';
        $targetHash = $_POST['target_hash'] ?? '';
        $start = microtime(true);
        [$matches, $needsRehash] = campusVerifyPassword($verifyInput, $targetHash);
        $verifyTime = round((microtime(true) - $start) * 1000, 2);
        $verifyResult = $matches;
        if ($matches) {
            $success = "Verification SUCCESS: Password matches hash! (Calculated in {$verifyTime} ms)";
        } else {
            $error = "Verification FAILED: Password does not match the provided hash. (Checked in {$verifyTime} ms)";
        }
    }

    // 2. Archive / Delete User Demo
    elseif ($action === 'archive_user') {
        $activeTab = 'deleted_users';
        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['deletion_reason'] ?? 'Assignment demonstration user deletion');
        $currentUser = currentUser();
        $deletedBy = ($currentUser['name'] ?? 'Admin') . ' (' . ($currentUser['role'] ?? 'admin') . ')';

        if ($userId <= 0) {
            $error = 'Please select a valid user to archive.';
        } else {
            $res = $archiveService->archiveUser($userId, $deletedBy, $reason);
            if ($res['success']) {
                $success = $res['message'];
            } else {
                $error = $res['message'];
            }
        }
    }

    // 3. Restore Archived User
    elseif ($action === 'restore_user') {
        $activeTab = 'deleted_users';
        $archiveId = (int)($_POST['archive_id'] ?? 0);
        $res = $archiveService->restoreUser($archiveId);
        if ($res['success']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }

    // 4. Purge Archived User Record
    elseif ($action === 'purge_user') {
        $activeTab = 'deleted_users';
        $archiveId = (int)($_POST['archive_id'] ?? 0);
        if ($archiveService->purgeUser($archiveId)) {
            $success = "Archive record #{$archiveId} permanently purged.";
        } else {
            $error = "Failed to purge record #{$archiveId}.";
        }
    }

    // 5. Restore Hashing Backend Files
    elseif ($action === 'restore_backend_files') {
        $activeTab = 'restore_files';
        $res = $restoreService->restoreFiles();
        if ($res['success']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }

    // 6. Simulate Tampering Test
    elseif ($action === 'simulate_tamper') {
        $activeTab = 'restore_files';
        $res = $restoreService->simulateTampering();
        if ($res['success']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    }
}

// Fetch current users for archive dropdown
$usersList = $db->query('SELECT id, name, email, role, status FROM users ORDER BY id ASC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);

// Fetch archived deleted users
$archivedUsers = $archiveService->getArchivedUsers();

// Fetch integrity status
$integrity = $restoreService->checkIntegrity();

// Page Header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="fa-solid fa-graduation-cap me-1"></i> Assignment Presentation Mode</span>
            <span class="badge bg-info text-dark fw-bold px-2 py-1"><i class="fa-solid fa-shield-halved me-1"></i> Cryptography Portal</span>
        </div>
        <h2 class="page-title mb-1">Security Architecture, Salting & Peppering Demonstration</h2>
        <p class="text-muted mb-0">Live interactive cryptography engine, secondary archive database for deleted users, and backend hashing file restoration.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard</a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
        <div><?php echo htmlspecialchars($success); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation fs-5 text-danger"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Assignment Notice Box -->
<div class="card mb-4 border-primary shadow-sm" style="background: rgba(13, 110, 253, 0.03);">
    <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="p-3 bg-primary text-white rounded-3">
                <i class="fa-solid fa-fingerprint fa-2x"></i>
            </div>
            <div>
                <h5 class="mb-1 text-primary fw-bold">Assignment Demonstration Features Overview</h5>
                <p class="small text-muted mb-0">
                    This portal addresses all requirements: 
                    <strong>(1)</strong> Secondary database / table for deleted users & restoration, 
                    <strong>(2)</strong> Interactive salting & peppering logic, 
                    <strong>(3)</strong> Academic notes on hashing algorithms, and 
                    <strong>(4)</strong> Integrity monitoring & one-click restoration of backend hashing files.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Tabs -->
<ul class="nav nav-pills mb-4 nav-fill gap-2 p-2 bg-light rounded-3 shadow-sm border" id="securityTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'simulator' ? 'active' : ''; ?>" id="tab-simulator-btn" data-bs-toggle="pill" data-bs-target="#tab-simulator" type="button" role="tab">
            <i class="fa-solid fa-microchip me-2"></i> 1. Salt & Pepper Simulator
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'deleted_users' ? 'active' : ''; ?>" id="tab-deleted-btn" data-bs-toggle="pill" data-bs-target="#tab-deleted" type="button" role="tab">
            <i class="fa-solid fa-database me-2"></i> 2. Deleted Users Archive & Restore
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'restore_files' ? 'active' : ''; ?>" id="tab-restore-btn" data-bs-toggle="pill" data-bs-target="#tab-restore" type="button" role="tab">
            <i class="fa-solid fa-file-shield me-2"></i> 3. Hashing Backend Integrity & Restore
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?php echo $activeTab === 'notes' ? 'active' : ''; ?>" id="tab-notes-btn" data-bs-toggle="pill" data-bs-target="#tab-notes" type="button" role="tab">
            <i class="fa-solid fa-book-bookmark me-2"></i> 4. Technical Reference & Notes
        </button>
    </li>
</ul>

<div class="tab-content" id="securityTabsContent">

    <!-- ========================================================================= -->
    <!-- TAB 1: SALT & PEPPER SIMULATOR -->
    <!-- ========================================================================= -->
    <div class="tab-pane fade <?php echo $activeTab === 'simulator' ? 'show active' : ''; ?>" id="tab-simulator" role="tabpanel">
        
        <!-- Live Generator Card -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-sliders text-primary me-2"></i> Live Password Hashing Pipeline</h5>
                <span class="badge bg-success-subtle text-success border border-success px-2 py-1">Engine Active</span>
            </div>
            <div class="card-body p-4">
                                <!-- Quick Preset / Form -->
                <form method="POST" action="security_demo.php" class="p-3 bg-light rounded-3 border mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="tab" value="simulator">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-dark mb-0">
                                <i class="fa-solid fa-flask-vial text-primary me-1"></i> Input Plaintext Password:
                            </label>
                        </div>
                        <div class="col-md-5">
                            <input type="text" name="sim_password" class="form-control form-control-sm font-monospace fw-bold" value="<?php echo htmlspecialchars($simPassword); ?>" required placeholder="Type any password to test...">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                                <i class="fa-solid fa-arrows-rotate me-1"></i> Update Table
                            </button>
                        </div>
                        <div class="col-md-2 text-end">
                            <span class="badge bg-danger text-white"><i class="fa-solid fa-lock me-1"></i> Admin Only</span>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top d-flex flex-wrap gap-2 align-items-center">
                        <small class="text-muted fw-bold">Quick Academic Presets:</small>
                        <button type="submit" name="sim_password" value="Student@2024" class="btn btn-outline-secondary btn-sm py-0 px-2 font-monospace">Student@2024</button>
                        <button type="submit" name="sim_password" value="Admin#2026!" class="btn btn-outline-secondary btn-sm py-0 px-2 font-monospace">Admin#2026!</button>
                        <button type="submit" name="sim_password" value="LecturerViva$99" class="btn btn-outline-secondary btn-sm py-0 px-2 font-monospace">LecturerViva$99</button>
                        <button type="submit" name="sim_password" value="Password123" class="btn btn-outline-secondary btn-sm py-0 px-2 font-monospace">Password123</button>
                    </div>
                </form>

                <!-- ========================================================================= -->
                <!-- TABLE 1: STEP-BY-STEP CRYPTOGRAPHIC PIPELINE (TABLE FORM) -->
                <!-- ========================================================================= -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold"><i class="fa-solid fa-table-list text-warning me-2"></i> Table 1: Password Salting &amp; Peppering Cryptographic Transformation Architecture</h6>
                            <small class="text-white-50">Detailed pipeline showing how plaintext transforms through application pepper, CSPRNG salt, and Bcrypt stretching</small>
                        </div>
                        <span class="badge bg-warning text-dark fw-bold px-2 py-1">Assignment Demonstration</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 12%;"><i class="fa-solid fa-layer-group me-1"></i> Security Stage</th>
                                    <th style="width: 18%;"><i class="fa-solid fa-tag me-1"></i> Component Name</th>
                                    <th style="width: 44%;"><i class="fa-solid fa-code me-1"></i> Actual Value / Output String</th>
                                    <th style="width: 26%;"><i class="fa-solid fa-circle-info me-1"></i> Cryptographic Role &amp; Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Row 0: Plaintext Password -->
                                <tr>
                                    <td><span class="badge bg-secondary px-2 py-1 fw-bold">Stage 0</span></td>
                                    <td class="fw-bold text-dark">
                                        <i class="fa-solid fa-key text-danger me-1"></i> User Plaintext Password
                                    </td>
                                    <td>
                                        <span class="font-monospace fw-bold text-danger fs-6 bg-light px-2 py-1 rounded border d-inline-block">
                                            <?php echo htmlspecialchars($simPassword); ?>
                                        </span>
                                        <small class="text-muted ms-2">(Length: <?php echo strlen($simPassword); ?> characters)</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">Memory Only</span>
                                        <small class="d-block text-muted mt-1">Never stored on disk or written to MySQL database.</small>
                                    </td>
                                </tr>

                                <!-- Row 1: Secret Pepper -->
                                <tr class="table-info bg-opacity-10">
                                    <td><span class="badge bg-info text-dark px-2 py-1 fw-bold">Stage 1</span></td>
                                    <td class="fw-bold text-dark">
                                        <i class="fa-solid fa-pepper-hot text-danger me-1"></i> Application Secret Pepper
                                    </td>
                                    <td>
                                        <div class="font-monospace small text-secondary bg-white p-2 rounded border text-break">
                                            <?php echo htmlspecialchars($simPepper); ?>
                                        </div>
                                        <small class="text-muted">Length: 64 hexadecimal characters (256-bit entropy)</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info">Server Environment Only</span>
                                        <small class="d-block text-muted mt-1">Kept in server environment. SQL dumps cannot crack hashes without this pepper.</small>
                                    </td>
                                </tr>

                                <!-- Row 2: HMAC-SHA256 -->
                                <tr>
                                    <td><span class="badge bg-info text-dark px-2 py-1 fw-bold">Stage 2</span></td>
                                    <td class="fw-bold text-dark">
                                        <i class="fa-solid fa-blender text-primary me-1"></i> Peppered HMAC-SHA256
                                    </td>
                                    <td>
                                        <div class="font-monospace small text-primary fw-semibold bg-light p-2 rounded border text-break">
                                            <?php echo htmlspecialchars($simHmac); ?>
                                        </div>
                                        <small class="text-muted"><code>hash_hmac('sha256', password, PEPPER)</code> &bull; Fixes 72-byte Bcrypt truncation</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary">Pre-Hash Buffer</span>
                                        <small class="d-block text-muted mt-1">Normalizes input into fixed 64-byte digest for Blowfish engine.</small>
                                    </td>
                                </tr>

                                <!-- Row 3: Unique Dynamic Salt -->
                                <tr class="table-warning bg-opacity-10">
                                    <td><span class="badge bg-warning text-dark px-2 py-1 fw-bold">Stage 3</span></td>
                                    <td class="fw-bold text-dark">
                                        <i class="fa-solid fa-cube text-warning me-1"></i> Dynamic Cryptographic Salt
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="font-monospace fw-bold text-dark bg-warning bg-opacity-25 p-2 rounded border border-warning text-break fs-6">
                                                <?php echo htmlspecialchars($simSalt1); ?>
                                            </span>
                                            <span class="badge bg-warning text-dark border">22 Base64 Chars</span>
                                        </div>
                                        <small class="text-muted">Generated freshly by CSPRNG (<code>random_bytes(16)</code>) per user hash</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning-subtle text-dark border border-warning">Embedded in Hash</span>
                                        <small class="d-block text-muted mt-1">Completely defeats pre-computed Rainbow Tables and dictionary attacks.</small>
                                    </td>
                                </tr>

                                <!-- Row 4: Final Stored Bcrypt Hash -->
                                <tr class="table-success bg-opacity-10">
                                    <td><span class="badge bg-success px-2 py-1 fw-bold">Stage 4</span></td>
                                    <td class="fw-bold text-dark">
                                        <i class="fa-solid fa-lock text-success me-1"></i> Final Stored Hash
                                    </td>
                                    <td>
                                        <div class="font-monospace small text-success fw-bold bg-white p-2 rounded border border-success text-break fs-6 mb-2">
                                            <?php echo htmlspecialchars($simHash1); ?>
                                        </div>
                                        <!-- Anatomy breakdown badges -->
                                        <div class="d-flex flex-wrap gap-1 font-monospace small">
                                            <span class="badge bg-dark" title="Hardened Blowfish algorithm">$2y$</span>
                                            <span class="badge bg-primary" title="Cost Factor (2^10 = 1024 rounds)">10$</span>
                                            <span class="badge bg-warning text-dark" title="Unique 22-char salt"><?php echo htmlspecialchars($simSalt1); ?></span>
                                            <span class="badge bg-success" title="Blowfish 31-char ciphertext"><?php echo htmlspecialchars(substr($simHash1, 29)); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-success-subtle text-success border border-success">Saved in MySQL</span>
                                        <small class="d-block text-muted mt-1">Column: <code>users.password_hash</code> (Exactly 60 chars)</small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- TABLE 2: RAINBOW TABLE IMMUNITY PROOF (SAME PASSWORD, 2 DIFFERENT SALTS) -->
                <!-- ========================================================================= -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-shield-virus text-warning me-2"></i> Table 2: Rainbow Table Defense Proof (Same Password Tested Twice)</h6>
                        <span class="badge bg-dark font-monospace">Tested Input: "<?php echo htmlspecialchars($simPassword); ?>"</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 15%;">Invocation</th>
                                    <th style="width: 25%;">Extracted Salt (22 chars)</th>
                                    <th style="width: 45%;">Generated Stored Hash</th>
                                    <th style="width: 15%;">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="badge bg-primary px-2 py-1">Hash Invocation A</span></td>
                                    <td><code class="text-primary fw-bold"><?php echo htmlspecialchars($simSalt1); ?></code></td>
                                    <td><span class="font-monospace small text-primary text-break"><?php echo htmlspecialchars($simHash1); ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success border border-success">Unique Salt A</span></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-danger px-2 py-1">Hash Invocation B</span></td>
                                    <td><code class="text-danger fw-bold"><?php echo htmlspecialchars($simSalt2); ?></code></td>
                                    <td><span class="font-monospace small text-danger text-break"><?php echo htmlspecialchars($simHash2); ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success border border-success">Unique Salt B</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-light small text-muted">
                        <i class="fa-solid fa-circle-check text-success me-1"></i> <strong>Academic Assessment Explanation:</strong> Notice that even though the plaintext password is identical, the two hashes share zero cryptographic similarity after the cost prefix. An attacker cannot use pre-computed lookup tables (Rainbow Tables) to crack passwords in bulk.
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- TABLE 3: REAL DATABASE USERS PASSWORD HASH INSPECTION TABLE -->
                <!-- ========================================================================= -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-users-viewfinder text-primary me-2"></i> Table 3: Live Database Users Cryptographic Inspection Table</h6>
                        <span class="badge bg-info text-dark">Live MySQL Records</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 6%;">#</th>
                                    <th style="width: 20%;">User Account</th>
                                    <th style="width: 12%;">System Role</th>
                                    <th style="width: 16%;">Runtime Pepper</th>
                                    <th style="width: 18%;">Extracted Dynamic Salt</th>
                                    <th style="width: 28%;">Stored Hash (users.password_hash)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($demoUsers)): ?>
                                    <?php foreach ($demoUsers as $du): 
                                        $dSalt = strlen($du['password_hash']) >= 29 ? substr($du['password_hash'], 7, 22) : 'N/A';
                                    ?>
                                        <tr>
                                            <td class="text-muted fw-bold"><?php echo (int)$du['id']; ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($du['name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($du['email']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-dark border text-capitalize">
                                                    <?php echo htmlspecialchars(str_replace('_', ' ', $du['role'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info-subtle text-info-emphasis border">
                                                    <i class="fa-solid fa-shield me-1"></i> Active Pepper
                                                </span>
                                            </td>
                                            <td>
                                                <code class="text-dark bg-warning-subtle px-2 py-1 rounded small border border-warning">
                                                    <?php echo htmlspecialchars($dSalt); ?>
                                                </code>
                                            </td>
                                            <td>
                                                <div class="font-monospace small text-break text-success">
                                                    <?php echo htmlspecialchars($du['password_hash']); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-muted">No database users found to display.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-light small text-muted">
                        <i class="fa-solid fa-lock text-primary me-1"></i> <strong>Security Assurance:</strong> User passwords are encrypted irreversibly. The database contains only the dynamic salt and Blowfish checksum; the plaintext password cannot be reversed even by database administrators.
                    </div>
                </div>

                <!-- ========================================================================= -->
                <!-- CARD 4: ACADEMIC ASSIGNMENT PRESENTATION & VIVA DEFENSE GUIDE -->
                <!-- ========================================================================= -->
                <div class="card border-primary border-opacity-25 shadow-sm rounded-4 mb-4" style="background: #f8fafc;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge bg-primary text-white px-2 py-1"><i class="fa-solid fa-graduation-cap me-1"></i> Assignment Guide</span>
                            <h5 class="mb-0 fw-bold text-dark">Cryptographic Defense Architecture - Presentation Notes</h5>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border h-100">
                                    <h6 class="fw-bold text-primary mb-2"><i class="fa-solid fa-pepper-hot text-danger me-2"></i>1. Why Use a Pepper? (Secret Key)</h6>
                                    <p class="small text-muted mb-0">
                                        If an attacker successfully dumps the SQL database (via SQL injection, backup leak, or compromised credentials), standard hashes can still be attacked offline with GPUs. The <strong>Pepper</strong> is stored in application environment configuration, entirely separate from the database. Without the server pepper, database hashes <strong>cannot</strong> be computed or brute-forced offline.
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border h-100">
                                    <h6 class="fw-bold text-primary mb-2"><i class="fa-solid fa-cube text-warning me-2"></i>2. Why Use a Salt? (Dynamic Entropy)</h6>
                                    <p class="small text-muted mb-0">
                                        A salt is a 22-character random string generated freshly for every password hash. If two users enter the exact same password (e.g. <code>Student@2024</code>), their hashes are completely different. This neutralizes <strong>Rainbow Table</strong> pre-computations and prevents attackers from cracking passwords in bulk.
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border h-100">
                                    <h6 class="fw-bold text-primary mb-2"><i class="fa-solid fa-blender text-info me-2"></i>3. Why HMAC-SHA256 Pre-Hashing?</h6>
                                    <p class="small text-muted mb-0">
                                        Native Bcrypt has a known limitation: it silently truncates input passwords longer than 72 bytes. CampusIQ runs <code>hash_hmac('sha256', $password, $pepper)</code> before passing it to Bcrypt. This solves the 72-byte truncation issue while binding the secret pepper securely before key expansion.
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border h-100">
                                    <h6 class="fw-bold text-primary mb-2"><i class="fa-solid fa-gauge-high text-success me-2"></i>4. Why Bcrypt Cost Factor 10?</h6>
                                    <p class="small text-muted mb-0">
                                        Bcrypt uses 2<sup>10</sup> = 1,024 iterative rounds of the Blowfish block cipher. This deliberate key stretching ensures each verification takes approximately 80–120ms, rendering hardware-accelerated GPU dictionary attacks computationally impossible.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Live Verification Tool -->
                <div class="mt-4 pt-4 border-top">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-spell-check text-success me-2"></i> Test Verification (campusVerifyPassword)</h6>
                    <form method="POST" action="security_demo.php" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="verify_test">
                        <input type="hidden" name="tab" value="simulator">
                        <input type="hidden" name="target_hash" value="<?php echo htmlspecialchars($simHash1); ?>">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Enter Password to Test Against Hash 1:</label>
                            <input type="text" name="verify_input" class="form-control" placeholder="Enter matching or non-matching password..." value="<?php echo htmlspecialchars($verifyInput ?: $simPassword); ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100 fw-bold">
                                <i class="fa-solid fa-check-double me-1"></i> Verify Match & Benchmark
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 2: DELETED USERS SECONDARY DATABASE & RESTORATION -->
    <!-- ========================================================================= -->
    <div class="tab-pane fade <?php echo $activeTab === 'deleted_users' ? 'show active' : ''; ?>" id="tab-deleted" role="tabpanel">
        
        <!-- Database Status Header -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card p-3 shadow-sm border-0 bg-white">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 bg-primary text-white rounded-circle"><i class="fa-solid fa-database fa-lg"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Primary Database Table</small>
                            <h6 class="mb-0 fw-bold">campusiq.deleted_users_archive</h6>
                            <span class="badge bg-success-subtle text-success border border-success mt-1">Active & Recording</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3 shadow-sm border-0 bg-white">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 bg-info text-white rounded-circle"><i class="fa-solid fa-server fa-lg"></i></div>
                        <div>
                            <small class="text-muted fw-semibold">Secondary Standalone Database</small>
                            <h6 class="mb-0 fw-bold">campusiq_archive.deleted_users</h6>
                            <span class="badge bg-info-subtle text-info border border-info mt-1">Synchronized Mirror</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Demonstration Action: Archive User -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-user-minus text-danger me-2"></i> Demonstrate User Deletion & Archival</h5>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small">
                    Select any active user to demonstrate the archival process. The system copies their complete credentials (including their salted & peppered password hash and TOTP 2FA configuration) into the secondary archive before removing/deactivating them in the active table.
                </p>
                <form method="POST" action="security_demo.php" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="archive_user">
                    <input type="hidden" name="tab" value="deleted_users">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Select User to Archive & Delete:</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">-- Choose User --</option>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    #<?php echo $u['id']; ?>: <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>) [<?php echo htmlspecialchars($u['role']); ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Deletion Reason:</label>
                        <input type="text" name="deletion_reason" class="form-control" value="Assignment demonstration archival" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-danger w-100 fw-bold" onclick="return confirm('Are you sure you want to archive and remove this user for demonstration? You can restore them with one click below.');">
                            <i class="fa-solid fa-box-archive me-1"></i> Archive & Delete User
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Archived Users Table & One-Click Restore -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Archived Deleted Users (Secondary Database)</h5>
                <span class="badge bg-secondary"><?php echo count($archivedUsers); ?> Archived Records</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($archivedUsers)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-folder-open fa-3x mb-3 text-secondary"></i>
                        <p class="mb-1 fw-bold">No deleted users in the secondary archive yet.</p>
                        <small>Use the form above to archive a test user, and they will appear here with one-click restoration.</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Archive ID</th>
                                    <th>User Details</th>
                                    <th>Role</th>
                                    <th>Preserved Password Hash</th>
                                    <th>Deleted Info</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedUsers as $arc): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark">#<?php echo $arc['id']; ?></span>
                                            <small class="d-block text-muted">Orig #<?php echo $arc['original_user_id']; ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($arc['name']); ?></div>
                                            <small class="text-muted font-monospace"><?php echo htmlspecialchars($arc['email']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($arc['role'])); ?></span>
                                        </td>
                                        <td>
                                            <span class="font-monospace small text-muted text-truncate d-inline-block" style="max-width: 180px;" title="<?php echo htmlspecialchars($arc['password_hash']); ?>">
                                                <?php echo htmlspecialchars(substr($arc['password_hash'], 0, 25)) . '...'; ?>
                                            </span>
                                            <small class="d-block text-success"><i class="fa-solid fa-lock me-1"></i> Salt Preserved</small>
                                        </td>
                                        <td>
                                            <small class="d-block text-muted"><i class="fa-regular fa-calendar me-1"></i> <?php echo htmlspecialchars($arc['deleted_at']); ?></small>
                                            <small class="d-block text-muted"><i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($arc['deleted_by'] ?? 'Admin'); ?></small>
                                            <small class="d-block text-info"><em>"<?php echo htmlspecialchars($arc['deletion_reason'] ?? 'N/A'); ?>"</em></small>
                                        </td>
                                        <td>
                                            <?php if ($arc['restored_at']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success">
                                                    <i class="fa-solid fa-check me-1"></i> Restored <?php echo date('M d, H:i', strtotime($arc['restored_at'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger">Archived</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <!-- One-Click Restore Form -->
                                                <form method="POST" action="security_demo.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                                                    <input type="hidden" name="action" value="restore_user">
                                                    <input type="hidden" name="tab" value="deleted_users">
                                                    <input type="hidden" name="archive_id" value="<?php echo $arc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success fw-bold" title="Restore this user back into active database">
                                                        <i class="fa-solid fa-rotate-left me-1"></i> Restore User
                                                    </button>
                                                </form>

                                                <!-- Permanent Purge Form -->
                                                <form method="POST" action="security_demo.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                                                    <input type="hidden" name="action" value="purge_user">
                                                    <input type="hidden" name="tab" value="deleted_users">
                                                    <input type="hidden" name="archive_id" value="<?php echo $arc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Permanently delete this archive record?');" title="Purge permanently">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 3: HASHING BACKEND FILES INTEGRITY & AUTO-RESTORE -->
    <!-- ========================================================================= -->
    <div class="tab-pane fade <?php echo $activeTab === 'restore_files' ? 'show active' : ''; ?>" id="tab-restore" role="tabpanel">
        
        <!-- System Status Banner -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-shield-virus text-primary me-2"></i> Hashing Backend Files Integrity Monitor</h5>
                <?php if ($integrity['all_intact']): ?>
                    <span class="badge bg-success fs-6 px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i> All Files 100% Pristine</span>
                <?php else: ?>
                    <span class="badge bg-danger fs-6 px-3 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> Integrity Warning: Alterations Detected</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-4">
                    The system continuously monitors the cryptographic hashing backend files (<code>includes/security.php</code> and <code>includes/auth.php</code>). If an attacker or corrupted script tampers with hashing logic or the secret pepper, the integrity check alerts administrators and allows instant one-click restoration from verified baseline signatures.
                </p>

                <!-- Monitored Files Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Backend File Path</th>
                                <th>Current SHA-256 Checksum</th>
                                <th>Baseline Golden Checksum</th>
                                <th>Status</th>
                                <th>Last Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($integrity['files'] as $relPath => $fInfo): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold font-monospace"><?php echo htmlspecialchars($relPath); ?></div>
                                        <div class="small text-muted mt-1">
                                            <strong>Monitored Functions:</strong> 
                                            <code><?php echo implode('</code>, <code>', array_slice($fInfo['critical_functions'], 0, 4)); ?>...</code>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="font-monospace small <?php echo $fInfo['is_intact'] ? 'text-success' : 'text-danger fw-bold'; ?> text-break">
                                            <?php echo htmlspecialchars($fInfo['current_hash']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-monospace small text-muted text-break">
                                            <?php echo htmlspecialchars($fInfo['baseline_hash']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($fInfo['is_intact']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                                <i class="fa-solid fa-check me-1"></i> Pristine
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger text-white px-2 py-1">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Altered / Corrupt
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($fInfo['last_modified']); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Action Controls: Restore & Simulate Tampering -->
                <div class="d-flex flex-wrap gap-3 p-3 bg-light rounded-3 border">
                    
                    <!-- One-Click Restore Button -->
                    <form method="POST" action="security_demo.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="restore_backend_files">
                        <input type="hidden" name="tab" value="restore_files">
                        <button type="submit" class="btn btn-success fw-bold btn-lg">
                            <i class="fa-solid fa-wrench me-1"></i> Restore Hashing Backend Files Now
                        </button>
                    </form>

                    <!-- Simulate Tampering Button (For Presentation Demonstration) -->
                    <form method="POST" action="security_demo.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="simulate_tamper">
                        <input type="hidden" name="tab" value="restore_files">
                        <button type="submit" class="btn btn-outline-warning text-dark fw-bold btn-lg" onclick="return confirm('This will append a simulated test alteration to includes/security.php to show the examiner that the integrity check detects tampering. You can restore it immediately using the Restore button. Proceed?');">
                            <i class="fa-solid fa-flask-vial me-1"></i> Simulate Tampering (Demo Test)
                        </button>
                    </form>

                </div>

                <!-- CLI Command Instruction -->
                <div class="mt-4 p-3 bg-dark text-light rounded-3 font-monospace small">
                    <div class="text-secondary mb-1"># Command Line Alternative (CLI): Run from terminal or Windows PowerShell</div>
                    <span class="text-warning">php</span> scripts/restore_security_files.php
                </div>

            </div>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 4: ACADEMIC NOTES ON HASHING ALGORITHM -->
    <!-- ========================================================================= -->
    <div class="tab-pane fade <?php echo $activeTab === 'notes' ? 'show active' : ''; ?>" id="tab-notes" role="tabpanel">
        
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-book text-primary me-2"></i> Academic Reference: CampusIQ Password Hashing Architecture</h5>
                <span class="badge bg-primary px-3 py-2">Examiner Notes</span>
            </div>
            <div class="card-body p-4">
                
                <!-- Section 1 -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary border-bottom pb-2">1. Fundamental Concepts: Hashing, Salting, and Peppering</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Security Primitive</th>
                                    <th>Storage Location</th>
                                    <th>Scope</th>
                                    <th>Cryptographic Defense Purpose</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-bold text-danger">Plain Hash (e.g., MD5 / SHA-256)</td>
                                    <td>Database (Direct)</td>
                                    <td>Global</td>
                                    <td>One-way representation. Highly vulnerable to dictionary attacks and pre-computed Rainbow Tables.</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold text-primary">Cryptographic Salt</td>
                                    <td>Database (Inside Hash String)</td>
                                    <td>Per-User Unique</td>
                                    <td>Prevents Rainbow Table lookup. Guarantees that two users with the same password have completely different hashes.</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold text-success">Application Pepper</td>
                                    <td>Server Environment (Outside DB)</td>
                                    <td>System-Wide Secret</td>
                                    <td>Protects passwords against SQL injection / database dumps. An attacker with the database dump still cannot crack hashes without the pepper.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 2 -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary border-bottom pb-2">2. Two-Tier Hybrid Architecture: HMAC-SHA256 + Bcrypt</h5>
                    <p class="text-muted">
                        CampusIQ utilizes a state-of-the-art hybrid design that combines the advantages of HMAC-SHA256 with Bcrypt key stretching:
                    </p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-arrow-right text-info me-2"></i> Tier 1: HMAC-SHA256 Pre-Hashing</h6>
                                <p class="small text-muted mb-2">
                                    <code>$hmac = hash_hmac('sha256', $password, $pepper);</code>
                                </p>
                                <ul class="small text-muted ps-3 mb-0">
                                    <li>Injects the secret server pepper before database storage.</li>
                                    <li><strong>Eliminates Bcrypt's 72-Byte Boundary:</strong> Bcrypt historically truncates passwords exceeding 72 bytes. Pre-hashing with HMAC-SHA256 normalizes arbitrary passwords (1 to 10,000 characters) into a fixed 64-character hex digest, eliminating this vulnerability.</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-arrow-right text-success me-2"></i> Tier 2: Bcrypt Key Stretching</h6>
                                <p class="small text-muted mb-2">
                                    <code>password_hash($hmac, PASSWORD_BCRYPT, ['cost' => 10]);</code>
                                </p>
                                <ul class="small text-muted ps-3 mb-0">
                                    <li>Generates a fresh 22-character cryptographically random salt per user.</li>
                                    <li>Applies <strong>1,024 rounds ($2^{10}$)</strong> of key expansion.</li>
                                    <li><strong>Memory-Hard:</strong> Forces attackers attempting GPU/ASIC brute forcing to allocate significant cache and computation per guess, making attacks millions of times slower.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3 -->
                <div class="mb-4">
                    <h5 class="fw-bold text-primary border-bottom pb-2">3. Mathematical & Computational Complexity Analysis</h5>
                    <p class="small text-muted">
                        The cost factor $C = 10$ defines $2^C = 2^{10} = 1024$ iterations of the Blowfish Eksblowfish setup. 
                        While standard SHA-256 can be computed over <strong>10,000,000,000 times per second</strong> on modern GPU rigs (Hashcat / RTX 4090), 
                        Bcrypt with cost 10 throttles candidate evaluations down to approximately <strong>10 to 15 guesses per second</strong> per core, 
                        rendering brute-force guessing economically and computationally infeasible.
                    </p>
                </div>

                <!-- Section 4 -->
                <div>
                    <h5 class="fw-bold text-primary border-bottom pb-2">4. Industry Standards & Compliance Alignment</h5>
                    <ul class="small text-muted ps-3 mb-0">
                        <li><strong>OWASP Password Storage Cheat Sheet:</strong> Recommends Bcrypt with work factor $\ge 10$ or Argon2id, combined with an application-level pepper.</li>
                        <li><strong>NIST Special Publication 800-63B:</strong> Recommends slow, salted, memory-hard key derivation functions for authentication secrets.</li>
                        <li><strong>Data Protection Regulations (GDPR & Ghana Data Protection Act 2012):</strong> Mandates pseudonymization and state-of-the-art cryptographic protection of personal authentication credentials.</li>
                    </ul>
                </div>

            </div>
        </div>

    </div>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
