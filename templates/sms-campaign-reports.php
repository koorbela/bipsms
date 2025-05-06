<?php

// Biztosítsuk, hogy közvetlenül ne legyen hozzáférhető
if (!defined('ABSPATH')) {
    exit;
}

// Kampány ID lekérése
$campaign_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$campaign_id) {
    echo '<div class="notice notice-error"><p>' . __('Érvénytelen kampány azonosító', 'fluentcrm-bip-sms') . '</p></div>';
    return;
}

// Kampány adatok lekérése
global $wpdb;
$campaigns_table = $wpdb->prefix . 'fcbip_sms_campaigns';
$campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns_table} WHERE id = %d", $campaign_id));

if (!$campaign) {
    echo '<div class="notice notice-error"><p>' . __('A kampány nem található', 'fluentcrm-bip-sms') . '</p></div>';
    return;
}

// Ellenőrizzük, hogy létezik-e az új SMS üzenetek tábla
$messages_table = $wpdb->prefix . 'fcbip_sms_messages';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;

// Ha létezik az új tábla, használjuk azt, egyébként a régit
if ($table_exists) {
    // Új rendszer: fcbip_sms_messages tábla használata
    // Kampány statisztikák lekérése az új DbManager osztály segítségével
    if (class_exists('FCBIP_DB_Manager')) {
        $stats = FCBIP_DB_Manager::recalculateCampaignStats($campaign_id);
    } else {
        // Ha nincs meg az osztály, manuálisan számoljuk az adatokat
        $stats = [
            'total' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d", $campaign_id)),
            'sent' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d AND status = 'sent'", $campaign_id)),
            'delivered' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d AND status = 'delivered'", $campaign_id)),
            'failed' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d AND status IN ('failed', 'error')", $campaign_id)),
            'pending' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d AND status = 'pending'", $campaign_id)),
            'processing' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d AND status = 'processing'", $campaign_id)),
            'total_cost' => $wpdb->get_var($wpdb->prepare("SELECT SUM(cost) FROM {$messages_table} WHERE campaign_id = %d", $campaign_id))
        ];
        
        // NULL érték ellenőrzése
        $stats['total_cost'] = $stats['total_cost'] ? floatval($stats['total_cost']) : 0;
    }
    
    // Kézbesítési arány kiszámítása
    $stats['delivery_rate'] = ($stats['total'] > 0) ? round(($stats['delivered'] / $stats['total']) * 100, 2) : 0;
    
    // Átlagos költség kiszámítása
    $stats['avg_cost'] = ($stats['total'] > 0 && $stats['total_cost'] > 0) ? $stats['total_cost'] / $stats['total'] : 0;
    
    // Utolsó 20 üzenet lekérése
    $recentMessages = $wpdb->get_results($wpdb->prepare(
        "SELECT m.*, 
         s.email, s.first_name, s.last_name, CONCAT(IFNULL(s.first_name, ''), ' ', IFNULL(s.last_name, '')) as subscriber_name,
         s.id as subscriber_id
         FROM {$messages_table} m
         LEFT JOIN {$wpdb->prefix}fc_subscribers s ON m.phone = s.phone 
         WHERE m.campaign_id = %d 
         ORDER BY m.id DESC LIMIT 20",
        $campaign_id
    ));
} else {
    // Régi rendszer: fcbip_sms_campaign_stats tábla használata
    $stats_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
    
    // Ellenőrizzük, hogy léteznek-e az oszlopok
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$stats_table}");
    $has_phone_column = in_array('phone_number', $columns);
    $has_price_column = in_array('price', $columns);
    
    // Statisztikák lekérése
    $stats = [
        'total' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stats_table} WHERE campaign_id = %d", $campaign_id)),
        'delivered' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stats_table} WHERE campaign_id = %d AND status = 'delivered'", $campaign_id)),
        'failed' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stats_table} WHERE campaign_id = %d AND status IN ('failed', 'error')", $campaign_id)),
        'pending' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$stats_table} WHERE campaign_id = %d AND status NOT IN ('delivered', 'failed', 'error')", $campaign_id)),
        'processing' => 0,
        'sent' => 0
    ];
    
    // Sent értéket számoljuk a delivered alapján, mivel ez a régi rendszerben nem volt külön
    $stats['sent'] = $stats['delivered'];
    
    // Költség adatok lekérése
    $stats['total_cost'] = 0;
    $stats['avg_cost'] = 0;
    if ($has_price_column) {
        $stats['total_cost'] = $wpdb->get_var($wpdb->prepare("SELECT SUM(price) FROM {$stats_table} WHERE campaign_id = %d", $campaign_id));
        if ($stats['total'] > 0 && $stats['total_cost'] > 0) {
            $stats['avg_cost'] = $stats['total_cost'] / $stats['total'];
        }
    }
    
    // Kézbesítési arány kiszámítása
    $stats['delivery_rate'] = ($stats['total'] > 0) ? round(($stats['delivered'] / $stats['total']) * 100, 2) : 0;
    
    // Utolsó 20 üzenet lekérése
    $recentMessages = $wpdb->get_results($wpdb->prepare(
        "SELECT st.*, " . 
        ($has_phone_column ? "st.phone_number as phone, " : "'' AS phone, ") . 
        ($has_price_column ? "st.price as cost, " : "0 AS cost, ") . 
        "s.email, s.first_name, s.last_name, CONCAT(IFNULL(s.first_name, ''), ' ', IFNULL(s.last_name, '')) as subscriber_name 
         FROM {$stats_table} st
         LEFT JOIN {$wpdb->prefix}fc_subscribers s ON st.subscriber_id = s.id
         WHERE st.campaign_id = %d 
         ORDER BY st.id DESC LIMIT 20",
        $campaign_id
    ));
    
    // Státusz és hibaüzenet átalakítása a megfelelő formátumra
    foreach ($recentMessages as $message) {
        // Státusz átalakítása az új formátumra
        if (!property_exists($message, 'status')) {
            $message->status = 'pending';
        }
        
        // Hibaüzenet
        if (!property_exists($message, 'error_message')) {
            $message->error_message = '';
        }
        
        // Metaadatok
        if (!property_exists($message, 'metadata')) {
            $message->metadata = '';
        }
    }
}

// AJAX figyeléshez nonce létrehozása
$monitor_nonce = wp_create_nonce('fcbip_monitor_nonce');

?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html($campaign->title); ?> - <?php _e('Kampányjelentés', 'fluentcrm-bip-sms'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=fcbip-sms-campaigns'); ?>" class="page-title-action">
        <?php _e('Vissza a kampányokhoz', 'fluentcrm-bip-sms'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <!-- Kampány monitorozás státusz jelző -->
    <?php if ($campaign->status === 'processing'): ?>
    <div id="fcbip-campaign-monitor" class="notice notice-info">
        <p id="fcbip-monitoring-status"><?php _e('Kampány figyelése...', 'fluentcrm-bip-sms'); ?></p>
    </div>
    <input type="hidden" id="fcbip-campaign-id" value="<?php echo $campaign_id; ?>">
    <input type="hidden" id="fcbip_monitor_nonce" value="<?php echo $monitor_nonce; ?>">
    <?php endif; ?>
    
    <div class="fcbip-statistics-cards">
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Összes SMS', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-total"><?php echo esc_html($stats['total']); ?></div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Kézbesítve', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-delivered"><?php echo esc_html($stats['delivered']); ?></div>
            <div class="fcbip-stat-subtitle"><?php echo esc_html($stats['delivered']); ?>/<?php echo esc_html($stats['total']); ?></div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Elküldve', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-sent"><?php echo esc_html($stats['sent']); ?></div>
            <div class="fcbip-stat-subtitle"><?php echo esc_html($stats['sent']); ?>/<?php echo esc_html($stats['total']); ?></div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Feldolgozás alatt', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-processing"><?php echo esc_html($stats['processing']); ?></div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Függőben', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-pending"><?php echo esc_html($stats['pending']); ?></div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Sikertelen', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-failed"><?php echo esc_html($stats['failed']); ?></div>
        </div>
        
        <div class="fcbip-stat-card fcbip-cost-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Összes költség', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value fcbip-stat-count-cost"><?php echo number_format($stats['total_cost'], 2); ?> Ft</div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Átlagos költség/SMS', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value"><?php echo number_format($stats['avg_cost'], 2); ?> Ft</div>
        </div>
        
        <div class="fcbip-stat-card">
            <div class="fcbip-stat-title"><?php echo esc_html__('Kézbesítési arány', 'fluentcrm-bip-sms'); ?></div>
            <div class="fcbip-stat-value"><?php echo esc_html($stats['delivery_rate']); ?>%</div>
        </div>
    </div>
    
    <div class="postbox">
        <h2 class="hndle"><span><?php _e('Kampány részletei', 'fluentcrm-bip-sms'); ?></span></h2>
        <div class="inside">
            <table class="widefat">
                <tr>
                    <th style="width: 200px;"><?php _e('Kampány neve', 'fluentcrm-bip-sms'); ?></th>
                    <td><?php echo esc_html($campaign->title); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Üzenet szövege', 'fluentcrm-bip-sms'); ?></th>
                    <td><?php echo esc_html($campaign->message_template); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Státusz', 'fluentcrm-bip-sms'); ?></th>
                    <td>
                        <?php
                        $statusClass = '';
                        $statusText = '';
                        
                        switch ($campaign->status) {
                            case 'completed':
                                $statusText = __('Befejezve', 'fluentcrm-bip-sms');
                                $statusClass = 'fcbip-status-delivered';
                                break;
                            case 'processing':
                                $statusText = __('Feldolgozás alatt', 'fluentcrm-bip-sms');
                                $statusClass = 'fcbip-status-pending';
                                break;
                            case 'scheduled':
                                $statusText = __('Ütemezve', 'fluentcrm-bip-sms');
                                $statusClass = 'fcbip-status-scheduled';
                                break;
                            case 'draft':
                                $statusText = __('Piszkozat', 'fluentcrm-bip-sms');
                                $statusClass = 'fcbip-status-draft';
                                break;
                            default:
                                $statusText = $campaign->status;
                        }
                        ?>
                        <span class="<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Létrehozva', 'fluentcrm-bip-sms'); ?></th>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($campaign->created_at)); ?></td>
                </tr>
                <?php if (!empty($campaign->scheduled_at)): ?>
                <tr>
                    <th><?php _e('Ütemezve', 'fluentcrm-bip-sms'); ?></th>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($campaign->scheduled_at)); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($campaign->completed_at)): ?>
                <tr>
                    <th><?php _e('Befejezve', 'fluentcrm-bip-sms'); ?></th>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($campaign->completed_at)); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="fcbip-report-table-wrapper">
        <h3><?php echo esc_html__('Küldési részletek', 'fluentcrm-bip-sms'); ?></h3>
        
        <table class="wp-list-table widefat fixed striped" id="fcbip-messages-table">
            <thead>
                <tr>
                    <th><?php _e('Feliratkozó', 'fluentcrm-bip-sms'); ?></th>
                    <th><?php _e('Telefonszám', 'fluentcrm-bip-sms'); ?></th>
                    <th><?php _e('Státusz', 'fluentcrm-bip-sms'); ?></th>
                    <th><?php _e('Ár', 'fluentcrm-bip-sms'); ?></th>
                    <th><?php _e('Idő', 'fluentcrm-bip-sms'); ?></th>
                    <th><?php _e('Hibaüzenet', 'fluentcrm-bip-sms'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentMessages): ?>
                    <?php foreach ($recentMessages as $message): 
                        // Státusz formázása
                        $statusClass = '';
                        switch ($message->status) {
                            case 'delivered': 
                                $statusText = __('Kézbesítve', 'fluentcrm-bip-sms');
                                $statusClass = 'message-status status-delivered fcbip-status-delivered'; 
                                break;
                            case 'sent': 
                                $statusText = __('Elküldve', 'fluentcrm-bip-sms');
                                $statusClass = 'message-status status-sent fcbip-status-sent'; 
                                break;
                            case 'failed': 
                            case 'error': 
                                $statusText = __('Sikertelen', 'fluentcrm-bip-sms');
                                $statusClass = 'message-status status-failed fcbip-status-failed'; 
                                break;
                            case 'processing':
                                $statusText = __('Feldolgozás alatt', 'fluentcrm-bip-sms');
                                $statusClass = 'message-status status-processing fcbip-status-pending';
                                break;
                            default: 
                                $statusText = __('Függőben', 'fluentcrm-bip-sms');
                                $statusClass = 'message-status status-pending fcbip-status-pending';
                        }
                        
                        // Költség formázása
                        $costDisplay = (isset($message->cost) && $message->cost > 0) 
                            ? number_format($message->cost, 2) . ' Ft' 
                            : '—';
                            
                        // Üzenet ID lekérése (ha van)
                        $message_id = isset($message->message_id) ? $message->message_id : '';
                        
                        // Feliratkozó hash megszerzése
                        $subscriberHash = '';
                        if (isset($message->subscriber_id) && $message->subscriber_id) {
                            $subscriber = \FluentCrm\App\Models\Subscriber::where('id', $message->subscriber_id)->first();
                            if ($subscriber && method_exists($subscriber, 'getHash')) {
                                $subscriberHash = $subscriber->getHash();
                            }
                        }
                    ?>
                        <tr data-message-id="<?php echo esc_attr($message_id); ?>">
                            <td>
                                <?php if (!empty($message->subscriber_name)): ?>
                                    <?php echo esc_html($message->subscriber_name); ?> 
                                    <br>
                                    <?php if ($subscriberHash): ?>
                                        <a href="<?php echo admin_url('admin.php?page=fluentcrm-admin&route=subscriber/' . $subscriberHash); ?>"><?php echo esc_html__('Megtekintés', 'fluentcrm-bip-sms'); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html__('Adatlap nem elérhető', 'fluentcrm-bip-sms'); ?>
                                    <?php endif; ?>
                                    <br>
                                    <?php if (!empty($message->email)): ?>
                                        <?php echo esc_html($message->email); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo esc_html__('Ismeretlen előfizető', 'fluentcrm-bip-sms'); ?> 
                                    <?php if (isset($message->subscriber_id) && $message->subscriber_id): ?>
                                        (ID: <?php echo esc_html($message->subscriber_id); ?>)
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(isset($message->phone) ? $message->phone : (isset($message->phone_number) ? $message->phone_number : '—')); ?></td>
                            <td>
                                <span class="<?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                            </td>
                            <td class="message-cost"><?php echo $costDisplay; ?></td>
                            <td><?php 
                                $time_field = isset($message->sent_at) ? $message->sent_at : (isset($message->created_at) ? $message->created_at : current_time('mysql'));
                                echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($time_field)); 
                            ?></td>
                            <td><?php echo esc_html(isset($message->error_message) ? $message->error_message : '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6"><?php echo esc_html__('Nincsenek megjeleníthető adatok', 'fluentcrm-bip-sms'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* Megtartjuk a korábbi stílusokat, de kiegészítjük az újakkal */
.fcbip-statistics-cards {
    display: flex;
    flex-wrap: wrap;
    margin: 20px 0;
    gap: 15px;
}

.fcbip-stat-card {
    background: #fff;
    border: 1px solid #e2e4e7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 15px;
    min-width: 150px;
    text-align: center;
    border-radius: 4px;
}

.fcbip-stat-title {
    font-size: 14px;
    color: #636363;
    margin-bottom: 10px;
}

.fcbip-stat-value {
    font-size: 24px;
    font-weight: 600;
    color: #333;
}

.fcbip-stat-subtitle {
    font-size: 13px;
    color: #888;
    margin-top: 5px;
}

.fcbip-report-table-wrapper {
    margin-top: 30px;
}

.fcbip-status-delivered, .status-delivered {
    color: #46b450;
    font-weight: 500;
}

.fcbip-status-sent, .status-sent {
    color: #0073aa;
    font-weight: 500;
}

.fcbip-status-failed, .status-failed, .status-error {
    color: #dc3232;
    font-weight: 500;
}

.fcbip-status-pending, .status-pending, .status-processing {
    color: #ffb900;
    font-weight: 500;
}

.fcbip-status-scheduled, .fcbip-status-draft {
    color: #888;
    font-weight: 500;
}

#fcbip-campaign-monitor {
    padding: 10px 15px;
}
</style>