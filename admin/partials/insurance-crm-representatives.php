<?php
/**
 * Müşteri Temsilcileri Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 */

if (!defined("WPINC")) {
    die;
}

$rep_id = isset($_GET["edit"]) ? intval($_GET["edit"]) : 0;
$editing = ($rep_id > 0);
$edit_rep = null;

if ($editing) {
    global $wpdb;
    $table_reps = $wpdb->prefix . "insurance_crm_representatives";
    $table_users = $wpdb->users;
    
    $edit_rep = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, u.user_email as email, u.display_name, u.user_login as username,
                u.first_name, u.last_name
         FROM $table_reps r 
         LEFT JOIN $table_users u ON r.user_id = u.ID 
         WHERE r.id = %d",
        $rep_id
    ));
    
    if (!$edit_rep) {
        $editing = false;
    }
}

if (isset($_POST["submit_representative"]) && isset($_POST["representative_nonce"]) && 
    wp_verify_nonce($_POST["representative_nonce"], "add_edit_representative")) {
    
    if ($editing) {
        $rep_data = array(
            "title" => sanitize_text_field($_POST["title"]),
            "phone" => sanitize_text_field($_POST["phone"]),
            "department" => sanitize_text_field($_POST["department"]),
            "monthly_target" => floatval($_POST["monthly_target"]),
            "updated_at" => current_time("mysql")
        );
        
        global $wpdb;
        $table_reps = $wpdb->prefix . "insurance_crm_representatives";
        
        $wpdb->update(
            $table_reps,
            $rep_data,
            array("id" => $rep_id)
        );
        
        if (isset($_POST["first_name"]) && isset($_POST["last_name"]) && isset($_POST["email"])) {
            $user_id = $edit_rep->user_id;
            wp_update_user(array(
                "ID" => $user_id,
                "first_name" => sanitize_text_field($_POST["first_name"]),
                "last_name" => sanitize_text_field($_POST["last_name"]),
                "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"]),
                "user_email" => sanitize_email($_POST["email"])
            ));
        }
        
        if (!empty($_POST["password"]) && !empty($_POST["confirm_password"]) && $_POST["password"] == $_POST["confirm_password"]) {
            wp_set_password($_POST["password"], $edit_rep->user_id);
        }
        
        echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
        
        echo '<script>window.location.href = "' . admin_url("admin.php?page=insurance-crm-representatives") . '";</script>';
    } else {
        if (isset($_POST["username"]) && isset($_POST["password"]) && isset($_POST["confirm_password"])) {
            $username = sanitize_user($_POST["username"]);
            $password = $_POST["password"];
            $confirm_password = $_POST["confirm_password"];
            
            if (empty($username) || empty($password) || empty($confirm_password)) {
                echo '<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>';
            } else if ($password !== $confirm_password) {
                echo '<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>';
            } else if (username_exists($username)) {
                echo '<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>';
            } else if (email_exists($_POST["email"])) {
                echo '<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>';
            } else {
                $user_id = wp_create_user($username, $password, sanitize_email($_POST["email"]));
                
                if (!is_wp_error($user_id)) {
                    wp_update_user(
                        array(
                            "ID" => $user_id,
                            "first_name" => sanitize_text_field($_POST["first_name"]),
                            "last_name" => sanitize_text_field($_POST["last_name"]),
                            "display_name" => sanitize_text_field($_POST["first_name"]) . " " . sanitize_text_field($_POST["last_name"])
                        )
                    );
                    
                    $user = new WP_User($user_id);
                    $user->set_role("insurance_representative");
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . "insurance_crm_representatives";
                    
                    $wpdb->insert(
                        $table_name,
                        array(
                            "user_id" => $user_id,
                            "title" => sanitize_text_field($_POST["title"]),
                            "phone" => sanitize_text_field($_POST["phone"]),
                            "department" => sanitize_text_field($_POST["department"]),
                            "monthly_target" => floatval($_POST["monthly_target"]),
                            "status" => "active",
                            "created_at" => current_time("mysql"),
                            "updated_at" => current_time("mysql")
                        )
                    );
                    
                    echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message() . '</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>';
        }
    }
}

global $wpdb;
$table_name = $wpdb->prefix . "insurance_crm_representatives";
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM $table_name r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active' 
     ORDER BY r.created_at DESC"
);
?>

<div class="wrap">
    <h1>Müşteri Temsilcileri</h1>
    
    <h2>Mevcut Müşteri Temsilcileri</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Ad Soyad</th>
                <th>E-posta</th>
                <th>Ünvan</th>
                <th>Telefon</th>
                <th>Departman</th>
                <th>Aylık Hedef</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($representatives as $rep): ?>
            <tr>
                <td><?php echo esc_html($rep->display_name); ?></td>
                <td><?php echo esc_html($rep->email); ?></td>
                <td><?php echo esc_html($rep->title); ?></td>
                <td><?php echo esc_html($rep->phone); ?></td>
                <td><?php echo esc_html($rep->department); ?></td>
                <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                <td>
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&edit=' . $rep->id); ?>" 
                       class="button button-small">
                        Düzenle
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=insurance-crm-representatives&action=delete&id=' . $rep->id), 'delete_representative_' . $rep->id); ?>" 
                       class="button button-small" 
                       onclick="return confirm('Bu müşteri temsilcisini silmek istediğinizden emin misiniz?');">
                        Sil
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <hr>
    
    <?php if ($editing): ?>
        <h2>Müşteri Temsilcisini Düzenle</h2>
    <?php else: ?>
        <h2>Yeni Müşteri Temsilcisi Ekle</h2>
    <?php endif; ?>
    
    <form method="post" action="">
        <?php wp_nonce_field("add_edit_representative", "representative_nonce"); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">
        <?php endif; ?>
        
        <table class="form-table">
            <?php if (!$editing): ?>
                <tr>
                    <th><label for="username">Kullanıcı Adı</label></th>
                    <td><input type="text" name="username" id="username" class="regular-text" required></td>
                </tr>
            <?php endif; ?>
                
            <tr>
                <th><label for="password">Şifre</label></th>
                <td>
                    <input type="password" name="password" id="password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>>
                    <p class="description">
                        <?php echo $editing ? "Değiştirmek istemiyorsanız boş bırakın." : "En az 8 karakter uzunluğunda olmalıdır."; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="confirm_password">Şifre (Tekrar)</label></th>
                <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" <?php echo !$editing ? "required" : ""; ?>></td>
            </tr>
            <tr>
                <th><label for="first_name">Ad</label></th>
                <td>
                    <input type="text" name="first_name" id="first_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->first_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="last_name">Soyad</label></th>
                <td>
                    <input type="text" name="last_name" id="last_name" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->last_name) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="email">E-posta</label></th>
                <td>
                    <input type="email" name="email" id="email" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->email) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="title">Ünvan</label></th>
                <td>
                    <input type="text" name="title" id="title" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->title) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="phone">Telefon</label></th>
                <td>
                    <input type="tel" name="phone" id="phone" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->phone) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="department">Departman</label></th>
                <td>
                    <input type="text" name="department" id="department" class="regular-text"
                           value="<?php echo $editing ? esc_attr($edit_rep->department) : ""; ?>">
                </td>
            </tr>
            <tr>
                <th><label for="monthly_target">Aylık Hedef (₺)</label></th>
                <td>
                    <input type="number" step="0.01" name="monthly_target" id="monthly_target" class="regular-text" required
                           value="<?php echo $editing ? esc_attr($edit_rep->monthly_target) : ""; ?>">
                    <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="submit_representative" class="button button-primary" 
                   value="<?php echo $editing ? "Temsilciyi Güncelle" : "Müşteri Temsilcisi Ekle"; ?>">
            <?php if ($editing): ?>
                <a href="<?php echo admin_url("admin.php?page=insurance-crm-representatives"); ?>" class="button">İptal</a>
            <?php endif; ?>
        </p>
    </form>
</div>