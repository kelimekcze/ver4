// js/booking.js - Booking Management with Slot Editing and Warehouse Functions (OPRAVENO)
class BookingManager {
    constructor() {
        this.apiBase = 'api/'; // API soubory jsou v api/ adresáři
        this.setupEventListeners();
    }

    setupEventListeners() {
        console.log('BookingManager: Setting up event listeners...');
        
        // Booking form
        const bookingForm = document.getElementById('bookingForm');
        if (bookingForm) {
            bookingForm.addEventListener('submit', this.handleBooking.bind(this));
            console.log('BookingManager: ✅ Booking form listener attached');
        }
        
        // New slot form
        const newSlotForm = document.getElementById('newSlotForm');
        if (newSlotForm) {
            newSlotForm.addEventListener('submit', this.handleNewSlot.bind(this));
            console.log('BookingManager: ✅ New slot form listener attached');
        }
        
        // Edit booking form
        const editBookingForm = document.getElementById('editBookingForm');
        if (editBookingForm) {
            editBookingForm.addEventListener('submit', this.handleEditBooking.bind(this));
            console.log('BookingManager: ✅ Edit booking form listener attached');
        }
        
        // Edit slot form
        const editSlotForm = document.getElementById('editSlotForm');
        if (editSlotForm) {
            editSlotForm.addEventListener('submit', this.handleEditSlot.bind(this));
            console.log('BookingManager: ✅ Edit slot form listener attached');
        }
        
        // User forms
        const addUserForm = document.getElementById('addUserForm');
        if (addUserForm) {
            addUserForm.addEventListener('submit', this.handleAddUser.bind(this));
            console.log('BookingManager: ✅ Add user form listener attached');
        }
        
        const editUserForm = document.getElementById('editUserForm');
        if (editUserForm) {
            editUserForm.addEventListener('submit', this.handleEditUser.bind(this));
            console.log('BookingManager: ✅ Edit user form listener attached');
        }
        
        // Warehouse forms
        const addWarehouseForm = document.getElementById('addWarehouseForm');
        if (addWarehouseForm) {
            addWarehouseForm.addEventListener('submit', this.handleAddWarehouse.bind(this));
            console.log('BookingManager: ✅ Add warehouse form listener attached');
        }
        
        const editWarehouseForm = document.getElementById('editWarehouseForm');
        if (editWarehouseForm) {
            editWarehouseForm.addEventListener('submit', this.handleEditWarehouse.bind(this));
            console.log('BookingManager: ✅ Edit warehouse form listener attached');
        }
        
        // Date change for booking
        const bookingDate = document.getElementById('booking_date');
        if (bookingDate) {
            bookingDate.addEventListener('change', this.loadAvailableSlots.bind(this));
        }
        
        // Warehouse change for booking
        const bookingWarehouse = document.getElementById('booking_warehouse');
        if (bookingWarehouse) {
            bookingWarehouse.addEventListener('change', this.loadAvailableSlots.bind(this));
        }
        
        // Date change for edit booking
        const editBookingDate = document.getElementById('edit_booking_date');
        if (editBookingDate) {
            editBookingDate.addEventListener('change', this.loadAvailableSlotsForEdit.bind(this));
        }
        
        // Warehouse change for edit booking
        const editBookingWarehouse = document.getElementById('edit_booking_warehouse');
        if (editBookingWarehouse) {
            editBookingWarehouse.addEventListener('change', this.loadAvailableSlotsForEdit.bind(this));
        }

        console.log('✅ BookingManager: Event listeners set up successfully');
    }

    // =============== SLOT EDITING FUNCTIONS ===============

    async loadSlotForEdit(slotId) {
        try {
            console.log('BookingManager: Loading slot for edit:', slotId);
            
            const response = await fetch(`${this.apiBase}slots.php?id=${slotId}`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success && data.slot) {
                        console.log('BookingManager: Slot data loaded:', data.slot);
                        return data.slot;
                    } else {
                        throw new Error(data.error || 'Slot nenalezen');
                    }
                }
            } else {
                throw new Error('Chyba při načítání slotu');
            }
        } catch (error) {
            console.error('BookingManager: Failed to load slot:', error);
            this.showError('Chyba při načítání slotu: ' + error.message);
            return null;
        }
    }

    async populateEditSlotForm(slotId) {
        try {
            const slot = await this.loadSlotForEdit(slotId);
            if (!slot) return false;

            // Fill form fields
            document.getElementById('edit_slot_id').value = slot.id;
            document.getElementById('edit_slot_warehouse').value = slot.warehouse_id;
            document.getElementById('edit_slot_date').value = slot.slot_date;
            document.getElementById('edit_slot_time').value = slot.slot_time.substring(0, 5);
            document.getElementById('edit_slot_duration').value = slot.duration_minutes;
            document.getElementById('edit_slot_capacity').value = slot.max_capacity;
            document.getElementById('edit_slot_type').value = slot.slot_type;
            document.getElementById('edit_slot_notes').value = slot.notes || '';

            // Show warning if slot has active bookings
            const warningDiv = document.getElementById('edit_slot_warning');
            if (slot.current_bookings > 0) {
                if (warningDiv) {
                    warningDiv.innerHTML = `
                        <div class="alert-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Upozornění:</strong> Tento slot má ${slot.current_bookings} aktivní rezervac${slot.current_bookings === 1 ? 'i' : 'e'}. 
                            Lze změnit pouze poznámky a kapacitu.
                        </div>
                    `;
                    warningDiv.style.display = 'block';
                }

                // Disable fields that can't be changed
                document.getElementById('edit_slot_warehouse').disabled = true;
                document.getElementById('edit_slot_date').disabled = true;
                document.getElementById('edit_slot_time').disabled = true;
                document.getElementById('edit_slot_duration').disabled = true;
                document.getElementById('edit_slot_type').disabled = true;
            } else {
                if (warningDiv) {
                    warningDiv.style.display = 'none';
                }
                
                // Enable all fields
                document.getElementById('edit_slot_warehouse').disabled = false;
                document.getElementById('edit_slot_date').disabled = false;
                document.getElementById('edit_slot_time').disabled = false;
                document.getElementById('edit_slot_duration').disabled = false;
                document.getElementById('edit_slot_type').disabled = false;
            }

            // Load warehouses for dropdown
            await this.loadWarehousesForForm('edit_slot_warehouse');
            document.getElementById('edit_slot_warehouse').value = slot.warehouse_id;

            return true;
        } catch (error) {
            console.error('BookingManager: Error populating edit form:', error);
            this.showError('Chyba při načítání formuláře: ' + error.message);
            return false;
        }
    }

    async handleEditSlot(e) {
        e.preventDefault();
        
        console.log('BookingManager: Edit slot form submitted');
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        data.slot_id = parseInt(data.slot_id);
        console.log('BookingManager: Edit slot data:', data);

        if (!this.validateSlotData(data)) {
            return;
        }

        try {
            const response = await fetch(`${this.apiBase}slots.php`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            console.log('BookingManager: Edit slot response:', text);
            
            const result = text ? JSON.parse(text) : {};
            
            if (response.ok && result.success) {
                this.showSuccess('Časový slot byl úspěšně aktualizován!');
                this.closeModal('editSlotModal');
                
                if (window.crmApp) {
                    if (document.getElementById('slots').classList.contains('active')) {
                        await window.crmApp.loadAllSlots();
                    }
                    
                    if (window.calendarManager && document.getElementById('calendar').classList.contains('active')) {
                        window.calendarManager.generateCalendar();
                    }
                }
            } else {
                throw new Error(result.error || 'Chyba při aktualizaci slotu');
            }
        } catch (error) {
            console.error('BookingManager: Edit slot error:', error);
            this.showError('Chyba při aktualizaci slotu: ' + error.message);
        }
    }

    async showEditSlotModal(slotId) {
        console.log('BookingManager: Showing edit slot modal for slot:', slotId);
        
        const warningDiv = document.getElementById('edit_slot_warning');
        if (warningDiv) {
            warningDiv.style.display = 'none';
        }

        const success = await this.populateEditSlotForm(slotId);
        if (success) {
            this.showModal('editSlotModal');
        }
    }

    // =============== BOOKING FORM HANDLERS ===============

    async handleBooking(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        // Validation
        if (!data.time_slot_id) {
            this.showError('Vyberte časový slot');
            return;
        }

        if (!this.isValidLicensePlate(data.truck_license_plate)) {
            this.showError('Neplatný formát SPZ (např. 1A2 3456)');
            return;
        }

        try {
            const response = await fetch(this.apiBase + '/bookings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            
            if (!text) {
                throw new Error('Server vrátil prázdnou odpověď');
            }

            const result = JSON.parse(text);
            
            if (response.ok && result.success) {
                this.showSuccess('Rezervace byla úspěšně vytvořena!');
                this.closeModal('bookingModal');
                
                if (window.crmApp) {
                    await window.crmApp.loadDashboardData();
                    
                    if (document.getElementById('bookings').classList.contains('active')) {
                        await window.crmApp.loadAllBookings();
                    }
                }
                
                document.getElementById('bookingForm').reset();
            } else {
                throw new Error(result.error || 'Chyba při vytváření rezervace');
            }
        } catch (error) {
            console.error('Booking error:', error);
            this.showError('Chyba při vytváření rezervace: ' + error.message);
        }
    }

    async handleNewSlot(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        if (!this.validateSlotData(data, false)) {
            return;
        }

        try {
            const response = await fetch(this.apiBase + '/slots.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            const result = text ? JSON.parse(text) : {};
            
            if (response.ok && result.success) {
                this.showSuccess('Časový slot byl úspěšně vytvořen!');
                this.closeModal('newSlotModal');
                document.getElementById('newSlotForm').reset();
                
                if (window.calendarManager && document.getElementById('calendar').classList.contains('active')) {
                    window.calendarManager.generateCalendar();
                }
                
                if (window.crmApp && document.getElementById('slots').classList.contains('active')) {
                    await window.crmApp.loadAllSlots();
                }
            } else {
                throw new Error(result.error || 'Chyba při vytváření slotu');
            }
        } catch (error) {
            console.error('Slot creation error:', error);
            this.showError('Chyba při vytváření slotu: ' + error.message);
        }
    }

    async handleEditBooking(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        try {
            const response = await fetch(this.apiBase + '/bookings.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            const result = text ? JSON.parse(text) : {};
            
            if (response.ok && result.success) {
                this.showSuccess('Rezervace byla úspěšně aktualizována!');
                this.closeModal('editBookingModal');
                
                if (window.crmApp) {
                    await window.crmApp.loadDashboardData();
                    await window.crmApp.loadAllBookings();
                }
            } else {
                throw new Error(result.error || 'Chyba při aktualizaci rezervace');
            }
        } catch (error) {
            console.error('Edit booking error:', error);
            this.showError('Chyba při aktualizaci rezervace: ' + error.message);
        }
    }

    // =============== SLOT LOADING FUNCTIONS ===============

    async loadAvailableSlots() {
        const warehouseId = document.getElementById('booking_warehouse').value;
        const date = document.getElementById('booking_date').value;
        
        const slotSelect = document.getElementById('booking_slot');
        slotSelect.innerHTML = '<option value="">Načítání...</option>';
        
        if (!warehouseId || !date) {
            slotSelect.innerHTML = '<option value="">Nejprve vyberte sklad a datum</option>';
            return;
        }

        try {
            const response = await fetch(`${this.apiBase}slots.php?warehouse_id=${warehouseId}&date=${date}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.updateSlotOptions(data.slots);
                    } else {
                        throw new Error(data.error || 'Chyba při načítání slotů');
                    }
                }
            } else {
                throw new Error('Chyba při komunikaci se serverem');
            }
        } catch (error) {
            console.error('Failed to load slots:', error);
            slotSelect.innerHTML = '<option value="">Chyba při načítání slotů</option>';
        }
    }

    updateSlotOptions(slots) {
        const slotSelect = document.getElementById('booking_slot');
        
        if (slots.length === 0) {
            slotSelect.innerHTML = '<option value="">Žádné dostupné sloty</option>';
            return;
        }

        slotSelect.innerHTML = '<option value="">Vyberte časový slot</option>';
        
        slots.forEach(slot => {
            const available = slot.max_capacity - slot.current_bookings;
            if (available > 0) {
                const option = document.createElement('option');
                option.value = slot.id;
                const endTime = this.addMinutes(slot.slot_time, slot.duration_minutes);
                option.textContent = `${slot.slot_time.substring(0, 5)} - ${endTime} (${available}/${slot.max_capacity} volné)`;
                slotSelect.appendChild(option);
            }
        });
    }

    async loadAvailableSlotsForEdit() {
        const warehouseId = document.getElementById('edit_booking_warehouse').value;
        const date = document.getElementById('edit_booking_date').value;
        const currentSlotId = document.getElementById('edit_booking_slot').value;
        
        const slotSelect = document.getElementById('edit_booking_slot');
        slotSelect.innerHTML = '<option value="">Načítání...</option>';
        
        if (!warehouseId || !date) {
            slotSelect.innerHTML = '<option value="">Nejprve vyberte sklad a datum</option>';
            return;
        }

        try {
            const response = await fetch(`${this.apiBase}slots.php?warehouse_id=${warehouseId}&date=${date}&include_current=${currentSlotId}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        slotSelect.innerHTML = '<option value="">Vyberte časový slot</option>';
                        
                        data.slots.forEach(slot => {
                            const option = document.createElement('option');
                            option.value = slot.id;
                            const endTime = this.addMinutes(slot.slot_time, slot.duration_minutes);
                            option.textContent = `${slot.slot_time.substring(0, 5)} - ${endTime}`;
                            if (slot.id == currentSlotId) {
                                option.selected = true;
                            }
                            slotSelect.appendChild(option);
                        });
                    } else {
                        throw new Error(data.error || 'Chyba při načítání slotů');
                    }
                }
            } else {
                throw new Error('Chyba při komunikaci se serverem');
            }
        } catch (error) {
            console.error('Failed to load slots for edit:', error);
            slotSelect.innerHTML = '<option value="">Chyba při načítání slotů</option>';
        }
    }

    // =============== USER MANAGEMENT ===============

    async handleAddUser(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        try {
            const response = await fetch(this.apiBase + '/user.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            const result = text ? JSON.parse(text) : {};
            
            if (response.ok && result.success) {
                this.showSuccess('Uživatel byl úspěšně vytvořen!');
                this.closeModal('addUserModal');
                document.getElementById('addUserForm').reset();
                
                if (window.crmApp) {
                    await window.crmApp.loadAllUsers();
                }
            } else {
                throw new Error(result.error || 'Chyba při vytváření uživatele');
            }
        } catch (error) {
            console.error('Add user error:', error);
            this.showError('Chyba při vytváření uživatele: ' + error.message);
        }
    }

    async handleEditUser(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        try {
            const response = await fetch(this.apiBase + '/user.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            const result = text ? JSON.parse(text) : {};
            
            if (response.ok && result.success) {
                this.showSuccess('Uživatel byl úspěšně aktualizován!');
                this.closeModal('editUserModal');
                
                if (window.crmApp) {
                    await window.crmApp.loadAllUsers();
                }
            } else {
                throw new Error(result.error || 'Chyba při aktualizaci uživatele');
            }
        } catch (error) {
            console.error('Edit user error:', error);
            this.showError('Chyba při aktualizaci uživatele: ' + error.message);
        }
    }

    // =============== WAREHOUSE MANAGEMENT ===============

    async handleAddWarehouse(e) {
        e.preventDefault();
        
        console.log('BookingManager: Add warehouse form submitted');
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        console.log('BookingManager: Add warehouse data:', data);

        if (!data.name || data.name.trim() === '') {
            this.showError('Název skladu je povinný');
            return;
        }

        try {
            const response = await fetch(this.apiBase + '/warehouses.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            console.log('BookingManager: Add warehouse response:', text);
            
            if (!text) {
                throw new Error('Server vrátil prázdnou odpověď');
            }
            
            const result = JSON.parse(text);
            
            if (response.ok && result.success) {
                this.showSuccess('Sklad byl úspěšně vytvořen!');
                this.closeModal('addWarehouseModal');
                document.getElementById('addWarehouseForm').reset();
                
                if (window.crmApp && document.getElementById('warehouses').classList.contains('active')) {
                    await window.crmApp.loadAllWarehouses();
                }
                
                await this.loadWarehousesForForm('booking_warehouse');
                await this.loadWarehousesForForm('slot_warehouse');
                await this.loadWarehousesForForm('edit_slot_warehouse');
                
            } else {
                throw new Error(result.error || result.message || 'Chyba při vytváření skladu');
            }
        } catch (error) {
            console.error('BookingManager: Add warehouse error:', error);
            this.showError('Chyba při vytváření skladu: ' + error.message);
        }
    }

    async handleEditWarehouse(e) {
        e.preventDefault();
        
        console.log('BookingManager: Edit warehouse form submitted');
        
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());

        console.log('BookingManager: Edit warehouse data:', data);

        if (!data.name || data.name.trim() === '') {
            this.showError('Název skladu je povinný');
            return;
        }

        if (!data.warehouse_id) {
            this.showError('ID skladu je povinné');
            return;
        }

        try {
            const response = await fetch(this.apiBase + '/warehouses.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });

            const text = await response.text();
            console.log('BookingManager: Edit warehouse response:', text);
            
            if (!text) {
                throw new Error('Server vrátil prázdnou odpověď');
            }
            
            const result = JSON.parse(text);
            
            if (response.ok && result.success) {
                this.showSuccess('Sklad byl úspěšně aktualizován!');
                this.closeModal('editWarehouseModal');
                
                if (window.crmApp && document.getElementById('warehouses').classList.contains('active')) {
                    await window.crmApp.loadAllWarehouses();
                }
                
                await this.loadWarehousesForForm('booking_warehouse');
                await this.loadWarehousesForForm('slot_warehouse');
                await this.loadWarehousesForForm('edit_slot_warehouse');
                
            } else {
                throw new Error(result.error || result.message || 'Chyba při aktualizaci skladu');
            }
        } catch (error) {
            console.error('BookingManager: Edit warehouse error:', error);
            this.showError('Chyba při aktualizaci skladu: ' + error.message);
        }
    }

    // =============== VALIDATION AND HELPER METHODS ===============

    validateSlotData(data, isEdit = false) {
        const errors = [];

        if (data.slot_date) {
            const slotDate = new Date(data.slot_date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (!isEdit && slotDate < today) {
                errors.push('Nelze vytvořit slot v minulosti');
            }
        }

        if (data.slot_time && !data.slot_time.match(/^\d{2}:\d{2}$/)) {
            errors.push('Neplatný formát času');
        }

        if (data.duration_minutes) {
            const duration = parseInt(data.duration_minutes);
            if (duration < 15 || duration > 480) {
                errors.push('Délka slotu musí být mezi 15 a 480 minuty');
            }
        }

        if (data.max_capacity) {
            const capacity = parseInt(data.max_capacity);
            if (capacity < 1 || capacity > 50) {
                errors.push('Kapacita musí být mezi 1 a 50');
            }
        }

        if (errors.length > 0) {
            this.showError('Chyby ve validaci:\n' + errors.join('\n'));
            return false;
        }

        return true;
    }

    isValidLicensePlate(plate) {
        const plateRegex = /^[A-Z0-9]{2,3}\s?[0-9]{4}$/;
        return plateRegex.test(plate.toUpperCase());
    }

    addMinutes(time, minutes) {
        const [hours, mins] = time.split(':').map(Number);
        const totalMinutes = hours * 60 + mins + minutes;
        const newHours = Math.floor(totalMinutes / 60);
        const newMins = totalMinutes % 60;
        return `${String(newHours).padStart(2, '0')}:${String(newMins).padStart(2, '0')}`;
    }

    showError(message) {
        console.error('BookingManager: Error -', message);
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'error');
        } else {
            alert(message);
        }
    }

    showSuccess(message) {
        console.log('BookingManager: Success -', message);
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'success');
        } else {
            alert(message);
        }
    }

    closeModal(modalId) {
        if (window.crmApp) {
            window.crmApp.closeModal(modalId);
        } else {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
            }
        }
    }

    showModal(modalId) {
        if (window.crmApp) {
            window.crmApp.showModal(modalId);
        } else {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
            }
        }
    }

    // =============== WAREHOUSE LOADING ===============

    async loadWarehousesForForm(selectId) {
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
                    }
                }
            }
        } catch (error) {
            console.error('BookingManager: Failed to load warehouses:', error);
        }
    }
}

// Global booking manager initialization
console.log('📋 Booking.js loaded');

// Initialize booking manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('📋 DOM ready, initializing booking manager...');
    
    setTimeout(() => {
        if (!window.bookingManager) {
            console.log('📋 Creating BookingManager instance...');
            window.bookingManager = new BookingManager();
        }
    }, 500);
});

// Initialize when CRM app is ready
window.addEventListener('crmAppReady', function() {
    console.log('📋 CRM App ready, ensuring booking manager is initialized...');
    if (!window.bookingManager) {
        window.bookingManager = new BookingManager();
    }
});

console.log('✅ Booking module fully loaded');