<?php

namespace FluentCrmBipSms\Models;

class SmsCampaign
{
    private $table = 'fcbip_sms_campaigns';
    
    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . $this->table;
    }
    
    /**
     * Létrehozza a szükséges adatbázis táblákat
     */
    public static function createTables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $sql = "CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                title varchar(192) NOT NULL,
                status varchar(50) NOT NULL DEFAULT 'draft',
                message_template text,
                from_name varchar(192),
                scheduled_at timestamp NULL,
                sending_started_at timestamp NULL,
                sending_completed_at timestamp NULL,
                target_type varchar(50) DEFAULT 'list',
                target_lists text,
                target_tags text,
                target_segment text,
                created_by bigint(20) unsigned,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        $statsTable = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$statsTable'") != $statsTable) {
            $sql = "CREATE TABLE $statsTable (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                campaign_id bigint(20) unsigned NOT NULL,
                subscriber_id bigint(20) unsigned NOT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                error_message text,
                sms_id varchar(255),
                sent_at timestamp NULL,
                created_at timestamp NULL,
                updated_at timestamp NULL,
                PRIMARY KEY  (id),
                KEY campaign_id (campaign_id),
                KEY subscriber_id (subscriber_id)
            ) $charset_collate;";
            
            dbDelta($sql);
        }
    }
    
    // További CRUD műveleteket is implementálnánk itt
}
