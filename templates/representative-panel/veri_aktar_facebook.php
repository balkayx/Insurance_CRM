<?php
/**
 * Facebook Veri Aktarım Sayfası - CSV Upload Desteği ile
 * Bu sayfa Facebook CSV dosyalarını yükleyerek içe aktarma işlemini yapar
 * @version 2.0.0
 * @date 2025-06-15
 */

if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $wpdb;

$current_user_rep_id = get_current_user_rep_id();
$notice = '';
$import_started = false;
$upload_success = false;
$uploaded_file_path = '';

// Role-based functions
/**
 * Get current user's role from insurance_crm_representatives table
 */
function get_current_user_crm_role() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
}

/**
 * Check if current user can assign to other representatives
 */
function can_assign_to_others($role) {
    return in_array($role, [1, 2, 3, 4]); // Patron, Müdür, Müdür Yardımcısı, Ekip Lideri
}

/**
 * Get assignable representatives for current user
 */
function get_assignable_representatives($current_user_role, $current_user_id) {
    global $wpdb;
    
    if (!can_assign_to_others($current_user_role)) {
        return [];
    }
    
    // For Patron, Müdür, Müdür Yardımcısı - can assign to anyone
    if (in_array($current_user_role, [1, 2, 3])) {
        return $wpdb->get_results(
            "SELECT r.id, u.display_name, r.role 
             FROM {$wpdb->prefix}insurance_crm_representatives r
             JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.status = 'active'
             ORDER BY u.display_name"
        );
    }
    
    // For Ekip Lideri - can only assign to team members
    if ($current_user_role == 4) {
        $team_members = get_team_members($current_user_id);
        if (empty($team_members)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($team_members), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, u.display_name, r.role 
             FROM {$wpdb->prefix}insurance_crm_representatives r
             JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.id IN ($placeholders) AND r.status = 'active'
             ORDER BY u.display_name",
            ...$team_members
        ));
    }
    
    return [];
}

/**
 * Get role name by role number
 */
function get_role_name($role) {
    $roles = [
        1 => 'Patron',
        2 => 'Müdür', 
        3 => 'Müdür Yardımcısı',
        4 => 'Ekip Lideri',
        5 => 'Müşteri Temsilcisi'
    ];
    return $roles[$role] ?? 'Bilinmeyen';
}

// Get current user's role and assignable representatives
$current_user_role = get_current_user_crm_role();
$can_assign = can_assign_to_others($current_user_role);
$assignable_representatives = $can_assign ? get_assignable_representatives($current_user_role, get_current_user_id()) : [];

// Dosya yükleme işlemi
if (isset($_POST['upload_facebook_csv']) && wp_verify_nonce($_POST['facebook_upload_nonce'], 'facebook_upload_action')) {
    if (isset($_FILES['facebook_csv_file']) && $_FILES['facebook_csv_file']['error'] === UPLOAD_ERR_OK) {
        $uploaded_file = $_FILES['facebook_csv_file'];
        
        // Dosya kontrolü
        $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'csv') {
            $notice = '<div class="ab-notice ab-error">Sadece CSV dosyaları kabul edilir.</div>';
        } else if ($uploaded_file['size'] > 10 * 1024 * 1024) { // 10MB limit
            $notice = '<div class="ab-notice ab-error">Dosya boyutu 10MB\'den büyük olamaz.</div>';
        } else {
            // Geçici dizin oluştur
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/facebook_csv_temp/';
            
            if (!is_dir($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }
            
            // Güvenli dosya adı oluştur
            $safe_filename = 'facebook_import_' . wp_get_current_user()->ID . '_' . time() . '.csv';
            $uploaded_file_path = $temp_dir . $safe_filename;
            
            if (move_uploaded_file($uploaded_file['tmp_name'], $uploaded_file_path)) {
                $upload_success = true;
                $notice = '<div class="ab-notice ab-success">CSV dosyası başarıyla yüklendi. Şimdi içe aktarım işlemini başlatabilirsiniz.</div>';
                
                // Session'da dosya yolunu sakla
                $_SESSION['facebook_csv_path'] = $uploaded_file_path;
            } else {
                $notice = '<div class="ab-notice ab-error">Dosya yüklenirken bir hata oluştu.</div>';
            }
        }
    } else {
        $notice = '<div class="ab-notice ab-error">Lütfen bir CSV dosyası seçin.</div>';
    }
}

// CSV Preview and Assignment processing
if (isset($_POST['preview_facebook_csv']) && wp_verify_nonce($_POST['facebook_preview_nonce'], 'facebook_preview_action')) {
    $facebook_csv_path = isset($_SESSION['facebook_csv_path']) ? $_SESSION['facebook_csv_path'] : '';
    
    if (empty($facebook_csv_path) || !file_exists($facebook_csv_path)) {
        $notice = '<div class="ab-notice ab-error">Önce bir CSV dosyası yüklemelisiniz.</div>';
    } else {
        // Load CSV for preview
        $facebook_importer_path = dirname(__FILE__) . '/import/csv-importer_facebook.php';
        if (file_exists($facebook_importer_path)) {
            require_once($facebook_importer_path);
            
            try {
                $csv_data = read_facebook_csv($facebook_csv_path);
                $_SESSION['facebook_csv_data'] = $csv_data;
                $_SESSION['show_preview'] = true;
                $notice = '<div class="ab-notice ab-info">CSV dosyası yüklendi. Aşağıdaki verileri kontrol edip temsilci atamalarını yapabilirsiniz.</div>';
            } catch (Exception $e) {
                $notice = '<div class="ab-notice ab-error">CSV dosyası okunamadı: ' . esc_html($e->getMessage()) . '</div>';
            }
        }
    }
}

// İçe aktarma işlemi
if (isset($_POST['start_facebook_import']) && wp_verify_nonce($_POST['facebook_import_nonce'], 'facebook_import_action')) {
    $facebook_csv_path = isset($_SESSION['facebook_csv_path']) ? $_SESSION['facebook_csv_path'] : '';
    $csv_data = isset($_SESSION['facebook_csv_data']) ? $_SESSION['facebook_csv_data'] : null;
    
    if (empty($facebook_csv_path) || !file_exists($facebook_csv_path)) {
        $notice = '<div class="ab-notice ab-error">Önce bir CSV dosyası yüklemelisiniz.</div>';
    } else {
        // Check if we have assignments data
        $row_assignments = isset($_POST['row_assignment']) ? $_POST['row_assignment'] : [];
        $default_assignment = isset($_POST['default_assignment']) ? intval($_POST['default_assignment']) : $current_user_rep_id;
        
        // Facebook CSV importer dosyasını çağır
        $facebook_importer_path = dirname(__FILE__) . '/import/csv-importer_facebook.php';
        if (file_exists($facebook_importer_path)) {
            require_once($facebook_importer_path);
            
            try {
                // Enhanced processing with assignments
                $result = process_facebook_csv_with_assignments($facebook_csv_path, $csv_data, $row_assignments, $default_assignment, $wpdb);
                
                if ($result['success']) {
                    $notice = '<div class="ab-notice ab-success">' . 
                             '<strong>Facebook verisi başarıyla aktarıldı!</strong><br>' .
                             'Müşteri sayısı: ' . $result['customers_created'] . '<br>' .
                             'Görev sayısı: ' . $result['tasks_created'] . '<br>' .
                             'Not sayısı: ' . $result['notes_created'] . 
                             '</div>';
                    $import_started = true;
                    
                    // Geçici dosyayı temizle
                    if (file_exists($facebook_csv_path)) {
                        unlink($facebook_csv_path);
                    }
                    unset($_SESSION['facebook_csv_path']);
                    unset($_SESSION['facebook_csv_data']);
                    unset($_SESSION['show_preview']);
                } else {
                    $notice = '<div class="ab-notice ab-error">İçe aktarma hatası: ' . esc_html($result['message']) . '</div>';
                }
            } catch (Exception $e) {
                $notice = '<div class="ab-notice ab-error">İçe aktarma hatası: ' . esc_html($e->getMessage()) . '</div>';
                error_log('Facebook CSV import error: ' . $e->getMessage());
            }
        } else {
            $notice = '<div class="ab-notice ab-error">Facebook CSV importer dosyası bulunamadı.</div>';
        }
    }
}

// Session'dan yüklenmiş dosya bilgisini al
$session_csv_path = isset($_SESSION['facebook_csv_path']) ? $_SESSION['facebook_csv_path'] : '';
$has_uploaded_file = !empty($session_csv_path) && file_exists($session_csv_path);
$show_preview = isset($_SESSION['show_preview']) && $_SESSION['show_preview'];
$csv_data = isset($_SESSION['facebook_csv_data']) ? $_SESSION['facebook_csv_data'] : null;

?>

?>

<div class="facebook-import-container">
    <div class="import-header">
        <h1><i class="dashicons dashicons-facebook-alt"></i> Facebook Veri Aktar</h1>
        <p>Facebook lead verilerini CSV dosyası yükleyerek sisteme aktarın</p>
    </div>

    <?php if ($notice): ?>
        <?php echo $notice; ?>
    <?php endif; ?>

    <?php if (!$import_started): ?>
        <div class="import-steps">
            <div class="step-indicator">
                <div class="step <?php echo (!$has_uploaded_file) ? 'active' : 'completed'; ?>">
                    <div class="step-number">1</div>
                    <div class="step-title">CSV Dosyası Yükle</div>
                </div>
                <div class="step-arrow">→</div>
                <div class="step <?php echo ($has_uploaded_file && !$show_preview) ? 'active' : ($show_preview ? 'completed' : ''); ?>">
                    <div class="step-number">2</div>
                    <div class="step-title">Önizleme & Atama</div>
                </div>
                <div class="step-arrow">→</div>
                <div class="step <?php echo $show_preview ? 'active' : ''; ?>">
                    <div class="step-number">3</div>
                    <div class="step-title">İçe Aktar</div>
                </div>
            </div>
        </div>

        <?php if (!$has_uploaded_file): ?>
            <!-- Dosya Yükleme Adımı -->
            <div class="upload-section">
                <div class="upload-info">
                    <h2><i class="dashicons dashicons-upload"></i> CSV Dosyası Yükle</h2>
                    <div class="info-content">
                        <p><strong>Desteklenen Formatlar:</strong></p>
                        <ul>
                            <li>UTF-16 LE kodlaması</li>
                            <li>TAB ayrılmış değerler</li>
                            <li>Maksimum dosya boyutu: 10MB</li>
                        </ul>
                        
                        <p><strong>Gerekli Sütunlar:</strong></p>
                        <ul>
                            <li><code>full_name</code> - Müşteri adı soyadı</li>
                            <li><code>email</code> - E-posta adresi</li>
                            <li><code>phone_number</code> - Telefon numarası</li>
                            <li><code>city</code> - Şehir bilgisi</li>
                            <li><code>TC</code> - TC kimlik numarası</li>
                            <li><code>campaign_name</code> - Kampanya adı</li>
                        </ul>
                    </div>
                </div>

                <div class="upload-form-container">
                    <form method="post" enctype="multipart/form-data" class="upload-form">
                        <?php wp_nonce_field('facebook_upload_action', 'facebook_upload_nonce'); ?>
                        
                        <div class="file-upload-area">
                            <div class="file-upload-icon">
                                <i class="dashicons dashicons-media-document"></i>
                            </div>
                            <div class="file-upload-text">
                                <p><strong>CSV dosyanızı seçin</strong></p>
                                <p>Dosyayı buraya sürükleyin veya tıklayarak seçin</p>
                            </div>
                            <input type="file" name="facebook_csv_file" id="facebook_csv_file" accept=".csv" required>
                        </div>
                        
                        <div class="upload-actions">
                            <button type="submit" name="upload_facebook_csv" class="btn btn-primary">
                                <i class="dashicons dashicons-upload"></i>
                                Dosyayı Yükle
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($has_uploaded_file && !$show_preview): ?>
            <!-- CSV Önizleme Adımı -->
            <div class="preview-section">
                <div class="file-ready">
                    <div class="file-info">
                        <i class="dashicons dashicons-yes-alt"></i>
                        <h3>CSV Dosyası Hazır</h3>
                        <p>Dosya başarıyla yüklendi. Devam etmek için önizlemeyi kontrol edin.</p>
                    </div>
                </div>

                <div class="preview-actions">
                    <form method="post" class="facebook-preview-form">
                        <?php wp_nonce_field('facebook_preview_action', 'facebook_preview_nonce'); ?>
                        
                        <div class="action-buttons">
                            <button type="submit" name="preview_facebook_csv" class="btn btn-primary">
                                <i class="dashicons dashicons-visibility"></i>
                                CSV'yi Önizle ve Temsilci Ata
                            </button>
                            
                            <a href="?view=veri_aktar_facebook" class="btn btn-secondary">
                                <i class="dashicons dashicons-update"></i>
                                Yeni Dosya Yükle
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($show_preview && $csv_data): ?>
            <!-- CSV Önizleme ve Temsilci Atama -->
            <div class="preview-assignment-section">
                <div class="preview-header">
                    <h3><i class="dashicons dashicons-visibility"></i> CSV Önizlemesi ve Temsilci Ataması</h3>
                    <p>Aşağıdaki veriler içe aktarılacak. Her satır için temsilci atayabilir veya tümü için genel atama yapabilirsiniz.</p>
                </div>

                <form method="post" class="facebook-import-form">
                    <?php wp_nonce_field('facebook_import_action', 'facebook_import_nonce'); ?>
                    
                    <?php if ($can_assign && !empty($assignable_representatives)): ?>
                        <div class="global-assignment">
                            <h4><i class="dashicons dashicons-admin-users"></i> Genel Temsilci Ataması</h4>
                            <p>Tüm satırlar için varsayılan temsilci seçin (satır bazında seçimler bu ayarı geçersiz kılar):</p>
                            <div class="form-group">
                                <select name="default_assignment" id="default_assignment" class="rep-select">
                                    <option value="<?php echo $current_user_rep_id; ?>">Kendime ata</option>
                                    <?php foreach ($assignable_representatives as $rep): ?>
                                        <option value="<?php echo esc_attr($rep->id); ?>">
                                            <?php echo esc_html($rep->display_name); ?> 
                                            (<?php echo esc_html(get_role_name($rep->role)); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="apply-to-all" class="btn btn-outline btn-small">
                                    Tümüne Uygula
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="csv-preview-table">
                        <div class="table-header">
                            <h4>CSV Verileri (<?php echo count($csv_data['data']); ?> satır)</h4>
                        </div>
                        
                        <div class="table-container">
                            <table class="facebook-preview-table">
                                <thead>
                                    <tr>
                                        <th>Sıra</th>
                                        <th>Temsilci Ataması</th>
                                        <th>Ad Soyad</th>
                                        <th>Email</th>
                                        <th>Telefon</th>
                                        <th>Şehir</th>
                                        <th>TC Kimlik</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Include mapping function
                                    $facebook_importer_path = dirname(__FILE__) . '/import/csv-importer_facebook.php';
                                    if (file_exists($facebook_importer_path)) {
                                        require_once($facebook_importer_path);
                                    }
                                    
                                    foreach ($csv_data['data'] as $row_index => $row): 
                                        $customer_data = map_facebook_row_to_customer($row, $csv_data['headers'], $row_index);
                                    ?>
                                        <tr>
                                            <td><?php echo $row_index + 1; ?></td>
                                            <td>
                                                <?php if ($can_assign && !empty($assignable_representatives)): ?>
                                                    <select name="row_assignment[<?php echo $row_index; ?>]" class="row-assignment-select">
                                                        <option value="">-- Genel atamayı kullan --</option>
                                                        <option value="<?php echo $current_user_rep_id; ?>">Kendime ata</option>
                                                        <?php foreach ($assignable_representatives as $rep): ?>
                                                            <option value="<?php echo esc_attr($rep->id); ?>">
                                                                <?php echo esc_html($rep->display_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <span class="current-user-assignment">Kendime atanacak</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($customer_data['full_name']); ?></td>
                                            <td><?php echo esc_html($customer_data['email']); ?></td>
                                            <td><?php echo esc_html($customer_data['phone_number']); ?></td>
                                            <td><?php echo esc_html($customer_data['city']); ?></td>
                                            <td>
                                                <?php echo esc_html($customer_data['tc_identity']); ?>
                                                <?php if (!$customer_data['has_real_tc']): ?>
                                                    <span class="generated-tc-badge">Otomatik</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="import-actions">
                        <div class="action-buttons">
                            <button type="submit" name="start_facebook_import" class="btn btn-primary facebook-btn">
                                <i class="dashicons dashicons-database-import"></i>
                                Facebook Verilerini İçe Aktar (<?php echo count($csv_data['data']); ?> satır)
                            </button>
                            
                            <a href="?view=veri_aktar_facebook" class="btn btn-secondary">
                                <i class="dashicons dashicons-arrow-left-alt2"></i>
                                Başa Dön
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- İçe Aktarma Adımı -->
            <div class="import-section">
                <div class="file-ready">
                    <div class="file-info">
                        <i class="dashicons dashicons-yes-alt"></i>
                        <h3>CSV Dosyası Hazır</h3>
                        <p>Dosya başarıyla yüklendi ve içe aktarıma hazır.</p>
                    </div>
                    
                    <?php if ($session_csv_path): ?>
                        <div class="file-preview">
                            <h4>Dosya Önizlemesi:</h4>
                            <div class="preview-content">
                                <?php
                                try {
                                    // UTF-16 LE dosyasını UTF-8'e çevirip ilk birkaç satırı göster
                                    $raw_content = file_get_contents($session_csv_path);
                                    $utf8_content = mb_convert_encoding($raw_content, 'UTF-8', 'UTF-16LE');
                                    $lines = explode("\n", $utf8_content);
                                    
                                    echo '<div class="csv-preview">';
                                    $line_count = 0;
                                    foreach ($lines as $line) {
                                        if ($line_count >= 3) break;
                                        $clean_line = str_replace("\0", '', $line);
                                        if (!empty(trim($clean_line))) {
                                            echo '<div class="csv-line">' . esc_html(substr($clean_line, 0, 100)) . '...</div>';
                                            $line_count++;
                                        }
                                    }
                                    echo '</div>';
                                } catch (Exception $e) {
                                    echo '<div class="ab-notice ab-warning">Dosya önizlemesi gösterilemedi.</div>';
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="import-actions">
                    <form method="post" class="facebook-import-form">
                        <?php wp_nonce_field('facebook_import_action', 'facebook_import_nonce'); ?>
                        
                        <?php if ($can_assign && !empty($assignable_representatives)): ?>
                            <div class="representative-selection">
                                <h4><i class="dashicons dashicons-admin-users"></i> Temsilci Seçimi</h4>
                                <p>Bu müşteriler ve görevler hangi temsilciye atanacak?</p>
                                <div class="form-group">
                                    <label for="selected_rep_id">Müşteri Temsilcisi:</label>
                                    <select name="selected_rep_id" id="selected_rep_id" class="rep-select">
                                        <option value="">Kendime ata</option>
                                        <?php foreach ($assignable_representatives as $rep): ?>
                                            <option value="<?php echo esc_attr($rep->id); ?>">
                                                <?php echo esc_html($rep->display_name); ?> 
                                                (<?php echo esc_html(get_role_name($rep->role)); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <button type="submit" name="start_facebook_import" class="btn btn-primary facebook-btn">
                                <i class="dashicons dashicons-database-import"></i>
                                Facebook Verilerini İçe Aktar
                            </button>
                            
                            <a href="?view=veri_aktar_facebook" class="btn btn-secondary">
                                <i class="dashicons dashicons-update"></i>
                                Yeni Dosya Yükle
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="back-button">
            <a href="?view=veri_aktar" class="btn btn-outline">
                <i class="dashicons dashicons-arrow-left-alt2"></i>
                Veri Aktar Sayfasına Dön
            </a>
        </div>
    <?php else: ?>
        <div class="import-success">
            <div class="success-icon">
                <i class="dashicons dashicons-yes-alt"></i>
            </div>
            <h2>İçe Aktarım Tamamlandı!</h2>
            <p>Facebook verileri başarıyla sisteme aktarıldı.</p>
            
            <div class="success-actions">
                <a href="?view=customers" class="btn btn-primary">
                    <i class="dashicons dashicons-groups"></i>
                    Müşterileri Görüntüle
                </a>
                
                <a href="?view=tasks" class="btn btn-primary">
                    <i class="dashicons dashicons-clipboard"></i>
                    Görevleri Görüntüle
                </a>
                
                <a href="?view=veri_aktar_facebook" class="btn btn-secondary">
                    <i class="dashicons dashicons-update"></i>
                    Yeni İçe Aktarım
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.facebook-import-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
}

.import-header {
    text-align: center;
    margin-bottom: 30px;
    padding: 30px;
    background: linear-gradient(135deg, #1877f2, #42a5f5);
    color: white;
    border-radius: 12px;
}

.import-header h1 {
    margin: 0 0 10px 0;
    font-size: 28px;
    color: white;
}

.import-header p {
    margin: 0;
    font-size: 16px;
    opacity: 0.9;
}

/* Step Indicator */
.import-steps {
    margin-bottom: 30px;
}

.step-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-bottom: 30px;
}

.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    opacity: 0.5;
}

.step.active {
    opacity: 1;
    color: #1877f2;
}

.step.completed {
    opacity: 1;
    color: #28a745;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 16px;
}

.step.active .step-number {
    background: #1877f2;
    color: white;
}

.step.completed .step-number {
    background: #28a745;
    color: white;
}

.step-title {
    font-weight: 600;
    font-size: 14px;
    text-align: center;
}

.step-arrow {
    font-size: 20px;
    color: #ccc;
}

/* Preview Section */
.preview-section, .preview-assignment-section {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-bottom: 20px;
}

.preview-header {
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
}

.preview-header h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.preview-header p {
    margin: 0;
    color: #666;
}

.preview-actions {
    padding: 30px;
    text-align: center;
}

.global-assignment {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px;
    margin: 20px;
    margin-bottom: 25px;
}

.global-assignment h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 16px;
}

.global-assignment p {
    margin: 0 0 15px 0;
    color: #666;
    font-size: 14px;
}

.global-assignment .form-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.csv-preview-table {
    margin: 20px 0;
    width: 100%;
}

.csv-preview-table .table-header {
    background: #f8f9fa;
    padding: 15px;
    border-bottom: 1px solid #e0e0e0;
}

.csv-preview-table .table-header h4 {
    margin: 0;
    color: #333;
}

.table-container {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
    width: 100%;
    margin: 0 -20px; /* Allow table to use more horizontal space */
    padding: 0 20px;
}

.facebook-preview-table {
    width: 100%;
    min-width: 1425px; /* Reduced from 1600px by 175px due to smaller Temsilci column */
    border-collapse: collapse;
    font-size: 14px;
}

.facebook-preview-table th,
.facebook-preview-table td {
    padding: 10px;
    border: 1px solid #e0e0e0;
    text-align: left;
    vertical-align: top;
}

.facebook-preview-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
    position: sticky;
    top: 0;
    z-index: 10;
}

.facebook-preview-table th:first-child {
    width: 60px;
    min-width: 60px;
}

.facebook-preview-table th:nth-child(2) {
    width: 175px; /* Temsilci Ataması - reduced from 350px to 175px */
    min-width: 175px;
}

.facebook-preview-table th:nth-child(3) {
    width: 180px; /* Ad Soyad */
    min-width: 180px;
}

.facebook-preview-table th:nth-child(4) {
    width: 220px; /* Email */
    min-width: 220px;
}

.facebook-preview-table th:nth-child(5) {
    width: 140px; /* Telefon */
    min-width: 140px;
}

.facebook-preview-table th:nth-child(6) {
    width: 120px; /* Şehir */
    min-width: 120px;
}

.facebook-preview-table th:nth-child(7) {
    width: 140px; /* TC Kimlik */
    min-width: 140px;
}

.facebook-preview-table tr:hover {
    background-color: #f5f5f5;
}

.facebook-preview-table td:first-child {
    font-weight: 600;
    color: #666;
    text-align: center;
    min-width: 60px;
    width: 60px;
}

.facebook-preview-table td:nth-child(2) {
    min-width: 175px; /* Temsilci Ataması - reduced from 350px to 175px */
    width: 175px;
}

.facebook-preview-table td:nth-child(3) {
    min-width: 180px; /* Ad Soyad */
    width: 180px;
}

.facebook-preview-table td:nth-child(4) {
    min-width: 220px; /* Email */
    width: 220px;
}

.facebook-preview-table td:nth-child(5) {
    min-width: 140px; /* Telefon */
    width: 140px;
}

.facebook-preview-table td:nth-child(6) {
    min-width: 120px; /* Şehir */
    width: 120px;
}

.facebook-preview-table td:nth-child(7) {
    min-width: 140px; /* TC Kimlik */
    width: 140px;
}

.generated-tc-badge {
    display: inline-block;
    background: #ffc107;
    color: #856404;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    margin-left: 8px;
    font-weight: 600;
}

.row-assignment-select {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    background: white;
}

.row-assignment-select:focus {
    outline: none;
    border-color: #1877f2;
    box-shadow: 0 0 0 2px rgba(24, 119, 242, 0.2);
}

.current-user-assignment {
    color: #666;
    font-style: italic;
    font-size: 12px;
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-outline {
    background: transparent;
    color: #0073aa;
    border: 1px solid #0073aa;
}

.btn-outline:hover {
    background: #0073aa;
    color: white;
    text-decoration: none;
}

/* Responsive Design */
@media (max-width: 1800px) {
    .facebook-preview-table {
        min-width: 1325px;
    }
}

@media (max-width: 1600px) {
    .facebook-preview-table {
        min-width: 1225px;
    }
}

@media (max-width: 1400px) {
    .facebook-preview-table {
        min-width: 1125px;
    }
    
    .table-container {
        margin: 0 -15px; /* Allow table to use more horizontal space */
        padding: 0 15px;
    }
}

@media (max-width: 1200px) {
    .facebook-preview-table {
        min-width: 1025px;
    }
    
    .table-container {
        margin: 0 -20px; /* Allow table to use more horizontal space */
        padding: 0 20px;
    }
    
    /* Slightly reduce column widths */
    .facebook-preview-table th:nth-child(2),
    .facebook-preview-table td:nth-child(2) {
        min-width: 160px; /* Temsilci Ataması */
        width: 160px;
    }
    
    .facebook-preview-table th:nth-child(3),
    .facebook-preview-table td:nth-child(3) {
        min-width: 160px; /* Ad Soyad */
        width: 160px;
    }
    
    .facebook-preview-table th:nth-child(4),
    .facebook-preview-table td:nth-child(4) {
        min-width: 200px; /* Email */
        width: 200px;
    }
}

@media (max-width: 992px) {
    .facebook-preview-table {
        min-width: 925px;
        font-size: 13px;
    }
    
    .facebook-preview-table th,
    .facebook-preview-table td {
        padding: 8px;
    }
    
    /* Adjust column widths for medium screens */
    .facebook-preview-table td:nth-child(2),
    .facebook-preview-table th:nth-child(2) {
        min-width: 150px; /* Temsilci Ataması */
        width: 150px;
    }
    
    .facebook-preview-table td:nth-child(3),
    .facebook-preview-table th:nth-child(3) {
        min-width: 150px; /* Ad Soyad */
        width: 150px;
    }
    
    .facebook-preview-table td:nth-child(4),
    .facebook-preview-table th:nth-child(4) {
        min-width: 180px; /* Email */
        width: 180px;
    }
}

@media (max-width: 768px) {
    .table-container {
        font-size: 12px;
        margin: 0 -25px; /* Use more horizontal space on mobile */
        padding: 0 25px;
    }
    
    .facebook-preview-table {
        min-width: 825px;
        font-size: 12px;
    }
    
    .facebook-preview-table th,
    .facebook-preview-table td {
        padding: 6px 4px;
    }
    
    /* Compact column widths for mobile */
    .facebook-preview-table td:nth-child(2),
    .facebook-preview-table th:nth-child(2) {
        min-width: 130px; /* Temsilci Ataması */
        width: 130px;
    }
    
    .facebook-preview-table td:nth-child(3),
    .facebook-preview-table th:nth-child(3) {
        min-width: 140px; /* Ad Soyad */
        width: 140px;
    }
    
    .facebook-preview-table td:nth-child(4),
    .facebook-preview-table th:nth-child(4) {
        min-width: 170px; /* Email */
        width: 170px;
    }
    
    .row-assignment-select {
        font-size: 11px;
        padding: 4px 6px;
    }
    
    .global-assignment .form-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .global-assignment .form-group .rep-select {
        margin-bottom: 10px;
    }
}

@media (max-width: 576px) {
    .facebook-preview-table {
        min-width: 725px;
        font-size: 11px;
    }
    
    .table-container {
        margin: 0 -30px; /* Use maximum horizontal space on small mobile */
        padding: 0 30px;
    }
    
    .facebook-preview-table th,
    .facebook-preview-table td {
        padding: 4px 3px;
    }
    
    /* Further compress columns for very small screens */
    .facebook-preview-table td:first-child,
    .facebook-preview-table th:first-child {
        min-width: 50px;
        width: 50px;
    }
    
    .facebook-preview-table td:nth-child(2),
    .facebook-preview-table th:nth-child(2) {
        min-width: 120px; /* Temsilci Ataması */
        width: 120px;
    }
    
    .facebook-preview-table td:nth-child(3),
    .facebook-preview-table th:nth-child(3) {
        min-width: 130px; /* Ad Soyad */
        width: 130px;
    }
    
    .facebook-preview-table td:nth-child(4),
    .facebook-preview-table th:nth-child(4) {
        min-width: 150px; /* Email */
        width: 150px;
    }
    
    .facebook-preview-table td:nth-child(5),
    .facebook-preview-table th:nth-child(5) {
        min-width: 110px; /* Telefon */
        width: 110px;
    }
    
    .facebook-preview-table td:nth-child(6),
    .facebook-preview-table th:nth-child(6) {
        min-width: 100px; /* Şehir */
        width: 100px;
    }
    
    .facebook-preview-table td:nth-child(7),
    .facebook-preview-table th:nth-child(7) {
        min-width: 120px; /* TC Kimlik */
        width: 120px;
    }
    
    .row-assignment-select {
        font-size: 10px;
        padding: 3px 4px;
    }
}

/* Upload Section */
.upload-section, .import-section {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-bottom: 20px;
}

.upload-info {
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
}

.upload-info h2 {
    margin: 0 0 15px 0;
    color: #333;
}

.info-content ul {
    margin: 10px 0;
    padding-left: 20px;
}

.info-content li {
    margin-bottom: 5px;
}

.info-content code {
    background: #f1f1f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: monospace;
}

.upload-form-container {
    padding: 30px;
}

.file-upload-area {
    position: relative;
    border: 2px dashed #ccc;
    border-radius: 8px;
    padding: 40px 20px;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
    margin-bottom: 20px;
}

.file-upload-area:hover {
    border-color: #1877f2;
    background-color: #f8f9ff;
}

.file-upload-area.dragover {
    border-color: #1877f2;
    background-color: #f0f7ff;
}

.file-upload-icon {
    font-size: 48px;
    color: #ccc;
    margin-bottom: 15px;
}

.file-upload-text p {
    margin: 5px 0;
    color: #666;
}

.file-upload-text strong {
    color: #333;
    font-size: 16px;
}

.file-upload-area input[type="file"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.upload-actions {
    text-align: center;
}

/* File Ready Section */
.file-ready {
    padding: 30px;
    text-align: center;
}

.file-info {
    margin-bottom: 30px;
}

.file-info i {
    font-size: 48px;
    color: #28a745;
    margin-bottom: 15px;
}

.file-info h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.file-info p {
    margin: 0;
    color: #666;
}

.file-preview {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.file-preview h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 14px;
}

.csv-preview {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 15px;
    font-family: monospace;
    font-size: 12px;
    text-align: left;
}

.csv-line {
    margin-bottom: 8px;
    padding: 5px 0;
    border-bottom: 1px solid #e0e0e0;
    color: #666;
}

.csv-line:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.import-actions {
    padding: 30px;
    border-top: 1px solid #e0e0e0;
    text-align: center;
}

.representative-selection {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 25px;
    text-align: left;
}

.representative-selection h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 16px;
}

.representative-selection p {
    margin: 0 0 15px 0;
    color: #666;
    font-size: 14px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.rep-select {
    width: 100%;
    max-width: 400px;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    background: white;
    color: #333;
}

.rep-select:focus {
    outline: none;
    border-color: #1877f2;
    box-shadow: 0 0 0 2px rgba(24, 119, 242, 0.2);
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    align-items: center;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #0073aa;
    color: white;
}

.btn-primary:hover {
    background: #005a87;
    color: white;
    text-decoration: none;
}

.facebook-btn {
    background: #1877f2;
}

.facebook-btn:hover {
    background: #166fe5;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    color: white;
    text-decoration: none;
}

.btn-outline {
    background: transparent;
    color: #0073aa;
    border: 1px solid #0073aa;
}

.btn-outline:hover {
    background: #0073aa;
    color: white;
    text-decoration: none;
}

.back-button {
    text-align: center;
    margin-top: 20px;
}

/* Success Section */
.import-success {
    text-align: center;
    padding: 40px;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}

.success-icon {
    font-size: 64px;
    color: #28a745;
    margin-bottom: 20px;
}

.import-success h2 {
    margin: 0 0 15px 0;
    color: #333;
}

.import-success p {
    margin: 0 0 30px 0;
    color: #666;
    font-size: 16px;
}

.success-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    align-items: center;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .step-indicator {
        flex-direction: column;
        gap: 10px;
    }
    
    .step-arrow {
        transform: rotate(90deg);
    }
    
    .action-buttons,
    .success-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .file-upload-area {
        padding: 30px 15px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Apply to All functionality
    const applyToAllBtn = document.getElementById('apply-to-all');
    const defaultAssignmentSelect = document.getElementById('default_assignment');
    const rowAssignmentSelects = document.querySelectorAll('.row-assignment-select');
    
    if (applyToAllBtn && defaultAssignmentSelect) {
        applyToAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const selectedValue = defaultAssignmentSelect.value;
            
            // Apply the selected value to all row assignment selects
            rowAssignmentSelects.forEach(function(select) {
                select.value = selectedValue;
            });
            
            // Show feedback
            applyToAllBtn.innerHTML = '<i class="dashicons dashicons-yes"></i> Uygulandı';
            applyToAllBtn.style.background = '#28a745';
            applyToAllBtn.style.borderColor = '#28a745';
            applyToAllBtn.style.color = 'white';
            
            // Reset button after 2 seconds
            setTimeout(function() {
                applyToAllBtn.innerHTML = 'Tümüne Uygula';
                applyToAllBtn.style.background = '';
                applyToAllBtn.style.borderColor = '';
                applyToAllBtn.style.color = '';
            }, 2000);
        });
    }
    
    // File drag and drop functionality
    const fileUploadArea = document.querySelector('.file-upload-area');
    const fileInput = document.getElementById('facebook_csv_file');
    
    if (fileUploadArea && fileInput) {
        fileUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });
        
        fileUploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
        });
        
        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileUploadText(files[0].name);
            }
        });
        
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                updateFileUploadText(e.target.files[0].name);
            }
        });
        
        function updateFileUploadText(fileName) {
            const uploadText = fileUploadArea.querySelector('.file-upload-text');
            if (uploadText) {
                uploadText.innerHTML = '<p><strong>Seçilen dosya:</strong></p><p>' + fileName + '</p>';
            }
        }
    }
});
</script>