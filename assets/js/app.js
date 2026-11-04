document.addEventListener('DOMContentLoaded', function () {
    // 1. Footer Year
    const currentYear = new Date().getFullYear();
    const footerYear = document.getElementById('footerYear');
    if (footerYear) {
        footerYear.textContent = currentYear;
    }

    // 2. Bootstrap Tooltips
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => {
            new bootstrap.Tooltip(element);
        });
    }

    // 3. Password Visibility Toggle
    const passwordToggleBtn = document.getElementById('passwordToggleBtn');
    const passwordInput = document.getElementById('loginPassword');
    const toggleIcon = document.getElementById('toggleIcon');

    if (passwordToggleBtn && passwordInput && toggleIcon) {
        passwordToggleBtn.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            toggleIcon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            passwordInput.focus();
        });
    }

    // 4. Live OTP 10-Minute Countdown Timer
    const otpCountdown = document.getElementById('otpCountdown');
    const otpTimerBox = document.getElementById('otpTimerBox');
    const resendBtn = document.getElementById('resendBtn');

    if (otpCountdown) {
        let totalSeconds = 600; // 10 minutes

        const updateTimer = () => {
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            otpCountdown.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

            if (totalSeconds <= 60 && otpTimerBox) {
                otpTimerBox.classList.add('expired');
            }

            if (totalSeconds <= 0) {
                clearInterval(timerInterval);
                otpCountdown.textContent = 'Expired';
                if (otpTimerBox) {
                    otpTimerBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Code Expired. Please request a new code.';
                }
                if (resendBtn) {
                    resendBtn.classList.remove('text-muted');
                    resendBtn.classList.add('btn-primary', 'text-white', 'px-3', 'py-1', 'rounded');
                }
            } else {
                totalSeconds--;
            }
        };

        updateTimer();
        const timerInterval = setInterval(updateTimer, 1000);
    }

    // 4b. Auto-clean 6-digit OTP code input (strips spaces, dashes, non-breaking spaces on input or paste)
    const codeInput = document.getElementById('code');
    if (codeInput) {
        const cleanCode = function () {
            codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
        };
        codeInput.addEventListener('input', cleanCode);
        codeInput.addEventListener('paste', function () {
            setTimeout(cleanCode, 10);
        });
    }

    // 5. Form Submit Loading State & Validation
    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', function (e) {
            const identityInput = document.getElementById('loginIdentity');
            const pwdInput = document.getElementById('loginPassword');
            const codeInput = document.getElementById('code');

            let hasError = false;

            if (identityInput && !identityInput.value.trim()) {
                identityInput.classList.add('is-invalid');
                identityInput.classList.remove('is-valid');
                hasError = true;
            }

            if (pwdInput && !pwdInput.value) {
                pwdInput.classList.add('is-invalid');
                pwdInput.classList.remove('is-valid');
                hasError = true;
            }

            if (codeInput && !codeInput.value.trim()) {
                codeInput.classList.add('is-invalid');
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();
                return;
            }

            // Show loading animation on button
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoading = submitBtn.querySelector('.btn-loading');
            if (btnText && btnLoading) {
                btnText.classList.add('d-none');
                btnLoading.classList.remove('d-none');
            }
            setTimeout(() => {
                submitBtn.disabled = true;
            }, 100);
        });

        // Real-time input check for email/username
        const identityInput = document.getElementById('loginIdentity');
        if (identityInput) {
            identityInput.addEventListener('input', function () {
                if (this.value.trim().length >= 3) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else if (this.value.trim().length === 0) {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            });
            identityInput.addEventListener('blur', function () {
                if (this.value.trim().length > 0 && this.value.trim().length < 3) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                }
            });
        }

        // Real-time input check for password
        if (passwordInput) {
            passwordInput.addEventListener('input', function () {
                if (this.value.length >= 1) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            });
        }
    }

    // 6. Live Topbar Digital Clock
    const headerLiveClock = document.getElementById('headerLiveClock');
    if (headerLiveClock) {
        const updateClock = () => {
            const now = new Date();
            const options = { 
                weekday: 'short', 
                month: 'short', 
                day: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit', 
                hour12: true 
            };
            headerLiveClock.textContent = now.toLocaleDateString('en-US', options);
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // 7. Global Search Keyboard Shortcut (Ctrl+K / Cmd+K)
    const globalSearch = document.getElementById('globalSearch');
    window.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            if (globalSearch) {
                e.preventDefault();
                globalSearch.focus();
                globalSearch.select();
            }
        }
    });

    // 8. Auto-Focus and Formatting for 6-Digit OTP Box
    const otpInput = document.getElementById('code');
    if (otpInput) {
        otpInput.addEventListener('input', function (e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            if (this.value.length === 6) {
                this.style.borderColor = '#10b981';
                this.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.18)';
            } else {
                this.style.borderColor = '';
                this.style.boxShadow = '';
            }
        });
    }

    // 9. Auto-close mobile offcanvas drawer on link click
    const sidebarNav = document.getElementById('sidebarNav');
    if (sidebarNav && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
        sidebarNav.querySelectorAll('.nav-item').forEach(link => {
            link.addEventListener('click', () => {
                const bsOffcanvas = bootstrap.Offcanvas.getInstance(sidebarNav);
                if (bsOffcanvas) {
                    bsOffcanvas.hide();
                }
            });
        });
    }
});
