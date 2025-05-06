<?php

/**
 * SMS Service class
 */
class FCBIP_SMS_Service {
    
    /**
     * API endpoint for BIP SMS
     */
    private $api_endpoint = 'https://api.bipkampany.hu';
    
    /**
     * API credentials
     */
    private $api_user;
    private $api_key;
    private $default_sender;
    private $test_mode;
    private $bip_api = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_user = get_option('fcbip_api_user', '');
        $this->api_key = get_option('fcbip_api_key', '');
        $this->default_sender = get_option('fcbip_default_sender', '');
        $this->test_mode = get_option('fcbip_test_mode', 'no');
        
        // BIP API könyvtár betöltése, ha létezik
        $api_file = FCBIP_SMS_PLUGIN_DIR . 'includes/libraries/bip_api.php';
        if (file_exists($api_file)) {
            require_once $api_file;
            $this->bip_api = new BipKampanyAPI($this->api_user, $this->api_key);
            
            // Naplózás beállítása, ha engedélyezve van
            if (get_option('fcbip_enable_logs', 'yes') === 'yes') {
                $this->bip_api->logFilename = FCBIP_SMS_PLUGIN_DIR . 'logs/bip-' . date("Y-m") . '.txt';
                
                // Logs mappa létrehozása, ha nem létezik
                $logs_dir = FCBIP_SMS_PLUGIN_DIR . 'logs';
                if (!file_exists($logs_dir)) {
                    mkdir($logs_dir, 0755, true);
                }
            }
            
            // cURL használata, ha a file_get_contents nem elérhető
            if (!ini_get('allow_url_fopen')) {
                $this->bip_api->callingMethod = 'curl';
            }
        }
    }
    
    /**
     * Check if service is configured
     */
    public function is_configured() {
        return !empty($this->api_user) && !empty($this->api_key);
    }
    
/**
 * Send SMS message
 * 
 * @param string $to Phone number
 * @param string $message Message content
 * @param string $sender_id Optional sender ID
 * @param string $scheduled_time Optional scheduled time (YYYY-MM-DD HH:MM:SS format)
 * @param int $campaign_id Optional campaign ID for callbacks
 * @param int $subscriber_id Optional subscriber ID for callbacks
 * @return array Result with success/error status
 */
public function send_sms($to, $message, $sender_id = '', $scheduled_time = null, $campaign_id = null, $subscriber_id = null) {
    if (!$this->is_configured()) {
        fcbip_log('BIP SMS service not configured properly');
        return [
            'success' => false,
            'message' => 'BIP SMS service not configured properly'
        ];
    }
    
    $sender = !empty($sender_id) ? $sender_id : $this->default_sender;
    
    // Test mód ellenőrzése
    if ($this->test_mode === 'yes') {
        fcbip_log("TEST MODE: Would send SMS to {$to}, message: {$message}, sender: {$sender}");
        return [
            'success' => true,
            'message' => 'Test mode - SMS not sent'
        ];
    }
    
    // Formázd a telefonszámot nemzetközi formátumra
    $to = $this->format_phone_number($to);
    fcbip_log("Formatted phone number: {$to}");
    
    try {
        if ($this->bip_api) {
            // A BIP API osztály használata
            $type = null; // Alapértelmezett, lehet 'unicode' is ha speciális karakterek vannak
            
            // Alapértelmezett paraméterek
            $callback = null;
            $reference_id = null;
            
            // Csak akkor adjuk hozzá a reference_id-t és callback-et, ha van kampány és feliratkozó ID
            if ($campaign_id && $subscriber_id) {
                $reference_id = $campaign_id . '-' . $subscriber_id;
                
                // A callback paraméter helyes formátuma: státusz kódok listája vagy "ALL"
                // A dokumentáció szerint "ALL" = minden státusz
                $callback = "ALL";
                
                // Létrehozunk egy callback URL-t is a REST API endpointunkhoz
                // De ezt a visszajelzéskor a BIP rendszere nem használja,
                // hanem az admin felületen beállított callback URL-t hívja
                $callback_url = get_rest_url(null, 'fluent-crm/v2/bip-sms-callback');
                fcbip_log("Using callback: {$callback} with reference ID: {$reference_id}");
                fcbip_log("Our callback URL (registered in BIP admin): {$callback_url}");
            }
            
            // Fontos: a callback itt a státusz kódok listája (vagy ALL), nem URL!
            $result = $this->bip_api->sendSMS($to, $message, $sender, $scheduled_time, $type, $callback, $reference_id);
            
            fcbip_log('API Response: ' . print_r($result, true));
            
            // Sikeres küldés esetén mentsük az üzenetet az adatbázisba
            if (isset($result['result']) && $result['result'] === 'OK') {
                global $wpdb;
                $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
                
                // Ellenőrizzük, hogy létezik-e a tábla
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
                
                if ($table_exists) {
                    // Lekérjük a message_id-t és cost értéket, ha van
                    $message_id = isset($result['id']) ? $result['id'] : '';
                    $cost = isset($result['price']) ? floatval($result['price']) : 0;
                    $status = 'processing'; // Kezdetben feldolgozás alatt státusz
                    
                    // Csak akkor mentjük, ha még nem létezik ilyen message_id
                    $existing_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$messages_table} WHERE message_id = %s",
                        $message_id
                    ));
                    
                    if (!$existing_id && $campaign_id) {
                        $data = [
    'message_id' => $message_id,
    'phone' => $to,
    'message' => urldecode($message), // Dekódolás itt
    'status' => $status,
    'cost' => $cost,
    'sent_at' => current_time('mysql'),
    'campaign_id' => $campaign_id,
    'metadata' => json_encode($result)
];
                        
                        $wpdb->insert($messages_table, $data);
                        fcbip_log("Saved SMS to database with message_id: {$message_id}, status: {$status}, cost: {$cost}");
                    } else if ($existing_id) {
                        fcbip_log("Message already exists in database with ID: {$existing_id}");
                    }
                }
            }
            
            fcbip_log("SMS sent successfully to {$to}");
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'data' => $result,
                'reference_id' => $reference_id,
                'message_id' => isset($result['id']) ? $result['id'] : '',
                'cost' => isset($result['price']) ? floatval($result['price']) : 0
            ];
        } else {
            throw new Exception('BIP API class not available');
        }
    } catch (BipKampanyAPIException $e) {
        $error_message = "BIP API Error (Code: {$e->getCode()}): {$e->getMessage()}";
        fcbip_log($error_message);
        
        // Hiba esetén mentsük el a hibás küldést az adatbázisban
        if ($campaign_id) {
            global $wpdb;
            $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
            
            if ($table_exists) {
                $data = [
                    'message_id' => '',
                    'phone' => $to,
                    'message' => $message,
                    'status' => 'failed',
                    'cost' => 0,
                    'sent_at' => current_time('mysql'),
                    'campaign_id' => $campaign_id,
                    'metadata' => json_encode(['error' => $error_message])
                ];
                
                $wpdb->insert($messages_table, $data);
                fcbip_log("Saved failed SMS to database with status: failed");
            }
        }
        
        return [
            'success' => false,
            'message' => $error_message
        ];
    } catch (Exception $e) {
        $error_message = "Error sending SMS: {$e->getMessage()}";
        fcbip_log($error_message);
        
        // Hiba esetén mentsük el a hibás küldést az adatbázisban
        if ($campaign_id) {
            global $wpdb;
            $messages_table = $wpdb->prefix . 'fcbip_sms_messages';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$messages_table}'") == $messages_table;
            
            if ($table_exists) {
                $data = [
                    'message_id' => '',
                    'phone' => $to,
                    'message' => $message,
                    'status' => 'failed',
                    'cost' => 0,
                    'sent_at' => current_time('mysql'),
                    'campaign_id' => $campaign_id,
                    'metadata' => json_encode(['error' => $error_message])
                ];
                
                $wpdb->insert($messages_table, $data);
                fcbip_log("Saved failed SMS to database with status: failed");
            }
        }
        
        return [
            'success' => false,
            'message' => $error_message
        ];
    }
}

    
    /**
     * Telefonszám formázás a BIP SMS API számára
     *
     * @param string $phone_number
     * @return string
     */
    private function format_phone_number($phone_number) {
        // Telefonszám tisztítása (eltávolítjuk a nem szám karaktereket)
        $phone_number = preg_replace('/[^\d+]/', '', trim($phone_number));
        
        // Ha a szám +36-tal kezdődik, távolítsuk el a + jelet
        if (strpos($phone_number, '+36') === 0) {
            $phone_number = substr($phone_number, 1);
        }
        // Ha a szám 06-tal kezdődik, cseréljük 36-ra
        else if (strpos($phone_number, '06') === 0) {
            $phone_number = '36' . substr($phone_number, 2);
        }
        // Ha a szám csak 0-val kezdődik, tegyük elé a 36-ot
        else if (strpos($phone_number, '0') === 0) {
            $phone_number = '36' . substr($phone_number, 1);
        }
        // Ha a szám nem 36-tal kezdődik, adjuk hozzá
        else if (strpos($phone_number, '36') !== 0) {
            $phone_number = '36' . $phone_number;
        }
        
        // Debug célokból naplózzuk
        fcbip_log('Eredeti telefonszám formázása eredménye: ' . $phone_number);
        
        return $phone_number;
    }
    
    /**
     * Get account balance
     */
    public function get_balance() {
        if (!$this->is_configured() || !$this->bip_api) {
            return [
                'success' => false,
                'message' => 'BIP SMS service not configured properly'
            ];
        }
        
        try {
            $result = $this->bip_api->getBalance();
            return [
                'success' => true,
                'balance' => $result['balance'],
                'currency' => $result['currency']
            ];
        } catch (Exception $e) {
            $error_message = "Error getting balance: {$e->getMessage()}";
            fcbip_log($error_message);
            return [
                'success' => false,
                'message' => $error_message
            ];
        }
    }
}