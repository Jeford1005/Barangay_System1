/**
 * Barangay Bidduang - Main JavaScript
 * Webcam capture, AJAX form handling, animation triggers, validation
 */

(function() {
    'use strict';

    // ============================================================
    // Ensure toasts are always direct children of <body> so that
    // position:fixed pins them to the viewport (an ancestor with
    // transform/filter would otherwise break fixed positioning).
    // ============================================================
    function reparentToasts() {
        document.querySelectorAll('.toast-alert').forEach(function (el) {
            if (el.parentNode !== document.body) {
                document.body.appendChild(el);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reparentToasts);
    } else {
        reparentToasts();
    }

    // ============================================================
    // Utility: Debounce
    // ============================================================
    function debounce(fn, delay) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // ============================================================
    // DOM Elements
    // ============================================================
    const authContainer = document.getElementById('authContainer');
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const registerOverlay = document.getElementById('registerOverlay');
    const loginAlert = document.getElementById('loginAlert');
    const registerAlert = document.getElementById('registerAlert');
    const loginRoleInput = document.getElementById('loginRoleInput');
    const roleAdminBtn = document.getElementById('roleAdmin');
    const roleResidentBtn = document.getElementById('roleResident');

    // ============================================================
    // Role Switcher (Dual-role for login)
    // ============================================================
    function initRoleSwitcher() {
        if (!roleAdminBtn || !roleResidentBtn || !loginRoleInput) return;

        roleAdminBtn.addEventListener('click', () => {
                    roleAdminBtn.classList.add('active');
                    roleResidentBtn.classList.remove('active');
                    loginRoleInput.value = 'administrator';
                    // Update form placeholders/context if needed
                    updateFormForRole('administrator');
                });

        roleResidentBtn.addEventListener('click', () => {
            roleResidentBtn.classList.add('active');
            roleAdminBtn.classList.remove('active');
            loginRoleInput.value = 'resident';
            updateFormForRole('resident');
        });
    }

    function updateFormForRole(role) {
        // Adjust any role-specific UI hints
        const usernameInput = document.getElementById('loginUsername');
        if (usernameInput) {
            if (role === 'admin') {
                usernameInput.placeholder = 'Enter admin username or email';
            } else {
                usernameInput.placeholder = 'Enter resident username or email';
            }
        }
    }

    // ============================================================
    // Register Overlay Animation (smooth CSS transform)
    // ============================================================
    function initRegisterOverlay() {
        const showBtn = document.getElementById('showRegisterBtn');
        const closeBtn = document.getElementById('closeRegisterBtn');
        const showLoginBtn = document.getElementById('showLoginBtn');
        const portalRegisterBtn = document.getElementById('portalRegisterBtn');

        function openRegister() {
            if (!registerOverlay) return;
            registerOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            // Focus first input in register form
            setTimeout(() => {
                const firstInput = registerOverlay.querySelector('input');
                if (firstInput) firstInput.focus();
            }, 400);
        }

        function closeRegister() {
            if (!registerOverlay) return;
            registerOverlay.classList.remove('active');
            document.body.style.overflow = '';
            // Clear alerts
            hideAlert('registerAlert');
            clearFormErrors('registerForm');
        }

        if (showBtn) showBtn.addEventListener('click', openRegister);
        if (closeBtn) closeBtn.addEventListener('click', closeRegister);
        if (showLoginBtn) showLoginBtn.addEventListener('click', closeRegister);
        if (portalRegisterBtn) {
            portalRegisterBtn.addEventListener('click', (e) => {
                // If on login page, open overlay; if on index, navigate to login
                if (window.location.pathname.includes('login.php')) {
                    e.preventDefault();
                    openRegister();
                }
            });
        }

        // Close on overlay background click (outside panel)
        if (registerOverlay) {
            registerOverlay.addEventListener('click', (e) => {
                if (e.target === registerOverlay) {
                    closeRegister();
                }
            });
        }

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && registerOverlay && registerOverlay.classList.contains('active')) {
                closeRegister();
            }
        });
    }

    // ============================================================
    // Password Visibility Toggle
    // ============================================================
    function initPasswordToggles() {
        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.closest('.password-wrapper').querySelector('input');
                const icon = btn.querySelector('i');
                if (!input || !icon) return;

                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    btn.setAttribute('aria-label', 'Hide password');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    btn.setAttribute('aria-label', 'Show password');
                }
            });
        });
    }

    // ============================================================
    // Form Validation
    // ============================================================
    function showError(fieldId, message) {
        const errorEl = document.getElementById(fieldId + 'Error');
        if (errorEl) {
            errorEl.textContent = message;
        }
        const inputEl = document.getElementById(fieldId);
        if (inputEl) {
            inputEl.style.borderColor = '#c53030';
            inputEl.addEventListener('input', function clearBorder() {
                inputEl.style.borderColor = '';
                inputEl.removeEventListener('input', clearBorder);
            });
        }
    }

    function clearFieldError(fieldId) {
        const errorEl = document.getElementById(fieldId + 'Error');
        if (errorEl) errorEl.textContent = '';
        const inputEl = document.getElementById(fieldId);
        if (inputEl) inputEl.style.borderColor = '';
    }

    function clearFormErrors(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        form.querySelectorAll('.error-msg').forEach(el => el.textContent = '');
        form.querySelectorAll('input').forEach(el => el.style.borderColor = '');
    }

    function validateLoginForm(data) {
        const errors = [];
        clearFormErrors('loginForm');

        if (!data.username || data.username.trim().length < 2) {
            errors.push('Username or email is required.');
            showError('loginUsername', 'Please enter your username or email.');
        }

        if (!data.password || data.password.length < 1) {
            errors.push('Password is required.');
            showError('loginPassword', 'Please enter your password.');
        }

        return errors;
    }

    function validateRegisterForm(data) {
        const errors = [];
        clearFormErrors('registerForm');

        if (!data.full_name || data.full_name.trim().length < 2) {
            errors.push('Full name is required.');
            showError('regFullName', 'Please enter your full name.');
        }

        if (!data.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
            errors.push('Valid email is required.');
            showError('regEmail', 'Please enter a valid email address.');
        }

        if (!data.phone || data.phone.replace(/\D/g, '').length < 10) {
            errors.push('Valid phone number is required.');
            showError('regPhone', 'Please enter a valid phone number (minimum 10 digits).');
        }

        if (!data.address || data.address.trim().length < 5) {
            errors.push('Address is required.');
            showError('regAddress', 'Please enter your address.');
        }

        if (!data.username || data.username.trim().length < 3) {
            errors.push('Username is required (min 3 characters).');
            showError('regUsername', 'Username must be at least 3 characters.');
        }

        if (!data.password || data.password.length < 6) {
            errors.push('Password must be at least 6 characters.');
            showError('regPassword', 'Password must be at least 6 characters.');
        }

        if (data.password !== data.confirm_password) {
            errors.push('Passwords do not match.');
            showError('regConfirmPassword', 'Passwords do not match.');
        }

        return errors;
    }

    // ============================================================
    // Alert Display
    // ============================================================
    function showAlert(alertId, message, type) {
        const alertEl = document.getElementById(alertId);
        if (!alertEl) return;

        alertEl.className = 'alert alert-' + type;
        alertEl.style.display = 'flex';
        alertEl.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> <span>' + escapeHtml(message) + '</span> <button class="alert-close" onclick="this.parentElement.style.display=\'none\'">&times;</button>';

        // Auto-dismiss after 3 seconds
        setTimeout(function() {
            alertEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-20px)';
            setTimeout(function() { alertEl.style.display = 'none'; }, 400);
        }, 3000);
    }

    function hideAlert(alertId) {
        const alertEl = document.getElementById(alertId);
        if (alertEl) alertEl.style.display = 'none';
    }

    async function showSwal(title, message, icon) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({
                title,
                text: message,
                icon,
                confirmButtonText: 'OK',
                timer: icon === 'success' ? 1800 : undefined,
                timerProgressBar: icon === 'success'
            });
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================================
    // AJAX Helpers
    // ============================================================
    async function postJson(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(data),
            credentials: 'same-origin'
        });
        return response;
    }

    async function postFormData(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        return response;
    }

    // ============================================================
    // Login Form Submission
    // ============================================================
    function initLoginForm() {
        if (!loginForm) return;

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert('loginAlert');
            clearFormErrors('loginForm');

            const data = {
                csrf_token: loginForm.querySelector('[name="csrf_token"]').value,
                role: loginRoleInput ? loginRoleInput.value : 'admin',
                username: loginForm.querySelector('[name="username"]').value,
                password: loginForm.querySelector('[name="password"]').value
            };

            const validationErrors = validateLoginForm(data);
            if (validationErrors.length > 0) {
                showAlert('loginAlert', validationErrors.join(' '), 'error');
                return;
            }

            const submitBtn = document.getElementById('loginSubmitBtn');
            const originalContent = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Signing in...';

            try {
                const response = await postJson('login.php', data);
                const text = await response.text();
                console.log('LOGIN STATUS:', response.status);
                console.log('LOGIN RAW RESPONSE:', text);
                let result = {};

                try {
                    result = JSON.parse(text);
                } catch (parseErr) {
                    console.error('LOGIN JSON PARSE ERROR', parseErr, text);
                    result = { status: 'error', message: 'Invalid server response.' };
                }

                console.log('LOGIN PARSED RESULT:', result);
                if (result.status === 'success') {
                    showAlert('loginAlert', result.message || 'Login successful! Redirecting...', 'success');
                    showSwal('Success', result.message || 'Login successful!', 'success');
                    setTimeout(() => {
                        window.location.href = result.redirect || 'dashboard.php';
                    }, 1000);
                } else {
                                    const errorMsg = result.message || 'Invalid username or password. Please try again.';
                                    showAlert('loginAlert', errorMsg, 'error');
                                    showSwal('Login Failed', errorMsg, 'error');
                                    // Removed shake effect per user request
                                }
            } catch (err) {
                console.error('LOGIN NETWORK ERROR', err);
                showAlert('loginAlert', 'Network error. Please check your connection and try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalContent;
            }
        });
    }

    // ============================================================
    // Register Form Submission (overlay in login.php)
    // ============================================================
    function initRegisterForm() {
        if (!registerForm) return;

        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert('registerAlert');
            clearFormErrors('registerForm');

            const data = {
                csrf_token: registerForm.querySelector('[name="csrf_token"]').value,
                full_name: registerForm.querySelector('[name="full_name"]').value,
                email: registerForm.querySelector('[name="email"]').value,
                phone: registerForm.querySelector('[name="phone"]').value,
                address: registerForm.querySelector('[name="address"]').value,
                username: registerForm.querySelector('[name="username"]').value,
                password: registerForm.querySelector('[name="password"]').value,
                confirm_password: registerForm.querySelector('[name="confirm_password"]').value
            };

            const validationErrors = validateRegisterForm(data);
            if (validationErrors.length > 0) {
                showAlert('registerAlert', validationErrors.join(' '), 'error');
                return;
            }

            const submitBtn = document.getElementById('registerSubmitBtn');
            const originalContent = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Creating account...';

            try {
                const response = await postJson('register.php', data);
                const text = await response.text();

                if (response.ok && (text.includes('success') || text.includes('Registration successful'))) {
                    showAlert('registerAlert', 'Registration successful! You can now sign in.', 'success');
                    await showSwal('Success', 'Registration successful! You can now sign in.', 'success');
                    // Clear form
                    registerForm.reset();
                    // Close overlay after short delay
                    setTimeout(() => {
                        const overlay = document.getElementById('registerOverlay');
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }, 1500);
                } else {
                    const errorMatch = text.match(/error[:\s]+([^<\n]+)/i) || text.match(/>([^<]{10,})</);
                    const errorMsg = errorMatch ? errorMatch[1].trim() : 'Registration failed. Please try again.';
                    showAlert('registerAlert', errorMsg, 'error');
                    await showSwal('Registration Failed', errorMsg, 'error');
                }
            } catch (err) {
                showAlert('registerAlert', 'Network error. Please check your connection and try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalContent;
            }
        });
    }

    // ============================================================
    // Webcam Capture Handler
    // ============================================================
    const WebcamHandler = {
        stream: null,
        videoElement: null,
        canvasElement: null,
        isCapturing: false,

        async start(videoEl, canvasEl) {
            this.videoElement = videoEl;
            this.canvasElement = canvasEl;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('getUserMedia is not supported in this browser.');
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user',
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    },
                    audio: false
                });

                if (this.videoElement) {
                    this.videoElement.srcObject = this.stream;
                    this.videoElement.play();
                }

                this.isCapturing = true;
                return true;
            } catch (err) {
                console.error('Webcam start error:', err);
                throw err;
            }
        },

        capture() {
            if (!this.isCapturing || !this.videoElement || !this.canvasElement) {
                throw new Error('Webcam is not active.');
            }

            const video = this.videoElement;
            const canvas = this.canvasElement;
            const ctx = canvas.getContext('2d');

            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;

            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            return canvas.toDataURL('image/jpeg', 0.85);
        },

        stop() {
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
                this.stream = null;
            }
            this.isCapturing = false;
            if (this.videoElement) {
                this.videoElement.srcObject = null;
            }
        },

        isActive() {
            return this.isCapturing;
        }
    };

    // Expose globally for potential inline handlers
    window.WebcamHandler = WebcamHandler;

    // ============================================================
    // Standalone Register Page Form
    // ============================================================
    function initRegisterPageForm() {
        const form = document.getElementById('registerPageForm');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearFormErrors('registerPageForm');

            const data = {
                csrf_token: form.querySelector('[name="csrf_token"]').value,
                full_name: form.querySelector('[name="full_name"]').value,
                email: form.querySelector('[name="email"]').value,
                phone: form.querySelector('[name="phone"]').value,
                address: form.querySelector('[name="address"]').value,
                username: form.querySelector('[name="username"]').value,
                password: form.querySelector('[name="password"]').value,
                confirm_password: form.querySelector('[name="confirm_password"]').value
            };

            const validationErrors = validateRegisterForm(data);
            if (validationErrors.length > 0) {
                // Show inline errors
                validationErrors.forEach(err => {
                    // Generic alert for standalone page
                });
                // Use simple alert for standalone page if no alert container
                if (!document.getElementById('registerAlert')) {
                    alert(validationErrors.join('\n'));
                    return;
                }
                showAlert('registerAlert', validationErrors.join(' '), 'error');
                return;
            }

            const submitBtn = document.getElementById('registerSubmitBtn');
            const originalContent = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Creating account...';

            try {
                const response = await postJson('register.php', data);
                const text = await response.text();

                if (response.ok && text.includes('success')) {
                    if (document.getElementById('registerAlert')) {
                        showAlert('registerAlert', 'Registration successful! Redirecting to login...', 'success');
                        await showSwal('Success', 'Registration successful! Redirecting to login...', 'success');
                        setTimeout(() => {
                            window.location.href = '/BARANGAY_MANAGEMENT/login.php';
                        }, 2000);
                    } else {
                        await showSwal('Success', 'Registration successful! You can now sign in.', 'success');
                        window.location.href = '/BARANGAY_MANAGEMENT/login.php';
                    }
                } else {
                    const errorMsg = 'Registration failed. Please try again.';
                    if (document.getElementById('registerAlert')) {
                        showAlert('registerAlert', errorMsg, 'error');
                    }
                    await showSwal('Registration Failed', errorMsg, 'error');
                }
            } catch (err) {
                const errorMsg = 'Network error. Please check your connection.';
                if (document.getElementById('registerAlert')) {
                    showAlert('registerAlert', errorMsg, 'error');
                }
                await showSwal('Error', errorMsg, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalContent;
            }
        });
    }

    // ============================================================
    // Real-time validation (debounced)
    // ============================================================
    function initRealtimeValidation() {
        const debouncedValidate = debounce(() => {
            // Clear errors on valid input
            document.querySelectorAll('input').forEach(input => {
                if (input.value && input.value.trim().length > 0) {
                    const errorEl = document.getElementById(input.id + 'Error');
                    if (errorEl && errorEl.textContent) {
                        // Keep errors until form submit
                    }
                }
            });
        }, 500);

        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('blur', () => {
                const errorEl = document.getElementById(input.id + 'Error');
                if (errorEl && input.value && errorEl.textContent) {
                    // Re-validate individual field on blur
                }
            });
            input.addEventListener('input', debouncedValidate);
        });
    }

    // ============================================================
        // Initialization
        // ============================================================
        function init() {
            initRoleSwitcher();
            initRegisterOverlay();
            initPasswordToggles();
            initLoginForm();
            initRegisterForm();
            initRegisterPageForm();
            initRealtimeValidation();
            autoDismissToasts();
        }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }


    // Auto-dismiss server-rendered flash toasts (.toast-alert) after 3 seconds
    function autoDismissToasts() {
        document.querySelectorAll('.toast-alert').forEach(function(el) {
            setTimeout(function() {
                el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-20px)';
                setTimeout(function() { el.remove(); }, 400);
            }, 3000);
        });
    }

    // Programmatic toast (e.g. after AJAX actions)
    window.showToast = function(message, type) {
        type = type || 'success';
        const el = document.createElement('div');
        el.className = 'toast-alert toast-' + type + ' no-print';
        const icon = type === 'success' ? 'fa-check' : (type === 'danger' ? 'fa-exclamation' : 'fa-info-circle');
        el.innerHTML = '<i class="fas ' + icon + '"></i> <span>' + message + '</span>';
        document.body.appendChild(el);
        setTimeout(function() {
            el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-20px)';
            setTimeout(function() { el.remove(); }, 400);
        }, 3000);
    };

    // Reusable confirmation popup for delete/destructive actions
    let _confirmResolve = null;
    function ensureConfirmModal() {
        if (document.getElementById('confirmModal')) return;
        const m = document.createElement('div');
        m.id = 'confirmModal';
        m.className = 'confirm-overlay';
        m.innerHTML =
            '<div class="confirm-box" role="alertdialog" aria-modal="true">' +
                '<div class="confirm-icon"><i class="fas fa-exclamation-triangle"></i></div>' +
                '<h3 class="confirm-title" id="confirmTitle">Are you sure?</h3>' +
                '<p class="confirm-text" id="confirmText">This action cannot be undone.</p>' +
                '<div class="confirm-actions">' +
                    '<button type="button" class="btn btn-outline" id="confirmCancel">Cancel</button>' +
                    '<button type="button" class="btn btn-danger" id="confirmOk">Delete</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(m);
        m.addEventListener('click', function(e) {
            if (e.target === m) doCancel();
        });
        m.querySelector('#confirmCancel').addEventListener('click', doCancel);
        m.querySelector('#confirmOk').addEventListener('click', function() {
            m.classList.remove('show');
            const r = _confirmResolve; _confirmResolve = null;
            if (r) r(true);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && m.classList.contains('show')) doCancel();
        });
    }
    function doCancel() {
        const m = document.getElementById('confirmModal');
        if (m) m.classList.remove('show');
        const r = _confirmResolve; _confirmResolve = null;
        if (r) r(false);
    }
    window.confirmAction = function(message, title) {
        ensureConfirmModal();
        const m = document.getElementById('confirmModal');
        m.querySelector('#confirmTitle').textContent = title || 'Are you sure?';
        m.querySelector('#confirmText').textContent = message || 'This action cannot be undone.';
        m.classList.add('show');
        return new Promise(function(resolve) { _confirmResolve = resolve; });
    };

    // Wire a delete form to the styled popup. Put onsubmit="return handleDelete(this)" on the form.
    window.handleDelete = function(form) {
        if (form.dataset.confirmed === '1') { form.dataset.confirmed = '0'; return true; }
        window.confirmAction(form.dataset.confirmMsg, form.dataset.confirmTitle).then(function(ok) {
            if (ok) { form.dataset.confirmed = '1'; form.submit(); }
        });
        return false;
    };

    // ============================================================
    // Off-canvas sidebar drawer (tablet/mobile)
    // ============================================================
    function initDrawer() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('drawerBackdrop');
        const hamburger = document.getElementById('hamburgerBtn');
        if (!sidebar || !backdrop) return;

        function open() {
            sidebar.classList.add('open');
            backdrop.classList.add('open');
            document.body.classList.add('drawer-open');
        }
        function close() {
            sidebar.classList.remove('open');
            backdrop.classList.remove('open');
            document.body.classList.remove('drawer-open');
        }

        if (hamburger) hamburger.addEventListener('click', open);
        backdrop.addEventListener('click', close);
        sidebar.querySelectorAll('a').forEach(function(a) {
            a.addEventListener('click', close);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') close();
        });
    }
    initDrawer();

})();
