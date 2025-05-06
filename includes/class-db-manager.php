<?php

/**
 * Adatbázis kezelő osztály
 */
class FCBIP_DB_Manager {
    
    /**
     * Adatbázis táblák létrehozása vagy frissítése
     */
    public static function createOrUpdateTables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // SMS kampányok tábla
        $campaigns_table = $wpdb->prefix . 'fcbip_sms_campaigns';
        $campaigns_sql = "CREATE TABLE {$campaigns_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            message_template text NOT NULL,
            sender_id varchar(100) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'draft',
            scheduled_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            target_type varchar(50) NOT NULL,
            target_data text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";
        
        // SMS feliratkozók tábla
        $subscribers_table = $wpdb->prefix . 'fcbip_sms_subscribers';
        $subscribers_sql = "CREATE TABLE {$subscribers_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            phone varchar(50) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            subscriber_data text NOT NULL,
            message_id varchar(255) DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_status (campaign_id, status)
        ) {$charset_collate};";
        
        // SMS üzenetek tábla
        $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
        $messages_sql = "CREATE TABLE {$messages_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            message_id varchar(255) DEFAULT NULL,
            campaign_id bigint(20) unsigned DEFAULT NULL,
            phone varchar(50) NOT NULL,
            message text NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            cost float DEFAULT 0,
            sent_at datetime DEFAULT NULL,
            delivered_at datetime DEFAULT NULL,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY message_id (message_id),
            KEY campaign_id (campaign_id),
            KEY status (status)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($campaigns_sql);
        dbDelta($subscribers_sql);
        dbDelta($messages_sql);
    }
    
    /**
     * Ellenőrzi és javítja az adatbázis inkonzisztenciákat
     */
    public static function checkAndRepairInconsistencies() {
        global $wpdb;
        
        $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
        $campaigns_table = $wpdb->prefix . 'fcbip_sms_campaigns';
        
        // 1. Státusz és költség inkonzisztenciák javítása
        $results = $wpdb->get_results("
            SELECT id, message_id, status, cost, metadata 
            FROM {$messages_table} 
            WHERE message_id IS NOT NULL AND message_id != ''
        ");
        
        $updated = 0;
        
        foreach ($results as $message) {
            $updateData = [];
            $metadata = json_decode($message->metadata, true);
            
            // Költség frissítése a metaadatokból, ha hiányzik
            if (!$message->cost && isset($metadata['cost']) && is_numeric($metadata['cost'])) {
                $updateData['cost'] = floatval($metadata['cost']);
            }
            
            // Státusz helyreállítása, ha "processing" maradt, de küldési idő már régebbi mint 1 óra
            if ($message->status === 'processing') {
                $sent_at = $wpdb->get_var($wpdb->prepare(
                    "SELECT sent_at FROM {$messages_table} WHERE id = %d",
                    $message->id
                ));
                
                if ($sent_at && (strtotime($sent_at) < (time() - 3600))) {
                    // 1 óránál régebbi feldolgozás alatt lévő SMS-t "sent"-nek jelölünk
                    $updateData['status'] = 'sent';
                }
            }
            
            // Frissítés, ha van mit
            if (!empty($updateData)) {
                $wpdb->update(
                    $messages_table,
                    $updateData,
                    ['id' => $message->id],
                    null,
                    ['%d']
                );
                $updated++;
            }
        }
        
        // 2. Kampány statisztikák frissítése
        $campaigns = $wpdb->get_results("
            SELECT id FROM {$campaigns_table}
        ");
        
        foreach ($campaigns as $campaign) {
            // Kampány statisztikák újraszámolása
            $stats = self::recalculateCampaignStats($campaign->id);
            
            // Kampány státusz ellenőrzése
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$campaigns_table} WHERE id = %d",
                $campaign->id
            ));
            
            // Ha a kampány "processing" státuszban van, de nincs több pending üzenet,
            // akkor állítsuk "completed"-re
            if ($status === 'processing' && $stats['pending'] === 0) {
                $wpdb->update(
                    $campaigns_table,
                    [
                        'status' => 'completed',
                        'completed_at' => current_time('mysql')
                    ],
                    ['id' => $campaign->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }
        }
        
        return [
            'messages_updated' => $updated,
            'campaigns_checked' => count($campaigns)
        ];
    }
    
    /**
     * Újraszámolja egy kampány statisztikáit
     */
    public static function recalculateCampaignStats($campaignId) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
        
        // Számoljuk az üzeneteket státusz szerint
        $stats = [
            'total' => 0,
            'sent' => 0,
            'delivered' => 0,
            'failed' => 0,
            'pending' => 0,
            'processing' => 0,
            'total_cost' => 0
        ];
        
        // Összesítés státusz szerint
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count 
             FROM {$messages_table} 
             WHERE campaign_id = %d 
             GROUP BY status",
            $campaignId
        ));
        
        foreach ($results as $row) {
            $stats[$row->status] = intval($row->count);
            $stats['total'] += intval($row->count);
        }
        
        // Költség összesítése
        $total_cost = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(cost) 
             FROM {$messages_table} 
             WHERE campaign_id = %d AND cost > 0",
            $campaignId
        ));
        
        $stats['total_cost'] = floatval($total_cost);
        
        return $stats;
    }
}