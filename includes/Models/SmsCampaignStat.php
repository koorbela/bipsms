<?php
namespace FluentCrmBipSms\Models;

class SmsCampaignStat
{
    private $table = 'fcbip_sms_campaign_stats';
    private $messages_table = 'fcbip_sms_messages';
    
    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . $this->table;
        $this->messages_table = $wpdb->prefix . $this->messages_table;
    }
    
    /**
     * Kampány statisztikát készít
     * Az új táblát használja, ha létezik, különben a régit
     */
    public function getCampaignStats($campaignId)
    {
        global $wpdb;
        
        // Alapértelmezett statisztikák
        $stats = [
            'total' => 0,
            'sent' => 0,
            'delivered' => 0,
            'failed' => 0,
            'pending' => 0,
            'processing' => 0,
            'total_cost' => 0
        ];
        
        // Ellenőrizzük, hogy létezik-e az új üzenetek tábla
        $new_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->messages_table}'") == $this->messages_table;
        
        if ($new_table_exists) {
            // Az új táblából lekérjük az adatokat
            fcbip_log("Fetching campaign stats from new table for campaign ID: {$campaignId}");
            
            // Összes üzenet száma
            $stats['total'] = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->messages_table} WHERE campaign_id = %d",
                $campaignId
            ));
            
            // Státuszok lekérdezése
            $statusCounts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM {$this->messages_table} 
                WHERE campaign_id = %d GROUP BY status",
                $campaignId
            ));
            
            if ($statusCounts) {
                foreach ($statusCounts as $row) {
                    if ($row->status === 'sent') {
                        $stats['sent'] = (int)$row->count;
                    } elseif ($row->status === 'delivered') {
                        $stats['delivered'] = (int)$row->count;
                        // A kézbesített üzeneteket is számoljuk az elküldöttekhez
                        $stats['sent'] += (int)$row->count;
                    } elseif ($row->status === 'failed' || $row->status === 'error') {
                        $stats['failed'] += (int)$row->count;
                    } elseif ($row->status === 'pending') {
                        $stats['pending'] = (int)$row->count;
                    } elseif ($row->status === 'processing') {
                        $stats['processing'] = (int)$row->count;
                    }
                }
            }
            
            // Költség összesítése
            $stats['total_cost'] = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT SUM(cost) FROM {$this->messages_table} WHERE campaign_id = %d",
                $campaignId
            ));
            
            // Ha null-t kaptunk, állítsuk 0-ra
            if ($stats['total_cost'] === null) {
                $stats['total_cost'] = 0;
            }
        } else {
            // Régi tábla használata
            fcbip_log("Using old stats table for campaign ID: {$campaignId}");
            
            // Összes üzenet száma
            $stats['total'] = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE campaign_id = %d",
                $campaignId
            ));
            
            // Státuszok lekérdezése
            $statusCounts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM {$this->table} 
                WHERE campaign_id = %d GROUP BY status",
                $campaignId
            ));
            
            if ($statusCounts) {
                foreach ($statusCounts as $row) {
                    if ($row->status === 'sent') {
                        $stats['sent'] = (int)$row->count;
                    } elseif ($row->status === 'delivered') {
                        $stats['delivered'] = (int)$row->count;
                        // A kézbesített üzeneteket is számoljuk az elküldöttekhez
                        $stats['sent'] += (int)$row->count;
                    } elseif ($row->status === 'failed' || $row->status === 'error') {
                        $stats['failed'] += (int)$row->count;
                    } elseif ($row->status === 'pending') {
                        $stats['pending'] = (int)$row->count;
                    } elseif ($row->status === 'processing' || $row->status === 'accepted') {
                        $stats['processing'] += (int)$row->count;
                    }
                }
            }
            
            // Költség összesítése - ellenőrizzük, hogy van-e price oszlop
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table}");
            if (in_array('price', $columns)) {
                $stats['total_cost'] = (float)$wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(price) FROM {$this->table} WHERE campaign_id = %d",
                    $campaignId
                ));
                
                // Ha null-t kaptunk, állítsuk 0-ra
                if ($stats['total_cost'] === null) {
                    $stats['total_cost'] = 0;
                }
            }
        }
        
        // Debug log
        fcbip_log("Campaign stats for ID {$campaignId}: " . print_r($stats, true));
        
        return $stats;
    }
}