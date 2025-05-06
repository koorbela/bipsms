<?php

namespace FluentCrmBipSms\Services;

use FluentCrm\App\Models\Subscriber;

class SmsCampaignProcessor
{
    /**
     * Feldolgozza az ütemezett SMS kampányokat
     */
    public function processScheduledCampaigns()
    {
        global $wpdb;
        
        // Aktív kampányok lekérése
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        $currentTime = current_time('mysql');
        
        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} 
                WHERE status = 'scheduled' 
                AND scheduled_at <= %s 
                AND (sending_started_at IS NULL OR sending_started_at = '0000-00-00 00:00:00')",
                $currentTime
            )
        );
        
        if (!$campaigns) {
            return;
        }
        
        fcbip_log('Found ' . count($campaigns) . ' SMS campaigns to process');
        
        foreach ($campaigns as $campaign) {
            // Kampány státusz frissítése
            $wpdb->update(
                $table,
                [
                    'status' => 'working',
                    'sending_started_at' => $currentTime
                ],
                ['id' => $campaign->id]
            );
            
            // Processzáljuk a kampányt
            $this->processCampaign($campaign);
        }
    }
    
    /**
     * Feldolgoz egy SMS kampányt
     * 
     * @param object|int $campaign Kampány objektum vagy ID
     * @return bool|array Feldolgozás eredménye
     */
    public function processCampaign($campaign)
    {
        // Ha csak ID van megadva, lekérjük a kampány adatokat
        if (is_numeric($campaign)) {
            global $wpdb;
            $table = $wpdb->prefix . 'fcbip_sms_campaigns';
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $campaign));
            
            if (!$campaign) {
                fcbip_log('Campaign not found with ID: ' . $campaign);
                return false;
            }
        }
        
        // Előkészítjük a címzetteket
        $subscribers = $this->getSubscribers($campaign);
        
        if (!$subscribers || empty($subscribers)) {
            $this->markCampaignAs($campaign->id, 'completed');
            fcbip_log('No subscribers found for campaign ID: ' . $campaign->id);
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }
        
        $batchSize = apply_filters('fcbip_sms_campaign_batch_size', 20);
        $processedCount = 0;
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($subscribers as $subscriber) {
            $result = $this->sendSms($campaign, $subscriber);
            $processedCount++;
            
            if ($result) {
                $successCount++;
            } else {
                $failedCount++;
            }
            
            if ($processedCount >= $batchSize) {
                // Batch limitet elértük, következő futásnál folytatjuk
                fcbip_log('Batch limit reached for campaign ID: ' . $campaign->id . '. Processed: ' . $processedCount);
                return [
                    'processed' => $processedCount,
                    'success' => $successCount,
                    'failed' => $failedCount,
                    'completed' => false
                ];
            }
        }
        
        // Minden feldolgozva, kampány befejezése
        $this->markCampaignAs($campaign->id, 'completed');
        fcbip_log('Campaign completed. ID: ' . $campaign->id . '. Processed: ' . $processedCount);
        
        return [
            'processed' => $processedCount,
            'success' => $successCount,
            'failed' => $failedCount,
            'completed' => true
        ];
    }
    
    /**
     * Lekéri a kampányhoz tartozó feliratkozókat
     */
    private function getSubscribers($campaign)
    {
        $query = Subscriber::where('status', 'subscribed');
        
        // Lista, címke vagy szegmens alapján szűrünk
        if ($campaign->target_type == 'list' && !empty($campaign->target_lists)) {
            $listIds = maybe_unserialize($campaign->target_lists);
            if ($listIds) {
                $query->whereHas('lists', function ($q) use ($listIds) {
                    $q->whereIn('lists.id', $listIds);
                });
            }
        } else if ($campaign->target_type == 'tag' && !empty($campaign->target_tags)) {
    $tagIds = maybe_unserialize($campaign->target_tags);
    if ($tagIds) {
        $query->whereHas('tags', function ($q) use ($tagIds) {
            $tagModel = new \FluentCrm\App\Models\Tag();
            $tagTable = $tagModel->getTable(); // Ez biztosítja a helyes táblanevet
            $q->whereIn($tagTable.'.id', $tagIds);
        });
    }
} else if ($campaign->target_type == 'segment' && !empty($campaign->target_segment)) {
            // Itt használnánk a FluentCRM szegmens rendszerét
        }
        
        // Csak olyanokat válasszunk, akiknek van telefonszáma
        $query->whereNotNull('phone');
        $query->where('phone', '!=', '');
        
        // Ellenőrizzük, hogy nem küldtünk-e már nekik
        global $wpdb;
        $statsTable = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        
        $sentSubscriberIds = $wpdb->get_col($wpdb->prepare(
            "SELECT subscriber_id FROM {$statsTable} WHERE campaign_id = %d",
            $campaign->id
        ));
        
        if ($sentSubscriberIds) {
            $query->whereNotIn('id', $sentSubscriberIds);
        }
        
        // Maximum feldolgozandó szám
        $query->limit(100);
        
        return $query->get();
    }
    
/**
 * SMS küldése egy feliratkozónak
 */
private function sendSms($campaign, $subscriber)
{
    // Üzenet előkészítése személyre szabással
    $message = $this->parseMessage($campaign->message_template, $subscriber);
    
    global $wpdb;
    $statsTable = $wpdb->prefix . 'fcbip_sms_campaign_stats';
    
    // Stat rekord létrehozása
    $wpdb->insert(
        $statsTable,
        [
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subscriber->id,
            'status' => 'sending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]
    );
    
    $statId = $wpdb->insert_id;
    
    // Használjuk az FCBIP_SMS_Service osztályt az SMS küldéshez
    $sms_service = new \FCBIP_SMS_Service();
    if (!$sms_service->is_configured()) {
        fcbip_log('SMS service not configured for campaign: ' . $campaign->id);
        
        $wpdb->update(
            $statsTable,
            [
                'status' => 'failed',
                'error_message' => 'SMS service not configured',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $statId]
        );
        
        return false;
    }
    
    // A küldő név beállítása
    $senderName = !empty($campaign->from_name) ? $campaign->from_name : get_option('fcbip_default_sender');
    
    // A telefonszám formázása és küldés az SMS szolgáltatással
    $result = $sms_service->send_sms($subscriber->phone, $message, $senderName, null, $campaign->id, $subscriber->id);
    fcbip_log('SMS sending result for campaign ' . $campaign->id . ': ' . print_r($result, true));
    
    if ($result['success']) {
        // Sikeres küldés
       $wpdb->update(
    $statsTable,
    [
        'status' => 'sent',
        'sms_id' => isset($result['data']['message_id']) ? $result['data']['message_id'] : '',
        'reference_id' => isset($result['reference_id']) ? $result['reference_id'] : $campaign->id . '-' . $subscriber->id,
        'sent_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ],
    ['id' => $statId]
);
        
        return true;
    } else {
        // API hiba
        $error = isset($result['message']) ? $result['message'] : 'Unknown API error';
        fcbip_log('API Error for campaign ' . $campaign->id . ': ' . $error);
        
        $wpdb->update(
            $statsTable,
            [
                'status' => 'failed',
                'error_message' => $error,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $statId]
        );
        
        return false;
    }
}
    
/**
 * Üzenet személyre szabása
 */
private function parseMessage($template, $subscriber)
{
    // Közvetlenül használjuk a Parser osztályt a smartcode-ok feldolgozására
    return \FluentCrm\App\Services\Libs\Parser\Parser::parse($template, $subscriber);
}
    
    /**
     * Kampány státusz frissítése
     */
    private function markCampaignAs($campaignId, $status)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        
        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];
        
        if ($status == 'completed') {
            $data['sending_completed_at'] = current_time('mysql');
        }
        
        $wpdb->update($table, $data, ['id' => $campaignId]);
    }
}
