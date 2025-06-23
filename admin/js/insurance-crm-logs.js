/**
 * Insurance CRM Logging JavaScript
 */

(function($) {
    'use strict';

    var InsuranceCRMLogs = {
        
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.startAutoRefresh();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Auto-refresh checkbox
            $(document).on('change', '#auto-refresh-logs', this.toggleAutoRefresh.bind(this));
            
            // Manual refresh button
            $(document).on('click', '.refresh-logs', this.refreshLogs.bind(this));
            
            // Filter form submission
            $(document).on('submit', '.log-filters form', this.handleFilterSubmit.bind(this));
            
            // Real-time search
            $(document).on('input', 'input[name="search"]', this.debounce(this.handleSearch.bind(this), 500));
            
            // Export buttons
            $(document).on('click', '.export-logs', this.handleExport.bind(this));
        },
        
        /**
         * Start auto refresh
         */
        startAutoRefresh: function() {
            this.autoRefreshInterval = setInterval(this.refreshLogs.bind(this), 30000); // 30 seconds
        },
        
        /**
         * Stop auto refresh
         */
        stopAutoRefresh: function() {
            if (this.autoRefreshInterval) {
                clearInterval(this.autoRefreshInterval);
                this.autoRefreshInterval = null;
            }
        },
        
        /**
         * Toggle auto refresh
         */
        toggleAutoRefresh: function(e) {
            if ($(e.target).is(':checked')) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        },
        
        /**
         * Refresh logs
         */
        refreshLogs: function() {
            var currentTab = $('.nav-tab-active').attr('href').split('tab=')[1] || 'system';
            var currentPage = $('.pagination .current').text() || 1;
            
            this.loadLogs(currentTab, currentPage);
        },
        
        /**
         * Load logs via AJAX
         */
        loadLogs: function(tab, page) {
            var self = this;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'insurance_crm_get_logs',
                    tab: tab,
                    page: page,
                    per_page: 20,
                    nonce: $('#log-nonce').val()
                },
                beforeSend: function() {
                    $('.logs-table tbody').addClass('loading');
                },
                success: function(response) {
                    if (response.success) {
                        self.updateLogsTable(response.data.logs);
                        self.updatePagination(response.data.pages, page);
                        self.updateLastRefresh();
                    }
                },
                complete: function() {
                    $('.logs-table tbody').removeClass('loading');
                }
            });
        },
        
        /**
         * Update logs table
         */
        updateLogsTable: function(logs) {
            var tbody = $('.logs-table tbody');
            tbody.empty();
            
            if (logs.length === 0) {
                tbody.append('<tr><td colspan="7">Log kaydı bulunamadı.</td></tr>');
                return;
            }
            
            $.each(logs, function(index, log) {
                var row = $('<tr>');
                
                // Format date
                var date = new Date(log.created_at);
                var formattedDate = date.toLocaleDateString('tr-TR') + ' ' + date.toLocaleTimeString('tr-TR');
                
                row.append('<td>' + formattedDate + '</td>');
                row.append('<td class="log-user">' + (log.user_name || 'Sistem') + '</td>');
                row.append('<td class="log-action">' + this.formatAction(log.action) + '</td>');
                
                if (log.table_name !== undefined) {
                    // System log
                    row.append('<td>' + (log.table_name || '') + '</td>');
                    row.append('<td>' + (log.record_id || '-') + '</td>');
                    row.append('<td class="log-details" title="' + (log.details || '') + '">' + (log.details || '') + '</td>');
                } else {
                    // User log
                    row.append('<td class="log-ip">' + (log.ip_address || '') + '</td>');
                    row.append('<td>' + (log.browser || '') + '</td>');
                    row.append('<td>' + (log.device || '') + '</td>');
                    row.append('<td>' + (log.session_duration ? this.formatDuration(log.session_duration) : '-') + '</td>');
                }
                
                row.append('<td class="log-ip">' + (log.ip_address || '') + '</td>');
                
                tbody.append(row);
            }.bind(this));
        },
        
        /**
         * Format action name
         */
        formatAction: function(action) {
            var actions = {
                'create_customer': 'Müşteri Oluşturma',
                'update_customer': 'Müşteri Güncelleme',
                'delete_customer': 'Müşteri Silme',
                'create_policy': 'Poliçe Oluşturma',
                'update_policy': 'Poliçe Güncelleme',
                'delete_policy': 'Poliçe Silme',
                'create_task': 'Görev Oluşturma',
                'update_task': 'Görev Güncelleme',
                'delete_task': 'Görev Silme',
                'login': 'Giriş',
                'logout': 'Çıkış',
                'failed_login': 'Başarısız Giriş'
            };
            
            return actions[action] || action.charAt(0).toUpperCase() + action.slice(1).replace('_', ' ');
        },
        
        /**
         * Format duration
         */
        formatDuration: function(seconds) {
            if (seconds < 60) {
                return seconds + ' saniye';
            } else if (seconds < 3600) {
                return Math.floor(seconds / 60) + ' dakika';
            } else {
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                return hours + ' saat ' + minutes + ' dakika';
            }
        },
        
        /**
         * Update pagination
         */
        updatePagination: function(totalPages, currentPage) {
            // Implementation would update pagination links
            // For now, just update current page indicator
            $('.pagination .current').text(currentPage);
        },
        
        /**
         * Update last refresh time
         */
        updateLastRefresh: function() {
            var now = new Date();
            var timeString = now.toLocaleTimeString('tr-TR');
            
            var refreshIndicator = $('.last-refresh');
            if (refreshIndicator.length === 0) {
                $('h1').after('<p class="last-refresh">Son güncelleme: ' + timeString + '</p>');
            } else {
                refreshIndicator.text('Son güncelleme: ' + timeString);
            }
        },
        
        /**
         * Handle filter form submission
         */
        handleFilterSubmit: function(e) {
            e.preventDefault();
            // Form will be submitted normally, no AJAX needed
            // Could be enhanced to use AJAX for better UX
        },
        
        /**
         * Handle search input
         */
        handleSearch: function(e) {
            var searchTerm = $(e.target).val();
            
            if (searchTerm.length >= 3 || searchTerm.length === 0) {
                // Trigger search - for now just update the form
                // Could be enhanced with real-time search
            }
        },
        
        /**
         * Handle export
         */
        handleExport: function(e) {
            // Export functionality is handled server-side
            // Could add progress indicator here
            
            var button = $(e.target);
            var originalText = button.text();
            
            button.text('İndiriliyor...').prop('disabled', true);
            
            setTimeout(function() {
                button.text(originalText).prop('disabled', false);
            }, 3000);
        },
        
        /**
         * Debounce function
         */
        debounce: function(func, delay) {
            var timeoutId;
            return function() {
                var context = this;
                var args = arguments;
                clearTimeout(timeoutId);
                timeoutId = setTimeout(function() {
                    func.apply(context, args);
                }, delay);
            };
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        if ($('.logs-table').length > 0) {
            InsuranceCRMLogs.init();
        }
    });
    
    // Add CSS for loading state
    $('<style>')
        .text('.logs-table tbody.loading { opacity: 0.5; } .last-refresh { color: #666; font-style: italic; margin-top: 10px; }')
        .appendTo('head');

})(jQuery);