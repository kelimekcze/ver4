// KLÍČOVÉ OPRAVY PRO calendar.js

// 1. OPRAVA loadWarehouses() - přidat kontrolu uživatele
    async loadWarehouses() {
        // Nepokoušet se načíst sklady pokud není uživatel přihlášen
        if (!window.crmApp || !window.crmApp.currentUser) {
            console.log('ℹ️ Skipping warehouses load - user not logged in');
            return;
        }
        
        try {
            console.log('📦 Načítání skladů...');
            const response = await fetch(`${this.apiBase}/warehouses.php`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });

            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.warehouses = data.warehouses;
                        console.log('✅ Načteno skladů:', this.warehouses.length);
                    }
                }
            } else if (response.status === 401) {
                console.log('⚠️ User not authenticated for warehouses');
                return;
            }
        } catch (error) {
            console.error('❌ Chyba při načítání skladů:', error);
        }
    }

// 2. OPRAVA loadSlots() - přidat kontrolu uživatele
    async loadSlots() {
        // Nepokoušet se načíst sloty pokud není uživatel přihlášen
        if (!window.crmApp || !window.crmApp.currentUser) {
            console.log('ℹ️ Skipping slots load - user not logged in');
            return;
        }
        
        try {
            console.log('📅 Načítání slotů pro kalendář...');
            
            const weekStart = this.getWeekStart(this.currentDate);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekEnd.getDate() + 6);

            const dateFrom = this.formatDate(weekStart);
            const dateTo = this.formatDate(weekEnd);

            console.log(`📅 Načítání slotů od ${dateFrom} do ${dateTo}`);

            let url = `${this.apiBase}/slots.php?date_from=${dateFrom}&date_to=${dateTo}`;
            if (this.selectedWarehouse) {
                url += `&warehouse_id=${this.selectedWarehouse}`;
            }

            const response = await fetch(url, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });

            if (response.ok) {
                const text = await response.text();
                console.log('📅 Raw response:', text.substring(0, 200) + '...');
                
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.slots = data.slots || [];
                        console.log('✅ Načteno slotů:', this.slots.length);
                        this.renderSlots();
                    } else {
                        console.error('❌ API vratilo chybu:', data.error);
                        this.showError(data.error || 'Chyba při načítání slotů');
                    }
                } else {
                    console.error('❌ Prázdná odpověď z API');
                }
            } else if (response.status === 401) {
                console.log('⚠️ User not authenticated for calendar');
                // Nepokazovat chybu - uživatel prostě není přihlášen
                return;
            } else {
                console.error('❌ HTTP chyba:', response.status, response.statusText);
                this.showError(`Chyba komunikace se serverem (${response.status})`);
            }
        } catch (error) {
            console.error('❌ Chyba při načítání slotů:', error);
            this.showError('Chyba při načítání slotů: ' + error.message);
        }
    }

// 3. PŘIDAT NA KONEC SOUBORU - globální inicializaci
// Globální inicializace kalendáře
console.log('📅 Calendar.js loaded');

// Initialize calendar when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('📅 DOM ready, initializing calendar...');
    
    // Initialize calendar after a short delay to ensure all dependencies are loaded
    setTimeout(() => {
        if (!window.logisticsCalendar) {
            console.log('📅 Creating LogisticsCalendar instance...');
            window.logisticsCalendar = new LogisticsCalendar();
        }
    }, 1000);
});

// Initialize when CRM app is ready
window.addEventListener('crmAppReady', function() {
    console.log('📅 CRM App ready, ensuring calendar is initialized...');
    if (!window.logisticsCalendar) {
        window.logisticsCalendar = new LogisticsCalendar();
    }
});

// Manual initialization function
function initializeCalendar() {
    console.log('📅 Manual calendar initialization...');
    if (window.logisticsCalendar) {
        console.log('📅 Calendar already exists, refreshing...');
        window.logisticsCalendar.refresh();
    } else {
        console.log('📅 Creating new calendar instance...');
        window.logisticsCalendar = new LogisticsCalendar();
    }
}

// Export functions for global access
window.initializeCalendar = initializeCalendar;
window.refreshCalendar = function() {
    if (window.logisticsCalendar) {
        window.logisticsCalendar.refresh();
    }
};

console.log('✅ Calendar module fully loaded');