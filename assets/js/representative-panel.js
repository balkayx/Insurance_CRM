/**
 * Representative Panel JavaScript
 * Version: 2.0.0
 * Author: Anadolu Birlik Insurance CRM
 * Date: 2025-05-27
 */

(function($) {
    'use strict';

    // Global namespace
    window.RepresentativePanel = window.RepresentativePanel || {};

    // Configuration
    const config = {
        ajaxUrl: representativePanel.ajaxUrl,
        nonce: representativePanel.nonce,
        refreshInterval: 300000, // 5 minutes
        animationDuration: 300,
        breakpoints: {
            mobile: 768,
            tablet: 1024,
            desktop: 1200
        },
        debounceDelay: 300,
        autoSaveDelay: 2000
    };

    // Utility functions
    const utils = {
        // Debounce function
        debounce(func, wait, immediate = false) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    timeout = null;
                    if (!immediate) func.apply(this, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(this, args);
            };
        },

        // Throttle function
        throttle(func, limit) {
            let inThrottle;
            return function(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },

        // Format number for Turkish locale
        formatNumber(num, decimals = 0) {
            if (isNaN(num)) return '0';
            return new Intl.NumberFormat('tr-TR', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }).format(num);
        },

        // Format currency
        formatCurrency(amount) {
            if (isNaN(amount)) return '₺0';
            return `₺${this.formatNumber(amount, 2)}`;
        },

        // Format percentage
        formatPercentage(value, decimals = 1) {
            if (isNaN(value)) return '0%';
            return `${this.formatNumber(value, decimals)}%`;
        },

        // Show notification
        showNotification(message, type = 'success', duration = 5000) {
            // Remove existing notifications of same type
            $(`.notification-${type}`).remove();

            const iconMap = {
                success: '✓',
                error: '✕',
                warning: '⚠',
                info: 'ℹ'
            };

            const notification = $(`
                <div class="notification notification-${type}" role="alert" aria-live="polite">
                    <div class="notification-content">
                        <span class="notification-icon">${iconMap[type] || iconMap.info}</span>
                        <span class="notification-message">${message}</span>
                        <button class="notification-close" aria-label="Bildirimi kapat">&times;</button>
                    </div>
                </div>
            `);

            // Add to DOM
            if (!$('.notifications-container').length) {
                $('body').append('<div class="notifications-container"></div>');
            }
            $('.notifications-container').append(notification);
            
            // Animate in
            requestAnimationFrame(() => {
                notification.addClass('show');
            });

            // Auto remove
            const autoRemove = setTimeout(() => {
                this.hideNotification(notification);
            }, duration);

            // Manual close
            notification.find('.notification-close').on('click', () => {
                clearTimeout(autoRemove);
                this.hideNotification(notification);
            });

            return notification;
        },

        // Hide notification
        hideNotification(notification) {
            notification.removeClass('show');
            setTimeout(() => {
                notification.remove();
            }, config.animationDuration);
        },

        // Loading state management
        setLoading(element, loading = true) {
            if (loading) {
                element.addClass('loading')
                       .prop('disabled', true)
                       .attr('aria-busy', 'true');
                
                // Store original text
                if (!element.data('original-text')) {
                    element.data('original-text', element.text());
                }
                
                // Show loading text
                const loadingText = element.data('loading-text') || 'Yükleniyor...';
                element.text(loadingText);
            } else {
                element.removeClass('loading')
                       .prop('disabled', false)
                       .attr('aria-busy', 'false');
                
                // Restore original text
                const originalText = element.data('original-text');
                if (originalText) {
                    element.text(originalText);
                }
            }
        },

        // Check if element is in viewport
        isInViewport(element) {
            if (!element.length) return false;
            
            const rect = element[0].getBoundingClientRect();
            const windowHeight = window.innerHeight || document.documentElement.clientHeight;
            const windowWidth = window.innerWidth || document.documentElement.clientWidth;
            
            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= windowHeight &&
                rect.right <= windowWidth
            );
        },

        // Animate number counting
        animateNumber(element, endValue, duration = 1000, decimals = 0) {
            const $element = $(element);
            const startValue = parseFloat($element.text().replace(/[^\d.-]/g, '')) || 0;
            const difference = endValue - startValue;
            const stepTime = Math.abs(Math.floor(duration / difference)) || 16;
            const timer = setInterval(() => {
                const current = parseFloat($element.text().replace(/[^\d.-]/g, '')) || 0;
                const increment = difference > 0 ? Math.ceil(difference / 10) : Math.floor(difference / 10);
                const newValue = current + increment;
                
                if ((difference > 0 && newValue >= endValue) || (difference < 0 && newValue <= endValue)) {
                    $element.text(this.formatNumber(endValue, decimals));
                    clearInterval(timer);
                } else {
                    $element.text(this.formatNumber(newValue, decimals));
                }
            }, stepTime);
        },

        // Smooth scroll to element
        scrollTo(element, offset = 0) {
            if (!element.length) return;
            
            const targetPosition = element.offset().top - offset;
            $('html, body').animate({
                scrollTop: targetPosition
            }, config.animationDuration);
        },

        // Local storage wrapper
        storage: {
            set(key, value) {
                try {
                    localStorage.setItem(`rp_${key}`, JSON.stringify(value));
                    return true;
                } catch (e) {
                    console.warn('localStorage not available:', e);
                    return false;
                }
            },
            
            get(key) {
                try {
                    const item = localStorage.getItem(`rp_${key}`);
                    return item ? JSON.parse(item) : null;
                } catch (e) {
                    console.warn('Error parsing localStorage item:', e);
                    return null;
                }
            },
            
            remove(key) {
                try {
                    localStorage.removeItem(`rp_${key}`);
                    return true;
                } catch (e) {
                    console.warn('Error removing localStorage item:', e);
                    return false;
                }
            }
        },

        // AJAX request wrapper
        ajax(options) {
            const defaults = {
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    nonce: config.nonce
                }
            };

            return $.ajax($.extend(true, defaults, options));
        },

        // Confirm dialog
        confirm(message, callback) {
            if (window.confirm(message)) {
                if (typeof callback === 'function') {
                    callback();
                }
                return true;
            }
            return false;
        }
    };

    // Dashboard functionality
    const dashboard = {
        refreshTimer: null,
        lastRefresh: null,

        init() {
            this.bindEvents();
            this.initAnimations();
            this.startAutoRefresh();
            this.initProgressCircles();
            this.loadStoredData();
        },

        bindEvents() {
            // Refresh button
            $(document).on('click', '.btn-refresh, .refresh-dashboard', this.refreshData.bind(this));
            
            // Quick action buttons
            $(document).on('click', '.quick-action-btn, .action-item', this.handleQuickAction.bind(this));
            
            // Insight actions
            $(document).on('click', '.insight-action', this.handleInsightAction.bind(this));
            
            // Schedule navigation
            $(document).on('click', '.schedule-btn', this.handleScheduleNavigation.bind(this));
            
            // Activity item actions
            $(document).on('click', '.btn-action', this.handleActivityAction.bind(this));
        },

        refreshData() {
            const button = $('.btn-refresh, .refresh-dashboard').first();
            utils.setLoading(button);

            utils.ajax({
                data: {
                    action: 'refresh_dashboard_data',
                    nonce: config.nonce
                }
            })
            .done((response) => {
                if (response.success) {
                    this.updateStats(response.data);
                    this.lastRefresh = new Date();
                    utils.storage.set('dashboard_data', response.data);
                    utils.storage.set('last_refresh', this.lastRefresh.toISOString());
                    utils.showNotification('Dashboard verileri güncellendi', 'success', 3000);
                } else {
                    utils.showNotification(response.data || 'Veriler güncellenirken hata oluştu', 'error');
                }
            })
            .fail(() => {
                utils.showNotification('Bağlantı hatası oluştu', 'error');
            })
            .always(() => {
                utils.setLoading(button, false);
            });
        },

        updateStats(data) {
            // Update numerical stats with animation
            Object.keys(data).forEach(key => {
                const elements = $(`.stat-${key}, .${key}-value`);
                elements.each((index, element) => {
                    const $element = $(element);
                    const currentValue = parseFloat($element.text().replace(/[^\d.-]/g, '')) || 0;
                    const newValue = parseFloat(data[key].replace(/[^\d.-]/g, '')) || 0;
                    
                    if (currentValue !== newValue) {
                        utils.animateNumber($element, newValue, 1000);
                    }
                });
            });

            // Update progress bars
            if (data.achievementRate) {
                const progressBars = $('.progress-fill');
                progressBars.each((index, bar) => {
                    const $bar = $(bar);
                    const newWidth = Math.min(100, parseFloat(data.achievementRate));
                    $bar.css('width', `${newWidth}%`);
                });

                // Update circular progress
                this.updateCircularProgress(parseFloat(data.achievementRate));
            }

            // Update last refresh indicator
            this.updateRefreshIndicator();
        },

        updateCircularProgress(percentage) {
            const circles = $('.progress-ring-fill');
            circles.each((index, circle) => {
                const radius = circle.r.baseVal.value;
                const circumference = 2 * Math.PI * radius;
                const offset = circumference - (percentage / 100) * circumference;
                
                $(circle).css({
                    'stroke-dasharray': circumference,
                    'stroke-dashoffset': offset
                });
            });
        },

        updateRefreshIndicator() {
            const indicator = $('.last-update, .refresh-indicator');
            const now = new Date();
            const timeString = now.toLocaleTimeString('tr-TR', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            indicator.text(`Son güncelleme: ${timeString}`);
        },

        startAutoRefresh() {
            // Clear existing timer
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
            }

            // Start new timer
            this.refreshTimer = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.refreshData();
                }
            }, config.refreshInterval);

            // Pause when page is hidden
            $(document).on('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    if (this.refreshTimer) {
                        clearInterval(this.refreshTimer);
                    }
                } else {
                    this.startAutoRefresh();
                }
            });
        },

        initAnimations() {
            // Intersection Observer for animations
            if ('IntersectionObserver' in window) {
                const observerOptions = {
                    threshold: 0.1,
                    rootMargin: '0px 0px -50px 0px'
                };

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const $element = $(entry.target);
                            
                            // Animate stats
                            if ($element.hasClass('stat-number')) {
                                const value = parseFloat($element.text().replace(/[^\d.-]/g, '')) || 0;
                                $element.text('0');
                                setTimeout(() => {
                                    utils.animateNumber($element, value);
                                }, 200);
                            }

                            // Animate progress bars
                            if ($element.hasClass('progress-fill')) {
                                const width = $element.data('width') || $element.attr('style').match(/width:\s*([^;]+)/);
                                if (width) {
                                    $element.css('width', '0%');
                                    setTimeout(() => {
                                        $element.css('width', width[1] || width);
                                    }, 400);
                                }
                            }

                            observer.unobserve(entry.target);
                        }
                    });
                }, observerOptions);

                // Observe elements
                $('.stat-number, .progress-fill, .chart-bar').each((index, element) => {
                    observer.observe(element);
                });
            }
        },

        initProgressCircles() {
            // SVG circular progress initialization
            $('.circular-progress').each((index, svg) => {
                const $svg = $(svg);
                if (!$svg.find('defs').length) {
                    const defs = `
                        <defs>
                            <linearGradient id="progressGradient${index}" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#667eea"/>
                                <stop offset="100%" stop-color="#764ba2"/>
                            </linearGradient>
                        </defs>
                    `;
                    $svg.prepend(defs);
                    $svg.find('.progress-fill').attr('stroke', `url(#progressGradient${index})`);
                }
            });
        },

        loadStoredData() {
            // Load cached data on page load
            const cachedData = utils.storage.get('dashboard_data');
            const lastRefresh = utils.storage.get('last_refresh');
            
            if (cachedData && lastRefresh) {
                const refreshTime = new Date(lastRefresh);
                const now = new Date();
                const timeDiff = now - refreshTime;
                
                // Use cached data if less than 10 minutes old
                if (timeDiff < 600000) {
                    this.updateStats(cachedData);
                    this.lastRefresh = refreshTime;
                }
            }
        },

        handleQuickAction(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const href = $button.attr('href');
            const action = $button.data('action');

            // Add visual feedback
            $button.addClass('active');
            setTimeout(() => $button.removeClass('active'), 200);

            // Track action
            this.trackAction('quick_action', action || 'click');

            // Navigate
            if (href && href !== '#') {
                window.location.href = href;
            }
        },

        handleInsightAction(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const action = $button.text().trim();

            utils.showNotification(`"${action}" özelliği yakında kullanıma sunulacak`, 'info', 4000);
            this.trackAction('insight_action', action);
        },

        handleScheduleNavigation(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const direction = $button.hasClass('prev') ? 'prev' : 'next';
            const $dateElement = $('.schedule-date');
            
            // Visual feedback
            $button.addClass('active');
            setTimeout(() => $button.removeClass('active'), 100);

            // Update date (placeholder - real implementation would fetch data)
            let currentDate = new Date($dateElement.text() || new Date());
            if (direction === 'next') {
                currentDate.setDate(currentDate.getDate() + 1);
            } else {
                currentDate.setDate(currentDate.getDate() - 1);
            }

            $dateElement.text(currentDate.toLocaleDateString('tr-TR', {
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            }));

            this.trackAction('schedule_navigation', direction);
        },

        handleActivityAction(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $activity = $button.closest('.activity-item');
            const activityId = $activity.data('id');

            // Show activity details (placeholder)
            utils.showNotification('Aktivite detayları yakında eklenecek', 'info');
            this.trackAction('activity_view', activityId);
        },

        trackAction(action, data) {
            // Simple action tracking
            if (window.gtag) {
                gtag('event', action, {
                    'custom_parameter': data
                });
            }
            
            // Store in localStorage for analytics
            const actions = utils.storage.get('user_actions') || [];
            actions.push({
                action: action,
                data: data,
                timestamp: new Date().toISOString(),
                page: window.location.pathname
            });
            
            // Keep only last 100 actions
            if (actions.length > 100) {
                actions.splice(0, actions.length - 100);
            }
            
            utils.storage.set('user_actions', actions);
        }
    };

    // Form handling
    const forms = {
        autoSaveTimers: {},

        init() {
            this.bindEvents();
            this.initValidation();
            this.restoreFormData();
        },

        bindEvents() {
            // Form submission
            $(document).on('submit', '.representative-form, .hierarchy-form, .team-form', this.handleSubmit.bind(this));
            
            // Real-time validation
            $(document).on('blur', '.form-input, .form-select, .form-textarea', this.validateField.bind(this));
            $(document).on('input', '.form-input, .form-textarea', this.handleInput.bind(this));
            
            // File upload
            $(document).on('change', 'input[type="file"]', this.handleFileUpload.bind(this));
            
            // Auto-save
            $(document).on('input change', '.auto-save', this.setupAutoSave.bind(this));
            
            // Form reset
            $(document).on('click', '.btn-reset', this.handleReset.bind(this));
            
            // Dynamic form interactions
            $(document).on('change', '.form-trigger', this.handleFormTrigger.bind(this));
        },

        handleSubmit(e) {
            const $form = $(e.target);
            const $submitButton = $form.find('button[type="submit"]');

            // Validate form
            if (!this.validateForm($form)) {
                e.preventDefault();
                utils.scrollTo($form.find('.invalid').first(), 100);
                utils.showNotification('Lütfen formdaki hataları düzeltin', 'error');
                return false;
            }

            // Show loading state
            utils.setLoading($submitButton);
            
            // Clear auto-save data on successful submit
            const formId = $form.attr('id');
            if (formId) {
                utils.storage.remove(`form_${formId}`);
            }

            utils.showNotification('Form gönderiliyor...', 'info');
        },

        validateForm($form) {
            let isValid = true;
            const $fields = $form.find('.form-input, .form-select, .form-textarea');

            // Clear previous errors
            $form.find('.form-error').remove();
            $fields.removeClass('invalid valid');

            // Validate each field
            $fields.each((index, field) => {
                if (!this.validateField({ target: field })) {
                    isValid = false;
                }
            });

            return isValid;
        },

        validateField(e) {
            const $field = $(e.target);
            const value = $field.val().trim();
            const fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();
            const isRequired = $field.prop('required');
            let isValid = true;
            let errorMessage = '';

            // Clear previous error
            this.clearFieldError($field);

            // Required validation
            if (isRequired && !value) {
                errorMessage = 'Bu alan zorunludur';
                isValid = false;
            }
            // Email validation
            else if (fieldType === 'email' && value && !this.isValidEmail(value)) {
                errorMessage = 'Geçerli bir e-posta adresi girin';
                isValid = false;
            }
            // Phone validation
            else if (fieldType === 'tel' && value && !this.isValidPhone(value)) {
                errorMessage = 'Geçerli bir telefon numarası girin (0XXX XXX XX XX)';
                isValid = false;
            }
            // Number validation
            else if (fieldType === 'number' && value) {
                const min = parseFloat($field.attr('min'));
                const max = parseFloat($field.attr('max'));
                const numValue = parseFloat(value);
                
                if (isNaN(numValue)) {
                    errorMessage = 'Geçerli bir sayı girin';
                    isValid = false;
                } else if (!isNaN(min) && numValue < min) {
                    errorMessage = `En az ${min} olmalıdır`;
                    isValid = false;
                } else if (!isNaN(max) && numValue > max) {
                    errorMessage = `En fazla ${max} olabilir`;
                    isValid = false;
                }
            }
            // Custom validation patterns
            else if ($field.attr('pattern') && value && !new RegExp($field.attr('pattern')).test(value)) {
                errorMessage = $field.data('pattern-message') || 'Geçersiz format';
                isValid = false;
            }

            // Show error or success
            if (!isValid) {
                this.showFieldError($field, errorMessage);
            } else if (value) {
                $field.addClass('valid');
            }

            return isValid;
        },

        showFieldError($field, message) {
            $field.addClass('invalid').removeClass('valid');
            
            const $errorElement = $(`<div class="form-error" role="alert">${message}</div>`);
            $field.after($errorElement);
            
            // Announce error to screen readers
            $field.attr('aria-describedby', $field.attr('id') + '-error');
            $errorElement.attr('id', $field.attr('id') + '-error');
        },

        clearFieldError($field) {
            $field.removeClass('invalid');
            $field.siblings('.form-error').remove();
            $field.removeAttr('aria-describedby');
        },

        handleInput(e) {
            const $field = $(e.target);
            
            // Clear error on input
            if ($field.hasClass('invalid')) {
                this.clearFieldError($field);
            }

            // Format specific fields
            if ($field.hasClass('format-currency')) {
                this.formatCurrencyInput($field);
            } else if ($field.hasClass('format-phone')) {
                this.formatPhoneInput($field);
            }
        },

        formatCurrencyInput($field) {
            let value = $field.val().replace(/[^\d]/g, '');
            if (value) {
                value = utils.formatNumber(parseInt(value));
                $field.val(value);
            }
        },

        formatPhoneInput($field) {
            let value = $field.val().replace(/[^\d]/g, '');
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.substring(0, 4) + ' ' + value.substring(4);
                } else if (value.length <= 8) {
                    value = value.substring(0, 4) + ' ' + value.substring(4, 7) + ' ' + value.substring(7);
                } else {
                    value = value.substring(0, 4) + ' ' + value.substring(4, 7) + ' ' + value.substring(7, 9) + ' ' + value.substring(9, 11);
                }
                $field.val(value);
            }
        },

        isValidEmail(email) {
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },

        isValidPhone(phone) {
            const cleanPhone = phone.replace(/[\s\-\(\)]/g, '');
            const regex = /^(\+90|0)?[5][0-9]{9}$/;
            return regex.test(cleanPhone);
        },

        handleFileUpload(e) {
            const $input = $(e.target);
            const file = e.target.files[0];
            const maxSize = parseInt($input.data('max-size')) || 5242880; // 5MB default
            const allowedTypes = $input.data('allowed-types') || 'image/jpeg,image/jpg,image/png,image/gif';

            if (file) {
                // File size validation
                if (file.size > maxSize) {
                    utils.showNotification(`Dosya boyutu ${Math.round(maxSize/1024/1024)}MB'dan büyük olamaz`, 'error');
                    $input.val('');
                    return;
                }

                // File type validation
                if (!allowedTypes.split(',').includes(file.type)) {
                    utils.showNotification('Desteklenmeyen dosya formatı', 'error');
                    $input.val('');
                    return;
                }

                // Show preview
                this.showFilePreview($input, file);
                utils.showNotification('Dosya başarıyla seçildi', 'success', 2000);
            }
        },

        showFilePreview($input, file) {
            const $preview = $input.siblings('.file-preview');
            if ($preview.length && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $preview.html(`
                        <div class="preview-image">
                            <img src="${e.target.result}" alt="Önizleme" />
                            <div class="preview-info">
                                <span class="file-name">${file.name}</span>
                                <span class="file-size">${(file.size / 1024).toFixed(1)} KB</span>
                            </div>
                        </div>
                    `);
                };
                reader.readAsDataURL(file);
            }
        },

        setupAutoSave(e) {
            const $field = $(e.target);
            const $form = $field.closest('form');
            const formId = $form.attr('id');

            if (!formId) return;

            // Clear existing timer
            if (this.autoSaveTimers[formId]) {
                clearTimeout(this.autoSaveTimers[formId]);
            }

            // Set new timer
            this.autoSaveTimers[formId] = setTimeout(() => {
                this.autoSave($form);
            }, config.autoSaveDelay);
        },

        autoSave($form) {
            const formId = $form.attr('id');
            if (!formId) return;

            const formData = $form.serializeArray();
            const dataObject = {};
            
            formData.forEach(item => {
                dataObject[item.name] = item.value;
            });

            utils.storage.set(`form_${formId}`, {
                data: dataObject,
                timestamp: new Date().toISOString()
            });

            // Show saved indicator
            this.showSavedIndicator($form);
        },

        showSavedIndicator($form) {
            let $indicator = $form.find('.auto-save-indicator');
            if (!$indicator.length) {
                $indicator = $('<div class="auto-save-indicator">Otomatik kaydedildi ✓</div>');
                $form.prepend($indicator);
            }

            $indicator.addClass('show');
            setTimeout(() => {
                $indicator.removeClass('show');
            }, 2000);
        },

        restoreFormData() {
            $('form[id]').each((index, form) => {
                const $form = $(form);
                const formId = $form.attr('id');
                const savedData = utils.storage.get(`form_${formId}`);

                if (savedData && savedData.data) {
                    // Check if data is not too old (24 hours)
                    const saveTime = new Date(savedData.timestamp);
                    const now = new Date();
                    const hoursDiff = (now - saveTime) / (1000 * 60 * 60);

                    if (hoursDiff < 24) {
                        // Restore form data
                        Object.keys(savedData.data).forEach(name => {
                            const $field = $form.find(`[name="${name}"]`);
                            if ($field.length && !$field.val()) {
                                $field.val(savedData.data[name]);
                            }
                        });

                        // Show restore notification
                        utils.showNotification('Form verileri geri yüklendi', 'info', 3000);
                    }
                }
            });
        },

        handleReset(e) {
            e.preventDefault();
            const $button = $(e.target);
            const $form = $button.closest('form');
            
            utils.confirm('Formu sıfırlamak istediğinizden emin misiniz?', () => {
                $form[0].reset();
                $form.find('.form-error').remove();
                $form.find('.invalid, .valid').removeClass('invalid valid');
                
                // Clear auto-save data
                const formId = $form.attr('id');
                if (formId) {
                    utils.storage.remove(`form_${formId}`);
                }
                
                utils.showNotification('Form sıfırlandı', 'success', 2000);
            });
        },

        handleFormTrigger(e) {
            const $trigger = $(e.target);
            const targetSelector = $trigger.data('target');
            const action = $trigger.data('action') || 'toggle';
            
            if (targetSelector) {
                const $target = $(targetSelector);
                
                switch (action) {
                    case 'show':
                        $target.slideDown(config.animationDuration);
                        break;
                    case 'hide':
                        $target.slideUp(config.animationDuration);
                        break;
                    case 'toggle':
                    default:
                        $target.slideToggle(config.animationDuration);
                        break;
                }
            }
        }
    };

    // Sidebar and navigation
    const navigation = {
        init() {
            this.bindEvents();
            this.handleResize();
            this.initMobileMenu();
        },

        bindEvents() {
            // Mobile sidebar toggle
            $(document).on('click', '#sidebar-toggle', this.toggleSidebar.bind(this));
            $(document).on('click', '#sidebar-close', this.closeSidebar.bind(this));
            $(document).on('click', '#sidebar-overlay', this.closeSidebar.bind(this));
            
            // Mobile user menu
            $(document).on('click', '#mobile-user-toggle', this.toggleUserMenu.bind(this));
            
            // Navigation links
            $(document).on('click', '.nav-link:not(.logout)', this.handleNavigation.bind(this));
            
            // Window resize
            $(window).on('resize', utils.throttle(this.handleResize.bind(this), 250));
            
            // Escape key handling
            $(document).on('keydown', this.handleKeydown.bind(this));
        },

        toggleSidebar() {
            const $sidebar = $('#sidebar');
            const $overlay = $('#sidebar-overlay');
            const $toggle = $('#sidebar-toggle');
            
            if ($sidebar.hasClass('active')) {
                this.closeSidebar();
            } else {
                $sidebar.addClass('active');
                $overlay.addClass('active');
                $toggle.addClass('active');
                $('body').addClass('sidebar-open');
                
                // Focus management
                $sidebar.find('.nav-link').first().focus();
            }
        },

        closeSidebar() {
            const $sidebar = $('#sidebar');
            const $overlay = $('#sidebar-overlay');
            const $toggle = $('#sidebar-toggle');
            const $userDropdown = $('#mobile-user-dropdown');
            
            $sidebar.removeClass('active');
            $overlay.removeClass('active');
            $toggle.removeClass('active');
            $userDropdown.removeClass('active');
            $('body').removeClass('sidebar-open');
        },

        toggleUserMenu(e) {
            e.stopPropagation();
            const $dropdown = $('#mobile-user-dropdown');
            $dropdown.toggleClass('active');
            
            // Close on outside click
            if ($dropdown.hasClass('active')) {
                $(document).one('click', () => {
                    $dropdown.removeClass('active');
                });
            }
        },

        handleNavigation(e) {
            const $link = $(e.currentTarget);
            const href = $link.attr('href');
            
            // Add loading state
            if (href && href !== '#' && !href.startsWith('javascript:')) {
                $link.addClass('loading');
                
                // Close mobile sidebar after navigation
                if (window.innerWidth <= config.breakpoints.mobile) {
                    setTimeout(() => {
                        this.closeSidebar();
                    }, 150);
                }
            }
        },

        handleResize() {
            const isMobile = window.innerWidth <= config.breakpoints.mobile;
            
            if (!isMobile) {
                this.closeSidebar();
            }
            
            // Update main container margin
            const $mainContainer = $('#main-container');
            if (isMobile) {
                $mainContainer.css('margin-left', '0');
            } else {
                $mainContainer.css('margin-left', '280px');
            }
        },

        handleKeydown(e) {
            // Escape key closes sidebar and dropdowns
            if (e.key === 'Escape') {
                this.closeSidebar();
                $('#mobile-user-dropdown').removeClass('active');
            }
            
            // Tab navigation within sidebar
            if (e.key === 'Tab' && $('#sidebar').hasClass('active')) {
                const $focusableElements = $('#sidebar').find('a, button, [tabindex]:not([tabindex="-1"])');
                const $firstElement = $focusableElements.first();
                const $lastElement = $focusableElements.last();
                
                if (e.shiftKey && document.activeElement === $firstElement[0]) {
                    e.preventDefault();
                    $lastElement.focus();
                } else if (!e.shiftKey && document.activeElement === $lastElement[0]) {
                    e.preventDefault();
                    $firstElement.focus();
                }
            }
        },

        initMobileMenu() {
            // Touch gesture support for mobile sidebar
            let touchStartX = 0;
            let touchStartY = 0;
            let isSwiping = false;
            
            $(document).on('touchstart', (e) => {
                touchStartX = e.originalEvent.touches[0].clientX;
                touchStartY = e.originalEvent.touches[0].clientY;
                isSwiping = false;
            });
            
            $(document).on('touchmove', (e) => {
                if (!isSwiping) {
                    const touchMoveX = e.originalEvent.touches[0].clientX;
                    const touchMoveY = e.originalEvent.touches[0].clientY;
                    const deltaX = touchMoveX - touchStartX;
                    const deltaY = touchMoveY - touchStartY;
                    
                    // Check if horizontal swipe
                    if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 30) {
                        isSwiping = true;
                        
                        if (window.innerWidth <= config.breakpoints.mobile) {
                            // Swipe right from edge to open
                            if (deltaX > 0 && touchStartX < 50 && !$('#sidebar').hasClass('active')) {
                                this.toggleSidebar();
                            }
                            // Swipe left to close
                            else if (deltaX < 0 && $('#sidebar').hasClass('active')) {
                                this.closeSidebar();
                            }
                        }
                    }
                }
            });
        }
    };

    // Data tables functionality
    const dataTables = {
        init() {
            this.bindEvents();
            this.initSorting();
            this.initFiltering();
        },

        bindEvents() {
            // Table sorting
            $(document).on('click', '.sortable th', this.handleSort.bind(this));
            
            // Table filtering
            $(document).on('input', '.table-filter', utils.debounce(this.handleFilter.bind(this), config.debounceDelay));
            
            // Row actions
            $(document).on('click', '.row-action', this.handleRowAction.bind(this));
            
            // Pagination
            $(document).on('click', '.pagination a', this.handlePagination.bind(this));
        },

        handleSort(e) {
            const $th = $(e.currentTarget);
            const $table = $th.closest('table');
            const columnIndex = $th.index();
            const currentSort = $th.data('sort') || 'none';
            let newSort = 'asc';
            
            if (currentSort === 'asc') {
                newSort = 'desc';
            } else if (currentSort === 'desc') {
                newSort = 'none';
            }
            
            // Clear other column sorts
            $table.find('th').removeData('sort').removeClass('sort-asc sort-desc');
            
            // Set new sort
            if (newSort !== 'none') {
                $th.data('sort', newSort).addClass(`sort-${newSort}`);
                this.sortTable($table, columnIndex, newSort);
            }
        },

        sortTable($table, columnIndex, direction) {
            const $tbody = $table.find('tbody');
            const $rows = $tbody.find('tr').toArray();
            
            $rows.sort((a, b) => {
                const aText = $(a).find('td').eq(columnIndex).text().trim();
                const bText = $(b).find('td').eq(columnIndex).text().trim();
                
                // Try to parse as numbers
                const aNum = parseFloat(aText.replace(/[^\d.-]/g, ''));
                const bNum = parseFloat(bText.replace(/[^\d.-]/g, ''));
                
                let result = 0;
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    result = aNum - bNum;
                } else {
                    result = aText.localeCompare(bText, 'tr', { numeric: true });
                }
                
                return direction === 'desc' ? -result : result;
            });
            
            $tbody.append($rows);
        },

        handleFilter(e) {
            const $input = $(e.target);
            const filter = $input.val().toLowerCase();
            const $table = $input.closest('.table-container').find('table');
            const $rows = $table.find('tbody tr');
            
            $rows.each((index, row) => {
                const $row = $(row);
                const text = $row.text().toLowerCase();
                
                if (text.includes(filter)) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
            
            // Update row count
            const visibleCount = $rows.filter(':visible').length;
            const totalCount = $rows.length;
            
            $input.siblings('.filter-results').text(`${visibleCount} / ${totalCount} kayıt gösteriliyor`);
        },

        handleRowAction(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const action = $button.data('action');
            const rowId = $button.closest('tr').data('id');
            
            switch (action) {
                case 'edit':
                    this.editRow(rowId);
                    break;
                case 'delete':
                    this.deleteRow(rowId, $button);
                    break;
                case 'view':
                    this.viewRow(rowId);
                    break;
                default:
                    console.warn('Unknown row action:', action);
            }
        },

        editRow(rowId) {
            // Navigate to edit page
            const editUrl = `/edit/${rowId}`;
            window.location.href = editUrl;
        },

        deleteRow(rowId, $button) {
            utils.confirm('Bu kaydı silmek istediğinizden emin misiniz?', () => {
                const $row = $button.closest('tr');
                utils.setLoading($button);
                
                utils.ajax({
                    data: {
                        action: 'delete_row',
                        row_id: rowId,
                        nonce: config.nonce
                    }
                })
                .done((response) => {
                    if (response.success) {
                        $row.fadeOut(config.animationDuration, () => {
                            $row.remove();
                        });
                        utils.showNotification('Kayıt başarıyla silindi', 'success');
                    } else {
                        utils.showNotification(response.data || 'Silme işlemi başarısız', 'error');
                    }
                })
                .fail(() => {
                    utils.showNotification('Bağlantı hatası oluştu', 'error');
                })
                .always(() => {
                    utils.setLoading($button, false);
                });
            });
        },

        viewRow(rowId) {
            // Navigate to view page
            const viewUrl = `/view/${rowId}`;
            window.location.href = viewUrl;
        },

        handlePagination(e) {
            e.preventDefault();
            const $link = $(e.currentTarget);
            const page = $link.data('page');
            
            if (page) {
                this.loadPage(page);
            }
        },

        loadPage(page) {
            // Implementation for loading new page data
            utils.showNotification(`Sayfa ${page} yükleniyor...`, 'info');
        },

        initSorting() {
            // Add sorting indicators to sortable columns
            $('.sortable th').append('<span class="sort-indicator"></span>');
        },

        initFiltering() {
            // Add filter results display
            $('.table-filter').each((index, input) => {
                const $input = $(input);
                if (!$input.siblings('.filter-results').length) {
                    $input.after('<div class="filter-results"></div>');
                }
            });
        }
    };

    // Performance monitoring
    const performance = {
        metrics: {
            pageLoadTime: 0,
            ajaxRequests: 0,
            errors: 0
        },

        init() {
            this.measurePageLoad();
            this.setupErrorTracking();
            this.monitorAjax();
        },

        measurePageLoad() {
            if (window.performance && window.performance.timing) {
                window.addEventListener('load', () => {
                    const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
                    this.metrics.pageLoadTime = loadTime;
                    
                    if (loadTime > 3000) {
                        console.warn('Slow page load detected:', loadTime + 'ms');
                    }
                });
            }
        },

        setupErrorTracking() {
            window.addEventListener('error', (e) => {
                this.metrics.errors++;
                console.error('JavaScript error:', e.error);
                
                // Report critical errors
                if (this.metrics.errors > 5) {
                    utils.showNotification('Sayfa yenilenebilir, çok fazla hata oluştu', 'warning');
                }
            });
        },

        monitorAjax() {
            // Monitor jQuery AJAX requests
            $(document).ajaxStart(() => {
                this.metrics.ajaxRequests++;
            });

            $(document).ajaxError((event, xhr, settings) => {
                console.error('AJAX error:', xhr.status, xhr.statusText, settings.url);
                
                if (xhr.status === 0) {
                    utils.showNotification('İnternet bağlantınızı kontrol edin', 'error');
                } else if (xhr.status >= 500) {
                    utils.showNotification('Sunucu hatası oluştu', 'error');
                }
            });
        }
    };

    // Initialize everything when DOM is ready
    $(document).ready(() => {
        try {
            // Initialize core modules
            dashboard.init();
            forms.init();
            navigation.init();
            dataTables.init();
            performance.init();

            // Custom initialization based on page
            const currentView = new URLSearchParams(window.location.search).get('view') || 'dashboard';
            
            switch (currentView) {
                case 'dashboard':
                    // Dashboard specific initialization
                    break;
                case 'organization':
                case 'organization_management':
                    // Organization specific initialization
                    break;
                default:
                    // Default initialization
                    break;
            }

            // Global click handler for smooth interactions
            $(document).on('click', 'a, button', function() {
                const $this = $(this);
                if (!$this.hasClass('no-ripple')) {
                    // Add ripple effect
                    $this.addClass('clicked');
                    setTimeout(() => $this.removeClass('clicked'), 200);
                }
            });

            // Accessibility improvements
            $('img').each((index, img) => {
                if (!$(img).attr('alt')) {
                    $(img).attr('alt', '');
                }
            });

            // Success message
            console.log('Representative Panel initialized successfully');
            
        } catch (error) {
            console.error('Error initializing Representative Panel:', error);
            utils.showNotification('Sayfa yüklenirken hata oluştu', 'error');
        }
    });

    // Export to global namespace
    RepresentativePanel.utils = utils;
    RepresentativePanel.dashboard = dashboard;
    RepresentativePanel.forms = forms;
    RepresentativePanel.navigation = navigation;
    RepresentativePanel.dataTables = dataTables;
    RepresentativePanel.performance = performance;

})(jQuery);