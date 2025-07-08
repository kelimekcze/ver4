// js/app.js - Hlavní aplikační logika s integrací moderního kalendáře (DOKONČENO)
class CRMApp {
    constructor() {
        this.currentUser = null;
        this.refreshInterval = null;
        this.currentDate = new Date();
        this.selectedDate = null;
        this.apiBase = 'api/'; // API soubory jsou v api/ adresáři
        
        this.init();
    }

    async init() {
        this.setupEventListeners();
        await this.checkAuthStatus();
        this.setupInitialData();
    }

    setupEventListeners() {
        // Auto-refresh every 30 seconds
        this.refreshInterval = setInterval(() => {
            if (document.visibilityState === 'visible' && this.currentUser) {
                this.loadDashboardData();
            }
        }, 30000);

        // Page visibility change - refresh when page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && this.currentUser) {
                this.loadDashboardData();
            }
        });

        // Global keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'r':
                        e.preventDefault();
                        this.refreshDashboard();
                        break;
                    case 'n':
                        e.preventDefault();
                        if (document.getElementById('calendar').classList.contains('active')) {
                            this.showNewSlotModal();
                        }
                        break;
                }
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
        });

        // Close modals when clicking outside
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        // Initialize sidebar navigation
        this.setupSidebarNavigation();
    }

    setupSidebarNavigation() {
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                
                // Remove active from all links
                document.querySelectorAll('.sidebar-menu a').forEach(l => l.classList.remove('active'));
                
                // Add active to clicked link
                link.classList.add('active');
                
                // Get section from onclick attribute
                const onclick = link.getAttribute('onclick');
                if (onclick) {
                    const sectionMatch = onclick.match(/showSection\('([^']+)'\)/);
                    if (sectionMatch) {
                        this.showSection(sectionMatch[1]);
                    }
                }
            });
        });
    }

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
            
            const response = await fetch(this.apiBase + 'session.php', {
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

    setupCalendarIntegration() {
        // Wait for calendar to be initialized and integrate
        setTimeout(() => {
            this.waitForCalendar(() => {
                if (window.logisticsCalendar) {
                    // Link calendar with main app notifications
                    window.logisticsCalendar.showSuccess = (message) => {
                        this.showNotification(message, 'success');
                    };
                    
                    window.logisticsCalendar.showError = (message) => {
                        this.showNotification(message, 'error');
                    };

                    // Enhanced slot editing integration
                    window.logisticsCalendar.editSlot = (slotId) => {
                        this.editSlot(slotId);
                    };

                    console.log('✅ Calendar integration completed');
                } else {
                    console.warn('Calendar not available for integration');
                }
            });
        }, 500);
    }

    // Helper method to wait for calendar availability
    waitForCalendar(callback, maxAttempts = 10, attempt = 1) {
        if (window.logisticsCalendar) {
            callback();
        } else if (attempt < maxAttempts) {
            setTimeout(() => {
                this.waitForCalendar(callback, maxAttempts, attempt + 1);
            }, 500);
        } else {
            console.warn('Calendar not available after maximum attempts');
        }
    }

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

    showLoginScreen() {
        document.getElementById('loginContainer').style.display = 'flex';
        const registerContainer = document.getElementById('registerContainer');
        if (registerContainer) {
            registerContainer.style.display = 'none';
        }
        document.getElementById('appContainer').style.display = 'none';
    }

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

    setupAdminVisibility() {
        const adminElements = document.querySelectorAll('.admin-only');
        const isAdmin = this.currentUser && ['admin', 'logistics', 'super_admin'].includes(this.currentUser.user_type);
        
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

    // Enhanced section navigation with calendar integration
    showSection(sectionName) {
        // Hide all sections
        document.querySelectorAll('.page-section').forEach(section => {
            section.classList.remove('active');
        });
        
        // Show selected section
        const section = document.getElementById(sectionName);
        if (section) {
            section.classList.add('active');
            this.loadSectionData(sectionName);
        }
    }

    async loadSectionData(sectionName) {
        console.log('Loading section data for:', sectionName);
        
        switch(sectionName) {
            case 'dashboard':
                await this.loadDashboardData();
                break;
            case 'calendar':
                // Initialize calendar after section becomes visible
                setTimeout(() => {
                    this.waitForCalendar(() => {
                        if (window.logisticsCalendar) {
                            console.log('Generating calendar and loading slots...');
                            window.logisticsCalendar.generateWeeklyCalendar();
                            window.logisticsCalendar.loadSlots();
                        }
                    });
                }, 100);
                break;
            case 'bookings':
                await this.loadAllBookings();
                break;
            case 'slots':
                await this.loadAllSlots();
                break;
            case 'warehouses':
                await this.loadAllWarehouses();
                break;
            case 'users':
                await this.loadAllUsers();
                break;
        }
    }

    // Dashboard Data Management
    async loadDashboardData() {
        // Nečíst data pokud uživatel není přihlášen
        if (!this.currentUser) {
            console.log('ℹ️ Skipping dashboard data load - user not logged in');
            return;
        }
        
        try {
            console.log('📊 Loading dashboard data...');
            
            // Load dashboard statistics
            const statsResponse = await fetch(`${this.apiBase}bookings.php?dashboard_stats=1`, {
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
            const upcomingResponse = await fetch(`${this.apiBase}bookings.php?upcoming=1&limit=5`, {
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

    updateDashboardStats(stats) {
        const elements = {
            pendingBookings: stats.pending || 0,
            confirmedBookings: stats.confirmed || 0,
            inProgressBookings: stats.in_progress || 0,
            completedToday: stats.completed_today || 0,
            bookingsBadge: (stats.pending + stats.confirmed) || 0
        };

        Object.entries(elements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        });
    }

    updateUpcomingBookings(bookings) {
        const container = document.getElementById('upcomingBookingsList');
        if (!container) return;

        if (bookings.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;">Žádné nadcházející rezervace</div>';
            return;
        }

        container.innerHTML = bookings.map(booking => this.createBookingCard(booking)).join('');
    }

    createBookingCard(booking) {
        const statusClass = this.getStatusClass(booking.booking_status);
        const statusText = this.getStatusText(booking.booking_status);
        const dateTime = new Date(`${booking.slot_date} ${booking.slot_time}`);
        const isToday = this.isDateToday(dateTime);
        const isTomorrow = this.isDateTomorrow(dateTime);
        
        let dateLabel = dateTime.toLocaleDateString('cs-CZ');
        if (isToday) dateLabel = 'DNES';
        else if (isTomorrow) dateLabel = 'ZÍTRA';

        return `
            <div class="booking-item" onclick="crmApp.viewBookingDetail(${booking.id})">
                <div class="booking-time">
                    <div class="booking-date">${dateLabel}</div>
                    <div class="booking-hour">${booking.slot_time.substring(0, 5)}</div>
                </div>
                <div class="booking-details">
                    <div class="booking-title">${booking.warehouse_name}</div>
                    <div class="booking-subtitle">
                        <span><i class="fas fa-truck"></i> ${booking.truck_license_plate}</span>
                        <span><i class="fas fa-user"></i> ${booking.driver_name}</span>
                        ${booking.cargo_weight ? `<span><i class="fas fa-weight-hanging"></i> ${(booking.cargo_weight/1000).toFixed(1)}t</span>` : ''}
                    </div>
                </div>
                <div class="booking-status ${statusClass}">${statusText}</div>
                <div class="booking-actions">
                    ${this.getBookingActions(booking)}
                </div>
            </div>
        `;
    }

    getBookingActions(booking) {
        if (!this.currentUser || this.currentUser.user_type === 'driver') return '';
        
        const actions = [];
        
        switch (booking.booking_status) {
            case 'pending':
                actions.push(`
                    <button class="btn btn-small btn-success" onclick="event.stopPropagation(); confirmBooking(${booking.id})">
                        <i class="fas fa-check"></i> Potvrdit
                    </button>
                    <button class="btn btn-small btn-danger" onclick="event.stopPropagation(); rejectBooking(${booking.id})">
                        <i class="fas fa-times"></i> Zamítnout
                    </button>
                `);
                break;
            case 'confirmed':
                actions.push(`
                    <button class="btn btn-small btn-success" onclick="event.stopPropagation(); startBooking(${booking.id})">
                        <i class="fas fa-play"></i> Začít
                    </button>
                `);
                break;
            case 'in_progress':
                actions.push(`
                    <button class="btn btn-small btn-warning" onclick="event.stopPropagation(); completeBooking(${booking.id})">
                        <i class="fas fa-flag-checkered"></i> Dokončit
                    </button>
                `);
                break;
        }
        
        return actions.join('');
    }

    // Notification System
    showNotification(message, type = 'info') {
        const container = document.getElementById('notificationsContainer');
        if (!container) return;

        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas ${this.getNotificationIcon(type)}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer; margin-left: auto;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        container.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    getNotificationIcon(type) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        return icons[type] || 'fa-info-circle';
    }

    // Modal Management
    showModal(modalId, data = null) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            if (data) {
                this.populateModalData(modalId, data);
            }
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            const form = modal.querySelector('form');
            if (form) {
                form.reset();
            }
        }
    }

    closeAllModals() {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
        
        // Close calendar modal if exists
        if (window.logisticsCalendar && window.logisticsCalendar.closeBookingModal) {
            window.logisticsCalendar.closeBookingModal();
        }
    }

    populateModalData(modalId, data) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        Object.entries(data).forEach(([key, value]) => {
            const input = modal.querySelector(`[name="${key}"], #${key}`);
            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = value;
                } else {
                    input.value = value;
                }
            }
        });
    }

    // Slot Management
    showNewSlotModal() {
        // Try calendar modal first
        this.waitForCalendar(() => {
            if (window.logisticsCalendar && window.logisticsCalendar.showBookingModal) {
                window.logisticsCalendar.showBookingModal();
            } else {
                this.showModal('newSlotModal');
            }
        });
    }

    async editSlot(slotId) {
        try {
            const response = await fetch(`${this.apiBase}slots.php?id=${slotId}`, {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    this.showModal('editSlotModal', data.slot);
                } else {
                    this.showNotification('Chyba při načítání slotu', 'error');
                }
            }
        } catch (error) {
            this.showNotification('Chyba při načítání slotu: ' + error.message, 'error');
        }
    }

    async deleteSlot(slotId) {
        if (!confirm('Opravdu smazat tento slot?')) return;

        try {
            const response = await fetch(`${this.apiBase}slots.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ slot_id: slotId })
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showNotification('Slot byl smazán', 'success');
                this.refreshAfterSlotAction();
            } else {
                throw new Error(result.error || 'Chyba při mazání slotu');
            }
        } catch (error) {
            this.showNotification('Chyba při mazání: ' + error.message, 'error');
        }
    }

    // Refresh methods
    refreshAfterSlotAction() {
        // Refresh calendar if visible
        if (document.getElementById('calendar').classList.contains('active')) {
            this.waitForCalendar(() => {
                if (window.logisticsCalendar && window.logisticsCalendar.refresh) {
                    window.logisticsCalendar.refresh();
                }
            });
        }
        
        // Refresh slots table if visible
        if (document.getElementById('slots').classList.contains('active')) {
            this.loadAllSlots();
        }
        
        // Refresh dashboard
        if (document.getElementById('dashboard').classList.contains('active')) {
            this.loadDashboardData();
        }
    }

    refreshDashboard() {
        this.loadDashboardData();
        this.showNotification('Dashboard byl obnoven', 'success');
    }

    // Form setup and handling
    setupFormHandlers() {
        // Set up form submission handlers here if needed
        this.setupUserTypeHandlers();
    }

    setupUserTypeHandlers() {
        const userTypeSelect = document.getElementById('user_type');
        if (userTypeSelect) {
            userTypeSelect.addEventListener('change', (e) => {
                const driverFields = document.getElementById('userDriverFields');
                if (driverFields) {
                    driverFields.style.display = e.target.value === 'driver' ? 'block' : 'none';
                }
            });
        }
        
        const editUserTypeSelect = document.getElementById('edit_user_type');
        if (editUserTypeSelect) {
            editUserTypeSelect.addEventListener('change', (e) => {
                const driverFields = document.getElementById('editUserDriverFields');
                if (driverFields) {
                    driverFields.style.display = e.target.value === 'driver' ? 'block' : 'none';
                }
            });
        }
    }

    setDefaultFormValues() {
        const today = new Date().toISOString().split('T')[0];
        const slotDateInput = document.getElementById('slot_date');
        if (slotDateInput) {
            slotDateInput.value = today;
        }
    }

    async loadWarehousesForForms() {
        try {
            const response = await fetch(`${this.apiBase}warehouses.php`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        const selectors = [
                            'booking_warehouse',
                            'slot_warehouse', 
                            'edit_slot_warehouse',
                            'edit_booking_warehouse'
                        ];
                        
                        selectors.forEach(selectId => {
                            const select = document.getElementById(selectId);
                            if (select) {
                                const currentValue = select.value;
                                select.innerHTML = '<option value="">Vyberte sklad</option>';
                                
                                data.warehouses.forEach(warehouse => {
                                    const option = document.createElement('option');
                                    option.value = warehouse.id;
                                    option.textContent = warehouse.name;
                                    select.appendChild(option);
                                });
                                
                                if (currentValue) {
                                    select.value = currentValue;
                                }
                            }
                        });
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load warehouses:', error);
        }
    }

    // Data loading methods
    async loadAllBookings() {
        try {
            const response = await fetch(`${this.apiBase}bookings.php`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.updateBookingsTable(data.bookings);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load bookings:', error);
        }
    }

    updateBookingsTable(bookings) {
        const tbody = document.getElementById('bookingsTableBody');
        if (!tbody) return;

        if (bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">Žádné rezervace</td></tr>';
            return;
        }

        tbody.innerHTML = bookings.map(booking => `
            <tr>
                <td>${booking.slot_date}</td>
                <td>${booking.slot_time.substring(0, 5)}</td>
                <td>${booking.warehouse_name}</td>
                <td>${booking.truck_license_plate}</td>
                <td>${booking.driver_name}</td>
                <td><span class="booking-status ${this.getStatusClass(booking.booking_status)}">${this.getStatusText(booking.booking_status)}</span></td>
                <td>
                    <button class="btn btn-small btn-secondary" onclick="crmApp.viewBookingDetail(${booking.id})">
                        <i class="fas fa-eye"></i> Detail
                    </button>
                    ${booking.booking_status === 'pending' ? `
                    <button class="btn btn-small btn-success" onclick="confirmBooking(${booking.id})">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-small btn-danger" onclick="rejectBooking(${booking.id})">
                        <i class="fas fa-times"></i>
                    </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    }

    async loadAllSlots() {
        try {
            const response = await fetch(`${this.apiBase}slots.php`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.updateSlotsTable(data.slots);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load slots:', error);
        }
    }

    updateSlotsTable(slots) {
        const tbody = document.getElementById('slotsTableBody');
        if (!tbody) return;

        if (slots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 40px;">Žádné časové sloty</td></tr>';
            return;
        }

        tbody.innerHTML = slots.map(slot => `
            <tr>
                <td>${slot.slot_date}</td>
                <td>${slot.slot_time.substring(0, 5)}</td>
                <td>${slot.warehouse_name}</td>
                <td>${this.getSlotTypeText(slot.slot_type)}</td>
                <td>${slot.max_capacity}</td>
                <td>${slot.current_bookings}/${slot.max_capacity}</td>
                <td>${slot.notes || '-'}</td>
                <td>
                    <button class="btn btn-small btn-secondary" onclick="crmApp.editSlot(${slot.id})">
                        <i class="fas fa-edit"></i> Upravit
                    </button>
                    <button class="btn btn-small btn-danger" onclick="crmApp.deleteSlot(${slot.id})">
                        <i class="fas fa-trash"></i> Smazat
                    </button>
                </td>
            </tr>
        `).join('');
    }

    getSlotTypeText(type) {
        const texts = {
            loading: 'Nakládka',
            unloading: 'Vykládka',
            both: 'Nakládka/Vykládka'
        };
        return texts[type] || type;
    }

    async loadAllWarehouses() {
        console.log('CRMApp: Loading all warehouses...');
        try {
            const response = await fetch(`${this.apiBase}warehouses.php`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.updateWarehousesTable(data.warehouses);
                    } else {
                        throw new Error(data.error || 'Chyba při načítání skladů');
                    }
                }
            } else {
                throw new Error('Chyba při komunikaci se serverem');
            }
        } catch (error) {
            console.error('CRMApp: Failed to load warehouses:', error);
            this.showNotification('Chyba při načítání skladů: ' + error.message, 'error');
        }
    }

    updateWarehousesTable(warehouses) {
        console.log('CRMApp: Updating warehouses table with', warehouses.length, 'warehouses');
        const tbody = document.getElementById('warehousesTableBody');
        if (!tbody) return;

        if (warehouses.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px;">Žádné sklady</td></tr>';
            return;
        }

        tbody.innerHTML = warehouses.map(warehouse => `
            <tr>
                <td>${warehouse.name}</td>
                <td>${warehouse.address || '-'}</td>
                <td>${warehouse.contact_person || '-'}<br><small>${warehouse.contact_phone || ''}</small></td>
                <td>${warehouse.working_hours_start || '08:00'} - ${warehouse.working_hours_end || '16:00'}</td>
                <td>${warehouse.max_simultaneous_slots || 5}</td>
                <td>
                    <button class="btn btn-small btn-secondary" onclick="crmApp.editWarehouse(${warehouse.id})">
                        <i class="fas fa-edit"></i> Upravit
                    </button>
                    <button class="btn btn-small btn-danger" onclick="crmApp.deleteWarehouse(${warehouse.id})">
                        <i class="fas fa-trash"></i> Smazat
                    </button>
                </td>
            </tr>
        `).join('');
    }

    async editWarehouse(warehouseId) {
        console.log('CRMApp: Edit warehouse:', warehouseId);
        
        try {
            // Načteme data skladu
            const response = await fetch(`${this.apiBase}warehouses.php?id=${warehouseId}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                const data = JSON.parse(text);
                if (data.success && data.warehouse) {
                    // Naplníme formulář
                    const warehouse = data.warehouse;
                    document.getElementById('edit_warehouse_id').value = warehouse.id;
                    document.getElementById('edit_warehouse_name').value = warehouse.name;
                    document.getElementById('edit_warehouse_address').value = warehouse.address || '';
                    document.getElementById('edit_warehouse_contact_person').value = warehouse.contact_person || '';
                    document.getElementById('edit_warehouse_contact_phone').value = warehouse.contact_phone || '';
                    document.getElementById('edit_warehouse_contact_email').value = warehouse.contact_email || '';
                    document.getElementById('edit_warehouse_working_hours_start').value = warehouse.working_hours_start || '08:00';
                    document.getElementById('edit_warehouse_working_hours_end').value = warehouse.working_hours_end || '16:00';
                    document.getElementById('edit_warehouse_max_slots').value = warehouse.max_simultaneous_slots || 5;
                    
                    // Zobrazíme modal
                    this.showModal('editWarehouseModal');
                } else {
                    this.showNotification('Chyba při načítání dat skladu', 'error');
                }
            } else {
                this.showNotification('Chyba při komunikaci se serverem', 'error');
            }
        } catch (error) {
            console.error('CRMApp: Edit warehouse error:', error);
            this.showNotification('Chyba při načítání skladu: ' + error.message, 'error');
        }
    }

    async deleteWarehouse(warehouseId) {
        if (!confirm('Opravdu smazat tento sklad? Tato akce je nevratná.')) return;

        try {
            const response = await fetch(`${this.apiBase}warehouses.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ warehouse_id: warehouseId })
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showNotification('Sklad byl smazán', 'success');
                this.loadAllWarehouses(); // Refresh table
            } else {
                throw new Error(result.error || 'Chyba při mazání skladu');
            }
        } catch (error) {
            this.showNotification('Chyba při mazání: ' + error.message, 'error');
        }
    }

    async loadAllUsers() {
        try {
            const response = await fetch(`${this.apiBase}user.php`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.updateUsersTable(data.users);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load users:', error);
        }
    }

    updateUsersTable(users) {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;

        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">Žádní uživatelé</td></tr>';
            return;
        }

        tbody.innerHTML = users.map(user => `
            <tr>
                <td>${user.full_name}</td>
                <td>${user.email}</td>
                <td>${user.phone || '-'}</td>
                <td>${this.getUserRoleText(user.user_type)}</td>
                <td>${user.company_name || '-'}</td>
                <td>${new Date(user.created_at).toLocaleDateString('cs-CZ')}</td>
                <td>
                    <button class="btn btn-small btn-secondary" onclick="crmApp.editUser(${user.id})">
                        <i class="fas fa-edit"></i> Upravit
                    </button>
                    <button class="btn btn-small btn-danger" onclick="crmApp.deleteUser(${user.id})">
                        <i class="fas fa-trash"></i> Smazat
                    </button>
                </td>
            </tr>
        `).join('');
    }

    async editUser(userId) {
        try {
            const response = await fetch(`${this.apiBase}user.php?id=${userId}`, {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    const user = data.user;
                    
                    // Naplníme formulář
                    document.getElementById('edit_user_id').value = user.id;
                    document.getElementById('edit_user_username').value = user.username;
                    document.getElementById('edit_user_email').value = user.email;
                    document.getElementById('edit_user_full_name').value = user.full_name;
                    document.getElementById('edit_user_phone').value = user.phone || '';
                    document.getElementById('edit_user_type').value = user.user_type;
                    document.getElementById('edit_user_company').value = user.company_name || '';
                    document.getElementById('edit_user_truck_plate').value = user.truck_license_plate || '';
                    document.getElementById('edit_user_license').value = user.driver_license || '';
                    
                    // Zobrazíme/skryjeme řidičská pole
                    const driverFields = document.getElementById('editUserDriverFields');
                    if (driverFields) {
                        driverFields.style.display = user.user_type === 'driver' ? 'block' : 'none';
                    }
                    
                    this.showModal('editUserModal');
                } else {
                    this.showNotification('Chyba při načítání uživatele', 'error');
                }
            }
        } catch (error) {
            this.showNotification('Chyba při načítání uživatele: ' + error.message, 'error');
        }
    }

    async deleteUser(userId) {
        if (!confirm('Opravdu smazat tohoto uživatele?')) return;

        try {
            const response = await fetch(`${this.apiBase}user.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ user_id: userId })
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showNotification('Uživatel byl smazán', 'success');
                this.loadAllUsers(); // Refresh table
            } else {
                throw new Error(result.error || 'Chyba při mazání uživatele');
            }
        } catch (error) {
            this.showNotification('Chyba při mazání: ' + error.message, 'error');
        }
    }

    // Helper methods for status handling
    getStatusClass(status) {
        const classes = {
            pending: 'status-pending',
            confirmed: 'status-confirmed',
            in_progress: 'status-in-progress',
            completed: 'status-completed',
            cancelled: 'status-cancelled'
        };
        return classes[status] || 'status-pending';
    }

    getStatusText(status) {
        const texts = {
            pending: 'Čeká na potvrzení',
            confirmed: 'Potvrzeno',
            in_progress: 'Probíhá',
            completed: 'Dokončeno',
            cancelled: 'Zrušeno'
        };
        return texts[status] || status;
    }

    // Date helper methods
    isDateToday(date) {
        const today = new Date();
        return date.toDateString() === today.toDateString();
    }

    isDateTomorrow(date) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        return date.toDateString() === tomorrow.toDateString();
    }

    // Booking detail view
    async viewBookingDetail(bookingId) {
        try {
            const response = await fetch(`${this.apiBase}bookings.php?id=${bookingId}`, {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    // Show booking detail modal or navigate to detail page
                    this.showBookingDetailModal(data.booking);
                }
            }
        } catch (error) {
            this.showNotification('Chyba při načítání detailu rezervace', 'error');
        }
    }

    showBookingDetailModal(booking) {
        // Create and show booking detail modal
        const modalHtml = `
            <div class="modal active" id="bookingDetailModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Detail rezervace #${booking.id}</h2>
                        <button class="close-btn" onclick="crmApp.closeModal('bookingDetailModal')">×</button>
                    </div>
                    <div class="booking-detail-content">
                        <div class="detail-section">
                            <h3>Základní informace</h3>
                            <p><strong>Datum:</strong> ${booking.slot_date}</p>
                            <p><strong>Čas:</strong> ${booking.slot_time.substring(0, 5)}</p>
                            <p><strong>Sklad:</strong> ${booking.warehouse_name}</p>
                            <p><strong>Status:</strong> <span class="booking-status ${this.getStatusClass(booking.booking_status)}">${this.getStatusText(booking.booking_status)}</span></p>
                        </div>
                        <div class="detail-section">
                            <h3>Řidič a vozidlo</h3>
                            <p><strong>Řidič:</strong> ${booking.driver_name}</p>
                            <p><strong>Telefon:</strong> ${booking.driver_phone}</p>
                            <p><strong>SPZ:</strong> ${booking.truck_license_plate}</p>
                        </div>
                        <div class="detail-section">
                            <h3>Náklad</h3>
                            <p><strong>Typ:</strong> ${booking.cargo_type || '-'}</p>
                            <p><strong>Hmotnost:</strong> ${booking.cargo_weight ? (booking.cargo_weight/1000).toFixed(1) + ' t' : '-'}</p>
                            <p><strong>Odhadovaná doba:</strong> ${booking.estimated_duration || '-'} min</p>
                        </div>
                        ${booking.special_requirements ? `
                        <div class="detail-section">
                            <h3>Speciální požadavky</h3>
                            <p>${booking.special_requirements}</p>
                        </div>
                        ` : ''}
                        ${booking.booking_notes ? `
                        <div class="detail-section">
                            <h3>Poznámky</h3>
                            <p>${booking.booking_notes}</p>
                        </div>
                        ` : ''}
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary" onclick="crmApp.closeModal('bookingDetailModal')">Zavřít</button>
                        ${this.getBookingDetailActions(booking)}
                    </div>
                </div>
            </div>
        `;

        // Remove existing modal if present
        const existingModal = document.getElementById('bookingDetailModal');
        if (existingModal) {
            existingModal.remove();
        }

        // Add new modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    getBookingDetailActions(booking) {
        if (!this.currentUser || this.currentUser.user_type === 'driver') return '';
        
        const actions = [];
        
        switch (booking.booking_status) {
            case 'pending':
                actions.push(`
                    <button class="btn btn-success" onclick="confirmBooking(${booking.id}); crmApp.closeModal('bookingDetailModal')">
                        <i class="fas fa-check"></i> Potvrdit
                    </button>
                    <button class="btn btn-danger" onclick="rejectBooking(${booking.id}); crmApp.closeModal('bookingDetailModal')">
                        <i class="fas fa-times"></i> Zamítnout
                    </button>
                `);
                break;
            case 'confirmed':
                actions.push(`
                    <button class="btn btn-success" onclick="startBooking(${booking.id}); crmApp.closeModal('bookingDetailModal')">
                        <i class="fas fa-play"></i> Začít
                    </button>
                `);
                break;
            case 'in_progress':
                actions.push(`
                    <button class="btn btn-warning" onclick="completeBooking(${booking.id}); crmApp.closeModal('bookingDetailModal')">
                        <i class="fas fa-flag-checkered"></i> Dokončit
                    </button>
                `);
                break;
        }
        
        return actions.join('');
    }

    // Quick actions for sidebar
    createNewSlot() {
        this.showSection('calendar');
        setTimeout(() => this.showNewSlotModal(), 500);
    }

    addUser() {
        this.showSection('users');
        setTimeout(() => this.showModal('addUserModal'), 500);
    }

    exportData() {
        this.showNotification('Export funkcionalita bude brzy dostupná', 'info');
    }

    // Cleanup method
    destroy() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
    }
}

// Global functions for backward compatibility and inline onclick handlers
function showSection(sectionName) {
    if (window.crmApp) {
        window.crmApp.showSection(sectionName);
    }
}

function logout() {
    if (window.auth) {
        window.auth.logout();
    }
}

function showModal(modalId) {
    if (window.crmApp) {
        window.crmApp.showModal(modalId);
    }
}

function closeModal(modalId) {
    if (window.crmApp) {
        window.crmApp.closeModal(modalId);
    }
}

function refreshDashboard() {
    if (window.crmApp) {
        window.crmApp.refreshDashboard();
    }
}

function showNewSlotModal() {
    if (window.crmApp) {
        window.crmApp.showNewSlotModal();
    }
}

function createNewSlot() {
    if (window.crmApp) {
        window.crmApp.createNewSlot();
    }
}

function addUser() {
    if (window.crmApp) {
        window.crmApp.addUser();
    }
}

function exportData() {
    if (window.crmApp) {
        window.crmApp.exportData();
    }
}

function showAddWarehouseModal() {
    if (window.crmApp) {
        window.crmApp.showModal('addWarehouseModal');
    }
}

function showAddUserModal() {
    if (window.crmApp) {
        window.crmApp.showModal('addUserModal');
    }
}

function showBookingModal() {
    if (window.crmApp) {
        window.crmApp.showModal('bookingModal');
    }
}

function toggleNotifications() {
    // Placeholder for notifications toggle
    console.log('Toggle notifications - to be implemented');
}

function refreshCalendar() {
    if (window.logisticsCalendar) {
        window.logisticsCalendar.refresh();
    }
}

function filterBookings(status) {
    console.log('Filter bookings by status:', status);
    // Placeholder for booking filtering
}

function filterSlotsByDate(date) {
    console.log('Filter slots by date:', date);
    // Placeholder for slot filtering
}

// Initialize the app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Initializing CRM App...');
    window.crmApp = new CRMApp();
    
    // Dispatch event when CRM app is ready
    setTimeout(() => {
        window.dispatchEvent(new CustomEvent('crmAppReady'));
        console.log('🚀 CRM App initialization complete');
    }, 1500);
});

// Global error handler
window.addEventListener('error', (event) => {
    console.error('Global error:', event.error);
    if (window.crmApp) {
        window.crmApp.showNotification('Došlo k neočekávané chybě', 'error');
    }
});

// Global unhandled promise rejection handler
window.addEventListener('unhandledrejection', (event) => {
    console.error('Unhandled promise rejection:', event.reason);
    if (window.crmApp) {
        window.crmApp.showNotification('Došlo k chybě při komunikaci se serverem', 'error');
    }
});