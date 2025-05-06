<?php

namespace FluentCrmBipSms;

/**
 * Handle FluentCRM hooks and integration
 */
class FluentCRMHooks
{
    public function init()
    {
        fcbip_debug_log('Registering FluentCRM hooks');
        
        // Ellenőrizzük, hogy az AJAX handler megfelelően regisztrálva van-e
        fcbip_debug_log('wp_ajax_fcbip_send_test_sms_campaign hook registered');
        
        // SMS kampány processzor inicializálása
        add_action('fluentcrm_scheduled_every_minute_tasks', [$this, 'process_scheduled_sms_campaigns']);
        
        // Admin AJAX műveletek
        add_action('wp_ajax_fcbip_preview_sms', [$this, 'ajax_preview_sms']);
        add_action('wp_ajax_fcbip_send_test_sms_campaign', [$this, 'ajax_send_test_sms_campaign']);
        add_action('wp_ajax_fcbip_save_sms_campaign', [$this, 'ajax_save_sms_campaign']);
        
        fcbip_debug_log('Hooks registration completed');
    }

    /**
     * Ütemezett SMS kampányok feldolgozása
     */
    public function process_scheduled_sms_campaigns()
    {
        if (!class_exists('FluentCrmBipSms\\Services\\SmsCampaignProcessor')) {
            return;
        }
        
        $processor = new \FluentCrmBipSms\Services\SmsCampaignProcessor();
        $processor->processScheduledCampaigns();
    }
    
    /**
     * SMS előnézet AJAX handler
     */
    public function ajax_preview_sms()
    {
        // Biztonsági ellenőrzés
        check_ajax_referer('fcbip_sms_nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nincs megfelelő jogosultságod ehhez a művelethez.']);
            return;
        }
        
        // Bemeneti adatok ellenőrzése
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (empty($message)) {
            wp_send_json_error(['message' => 'Az üzenet nem lehet üres.']);
            return;
        }
        
        // Itt implementálhatunk további előnézeti logikát
        // például a változók helyettesítését egy minta adattal
        
        // Egyszerű előnézet visszaadása
        wp_send_json_success([
            'preview' => $message,
            'message' => 'Előnézet betöltve.'
        ]);
    }
    
    /**
     * SMS teszt küldés AJAX handler kampányokhoz
     */
    public function ajax_send_test_sms_campaign() 
    {
        fcbip_debug_log('Test SMS campaign request received');
        
        // Biztonsági ellenőrzés
        if (!current_user_can('manage_options')) {
            fcbip_debug_log('Permission denied for test SMS');
            wp_send_json_error(['message' => 'Permission denied'], 403);
            return;
        }
        
        // Adatok kiolvasása
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        fcbip_debug_log('Test SMS data - Phone: ' . $phone . ', Message: ' . $message);
        
        if (empty($phone) || empty($message)) {
            fcbip_debug_log('Empty phone or message');
            wp_send_json_error(['message' => 'Phone and message are required']);
            return;
        }
        
        // SMS küldési logika
        $sms_service = new \FCBIP_SMS_Service();
        if (!$sms_service->is_configured()) {
            fcbip_debug_log('SMS service not configured');
            wp_send_json_error(['message' => 'SMS service not configured']);
            return;
        }
        
        $result = $sms_service->send_sms($phone, $message);
        fcbip_debug_log('SMS sending result: ' . print_r($result, true));
        
        if ($result['success']) {
            wp_send_json_success(['message' => 'Test SMS sent successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to send SMS: ' . $result['message']]);
        }
        
        wp_die();
    }

    /**
     * Kampány mentése AJAX handler
     */
    public function ajax_save_sms_campaign() 
    {
        // Biztonsági ellenőrzés
        check_ajax_referer('fcbip_sms_nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nincs megfelelő jogosultságod ehhez a művelethez.']);
            return;
        }
        
        // Bemeneti adatok ellenőrzése
        $campaignData = isset($_POST['campaign']) ? $_POST['campaign'] : [];
        
        if (empty($campaignData)) {
            wp_send_json_error(['message' => 'Hiányzó kampány adatok.']);
            return;
        }
        
        // Adatok tisztítása
        $sanitizedData = [
            'title' => isset($campaignData['title']) ? sanitize_text_field($campaignData['title']) : '',
            'status' => 'draft',
            'message_template' => isset($campaignData['message_template']) ? sanitize_textarea_field($campaignData['message_template']) : '',
            'from_name' => isset($campaignData['from_name']) ? sanitize_text_field($campaignData['from_name']) : '',
            'target_type' => isset($campaignData['target_type']) ? sanitize_text_field($campaignData['target_type']) : '',
            'target_lists' => isset($campaignData['target_lists']) ? array_map('intval', $campaignData['target_lists']) : [],
            'target_tags' => isset($campaignData['target_tags']) ? array_map('intval', $campaignData['target_tags']) : [],
            'target_segment' => isset($campaignData['target_segment']) ? sanitize_text_field($campaignData['target_segment']) : ''
        ];
        
        // Kötelező mezők ellenőrzése
        if (empty($sanitizedData['title'])) {
            wp_send_json_error(['message' => 'A kampány címe kötelező mező.']);
            return;
        }
        
        // Kampány mentése
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_campaigns';
        $campaignId = isset($campaignData['id']) ? intval($campaignData['id']) : 0;
        
        if ($campaignId) {
            // Meglévő kampány frissítése
            $result = $wpdb->update(
                $table,
                [
                    'title' => $sanitizedData['title'],
                    'status' => $sanitizedData['status'],
                    'message_template' => $sanitizedData['message_template'],
                    'from_name' => $sanitizedData['from_name'],
                    'target_type' => $sanitizedData['target_type'],
                    'target_lists' => maybe_serialize($sanitizedData['target_lists']),
                    'target_tags' => maybe_serialize($sanitizedData['target_tags']),
                    'target_segment' => $sanitizedData['target_segment'],
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $campaignId],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Új kampány létrehozása
            $result = $wpdb->insert(
                $table,
                [
                    'title' => $sanitizedData['title'],
                    'status' => $sanitizedData['status'],
                    'message_template' => $sanitizedData['message_template'],
                    'from_name' => $sanitizedData['from_name'],
                    'target_type' => $sanitizedData['target_type'],
                    'target_lists' => maybe_serialize($sanitizedData['target_lists']),
                    'target_tags' => maybe_serialize($sanitizedData['target_tags']),
                    'target_segment' => $sanitizedData['target_segment'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            
            $campaignId = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Adatbázis hiba történt a mentés során.']);
            return;
        }
        
        wp_send_json_success([
            'message' => 'Kampány sikeresen elmentve piszkozatként.',
            'campaign_id' => $campaignId
        ]);
    }
}
