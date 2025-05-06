<?php

namespace FluentCrmBipSms\Services;

class SmsScheduler
{
    /**
     * SMS kampány ütemezése a jövőbeli időpontra
     *
     * @param int $campaignId A kampány azonosítója
     * @param string $scheduledAt Időpont MySQL formátumban
     * @return bool Sikeres-e az ütemezés
     */
    public function scheduleCampaign($campaignId, $scheduledAt)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        
        // Ellenőrizzük, hogy érvényes-e az időpont (jelen időnél későbbi)
        $currentTime = current_time('mysql');
        
        if (strtotime($scheduledAt) <= strtotime($currentTime)) {
            fcbip_log('Invalid scheduling time for campaign ID: ' . $campaignId . '. Time must be in the future.');
            return false;
        }
        
        // Kampány státusz frissítése scheduled-ra
        $result = $wpdb->update(
            $table,
            [
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'updated_at' => $currentTime
            ],
            ['id' => $campaignId],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            fcbip_log('Database error while scheduling campaign ID: ' . $campaignId);
            return false;
        }
        
        fcbip_log('Campaign scheduled successfully. ID: ' . $campaignId . ', Scheduled at: ' . $scheduledAt);
        
        // Biztosítsuk, hogy a WordPress cron fut
        $this->ensureWpCronIsRunning();
        
        return true;
    }
    
    /**
     * Kampány azonnali indítása
     *
     * @param int $campaignId A kampány azonosítója
     * @return bool Sikeres-e az indítás
     */
    public function startCampaignNow($campaignId)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        
        // Kampány státusz frissítése working-ra
        $currentTime = current_time('mysql');
        $result = $wpdb->update(
            $table,
            [
                'status' => 'working',
                'scheduled_at' => $currentTime,
                'sending_started_at' => $currentTime,
                'updated_at' => $currentTime
            ],
            ['id' => $campaignId],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            fcbip_log('Database error while starting campaign ID: ' . $campaignId);
            return false;
        }
        
        fcbip_log('Campaign started immediately. ID: ' . $campaignId);
        
        // Kampány indítása
        $processor = new SmsCampaignProcessor();
        $processor->processCampaign($campaignId);
        
        return true;
    }
    
    /**
     * Biztosítja, hogy a WordPress cron fut
     */
    private function ensureWpCronIsRunning()
    {
        // Ellenőrizzük, hogy a cron be van-e kapcsolva
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            fcbip_log('WARNING: WP_CRON is disabled in wp-config.php. Scheduled campaigns may not run automatically.');
            return;
        }
        
        // Ellenőrizzük, hogy a FluentCRM minden perces feladat be van-e regisztrálva
        if (!wp_next_scheduled('fluentcrm_scheduled_every_minute_tasks')) {
            wp_schedule_event(time(), 'minute', 'fluentcrm_scheduled_every_minute_tasks');
            fcbip_log('FluentCRM minute tasks schedule was missing. Re-scheduled.');
        }
        
        // Biztonsági mentési mechanizmus: saját cron esemény beállítása
        if (!wp_next_scheduled('fcbip_process_sms_campaigns')) {
            wp_schedule_event(time(), 'five_minutes', 'fcbip_process_sms_campaigns');
            fcbip_log('Custom SMS campaign processing schedule created.');
        }
    }
}