<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();
$userId = (int)$user['id'];

$successMsg = '';
$errorMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_announcement') {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $audience = $_POST['audience'] ?? 'all';

        if (!in_array($audience, ['all', 'teachers', 'students', 'parents'])) {
            $audience = 'students';
        }

        if (empty($title) || empty($message)) {
            $errorMsg = 'Please provide an announcement title and message content.';
        } else {
            $stmt = $db->prepare('INSERT INTO announcements (title, message, author_id, audience, status) VALUES (:title, :message, :author_id, :audience, "published")');
            $stmt->execute([
                'title' => $title,
                'message' => $message,
                'author_id' => $userId,
                'audience' => $audience
            ]);
            $successMsg = 'Announcement broadcasted successfully!';
        }
    } elseif ($action === 'delete_announcement') {
        $annId = (int)($_POST['announcement_id'] ?? 0);
        $del = $db->prepare('DELETE FROM announcements WHERE id = :id AND author_id = :author_id');
        $del->execute(['id' => $annId, 'author_id' => $userId]);
        $successMsg = 'Announcement removed.';
    } elseif ($action === 'send_message') {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? 'Notification from Teacher');
        $body = trim($_POST['message'] ?? '');

        if ($receiverId <= 0 || empty($body)) {
            $errorMsg = 'Please select a recipient and enter your message.';
        } else {
            $ins = $db->prepare('INSERT INTO messages (sender_id, receiver_id, subject, message, is_read) VALUES (:sender, :receiver, :subject, :msg, 0)');
            $ins->execute([
                'sender' => $userId,
                'receiver' => $receiverId,
                'subject' => $subject,
                'msg' => $body
            ]);
            $successMsg = 'Message sent successfully!';
        }
    } elseif ($action === 'mark_read') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $db->prepare('UPDATE messages SET is_read = 1 WHERE id = :id AND receiver_id = :receiver')->execute([
            'id' => $msgId,
            'receiver' => $userId
        ]);
        $successMsg = 'Message marked as read.';
    }
}

// Fetch Announcements
$announcements = $db->query('
    SELECT a.*, u.name as author_name, u.role as author_role
    FROM announcements a
    JOIN users u ON u.id = a.author_id
    WHERE a.status = "published"
    ORDER BY a.created_at DESC
')->fetchAll();

// Fetch Messages - Inbox
$inboxStmt = $db->prepare('
    SELECT m.*, u.name as sender_name, u.email as sender_email, u.role as sender_role
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.receiver_id = :uid
    ORDER BY m.created_at DESC
');
$inboxStmt->execute(['uid' => $userId]);
$inbox = $inboxStmt->fetchAll();

// Fetch Messages - Sent
$sentStmt = $db->prepare('
    SELECT m.*, u.name as receiver_name, u.email as receiver_email, u.role as receiver_role
    FROM messages m
    JOIN users u ON u.id = m.receiver_id
    WHERE m.sender_id = :uid
    ORDER BY m.created_at DESC
');
$sentStmt->execute(['uid' => $userId]);
$sentMessages = $sentStmt->fetchAll();

// Fetch prospective message recipients (students, parents, admins, fellow teachers)
$recipients = $db->prepare('
    SELECT u.id, u.name, u.email, u.role
    FROM users u
    WHERE u.id != :uid AND u.status = "active"
    ORDER BY u.role ASC, u.name ASC
');
$recipients->execute(['uid' => $userId]);
$userList = $recipients->fetchAll();

$unreadCount = count(array_filter($inbox, fn($m) => !(bool)$m['is_read']));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-bullhorn me-2"></i>Institutional Hub</p>
        <h2 class="fw-bold mb-0">Communication & Notifications</h2>
        <small class="text-muted">Broadcast announcements to students and parents, or send private direct messages.</small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sendMessageModal">
            <i class="fa-solid fa-envelope me-2"></i>Compose Message
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
            <i class="fa-solid fa-plus me-2"></i>New Announcement
        </button>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5 me-2"></i>
        <div><?php echo e($successMsg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-triangle-exclamation fs-5 me-2"></i>
        <div><?php echo e($errorMsg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-4 gap-2" id="commTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active px-4 fw-semibold" id="announcements-tab" data-bs-toggle="tab" data-bs-target="#announcements-pane" type="button" role="tab">
            <i class="fa-solid fa-bullhorn me-2"></i>Announcements Board
            <span class="badge bg-primary ms-1"><?php echo count($announcements); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link px-4 fw-semibold" id="inbox-tab" data-bs-toggle="tab" data-bs-target="#inbox-pane" type="button" role="tab">
            <i class="fa-solid fa-inbox me-2"></i>Inbox
            <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger ms-1"><?php echo $unreadCount; ?> New</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-1"><?php echo count($inbox); ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link px-4 fw-semibold" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent-pane" type="button" role="tab">
            <i class="fa-solid fa-paper-plane me-2"></i>Sent Messages
            <span class="badge bg-secondary ms-1"><?php echo count($sentMessages); ?></span>
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="commTabsContent">

    <!-- Announcements Pane -->
    <div class="tab-pane fade show active" id="announcements-pane" role="tabpanel">
        <div class="row g-4">
            <?php if (empty($announcements)): ?>
                <div class="col-12">
                    <div class="card p-5 text-center border-0 shadow-sm">
                        <i class="fa-solid fa-bullhorn fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                        <h5>No Announcements Posted Yet</h5>
                        <p class="text-muted">Post circulars, test schedules, or classroom reminders using the "New Announcement" button.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($announcements as $a): 
                    $isAuthor = ((int)$a['author_id'] === $userId);
                ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 border-0 shadow-sm p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 text-capitalize">
                                        <i class="fa-solid fa-users me-1"></i>Audience: <?php echo e($a['audience']); ?>
                                    </span>
                                    <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($a['created_at'])); ?></small>
                                </div>
                                <h5 class="fw-bold text-dark mb-2"><?php echo e($a['title']); ?></h5>
                                <p class="text-secondary small mb-3" style="line-height: 1.6;"><?php echo nl2br(e($a['message'])); ?></p>
                            </div>
                            <div class="pt-3 border-top d-flex justify-content-between align-items-center mt-3">
                                <div class="small text-muted">
                                    <i class="fa-solid fa-user-pen me-1"></i><?php echo e($a['author_name']); ?>
                                    <span class="badge bg-light text-secondary ms-1"><?php echo e($a['author_role']); ?></span>
                                </div>
                                <?php if ($isAuthor): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this announcement?');">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?php echo $a['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inbox Pane -->
    <div class="tab-pane fade" id="inbox-pane" role="tabpanel">
        <div class="card p-0 border-0 shadow-sm overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sender</th>
                            <th>Subject & Preview</th>
                            <th>Received At</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inbox)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-inbox fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    Your inbox is empty. No messages received yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inbox as $msg): ?>
                                <tr class="<?php echo !(bool)$msg['is_read'] ? 'fw-bold table-light' : ''; ?>">
                                    <td>
                                        <div class="text-dark"><?php echo e($msg['sender_name']); ?></div>
                                        <small class="text-muted text-capitalize"><?php echo e($msg['sender_role']); ?> • <?php echo e($msg['sender_email']); ?></small>
                                    </td>
                                    <td>
                                        <div class="text-dark"><?php echo e($msg['subject'] ?: 'No Subject'); ?></div>
                                        <small class="text-muted text-truncate d-block" style="max-width: 400px;"><?php echo e($msg['message']); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-dark"><?php echo date('d M Y, h:i A', strtotime($msg['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!(bool)$msg['is_read']): ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-envelope me-1"></i>Unread</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Read</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewMsgModal_<?php echo $msg['id']; ?>">
                                            <i class="fa-solid fa-eye me-1"></i>Read
                                        </button>
                                    </td>
                                </tr>

                                <!-- View Message Modal -->
                                <div class="modal fade" id="viewMsgModal_<?php echo $msg['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content border-0 shadow">
                                            <div class="modal-header bg-light">
                                                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-envelope-open-text text-primary me-2"></i><?php echo e($msg['subject']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                                                    <div>
                                                        <strong>From:</strong> <?php echo e($msg['sender_name']); ?> (<?php echo e($msg['sender_email']); ?>)<br>
                                                        <strong>Role:</strong> <span class="badge bg-primary-subtle text-primary text-capitalize"><?php echo e($msg['sender_role']); ?></span>
                                                    </div>
                                                    <div class="text-end text-muted small">
                                                        <?php echo date('D, d M Y - h:i A', strtotime($msg['created_at'])); ?>
                                                    </div>
                                                </div>
                                                <div class="p-3 bg-light rounded text-dark" style="min-height: 120px; line-height: 1.7; font-size: 1rem;">
                                                    <?php echo nl2br(e($msg['message'])); ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light d-flex justify-content-between">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Mark as Read</button>
                                                </form>
                                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sendMessageModal" onclick="$('#replyReceiver').val('<?php echo $msg['sender_id']; ?>'); $('#replySubject').val('Re: <?php echo e($msg['subject']); ?>');">
                                                    <i class="fa-solid fa-reply me-1"></i>Reply
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sent Messages Pane -->
    <div class="tab-pane fade" id="sent-pane" role="tabpanel">
        <div class="card p-0 border-0 shadow-sm overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Recipient</th>
                            <th>Subject & Preview</th>
                            <th>Sent At</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sentMessages)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-paper-plane fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    You have not sent any private messages yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sentMessages as $msg): ?>
                                <tr>
                                    <td>
                                        <div class="text-dark fw-bold"><?php echo e($msg['receiver_name']); ?></div>
                                        <small class="text-muted text-capitalize"><?php echo e($msg['receiver_role']); ?> • <?php echo e($msg['receiver_email']); ?></small>
                                    </td>
                                    <td>
                                        <div class="text-dark fw-semibold"><?php echo e($msg['subject'] ?: 'No Subject'); ?></div>
                                        <small class="text-muted text-truncate d-block" style="max-width: 450px;"><?php echo e($msg['message']); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-dark"><?php echo date('d M Y, h:i A', strtotime($msg['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if ((bool)$msg['is_read']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fa-solid fa-check-double me-1"></i>Read by recipient</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-check me-1"></i>Delivered</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Create Announcement Modal -->
<div class="modal fade" id="createAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="create_announcement">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-bullhorn me-2"></i>Post Class Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Headline / Subject <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Science Fair Presentation Schedule & Guidelines" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Target Audience <span class="text-danger">*</span></label>
                    <select name="audience" class="form-select" required>
                        <option value="students">Students Only</option>
                        <option value="parents">Parents Only</option>
                        <option value="all" selected>All (Students, Parents & Staff)</option>
                        <option value="teachers">Teachers & Staff</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Announcement Content <span class="text-danger">*</span></label>
                    <textarea name="message" class="form-control" rows="5" placeholder="Write your announcement or memo details here..." required></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Broadcast Announcement</button>
            </div>
        </form>
    </div>
</div>

<!-- Send Message Modal -->
<div class="modal fade" id="sendMessageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="send_message">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-envelope-circle-check me-2"></i>Send Direct Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Recipient <span class="text-danger">*</span></label>
                    <select name="receiver_id" id="replyReceiver" class="form-select" required>
                        <option value="">Choose Student, Parent, or Staff Member...</option>
                        <?php 
                        $currRole = '';
                        foreach ($userList as $u): 
                            if ($currRole !== $u['role']) {
                                if ($currRole !== '') echo '</optgroup>';
                                $currRole = $u['role'];
                                echo '<optgroup label="' . ucfirst($currRole) . 's">';
                            }
                        ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo e($u['name']); ?> (<?php echo e($u['email']); ?>)</option>
                        <?php endforeach; 
                        if ($currRole !== '') echo '</optgroup>';
                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Subject</label>
                    <input type="text" name="subject" id="replySubject" class="form-control" placeholder="e.g. Inquiries regarding homework performance">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Message Content <span class="text-danger">*</span></label>
                    <textarea name="message" class="form-control" rows="5" placeholder="Write your message here..." required></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Send Message</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
