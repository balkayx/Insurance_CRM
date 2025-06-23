<?php
/**
 * Frontend Tekliflerim Sayfası - Enhanced Modern Version
 * @version 2.1.0
 * @date 2025-05-30
 * @author anadolubirlik
 * @description Teklif verilmiş müşterilerin listelendiği sayfa - Modern Material Design UI
 * @fixed Line 1451 - Missing closing tag bug fixed
 * @updated Policies.php ile tam uyumlu tasarım
 */

// Güvenlik kontrolü
if (!defined('ABSPATH') || !is_user_logged_in()) {
    wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'insurance-crm'), __('Erişim Engellendi', 'insurance-crm'), array('response' => 403));
}

// Modern CSS Framework - Material Design 3
wp_enqueue_style('font-awesome');
?>

<style>
/* Modern CSS Styles with Material Design 3 Principles - Enhanced v5.2.0 */
:root {
    /* Colors */
    --primary: #1976d2;
    --primary-dark: #1565c0;
    --primary-light: #42a5f5;
    --secondary: #9c27b0;
    --success: #2e7d32;
    --warning: #f57c00;
    --danger: #d32f2f;
    --info: #0288d1;
    
    /* Neutral Colors */
    --surface: #ffffff;
    --surface-variant: #f5f5f5;
    --surface-container: #fafafa;
    --on-surface: #1c1b1f;
    --on-surface-variant: #49454f;
    --outline: #79747e;
    --outline-variant: #cac4d0;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-base: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-2xl: 1.5rem;
    
    /* Spacing */
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    
    /* Border Radius */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms ease;
    --transition-base: 250ms ease;
    --transition-slow: 350ms ease;

    /* Legacy Support - Keep for backward compatibility */
    --md-primary: var(--primary);
    --md-primary-light: var(--primary-light);
    --md-primary-dark: var(--primary-dark);
    --md-secondary: var(--secondary);
    --md-surface: var(--surface);
    --md-on-primary: #ffffff;
    --md-on-surface: var(--on-surface);
    --md-background: var(--surface-container);
    
    /* Neutral Colors - Legacy Support */
    --neutral-50: #fafafa;
    --neutral-100: #f5f5f5;
    --neutral-200: #eeeeee;
    --neutral-300: #e0e0e0;
    --neutral-400: #bdbdbd;
    --neutral-500: #9e9e9e;
    --neutral-600: #757575;
    --neutral-700: #616161;
    --neutral-800: #424242;
    --neutral-900: #212121;

    --radius-none: 0;
    --radius-xs: 2px;
    --radius-2xl: 16px;
    --radius-3xl: 24px;
    --radius-full: 9999px;
}

/* Reset & Base Styles */
* {
    box-sizing: border-box;
}

.modern-crm-container {
    font-family: var(--font-family);
    color: var(--on-surface);
    background-color: var(--surface-container);
    min-height: 100vh;
    padding: var(--spacing-lg);
    margin: 0;
}

.error-notice {
    background: #ffebee;
    border: 1px solid #e57373;
    color: #c62828;
    padding: var(--spacing-md);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    font-weight: 500;
}

/* Notification Banner */
.notification-banner {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md) var(--spacing-lg);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    animation: slideInDown 0.3s ease;
    box-shadow: var(--shadow-md);
}

.notification-success {
    background-color: #e8f5e9;
    border-left: 4px solid var(--success);
}

.notification-error {
    background-color: #ffebee;
    border-left: 4px solid var(--danger);
}

.notification-warning {
    background-color: #fff3e0;
    border-left: 4px solid var(--warning);
}

.notification-info {
    background-color: #e1f5fe;
    border-left: 4px solid var(--info);
}

.notification-icon {
    font-size: var(--font-size-xl);
}

.notification-success .notification-icon {
    color: var(--success);
}

.notification-error .notification-icon {
    color: var(--danger);
}

.notification-warning .notification-icon {
    color: var(--warning);
}

.notification-info .notification-icon {
    color: var(--info);
}

.notification-content {
    flex-grow: 1;
    font-size: var(--font-size-base);
}

.notification-close {
    background: none;
    border: none;
    color: var(--on-surface-variant);
    cursor: pointer;
    font-size: var(--font-size-lg);
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    transition: background-color var(--transition-fast);
}

.notification-close:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

@keyframes slideInDown {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Header Styles - Updated to match policies.php */
.crm-header {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}

.title-section {
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

.page-title {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.page-title i {
    font-size: var(--font-size-xl);
    color: var(--primary);
}

.page-title h1 {
    margin: 0;
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--on-surface);
}

.version-badge {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-md);
    font-size: var(--font-size-xs);
    font-weight: 600;
    letter-spacing: 0.5px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

/* Button Components - From policies.php */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border: 1px solid transparent;
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
    background: none;
    white-space: nowrap;
}

.btn:before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.btn:hover:before {
    left: 100%;
}

.btn-primary {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-secondary {
    background: #757575;
    color: white;
}

.btn-secondary:hover {
    background: #616161;
    transform: translateY(-1px);
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--outline-variant);
}

.btn-outline:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #2e7d32;
    transform: translateY(-1px);
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #ef6c00;
    transform: translateY(-1px);
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #d32f2f;
    transform: translateY(-1px);
}

.btn-info {
    background: var(--info);
    color: white;
}

.btn-info:hover {
    background: #0288d1;
    transform: translateY(-1px);
}

/* Filter Controls */
.filter-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.filter-toggle {
    position: relative;
}

.filter-count {
    position: absolute;
    top: -8px;
    right: -8px;
    background: var(--danger);
    color: white;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
    line-height: 1;
}

.chevron {
    transition: transform var(--transition-fast);
}

.filter-toggle.active .chevron {
    transform: rotate(180deg);
}

/* Scope Toggle - Kişisel/Ekip Seçimi */
.scope-toggle {
    display: flex;
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: 4px;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
}

.scope-toggle input[type="radio"] {
    display: none;
}

.scope-toggle label {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    cursor: pointer;
    font-size: var(--font-size-sm);
    font-weight: 500;
    transition: all var(--transition-fast);
    color: var(--on-surface-variant);
}

.scope-toggle input[type="radio"]:checked + label {
    background: var(--surface);
    color: var(--primary);
    box-shadow: var(--shadow-sm);
}

.scope-toggle label:hover {
    color: var(--on-surface);
}

/* Modern Filter Section - Updated to match policies.php */
.filters-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
    transition: all var(--transition-base);
    display: none; /* Default olarak gizli */
}

.filters-section.show {
    display: block;
}

.filters-container {
    padding: var(--spacing-xl);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.filter-group label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
}

.form-input,
.form-select {
    width: 100%;
    padding: var(--spacing-md);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    font-size: var(--font-size-base);
    color: var(--on-surface);
    background-color: var(--surface);
    transition: all var(--transition-fast);
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.1);
}

.filters-form {
    width: 100%;
}

.filters-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: flex-end;
    margin-top: var(--spacing-lg);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
}

/* Search Input Wrapper */
.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-input-wrapper i {
    position: absolute;
    left: var(--spacing-md);
    color: var(--on-surface-variant);
    z-index: 1;
}

.search-input-wrapper .form-input {
    padding-left: calc(var(--spacing-md) + 20px);
}

/* Statistics Cards - Updated to match policies.php */
.stats-container {
    padding: var(--spacing-lg);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
}

/* Statistics Cards - Policies.php Compatible Styles */
.dashboard-section {
    background: var(--surface);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-xl);
    padding: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.stat-card:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--primary);
}

.stat-card.success:before {
    background: var(--success);
}

.stat-card.warning:before {
    background: var(--warning);
}

.stat-card.danger:before {
    background: var(--danger);
}

.stat-card.info:before {
    background: var(--info);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
    margin-bottom: var(--spacing-md);
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.stat-card.primary .stat-icon {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.stat-card.success .stat-icon {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.stat-card.warning .stat-icon {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.stat-card.danger .stat-icon {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.stat-card.info .stat-icon {
    background: rgba(2, 136, 209, 0.1);
    color: var(--info);
}

.stat-content h3 {
    font-size: var(--font-size-sm);
    font-weight: 600;
    color: var(--on-surface-variant);
    margin: 0 0 var(--spacing-xs) 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: var(--font-size-2xl);
    font-weight: 700;
    color: var(--on-surface);
    margin: 0 0 var(--spacing-xs) 0;
}

.stat-subtitle {
    font-size: var(--font-size-xs);
    color: var(--on-surface-variant);
    margin: 0;
}

/* Table Wrapper - Policies.php Compatible */
.table-wrapper {
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.table-header {
    background: var(--surface-variant);
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--outline-variant);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.table-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.table-meta {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.table-meta span {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.table-meta strong {
    color: var(--on-surface);
    font-weight: 600;
}

.view-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-md);
    font-size: var(--font-size-xs);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.view-badge.team {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
    border: 1px solid rgba(25, 118, 210, 0.3);
}

.view-badge.personal {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
    border: 1px solid rgba(46, 125, 50, 0.3);
}

.table-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.per-page-selector {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.per-page-selector label {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
    font-weight: 500;
}

.per-page-selector select {
    padding: var(--spacing-xs) var(--spacing-sm);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--on-surface);
    font-size: var(--font-size-sm);
}

/* Table Header Scroll */
.table-header-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    position: sticky;
    top: 0;
    z-index: 3;
    margin-bottom: -15px;
    height: 15px;
    background: var(--surface-variant);
}

.table-header-scroll div {
    height: 1px;
    width: 100%;
}

/* Table Container */
.table-container {
    overflow-x: auto;
    overflow-y: visible;
    max-height: 70vh;
    background: var(--surface);
}

.table-container::-webkit-scrollbar,
.table-header-scroll::-webkit-scrollbar {
    height: 8px;
}

.table-container::-webkit-scrollbar-thumb,
.table-header-scroll::-webkit-scrollbar-thumb {
    background: var(--outline);
    border-radius: var(--radius-sm);
}

.table-container::-webkit-scrollbar-track,
.table-header-scroll::-webkit-scrollbar-track {
    background: var(--surface-variant);
}

/* Legacy stats styles for compatibility */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-md);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    transition: all var(--transition-fast);
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
}

.stat-total .stat-icon {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.stat-active .stat-icon {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.stat-premium .stat-icon {
    background: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.stat-expires-soon .stat-icon {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.stat-expired .stat-icon {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: var(--font-size-xl);
    font-weight: 700;
    color: var(--on-surface);
    margin: 0;
}

.stat-label {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
    margin: 0;
}

/* Table Section - Updated to match policies.php */
.table-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.table-header {
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.table-title {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.table-title h3 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
}

.table-title i {
    color: var(--primary);
}

.table-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.per-page-selector {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.per-page-selector label {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.per-page-selector select {
    padding: var(--spacing-xs) var(--spacing-sm);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--on-surface);
    font-size: var(--font-size-sm);
}

.table-container {
    overflow-x: auto;
}

.table-header-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    height: 15px;
    border-bottom: 1px solid var(--outline-variant);
}

.table-header-scroll > div {
    height: 1px;
}

/* Table Styling - Updated to match policies.php */
.offers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.offers-table th,
.offers-table td {
    padding: var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--outline-variant);
    font-size: var(--font-size-sm);
    vertical-align: middle;
}

.offers-table th {
    background: var(--surface-variant);
    font-weight: 600;
    color: var(--on-surface);
    position: sticky;
    top: 0;
    z-index: 1;
}

.offers-table th a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.offers-table th a:hover {
    color: var(--primary);
}

.offers-table tbody tr {
    transition: all var(--transition-fast);
}

.offers-table tbody tr:hover {
    background: var(--surface-variant);
    transform: scale(1.002);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Row Status Colors */
.offers-table tr.row-inactive td {
    background: rgba(117, 117, 117, 0.05) !important;
    border-left: 3px solid #757575;
}

.offers-table tr.row-expired td {
    background: rgba(245, 124, 0, 0.05) !important;
    border-left: 3px solid var(--warning);
}

.offers-table tr.row-expiring td {
    background: rgba(25, 118, 210, 0.05) !important;
    border-left: 3px solid var(--primary);
}

/* Table Cell Specific Styles */
.customer-info {
    min-width: 200px;
}

.customer-name {
    margin-bottom: var(--spacing-xs);
}

.customer-name a {
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
}

.customer-name a:hover {
    text-decoration: underline;
}

.customer-details {
    font-size: var(--font-size-xs);
    color: var(--on-surface-variant);
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
}

.customer-details span {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.amount-cell {
    font-weight: 600;
    color: var(--success);
    text-align: right;
}

.currency-symbol {
    font-size: var(--font-size-xs);
    opacity: 0.8;
}

.no-data {
    color: var(--on-surface-variant);
    font-style: italic;
    font-size: var(--font-size-xs);
}

/* Action Buttons in Table */
.actions-column {
    width: 120px;
    text-align: center;
}

.action-buttons-group {
    display: flex;
    gap: var(--spacing-xs);
    justify-content: center;
    align-items: center;
    flex-wrap: wrap;
}

.action-buttons-group .btn {
    padding: var(--spacing-xs);
    min-width: 32px;
    height: 32px;
    font-size: var(--font-size-xs);
}

/* Badge Styling */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px var(--spacing-xs);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-success {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
    border: 1px solid rgba(46, 125, 50, 0.2);
}

.badge-warning {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
    border: 1px solid rgba(245, 124, 0, 0.2);
}

.badge-danger {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
    border: 1px solid rgba(211, 47, 47, 0.2);
}

.badge-secondary {
    background: rgba(117, 117, 117, 0.1);
    color: #757575;
    border: 1px solid rgba(117, 117, 117, 0.2);
}

.badge-primary {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
    border: 1px solid rgba(25, 118, 210, 0.2);
}

/* Pagination Styling */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg);
    background: var(--surface-variant);
    border-top: 1px solid var(--outline-variant);
}

.pagination {
    display: flex;
    gap: var(--spacing-xs);
}

.pagination a,
.pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    min-width: 32px;
    font-size: var(--font-size-sm);
    line-height: 1;
    border: 1px solid var(--outline-variant);
    background-color: var(--surface);
    color: var(--on-surface-variant);
    text-decoration: none;
    transition: all var(--transition-fast) ease;
}

.pagination a:hover {
    background-color: var(--surface-variant);
    border-color: var(--outline);
    color: var(--on-surface);
}

.pagination span.current {
    background-color: var(--primary);
    border-color: var(--primary);
    color: white;
    font-weight: 500;
}

.pagination-info {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

/* No Data Styles */
.no-data-container {
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    padding: var(--spacing-2xl);
    text-align: center;
}

.no-data-content {
    max-width: 400px;
    margin: 0 auto;
}

.no-data-icon {
    font-size: 4rem;
    color: var(--on-surface-variant);
    margin-bottom: var(--spacing-lg);
}

.no-data-content h3 {
    font-size: var(--font-size-xl);
    color: var(--on-surface);
    margin-bottom: var(--spacing-md);
}

.no-data-content p {
    color: var(--on-surface-variant);
    margin-bottom: var(--spacing-xl);
    line-height: 1.6;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-lg);
    border-radius: var(--radius-md);
    text-decoration: none;
    font-weight: 500;
    font-size: var(--font-size-sm);
    transition: all var(--transition-fast);
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

/* Responsive Design */
@media (max-width: 1400px) {
    .table-container {
        position: relative;
        overflow-x: auto;
        margin-bottom: 0 !important;
    }

    .table-header-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        height: 15px;
        border-bottom: 1px solid var(--outline-variant);
    }

    .table-header-scroll > div {
        height: 1px;
    }
}

/* Tablet optimizations */
@media (max-width: 1200px) {
    .offers-table {
        min-width: 900px;
    }
    
    .offers-table th,
    .offers-table td {
        padding: calc(var(--spacing-md) * 0.8);
        font-size: calc(var(--font-size-sm) * 0.95);
    }
}

@media (max-width: 992px) {
    .offers-table {
        min-width: 800px;
    }
    
    .offers-table th:nth-child(7), /* Görüşme */
    .offers-table td:nth-child(7),
    .offers-table th:nth-child(8), /* Dosya */
    .offers-table td:nth-child(8) {
        display: none;
    }
}

@media (max-width: 768px) {
    .modern-crm-container {
        padding: var(--spacing-md);
    }

    .crm-header {
        padding: var(--spacing-lg);
    }

    .header-content {
        flex-direction: column;
        align-items: stretch;
        gap: var(--spacing-md);
    }

    .title-section {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }

    .stats-row {
        grid-template-columns: 1fr;
    }

    .table-container {
        overflow-x: visible;
    }

    /* Hide table headers on mobile */
    .offers-table thead {
        display: none;
    }
    
    /* Reset table styling for mobile */
    .offers-table {
        width: 100%;
        min-width: auto;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    /* Convert table rows to cards */
    .offers-table tbody {
        display: block;
    }
    
    .offers-table tbody tr {
        display: block;
        background: white;
        border: 1px solid var(--outline-variant);
        border-radius: 12px;
        margin-bottom: var(--spacing-md);
        padding: var(--spacing-md);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all var(--transition-fast);
    }
    
    .offers-table tbody tr:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        transform: translateY(-2px);
        background: white;
    }
    
    /* Style table cells as card content */
    .offers-table td {
        display: block;
        padding: var(--spacing-sm) 0;
        border-bottom: none;
        position: relative;
        font-size: var(--font-size-sm);
    }
    
    /* Add labels before each cell content */
    .offers-table td:before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        font-size: var(--font-size-xs);
        color: var(--on-surface-variant);
        margin-bottom: var(--spacing-xs);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Special styling for first cell (customer) */
    .offers-table td:first-child {
        border-bottom: 1px solid var(--outline-variant);
        padding-bottom: var(--spacing-md);
        margin-bottom: var(--spacing-sm);
    }
    
    .offers-table td:first-child:before {
        display: none;
    }
    
    /* Last cell (actions) styling */
    .offers-table td:last-child {
        padding-top: var(--spacing-md);
        border-top: 1px solid var(--outline-variant);
        margin-top: var(--spacing-sm);
    }
    
    .action-buttons-group {
        flex-direction: row;
        flex-wrap: wrap;
        gap: var(--spacing-xs);
        justify-content: flex-start;
    }

    .action-buttons-group .btn {
        flex: 0 0 auto;
        padding: 4px 8px;
        font-size: 0.7rem;
    }
    
    /* Status-based row styling for mobile cards */
    .offers-table tr.row-inactive {
        border-left: 4px solid #757575;
        opacity: 0.7;
    }
    
    .offers-table tr.row-expired {
        border-left: 4px solid #d32f2f;
    }
    
    .offers-table tr.row-expiring {
        border-left: 4px solid #ff9800;
    }

    .pagination-container {
        flex-direction: column;
        gap: var(--spacing-md);
    }
}

@media (max-width: 480px) {
    .offers-table th:nth-child(n+3),
    .offers-table td:nth-child(n+3) {
        display: none;
    }

    .stat-card {
        flex-direction: column;
        text-align: center;
    }

    .action-buttons-group .btn {
        padding: var(--spacing-xs);
        font-size: 0.65rem;
    }
}

/* Loading States */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    border: 2px solid var(--outline-variant);
    border-top: 2px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: var(--spacing-2xl);
    color: var(--on-surface-variant);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: var(--spacing-lg);
    opacity: 0.5;
}

.empty-state h3 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-lg);
    color: var(--on-surface);
}

.empty-state p {
    margin: 0;
    font-size: var(--font-size-base);
}
</style>

<?php
// Global değişkenler
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$teams_table = $wpdb->prefix . 'insurance_crm_teams';
$users_table = $wpdb->users;
$notes_table = $wpdb->prefix . 'insurance_crm_customer_notes';
$files_table = $wpdb->prefix . 'insurance_crm_customer_files';

/**
 * Temsilci ID'sini al
 */
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}

$current_user_rep_id = get_current_user_rep_id();
$current_user_id = get_current_user_id();

// Yönetici yetkisini kontrol et (WordPress admin veya insurance_manager)
$is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');

// Scope (Kapsam) seçimi - Kişisel veya Ekip
$scope = isset($_GET['scope']) ? sanitize_text_field($_GET['scope']) : 'personal';

// Temsilcinin takımındaki temsilciler için yetki kontrolü
$user_rep_ids = array($current_user_rep_id); // Varsayılan olarak sadece kendisi

// Ekip lideri ise ve ekip görünümü seçildiyse ekibindeki temsilcileri de ekle
if ($scope === 'team' && function_exists('is_team_leader') && is_team_leader($current_user_id)) {
    $team_members = get_team_members_ids($current_user_id);
    if (!empty($team_members)) {
        $user_rep_ids = array_merge($user_rep_ids, $team_members);
    }
}

// Müdür Yardımcısı, Müdür veya Patron ise tüm temsilcileri görebilir
$can_view_all_representatives = (function_exists('has_full_admin_access') && has_full_admin_access($current_user_id)) || (function_exists('is_assistant_manager') && is_assistant_manager($current_user_id)) || (function_exists('is_manager') && is_manager($current_user_id)) || (function_exists('is_boss') && is_boss($current_user_id)) || $is_wp_admin_or_manager;

// Filtreler
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$representative_filter = isset($_GET['representative_id']) && is_numeric($_GET['representative_id']) ? intval($_GET['representative_id']) : '';
$show_expired = isset($_GET['show_expired']) && $_GET['show_expired'] === '1' ? true : false;
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// WHERE koşullarını oluştur
$where_clauses = array();
$query_args = array();

// Sadece teklifi olan müşterileri göster
$where_clauses[] = "c.has_offer = 1";

// Süresi dolmuş teklifleri gizle (varsayılan olarak)
if (!$show_expired) {
    $where_clauses[] = "c.offer_expiry_date >= CURDATE()";
}

// Durum filtresi
if (!empty($status_filter)) {
    $where_clauses[] = "c.status = %s";
    $query_args[] = $status_filter;
}

// Temsilci filtresi
if (!empty($representative_filter)) {
    $where_clauses[] = "c.representative_id = %d";
    $query_args[] = $representative_filter;
} elseif (!$can_view_all_representatives) {
    // Yetkisi yoksa sadece kendi veya takımındaki müşterileri görebilir
    if (count($user_rep_ids) > 1) {
        $placeholders = implode(',', array_fill(0, count($user_rep_ids), '%d'));
        $where_clauses[] = "c.representative_id IN ($placeholders)";
        $query_args = array_merge($query_args, $user_rep_ids);
    } else {
        $where_clauses[] = "c.representative_id = %d";
        $query_args[] = $current_user_rep_id;
    }
}

// Arama filtresi
if (!empty($search)) {
    $where_clauses[] = "(c.first_name LIKE %s OR c.last_name LIKE %s OR c.tc_identity LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.offer_insurance_type LIKE %s)";
    $search_term = '%' . $wpdb->esc_like($search) . '%';
    $query_args[] = $search_term;
    $query_args[] = $search_term;
    $query_args[] = $search_term;
    $query_args[] = $search_term;
    $query_args[] = $search_term;
    $query_args[] = $search_term;
}

// Aktif filtre sayısını hesapla
$active_filter_count = 0;
if (!empty($status_filter)) $active_filter_count++;
if (!empty($representative_filter)) $active_filter_count++;
if (!empty($search)) $active_filter_count++;
if ($show_expired) $active_filter_count++;

// WHERE koşulunu birleştir
$where = '';
if (!empty($where_clauses)) {
    $where = "WHERE " . implode(" AND ", $where_clauses);
}

// Toplam kayıt sayısını hesapla
$total_items_query = "SELECT COUNT(*) FROM $customers_table c $where";
if (!empty($query_args)) {
    $total_items_query = $wpdb->prepare($total_items_query, $query_args);
}
$total_items = $wpdb->get_var($total_items_query);

// İstatistikleri hesapla - Yetkilere göre filtreli
$stats_where_clauses = array("has_offer = 1");
$stats_query_args = array();

// Temsilci filtresini istatistiklere de uygula
if (!empty($representative_filter)) {
    $stats_where_clauses[] = "representative_id = %d";
    $stats_query_args[] = $representative_filter;
} elseif (!$can_view_all_representatives) {
    if (count($user_rep_ids) > 1) {
        $placeholders = implode(',', array_fill(0, count($user_rep_ids), '%d'));
        $stats_where_clauses[] = "representative_id IN ($placeholders)";
        $stats_query_args = array_merge($stats_query_args, $user_rep_ids);
    } else {
        $stats_where_clauses[] = "representative_id = %d";
        $stats_query_args[] = $current_user_rep_id;
    }
}

// Durum filtresini istatistiklere de uygula
if (!empty($status_filter)) {
    $stats_status_where = " AND status = %s";
    $stats_status_arg = $status_filter;
} else {
    $stats_status_where = "";
    $stats_status_arg = null;
}

$stats_where = implode(" AND ", $stats_where_clauses);

// Aktif teklifler - sadece süresi dolmamış olanlar
$active_offers_query = "SELECT COUNT(*) FROM $customers_table WHERE $stats_where AND status = 'aktif' AND offer_expiry_date >= CURDATE()";
if (!empty($stats_query_args)) {
    $active_offers_query_args = array_merge($stats_query_args, array('aktif'));
    $active_offers = $wpdb->get_var($wpdb->prepare($active_offers_query, $stats_query_args));
} else {
    $active_offers = $wpdb->get_var("SELECT COUNT(*) FROM $customers_table WHERE has_offer = 1 AND status = 'aktif' AND offer_expiry_date >= CURDATE()");
}

// Yakında sona erecek teklifler
$expires_soon_query = "SELECT COUNT(*) FROM $customers_table 
                      WHERE $stats_where 
                      AND offer_expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
                      AND offer_expiry_date >= CURDATE()";
if (!empty($stats_status_where)) {
    $expires_soon_query .= $stats_status_where;
}
if (!empty($stats_query_args)) {
    $expires_soon_args = $stats_query_args;
    if ($stats_status_arg) {
        $expires_soon_args[] = $stats_status_arg;
    }
    $expires_soon = $wpdb->get_var($wpdb->prepare($expires_soon_query, $expires_soon_args));
} else {
    $expires_soon = $wpdb->get_var($expires_soon_query);
}

// Süresi dolmuş teklifler
$expired_query = "SELECT COUNT(*) FROM $customers_table 
                 WHERE $stats_where 
                 AND offer_expiry_date < CURDATE()";
if (!empty($stats_status_where)) {
    $expired_query .= $stats_status_where;
}
if (!empty($stats_query_args)) {
    $expired_args = $stats_query_args;
    if ($stats_status_arg) {
        $expired_args[] = $stats_status_arg;
    }
    $expired = $wpdb->get_var($wpdb->prepare($expired_query, $expired_args));
} else {
    $expired = $wpdb->get_var($expired_query);
}

// Toplam prim
$total_premium_query = "SELECT SUM(offer_amount) FROM $customers_table 
                       WHERE $stats_where 
                       AND offer_amount > 0";
if (!empty($stats_status_where)) {
    $total_premium_query .= $stats_status_where;
}
if (!empty($stats_query_args)) {
    $premium_args = $stats_query_args;
    if ($stats_status_arg) {
        $premium_args[] = $stats_status_arg;
    }
    $total_premium = $wpdb->get_var($wpdb->prepare($total_premium_query, $premium_args));
} else {
    $total_premium = $wpdb->get_var($total_premium_query);
}

// Sıralama parametreleri
$order_by = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'offer_expiry_date';
$order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

// Güvenli sıralama sütunları
$allowed_order_columns = array(
    'id', 'first_name', 'last_name', 'email', 'phone', 'offer_insurance_type', 
    'offer_amount', 'offer_expiry_date', 'representative_id', 'created_at'
);

if (!in_array($order_by, $allowed_order_columns)) {
    $order_by = 'offer_expiry_date';
}

// Müşterileri sorgula
$query = "
    SELECT c.*, u.display_name as representative_name
    FROM $customers_table c
    LEFT JOIN $representatives_table r ON c.representative_id = r.id
    LEFT JOIN $users_table u ON r.user_id = u.ID
    $where
    ORDER BY c.$order_by $order
    LIMIT %d OFFSET %d
";

$query_args[] = $per_page;
$query_args[] = $offset;

$customers = $wpdb->get_results($wpdb->prepare($query, $query_args));

// Temsilcileri al (filtre için)
$representatives = array();
if ($can_view_all_representatives) {
    $representatives = $wpdb->get_results(
        "SELECT r.id, u.display_name
         FROM $representatives_table r
         JOIN $users_table u ON r.user_id = u.ID
         WHERE r.status = 'active'
         ORDER BY u.display_name ASC"
    );
} elseif (function_exists('is_team_leader') && is_team_leader($current_user_id)) {
    // Ekip liderleri kendi ekibindeki temsilcileri görebilir
    $placeholders = implode(',', array_fill(0, count($user_rep_ids), '%d'));
    $representatives = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, u.display_name
         FROM $representatives_table r
         JOIN $users_table u ON r.user_id = u.ID
         WHERE r.id IN ($placeholders) AND r.status = 'active'
         ORDER BY u.display_name ASC",
        ...$user_rep_ids
    ));
}

// Sayfalama için toplam sayfa sayısı
$total_pages = ceil($total_items / $per_page);

// Sayfalama bağlantıları
$page_links = paginate_links(array(
    'base' => add_query_arg('paged', '%#%'),
    'format' => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total' => $total_pages,
    'current' => $current_page
));

// Tablo kolon başlıklarını oluşturan fonksiyon
function get_sortable_column_header($column_name, $display_text) {
    $current_order = isset($_GET['order']) ? strtolower($_GET['order']) : 'asc';
    $current_orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'offer_expiry_date';
    
    $new_order = ($current_orderby === $column_name && $current_order === 'asc') ? 'desc' : 'asc';
    $sort_indicator = '';
    
    if ($current_orderby === $column_name) {
        $sort_indicator = $current_order === 'asc' ? ' <i class="fas fa-chevron-up"></i>' : ' <i class="fas fa-chevron-down"></i>';
    }
    
    $query_args = array_merge(
        $_GET,
        ['orderby' => $column_name, 'order' => $new_order]
    );
    
    $url = add_query_arg($query_args, get_permalink());
    
    return '<a href="' . esc_url($url) . '" class="sortable-column">' . $display_text . $sort_indicator . '</a>';
}

// Teklif bitişine kalan günleri hesapla
function get_days_remaining($expiry_date) {
    if (empty($expiry_date)) {
        return -1; // Tarih yoksa
    }
    
    $now = new DateTime();
    $expiry = new DateTime($expiry_date);
    $interval = $now->diff($expiry);
    
    if ($interval->invert) {
        return -1 * $interval->days; // Geçmiş tarih ise negatif değer
    }
    
    return $interval->days;
}

// Teklif durumunu görüntüle
function get_offer_status_badge($expiry_date) {
    $days = get_days_remaining($expiry_date);
    
    if ($days < 0) {
        return '<span class="badge badge-danger">Süresi Dolmuş</span>';
    } elseif ($days <= 7) {
        return '<span class="badge badge-warning">' . $days . ' Gün Kaldı</span>';
    } else {
        return '<span class="badge badge-success">' . $days . ' Gün Kaldı</span>';
    }
}

// Görüşme notlarını say
function get_note_count($customer_id) {
    global $wpdb, $notes_table;
    
    return $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $notes_table WHERE customer_id = %d",
        $customer_id
    ));
}

// Müşteri dosyalarını say
function get_file_count($customer_id) {
    global $wpdb, $files_table;
    
    return $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $files_table WHERE customer_id = %d",
        $customer_id
    ));
}

// Panel URL'i oluştur
function get_panel_url($view, $action = '', $id = 0) {
    $url = add_query_arg('view', $view, get_permalink());
    
    if (!empty($action)) {
        $url = add_query_arg('action', $action, $url);
    }
    
    if ($id > 0) {
        $url = add_query_arg('id', $id, $url);
    }
    
    return $url;
}

// Kullanıcının takım lideri veya üstü olup olmadığını kontrol et
$show_scope_toggle = false;
if (function_exists('is_team_leader') && is_team_leader($current_user_id)) {
    $show_scope_toggle = true;
} elseif (function_exists('is_assistant_manager') && is_assistant_manager($current_user_id)) {
    $show_scope_toggle = true;
} elseif (function_exists('is_manager') && is_manager($current_user_id)) {
    $show_scope_toggle = true;
} elseif (function_exists('is_boss') && is_boss($current_user_id)) {
    $show_scope_toggle = true;
}

?>

<!-- Modern CRM Container -->
<div class="modern-crm-container" id="offers-container">
    
    <!-- Header Section -->
    <header class="crm-header">
        <div class="header-content">
            <div class="title-section">
                <div class="page-title">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <h1>Tekliflerim</h1>
                    <span class="version-badge">v2.1.0</span>
                </div>
                <div class="user-badge">
                    <span class="role-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php
                        if (function_exists('get_user_role_level') && function_exists('get_role_name')) {
                            $role_level = get_user_role_level();
                            $role_name = get_role_name($role_level);
                            echo esc_html($role_name);
                        } else {
                            echo 'Kullanıcı';
                        }
                        ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if ($show_scope_toggle): ?>
                <form method="get" class="scope-toggle">
                    <input type="hidden" name="view" value="offers">
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if ($key !== 'scope' && $key !== 'view'): ?>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <input type="radio" id="personal" name="scope" value="personal" <?php checked($scope, 'personal'); ?> onchange="this.form.submit()">
                    <label for="personal">
                        <i class="fas fa-user"></i>
                        <span>Kişisel</span>
                    </label>
                    
                    <input type="radio" id="team" name="scope" value="team" <?php checked($scope, 'team'); ?> onchange="this.form.submit()">
                    <label for="team">
                        <i class="fas fa-users"></i>
                        <span>Ekip</span>
                    </label>
                </form>
                <?php endif; ?>
                
                <button type="button" id="filterToggle" class="btn btn-outline filter-toggle">
                    <i class="fas fa-filter"></i>
                    <span>Filtrele</span>
                    <i class="fas fa-chevron-down chevron"></i>
                    <?php 
                    $active_filters = array_filter([$status_filter, $search, $representative_filter]);
                    if ($show_expired) $active_filters[] = 'show_expired';
                    if (!empty($active_filters)): 
                    ?>
                        <span class="filter-count"><?php echo count($active_filters); ?></span>
                    <?php endif; ?>
                </button>
                
                <a href="<?php echo get_panel_url('customers', 'new'); ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Yeni Müşteri</span>
                </a>
            </div>
        </div>
    </header>
    
    <!-- Modern Filters Section -->
    <section class="filters-section" id="filtersSection">
        <div class="filters-container">
            <form id="offers-filter" method="get" class="filters-form">
                <input type="hidden" name="view" value="offers">
                <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
                <?php if ($show_expired): ?>
                <input type="hidden" name="show_expired" value="1">
                <?php endif; ?>
                
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Durum</label>
                        <select name="status" id="status" class="form-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                        </select>
                    </div>
                    
                    <?php if ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))): ?>
                    <div class="filter-group">
                        <label for="representative_id">Temsilci</label>
                        <select name="representative_id" id="representative_id" class="form-select" data-live-search="true">
                            <option value="">Tüm Temsilciler</option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo $rep->id; ?>" <?php selected($representative_filter, $rep->id); ?>><?php echo esc_html($rep->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="orderby">Sırala</label>
                        <select name="orderby" id="orderby" class="form-select">
                            <option value="offer_expiry_date" <?php selected($order_by, 'offer_expiry_date'); ?>>Teklif Vadesi</option>
                            <option value="offer_amount" <?php selected($order_by, 'offer_amount'); ?>>Teklif Tutarı</option>
                            <option value="created_at" <?php selected($order_by, 'created_at'); ?>>Oluşturulma Tarihi</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="order">Yön</label>
                        <select name="order" id="order" class="form-select">
                            <option value="asc" <?php selected($order, 'ASC'); ?>>Artan</option>
                            <option value="desc" <?php selected($order, 'DESC'); ?>>Azalan</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="s">Arama</label>
                        <div class="search-input-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" name="s" id="s" value="<?php echo esc_attr($search); ?>" placeholder="Ad, Soyad, TC, E-posta, Teklif Türü..." class="form-input">
                        </div>
                    </div>
                </div>
                
                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span>Ara</span>
                    </button>
                    <a href="<?php echo remove_query_arg(['status', 's', 'representative_id', 'orderby', 'order', 'paged', 'show_expired']); ?>" class="btn btn-ghost">
                        <i class="fas fa-times"></i>
                        <span>Temizle</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <!-- Statistics Dashboard -->
    <section class="dashboard-section" id="dashboardSection" <?php echo $active_filter_count > 0 ? 'style="display:none;"' : ''; ?>>
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Teklif</h3>
                    <div class="stat-value"><?php echo number_format($total_items); ?></div>
                    <div class="stat-subtitle">
                        <?php 
                        if ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))) echo 'Ekip Toplamı';
                        else echo 'Kişisel Toplam';
                        ?>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Aktif Teklifler</h3>
                    <div class="stat-value"><?php echo number_format($active_offers); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $total_items > 0 ? number_format(($active_offers / $total_items) * 100, 1) : 0; ?>% Toplam
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-content">
                    <h3>Olası Prim</h3>
                    <div class="stat-value">₺<?php echo number_format($total_premium ?: 0, 0, ',', '.'); ?></div>
                    <div class="stat-subtitle">
                        Ort: ₺<?php echo $active_offers > 0 ? number_format(($total_premium ?: 0) / $active_offers, 0, ',', '.') : 0; ?>
                    </div>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <h3>Yakında Sona Erecek</h3>
                    <div class="stat-value"><?php echo number_format($expires_soon); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $total_items > 0 ? number_format(($expires_soon / $total_items) * 100, 1) : 0; ?>% Toplam
                    </div>
                </div>
            </div>
            
            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-hourglass-end"></i>
                </div>
                <div class="stat-content">
                    <h3>Süresi Dolmuş</h3>
                    <div class="stat-value"><?php echo number_format($expired); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $total_items > 0 ? number_format(($expired / $total_items) * 100, 1) : 0; ?>% Toplam
                    </div>
                </div>
            </div>
        </div>
    </section>
            
    <!-- Offers Table -->
    <section class="table-section">
        <?php if (!empty($customers)): ?>
        <div class="table-wrapper">
            <div class="table-header">
                <div class="table-info">
                    <div class="table-meta">
                        <span>Toplam: <strong><?php echo number_format($total_items); ?></strong> teklif</span>
                        <?php if ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))): ?>
                        <span class="view-badge team">
                            <i class="fas fa-users"></i>
                            Ekip Görünümü
                        </span>
                        <?php else: ?>
                        <span class="view-badge personal">
                            <i class="fas fa-user"></i>
                            Kişisel Görünüm
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- PER PAGE SELECTOR & EXPIRED TOGGLE -->
                    <div class="table-controls">
                        <?php if (!$show_expired): ?>
                        <a href="<?php echo add_query_arg('show_expired', '1'); ?>" class="btn btn-ghost btn-sm">
                            <i class="fas fa-hourglass-end"></i>
                            <span>Süresi Dolanları Getir</span>
                        </a>
                        <?php else: ?>
                        <a href="<?php echo remove_query_arg('show_expired'); ?>" class="btn btn-ghost btn-sm">
                            <i class="fas fa-eye-slash"></i>
                            <span>Süresi Dolanları Gizle</span>
                        </a>
                        <?php endif; ?>
                        
                        <div class="per-page-selector">
                            <label for="per_page">Sayfa başına:</label>
                            <select id="per_page" name="per_page" class="form-select" onchange="updatePerPage(this.value)">
                                <option value="10" <?php selected($per_page, 10); ?>>10</option>
                                <option value="20" <?php selected($per_page, 20); ?>>20</option>
                                <option value="50" <?php selected($per_page, 50); ?>>50</option>
                                <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-header-scroll">
                <div style="width: 1200px;"></div>
            </div>
            <div class="table-container">
                <table class="offers-table">
                    <thead>
                        <tr>
                            <th><?php echo get_sortable_column_header('first_name', 'Müşteri'); ?></th>
                            <th><?php echo get_sortable_column_header('offer_insurance_type', 'Teklif Türü'); ?></th>
                            <th><?php echo get_sortable_column_header('offer_amount', 'Tutar'); ?></th>
                            <th>Vade</th>
                            <th>Durum</th>
                            <?php if ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))): ?>
                            <th><?php echo get_sortable_column_header('representative_id', 'Temsilci'); ?></th>
                            <?php endif; ?>
                            <th>Görüşme</th>
                            <th>Dosya</th>
                            <th class="actions-column">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($customers)): ?>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $days_remaining = get_days_remaining($customer->offer_expiry_date);
                                $row_class = '';
                                if ($customer->status === 'pasif') {
                                    $row_class = 'row-inactive';
                                } elseif ($days_remaining < 0) {
                                    $row_class = 'row-expired';
                                } elseif ($days_remaining <= 7) {
                                    $row_class = 'row-expiring';
                                }
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td class="customer-info" data-label="Müşteri">
                                        <div class="customer-name">
                                            <a href="<?php echo get_panel_url('customers', 'view', $customer->id); ?>">
                                                <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                                            </a>
                                        </div>
                                        <div class="customer-details">
                                            <?php if (!empty($customer->phone)): ?>
                                                <span><i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Teklif Türü"><?php echo !empty($customer->offer_insurance_type) ? esc_html($customer->offer_insurance_type) : '<span class="no-data">Belirtilmemiş</span>'; ?></td>
                                    <td class="amount-cell" data-label="Tutar">
                                        <?php if (!empty($customer->offer_amount)): ?>
                                            <span class="currency-symbol">₺</span><?php echo number_format($customer->offer_amount, 2, ',', '.'); ?>
                                        <?php else: ?>
                                            <span class="no-data">Belirtilmemiş</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Vade">
                                        <?php if (!empty($customer->offer_expiry_date)): ?>
                                            <?php echo date('d.m.Y', strtotime($customer->offer_expiry_date)); ?>
                                        <?php else: ?>
                                            <span class="no-data">Belirtilmemiş</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Durum">
                                        <?php echo !empty($customer->offer_expiry_date) ? get_offer_status_badge($customer->offer_expiry_date) : '<span class="badge badge-secondary">Vade Yok</span>'; ?>
                                    </td>
                                    <?php if ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))): ?>
                                    <td data-label="Temsilci"><?php echo esc_html($customer->representative_name); ?></td>
                                    <?php endif; ?>
                                    <td data-label="Görüşme">
                                        <?php 
                                        $note_count = get_note_count($customer->id);
                                        if ($note_count > 0) {
                                            echo '<span class="badge badge-info">' . $note_count . '</span>';
                                        } else {
                                            echo '<span class="badge badge-light">0</span>';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Dosya">
                                        <?php 
                                        $file_count = get_file_count($customer->id);
                                        if ($file_count > 0) {
                                            echo '<span class="badge badge-info">' . $file_count . '</span>';
                                        } else {
                                            echo '<span class="badge badge-light">0</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="actions" data-label="İşlemler">
                                        <div class="action-buttons-group">
                                            <a href="?view=offer-view&id=<?php echo $customer->id; ?>" class="btn btn-sm btn-info" title="Teklif Detayı">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?view=policies&action=new&customer_search=<?php echo urlencode($customer->first_name . ' ' . $customer->last_name); ?>&offer_type=<?php echo urlencode($customer->offer_insurance_type); ?>&offer_amount=<?php echo urlencode($customer->offer_amount); ?>" class="btn btn-sm btn-success" title="Poliçeye Çevir">
                                                <i class="fas fa-exchange-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo ($can_view_all_representatives || (function_exists('is_team_leader') && is_team_leader($current_user_id))) ? '9' : '8'; ?>" class="no-records">
                                    <div class="empty-state">
                                        <i class="fas fa-file-invoice-dollar fa-3x"></i>
                                        <p>Teklif kayıtları bulunamadı</p>
                                        <a href="<?php echo get_panel_url('customers', 'new'); ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-plus"></i> Yeni Müşteri Ekle
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    <span>Gösterilen: <?php echo (($current_page - 1) * $per_page + 1); ?>-<?php echo min($current_page * $per_page, $total_items); ?> / <?php echo number_format($total_items); ?></span>
                </div>
                <div class="pagination">
                    <?php echo $page_links; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="no-data-container">
            <div class="no-data-content">
                <i class="fas fa-inbox no-data-icon"></i>
                <h3>Teklif Bulunamadı</h3>
                <p>Mevcut filtrelerinizle eşleşen teklif bulunmamaktadır.</p>
                <a href="<?php echo get_panel_url('customers', 'form'); ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Yeni Müşteri Ekle
                </a>
            </div>
        </div>
        <?php endif; ?>
    </section>

<script>
/**
 * Modern Offers Management App - Enhanced Version v2.2.0
 * @description Complete offers management system with modern UI/UX
 * @version 2.2.0 - Updated to match policies.php structure with filters hidden by default
 * @date 2025-05-30
 */
class ModernOffersApp {
    constructor() {
        this.version = '2.2.0';
        this.isInitialized = false;
        this.activeFilterCount = 0;
        this.currentPage = 1;
        this.perPage = 20;
        this.statisticsData = {};
        
        // Initialize app components
        this.init();
    }

    async init() {
        try {
            console.log('🚀 Starting Modern Offers App v' + this.version);
            
            // Initialize core components
            this.initializeFilters();
            this.initializeTableFeatures();
            this.initializeFormEnhancements();
            this.initializeKeyboardShortcuts();
            this.initializeNotifications();
            
            // Register event listeners
            this.registerEventListeners();
            
            // Calculate statistics
            this.calculateStatistics();
            
            // Mark as initialized
            this.isInitialized = true;
            this.logInitialization();
            
            // Check for notification banners
            this.handleNotificationBanners();
            
        } catch (error) {
            console.error('❌ Initialization failed:', error);
            this.showNotification('Uygulama başlatılamadı. Sayfayı yenileyin.', 'error');
        }
    }

    initializeFilters() {
        // Filter toggle functionality
        const filterToggle = document.getElementById('filterToggle');
        const filtersSection = document.getElementById('filtersSection');
        
        if (filterToggle && filtersSection) {
            filterToggle.addEventListener('click', (e) => {
                e.preventDefault();
                filtersSection.classList.toggle('show');
                filterToggle.classList.toggle('active');
                
                const chevron = filterToggle.querySelector('.chevron');
                if (chevron) {
                    chevron.style.transform = filtersSection.classList.contains('show') 
                        ? 'rotate(180deg)' 
                        : 'rotate(0deg)';
                }
            });
        }

        // Enhanced select boxes
        this.enhanceSelectBoxes();
        
        // Auto-submit on filter change
        this.setupAutoSubmit();
        
        // Count active filters
        this.countActiveFilters();
    }

    initializeTableFeatures() {
        this.addTableRowHoverEffects();
        this.addTableSorting();
        this.addTableQuickActions();
        this.setupScrollSynchronization();
    }

    initializeFormEnhancements() {
        this.enhanceFormInputs();
        this.addFormValidation();
        this.setupSearchFeatures();
    }

    initializeKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }

            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        this.toggleFiltersShortcut();
                        break;
                    case 'r':
                        e.preventDefault();
                        this.refreshPage();
                        break;
                    case 'n':
                        e.preventDefault();
                        window.location.href = '?view=customers&action=new';
                        break;
                }
            }
        });
    }

    initializeNotifications() {
        // Auto-hide notifications after 5 seconds
        const notifications = document.querySelectorAll('.notification-banner');
        notifications.forEach(notification => {
            const closeBtn = notification.querySelector('.notification-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    this.hideNotification(notification);
                });
            }
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                this.hideNotification(notification);
            }, 5000);
        });
    }

    registerEventListeners() {
        // Form submit loading effect
        const offersFilter = document.getElementById('offers-filter');
        if (offersFilter) {
            offersFilter.addEventListener('submit', () => {
                this.showLoadingState();
            });
        }
        
        // Row click functionality
        this.addRowClickHandlers();
        
        // Action button enhancements
        this.enhanceActionButtons();
    }

    addTableRowHoverEffects() {
        const tableRows = document.querySelectorAll('.offers-table tbody tr');
        
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.transform = 'scale(1.002)';
                row.style.zIndex = '1';
                row.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', () => {
                row.style.transform = 'scale(1)';
                row.style.zIndex = 'auto';
                row.style.boxShadow = 'none';
            });
        });
    }

    addTableSorting() {
        const sortableHeaders = document.querySelectorAll('.offers-table th a');
        
        sortableHeaders.forEach(header => {
            header.addEventListener('click', (e) => {
                const table = header.closest('table');
                if (table) {
                    table.classList.add('loading');
                    setTimeout(() => {
                        table.classList.remove('loading');
                    }, 1000);
                }
            });
        });
    }

    addTableQuickActions() {
        // Implement quick actions for table rows
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'k':
                        e.preventDefault();
                        this.focusTableSearch();
                        break;
                }
            }
        });
    }

    setupScrollSynchronization() {
        // Implement dual scrollbar synchronization
        const tableContainer = document.querySelector('.table-container');
        const headerScroll = document.querySelector('.table-header-scroll');
        
        if (tableContainer && headerScroll) {
            // Set table width for header scrollbar content
            const tableWidth = document.querySelector('.offers-table').scrollWidth;
            headerScroll.querySelector('div').style.width = tableWidth + 'px';
            
            // Scroll synchronization
            tableContainer.addEventListener('scroll', function() {
                headerScroll.scrollLeft = tableContainer.scrollLeft;
            });
            
            headerScroll.addEventListener('scroll', function() {
                tableContainer.scrollLeft = headerScroll.scrollLeft;
            });
        }
    }

    enhanceSelectBoxes() {
        const selects = document.querySelectorAll('.form-select');
        selects.forEach(select => {
            if (select.options.length > 10) {
                select.setAttribute('data-live-search', 'true');
                select.setAttribute('data-size', '8');
            }
        });
    }

    setupAutoSubmit() {
        // Auto-submit form on filter changes (with debouncing)
        const filterInputs = document.querySelectorAll('#offers-filter input, #offers-filter select');
        let submitTimeout;

        filterInputs.forEach(input => {
            // Search input için farklı davranış
            if (input.id === 's') {
                input.addEventListener('input', () => {
                    clearTimeout(submitTimeout);
                    submitTimeout = setTimeout(() => {
                        document.getElementById('offers-filter').submit();
                    }, 800);
                });
            } else {
                input.addEventListener('change', () => {
                    clearTimeout(submitTimeout);
                    submitTimeout = setTimeout(() => {
                        document.getElementById('offers-filter').submit();
                    }, 300);
                });
            }
        });
    }

    countActiveFilters() {
        const filterInputs = document.querySelectorAll('#offers-filter input:not([type="hidden"]), #offers-filter select');
        let count = 0;

        filterInputs.forEach(input => {
            if (input.value && input.value !== '' && input.value !== '0') {
                count++;
            }
        });

        this.activeFilterCount = count;
        this.updateFilterCount();
        return count;
    }

    updateFilterCount() {
        const filterCount = document.querySelector('.filter-count');
        if (filterCount) {
            if (this.activeFilterCount > 0) {
                filterCount.textContent = this.activeFilterCount;
                filterCount.style.display = 'block';
            } else {
                filterCount.style.display = 'none';
            }
        }
    }

    enhanceFormInputs() {
        const inputs = document.querySelectorAll('.form-input, .form-select');
        
        inputs.forEach(input => {
            this.addFloatingLabelEffect(input);
            this.addValidationStyling(input);
        });
    }

    addFloatingLabelEffect(input) {
        input.addEventListener('focus', () => {
            input.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', () => {
            if (!input.value) {
                input.parentElement.classList.remove('focused');
            }
        });
        
        if (input.value) {
            input.parentElement.classList.add('focused');
        }
    }

    addValidationStyling(input) {
        input.addEventListener('invalid', () => {
            input.classList.add('invalid');
        });
        
        input.addEventListener('input', () => {
            if (input.validity.valid) {
                input.classList.remove('invalid');
            }
        });
    }

    setupSearchFeatures() {
        const searchInput = document.getElementById('s');
        if (searchInput) {
            // Add search suggestions/autocomplete functionality
            searchInput.addEventListener('input', (e) => {
                // Implement search suggestions here if needed
            });
        }
    }

    addFormValidation() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    this.showNotification('Lütfen tüm gerekli alanları doldurun.', 'error');
                }
            });
        });
    }

    addRowClickHandlers() {
        const tableRows = document.querySelectorAll('.offers-table tbody tr');
        
        tableRows.forEach(row => {
            row.addEventListener('click', (e) => {
                if (!e.target.closest('a') && !e.target.closest('button')) {
                    const viewLink = row.querySelector('.customer-name a');
                    if (viewLink) {
                        viewLink.click();
                    }
                }
            });
        });
    }

    enhanceActionButtons() {
        const actionButtons = document.querySelectorAll('.action-buttons-group .btn');
        
        actionButtons.forEach(button => {
            // Add loading state on click
            button.addEventListener('click', () => {
                if (!button.classList.contains('loading')) {
                    button.classList.add('loading');
                    setTimeout(() => {
                        button.classList.remove('loading');
                    }, 2000);
                }
            });
        });
    }

    calculateStatistics() {
        // Calculate statistics from current data
        const rows = document.querySelectorAll('.offers-table tbody tr');
        let activeCount = 0;
        let inactiveCount = 0;
        let totalAmount = 0;

        rows.forEach(row => {
            if (row.classList.contains('row-inactive')) {
                inactiveCount++;
            } else {
                activeCount++;
            }
            
            // Extract amount if available
            const amountCell = row.querySelector('.amount-cell');
            if (amountCell && amountCell.textContent.includes('₺')) {
                const amount = parseFloat(amountCell.textContent.replace(/[^\d,]/g, '').replace(',', '.'));
                if (!isNaN(amount)) {
                    totalAmount += amount;
                }
            }
        });

        this.statisticsData = {
            active: activeCount,
            inactive: inactiveCount,
            total: activeCount + inactiveCount,
            totalAmount: totalAmount
        };

        this.updateStatisticsDisplay();
    }

    updateStatisticsDisplay() {
        // Update statistics cards if they exist
        const activeCard = document.querySelector('.stat-active .stat-number');
        const inactiveCard = document.querySelector('.stat-inactive .stat-number');
        const totalCard = document.querySelector('.stat-total .stat-number');

        if (activeCard) activeCard.textContent = this.statisticsData.active;
        if (inactiveCard) inactiveCard.textContent = this.statisticsData.inactive;
        if (totalCard) totalCard.textContent = this.statisticsData.total;
    }

    showLoadingState() {
        document.body.classList.add('loading');
        setTimeout(() => {
            document.body.classList.remove('loading');
        }, 2000);
    }

    hideNotification(notification) {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }

    showNotification(message, type = 'info') {
        // Create and show notification banner
        const notification = document.createElement('div');
        notification.className = `notification-banner notification-${type}`;
        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fas fa-${type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle')}"></i>
            </div>
            <div class="notification-content">${message}</div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        `;

        document.body.insertBefore(notification, document.body.firstChild);

        // Add close functionality
        notification.querySelector('.notification-close').addEventListener('click', () => {
            this.hideNotification(notification);
        });

        // Auto-hide after 5 seconds
        setTimeout(() => {
            this.hideNotification(notification);
        }, 5000);
    }

    handleNotificationBanners() {
        // Handle existing notification banners
        const notifications = document.querySelectorAll('.notification-banner');
        notifications.forEach(notification => {
            const closeBtn = notification.querySelector('.notification-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    this.hideNotification(notification);
                });
            }
        });
    }

    toggleFiltersShortcut() {
        const filterToggle = document.getElementById('filterToggle');
        if (filterToggle) {
            filterToggle.click();
        }
    }

    refreshPage() {
        window.location.reload();
    }

    focusTableSearch() {
        const searchInput = document.getElementById('s');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    logInitialization() {
        console.log('✅ Modern Offers App v' + this.version + ' initialized successfully');
        console.log('📊 Statistics:', this.statisticsData);
        console.log('🔍 Active Filters:', this.activeFilterCount);
        console.log('✅ All enhancements completed:');
        console.log('  ✓ Enhanced filtering system with hidden by default');
        console.log('  ✓ Modern table design matching policies.php');
        console.log('  ✓ Personal/Team scope toggle added');
        console.log('  ✓ Statistics cards matching policies.php style');
        console.log('  ✓ Keyboard shortcuts added');
        console.log('  ✓ Responsive design optimized');
        console.log('  ✓ Function existence checks added');
        console.log('  ✓ XSS security improvements');
        console.log('  ✓ Statistics fixed to respect user permissions');
        console.log('🎯 System is production-ready and enhanced');
    }
}

// PER PAGE SELECTION FUNCTION
function updatePerPage(newPerPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', newPerPage);
    url.searchParams.delete('paged'); // Reset to first page
    window.location.href = url.toString();
}

// Çift yönlü kaydırma çubuğu senkronizasyonu
document.addEventListener('DOMContentLoaded', function() {
    const tableContainer = document.querySelector('.table-container');
    const headerScroll = document.querySelector('.table-header-scroll');
    
    if (tableContainer && headerScroll) {
        // Tablo genişliğini header scrollbar içeriğine uygula
        const tableWidth = document.querySelector('.offers-table').scrollWidth;
        headerScroll.querySelector('div').style.width = tableWidth + 'px';
        
        // Scroll senkronizasyonu
        tableContainer.addEventListener('scroll', function() {
            headerScroll.scrollLeft = tableContainer.scrollLeft;
        });
        
        headerScroll.addEventListener('scroll', function() {
            tableContainer.scrollLeft = headerScroll.scrollLeft;
        });
    }
});

// Initialize App when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new ModernOffersApp();
    
    // Add animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        @keyframes slideInUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .notification-banner {
            animation: slideInUp 0.3s ease-out forwards;
        }
        .loading .btn::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            margin: auto;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        .badge-light {
            background: rgba(158, 158, 158, 0.1);
            color: #9e9e9e;
            border: 1px solid rgba(158, 158, 158, 0.2);
        }
    `;
    document.head.appendChild(style);
});
</script>