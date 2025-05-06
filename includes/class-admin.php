<?php

/**
 * Admin class
 * Handles admin settings and UI
 */
class FCBIP_Admin
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Ensure default settings exist
        $this->initialize_settings();
        
        // Simple WordPress admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Admin eszközök hozzáadása
        $this->add_admin_tools();
      
        // AJAX handlers
        add_action('wp_ajax_fcbip_test_sms', [$this, 'ajax_test_sms']);
        add_action('wp_ajax_fcbip_get_balance', [$this, 'ajax_get_balance']);
        add_action('wp_ajax_fcbip_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_fcbip_get_campaign_stats', [$this, 'ajax_get_campaign_stats']); // ÚJ AJAX handler
        add_action('admin_enqueue_scripts', [$this, 'enqueue_automation_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_campaign_monitor_script']); // ÚJ script betöltés
    }
    
    /**
     * Add menu item to WordPress Settings
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('BIP SMS Settings', 'fluentcrm-bip-sms'),
            __('BIP SMS', 'fluentcrm-bip-sms'),
            'manage_options',
            'fcbip-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page()
    {
        // Display settings page
        include FCBIP_SMS_PLUGIN_DIR . 'templates/admin-settings.php';
    }
  
    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('fcbip_settings', 'fcbip_api_key');
        register_setting('fcbip_settings', 'fcbip_api_user');
        register_setting('fcbip_settings', 'fcbip_default_sender');
        register_setting('fcbip_settings', 'fcbip_test_mode');
        register_setting('fcbip_settings', 'fcbip_enable_logs');
    }

    /**
     * Regisztrálja az SMS kampány asset-okat
     */
    public function register_assets() {
        wp_register_script(
            'fcbip-sms-campaign',
            FCBIP_SMS_PLUGIN_URL . 'includes/assets/js/sms-campaign.js',
            ['jquery'],
            FCBIP_SMS_VERSION,
            true
        );
        
        wp_register_style(
            'fcbip-sms-campaign',
            FCBIP_SMS_PLUGIN_URL . 'includes/assets/css/sms-campaign.css',
            [],
            FCBIP_SMS_VERSION
        );
        
        // Regisztráljuk a kampány monitor js-t
        wp_register_script(
            'fcbip-campaign-monitor',
            FCBIP_SMS_PLUGIN_URL . 'includes/assets/js/campaign-monitor.js',
            ['jquery'],
            FCBIP_SMS_VERSION,
            true
        );
    }
    
    /**
     * Kampány monitor JavaScript betöltése a jelentés oldalon
     */
    public function enqueue_campaign_monitor_script($hook) {
        // Csak a kampány jelentések oldalon töltsük be
        if (isset($_GET['page']) && $_GET['page'] === 'fcbip-sms-campaigns' && 
            isset($_GET['action']) && $_GET['action'] === 'report') {
            
            // Regisztráljuk, ha még nem tettük
            if (!wp_script_is('fcbip-campaign-monitor', 'registered')) {
                $this->register_assets();
            }
            
            // Betöltjük a kampány monitor szkriptet
            wp_enqueue_script('fcbip-campaign-monitor');
            
            // Admin URL és nonce átadása a szkriptnek
            wp_localize_script('fcbip-campaign-monitor', 'fcbipCampaignData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fcbip_monitor_nonce')
            ));
        }
    }
  
    /**
     * AJAX handler for testing SMS
     */
    public function ajax_test_sms()
    {
        // Security check
        check_ajax_referer('fcbip_admin_nonce', '_wpnonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (empty($phone) || empty($message)) {
            wp_send_json_error('Phone number and message are required');
            return;
        }
        
        // Load SMS service
        $sms_service = new FCBIP_SMS_Service();
        
        try {
            // Send test message
            $result = $sms_service->send_sms($phone, $message);
            
            if (isset($result['success']) && $result['success']) {
                wp_send_json_success('Message sent successfully. Message ID: ' . $result['message_id']);
            } else {
                wp_send_json_error('Failed to send message: ' . (isset($result['error']) ? $result['error'] : 'Unknown error'));
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for getting balance
     */
    public function ajax_get_balance()
    {
        // Security check
        check_ajax_referer('fcbip_admin_nonce', '_wpnonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Load SMS service
        $sms_service = new FCBIP_SMS_Service();
        
        try {
            // Get balance
            $balance = $sms_service->get_balance();
            
            if ($balance !== false) {
                wp_send_json_success($balance . ' credits');
            } else {
                wp_send_json_error('Failed to retrieve balance');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler to clear logs
     */
    public function ajax_clear_logs()
    {
        // Security check
        check_ajax_referer('fcbip_admin_nonce', '_wpnonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $log_file = FCBIP_SMS_PLUGIN_DIR . 'bip_sms_debug.log';
        
        if (file_exists($log_file) && is_writable($log_file)) {
            file_put_contents($log_file, '');
            wp_send_json_success('Logs cleared successfully');
        } else {
            wp_send_json_error('Could not clear logs. File not found or not writable.');
        }
    }
    
    /**
     * AJAX handler a kampány statisztikák lekérdezéséhez
     */
    public function ajax_get_campaign_stats() {
        // Biztonsági ellenőrzés
        check_ajax_referer('fcbip_monitor_nonce', 'security');
        
        // Jogosultság ellenőrzése
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        
        if (!$campaign_id) {
            wp_send_json_error('Érvénytelen kampány azonosító');
            return;
        }
        
        global $wpdb;
        $campaign_table = $wpdb->prefix . 'fcbip_sms_campaigns';
        $message_table = $wpdb->prefix . 'fcbip_sms_messages';
        
        // Kampány lekérése
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$campaign_table} WHERE id = %d",
            $campaign_id
        ));
        
        if (!$campaign) {
            wp_send_json_error('A kampány nem található');
            return;
        }
        
        // Statisztikák lekérése
        $stats = [];
        
        // Ellenőrizzük, hogy az új rendszer elérhető-e
        $messages_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$message_table}'") == $message_table;
        
        if ($messages_table_exists && class_exists('FCBIP_DB_Manager')) {
            // Új rendszer használata
            $stats = FCBIP_DB_Manager::recalculateCampaignStats($campaign_id);
        } else {
            // Régi rendszer használata
            $statsModel = new FluentCrmBipSms\Models\SmsCampaignStat();
            $stats = $statsModel->getCampaignStats($campaign_id);
            
            // Költségadatok lekérése
            $stats['total_cost'] = 0;
            $metrics_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
            
            // Ellenőrizzük, hogy létezik-e a price oszlop
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$metrics_table}");
            $has_price_column = in_array('price', $columns);
            
            if ($has_price_column) {
                $total_cost = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(price) FROM {$metrics_table} WHERE campaign_id = %d",
                    $campaign_id
                ));
                $stats['total_cost'] = $total_cost ? floatval($total_cost) : 0;
            }
        }
        
        // Kampány aktív státusz meghatározása
        $is_active = ($campaign->status === 'processing' || $campaign->status === 'working');
        
        // Utolsó 10 üzenet lekérése
        $recent_messages = [];
        
        if ($messages_table_exists) {
            $recent_messages = $wpdb->get_results($wpdb->prepare(
                "SELECT message_id, phone, status, cost, sent_at, delivered_at 
                 FROM {$message_table} 
                 WHERE campaign_id = %d 
                 ORDER BY id DESC LIMIT 10",
                $campaign_id
            ));
            
            // Átalakítjuk a SQL eredményeket a JS számára megfelelő formátumba
            $messages = [];
            foreach ($recent_messages as $message) {
                $messages[] = [
                    'message_id' => $message->message_id,
                    'phone' => $message->phone,
                    'status' => $message->status,
                    'cost' => floatval($message->cost),
                    'sent_at' => $message->sent_at ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message->sent_at)) : '-',
                    'delivered_at' => $message->delivered_at ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message->delivered_at)) : '-'
                ];
            }
            
            $stats['messages'] = $messages;
        }
        
        // NULL értékek kezelése
        foreach ($stats as $key => $value) {
            if (is_numeric($value)) {
                $stats[$key] = floatval($value);
            }
        }
        
        // Válasz összeállítása
        $response = array_merge($stats, [
            'is_active' => $is_active,
            'campaign_status' => $campaign->status
        ]);
        
        wp_send_json_success($response);
    }

    /**
     * Enqueue automation scripts
     */
    public function enqueue_automation_scripts() {
        // Only load on automation editor
        global $current_screen;
        
        if (!$current_screen || !property_exists($current_screen, 'id') || $current_screen->id !== 'fluentcrm_page_fluentcrm-automations') {
            return;
        }
        
        // Register and enqueue our script
        wp_register_script(
            'fcbip-sms-action',
            FCBIP_SMS_PLUGIN_URL . 'assets/js/bip-sms-action.js',
            ['jquery', 'wp-element'],
            FCBIP_SMS_VERSION,
            true
        );
        
        wp_enqueue_script('fcbip-sms-action');
        
        fcbip_log('BIP SMS automation editor scripts enqueued');
    }

    /**
     * Initialize default settings if they don't exist
     */
    public function initialize_settings()
    {
        if (get_option('fcbip_api_key') === false) {
            add_option('fcbip_api_key', '');
        }
        
        if (get_option('fcbip_api_user') === false) {
            add_option('fcbip_api_user', '');
        }
        
        if (get_option('fcbip_default_sender') === false) {
            add_option('fcbip_default_sender', '');
        }
        
        if (get_option('fcbip_test_mode') === false) {
            add_option('fcbip_test_mode', 'no');
        }
        
        if (get_option('fcbip_enable_logs') === false) {
            add_option('fcbip_enable_logs', 'yes');
        }
    }

    // ÚJ METÓDUSOK A JELENTÉSEK KEZELÉSÉHEZ - CODY JAVASLATAI ALAPJÁN
    
    /**
     * SMS kampány jelentés adatok lekérése
     */
    private function get_campaign_report_data($campaign_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        
        // Ellenőrizzük, hogy létezik-e a phone_number és price oszlop
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        $has_phone_column = in_array('phone_number', $columns);
        $has_price_column = in_array('price', $columns);
        
        // Lekérdezés összeállítása
        $metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT *, 
            " . ($has_phone_column ? "phone_number" : "'' AS phone_number") . ", 
            " . ($has_price_column ? "price" : "0 AS price") . " 
            FROM {$table_name} 
            WHERE campaign_id = %d 
            ORDER BY created_at DESC",
            $campaign_id
        ));
        
        // Statisztikák kiszámítása
        $stats = [
            'total' => count($metrics),
            'delivered' => 0,
            'failed' => 0,
            'pending' => 0,
            'total_cost' => 0,
            'avg_cost' => 0
        ];
        
        foreach ($metrics as $metric) {
            switch ($metric->status) {
                case 'delivered':
                    $stats['delivered']++;
                    break;
                case 'failed':
                case 'error':
                    $stats['failed']++;
                    break;
                default:
                    $stats['pending']++;
                    break;
            }
            
            // Összesítsük a költségeket
            if ($has_price_column && $metric->price) {
                $stats['total_cost'] += floatval($metric->price);
            }
        }
        
        // Átlagos költség kiszámítása
        if ($stats['total'] > 0 && $stats['total_cost'] > 0) {
            $stats['avg_cost'] = $stats['total_cost'] / $stats['total'];
        }
        
        return [
            'metrics' => $metrics,
            'stats' => $stats
        ];
    }

    /**
     * SMS kampány jelentés megjelenítése
     */
    public function render_sms_campaign_report() {
        if (!isset($_GET['campaign_id'])) {
            wp_die(__('Hiányzó kampány azonosító', 'fluentcrm-bip-sms'));
        }
        
        $campaign_id = intval($_GET['campaign_id']);
        
        // Kampány adatok lekérése
        global $wpdb;
        $campaigns_table = $wpdb->prefix . 'fcbip_sms_campaigns';
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns_table} WHERE id = %d", $campaign_id));
        
        if (!$campaign) {
            wp_die(__('A megadott SMS kampány nem található', 'fluentcrm-bip-sms'));
        }
        
        // Jelentés adatok lekérése
        $report_data = $this->get_campaign_report_data($campaign_id);
        $metrics = $report_data['metrics'];
        $stats = $report_data['stats'];
        
        // Jelentés oldal megjelenítése
        include(FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-reports.php');
    }
    
    /**
     * SMS statisztikák lekérése és összesítése az admin kezdőlapon
     */
    public function get_admin_dashboard_stats() {
        global $wpdb;
        $stats_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        
        // Ellenőrizzük, hogy a price oszlop létezik-e
        $has_price_column = $wpdb->get_var("SHOW COLUMNS FROM {$stats_table} LIKE 'price'");
        
        // Alapstatisztikák
        $total_sent = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table}");
        $total_delivered = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE status = 'delivered'");
        $total_failed = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE status IN ('failed', 'error')");
        
        // Költségek
        $total_cost = 0;
        if ($has_price_column) {
            $total_cost = $wpdb->get_var("SELECT SUM(price) FROM {$stats_table}");
        }
        
        // Havi statisztikák
        $current_month_start = date('Y-m-01 00:00:00');
        $month_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$stats_table} WHERE created_at >= %s",
            $current_month_start
        ));
        
        $month_cost = 0;
        if ($has_price_column) {
            $month_cost = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(price) FROM {$stats_table} WHERE created_at >= %s",
                $current_month_start
            ));
        }
        
        return [
            'total_sent' => $total_sent,
            'total_delivered' => $total_delivered,
            'total_failed' => $total_failed,
            'total_cost' => $total_cost,
            'month_sent' => $month_sent,
            'month_cost' => $month_cost
        ];
    }

    /**
     * SMS statisztikák megjelenítése az admin kezdőlapon
     */
    public function render_admin_dashboard_stats() {
        $stats = $this->get_admin_dashboard_stats();
        ?>
        <div class="fcbip-admin-dashboard-stats">
            <h2><?php _e('BIP SMS Statisztikák', 'fluentcrm-bip-sms'); ?></h2>
            
            <div class="fcbip-stats-grid">
                <div class="fcbip-stat-card">
                    <h3><?php _e('Összes SMS', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['total_sent']); ?></div>
                </div>
                
                <div class="fcbip-stat-card">
                    <h3><?php _e('Kézbesítve', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['total_delivered']); ?></div>
                    <?php if ($stats['total_sent'] > 0): ?>
                        <div class="fcbip-stat-rate">
                            <?php echo round(($stats['total_delivered'] / $stats['total_sent']) * 100); ?>%
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="fcbip-stat-card">
                    <h3><?php _e('Sikertelen', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['total_failed']); ?></div>
                </div>
                
                <div class="fcbip-stat-card">
                    <h3><?php _e('Összes költség', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['total_cost'], 2); ?> Ft</div>
                </div>
                
                <div class="fcbip-stat-card">
                    <h3><?php _e('SMS-ek ebben a hónapban', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['month_sent']); ?></div>
                </div>
                
                <div class="fcbip-stat-card">
                    <h3><?php _e('Költség ebben a hónapban', 'fluentcrm-bip-sms'); ?></h3>
                    <div class="fcbip-stat-value"><?php echo number_format($stats['month_cost'], 2); ?> Ft</div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Admin eszközök hozzáadása
     */
    public function add_admin_tools()
    {
        // Csak admin fióknak jelenjen meg
        if (!current_user_can('manage_options')) {
            return;
        }

        // Ellenőrizzük, hogy van-e futtatási kérés
        if (isset($_GET['fcbip_normalize_statuses']) && $_GET['fcbip_normalize_statuses'] == '1' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'fcbip_normalize_statuses')) {
            $this->run_status_normalization();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>SMS státuszok normalizálása sikeres! A \'sent\' státuszú SMS-ek \'delivered\' státuszra módosultak.</p></div>';
            });
        }

        // Eszköz megjelenítése a beállítások oldalon
        add_action('admin_notices', function() {
            $current_screen = get_current_screen();
            if ($current_screen && $current_screen->id === 'settings_page_fcbip-settings') {
                $url = wp_nonce_url(admin_url('options-general.php?page=fcbip-settings&fcbip_normalize_statuses=1'), 'fcbip_normalize_statuses');
                echo '<div class="notice notice-info is-dismissible"><p>SMS státuszok normalizálásához (sent → delivered) <a href="' . esc_url($url) . '">kattints ide</a>.</p></div>';
            }
        });
    }

    /**
     * Státuszok normalizálása az adatbázisban (egyszer futtatható verzió)
     */
    public function run_status_normalization() 
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        
        // Frissítsük a 'sent' státuszokat 'delivered'-re
        $affected_rows = $wpdb->query("UPDATE {$table} SET status = 'delivered' WHERE status = 'sent'");
        
        // Naplózzuk a frissítést
        if (function_exists('fcbip_log')) {
            fcbip_log('SMS státuszok normalizálása végrehajtva: sent -> delivered. Érintett rekordok: ' . $affected_rows);
        }
        
        return $affected_rows;
    }
}