// js/calendar.js - Moderní kalendář s drag & drop a správným zobrazením slotů
class LogisticsCalendar {
    constructor(containerId = 'timeSlotsGrid') {
        this.containerId = containerId;
        this.container = null;
        this.currentDate = new Date();
        this.selectedWarehouse = null;
        this.slots = [];
        this.warehouses = [];
        this.apiBase = 'api';
        this.draggedSlot = null;
        this.isInitialized = false;
        
        this.init();
    }

    async init() {
        console.log('🗓️ Inicializace kalendáře...');
        
        // Najít kontejner
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error('❌ Kalendář kontejner nenalezen:', this.containerId);
            return;
        }

        await this.loadWarehouses();
        this.generateCalendarStructure();
        this.setupEventListeners();
        await this.loadSlots();
        
        this.isInitialized = true;
        console.log('✅ Kalendář byl úspěšně inicializován');
    }

    async loadWarehouses() {
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
            }
        } catch (error) {
            console.error('❌ Chyba při načítání skladů:', error);
        }
    }

    generateCalendarStructure() {
        console.log('🏗️ Generování struktury kalendáře...');
        
        const calendarHtml = `
            <div class="logistics-calendar">
                <!-- Calendar Controls -->
                <div class="calendar-header-controls">
                    <div class="week-navigation">
                        <button class="nav-btn" onclick="logisticsCalendar.previousWeek()">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="current-week" id="currentWeekDisplay">
                            ${this.getWeekDisplayText()}
                        </div>
                        <button class="nav-btn" onclick="logisticsCalendar.nextWeek()">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="calendar-actions">
                        <select class="warehouse-selector" id="warehouseFilter" onchange="logisticsCalendar.filterByWarehouse(this.value)">
                            <option value="">Všechny sklady</option>
                            ${this.warehouses.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
                        </select>
                        <button class="today-btn" onclick="logisticsCalendar.goToToday()">Dnes</button>
                    </div>
                </div>

                <!-- Status Legend -->
                <div class="status-legend">
                    <div class="status-item">
                        <div class="status-color slot-unloading"></div>
                        <span>Vykládka</span>
                    </div>
                    <div class="status-item">
                        <div class="status-color slot-loading"></div>
                        <span>Nakládka</span>
                    </div>
                    <div class="status-item">
                        <div class="status-color slot-both"></div>
                        <span>Nakládka/Vykládka</span>
                    </div>
                    <div class="status-item">
                        <div class="status-color slot-reserved"></div>
                        <span>Rezervováno</span>
                    </div>
                </div>

                <!-- Calendar Grid -->
                <div class="calendar-container">
                    <div class="calendar-grid" id="calendarGrid">
                        ${this.generateWeeklyCalendar()}
                    </div>
                </div>
            </div>
        `;

        this.container.innerHTML = calendarHtml;
    }

    generateWeeklyCalendar() {
        const weekStart = this.getWeekStart(this.currentDate);
        const days = this.getWeekDays(weekStart);
        const timeSlots = this.generateTimeSlots();

        let html = `
            <!-- Calendar Header -->
            <div class="calendar-header-row">
                <div class="time-header">Čas</div>
                ${days.map(day => `
                    <div class="day-header ${this.isToday(day) ? 'today' : ''}">
                        <div class="day-name">${this.getDayName(day)}</div>
                        <div class="day-date">${day.getDate()}</div>
                    </div>
                `).join('')}
            </div>
        `;

        // Time rows
        timeSlots.forEach(timeSlot => {
            html += `
                <div class="calendar-time-row" data-time="${timeSlot}">
                    <div class="time-cell">${timeSlot}</div>
                    ${days.map(day => `
                        <div class="day-cell" 
                             data-date="${this.formatDate(day)}" 
                             data-time="${timeSlot}"
                             ondrop="logisticsCalendar.handleDrop(event)"
                             ondragover="logisticsCalendar.handleDragOver(event)"
                             onclick="logisticsCalendar.handleCellClick(event)">
                            <div class="slot-container" id="slot-${this.formatDate(day)}-${timeSlot.replace(':', '')}">
                                <!-- Sloty budou vloženy zde -->
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        });

        return html;
    }

    async loadSlots() {
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
            } else {
                console.error('❌ HTTP chyba:', response.status, response.statusText);
                this.showError(`Chyba komunikace se serverem (${response.status})`);
            }
        } catch (error) {
            console.error('❌ Chyba při načítání slotů:', error);
            this.showError('Chyba při načítání slotů: ' + error.message);
        }
    }

    renderSlots() {
        console.log('🎨 Vykreslování slotů v kalendáři...');
        
        // Vyčistit všechny kontejnery
        document.querySelectorAll('.slot-container').forEach(container => {
            container.innerHTML = '';
        });

        // Vykreslit každý slot
        this.slots.forEach(slot => {
            this.renderSlot(slot);
        });

        console.log('✅ Vykresleno slotů:', this.slots.length);
    }

    renderSlot(slot) {
        const slotDate = slot.slot_date;
        const slotTime = slot.slot_time.substring(0, 5); // HH:MM format
        const containerId = `slot-${slotDate}-${slotTime.replace(':', '')}`;
        const container = document.getElementById(containerId);

        if (!container) {
            console.warn(`⚠️ Kontejner nenalezen pro slot: ${containerId}`);
            return;
        }

        const slotElement = this.createSlotElement(slot);
        container.appendChild(slotElement);
    }

    createSlotElement(slot) {
        const slotDiv = document.createElement('div');
        slotDiv.className = `slot-item slot-${slot.slot_type}`;
        slotDiv.draggable = true;
        slotDiv.dataset.slotId = slot.id;

        // Výpočet dostupnosti
        const available = slot.max_capacity - (slot.current_bookings || 0);
        const isAvailable = available > 0;

        slotDiv.innerHTML = `
            <div class="slot-content">
                <div class="slot-time">${slot.slot_time.substring(0, 5)}</div>
                <div class="slot-warehouse">${slot.warehouse_name}</div>
                <div class="slot-type">${this.getSlotTypeText(slot.slot_type)}</div>
                <div class="slot-capacity ${!isAvailable ? 'full' : ''}">${slot.current_bookings || 0}/${slot.max_capacity}</div>
                ${slot.notes ? `<div class="slot-notes">${slot.notes}</div>` : ''}
            </div>
            <div class="slot-actions">
                <button class="slot-action-btn edit" onclick="logisticsCalendar.editSlot(${slot.id})" title="Upravit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="slot-action-btn delete" onclick="logisticsCalendar.deleteSlot(${slot.id})" title="Smazat">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;

        // Event listenery pro drag & drop
        slotDiv.addEventListener('dragstart', (e) => this.handleDragStart(e));
        slotDiv.addEventListener('dragend', (e) => this.handleDragEnd(e));

        // Click handler pro detail
        slotDiv.addEventListener('click', (e) => {
            if (!e.target.closest('.slot-action-btn')) {
                this.showSlotDetail(slot);
            }
        });

        return slotDiv;
    }

    getSlotTypeText(type) {
        const types = {
            'loading': 'Nakládka',
            'unloading': 'Vykládka', 
            'both': 'Nakládka/Vykládka'
        };
        return types[type] || type;
    }

    // Navigation methods
    previousWeek() {
        this.currentDate.setDate(this.currentDate.getDate() - 7);
        this.refresh();
    }

    nextWeek() {
        this.currentDate.setDate(this.currentDate.getDate() + 7);
        this.refresh();
    }

    goToToday() {
        this.currentDate = new Date();
        this.refresh();
    }

    refresh() {
        console.log('🔄 Obnovování kalendáře...');
        const weekDisplay = document.getElementById('currentWeekDisplay');
        if (weekDisplay) {
            weekDisplay.textContent = this.getWeekDisplayText();
        }
        
        const calendarGrid = document.getElementById('calendarGrid');
        if (calendarGrid) {
            calendarGrid.innerHTML = this.generateWeeklyCalendar();
        }
        
        this.loadSlots();
    }

    filterByWarehouse(warehouseId) {
        this.selectedWarehouse = warehouseId || null;
        console.log('🏭 Filtrování podle skladu:', warehouseId);
        this.loadSlots();
    }

    // Drag & Drop handlers
    handleDragStart(e) {
        this.draggedSlot = {
            id: e.target.dataset.slotId,
            element: e.target
        };
        e.target.classList.add('dragging');
        console.log('🎯 Začátek přetahování slotu:', this.draggedSlot.id);
    }

    handleDragEnd(e) {
        e.target.classList.remove('dragging');
        document.querySelectorAll('.drop-highlight').forEach(cell => {
            cell.classList.remove('drop-highlight');
        });
        this.draggedSlot = null;
    }

    handleDragOver(e) {
        e.preventDefault();
        e.currentTarget.classList.add('drop-highlight');
    }

    async handleDrop(e) {
        e.preventDefault();
        e.currentTarget.classList.remove('drop-highlight');

        if (!this.draggedSlot) return;

        const targetDate = e.currentTarget.dataset.date;
        const targetTime = e.currentTarget.dataset.time;

        console.log(`🎯 Přesun slotu ${this.draggedSlot.id} na ${targetDate} ${targetTime}`);

        try {
            const response = await fetch(`${this.apiBase}/slots.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    slot_id: this.draggedSlot.id,
                    slot_date: targetDate,
                    slot_time: targetTime + ':00'
                })
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showSuccess('Slot byl úspěšně přesunut');
                this.loadSlots();
            } else {
                throw new Error(result.error || 'Chyba při přesunu slotu');
            }
        } catch (error) {
            console.error('❌ Chyba při přesunu slotu:', error);
            this.showError('Chyba při přesunu: ' + error.message);
        }
    }

    // Cell click handler for creating new slots
    handleCellClick(e) {
        if (e.target.closest('.slot-item')) return; // Neotevírat modal pokud klikáme na existující slot

        const date = e.currentTarget.dataset.date;
        const time = e.currentTarget.dataset.time;
        
        console.log(`📅 Klik na buňku: ${date} ${time}`);
        this.showBookingModal(date, time);
    }

    // Modal methods
    showBookingModal(date = null, time = null) {
        const today = new Date().toISOString().split('T')[0];
        const currentTime = new Date().toTimeString().substring(0, 5);

        const modalHtml = `
            <div class="modal active" id="calendarSlotModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Nový časový slot</h2>
                        <button class="close-btn" onclick="logisticsCalendar.closeBookingModal()">×</button>
                    </div>
                    <form id="calendarSlotForm" onsubmit="logisticsCalendar.handleSlotSubmit(event)">
                        <div class="form-group">
                            <label for="modal_warehouse">Sklad:</label>
                            <select id="modal_warehouse" name="warehouse_id" required>
                                <option value="">Vyberte sklad</option>
                                ${this.warehouses.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="modal_date">Datum:</label>
                                <input type="date" id="modal_date" name="slot_date" value="${date || today}" required>
                            </div>
                            <div class="form-group">
                                <label for="modal_time">Čas:</label>
                                <input type="time" id="modal_time" name="slot_time" value="${time || currentTime}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="modal_duration">Délka (min):</label>
                                <input type="number" id="modal_duration" name="duration_minutes" value="60" min="15" max="480" required>
                            </div>
                            <div class="form-group">
                                <label for="modal_capacity">Kapacita:</label>
                                <input type="number" id="modal_capacity" name="max_capacity" value="1" min="1" max="50" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="modal_type">Typ:</label>
                            <select id="modal_type" name="slot_type" required>
                                <option value="unloading">Vykládka</option>
                                <option value="loading">Nakládka</option>
                                <option value="both">Nakládka/Vykládka</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="modal_notes">Poznámky:</label>
                            <textarea id="modal_notes" name="notes" rows="3"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="logisticsCalendar.closeBookingModal()">
                                Zrušit
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Vytvořit slot
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        // Remove existing modal
        const existingModal = document.getElementById('calendarSlotModal');
        if (existingModal) {
            existingModal.remove();
        }

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    closeBookingModal() {
        const modal = document.getElementById('calendarSlotModal');
        if (modal) {
            modal.remove();
        }
    }

    async handleSlotSubmit(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const slotData = Object.fromEntries(formData.entries());
        
        console.log('💾 Vytváření nového slotu:', slotData);

        try {
            const response = await fetch(`${this.apiBase}/slots.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(slotData)
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showSuccess('Slot byl úspěšně vytvořen');
                this.closeBookingModal();
                this.loadSlots();
            } else {
                throw new Error(result.error || 'Chyba při vytváření slotu');
            }
        } catch (error) {
            console.error('❌ Chyba při vytváření slotu:', error);
            this.showError('Chyba při vytváření: ' + error.message);
        }
    }

    // Slot management methods
    async editSlot(slotId) {
        console.log('✏️ Úprava slotu:', slotId);
        
        try {
            const response = await fetch(`${this.apiBase}/slots.php?id=${slotId}`, {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    this.showEditSlotModal(data.slot);
                } else {
                    this.showError('Chyba při načítání slotu');
                }
            }
        } catch (error) {
            console.error('❌ Chyba při načítání slotu:', error);
            this.showError('Chyba při načítání slotu: ' + error.message);
        }
    }

    showEditSlotModal(slot) {
        const modalHtml = `
            <div class="modal active" id="calendarEditSlotModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Upravit slot #${slot.id}</h2>
                        <button class="close-btn" onclick="logisticsCalendar.closeEditSlotModal()">×</button>
                    </div>
                    <form id="calendarEditSlotForm" onsubmit="logisticsCalendar.handleEditSlotSubmit(event)">
                        <input type="hidden" name="slot_id" value="${slot.id}">
                        <div class="form-group">
                            <label for="edit_modal_warehouse">Sklad:</label>
                            <select id="edit_modal_warehouse" name="warehouse_id" required>
                                ${this.warehouses.map(w => `<option value="${w.id}" ${w.id == slot.warehouse_id ? 'selected' : ''}>${w.name}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_modal_date">Datum:</label>
                                <input type="date" id="edit_modal_date" name="slot_date" value="${slot.slot_date}" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_modal_time">Čas:</label>
                                <input type="time" id="edit_modal_time" name="slot_time" value="${slot.slot_time.substring(0, 5)}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_modal_duration">Délka (min):</label>
                                <input type="number" id="edit_modal_duration" name="duration_minutes" value="${slot.duration_minutes}" min="15" max="480" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_modal_capacity">Kapacita:</label>
                                <input type="number" id="edit_modal_capacity" name="max_capacity" value="${slot.max_capacity}" min="1" max="50" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="edit_modal_type">Typ:</label>
                            <select id="edit_modal_type" name="slot_type" required>
                                <option value="unloading" ${slot.slot_type === 'unloading' ? 'selected' : ''}>Vykládka</option>
                                <option value="loading" ${slot.slot_type === 'loading' ? 'selected' : ''}>Nakládka</option>
                                <option value="both" ${slot.slot_type === 'both' ? 'selected' : ''}>Nakládka/Vykládka</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_modal_notes">Poznámky:</label>
                            <textarea id="edit_modal_notes" name="notes" rows="3">${slot.notes || ''}</textarea>
                        </div>
                        ${slot.current_bookings > 0 ? `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Tento slot má ${slot.current_bookings} aktivních rezervací. 
                            Některé změny nemusí být možné.
                        </div>
                        ` : ''}
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="logisticsCalendar.closeEditSlotModal()">
                                Zrušit
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Uložit změny
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        // Remove existing modal
        const existingModal = document.getElementById('calendarEditSlotModal');
        if (existingModal) {
            existingModal.remove();
        }

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    closeEditSlotModal() {
        const modal = document.getElementById('calendarEditSlotModal');
        if (modal) {
            modal.remove();
        }
    }

    async handleEditSlotSubmit(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const slotData = Object.fromEntries(formData.entries());
        
        console.log('💾 Úprava slotu:', slotData);

        try {
            const response = await fetch(`${this.apiBase}/slots.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(slotData)
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showSuccess('Slot byl úspěšně upraven');
                this.closeEditSlotModal();
                this.loadSlots();
            } else {
                throw new Error(result.error || 'Chyba při úpravě slotu');
            }
        } catch (error) {
            console.error('❌ Chyba při úpravě slotu:', error);
            this.showError('Chyba při úpravě: ' + error.message);
        }
    }

    async deleteSlot(slotId) {
        if (!confirm('Opravdu smazat tento slot?')) return;

        console.log('🗑️ Mazání slotu:', slotId);

        try {
            const response = await fetch(`${this.apiBase}/slots.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ slot_id: slotId })
            });

            const result = await response.json();
            
            if (response.ok && result.success) {
                this.showSuccess('Slot byl smazán');
                this.loadSlots();
            } else {
                throw new Error(result.error || 'Chyba při mazání slotu');
            }
        } catch (error) {
            console.error('❌ Chyba při mazání slotu:', error);
            this.showError('Chyba při mazání: ' + error.message);
        }
    }

    showSlotDetail(slot) {
        console.log('👁️ Zobrazení detailu slotu:', slot.id);
        
        const modalHtml = `
            <div class="modal active" id="slotDetailModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Detail slotu #${slot.id}</h2>
                        <button class="close-btn" onclick="logisticsCalendar.closeSlotDetailModal()">×</button>
                    </div>
                    <div class="slot-detail-content">
                        <div class="detail-section">
                            <h3>Základní informace</h3>
                            <p><strong>Datum:</strong> ${slot.slot_date}</p>
                            <p><strong>Čas:</strong> ${slot.slot_time.substring(0, 5)}</p>
                            <p><strong>Délka:</strong> ${slot.duration_minutes} minut</p>
                            <p><strong>Sklad:</strong> ${slot.warehouse_name}</p>
                            <p><strong>Typ:</strong> ${this.getSlotTypeText(slot.slot_type)}</p>
                        </div>
                        <div class="detail-section">
                            <h3>Kapacita</h3>
                            <p><strong>Maximální kapacita:</strong> ${slot.max_capacity}</p>
                            <p><strong>Aktuální rezervace:</strong> ${slot.current_bookings || 0}</p>
                            <p><strong>Volná místa:</strong> ${slot.max_capacity - (slot.current_bookings || 0)}</p>
                        </div>
                        ${slot.notes ? `
                        <div class="detail-section">
                            <h3>Poznámky</h3>
                            <p>${slot.notes}</p>
                        </div>
                        ` : ''}
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-secondary" onclick="logisticsCalendar.closeSlotDetailModal()">
                            Zavřít
                        </button>
                        <button class="btn btn-success" onclick="logisticsCalendar.editSlot(${slot.id}); logisticsCalendar.closeSlotDetailModal()">
                            <i class="fas fa-edit"></i> Upravit
                        </button>
                        <button class="btn btn-danger" onclick="logisticsCalendar.deleteSlot(${slot.id}); logisticsCalendar.closeSlotDetailModal()">
                            <i class="fas fa-trash"></i> Smazat
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Remove existing modal
        const existingModal = document.getElementById('slotDetailModal');
        if (existingModal) {
            existingModal.remove();
        }

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    closeSlotDetailModal() {
        const modal = document.getElementById('slotDetailModal');
        if (modal) {
            modal.remove();
        }
    }

    // Utility methods
    getWeekStart(date) {
        const d = new Date(date);
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Pondělí jako první den
        return new Date(d.setDate(diff));
    }

    getWeekDays(weekStart) {
        const days = [];
        for (let i = 0; i < 7; i++) {
            const day = new Date(weekStart);
            day.setDate(weekStart.getDate() + i);
            days.push(day);
        }
        return days;
    }

    generateTimeSlots() {
        const slots = [];
        for (let hour = 6; hour < 22; hour++) {
            slots.push(`${hour.toString().padStart(2, '0')}:00`);
            slots.push(`${hour.toString().padStart(2, '0')}:30`);
        }
        return slots;
    }

    getWeekDisplayText() {
        const weekStart = this.getWeekStart(this.currentDate);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);

        const options = { day: 'numeric', month: 'short' };
        return `${weekStart.toLocaleDateString('cs-CZ', options)} - ${weekEnd.toLocaleDateString('cs-CZ', options)} ${weekEnd.getFullYear()}`;
    }

    getDayName(date) {
        const days = ['NE', 'PO', 'ÚT', 'ST', 'ČT', 'PÁ', 'SO'];
        return days[date.getDay()];
    }

    isToday(date) {
        const today = new Date();
        return date.toDateString() === today.toDateString();
    }

    formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    // Event listeners setup
    setupEventListeners() {
        // Drag leave handler to remove highlight
        document.addEventListener('dragleave', (e) => {
            if (e.target.classList.contains('day-cell')) {
                e.target.classList.remove('drop-highlight');
            }
        });

        // Escape key to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeBookingModal();
                this.closeEditSlotModal();
                this.closeSlotDetailModal();
            }
        });
    }

    // Notification methods
    showSuccess(message) {
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'success');
        } else {
            console.log('✅ Success:', message);
            alert(message);
        }
    }

    showError(message) {
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification(message, 'error');
        } else {
            console.error('❌ Error:', message);
            alert('Chyba: ' + message);
        }
    }

    // Public API methods for external access
    isReady() {
        return this.isInitialized;
    }

    getSlots() {
        return this.slots;
    }

    getSelectedDate() {
        return this.currentDate;
    }

    // Method to reinitialize calendar if needed
    async reinitialize() {
        console.log('🔄 Reinicializace kalendáře...');
        this.isInitialized = false;
        await this.init();
    }

    // Method to update current date and refresh
    setCurrentDate(date) {
        this.currentDate = new Date(date);
        this.refresh();
    }

    // Method to get available time slots for a date
    getAvailableTimeSlotsForDate(date) {
        return this.slots.filter(slot => 
            slot.slot_date === date && 
            (slot.current_bookings || 0) < slot.max_capacity
        );
    }

    // Method to check if a time slot is available
    isTimeSlotAvailable(date, time) {
        const slot = this.slots.find(s => 
            s.slot_date === date && 
            s.slot_time.substring(0, 5) === time
        );
        
        if (!slot) return false;
        return (slot.current_bookings || 0) < slot.max_capacity;
    }

    // Method to get slot by date and time
    getSlotByDateTime(date, time) {
        return this.slots.find(s => 
            s.slot_date === date && 
            s.slot_time.substring(0, 5) === time
        );
    }

    // Method to force reload from server
    async forceRefresh() {
        console.log('🔄 Nucené obnovení kalendáře...');
        this.slots = [];
        await this.loadSlots();
    }

    // Method to add new slot programmatically
    addSlot(slotData) {
        this.slots.push(slotData);
        this.renderSlots();
    }

    // Method to update existing slot
    updateSlot(slotId, updatedData) {
        const index = this.slots.findIndex(s => s.id == slotId);
        if (index !== -1) {
            this.slots[index] = { ...this.slots[index], ...updatedData };
            this.renderSlots();
        }
    }

    // Method to remove slot
    removeSlot(slotId) {
        this.slots = this.slots.filter(s => s.id != slotId);
        this.renderSlots();
    }

    // Debug methods
    debug() {
        console.log('📊 Kalendář debug info:', {
            initialized: this.isInitialized,
            containerId: this.containerId,
            currentDate: this.currentDate,
            selectedWarehouse: this.selectedWarehouse,
            slotsCount: this.slots.length,
            warehousesCount: this.warehouses.length,
            container: this.container ? 'found' : 'not found'
        });
        
        // Log current slots for debugging
        if (this.slots.length > 0) {
            console.log('📅 Current slots:', this.slots.slice(0, 3)); // Show first 3 slots
        }
    }

    // Method to get calendar statistics
    getStatistics() {
        const today = new Date().toISOString().split('T')[0];
        const thisWeekStart = this.getWeekStart(new Date());
        const thisWeekEnd = new Date(thisWeekStart);
        thisWeekEnd.setDate(thisWeekEnd.getDate() + 6);

        const todaySlots = this.slots.filter(s => s.slot_date === today);
        const weekSlots = this.slots.filter(s => {
            const slotDate = new Date(s.slot_date);
            return slotDate >= thisWeekStart && slotDate <= thisWeekEnd;
        });

        return {
            total_slots: this.slots.length,
            today_slots: todaySlots.length,
            week_slots: weekSlots.length,
            available_today: todaySlots.filter(s => (s.current_bookings || 0) < s.max_capacity).length,
            full_today: todaySlots.filter(s => (s.current_bookings || 0) >= s.max_capacity).length
        };
    }

    // Method to manually trigger calendar generation
    generateWeeklyCalendarView() {
        if (!this.container) {
            console.error('❌ Kalendář kontejner není dostupný');
            return '';
        }
        
        const calendarGrid = document.getElementById('calendarGrid');
        if (calendarGrid) {
            calendarGrid.innerHTML = this.generateWeeklyCalendar();
        }
        
        return this.generateWeeklyCalendar();
    }

    // Method to check calendar health
    checkHealth() {
        const health = {
            container: !!this.container,
            initialized: this.isInitialized,
            hasSlots: this.slots.length > 0,
            hasWarehouses: this.warehouses.length > 0,
            apiBase: this.apiBase,
            errors: []
        };

        if (!health.container) {
            health.errors.push('Container not found');
        }
        if (!health.initialized) {
            health.errors.push('Calendar not initialized');
        }
        if (!health.hasWarehouses) {
            health.errors.push('No warehouses loaded');
        }

        return health;
    }
}

// Auto-initialize calendar when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Wait a bit for other scripts to load
    setTimeout(() => {
        console.log('🗓️ Auto-inicializace kalendáře...');
        
        // Check if container exists before initializing
        const container = document.getElementById('timeSlotsGrid');
        if (container) {
            console.log('✅ Kontejner nalezen, inicializuji kalendář...');
            window.logisticsCalendar = new LogisticsCalendar('timeSlotsGrid');
        } else {
            console.warn('⚠️ Kalendář kontejner #timeSlotsGrid nenalezen, inicializace odložena');
            
            // Try again after a delay
            setTimeout(() => {
                const delayedContainer = document.getElementById('timeSlotsGrid');
                if (delayedContainer) {
                    console.log('🗓️ Pozdní inicializace kalendáře...');
                    window.logisticsCalendar = new LogisticsCalendar('timeSlotsGrid');
                } else {
                    console.error('❌ Kalendář kontejner stále nenalezen po 2s delay');
                }
            }, 2000);
        }
    }, 500);
});

// Manual initialization function for cases when auto-init fails
function manualInitCalendar() {
    console.log('🗓️ Manuální inicializace kalendáře...');
    if (window.logisticsCalendar) {
        console.log('⚠️ Kalendář již existuje, provádím reinicializaci...');
        window.logisticsCalendar.reinitialize();
    } else {
        window.logisticsCalendar = new LogisticsCalendar('timeSlotsGrid');
    }
}

// Global functions for compatibility
function refreshCalendar() {
    if (window.logisticsCalendar && window.logisticsCalendar.isReady()) {
        console.log('🔄 Refresh kalendáře...');
        window.logisticsCalendar.refresh();
    } else {
        console.warn('⚠️ Kalendář není připraven pro refresh');
        // Try manual init
        manualInitCalendar();
    }
}

function initializeCalendar() {
    manualInitCalendar();
}

function getCalendarStats() {
    if (window.logisticsCalendar && window.logisticsCalendar.isReady()) {
        return window.logisticsCalendar.getStatistics();
    }
    return null;
}

function debugCalendar() {
    if (window.logisticsCalendar) {
        window.logisticsCalendar.debug();
        const health = window.logisticsCalendar.checkHealth();
        console.log('🏥 Calendar health:', health);
    } else {
        console.log('❌ Kalendář není inicializován');
    }
}

function forceCalendarRefresh() {
    if (window.logisticsCalendar) {
        window.logisticsCalendar.forceRefresh();
    } else {
        console.warn('⚠️ Kalendář není dostupný pro force refresh');
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LogisticsCalendar;
}

// Global error handler for calendar
window.addEventListener('error', (event) => {
    if (event.filename && event.filename.includes('calendar.js')) {
        console.error('❌ Calendar Error:', event.error);
        if (window.crmApp && window.crmApp.showNotification) {
            window.crmApp.showNotification('Chyba v kalendáři: ' + event.message, 'error');
        }
    }
});

console.log('📅 Calendar.js loaded successfully');

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