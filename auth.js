// js/auth.js - Authentication and Registration (KOMPLETNĚ OPRAVENÁ VERZE)
class AuthManager {
    constructor() {
        this.apiBase = 'api'; // API soubory jsou v api/ adresáři
        console.log('🔐 AuthManager: Initializing...');
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Počkáme na DOM a pak připojíme event listenery
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.attachEventListeners();
            });
        } else {
            this.attachEventListeners();
        }
    }

    attachEventListeners() {
        console.log('🔧 AuthManager: Attaching event listeners...');
        
        // Login form
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            console.log('✅ Login form found');
            loginForm.addEventListener('submit', this.handleLogin.bind(this));
        } else {
            console.error('❌ Login form NOT found!');
        }
        
        // Register form  
        const registerForm = document.getElementById('registerForm');
        if (registerForm) {
            console.log('✅ Register form found');
            registerForm.addEventListener('submit', this.handleRegister.bind(this));
        }
        
        // Show/hide forms
        const showRegister = document.getElementById('showRegister');
        if (showRegister) {
            showRegister.addEventListener('click', this.showRegisterForm.bind(this));
        }
        
        const showLogin = document.getElementById('showLogin');
        if (showLogin) {
            showLogin.addEventListener('click', this.showLoginForm.bind(this));
        }
    }

    async handleLogin(e) {
        e.preventDefault();
        console.log('🔑 Login attempt started...');
        
        const formData = new FormData(e.target);
        const data = {
            email: formData.get('email'),
            password: formData.get('password')
        };

        console.log('📤 Login data:', { email: data.email, password: '[HIDDEN]' });

        try {
            const response = await fetch(this.apiBase + '/login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            console.log('📡 Response status:', response.status);

            const text = await response.text();
            console.log('📝 Response text (first 200 chars):', text.substring(0, 200) + '...');

            if (!text) {
                throw new Error('Server vrátil prázdnou odpověď');
            }

            let result;
            try {
                result = JSON.parse(text);
            } catch (jsonError) {
                console.error('❌ JSON parse error:', jsonError);
                console.error('📄 Raw response:', text);
                throw new Error('Server vrátil neplatný JSON');
            }

            if (response.ok && result.success) {
                console.log('🎉 Login successful!', result.user);
                
                // Bezpečné volání CRM App
                this.handleSuccessfulLogin(result.user);
                
            } else {
                throw new Error(result.error || 'Přihlášení selhalo');
            }
            
        } catch (error) {
            console.error('❌ Login error:', error);
            this.showError('Chyba při přihlašování: ' + error.message);
        }
    }

    handleSuccessfulLogin(user) {
        console.log('🚀 AUTH: Handling successful login...');
        
        // NEMĚNÍME ZOBRAZENÍ - to nechme na CRM App
        // Jen nastavíme uživatele a zavoláme CRM App
        
        if (window.crmApp) {
            console.log('🔗 AUTH: Setting current user and calling showMainContent');
            window.crmApp.currentUser = user;
            window.crmApp.showMainContent();
            window.crmApp.showNotification('Úspěšně přihlášen!', 'success');
            // Zavoláme znovu checkAuthStatus aby se správně načetla data
            window.crmApp.checkAuthStatus();
        } else {
            console.warn('⚠️ AUTH: CRM App not found, will initialize later');
            // Počkáme na CRM App
            this.waitForCrmApp(user);
        }
    }

    waitForCrmApp(user, attempts = 0) {
        const maxAttempts = 10;
        
        if (window.crmApp) {
            console.log('🔗 AUTH: CRM App found after waiting, initializing...');
            window.crmApp.currentUser = user;
            window.crmApp.showMainContent();
            window.crmApp.showNotification('Úspěšně přihlášen!', 'success');
            // Zavoláme znovu checkAuthStatus aby se správně načetla data
            window.crmApp.checkAuthStatus();
        } else if (attempts < maxAttempts) {
            console.log(`⏳ AUTH: Waiting for CRM App... (${attempts + 1}/${maxAttempts})`);
            setTimeout(() => {
                this.waitForCrmApp(user, attempts + 1);
            }, 500);
        } else {
            console.error('❌ AUTH: CRM App not available after waiting');
            this.showError('Aplikace se nenačetla správně. Obnovte stránku.');
        }
    }

    updateUserInfo(user) {
        // Bezpečně aktualizujeme user info
        const userDisplayName = document.getElementById('userDisplayName');
        if (userDisplayName) {
            userDisplayName.textContent = user.full_name;
        }
        
        const userRole = document.getElementById('userRole');
        if (userRole) {
            userRole.textContent = this.getUserRoleText(user.user_type);
        }
        
        // Admin visibility
        this.setupAdminVisibility(user);
    }

    setupAdminVisibility(user) {
        const adminElements = document.querySelectorAll('.admin-only');
        const isAdmin = ['admin', 'logistics', 'super_admin'].includes(user.user_type);
        
        adminElements.forEach(el => {
            el.style.display = isAdmin ? 'block' : 'none';
        });
    }

    getUserRoleText(userType) {
        const roles = {
            'super_admin': 'Super Admin',
            'admin': 'Administrátor',
            'logistics': 'Logistika',
            'driver': 'Řidič'
        };
        return roles[userType] || 'Uživatel';
    }

    async handleRegister(e) {
        e.preventDefault();
        console.log('📝 Register attempt started...');
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        // Základní validace
        if (data.password.length < 6) {
            this.showError('Heslo musí mít alespoň 6 znaků');
            return;
        }

        if (!this.isValidEmail(data.email)) {
            this.showError('Neplatný formát emailu');
            return;
        }

        try {
            const response = await fetch(this.apiBase + '/register.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            });

            const text = await response.text();
            
            if (!text) {
                throw new Error('Server vrátil prázdnou odpověď');
            }

            const result = JSON.parse(text);

            if (response.ok && result.success) {
                this.showSuccess('Registrace úspěšná! Můžete se přihlásit.');
                this.showLoginForm();
                document.getElementById('registerForm').reset();
                
                // Pre-fill login email
                const emailInput = document.getElementById('email');
                if (emailInput) {
                    emailInput.value = data.email;
                }
            } else {
                throw new Error(result.error || 'Registrace selhala');
            }
        } catch (error) {
            console.error('❌ Registration error:', error);
            this.showError('Chyba při registraci: ' + error.message);
        }
    }

    showRegisterForm(e) {
        if (e) e.preventDefault();
        console.log('📋 Showing register form');
        
        const loginContainer = document.getElementById('loginContainer');
        const registerContainer = document.getElementById('registerContainer');
        
        if (loginContainer) loginContainer.style.display = 'none';
        if (registerContainer) registerContainer.style.display = 'block';
    }

    showLoginForm(e) {
        if (e) e.preventDefault();
        console.log('🔑 Showing login form');
        
        const loginContainer = document.getElementById('loginContainer');
        const registerContainer = document.getElementById('registerContainer');
        
        if (registerContainer) registerContainer.style.display = 'none';
        if (loginContainer) loginContainer.style.display = 'block';
    }

    async logout() {
        if (!confirm('Opravdu se chcete odhlásit?')) return;
        
        console.log('🚪 Logging out...');
        
        try {
            await fetch(this.apiBase + '/logout.php', {
                method: 'POST',
                credentials: 'include'
            });
        } catch (error) {
            console.error('❌ Logout error:', error);
        }
        
        // Vždy zobrazíme login screen
        this.showLoginScreen();
        
        // Vyčistíme CRM App
        if (window.crmApp) {
            window.crmApp.currentUser = null;
        }
    }

    showLoginScreen() {
        console.log('🔑 Showing login screen');
        
        const loginContainer = document.getElementById('loginContainer');
        const appContainer = document.getElementById('appContainer');
        const registerContainer = document.getElementById('registerContainer');
        
        if (loginContainer) loginContainer.style.display = 'block';
        if (appContainer) appContainer.style.display = 'none';
        if (registerContainer) registerContainer.style.display = 'none';
    }

    showError(message) {
        console.error('🚨 Error:', message);
        
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'error');
        } else {
            // Fallback alert
            alert(message);
        }
    }

    showSuccess(message) {
        console.log('✅ Success:', message);
        
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'success');
        } else {
            // Fallback alert
            alert(message);
        }
    }

    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Session management
    async checkSession() {
        try {
            const response = await fetch(this.apiBase + '/session.php', {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    return data.success && data.user ? data.user : null;
                }
            }
            
            return null;
        } catch (error) {
            console.error('❌ Session check failed:', error);
            return null;
        }
    }
}

// Globální inicializace
console.log('🚀 AuthManager: Starting initialization...');

// Initialize když je DOM ready
function initializeAuthManager() {
    if (!window.authManager) {
        console.log('🔧 Creating AuthManager instance...');
        window.authManager = new AuthManager();
    }
}

// Initialize immediately or wait for DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAuthManager);
} else {
    initializeAuthManager();
}

// Global logout function
function logout() {
    if (window.authManager) {
        window.authManager.logout();
    }
}

console.log('✅ AuthManager: Module loaded successfully');