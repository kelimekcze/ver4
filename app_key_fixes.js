// KLÍČOVÉ OPRAVY PRO app.js

// 1. OPRAVA KONSTRUKTORU (řádky cca 3-10)
class CRMApp {
    constructor() {
        this.currentUser = null;
        this.refreshInterval = null;
        this.currentDate = new Date();
        this.selectedDate = null;
        this.apiBase = 'api'; // API soubory jsou v api/ adresáři - OPRAVENO
        
        this.init();
    }

// 2. OPRAVA checkAuthStatus() (řádky cca 105-150)
    async checkAuthStatus() {
        try {
            console.log('🔐 NOVÁ VERZE - Checking auth status...');
            
            // Pokud už máme uživatele (z přihlášení), jen načteme data
            if (this.currentUser) {
                console.log('👤 User already set, loading dashboard data...');
                this.showMainContent();
                await this.loadDashboardData();
                this.setupCalendarIntegration();
                return;
            }
            
            const response = await fetch(this.apiBase + '/session.php', {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            console.log('🔐 Session check response:', response.status);
            
            if (response.ok) {
                const text = await response.text();
                console.log('🔐 Session response text:', text);
                
                if (text) {
                    try {
                        const data = JSON.parse(text);
                        console.log('🔐 Parsed session data:', data);
                        
                        if (data.success && data.user) {
                            console.log('✅ User is logged in:', data.user);
                            this.currentUser = data.user;
                            console.log('🎯 Calling showMainContent()...');
                            this.showMainContent();
                            console.log('📊 Loading dashboard data...');
                            await this.loadDashboardData();
                            this.setupCalendarIntegration();
                            return;
                        } else {
                            console.log('ℹ️ No active session, showing login screen');
                            this.showLoginScreen();
                        }
                    } catch (jsonError) {
                        console.error('❌ Session JSON parse error:', jsonError);
                        this.showLoginScreen();
                    }
                }
            } else {
                console.log('⚠️ Session check failed with status:', response.status);
                this.showLoginScreen();
            }
            
        } catch (error) {
            console.error('❌ Auth check failed:', error);
            this.showLoginScreen();
        }
    }

// 3. OPRAVA showMainContent() (řádky cca 188-220)
    showMainContent() {
        console.log('🎯 showMainContent() called');
        
        const loginContainer = document.getElementById('loginContainer');
        const registerContainer = document.getElementById('registerContainer');
        const appContainer = document.getElementById('appContainer');
        
        console.log('🎯 Elements found:', {
            loginContainer: !!loginContainer,
            registerContainer: !!registerContainer,
            appContainer: !!appContainer
        });
        
        if (loginContainer) {
            loginContainer.style.display = 'none';
            console.log('✅ Login container hidden');
        }
        
        if (registerContainer) {
            registerContainer.style.display = 'none';
            console.log('✅ Register container hidden');
        }
        
        if (appContainer) {
            appContainer.style.display = 'block';
            console.log('✅ App container shown');
        } else {
            console.error('❌ App container not found!');
            return;
        }
        
        this.updateUserInfo();
        this.setupAdminVisibility();
        
        console.log('🎯 showMainContent() completed');
    }

// 4. OPRAVA updateUserInfo() (řádky cca 230-250)
    updateUserInfo() {
        if (!this.currentUser) {
            console.log('⚠️ No current user for updateUserInfo');
            return;
        }
        
        console.log('👤 Updating user info for:', this.currentUser);
        
        const elements = {
            userDisplayName: this.currentUser.full_name,
            userRole: this.getUserRoleText(this.currentUser.user_type)
        };

        Object.entries(elements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
                console.log(`✅ Updated ${id}: ${value}`);
            } else {
                console.warn(`⚠️ Element not found: ${id}`);
            }
        });
    }

// 5. OPRAVA setupInitialData() (řádky cca 91-105)
    async setupInitialData() {
        // Setup forms after auth check - pouze pokud je uživatel přihlášen
        setTimeout(() => {
            if (this.currentUser) {
                console.log('🔧 Setting up initial data for logged user');
                this.loadWarehousesForForms();
                this.setupFormHandlers();
                this.setDefaultFormValues();
            } else {
                console.log('ℹ️ Skipping initial data setup - user not logged in');
            }
        }, 1000);
    }

// 6. OPRAVA loadDashboardData() (řádky cca 308-360)
    async loadDashboardData() {
        // Nečíst data pokud uživatel není přihlášen
        if (!this.currentUser) {
            console.log('ℹ️ Skipping dashboard data load - user not logged in');
            return;
        }
        
        try {
            console.log('📊 Loading dashboard data...');
            
            // Load dashboard statistics
            const statsResponse = await fetch(`${this.apiBase}/bookings.php?dashboard_stats=1`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            
            if (statsResponse.ok) {
                const text = await statsResponse.text();
                if (text) {
                    const statsData = JSON.parse(text);
                    if (statsData.success) {
                        this.updateDashboardStats(statsData.stats);
                    }
                }
            } else if (statsResponse.status === 401) {
                console.log('⚠️ User not authenticated, redirecting to login');
                this.showLoginScreen();
                return;
            }

            // Load upcoming bookings
            const upcomingResponse = await fetch(`${this.apiBase}/bookings.php?upcoming=1&limit=5`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            
            if (upcomingResponse.ok) {
                const text = await upcomingResponse.text();
                if (text) {
                    const upcomingData = JSON.parse(text);
                    if (upcomingData.success) {
                        this.updateUpcomingBookings(upcomingData.bookings);
                    }
                }
            } else if (upcomingResponse.status === 401) {
                console.log('⚠️ User not authenticated, redirecting to login');
                this.showLoginScreen();
                return;
            }

        } catch (error) {
            console.error('❌ Failed to load dashboard data:', error);
            // Nezobrazovat chybu při načítání stránky
            if (this.currentUser) {
                this.showNotification('Chyba při načítání dat dashboardu', 'error');
            }
        }
    }

// ... zbytek původního kódu zůstává stejný ...