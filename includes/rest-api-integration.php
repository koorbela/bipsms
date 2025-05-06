<?php

// FluentCRM REST API integration for BIP SMS callbacks

// Várjunk a WordPress init event-re
add_action('init', function() {
    // Ellenőrizzük, hogy a FluentCRM be van-e töltve
    if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
        return;
    }
    
    // Naplózzuk, hogy megpróbáljuk regisztrálni a végpontot
    if (function_exists('fcbip_log')) {
        fcbip_log('Attempting to register FluentCRM API callback endpoint');
    }
    
    try {
        // REST API registrálása - FONTOS: v2 az egységes verzió
        add_action('rest_api_init', function() {
            register_rest_route('fluent-crm/v2', '/bip-sms-callback', [
                'methods'  => ['GET', 'POST'],
                'callback' => 'fcbip_handle_fluentcrm_sms_callback',
                'permission_callback' => '__return_true'
            ]);
            
            if (function_exists('fcbip_log')) {
                fcbip_log('REST API endpoint registered for bip-sms-callback');
            }
        });
    } catch (Exception $e) {
        if (function_exists('fcbip_log')) {
            fcbip_log('Error registering REST API endpoint: ' . $e->getMessage());
        }
    }
});

/**
 * SMS Callback kezelése a FluentCRM API-n keresztül
 */
function fcbip_handle_fluentcrm_sms_callback($request) {
    if (function_exists('fcbip_log')) {
        fcbip_log('BIP SMS Callback érkezett: ' . print_r($request->get_params(), true));
    }
    
    // Paraméterek kinyerése
    $params = $request->get_params();
    
    // A státusz konvertálása BIP státuszokból a saját rendszerünk státuszaira
    $statusMapping = [
        'ACCEPTED' => 'processing',
        'SENT' => 'sent',
        'WAITING' => 'processing',
        'DELIVERED' => 'delivered',
        'REJECTED' => 'failed',
        'UNDELIVERABLE' => 'failed',
        'MISC' => 'failed',
        'STOPPED' => 'failed',
        'EXPIRED' => 'failed',
        'STATUSLOST' => 'failed'
    ];
    
    // Paraméterek feldolgozása
    $reference_id = isset($params['referenceid']) ? sanitize_text_field($params['referenceid']) : '';
    $phone_number = isset($params['number']) ? sanitize_text_field($params['number']) : '';
    $incomingStatus = isset($params['status']) ? sanitize_text_field($params['status']) : '';
    $cost = isset($params['price']) ? floatval($params['price']) : 0;
    $message = isset($params['message']) ? urldecode(sanitize_text_field($params['message'])) : '';
    $timestamp = isset($params['timestamp']) ? sanitize_text_field($params['timestamp']) : current_time('mysql');
    
    // BIP státusz konvertálása a saját rendszerünk státuszára
    $normalizedStatus = isset($statusMapping[$incomingStatus]) ? $statusMapping[$incomingStatus] : 'processing';
    
    if (!isset($statusMapping[$incomingStatus])) {
        fcbip_log("Ismeretlen státusz érkezett: {$incomingStatus}, alapértelmezett 'processing' használata");
    }
    
    global $wpdb;
    $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
    
    // Ha van reference_id, abból kinyerjük a campaign_id és subscriber_id-t
    if (!empty($reference_id)) {
        $reference_parts = explode('-', $reference_id);
        
        if (count($reference_parts) == 2) {
            $campaign_id = intval($reference_parts[0]);
            $subscriber_id = intval($reference_parts[1]);
            
            // Először frissítsük az új táblát, ha létezik
            if ($table_exists) {
                // Az új táblában a message_id-t a telefon + kampány alapján keressük
                // (ha nincs még message_id, akkor is működnie kell)
                $message = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, message_id, status, cost FROM {$messages_table} 
                     WHERE campaign_id = %d AND phone = %s 
                     ORDER BY id DESC LIMIT 1",
                    $campaign_id, $phone_number
                ));
                
                if ($message) {
                    $message_id = $message->message_id;
                    
                    // Státusz hierarchia meghatározása
                    $status_hierarchy = [
                        'error' => 0,
                        'failed' => 0,
                        'pending' => 1,
                        'processing' => 2,
                        'sent' => 3,
                        'delivered' => 4
                    ];
                    
                    // Csak akkor frissítünk, ha a státusz magasabb a hierarchiában
                    $should_update_status = false;
                    if (isset($status_hierarchy[$normalizedStatus]) && isset($status_hierarchy[$message->status])) {
                        if ($status_hierarchy[$normalizedStatus] > $status_hierarchy[$message->status]) {
                            $should_update_status = true;
                        }
                    } elseif (!isset($status_hierarchy[$message->status])) {
                        // Ha a jelenlegi státusz nem szerepel a hierarchiában, akkor frissítünk
                        $should_update_status = true;
                    }
                    
                    // Költség frissítése csak akkor, ha még nincs beállítva vagy nagyobb
                    $new_cost = ($message->cost > 0) ? $message->cost : $cost;
                    if ($cost > $message->cost) {
                        $new_cost = $cost;
                    }
                    
                    // Update query építése
                    $update_data = [
                        'cost' => $new_cost,
                        'updated_at' => current_time('mysql')
                    ];
                    
                    if ($should_update_status) {
                        $update_data['status'] = $normalizedStatus;
                        
                        // Ha kézbesítve státusz, akkor beállítjuk a kézbesítési időt
                        if ($normalizedStatus === 'delivered') {
                            $update_data['delivered_at'] = $timestamp ?: current_time('mysql');
                        }
                    }
                    
                    // Frissítés
                    $wpdb->update(
                        $messages_table,
                        $update_data,
                        ['id' => $message->id],
                        null,
                        ['%d']
                    );
                    
                    fcbip_log("SMS kampány statisztika frissítve: Kampány ID: {$campaign_id}, Előfizető ID: {$subscriber_id}, Státusz: {$normalizedStatus}, Telefonszám: {$phone_number}, Ár: {$new_cost}");
                    
                    // Ha van FCBIP_Status_Manager, akkor is frissítsük a státuszt
                    if (class_exists('FCBIP_Status_Manager') && !empty($message_id)) {
                        if ($should_update_status) {
                            FCBIP_Status_Manager::updateStatus($message_id, $normalizedStatus);
                        }
                        
                        if ($cost > 0) {
                            FCBIP_Status_Manager::updateCost($message_id, $cost);
                        }
                    }
                    
                    // Kampány statisztikák frissítése
                    refresh_campaign_statistics($campaign_id);
                    
                } else {
                    fcbip_log("Message not found in new table for campaign {$campaign_id} and phone {$phone_number}");
                    
                    // Ha nincs ilyen üzenet, de van telefon és kampány ID, akkor létrehozunk egy újat
                    if (!empty($phone_number) && $campaign_id > 0) {
                        $wpdb->insert(
                            $messages_table,
                            [
                                'campaign_id' => $campaign_id,
                                'subscriber_id' => $subscriber_id,
                                'phone' => $phone_number,
                                'message' => $message, // Most már dekódolva van
                                'status' => $normalizedStatus,
                                'cost' => $cost,
                                'sent_at' => current_time('mysql'),
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql')
                            ],
                            ['%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
                        );
                        
                        $insert_id = $wpdb->insert_id;
                        fcbip_log("Új SMS üzenet létrehozva: ID: {$insert_id}, Kampány ID: {$campaign_id}, Telefon: {$phone_number}, Státusz: {$normalizedStatus}");
                        
                        // Kampány statisztikák frissítése
                        refresh_campaign_statistics($campaign_id);
                    }
                }
            }
            
            // Backward compatibility - frissítsük a régi táblát is
            $stats_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
            $stats_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stats_table}'") == $stats_table;
            
            if ($stats_table_exists) {
                // Adatok, amiket frissítünk
                $update_data = [
                    'updated_at' => current_time('mysql')
                ];
                
                // A státuszt mindig frissítjük - használjuk a normalizált státuszt
                $update_data['status'] = $normalizedStatus;
                
                // Ha van telefonszám, azt is mentsük
                if (!empty($phone_number)) {
                    $update_data['phone_number'] = $phone_number;
                }
                
                // Ha van költség, azt is mentsük
                if ($cost > 0) {
                    $update_data['price'] = $cost;
                }
                
                // Ellenőrizzük, hogy létezik-e már ilyen rekord
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$stats_table} 
                     WHERE campaign_id = %d AND subscriber_id = %d",
                    $campaign_id, $subscriber_id
                ));
                
                if ($exists) {
                    // Frissítés
                    $wpdb->update(
                        $stats_table,
                        $update_data,
                        [
                            'campaign_id' => $campaign_id,
                            'subscriber_id' => $subscriber_id
                        ]
                    );
                    
                    fcbip_log("SMS kampány statisztika frissítve: Kampány ID: {$campaign_id}, Előfizető ID: {$subscriber_id}, Státusz: {$normalizedStatus}, Telefonszám: {$phone_number}, Ár: {$cost}");
                } else {
                    // Új rekord beszúrása
                    $insert_data = array_merge($update_data, [
                        'campaign_id' => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'created_at' => current_time('mysql')
                    ]);
                    
                    $wpdb->insert($stats_table, $insert_data);
                    
                    fcbip_log("Új SMS kampány statisztika létrehozva: Kampány ID: {$campaign_id}, Előfizető ID: {$subscriber_id}, Státusz: {$normalizedStatus}, Telefonszám: {$phone_number}, Ár: {$cost}");
                }
            }
        } else {
            fcbip_log("Invalid reference ID format: {$reference_id}");
        }
    } else {
        fcbip_log("No reference ID provided in callback");
    }
    
    return new WP_REST_Response(['success' => true], 200);
}

/**
 * Kampány statisztikák frissítése
 * 
 * @param int $campaign_id A kampány azonosítója
 */
function refresh_campaign_statistics($campaign_id) {
    global $wpdb;
    
    // Transient cache törlése a kampánystatisztikákhoz
    delete_transient('fcbip_campaign_stats_' . $campaign_id);
    
    // Kampány tábla ellenőrzése
    $campaign_table = $wpdb->prefix . 'fcbip_sms_campaigns';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$campaign_table}'") == $campaign_table;
    
    if (!$table_exists) {
        return;
    }
    
    // Üzenetek tábla ellenőrzése
    $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
    $messages_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
    
    if (!$messages_table_exists) {
        return;
    }
    
    // Státuszok lekérdezése
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(cost) as total_cost
        FROM {$messages_table}
        WHERE campaign_id = %d",
        $campaign_id
    ));
    
    if ($stats && $stats->total > 0) {
        // Kampány státuszának frissítése
        $status = 'completed';
        if ($stats->processing > 0 || $stats->pending > 0) {
            $status = 'working';
        }
        
        $wpdb->update(
            $campaign_table,
            [
                'status' => $status,
                'processed_count' => ($stats->sent + $stats->delivered),
                'delivered_count' => $stats->delivered,
                'cost' => $stats->total_cost,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $campaign_id]
        );
        
        fcbip_log("Kampány statisztikák frissítve: ID: {$campaign_id}, Állapot: {$status}, Feldolgozva: {$stats->sent}, Kézbesítve: {$stats->delivered}, Költség: {$stats->total_cost}");
    }
}