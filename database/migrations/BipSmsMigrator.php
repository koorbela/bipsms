<?php

namespace BipSmsMigrations;

class BipSmsMigrator
{
    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        // 1. Alap SMS log tábla létrehozása
        $table = $wpdb->prefix .'bip_sms_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            if(!function_exists('dbDelta')) {
                require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            }
            
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `subscriber_id` BIGINT UNSIGNED NULL,
                `phone_number` VARCHAR(20) NULL,
                `message` TEXT NULL,
                `status` VARCHAR(20) DEFAULT 'pending',
                `response` TEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `phone_idx` (`phone_number`),
                INDEX `subscriber_idx` (`subscriber_id`),
                INDEX `status_idx` (`status`)
            ) $charsetCollate;";
            dbDelta($sql);
        }
        
        // 2. SMS kampány tábla létrehozása
        $campaignsTable = $wpdb->prefix . 'fcbip_sms_campaigns';
        if ($wpdb->get_var("SHOW TABLES LIKE '$campaignsTable'") != $campaignsTable) {
            if(!function_exists('dbDelta')) {
                require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            }
            
            $sql = "CREATE TABLE $campaignsTable (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `title` VARCHAR(255) NOT NULL,
                `message_template` TEXT NOT NULL,
                `from_name` VARCHAR(255) NULL,
                `status` VARCHAR(50) DEFAULT 'draft',
                `target_type` VARCHAR(50) DEFAULT 'list',
                `target_lists` TEXT NULL,
                `target_tags` TEXT NULL,
                `target_segment` TEXT NULL,
                `scheduled_at` DATETIME NULL,
                `sending_started_at` DATETIME NULL,
                `sending_completed_at` DATETIME NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `status_idx` (`status`),
                INDEX `target_type_idx` (`target_type`)
            ) $charsetCollate;";
            dbDelta($sql);
        }
        
        // 3. SMS kampánystatisztika tábla létrehozása
        $statsTable = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        if ($wpdb->get_var("SHOW TABLES LIKE '$statsTable'") != $statsTable) {
            if(!function_exists('dbDelta')) {
                require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            }
            
            $sql = "CREATE TABLE $statsTable (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `campaign_id` BIGINT UNSIGNED NOT NULL,
                `subscriber_id` BIGINT UNSIGNED NOT NULL,
                `phone_number` VARCHAR(50) NULL,
                `status` VARCHAR(20) DEFAULT 'pending',
                `sms_id` VARCHAR(255) NULL,
                `reference_id` VARCHAR(255) NULL,
                `error_message` TEXT NULL,
                `sent_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `campaign_idx` (`campaign_id`),
                INDEX `subscriber_idx` (`subscriber_id`),
                INDEX `status_idx` (`status`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {
            // 4. Ha a tábla már létezik, ellenőrizzük a reference_id oszlopot
            $column = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'reference_id'",
                DB_NAME,
                $statsTable
            ));
            
            // Ha nincs reference_id oszlop, hozzáadjuk
            if (empty($column)) {
                $wpdb->query("ALTER TABLE {$statsTable} ADD COLUMN reference_id VARCHAR(255) NULL AFTER sms_id");
            }
        }
    }
}
