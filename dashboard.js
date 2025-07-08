// js/dashboard.js - Dashboard specific functionality (KOMPLETNÍ)
class DashboardManager {
    constructor() {
        this.refreshInterval = null;
        this.apiBase = 'api/'; // API soubory jsou v api/ adresáři
        this.lastRefresh = null;
    }

    init() {
        this.startAutoRefresh();
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Refresh button click
        const refreshBtn = document.querySelector('[onclick="refreshDashboard()"]');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.forceRefresh();
            });
        }

        // Monitor visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.handleVisibilityChange();
            }
        });
    }

    startAutoRefresh() {
        // Auto refresh dashboard every 2 minutes
        this.refreshInterval = setInterval(() => {
            if (this.shouldAutoRefresh()) {
                this.refreshDashboardData();
            }
        }, 120000);
    }

    shouldAutoRefresh() {
        return document.getElementById('dashboard').classList.contains('active') && 
               window.crmApp && 
               window.crmApp.currentUser &&
               document.visibilityState === 'visible';
    }

    handleVisibilityChange() {
        if (this.shouldAutoRefresh()) {
            // Check if data is stale (older than 5 minutes)
            const now = Date.now();
            if (!this.lastRefresh || (now - this.lastRefresh) > 300000) {
                this.refreshDashboardData();
            }
        }
    }

    async refreshDashboardData() {
        if (!window.crmApp) return;

        try {
            await window.crmApp.loadDashboardData();
            this.lastRefresh = Date.now();
            this.updateRefreshIndicator();
        } catch (error) {
            console.error('Dashboard refresh failed:', error);
        }
    }

    forceRefresh() {
        this.showRefreshingIndicator();
        this.refreshDashboardData();
    }

    updateRefreshIndicator() {
        const refreshBtn = document.querySelector('[onclick="refreshDashboard()"]');
        if (refreshBtn) {
            const icon = refreshBtn.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-spin');
                // Add brief success indication
                icon.style.color = '#28a745';
                setTimeout(() => {
                    icon.style.color = '';
                }, 1000);
            }
        }
    }

    showRefreshingIndicator() {
        const refreshBtn = document.querySelector('[onclick="refreshDashboard()"]');
        if (refreshBtn) {
            const icon = refreshBtn.querySelector('i');
            if (icon) {
                icon.classList.add('fa-spin');
            }
        }
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    // Dashboard statistics visualization
    animateCounters() {
        const counters = document.querySelectorAll('.card-value');
        
        counters.forEach(counter => {
            const target = parseInt(counter.textContent) || 0;
            const increment = target / 50; // Animate over 50 steps
            let current = 0;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    counter.textContent = target;
                    clearInterval(timer);
                } else {
                    counter.textContent = Math.floor(current);
                }
            }, 20);
        });
    }

    // Quick actions for dashboard
    async getQuickStats() {
        try {
            const response = await fetch(`${this.apiBase}bookings.php?quick_stats=1`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        return data.stats;
                    }
                }
            }
        } catch (error) {
            console.error('Failed to get quick stats:', error);
        }
        return null;
    }

    // Performance metrics
    async loadPerformanceMetrics() {
        try {
            const response = await fetch(`${this.apiBase}bookings.php?performance=1&days=30`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        this.displayPerformanceChart(data.metrics);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to load performance metrics:', error);
        }
    }

    displayPerformanceChart(metrics) {
        // Simple chart display - can be enhanced with Chart.js later
        const chartContainer = document.getElementById('performanceChart');
        if (chartContainer && metrics) {
            const chartHtml = `
                <div class="simple-chart">
                    <div class="chart-title">Výkonnost za posledních 30 dní</div>
                    <div class="chart-bars">
                        ${metrics.map(metric => `
                            <div class="chart-bar">
                                <div class="bar" style="height: ${(metric.value / metrics.reduce((max, m) => Math.max(max, m.value), 0)) * 100}%"></div>
                                <div class="bar-label">${metric.label}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            chartContainer.innerHTML = chartHtml;
        }
    }

    // Alert system for critical issues
    async checkCriticalAlerts() {
        try {
            const response = await fetch(`${this.apiBase}alerts.php?critical=1`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const text = await response.text();
                if (text) {
                    const data = JSON.parse(text);
                    if (data.success && data.alerts.length > 0) {
                        this.displayCriticalAlerts(data.alerts);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to check critical alerts:', error);
        }
    }

    displayCriticalAlerts(alerts) {
        alerts.forEach(alert => {
            if (window.crmApp && window.crmApp.showNotification) {
                window.crmApp.showNotification(alert.message, alert.type || 'warning');
            }
        });
    }

    // Data export functionality
    async exportDashboardData(format = 'csv') {
        try {
            const response = await fetch(`${this.apiBase}export.php?type=dashboard&format=${format}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `dashboard_${new Date().toISOString().split('T')[0]}.${format}`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                if (window.crmApp && window.crmApp.showNotification) {
                    window.crmApp.showNotification('Dashboard data exported', 'success');
                }
            } else {
                throw new Error('Export failed');
            }
        } catch (error) {
            console.error('Export error:', error);
            if (window.crmApp && window.crmApp.showNotification) {
                window.crmApp.showNotification('Export failed: ' + error.message, 'error');
            }
        }
    }

    // Widget management
    toggleWidget(widgetId) {
        const widget = document.getElementById(widgetId);
        if (widget) {
            widget.style.display = widget.style.display === 'none' ? 'block' : 'none';
            
            // Save preference to localStorage
            const preferences = JSON.parse(localStorage.getItem('dashboardPreferences') || '{}');
            preferences[widgetId] = widget.style.display !== 'none';
            localStorage.setItem('dashboardPreferences', JSON.stringify(preferences));
        }
    }

    loadWidgetPreferences() {
        try {
            const preferences = JSON.parse(localStorage.getItem('dashboardPreferences') || '{}');
            Object.entries(preferences).forEach(([widgetId, visible]) => {
                const widget = document.getElementById(widgetId);
                if (widget) {
                    widget.style.display = visible ? 'block' : 'none';
                }
            });
        } catch (error) {
            console.error('Failed to load widget preferences:', error);
        }
    }

    // Real-time updates via WebSocket (if available)
    initializeRealTimeUpdates() {
        if (typeof WebSocket !== 'undefined') {
            try {
                const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                const wsUrl = `${wsProtocol}//${window.location.host}/ws/dashboard`;
                
                this.websocket = new WebSocket(wsUrl);
                
                this.websocket.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        this.handleRealTimeUpdate(data);
                    } catch (error) {
                        console.error('WebSocket message parse error:', error);
                    }
                };
                
                this.websocket.onerror = (error) => {
                    console.log('WebSocket not available, using polling instead');
                };
                
            } catch (error) {
                console.log('WebSocket not supported, using polling');
            }
        }
    }

    handleRealTimeUpdate(data) {
        if (data.type === 'booking_update') {
            this.refreshDashboardData();
        } else if (data.type === 'notification') {
            if (window.crmApp && window.crmApp.showNotification) {
                window.crmApp.showNotification(data.message, data.level || 'info');
            }
        }
    }

    // Cleanup
    destroy() {
        this.stopAutoRefresh();
        
        if (this.websocket) {
            this.websocket.close();
        }
    }
}

// Initialize dashboard manager
const dashboardManager = new DashboardManager();

// Make it globally available
window.dashboardManager = dashboardManager;

// Global functions
function refreshDashboard() {
    if (dashboardManager) {
        dashboardManager.forceRefresh();
    }
}

function exportDashboard(format = 'csv') {
    if (dashboardManager) {
        dashboardManager.exportDashboardData(format);
    }
}

function toggleDashboardWidget(widgetId) {
    if (dashboardManager) {
        dashboardManager.toggleWidget(widgetId);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    dashboardManager.init();
    dashboardManager.loadWidgetPreferences();
    dashboardManager.initializeRealTimeUpdates();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (dashboardManager) {
        dashboardManager.destroy();
    }
});