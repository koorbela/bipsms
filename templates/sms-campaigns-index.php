<?php
// Biztonsági ellenőrzés
if (!defined('ABSPATH')) {
    exit;
}

wp_enqueue_style('fcbip-sms-campaign');

global $wpdb;
$table = $wpdb->prefix . 'fcbip_sms_campaigns';

// Lekérjük a kampányokat
$campaigns = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

// Ellenőrizzük, hogy a BIP SMS API be van-e állítva
$apiKey = get_option('fcbip_api_key');
$apiUser = get_option('fcbip_api_user');
$defaultSender = get_option('fcbip_default_sender');

$apiConfigured = !empty($apiKey) && !empty($apiUser) && !empty($defaultSender);
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('SMS Kampányok', 'fluentcrm-bip-sms'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=fcbip-sms-campaigns&action=create'); ?>" class="button button-primary">
        <?php _e('Új kampány', 'fluentcrm-bip-sms'); ?>
    </a>

    
    <?php if (!$apiConfigured): ?>
    <div class="notice notice-warning">
        <p>
            <?php _e('Kérjük, konfigurálja a BIP SMS API beállításait a kampányok létrehozása előtt.', 'fluentcrm-bip-sms'); ?>
            <a href="<?php echo admin_url('options-general.php?page=fcbip-settings'); ?>"><?php _e('Ugrás a beállításokhoz', 'fluentcrm-bip-sms'); ?></a>
        </p>
    </div>
    <?php endif; ?>
    
    <div class="fcbip-campaign-list">
        <?php if (empty($campaigns)): ?>
            <div class="notice notice-info">
                <p><?php _e('Nem található SMS kampány. Hozza létre az első kampányát!', 'fluentcrm-bip-sms'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Kampány neve', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Létrehozva', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Állapot', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Elküldve/Összes', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Kézbesítve', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Költség', 'fluentcrm-bip-sms'); ?></th>
                        <th><?php _e('Műveletek', 'fluentcrm-bip-sms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $campaign): 
                        $campaignId = $campaign->id;
                        
                        // Ellenőrizzük, hogy van-e új SMS üzenetek tábla és DB Manager
                        $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
                        $messages_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
                        
                        // Kampány statisztikák lekérése a megfelelő módszerrel
                        if ($messages_table_exists && class_exists('FCBIP_DB_Manager')) {
                            // Új rendszer: FCBIP_DB_Manager használata
                            $stats = FCBIP_DB_Manager::recalculateCampaignStats($campaignId);
                        } else {
                            // Régi rendszer: SmsCampaignStat használata
                            $statsModel = new FluentCrmBipSms\Models\SmsCampaignStat();
                            $stats = $statsModel->getCampaignStats($campaignId);
                            
                            // Költségadatok lekérése - régi módszer
                            $stats['total_cost'] = 0;
                            $metrics_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
                            
                            // Ellenőrizzük, hogy létezik-e a price oszlop
                            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$metrics_table}");
                            $has_price_column = in_array('price', $columns);
                            
                            if ($has_price_column) {
                                $total_cost = $wpdb->get_var($wpdb->prepare(
                                    "SELECT SUM(price) FROM {$metrics_table} WHERE campaign_id = %d",
                                    $campaignId
                                ));
                                $stats['total_cost'] = $total_cost ? floatval($total_cost) : 0;
                            }
                        }
                        
                        // Ellenőrizzük, hogy van-e BÁRMILYEN költség adat, még ha
                        // részleges is
                        $costDisplay = ($stats['total_cost'] > 0) 
                            ? number_format($stats['total_cost'], 2) . ' Ft' 
                            : '<span class="fcbip-pending-cost">Feldolgozás alatt...</span>';
                        
                        // Státusz formázása
                        $statusClass = '';
                        
                        if ($campaign->status === 'completed') {
                            $statusClass = 'status-completed';
                            $statusLabel = __('Befejezett', 'fluentcrm-bip-sms');
                        } elseif ($campaign->status === 'scheduled') {
                            $statusClass = 'status-scheduled';
                            $statusLabel = __('Ütemezett', 'fluentcrm-bip-sms');
                        } elseif ($campaign->status === 'processing' || $campaign->status === 'working') {
                            $statusClass = 'status-processing';
                            $statusLabel = __('Feldolgozás alatt', 'fluentcrm-bip-sms');
                        } elseif ($campaign->status === 'draft') {
                            $statusClass = 'status-draft';
                            $statusLabel = __('Piszkozat', 'fluentcrm-bip-sms');
                        } else {
                            $statusLabel = ucfirst($campaign->status);
                        }
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=fcbip-sms-campaigns&action=edit&id=' . $campaignId); ?>">
                                <strong><?php echo esc_html($campaign->title); ?></strong>
                            </a>
                        </td>
                        <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($campaign->created_at)); ?></td>
                        <td><span class="fcbip-status <?php echo esc_attr($statusClass); ?>"><?php echo esc_html($statusLabel); ?></span></td>
                        <td><span class="fcbip-count"><?php echo esc_html($stats['sent']); ?>/<?php echo esc_html($stats['total']); ?></span></td>
                        <td><span class="fcbip-count"><?php echo esc_html($stats['delivered']); ?>/<?php echo esc_html($stats['total']); ?></span></td>
                        <td><?php echo $costDisplay; ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=fcbip-sms-campaigns&action=report&id=' . $campaignId); ?>" class="button button-small">
                                <?php _e('Jelentés', 'fluentcrm-bip-sms'); ?>
                            </a>
                            
                            <?php if (in_array($campaign->status, ['draft', 'scheduled'])): ?>
                                <a href="<?php echo admin_url('admin.php?page=fcbip-sms-campaigns&action=edit&id=' . $campaignId); ?>" class="button button-small">
                                    <?php _e('Szerkesztés', 'fluentcrm-bip-sms'); ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (in_array($campaign->status, ['draft', 'scheduled', 'completed'])): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fcbip-sms-campaigns&action=delete&id=' . $campaignId), 'fcbip_delete_campaign'); ?>" class="button button-small" onclick="return confirm('<?php _e('Biztosan törölni szeretné ezt a kampányt?', 'fluentcrm-bip-sms'); ?>');">
                                    <?php _e('Törlés', 'fluentcrm-bip-sms'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
/* Kampány lista stílusok */
.fcbip-status {
    font-weight: 500;
}

.status-completed {
    color: #46b450;
}

.status-scheduled {
    color: #2271b1;
}

.status-processing, 
.status-working {
    color: #f0c33c;
}

.status-draft {
    color: #72aee6;
}

.fcbip-count {
    display: inline-block;
}

.fcbip-pending-cost {
    color: #888;
    font-style: italic;
}
</style>