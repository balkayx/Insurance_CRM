/**
 * Sayfa Loader'ı JavaScript - Sayfa Yüklendikten Sonra Fade-Out
 * 
 * @author anadolubirlik
 * @version 1.0.0
 * @date 2025-06-02
 */

(function() {
    'use strict';
    
    /**
     * Loader'ı gizleyen ana fonksiyon
     */
    function hidePageLoader() {
        const loader = document.getElementById('page-loader');
        if (loader) {
            // Fade-out animasyonu başlat
            loader.classList.add('hidden');
            
            // Animasyon tamamlandıktan sonra DOM'dan kaldır
            setTimeout(function() {
                if (loader.parentNode) {
                    loader.parentNode.removeChild(loader);
                }
            }, 500); // CSS transition süresi ile eşleşir
        }
    }
    
    /**
     * Sayfa tamamen yüklendiğinde loader'ı gizle
     */
    function onPageLoad() {
        // Minimum gösterim süresi (kullanıcı deneyimi için)
        const minDisplayTime = 300; // ms
        
        setTimeout(hidePageLoader, minDisplayTime);
    }
    
    /**
     * DOM hazır olduğunda başlat
     */
    function onDOMReady() {
        // Eğer sayfa zaten yüklenmişse hemen gizle
        if (document.readyState === 'complete') {
            onPageLoad();
        } else {
            // Sayfa yüklenmeyi bekle
            window.addEventListener('load', onPageLoad);
        }
        
        // Ek güvenlik: Maksimum bekleme süresi
        setTimeout(function() {
            const loader = document.getElementById('page-loader');
            if (loader && !loader.classList.contains('hidden')) {
                console.warn('Loader maksimum süre aşıldığı için zorla gizlendi');
                hidePageLoader();
            }
        }, 10000); // 10 saniye maksimum
    }
    
    /**
     * Manuel loader gizleme fonksiyonu (dış kullanım için)
     */
    window.hidePageLoader = hidePageLoader;
    
    /**
     * Loader'ı gösterme fonksiyonu (sayfa geçişleri için)
     */
    function showPageLoader() {
        const existingLoader = document.getElementById('page-loader');
        if (existingLoader) {
            existingLoader.classList.remove('hidden');
            return;
        }
        
        // Yeni loader oluştur (AJAX sayfa geçişleri için)
        console.log('Yeni loader oluşturuluyor...');
    }
    
    window.showPageLoader = showPageLoader;
    
    /**
     * Sayfa geçişlerinde loader göster
     */
    function handlePageTransitions() {
        // Tüm navigation linklerini yakala
        const navLinks = document.querySelectorAll('a[href*="page="]');
        
        navLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                // Eğer aynı sayfadaysa veya hash link ise loader gösterme
                const href = this.getAttribute('href');
                if (href && href.indexOf('#') === -1) {
                    showPageLoader();
                }
            });
        });
        
        // Form submit'lerinde de loader göster
        const forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            form.addEventListener('submit', function(e) {
                // Sadece sayfa yenileyecek form'larda loader göster
                const method = this.getAttribute('method');
                const action = this.getAttribute('action');
                
                if (!action || action.indexOf('#') === -1) {
                    setTimeout(showPageLoader, 100);
                }
            });
        });
    }
    
    // Browser uyumluluğu kontrolü
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            onDOMReady();
            handlePageTransitions();
        });
    } else {
        onDOMReady();
        handlePageTransitions();
    }
    
    // Debug bilgileri (geliştirme modunda)
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        console.log('Insurance CRM Loader initialized');
        
        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Sayfa yükleme süresi:', loadTime + 'ms');
        });
    }
    
})();