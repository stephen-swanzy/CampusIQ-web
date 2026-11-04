<?php
// System Report / Feedback Modal component for CampusIQ
if (!isLoggedIn()) {
    return;
}
$currentUser = currentUser();
?>
<!-- System Concern / Report Modal -->
<div class="modal fade" id="reportSystemConcernModal" tabindex="-1" aria-labelledby="reportSystemConcernLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 bg-light p-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        <i class="fa-solid fa-flag"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-1" id="reportSystemConcernLabel">Report System Concern / Suggestion</h5>
                        <p class="text-muted small mb-0">Help us improve CampusIQ. Your submission is sent directly to the Super Administration.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="systemReportForm" action="<?php echo baseUrl(); ?>/api/report_system_concern.php" method="POST" class="p-4 pt-2">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                <input type="hidden" name="page_url" id="reportPageUrl" value="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">

                <!-- Submitter Identity Pill -->
                <div class="mb-3 p-3 bg-light rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-user-circle text-primary fs-5"></i>
                        <span class="small text-dark fw-semibold"><?php echo e($currentUser['name'] ?? 'User'); ?></span>
                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill small"><?php echo e(userRoleLabel($currentUser['role'] ?? 'user')); ?></span>
                    </div>
                    <small class="text-muted"><i class="fa-solid fa-location-dot me-1"></i>Current Page: <code class="text-dark small"><?php echo e(basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard')); ?></code></small>
                </div>

                <div id="reportAlertBox" class="alert d-none mb-3" role="alert"></div>

                <div class="row g-3">
                    <!-- Report Category -->
                    <div class="col-md-6">
                        <label for="reportType" class="form-label fw-bold small text-dark">Category / Concern Type <span class="text-danger">*</span></label>
                        <select class="form-select rounded-3 py-2" id="reportType" name="report_type" required>
                            <option value="bug" selected>🐛 Bug or System Error (Something isn't working)</option>
                            <option value="feature_request">💡 Feature Request / Improvement Idea</option>
                            <option value="usability">🎨 Usability / Confusing Interface</option>
                            <option value="performance">⚡ Slow Page / Performance Lag</option>
                            <option value="general">💬 General Feedback or Question</option>
                        </select>
                    </div>

                    <!-- Priority Level -->
                    <div class="col-md-6">
                        <label for="reportPriority" class="form-label fw-bold small text-dark">Urgency / Priority <span class="text-danger">*</span></label>
                        <select class="form-select rounded-3 py-2" id="reportPriority" name="priority" required>
                            <option value="low">🟢 Low (Minor feedback / Small suggestion)</option>
                            <option value="medium" selected>🟡 Normal (Standard issue / Inconvenience)</option>
                            <option value="high">🟠 High (Impacting my daily work / Broken action)</option>
                            <option value="critical">🔴 Critical (Cannot proceed / Data discrepancy)</option>
                        </select>
                    </div>

                    <!-- Subject / Title -->
                    <div class="col-12">
                        <label for="reportSubject" class="form-label fw-bold small text-dark">Title / Summary <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3 py-2" id="reportSubject" name="subject" required minlength="3" maxlength="255" placeholder="e.g. Fee balance doesn't update on mobile screen, or Add SMS alert button">
                    </div>

                    <!-- Detailed Message -->
                    <div class="col-12">
                        <label for="reportMessage" class="form-label fw-bold small text-dark">Details / Description <span class="text-danger">*</span></label>
                        <textarea class="form-control rounded-3" id="reportMessage" name="message" rows="4" required minlength="5" placeholder="Describe the issue clearly: What happened? What did you expect to see? Any specific error message or steps to reproduce?"></textarea>
                    </div>
                </div>

                <div class="modal-footer border-0 p-0 mt-4 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 rounded-3 d-flex align-items-center gap-2 shadow-sm" id="btnSubmitReport">
                        <span class="submit-spinner spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <i class="fa-solid fa-paper-plane submit-icon"></i>
                        <span class="submit-label">Send to Super Admin</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reportForm = document.getElementById('systemReportForm');
    const alertBox = document.getElementById('reportAlertBox');
    const btnSubmit = document.getElementById('btnSubmitReport');
    const pageUrlInput = document.getElementById('reportPageUrl');

    if (pageUrlInput) {
        pageUrlInput.value = window.location.href;
    }

    if (reportForm && btnSubmit) {
        reportForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            // Set loading state
            btnSubmit.disabled = true;
            btnSubmit.querySelector('.submit-spinner').classList.remove('d-none');
            btnSubmit.querySelector('.submit-icon').classList.add('d-none');
            btnSubmit.querySelector('.submit-label').textContent = 'Submitting...';

            if (alertBox) {
                alertBox.className = 'alert d-none';
                alertBox.textContent = '';
            }

            try {
                const formData = new FormData(reportForm);
                const response = await fetch(reportForm.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    if (alertBox) {
                        alertBox.className = 'alert alert-success d-flex align-items-center gap-2';
                        alertBox.innerHTML = '<i class="fa-solid fa-circle-check fs-5"></i><div>' + data.message + '</div>';
                    }
                    reportForm.reset();
                    setTimeout(() => {
                        const modalEl = document.getElementById('reportSystemConcernModal');
                        if (modalEl && typeof bootstrap !== 'undefined') {
                            const modalInstance = bootstrap.Modal.getInstance(modalEl);
                            if (modalInstance) modalInstance.hide();
                        }
                        if (alertBox) alertBox.className = 'alert d-none';
                    }, 2200);
                } else {
                    if (alertBox) {
                        alertBox.className = 'alert alert-danger d-flex align-items-center gap-2';
                        alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation fs-5"></i><div>' + (data.error || 'Submission failed. Please check your inputs.') + '</div>';
                    }
                }
            } catch (err) {
                if (alertBox) {
                    alertBox.className = 'alert alert-danger d-flex align-items-center gap-2';
                    alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation fs-5"></i><div>Connection error. Please try again.</div>';
                }
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.querySelector('.submit-spinner').classList.add('d-none');
                btnSubmit.querySelector('.submit-icon').classList.remove('d-none');
                btnSubmit.querySelector('.submit-label').textContent = 'Send to Super Admin';
            }
        });
    }
});
</script>
