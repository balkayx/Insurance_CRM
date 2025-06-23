/**
 * Dashboard Widgets JavaScript - Enhanced Functionality
 * Version: 5.1.0
 * Author: Anadolu Birlik
 * Description: Interactive dashboard widgets with real-time updates
 */

class DashboardWidgets {
    constructor() {
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeWidgets();
        this.startPerformanceTracking();
    }

    bindEvents() {
        // Performance filter buttons
        document.querySelectorAll('.performance-filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.handlePerformanceFilter(e));
        });

        // Menu box hover effects
        document.querySelectorAll('.dropdown-menu-box').forEach(box => {
            box.addEventListener('mouseenter', (e) => this.handleMenuBoxHover(e, true));
            box.addEventListener('mouseleave', (e) => this.handleMenuBoxHover(e, false));
        });

        // Real-time updates toggle
        const realTimeToggle = document.getElementById('realtime-toggle');
        if (realTimeToggle) {
            realTimeToggle.addEventListener('change', (e) => this.toggleRealTimeUpdates(e));
        }

        // Export functionality
        document.querySelectorAll('.export-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleExport(e));
        });
    }

    initializeWidgets() {
        this.initPerformanceCards();
        this.initTopPerformersWidgets();
        this.initTaskSummaryCards();
        this.initProgressBars();
    }

    initPerformanceCards() {
        const cards = document.querySelectorAll('.performance-card');
        cards.forEach((card, index) => {
            // Add staggered animation
            setTimeout(() => {
                card.classList.add('animate-slide-in');
            }, index * 100);

            // Initialize tooltips
            this.initTooltip(card);
        });
    }

    initTopPerformersWidgets() {
        const widgets = document.querySelectorAll('.top-performers-widget');
        widgets.forEach(widget => {
            this.loadTopPerformersData(widget);
        });
    }

    initTaskSummaryCards() {
        const taskCards = document.querySelectorAll('.task-summary-card');
        taskCards.forEach((card, index) => {
            setTimeout(() => {
                card.classList.add('animate-fade-in');
            }, index * 50);

            // Add click handler for task detail view
            card.addEventListener('click', (e) => this.handleTaskCardClick(e));
        });
    }

    initProgressBars() {
        const progressBars = document.querySelectorAll('.progress-fill, .metric-progress-bar');
        progressBars.forEach(bar => {
            const targetWidth = bar.getAttribute('data-progress') || bar.style.width;
            bar.style.width = '0%';
            
            setTimeout(() => {
                bar.style.width = targetWidth;
            }, 300);
        });
    }

    handlePerformanceFilter(e) {
        e.preventDefault();
        const btn = e.currentTarget;
        const filterType = btn.getAttribute('data-filter');
        const section = btn.closest('.performance-section');

        // Remove active class from all buttons in this section
        section.querySelectorAll('.performance-filter-btn').forEach(b => {
            b.classList.remove('active');
        });

        // Add active class to clicked button
        btn.classList.add('active');

        // Load filtered data
        this.loadPerformanceData(section, filterType);
    }

    handleMenuBoxHover(e, isEntering) {
        const box = e.currentTarget;
        const icon = box.querySelector('.dropdown-box-icon');
        
        if (isEntering) {
            box.style.transform = 'translateY(-4px) scale(1.02)';
            if (icon) {
                icon.style.transform = 'scale(1.1)';
            }
        } else {
            box.style.transform = 'translateY(0) scale(1)';
            if (icon) {
                icon.style.transform = 'scale(1)';
            }
        }
    }

    handleTaskCardClick(e) {
        const card = e.currentTarget;
        const taskType = card.getAttribute('data-task-type');
        
        // Navigate to detailed task view
        if (taskType) {
            const url = this.buildTaskUrl(taskType);
            window.location.href = url;
        }
    }

    buildTaskUrl(taskType) {
        const baseUrl = window.location.origin + window.location.pathname;
        const params = new URLSearchParams();
        params.set('view', 'tasks');
        params.set('filter', taskType);
        return baseUrl + '?' + params.toString();
    }

    loadPerformanceData(section, filterType) {
        const sectionId = section.getAttribute('id');
        const loadingOverlay = this.createLoadingOverlay();
        section.appendChild(loadingOverlay);

        // Simulate API call with setTimeout (replace with actual AJAX call)
        setTimeout(() => {
            this.updatePerformanceCards(section, filterType);
            section.removeChild(loadingOverlay);
        }, 1000);

        // Actual AJAX call would look like this:
        /*
        jQuery.ajax({
            url: dashboard_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'load_performance_data',
                filter_type: filterType,
                section_id: sectionId,
                nonce: dashboard_ajax.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updatePerformanceCards(section, response.data);
                }
                section.removeChild(loadingOverlay);
            },
            error: () => {
                section.removeChild(loadingOverlay);
                this.showErrorMessage('Veri yüklenirken hata oluştu.');
            }
        });
        */
    }

    loadTopPerformersData(widget) {
        const widgetType = widget.getAttribute('data-widget-type');
        const performersList = widget.querySelector('.top-performers-list');

        // Show loading skeletons
        this.showLoadingSkeletons(performersList);

        // Simulate data loading (replace with actual AJAX)
        setTimeout(() => {
            this.updateTopPerformers(widget, this.generateMockData(widgetType));
        }, 800);
    }

    generateMockData(widgetType) {
        // This would normally come from the server
        const mockData = {
            sales: [
                { name: 'Ahmet Yılmaz', amount: 125000, change: 15.3, avatar: null },
                { name: 'Fatma Demir', amount: 98500, change: 8.7, avatar: null },
                { name: 'Mehmet Kaya', amount: 87200, change: -2.1, avatar: null },
                { name: 'Ayşe Çelik', amount: 75600, change: 12.4, avatar: null },
                { name: 'Ali Özkan', amount: 68900, change: 5.2, avatar: null }
            ],
            customers: [
                { name: 'Zeynep Aktaş', count: 34, change: 22.1, avatar: null },
                { name: 'Mustafa Şen', count: 28, change: 11.3, avatar: null },
                { name: 'Elif Yıldız', count: 25, change: 8.9, avatar: null },
                { name: 'Hasan Doğan', count: 22, change: -1.5, avatar: null },
                { name: 'Selin Öztürk', count: 19, change: 15.7, avatar: null }
            ]
        };
        return mockData[widgetType] || [];
    }

    updatePerformanceCards(section, filterType) {
        const cards = section.querySelectorAll('.performance-card');
        cards.forEach((card, index) => {
            setTimeout(() => {
                card.classList.add('animate-slide-in');
                this.updateCardData(card, filterType);
            }, index * 100);
        });
    }

    updateCardData(card, filterType) {
        // Update card content based on filter type
        const metrics = card.querySelectorAll('.metric-value');
        metrics.forEach(metric => {
            const currentValue = parseInt(metric.textContent.replace(/[^\d]/g, ''));
            const newValue = this.calculateFilteredValue(currentValue, filterType);
            this.animateNumberChange(metric, currentValue, newValue);
        });

        // Update progress bars
        const progressBars = card.querySelectorAll('.metric-progress-bar');
        progressBars.forEach(bar => {
            const newWidth = Math.floor(Math.random() * 100) + '%';
            bar.style.width = newWidth;
        });
    }

    calculateFilteredValue(currentValue, filterType) {
        const multipliers = {
            'week': 0.25,
            'month': 1,
            'quarter': 3,
            'year': 12
        };
        return Math.floor(currentValue * (multipliers[filterType] || 1));
    }

    animateNumberChange(element, from, to) {
        const duration = 1000;
        const startTime = performance.now();
        const difference = to - from;

        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            const easeOutCubic = 1 - Math.pow(1 - progress, 3);
            const currentValue = Math.floor(from + (difference * easeOutCubic));
            
            if (element.textContent.includes('₺')) {
                element.textContent = '₺' + this.formatNumber(currentValue);
            } else if (element.textContent.includes('%')) {
                element.textContent = currentValue + '%';
            } else {
                element.textContent = this.formatNumber(currentValue);
            }

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    formatNumber(num) {
        return new Intl.NumberFormat('tr-TR').format(num);
    }

    updateTopPerformers(widget, data) {
        const performersList = widget.querySelector('.top-performers-list');
        performersList.innerHTML = '';

        data.forEach((performer, index) => {
            const item = this.createPerformerItem(performer, index);
            performersList.appendChild(item);
        });
    }

    createPerformerItem(performer, index) {
        const item = document.createElement('div');
        item.className = 'top-performer-item';
        
        const rankClasses = ['first', 'second', 'third'];
        const rankClass = rankClasses[index] || '';
        
        const changeIcon = performer.change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
        const changeClass = performer.change >= 0 ? 'positive' : 'negative';
        
        item.innerHTML = `
            <div class="performer-rank ${rankClass}">${index + 1}</div>
            <div class="performer-avatar">
                <!-- Avatar görselleri kaldırıldı - sadece kullanıcı adı ilk harfi gösterilecek -->
                ${performer.name.charAt(0)}
            </div>
            <div class="performer-details">
                <div class="performer-name">${performer.name}</div>
                <div class="performer-subtitle">
                    ${performer.amount ? 'Satış Temsilcisi' : 'Müşteri Temsilcisi'}
                </div>
            </div>
            <div class="performer-value">
                <div class="performer-amount">
                    ${performer.amount ? 
                        '₺' + this.formatNumber(performer.amount) : 
                        this.formatNumber(performer.count) + ' müşteri'
                    }
                </div>
                <div class="performer-change ${changeClass}">
                    <i class="fas ${changeIcon}"></i>
                    ${Math.abs(performer.change)}%
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${Math.min(100, Math.abs(performer.change) * 2)}%"></div>
                    </div>
                </div>
            </div>
        `;

        return item;
    }

    createLoadingOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        `;
        
        overlay.innerHTML = `
            <div class="loading-spinner" style="
                width: 40px;
                height: 40px;
                border: 4px solid #f3f4f6;
                border-top: 4px solid #3b82f6;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            "></div>
        `;

        return overlay;
    }

    showLoadingSkeletons(container) {
        container.innerHTML = '';
        for (let i = 0; i < 5; i++) {
            const skeleton = document.createElement('div');
            skeleton.className = 'top-performer-item loading-skeleton';
            skeleton.style.height = '72px';
            container.appendChild(skeleton);
        }
    }

    initTooltip(element) {
        const tooltip = element.getAttribute('data-tooltip');
        if (!tooltip) return;

        element.addEventListener('mouseenter', (e) => {
            this.showTooltip(e, tooltip);
        });

        element.addEventListener('mouseleave', () => {
            this.hideTooltip();
        });
    }

    showTooltip(e, text) {
        const tooltip = document.createElement('div');
        tooltip.className = 'dashboard-tooltip';
        tooltip.textContent = text;
        tooltip.style.cssText = `
            position: absolute;
            background: #1f2937;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            z-index: 1000;
            pointer-events: none;
            white-space: nowrap;
        `;

        document.body.appendChild(tooltip);

        const rect = e.target.getBoundingClientRect();
        tooltip.style.left = rect.left + rect.width / 2 - tooltip.offsetWidth / 2 + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';

        this.currentTooltip = tooltip;
    }

    hideTooltip() {
        if (this.currentTooltip) {
            document.body.removeChild(this.currentTooltip);
            this.currentTooltip = null;
        }
    }

    toggleRealTimeUpdates(e) {
        if (e.target.checked) {
            this.startRealTimeUpdates();
        } else {
            this.stopRealTimeUpdates();
        }
    }

    startRealTimeUpdates() {
        this.realTimeInterval = setInterval(() => {
            this.updateDashboardData();
        }, 30000); // Update every 30 seconds
    }

    stopRealTimeUpdates() {
        if (this.realTimeInterval) {
            clearInterval(this.realTimeInterval);
            this.realTimeInterval = null;
        }
    }

    updateDashboardData() {
        // Update live data (replace with actual AJAX calls)
        this.updateStatBoxes();
        this.updatePerformanceMetrics();
        this.refreshTopPerformers();
    }

    updateStatBoxes() {
        const statValues = document.querySelectorAll('.stat-value');
        statValues.forEach(element => {
            const currentValue = parseInt(element.textContent.replace(/[^\d]/g, ''));
            const variation = Math.floor(Math.random() * 10) - 5; // ±5 variation
            const newValue = Math.max(0, currentValue + variation);
            this.animateNumberChange(element, currentValue, newValue);
        });
    }

    updatePerformanceMetrics() {
        const metricValues = document.querySelectorAll('.metric-value');
        metricValues.forEach(element => {
            // Add subtle updates to performance metrics
            if (Math.random() > 0.7) { // 30% chance of update
                element.style.background = '#10b981';
                element.style.color = 'white';
                element.style.borderRadius = '4px';
                element.style.padding = '2px 6px';
                
                setTimeout(() => {
                    element.style.background = '';
                    element.style.color = '';
                    element.style.borderRadius = '';
                    element.style.padding = '';
                }, 2000);
            }
        });
    }

    refreshTopPerformers() {
        const widgets = document.querySelectorAll('.top-performers-widget');
        widgets.forEach(widget => {
            const widgetType = widget.getAttribute('data-widget-type');
            if (Math.random() > 0.8) { // 20% chance of update
                this.loadTopPerformersData(widget);
            }
        });
    }

    startPerformanceTracking() {
        // Track user interactions for analytics
        this.trackClicks();
        this.trackTimeSpent();
    }

    trackClicks() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.performance-card') || 
                e.target.closest('.dropdown-menu-box') || 
                e.target.closest('.task-summary-card')) {
                
                const elementType = e.target.closest('[class*="card"], [class*="box"]').className;
                this.logInteraction('click', elementType);
            }
        });
    }

    trackTimeSpent() {
        this.startTime = Date.now();
        
        window.addEventListener('beforeunload', () => {
            const timeSpent = Date.now() - this.startTime;
            this.logInteraction('time_spent', timeSpent);
        });
    }

    logInteraction(type, data) {
        // Send analytics data to server (implement as needed)
        if (console && console.log) {
            console.log(`Dashboard interaction: ${type}`, data);
        }
    }

    handleExport(e) {
        e.preventDefault();
        const exportType = e.currentTarget.getAttribute('data-export-type');
        
        switch (exportType) {
            case 'performance':
                this.exportPerformanceData();
                break;
            case 'tasks':
                this.exportTaskData();
                break;
            case 'summary':
                this.exportSummaryData();
                break;
        }
    }

    exportPerformanceData() {
        // Collect performance data and create CSV/Excel export
        const data = this.collectPerformanceData();
        this.downloadCSV(data, 'performance-report.csv');
    }

    exportTaskData() {
        const data = this.collectTaskData();
        this.downloadCSV(data, 'task-summary.csv');
    }

    exportSummaryData() {
        const data = this.collectSummaryData();
        this.downloadCSV(data, 'dashboard-summary.csv');
    }

    collectPerformanceData() {
        // Collect data from performance cards
        const data = [];
        document.querySelectorAll('.performance-card').forEach(card => {
            const name = card.querySelector('.performance-info h4')?.textContent;
            const metrics = {};
            card.querySelectorAll('.performance-metric').forEach(metric => {
                const label = metric.querySelector('.metric-label')?.textContent;
                const value = metric.querySelector('.metric-value')?.textContent;
                if (label && value) {
                    metrics[label] = value;
                }
            });
            data.push({ name, ...metrics });
        });
        return data;
    }

    collectTaskData() {
        const data = [];
        document.querySelectorAll('.task-summary-card').forEach(card => {
            const title = card.querySelector('h4')?.textContent;
            const count = card.querySelector('.task-card-count')?.textContent;
            data.push({ task_type: title, count: count });
        });
        return data;
    }

    collectSummaryData() {
        const data = [];
        document.querySelectorAll('.stat-box').forEach(box => {
            const label = box.querySelector('.stat-label')?.textContent;
            const value = box.querySelector('.stat-value')?.textContent;
            data.push({ metric: label, value: value });
        });
        return data;
    }

    downloadCSV(data, filename) {
        if (!data.length) return;
        
        const headers = Object.keys(data[0]);
        const csvContent = [
            headers.join(','),
            ...data.map(row => 
                headers.map(header => `"${row[header] || ''}"`).join(',')
            )
        ].join('\n');

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    }

    showErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'dashboard-error-message';
        errorDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ef4444;
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            z-index: 10000;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        `;
        errorDiv.textContent = message;

        document.body.appendChild(errorDiv);

        setTimeout(() => {
            document.body.removeChild(errorDiv);
        }, 5000);
    }
}

// Add CSS for spinner animation
const style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.dashboardWidgets = new DashboardWidgets();
});

// Expose for external use
window.DashboardWidgets = DashboardWidgets;