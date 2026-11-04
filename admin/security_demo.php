<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/security_archive.php';
require_once __DIR__ . '/../includes/security_restore_service.php';

requireLogin(['super_admin', 'school_admin']);

$db = getDb();
$archiveService = new CampusSecurityArchive();
$restoreService = new CampusSecurityRestoreService();

$success = '';
$error = '';
$activeTab = $_POST['tab'] ?? ($_GET['tab'] ?? 'simulator');

// --- Simulator State Variables ---
$simPassword = $_POST['sim_password'] ?? 'Student@2024';
$simPepper = campusPasswordPepper();
$simHmac = hash_hmac('sha256', $simPassword, $simPepper);
$simHash1 = campusPasswordHash($simPassword);
$simHash2 = campusPasswordHash($simPassword); // Second hash of the exact same password to prove salt difference

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
                <form method="POST" action="security_demo.php" class="row g-3 align-items-end mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="tab" value="simulator">
                    <div class="col-md-9">
                        <label class="form-label fw-bold">Enter Plaintext Password to Trace Cryptographic Transformation:</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light"><i class="fa-solid fa-key"></i></span>
                            <input type="text" name="sim_password" class="form-control font-monospace" value="<?php echo htmlspecialchars($simPassword); ?>" required>
                        </div>
                        <small class="text-muted">Type any sample password to watch HMAC-SHA256 peppering, 22-character salt generation, and Bcrypt stretching.</small>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                            <i class="fa-solid fa-bolt me-1"></i> Execute Pipeline
                        </button>
                    </div>
                </form>

                <!-- Step-by-Step Visualization -->
                <div class="row g-4">
                    
                    <!-- Step 1: Raw Input -->
                    <div class="col-12">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="badge bg-secondary fw-bold px-2 py-1">Stage 0</span>
                                <h6 class="mb-0 fw-bold text-dark">Original Plaintext Password</h6>
                                <span class="badge bg-light text-dark border font-monospace"><?php echo strlen($simPassword); ?> chars</span>
                            </div>
                            <div class="font-monospace p-2 bg-white rounded border text-danger fw-bold fs-6">
                                <?php echo htmlspecialchars($simPassword); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: The Server Pepper -->
                    <div class="col-12">
                        <div class="border rounded-3 p-3" style="background: rgba(13, 202, 240, 0.05); border-color: rgba(13, 202, 240, 0.3) !important;">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="badge bg-info text-dark fw-bold px-2 py-1">Stage 1: Server Pepper Injection</span>
                                <span class="text-info-emphasis fw-bold font-monospace">hash_hmac('sha256', password, PEPPER)</span>
                            </div>
                            <p class="small text-muted mb-2">
                                The <strong>Pepper</strong> is a secret 64-hex-character cryptographic key held strictly in the application environment (never in the database). An HMAC-SHA256 digest is generated, preventing database-dump decryption.
                            </p>
                            <div class="mb-2">
                                <label class="small text-muted fw-semibold">Application Secret Pepper (64 hex characters):</label>
                                <div class="font-monospace p-2 bg-white rounded border small text-break text-secondary">
                                    <?php echo htmlspecialchars($simPepper); ?>
                                </div>
                            </div>
                            <div>
                                <label class="small text-muted fw-semibold">Intermediate HMAC-SHA256 Pre-Hash (Normalized 64 characters):</label>
                                <div class="font-monospace p-2 bg-white rounded border text-break text-primary fw-semibold">
                                    <?php echo htmlspecialchars($simHmac); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Bcrypt Salting & Key Stretching -->
                    <div class="col-12">
                        <div class="border rounded-3 p-3" style="background: rgba(25, 135, 84, 0.05); border-color: rgba(25, 135, 84, 0.3) !important;">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="badge bg-success fw-bold px-2 py-1">Stage 2: Salt Generation & Bcrypt Key Stretching</span>
                                <span class="text-success fw-bold font-monospace">password_hash($hmac, PASSWORD_BCRYPT, ['cost' => 10])</span>
                            </div>
                            <p class="small text-muted mb-2">
                                PHP generates a unique <strong>22-character random salt</strong> per invocation and performs <strong>1,024 iterative rounds ($2^{10}$)</strong> of Blowfish expansion.
                            </p>
                            <div class="mb-2">
                                <label class="small text-muted fw-semibold">Resulting 60-Character Stored Hash (Saved in users.password_hash):</label>
                                <div class="font-monospace p-2 bg-white rounded border text-break text-success fw-bold fs-6">
                                    <?php echo htmlspecialchars($simHash1); ?>
                                </div>
                            </div>

                            <!-- Color Coded Hash Breakdown -->
                            <div class="mt-3 p-3 bg-white rounded border">
                                <label class="small fw-bold text-dark mb-2 d-block">Anatomy of the 60-Character Bcrypt String:</label>
                                <div class="d-flex flex-wrap gap-2 font-monospace fs-6">
                                    <span class="badge bg-dark p-2" title="Algorithm Identifier: $2y$ represents PHP's hardened Blowfish crypt implementation">
                                        <?php echo htmlspecialchars(substr($simHash1, 0, 4)); ?>
                                        <small class="d-block text-white-50 fw-normal">Algo ($2y$)</small>
                                    </span>
                                    <span class="badge bg-primary p-2" title="Work Factor: 10 indicates 2^10 = 1024 key expansion rounds">
                                        <?php echo htmlspecialchars(substr($simHash1, 4, 3)); ?>
                                        <small class="d-block text-white-50 fw-normal">Cost (10$)</small>
                                    </span>
                                    <span class="badge bg-warning text-dark p-2" title="Salt: 22 base64-style characters generated freshly per hash">
                                        <?php echo htmlspecialchars(substr($simHash1, 7, 22)); ?>
                                        <small class="d-block text-dark-50 fw-normal">Unique Salt (22 chars)</small>
                                    </span>
                                    <span class="badge bg-success p-2" title="Ciphertext Checksum: 31 characters derived from Blowfish encryption">
                                        <?php echo htmlspecialchars(substr($simHash1, 29)); ?>
                                        <small class="d-block text-white-50 fw-normal">Checksum (31 chars)</small>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Rainbow Table Defense Demonstration -->
                    <div class="col-12">
                        <div class="border rounded-3 p-3 bg-warning-subtle border-warning">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="fa-solid fa-shield-virus me-1"></i> Live Proof: Rainbow Table & Dictionary Attack Immunity</span>
                                <span class="badge bg-dark">Salting in Action</span>
                            </div>
                            <p class="small text-dark mb-2">
                                Below are <strong>two hashes generated for the EXACT same password ("<?php echo htmlspecialchars($simPassword); ?>")</strong> in the same request. Notice that the salts and resulting ciphertexts are completely different:
                            </p>
                            <div class="row g-2 font-monospace small">
                                <div class="col-md-6">
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted fw-bold d-block mb-1">Hash 1 (Fresh Salt A):</span>
                                        <span class="text-primary text-break"><?php echo htmlspecialchars($simHash1); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted fw-bold d-block mb-1">Hash 2 (Fresh Salt B):</span>
                                        <span class="text-danger text-break"><?php echo htmlspecialchars($simHash2); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="small text-muted mt-2">
                                <i class="fa-solid fa-circle-info text-primary me-1"></i> 
                                <strong>Why this matters to the examiner:</strong> Even if 100 users choose the password <code>Password123</code>, every single user has a completely unique hash in the database. Attackers cannot use pre-computed Rainbow Tables to invert passwords in bulk.
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
