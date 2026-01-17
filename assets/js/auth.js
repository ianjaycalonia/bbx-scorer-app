// Authentication Service
class AuthService {
    constructor() {
        this.currentToast = null;
        this.currentToastMessage = null;
        this.initEventListeners();
    }

    initEventListeners() {
        // Form switches
        document.getElementById('showRegister').addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('registerForm').style.display = 'block';
        });

        document.getElementById('showLogin').addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('registerForm').style.display = 'none';
            document.getElementById('loginForm').style.display = 'block';
        });

        // Login form
        document.getElementById('loginFormElement').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.login();
        });

        // Register form
        document.getElementById('registerFormElement').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.register();
        });

        // Input listeners to dismiss toast on edit
        document.getElementById('loginEmail').addEventListener('input', () => this.dismissToast());
        document.getElementById('loginPassword').addEventListener('input', () => this.dismissToast());
        document.getElementById('registerEmail').addEventListener('input', () => this.dismissToast());
        document.getElementById('registerPassword').addEventListener('input', () => this.dismissToast());
        document.getElementById('bladerName').addEventListener('input', () => this.dismissToast());
        document.getElementById('displayName').addEventListener('input', () => this.dismissToast());
    }

    async login() {
        const email = document.getElementById('loginEmail').value;
        const password = document.getElementById('loginPassword').value;

        if (!email || !password) {
            this.showAlert('Please fill in all fields', 'danger');
            return;
        }

        try {
            this.showLoading('Logging in...');
            const response = await api.login(email, password);

            if (response.success) {
                this.showAlert('Login successful! Redirecting...', 'success');
                // Save user to session storage
                sessionStorage.setItem('user', JSON.stringify(response.profile));

                setTimeout(() => {
                    window.location.href = 'dashboard.html';
                }, 1500);
            } else {
                this.showAlert(response.message || 'Login failed', 'danger');
            }
        } catch (error) {
            this.showAlert('Login failed. Please try again.', 'danger');
        } finally {
            this.hideLoading();
        }
    }

    async register() {
        const email = document.getElementById('registerEmail').value;
        const password = document.getElementById('registerPassword').value;
        const bladerName = document.getElementById('bladerName').value;
        const displayName = document.getElementById('displayName').value;

        if (!email || !password || !bladerName || !displayName) {
            this.showAlert('Please fill in all fields', 'danger');
            return;
        }

        try {
            this.showLoading('Creating account...');
            const response = await api.register({
                email,
                password,
                blader_name: bladerName,
                display_name: displayName
            });

            if (response.success) {
                this.showAlert('Registration successful! Please login.', 'success');
                setTimeout(() => {
                    document.getElementById('registerForm').style.display = 'none';
                    document.getElementById('loginForm').style.display = 'block';
                }, 2000);
            } else {
                this.showAlert(response.message || 'Registration failed', 'danger');
            }
        } catch (error) {
            this.showAlert('Registration failed. Please try again.', 'danger');
        } finally {
            this.hideLoading();
        }
    }

    showAlert(message, type = 'info') {
        // UI guard: don't show duplicate toast with same message
        if (this.currentToast && this.currentToastMessage === message) {
            return;
        }

        // Dismiss existing toast if showing different message
        if (this.currentToast) {
            this.dismissToast();
        }

        const toastContainer = document.getElementById('toastContainer');
        const toastId = `toast-${Date.now()}`;
        const iconClass = type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle';
        const bgClass = type === 'success' ? 'text-bg-success' : type === 'danger' ? 'text-bg-danger' : 'text-bg-info';

        const toastElement = document.createElement('div');
        toastElement.className = `toast align-items-center ${bgClass} border-0`;
        toastElement.id = toastId;
        toastElement.setAttribute('role', 'alert');
        toastElement.setAttribute('aria-live', 'assertive');
        toastElement.setAttribute('aria-atomic', 'true');
        toastElement.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-${iconClass} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        toastContainer.appendChild(toastElement);

        // Initialize Bootstrap toast
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 5000
        });

        // Store reference and message
        this.currentToast = toast;
        this.currentToastMessage = message;

        // Show toast
        toast.show();

        // Clean up when toast is hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
            if (this.currentToast === toast) {
                this.currentToast = null;
                this.currentToastMessage = null;
            }
        });
    }

    dismissToast() {
        if (this.currentToast) {
            this.currentToast.hide();
            this.currentToast = null;
            this.currentToastMessage = null;
        }
    }

    showLoading(message) {
        const alertContainer = document.getElementById('alertContainer');
        const loading = document.createElement('div');
        loading.className = 'alert alert-info';
        loading.innerHTML = `
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            ${message}
        `;
        loading.id = 'loadingAlert';
        alertContainer.appendChild(loading);
    }

    hideLoading() {
        const loadingAlert = document.getElementById('loadingAlert');
        if (loadingAlert) {
            loadingAlert.remove();
        }
    }
}

// Initialize auth service
const authService = new AuthService();
