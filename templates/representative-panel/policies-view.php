<?php
/**
 * Poliçe Detay Sayfası - Material Design Edition
 * @version 5.1.0
 * @updated 2025-05-29 17:45
 */

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$policy_id = intval($_GET['id']);
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// Sütun kontrolü yaparak veritabanını güncel tut
$column_checks = [
    // İptal bilgileri için sütunlar
    'cancellation_date' => "ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status",
    'refunded_amount' => "ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date",
    'cancellation_reason' => "ALTER TABLE $policies_table ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER refunded_amount",
    
    // Poliçe meta bilgileri
    'policy_category' => "ALTER TABLE $policies_table ADD COLUMN policy_category VARCHAR(50) DEFAULT 'Yeni İş' AFTER policy_type",
    'network' => "ALTER TABLE $policies_table ADD COLUMN network VARCHAR(255) DEFAULT NULL AFTER premium_amount",
    'status_note' => "ALTER TABLE $policies_table ADD COLUMN status_note TEXT DEFAULT NULL AFTER status",
    'payment_info' => "ALTER TABLE $policies_table ADD COLUMN payment_info VARCHAR(255) DEFAULT NULL AFTER premium_amount",
    'insured_party' => "ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status",
    'plate_number' => "ALTER TABLE $policies_table ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL AFTER insured_party",
    'insurer' => "ALTER TABLE $policies_table ADD COLUMN insurer VARCHAR(255) DEFAULT NULL AFTER insured_party",
    'insured_list' => "ALTER TABLE $policies_table ADD COLUMN insured_list TEXT DEFAULT NULL AFTER insurer"
];

foreach ($column_checks as $column => $query) {
    $column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE '$column'");
    if (!$column_exists) {
        $wpdb->query($query);
    }
}

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";
$where_params = array($policy_id);

// Temsilcinin rolünü kontrol et ve yetkilendirme yap
$patron_access = false;
if ($current_user_rep_id) {
    global $wpdb;
    $rep_role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
        $current_user_rep_id
    ));
    
    // Patron veya Müdür rolüne sahip kullanıcılar tüm poliçelere erişebilir
    if ($rep_role == 1 || $rep_role == 2) {
        $patron_access = true;
    }
}

// Yönetici veya patron/müdür değilse, sadece kendi poliçelerine erişim sağlanır
if (!current_user_can('administrator') && !current_user_can('insurance_manager') && !$patron_access && $current_user_rep_id) {
    $where_clause = " AND p.representative_id = %d";
    $where_params[] = $current_user_rep_id;
}

// Poliçe bilgilerini al
$policy = $wpdb->get_row($wpdb->prepare("
    SELECT p.*,
           c.first_name, c.last_name, c.tc_identity, c.phone, c.email,
           c.spouse_name, c.spouse_tc_identity, c.children_names, c.children_tc_identities,
           u.display_name AS rep_name
    FROM $policies_table p
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE p.id = %d
    {$where_clause}
", $where_params));

if (!$policy) {
    echo '<div class="ab-notice ab-error">Poliçe bulunamadı veya görüntüleme yetkiniz yok.</div>';
    return;
}

// Sigortalı listesini parse etmek için fonksiyon
function parse_insured_list($insured_list, $policy) {
    if (empty($insured_list)) return [];
    
    $insured_persons = array();
    $names = explode(',', $insured_list);
    
    foreach ($names as $name) {
        $name = trim($name);
        if (empty($name)) continue;
        
        $person = array('name' => $name, 'tc' => 'Belirtilmemiş', 'type' => 'Diğer');
        
        // Müşterinin kendisi mi kontrol et
        $customer_full_name = trim($policy->first_name . ' ' . $policy->last_name);
        if ($name === $customer_full_name) {
            $person['tc'] = $policy->tc_identity ?: 'Belirtilmemiş';
            $person['type'] = 'Müşteri';
        }
        // Eş mi kontrol et
        elseif (!empty($policy->spouse_name) && $name === trim($policy->spouse_name)) {
            $person['tc'] = $policy->spouse_tc_identity ?: 'Belirtilmemiş';
            $person['type'] = 'Eş';
        }
        // Çocuk mu kontrol et
        elseif (!empty($policy->children_names)) {
            $children_names = explode(',', $policy->children_names);
            $children_tcs = !empty($policy->children_tc_identities) ? explode(',', $policy->children_tc_identities) : array();
            
            foreach ($children_names as $index => $child_name) {
                $child_name = trim($child_name);
                if ($name === $child_name) {
                    $person['tc'] = isset($children_tcs[$index]) ? trim($children_tcs[$index]) : 'Belirtilmemiş';
                    $person['type'] = 'Çocuk';
                    break;
                }
            }
        }
        
        $insured_persons[] = $person;
    }
    
    return $insured_persons;
}

// Poliçe ile ilgili görevleri al
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$tasks = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $tasks_table 
    WHERE policy_id = %d
    ORDER BY due_date ASC
", $policy_id));

// Poliçe bitiş tarihini kontrol et
$current_date = date('Y-m-d');
$days_until_expiry = (strtotime($policy->end_date) - strtotime($current_date)) / (60 * 60 * 24);
$expiry_status = '';
$expiry_class = '';

// İptal durumunu kontrol et
$is_cancelled = !empty($policy->cancellation_date);

if ($is_cancelled) {
    $expiry_status = 'İptal Edilmiş';
    $expiry_class = 'cancelled';
} elseif ($days_until_expiry < 0) {
    $expiry_status = 'Süresi Dolmuş';
    $expiry_class = 'expired';
} elseif ($days_until_expiry <= 30) {
    $expiry_status = 'Yakında Bitiyor (' . round($days_until_expiry) . ' gün)';
    $expiry_class = 'expiring-soon';
} else {
    $expiry_status = 'Aktif';
    $expiry_class = 'active';
}
?>

<div class="policy-detail-container">
    <?php if ($is_cancelled): ?>
    <!-- ÜST KISIMDA YANIP SÖNEN İPTAL BİLDİRİMİ -->
    <div class="cancellation-notification">
        <div class="notification-icon">
            <i class="fas fa-ban"></i>
        </div>
        <div class="notification-content">
            <h2>BU POLİÇE İPTAL EDİLMİŞTİR</h2>
            <div class="notification-meta">
                <span><i class="fas fa-calendar-times"></i> İptal Tarihi: <?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></span>
                <span><i class="fas fa-money-bill-wave"></i> İade Edilen: <?php echo number_format($policy->refunded_amount, 2, ',', '.'); ?> ₺</span>
                <?php if (!empty($policy->cancellation_reason)): ?>
                <span><i class="fas fa-info-circle"></i> Neden: <?php echo esc_html($policy->cancellation_reason); ?></span>

                        
                        <?php 
                        $start_date = new DateTime($policy->start_date);
                        $end_date = new DateTime($policy->end_date);
                        $cancel_date = new DateTime($policy->cancellation_date);
                        
                        $total_days = $start_date->diff($end_date)->days;
                        $used_days = $start_date->diff($cancel_date)->days;
                        $unused_days = $end_date->diff($cancel_date)->days;
                        
                        $used_percent = ($used_days / $total_days) * 100;
                        $unused_percent = 100 - $used_percent;
                        ?>
                        
                        <!-- Compact Progress Bar -->
                                    <div class="kullanim-segment used" style="width: <?php echo $used_percent; ?>%;">
                                        <?php if ($used_percent > 15): ?>
                                        <span><?php echo round($used_percent); ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="kullanim-segment unused" style="width: <?php echo $unused_percent; ?>%;">
                                        <?php if ($unused_percent > 15): ?>
                                        <span><?php echo round($unused_percent); ?>%</span>
                                        <?php endif; ?>
                                    
                                <div class="kullanim-labels">
                                    <span>Kullanılan: <?php echo $used_days; ?> gün</span>
				</div>
                                 <div class="kullanim-labels">
			   <span>Kullanılmayan: <?php echo $unused_days; ?> gün</span>
                                </div>
                           </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Üst Bilgi Alanı -->
    <div class="policy-header <?php echo $is_cancelled ? 'cancelled' : ''; ?>">
        <div class="header-content">
            <div class="header-primary">
                <div class="policy-title">
                    <div class="title-group">
                        <h1><?php echo esc_html($policy->policy_number); ?></h1>
                        <div class="badges">
                            <span class="badge badge-<?php echo $policy->status === 'aktif' ? 'success' : 'secondary'; ?>">
                                <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                            </span>
                            <span class="badge badge-<?php echo $expiry_class; ?>">
                                <?php echo $expiry_status; ?>
                            </span>
                        </div>
                    </div>
                    <div class="policy-meta">
                        <div class="meta-item">
                            <i class="fas fa-file-signature"></i>
                            <span><?php echo esc_html($policy->policy_type); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-building"></i>
                            <span><?php echo esc_html($policy->insurance_company); ?></span>
                        </div>
                        <?php if (!empty($policy->plate_number)): ?>
                        <div class="meta-item">
                            <i class="fas fa-car"></i>
                            <span><?php echo esc_html($policy->plate_number); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="meta-item">
                            <i class="fas fa-user"></i>
                            <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>">
                                <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <a href="?view=policies&action=edit&id=<?php echo $policy_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Düzenle
                </a>
                <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>" class="btn btn-success">
                    <i class="fas fa-tasks"></i> Yeni Görev
                </a>
                <a href="?view=policies" class="btn btn-text">
                    <i class="fas fa-arrow-left"></i> Listeye Dön
                </a>
                
                <?php if ($policy->status === 'aktif' && empty($policy->cancellation_date)): ?>
                <div class="dropdown">
                    <button class="btn btn-icon" type="button">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu">
                        <a href="?view=policies&action=cancel&id=<?php echo $policy_id; ?>" class="dropdown-item text-danger">
                            <i class="fas fa-ban"></i>
                            <span>İptal Et</span>
                        </a>
                        <a href="?view=policies&action=renew&id=<?php echo $policy_id; ?>" class="dropdown-item text-success">
                            <i class="fas fa-sync-alt"></i>
                            <span>Yenile</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- İçerik Alanı -->
    <div class="policy-content">
        <!-- Ana Bilgi Kısmı - 2 Sütun Grid -->
        <div class="info-grid">
            <!-- Poliçe Bilgileri Paneli -->
            <div class="panel panel-primary <?php echo $is_cancelled ? 'panel-cancelled' : ''; ?>">
                <div class="panel-header">
                    <h2><i class="fas fa-info-circle"></i> Poliçe Bilgileri</h2>
                </div>
                <div class="panel-body">
                    <div class="info-list">
                        <div class="info-item">
                            <div class="item-label">Poliçe Numarası</div>
                            <div class="item-value"><?php echo esc_html($policy->policy_number); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Poliçe Türü</div>
                            <div class="item-value"><?php echo esc_html($policy->policy_type); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Kategori</div>
                            <div class="item-value">
                                <?php 
                                $category = !empty($policy->policy_category) ? $policy->policy_category : 'Yeni İş';
                                // Turkish character-aware class name mapping
                                $category_class_map = [
                                    'Yeni İş' => 'yeni-is',
                                    'Yenileme' => 'yenileme', 
                                    'Zeyil' => 'zeyil',
                                    'Diğer' => 'diger'
                                ];
                                $category_class = isset($category_class_map[$category]) ? $category_class_map[$category] : 'diger';
                                ?>
                                <span class="badge <?php echo esc_attr($category_class); ?>">
                                    <?php echo esc_html($category); ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Sigorta Şirketi</div>
                            <div class="item-value"><?php echo esc_html($policy->insurance_company); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Temsilci</div>
                            <div class="item-value">
                                <?php echo !empty($policy->rep_name) ? esc_html($policy->rep_name) : 'Atanmamış'; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Başlangıç Tarihi</div>
                            <div class="item-value"><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Bitiş Tarihi</div>
                            <div class="item-value"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Prim Tutarı</div>
                            <div class="item-value amount"><?php echo number_format($policy->premium_amount, 2, ',', '.'); ?> ₺</div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Ödeme Bilgisi</div>
                            <div class="item-value">
                                <?php echo !empty($policy->payment_info) ? esc_html($policy->payment_info) : 'Belirtilmemiş'; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="item-label">Network</div>
                            <div class="item-value">
                                <?php echo !empty($policy->network) ? esc_html($policy->network) : 'Belirtilmemiş'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($policy->status_note)): ?>
                    <div class="note-section">
                        <div class="note-header">Durum Notu</div>
                        <div class="note-content"><?php echo nl2br(esc_html($policy->status_note)); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sigorta Ettiren Bilgileri Paneli -->
            <div class="panel panel-secondary <?php echo $is_cancelled ? 'panel-cancelled' : ''; ?>">
                <div class="panel-header">
                    <h2><i class="fas fa-user"></i> Sigorta Ettiren</h2>
                </div>
                <div class="panel-body">
                    <div class="customer-card">
                        <div class="customer-avatar">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="customer-name">
                            <h3>
                                <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>">
                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                </a>
                            </h3>
                            <?php if (!empty($policy->tc_identity)): ?>
                                <div class="customer-tc"><?php echo esc_html($policy->tc_identity); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="info-list">
                        <?php if(!empty($policy->phone)): ?>
                        <div class="info-item">
                            <div class="item-label">Telefon</div>
                            <div class="item-value">
                                <a href="tel:<?php echo esc_attr($policy->phone); ?>" class="contact-link">
                                    <i class="fas fa-phone"></i> <?php echo esc_html($policy->phone); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($policy->email)): ?>
                        <div class="info-item">
                            <div class="item-label">E-posta</div>
                            <div class="item-value">
                                <a href="mailto:<?php echo esc_attr($policy->email); ?>" class="contact-link">
                                    <i class="fas fa-envelope"></i> <?php echo esc_html($policy->email); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($policy->insured_party)): ?>
                        <div class="info-item">
                            <div class="item-label">Sigortalayan</div>
                            <div class="item-value highlight"><?php echo esc_html($policy->insured_party); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($policy->plate_number)): ?>
                        <div class="info-item">
                            <div class="item-label">Araç Plakası</div>
                            <div class="item-value highlight"><?php echo esc_html($policy->plate_number); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-user"></i> Müşteriyi Görüntüle
                        </a>
                        <a href="?view=customers&action=edit&id=<?php echo $policy->customer_id; ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-edit"></i> Müşteriyi Düzenle
                        </a>
                    </div>
                    
                    <!-- Poliçedeki Sigortalılar Bölümü -->
                    <?php if (!empty($policy->insured_list) || !empty($policy->insurer)): ?>
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e1e5e9;">
                        <h5 style="color: #2c3e50; margin-bottom: 10px; font-size: 14px;">
                            <i class="fas fa-shield-alt" style="color: <?php echo $corporate_color; ?>; margin-right: 6px;"></i>
                            Poliçedeki Sigortalılar
                        </h5>
                        <?php if (!empty($policy->insured_list)): ?>
                            <?php 
                            // Parse insured list with TC numbers
                            $insured_persons = parse_insured_list($policy->insured_list, $policy);
                            
                            foreach ($insured_persons as $person): 
                                // Tip ikonları
                                $icon = 'fas fa-user';
                                if ($person['type'] === 'Müşteri') $icon = 'fas fa-user-tie';
                                elseif ($person['type'] === 'Eş') $icon = 'fas fa-user-friends';
                                elseif ($person['type'] === 'Çocuk') $icon = 'fas fa-child';
                            ?>
                            <div style="background: #f8f9fa; padding: 8px 12px; margin: 5px 0; border-radius: 4px; border-left: 3px solid <?php echo $corporate_color; ?>;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-weight: 500; color: #495057;">
                                        <i class="<?php echo $icon; ?>" style="color: <?php echo $corporate_color; ?>; margin-right: 6px;"></i>
                                        <?php echo esc_html($person['name']); ?>
                                        <small style="color: #6c757d; margin-left: 8px;">(<?php echo esc_html($person['type']); ?>)</small>
                                    </span>
                                    <span style="font-size: 12px; color: #6c757d; background: #e9ecef; padding: 2px 6px; border-radius: 3px;">
                                        TC: <?php echo esc_html($person['tc']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 4px; color: #6c757d; font-style: italic;">
                                <i class="fas fa-info-circle" style="margin-right: 6px;"></i>
                                Sigortalı bilgisi mevcut değil
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Döküman Bölümü -->
            <div class="panel panel-simple <?php echo $is_cancelled ? 'panel-cancelled' : ''; ?>">
                <div class="panel-header">
                    <h2><i class="fas fa-file-pdf"></i> Poliçe Dökümanı</h2>
                </div>
                <div class="panel-body">
                    <?php if (!empty($policy->document_path)): ?>
                    <div class="document-display">
                        <div class="document-preview">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="document-info">
                            <div class="document-name"><?php echo basename($policy->document_path); ?></div>
                            <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="btn btn-primary">
                                <i class="fas fa-download"></i> İndir
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-file-upload"></i>
                        </div>
                        <div class="empty-message">Henüz yüklenmiş bir döküman bulunmuyor.</div>
                        <a href="?view=policies&action=edit&id=<?php echo $policy_id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> Döküman Ekle
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
                    
        <!-- Görevler Tablosu - Tam Genişlik -->
        <div class="panel panel-full <?php echo $is_cancelled ? 'panel-cancelled' : ''; ?>">
            <div class="panel-header with-actions">
                <h2><i class="fas fa-tasks"></i> İlgili Görevler</h2>
                <div class="panel-actions">
                    <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Yeni Görev
                    </a>
                </div>
            </div>
            <div class="panel-body">
                <?php if (empty($tasks)): ?>
                <div class="empty-state sm">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="empty-message">Bu poliçe ile ilgili görev bulunmuyor.</div>
                    <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Yeni Görev Ekle
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Görev Açıklaması</th>
                                <th>Son Tarih</th>
                                <th>Öncelik</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($tasks as $task):
                                $is_overdue = strtotime($task->due_date) < time() && $task->status !== 'completed';
                                $row_class = $is_overdue ? 'overdue' : '';
                                
                                // Durum çevirisi
                                $status_text = '';
                                switch ($task->status) {
                                    case 'pending': $status_text = 'Beklemede'; break;
                                    case 'in_progress': $status_text = 'İşlemde'; break;
                                    case 'completed': $status_text = 'Tamamlandı'; break;
                                    case 'cancelled': $status_text = 'İptal'; break;
                                    default: $status_text = ucfirst($task->status); break;
                                }
                                
                                // Öncelik çevirisi
                                $priority_text = '';
                                switch ($task->priority) {
                                    case 'low': $priority_text = 'Düşük'; break;
                                    case 'medium': $priority_text = 'Orta'; break;
                                    case 'high': $priority_text = 'Yüksek'; break;
                                    default: $priority_text = ucfirst($task->priority); break;
                                }
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" class="task-link">
                                            <?php echo esc_html($task->task_description); ?>
                                        </a>
                                        <?php if ($is_overdue): ?>
                                            <span class="badge badge-danger">Gecikmiş</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($task->due_date)); ?></td>
                                    <td>
                                        <span class="badge badge-priority-<?php echo esc_attr($task->priority); ?>">
                                            <?php echo $priority_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-status-<?php echo esc_attr($task->status); ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" class="btn btn-icon btn-sm" title="Görüntüle">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" class="btn btn-icon btn-sm" title="Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Yenileme Bilgisi (İptal edilmemiş poliçeler için) -->
        <?php if (!$is_cancelled): ?>
        <div class="panel panel-full">
            <div class="panel-header">
                <h2><i class="fas fa-sync-alt"></i> Poliçe Yenileme</h2>
            </div>
            <div class="panel-body">
                <?php if ($days_until_expiry < 0): ?>
                <div class="alert alert-danger">
                    <div class="alert-content">
                        <div class="alert-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="alert-text">
                            <h4>Bu poliçenin süresi dolmuştur!</h4>
                            <p>Poliçe <?php echo date('d.m.Y', strtotime($policy->end_date)); ?> tarihinde sona erdi. Müşteri hala aktif bir poliçeye sahip olmak istiyorsa yenileme yapmanız gerekiyor.</p>
                        </div>
                    </div>
                    <div class="alert-actions">
                        <a href="?view=policies&action=renew&id=<?php echo $policy_id; ?>" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Poliçeyi Yenile
                        </a>
                    </div>
                </div>
                <?php elseif ($days_until_expiry <= 30): ?>
                <div class="alert alert-warning">
                    <div class="alert-content">
                        <div class="alert-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="alert-text">
                            <h4>Bu poliçe yakında sona erecek!</h4>
                            <p>Poliçenin bitiş tarihine <?php echo round($days_until_expiry); ?> gün kaldı. Müşteriyle iletişime geçerek yenileme hakkında bilgi verin.</p>
                        </div>
                    </div>
                    <div class="alert-actions">
                        <a href="?view=tasks&action=new&policy_id=<?php echo $policy_id; ?>&task_type=renewal" class="btn btn-secondary">
                            <i class="fas fa-tasks"></i> Hatırlatma Görevi Oluştur
                        </a>
                        <a href="?view=policies&action=renew&id=<?php echo $policy_id; ?>" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Poliçeyi Yenile
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <div class="alert-content">
                        <div class="alert-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="alert-text">
                            <h4>Bu poliçe aktif durumda.</h4>
                            <p>Bitiş tarihi: <?php echo date('d.m.Y', strtotime($policy->end_date)); ?> (<?php echo round($days_until_expiry); ?> gün kaldı)</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Modern Material Design Poliçe Detayları CSS v5.1.0 */
:root {
    /* Primary Colors */
    --color-primary: #1976d2;
    --color-primary-dark: #0d47a1;
    --color-primary-light: #42a5f5;
    --color-secondary: #9c27b0;
    
    /* Status Colors */
    --color-success: #2e7d32;
    --color-success-light: #e8f5e9;
    --color-warning: #f57c00;
    --color-warning-light: #fff8e0;
    --color-danger: #d32f2f;
    --color-danger-light: #ffebee;
    --color-info: #0288d1;
    --color-info-light: #e1f5fe;
    
    /* Neutral Colors */
    --color-text-primary: #212121;
    --color-text-secondary: #757575;
    --color-background: #f5f5f5;
    --color-surface: #ffffff;
    --color-border: #e0e0e0;
    
    /* Priority Colors */
    --color-priority-high: #d32f2f;
    --color-priority-high-light: #ffebee;
    --color-priority-medium: #f57c00;
    --color-priority-medium-light: #fff8e0;
    --color-priority-low: #388e3c;
    --color-priority-low-light: #e8f5e9;
    
    /* Task Status Colors */
    --color-status-pending: #2196f3;
    --color-status-pending-light: #e3f2fd;
    --color-status-in_progress: #f57c00;
    --color-status-in_progress-light: #fff8e0;
    --color-status-completed: #388e3c;
    --color-status-completed-light: #e8f5e9;
    --color-status-cancelled: #757575;
    --color-status-cancelled-light: #f5f5f5;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    
    /* Shadows */
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    --shadow-md: 0 3px 6px rgba(0,0,0,0.15), 0 2px 4px rgba(0,0,0,0.12);
    --shadow-lg: 0 10px 20px rgba(0,0,0,0.15), 0 3px 6px rgba(0,0,0,0.1);
    
    /* Border Radius */
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    
    /* Spacing */
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
}

/* Base Styling */
.policy-detail-container {
    font-family: var(--font-family);
    color: var(--color-text-primary);
    background-color: var(--color-background);
    max-width: 1280px;
    margin: 0 auto;
    padding: var(--spacing-md);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
}

/* YANIP SÖNEN İPTAL BİLDİRİMİ STILI - YENİ */
.cancellation-notification {
    background: linear-gradient(135deg, #ffcdd2, #ffebee);
    border: 2px solid #e53935;
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
    animation: pulsate 2s infinite alternate;
    box-shadow: var(--shadow-md);
    margin-bottom: var(--spacing-lg);
}

@keyframes pulsate {
    0% {
        box-shadow: 0 0 0 0 rgba(229, 57, 53, 0.4);
        background: linear-gradient(135deg, #ffcdd2, #ffebee);
    }
    100% {
        box-shadow: 0 0 10px 5px rgba(229, 57, 53, 0.4);
        background: linear-gradient(135deg, #ef9a9a, #ffcdd2);
    }
}

.notification-icon {
    width: 64px;
    height: 64px;
    min-width: 64px;
    border-radius: 50%;
    background-color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
    font-size: 32px;
    color: var(--color-danger);
}

.notification-content {
    flex: 1;
}

.notification-content h2 {
    margin: 0 0 var(--spacing-sm) 0;
    font-size: 24px;
    font-weight: 700;
    color: var(--color-danger);
    text-shadow: 1px 1px 1px rgba(255, 255, 255, 0.5);
}

.notification-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.notification-meta span {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    background-color: rgba(255, 255, 255, 0.7);
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
}

.notification-meta i {
    color: var(--color-danger);
}

/* Header Styling */
.policy-header {
    background-color: var(--color-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    transition: all 0.3s ease;
}

.policy-header.cancelled {
    border-left: 5px solid var(--color-danger);
}

.header-content {
    padding: var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: var(--spacing-lg);
}

.header-primary {
    flex: 1;
    min-width: 300px;
}

.policy-title {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.title-group {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.policy-title h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: var(--color-text-primary);
}

.badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
}

.badge {
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: 100px; /* Pill shape */
    font-size: 12px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-success {
    background-color: var(--color-success-light);
    color: var(--color-success);
}

.badge-secondary {
    background-color: #f5f5f5;
    color: #757575;
}

.badge-danger,
.badge-cancelled {
    background-color: var(--color-danger-light);
    color: var(--color-danger);
}

.badge-warning,
.badge-expiring-soon {
    background-color: var(--color-warning-light);
    color: var(--color-warning);
}

.badge-expired {
    background-color: var(--color-warning-light);
    color: var(--color-warning);
}

.badge-active {
    background-color: var(--color-success-light);
    color: var(--color-success);
}

.badge.yeni-is {
    background: rgba(67, 160, 71, 0.1);
    color: #2e7d32;
    border: 1px solid rgba(67, 160, 71, 0.2);
}

.badge.yenileme {
    background: rgba(156, 39, 176, 0.1);
    color: #7b1fa2;
    border: 1px solid rgba(156, 39, 176, 0.2);
}

.badge.zeyil {
    background: rgba(255, 152, 0, 0.1);
    color: #e65100;
    border: 1px solid rgba(255, 152, 0, 0.2);
}

.badge.diger {
    background: rgba(117, 117, 117, 0.1);
    color: #424242;
    border: 1px solid rgba(117, 117, 117, 0.2);
}

.policy-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--color-text-secondary);
}

.meta-item i {
    font-size: 16px;
    color: var(--color-text-secondary);
}

.meta-item a {
    color: var(--color-primary);
    text-decoration: none;
}

.meta-item a:hover {
    text-decoration: underline;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    flex-wrap: wrap;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
    line-height: 1.5;
}

.btn-primary {
    background-color: var(--color-primary);
    color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-primary:hover {
    background-color: var(--color-primary-dark);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-success {
    background-color: var(--color-success);
    color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-success:hover {
    background-color: #1b5e20;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-secondary {
    background-color: #f5f5f5;
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
}

.btn-secondary:hover {
    background-color: #e0e0e0;
}

.btn-text {
    background-color: transparent;
    color: var(--color-text-secondary);
}

.btn-text:hover {
    background-color: rgba(0,0,0,0.05);
    color: var(--color-text-primary);
}

.btn-outline {
    background-color: transparent;
    border: 1px solid var(--color-border);
    color: var(--color-primary);
}

.btn-outline:hover {
    background-color: rgba(25, 118, 210, 0.05);
}

.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}

.btn-icon {
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: transparent;
    color: var(--color-text-secondary);
}

.btn-icon:hover {
    background-color: rgba(0,0,0,0.05);
    color: var(--color-text-primary);
}

/* Content Area */
.policy-content {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: var(--spacing-lg);
}

/* Panel Styling */
.panel {
    background-color: var(--color-surface);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    border: 1px solid var(--color-border);
    transition: all 0.3s ease;
}

.panel:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.panel-cancelled {
    opacity: 0.9;
    border-left: 3px solid var(--color-danger);
}

.panel-primary .panel-header {
    background-color: rgba(25, 118, 210, 0.05);
}

.panel-secondary .panel-header {
    background-color: rgba(156, 39, 176, 0.05);
}

.panel-danger .panel-header {
    background-color: var(--color-danger-light);
    color: var(--color-danger);
}

.panel-danger .panel-header h2 {
    color: var(--color-danger);
}

.panel-full {
    grid-column: 1 / -1;
}

.panel-header {
    padding: var(--spacing-md);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.panel-header h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.panel-header h2 i {
    color: var(--color-primary);
}

.panel-header.with-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.panel-body {
    padding: var(--spacing-lg);
}

/* Info List */
.info-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.item-label {
    font-size: 12px;
    color: var(--color-text-secondary);
    font-weight: 500;
}

.item-value {
    font-size: 14px;
    color: var(--color-text-primary);
}

.item-value.amount {
    font-weight: 600;
    color: var(--color-success);
}

.item-value.highlight {
    font-weight: 600;
}

.contact-link {
    color: var(--color-primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}

.contact-link:hover {
    text-decoration: underline;
}

/* Note Section */
.note-section {
    background-color: #f5f5f5;
    border-radius: var(--radius-sm);
    padding: var(--spacing-md);
    margin-top: var(--spacing-md);
    border-left: 4px solid var(--color-primary-light);
}

.note-header {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: var(--spacing-sm);
    color: var(--color-text-secondary);
}

.note-content {
    font-size: 14px;
    white-space: pre-line;
    color: var(--color-text-primary);
}

/* Customer Card */
.customer-card {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
    padding: var(--spacing-md);
    background-color: #f9f9f9;
    border-radius: var(--radius-md);
}

.customer-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background-color: #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9e9e9e;
    font-size: 30px;
}

.customer-name {
    flex: 1;
}

.customer-name h3 {
    margin: 0 0 4px 0;
    font-size: 18px;
    font-weight: 600;
}

.customer-name h3 a {
    color: var(--color-text-primary);
    text-decoration: none;
}

.customer-name h3 a:hover {
    color: var(--color-primary);
    text-decoration: underline;
}

.customer-tc {
    font-size: 14px;
    color: var(--color-text-secondary);
}

.insured-info {
    background-color: #e8f5e9;
    color: #2e7d32;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
}

.action-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: var(--spacing-md);
}

/* Document Display */
.document-display {
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

.document-preview {
    width: 80px;
    height: 100px;
    background-color: #f5f5f5;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    color: #e53935;
}

.document-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.document-name {
    font-size: 14px;
    color: var(--color-text-primary);
    word-break: break-all;
    margin-bottom: var(--spacing-sm);
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--color-text-secondary);
}

.empty-state.sm {
    padding: var(--spacing-lg);
}

.empty-icon {
    font-size: 48px;
    color: #e0e0e0;
    margin-bottom: var(--spacing-md);
}

.empty-state.sm .empty-icon {
    font-size: 32px;
}

.empty-message {
    font-size: 16px;
    margin-bottom: var(--spacing-md);
}

.empty-state.sm .empty-message {
    font-size: 14px;
    margin-bottom: var(--spacing-sm);
}

/* Table Styling */
.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: var(--spacing-sm) var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
    font-size: 14px;
}

.data-table th {
    background-color: #f9f9f9;
    font-weight: 600;
    color: var(--color-text-secondary);
}

.data-table tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}

.data-table tbody tr.overdue {
    background-color: var(--color-danger-light);
}

.task-link {
    color: var(--color-text-primary);
    text-decoration: none;
    font-weight: 500;
}

.task-link:hover {
    color: var(--color-primary);
    text-decoration: underline;
}

/* Priority and Status Badges */
.badge-priority-high {
    background-color: var(--color-priority-high-light);
    color: var(--color-priority-high);
}

.badge-priority-medium {
    background-color: var(--color-priority-medium-light);
    color: var(--color-priority-medium);
}

.badge-priority-low {
    background-color: var(--color-priority-low-light);
    color: var(--color-priority-low);
}

.badge-status-pending {
    background-color: var(--color-status-pending-light);
    color: var(--color-status-pending);
}

.badge-status-in_progress {
    background-color: var(--color-status-in_progress-light);
    color: var(--color-status-in_progress);
}

.badge-status-completed {
    background-color: var(--color-status-completed-light);
    color: var(--color-status-completed);
}

.badge-status-cancelled {
    background-color: var(--color-status-cancelled-light);
    color: var(--color-status-cancelled);
}

/* Dropdown */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    min-width: 180px;
    background-color: var(--color-surface);
    box-shadow: var(--shadow-md);
    border-radius: var(--radius-sm);
    padding: var(--spacing-xs);
    z-index: 1000;
}

.dropdown:hover .dropdown-menu {
    display: block;
}

.dropdown-item {
    display: flex;
    align-items: center;
    padding: var(--spacing-sm) var(--spacing-md);
    color: var(--color-text-primary);
    text-decoration: none;
    font-size: 14px;
    border-radius: var(--radius-sm);
    transition: background-color 0.2s ease;
    gap: var(--spacing-sm);
}

.dropdown-item:hover {
    background-color: #f5f5f5;
}

.dropdown-item.text-danger {
    color: var(--color-danger);
}

.dropdown-item.text-danger:hover {
    background-color: var(--color-danger-light);
}

.dropdown-item.text-success {
    color: var(--color-success);
}

.dropdown-item.text-success:hover {
    background-color: var(--color-success-light);
}

/* İPTAL BİLGİLERİ - YENİ KOMPAKT TASARIM */
.iptal-bilgi-compact {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
}

.iptal-detay {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.iptal-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    background-color: #f9f9f9;
    padding: var(--spacing-sm);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
}

.iptal-icon {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background-color: white;
    box-shadow: var(--shadow-sm);
}

.iptal-icon.danger {
    color: var(--color-danger);
}

.iptal-icon.success {
    color: var(--color-success);
}

.iptal-icon.info {
    color: var(--color-info);
}

.iptal-content {
    flex: 1;
}

.iptal-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 2px;
}

.iptal-value {
    font-size: 16px;
    font-weight: 600;
    color: var(--color-text-primary);
}

/* KOMPAKT KULLANIM CHART */
.kullanim-chart {
    background-color: #f9f9f9;
    padding: var(--spacing-md);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    margin-top: var(--spacing-sm);
}

.kullanim-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: var(--spacing-sm);
    text-align: center;
}

.kullanim-bar-container {
    max-width: 100%;
}

.kullanim-bar {
    height: 24px;
    border-radius: 12px;
    background-color: #f5f5f5;
    display: flex;
    overflow: hidden;
    margin-bottom: var(--spacing-sm);
}

.kullanim-segment {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
    font-weight: 600;
    transition: width 1s ease-in-out;
}

.kullanim-segment.used {
    background-color: #e53935;
}

.kullanim-segment.unused {
    background-color: #4caf50;
}

.kullanim-labels {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--color-text-secondary);
}

/* Alert Styles */
.alert {
    border-radius: var(--radius-md);
    padding: var(--spacing-lg);
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
    position: relative;
}

.alert-content {
    flex: 1;
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
}

.alert-icon {
    font-size: 24px;
    width: 50px;
    height: 50px;
    min-width: 50px;
    border-radius: 50%;
    background-color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-sm);
}

.alert-text {
    flex: 1;
}

.alert-text h4 {
    margin: 0 0 var(--spacing-sm) 0;
    font-size: 16px;
    font-weight: 600;
}

.alert-text p {
    margin: 0;
    font-size: 14px;
    color: var(--color-text-secondary);
}

.alert-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-md);
    padding-left: calc(50px + var(--spacing-md));
}

.alert-danger {
    background-color: var(--color-danger-light);
    border-left: 4px solid var(--color-danger);
}

.alert-danger .alert-icon {
    color: var(--color-danger);
}

.alert-warning {
    background-color: var(--color-warning-light);
    border-left: 4px solid var(--color-warning);
}

.alert-warning .alert-icon {
    color: var(--color-warning);
}

.alert-success {
    background-color: var(--color-success-light);
    border-left: 4px solid var(--color-success);
}

.alert-success .alert-icon {
    color: var(--color-success);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.policy-detail-container {
    animation: fadeIn 0.3s ease-in-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
    }

    .header-actions {
        width: 100%;
        justify-content: flex-start;
        margin-top: var(--spacing-md);
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .info-list {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }

    .cancellation-notification {
        flex-direction: column;
        text-align: center;
    }

    .notification-meta {
        justify-content: center;
    }

    .document-display {
        flex-direction: column;
        align-items: center;
    }

    .alert-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .alert-actions {
        padding-left: 0;
        justify-content: center;
    }
    
    .iptal-item {
        flex-direction: column;
        text-align: center;
        padding: var(--spacing-md);
    }
    
    .iptal-content {
        width: 100%;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .policy-title h1 {
        font-size: 22px;
    }

    .panel-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-sm);
    }

    .panel-header.with-actions {
        flex-direction: column;
        align-items: flex-start;
    }

    .panel-actions {
        width: 100%;
        margin-top: var(--spacing-sm);
    }

    .panel-body {
        padding: var(--spacing-md);
    }
    
    .notification-content h2 {
        font-size: 20px;
    }
    
    .notification-meta {
        flex-direction: column;
        align-items: center;
    }
    
    .notification-meta span {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tooltip gösterimi için
    document.querySelectorAll('[title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            const title = this.getAttribute('title');
            if (!title) return;
            
            this.setAttribute('data-title', title);
            this.removeAttribute('title');
            
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = title;
            
            const rect = this.getBoundingClientRect();
            tooltip.style.cssText = `
                position: fixed;
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 9999;
                top: ${rect.top - 30}px;
                left: ${rect.left + rect.width / 2}px;
                transform: translateX(-50%);
                pointer-events: none;
            `;
            
            document.body.appendChild(tooltip);
            
            this.addEventListener('mouseleave', function() {
                this.setAttribute('title', this.getAttribute('data-title'));
                this.removeAttribute('data-title');
                document.body.removeChild(tooltip);
            }, { once: true });
        });
    });
    
    // İptal uyarısı animasyonu için
    const cancelNotification = document.querySelector('.cancellation-notification');
    if (cancelNotification) {
        // Ekstra dikkat çekici animasyon efekti
        const icon = cancelNotification.querySelector('.notification-icon i');
        if (icon) {
            setInterval(() => {
                icon.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    icon.style.transform = 'scale(1)';
                }, 500);
            }, 2000);
        }
    }
    
    // Müşteri kartı animasyonu
    const customerCard = document.querySelector('.customer-card');
    if (customerCard) {
        customerCard.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px)';
            this.style.boxShadow = '0 6px 12px rgba(0,0,0,0.1)';
        });
        
        customerCard.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    }
    
    // Doküman önizleme animasyonu
    const docPreview = document.querySelector('.document-preview');
    if (docPreview) {
        docPreview.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
        });
        
        docPreview.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    }
    
    // İptal öğeleri için hover efekti
    const iptalItems = document.querySelectorAll('.iptal-item');
    if (iptalItems) {
        iptalItems.forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'var(--shadow-sm)';
            });
        });
    }
    
    console.log('Modern Poliçe Detay Sayfası v5.1.0 - Anadolu Birlik');
    console.log('Son Güncelleme: 2025-05-29 17:50:45');
    console.log('Kullanıcı: anadolubirlikdevam');
});
</script>