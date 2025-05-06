<?php

/**
 * Plugin Name: FluentCRM - BIP SMS Integration
 * Plugin URI: https://your-website.com
 * Description: Send SMS messages to your FluentCRM contacts using BIP SMS service
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://your-website.com
 * Text Domain: fluentcrm-bip-sms
 * Domain Path: /languages
 */

// Ne engedjük a közvetlen hozzáférést
if (!defined('ABSPATH')) {
    exit;
}

// Egyszerű, garantáltan működő naplózási függvény
if (!function_exists('fcbip_debug_log')) {
    function fcbip_debug_log($message, $level = 'info') {
        // Közvetlenül a wp-content mappába írunk
        $log_file = WP_CONTENT_DIR . '/bip-sms-debug.log';
        
        // Formázott üzenet létrehozása
        $timestamp = date('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] [{$level}] {$message}\n";
        
        // Naplófájl írása
        @file_put_contents($log_file, $formatted, FILE_APPEND);
    }
}




// Teszt naplózás
fcbip_debug_log('Plugin loaded at ' . date('Y-m-d H:i:s'));

// Define constants
define('FCBIP_SMS_PLUGIN_FILE', __FILE__);
define('FCBIP_SMS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FCBIP_SMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FCBIP_SMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FCBIP_SMS_VERSION', '1.0.0');

/**
 * Check if FluentCRM is active
 */
function fcbip_check_fluentcrm()
{
    return defined('FLUENTCRM') && FLUENTCRM;
}

/**
 * Write to log file
 */
function fcbip_log($message)
{
    $log_file = FCBIP_SMS_PLUGIN_DIR . 'bip_sms_debug.log';
    $date = date('Y-m-d H:i:s');
    $log_message = "[{$date}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

/**
 * Load class file
 */
function fcbip_load_class($file)
{
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
}

/**
 * Load helpers
 */
require_once FCBIP_SMS_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Register autoloader for namespaced classes
 */
function fcbip_register_autoloader()
{
    spl_autoload_register(function ($class) {
        // Check if the class uses our namespace
        if (strpos($class, 'FluentCrmBipSms\\') === 0) {
            // Kivesszük a névteret
            $class_name = substr($class, 15); // 'FluentCrmBipSms\\' hossza 15
            
            // Kezeljük a különböző típusú fájlneveket
            if ($class_name == 'FluentCRMHooks') {
                $file = FCBIP_SMS_PLUGIN_DIR . 'includes/class-fluentcrm-hooks.php';
            } else {
                $path = str_replace('\\', '/', $class_name);
                $file = FCBIP_SMS_PLUGIN_DIR . 'includes/' . $path . '.php';
            }
            
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        } elseif (strpos($class, 'BipSmsMigrations\\') === 0) {
            $path = str_replace('\\', '/', substr($class, 16));
            $file = FCBIP_SMS_PLUGIN_DIR . 'database/migrations/' . $path . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        return false;
    });
}


/**
 * Initialize plugin
 */
function fcbip_init()
{
    if (!fcbip_check_fluentcrm()) {
        add_action('admin_notices', 'fcbip_admin_notice_fluentcrm_missing');
        fcbip_log('FluentCRM not found or not active');
        return;
    }
    
    fcbip_log('Initializing plugin - FluentCRM is active');
    fcbip_register_autoloader();
    
    // Create required directories
    $automations_dir = FCBIP_SMS_PLUGIN_DIR . 'includes/Automations';
    if (!file_exists($automations_dir)) {
        mkdir($automations_dir, 0755, true);
    }
    
   // Load base classes in correct order
    $classes = [
        'class-db-manager.php',      // Új osztály a DB kezeléshez - ez legyen az első
        'class-status-manager.php',  // Új osztály a státusz kezeléshez
        'class-sms-service.php',     // Meglévő SMS szolgáltatás
        'class-admin.php',           // Meglévő admin osztály
    ];
    
    $all_loaded = true;
    foreach ($classes as $class) {
        $file = FCBIP_SMS_PLUGIN_DIR . 'includes/' . $class;
        if (!fcbip_load_class($file)) {
            $all_loaded = false;
            fcbip_log('Failed to load: ' . $class);
            break;
        }
        fcbip_log('Successfully loaded: ' . $class);
    }
    
    // Initialize components
    try {
    fcbip_log('Initializing Admin class');
    $admin = new FCBIP_Admin();
    
    // Register admin menu and settings
    add_action('admin_menu', [$admin, 'add_admin_menu']);
    add_action('admin_init', [$admin, 'register_settings']);
    
    fcbip_log('Initializing FluentCRM Hooks class');
    
    // Közvetlenül betöltjük a fájlt
    require_once FCBIP_SMS_PLUGIN_DIR . 'includes/class-fluentcrm-hooks.php';
    
    // Ha a fájl betöltésre került, most már az osztály elérhető lesz
    $hooks = new \FluentCrmBipSms\FluentCRMHooks();
    $hooks->init();

        
        // Load translations
        load_plugin_textdomain('fluentcrm-bip-sms', false, dirname(FCBIP_SMS_PLUGIN_BASENAME) . '/languages');
        
        fcbip_log('Plugin initialization completed successfully');
        
        // Ellenőrizzük, hogy szükséges-e adatbázis frissítés
        fcbip_check_db_updates();
        
   } catch (Exception $e) {
        fcbip_log('Error during initialization: ' . $e->getMessage());
    }
}


// A plugin inicializálásánál:

// A plugin inicializálásánál:

// Ütemezett kampányok feldolgozása
add_action('init', function() {
    if (!wp_next_scheduled('fcbip_process_scheduled_campaigns')) {
        wp_schedule_event(time(), 'hourly', 'fcbip_process_scheduled_campaigns');
    }
});

// Ütemezett kampányok feldolgozása eseménykezelő
add_action('fcbip_process_scheduled_campaigns', function() {
    global $wpdb;
    
    // Lekérjük az időzített kampányokat, amelyek indítási ideje elmúlt
    $table = $wpdb->prefix . 'fcbip_sms_campaigns';
    $campaigns = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} 
            WHERE status = %s 
            AND scheduled_at <= %s",
            'scheduled',
            current_time('mysql')
        )
    );
    
    if (empty($campaigns)) {
        return;
    }
    
    // Feldolgozzuk a kampányokat egyenként
    foreach ($campaigns as $campaign) {
        // Frissítsük a kampány állapotát "working"-re
        $wpdb->update(
            $table,
            [
                'status' => 'working',
                'sending_started_at' => current_time('mysql')
            ],
            ['id' => $campaign->id]
        );
        
        // Indítsuk el a kampány feldolgozást
        $processor = new FluentCrmBipSms\Services\SmsCampaignProcessor();
        $processor->processCampaign($campaign->id);
    }
});



/**
 * Adatbázis frissítések ellenőrzése és futtatása
 */
function fcbip_check_db_updates() {
    $current_db_version = get_option('fcbip_sms_db_version', '0');
    $plugin_db_version = '1.1'; // Növeld ezt a számot, amikor változik az adatbázis szerkezete
    
    if (version_compare($current_db_version, $plugin_db_version, '<')) {
        // Adatbázis frissítése szükséges
        fcbip_log('Adatbázis frissítés szükséges: ' . $current_db_version . ' -> ' . $plugin_db_version);
        
        // Biztosítjuk, hogy a dbDelta() elérhető legyen
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Ellenőrizzük a statisztika táblát és adjunk hozzá új oszlopokat, ha szükséges
        $stats_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
        $stats_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stats_table}'") === $stats_table;
        
        if ($stats_table_exists) {
            $phone_column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$stats_table} LIKE 'phone_number'");
            if (!$phone_column_exists) {
                $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER subscriber_id");
                fcbip_log('Az SMS kampány statisztika táblához hozzáadva: phone_number oszlop');
            }
            
            $price_column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$stats_table} LIKE 'price'");
            if (!$price_column_exists) {
                $wpdb->query("ALTER TABLE {$stats_table} ADD COLUMN price DECIMAL(10,2) DEFAULT NULL AFTER status");
                fcbip_log('Az SMS kampány statisztika táblához hozzáadva: price oszlop');
            }
        } else {
            // Ha a tábla nem létezik, használjuk a migrációs osztályt
            if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php')) {
                require_once(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php');
                \BipSmsMigrations\BipSmsMigrator::migrate();
                fcbip_log('BipSmsMigrator::migrate() futtatva');
            } else {
                fcbip_log('HIBA: BipSmsMigrator.php fájl nem található!');
            }
        }
        
        // Frissítjük a verzió számot
        update_option('fcbip_sms_db_version', $plugin_db_version);
        fcbip_log('Adatbázis verzió frissítve: ' . $plugin_db_version);
    }
}

/**
 * Admin notice for FluentCRM missing
 */
function fcbip_admin_notice_fluentcrm_missing()
{
    echo '<div class="notice notice-error"><p>' . 
         __('FluentCRM BIP SMS Integration requires FluentCRM to be installed and activated.', 'fluentcrm-bip-sms') . 
         '</p></div>';
}

/**
 * Plugin aktiválási funkció - adatbázis táblák létrehozása
 */
function fcbip_activate_plugin() {
    fcbip_debug_log('Plugin activation started');
    
    // Biztosítjuk, hogy a dbDelta() elérhető legyen
    if (!function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }
    
    // Adatbázis táblák létrehozása a migrációs osztállyal
    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php')) {
        require_once(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php');
        \BipSmsMigrations\BipSmsMigrator::migrate();
        fcbip_debug_log('Adatbázis táblák létrehozva a BipSmsMigrator segítségével');
    } else {
        fcbip_debug_log('HIBA: BipSmsMigrator.php fájl nem található!');
    }
    
    // Alapértelmezett beállítások
    add_option('fcbip_sms_api_key', '');
    add_option('fcbip_sms_api_secret', '');
    add_option('fcbip_sms_sender', 'BIP SMS');
    update_option('fcbip_sms_db_version', '1.0');
    
    fcbip_debug_log('Plugin activation completed');
}

// Initialize plugin after plugins loaded
add_action('plugins_loaded', 'fcbip_init');

// Plugin aktiválási hook
register_activation_hook(__FILE__, 'fcbip_activate_plugin');

// Register BIP SMS Action with FluentCRM - használjuk a helyes hookot
add_action('fluent_crm/after_init', function() {
    // BIP SMS Action betöltése
    if (defined('FLUENTCRM') && class_exists('FluentCrm\App\Services\Funnel\BaseAction')) {
        if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'includes/Automations/BipSmsAction.php')) {
            require_once FCBIP_SMS_PLUGIN_DIR . 'includes/Automations/BipSmsAction.php';
            new FluentCrmBipSms\Automations\BipSmsAction();
            fcbip_log('BipSmsAction registered with fluent_crm/after_init hook');
        } else {
            fcbip_log('BipSmsAction file not found');
        }
    }
    
    // SMS Kampányok menüpont hozzáadása
    add_filter('fluentcrm_menu_items', function($items) {
        fcbip_log('Registering SMS Campaigns menu item');
        $items['sms_campaigns'] = [
            'title' => __('SMS Campaigns', 'fluentcrm-bip-sms'),
            'slug' => 'sms-campaigns',
            'route' => 'sms-campaigns',
            'position' => 45,
            'capability' => 'manage_options',
            'render_callback' => function() {
                echo '<div id="fluentcrm_app">SMS Campaigns Content Here</div>';
            }
        ];
        return $items;
    });
});

// SMS kampány képességek hozzáadása
add_filter('fluentcrm_permissions', function($permissions) {
    $permissions['fluentcrm_manage_sms_campaigns'] = [
        'title' => __('Manage SMS Campaigns', 'fluentcrm-bip-sms'),
        'depends' => [
            'fluentcrm_manage_contacts'
        ]
    ];
    return $permissions;
});

// Legegyszerűbb megoldás - csak egy egyszerű menüpont
add_action('admin_menu', function() {
    add_menu_page(
        __('BIP SMS', 'fluentcrm-bip-sms'),
        __('BIP SMS', 'fluentcrm-bip-sms'),
        'manage_options',
        'fcbip-sms-campaigns',  // Használjuk vissza az eredeti azonosítót
        function() {
            echo '<div class="wrap">';
            
            // Ellenőrizzük, hogy van-e művelet paraméter
            $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
            
            switch ($action) {
                case 'create':
                    echo '<h1>' . __('Create SMS Campaign', 'fluentcrm-bip-sms') . '</h1>';
                    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php')) {
                        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php';
                    } else {
                        echo '<p>Campaign editor template not found!</p>';
                    }
                    break;
                
                case 'edit':
                    echo '<h1>' . __('Edit SMS Campaign', 'fluentcrm-bip-sms') . '</h1>';
                    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php')) {
                        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php';
                    } else {
                        echo '<p>Campaign editor template not found!</p>';
                    }
                    break;
                
                case 'report':
                    echo '<h1>' . __('SMS Campaign Report', 'fluentcrm-bip-sms') . '</h1>';
                    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-reports.php')) {
                        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-reports.php';
                    } else {
                        echo '<p>Campaign report template not found!</p>';
                    }
                    break;
                
                default:
                    echo '<h1>' . __('SMS Campaigns', 'fluentcrm-bip-sms') . '</h1>';
                    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaigns-index.php')) {
                        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaigns-index.php';
                    } else {
                        echo '<p>Template file not found!</p>';
                    }
                    break;
            }
            
            echo '</div>';
        },
        'dashicons-email-alt',
        58
    );
});



// AJAX kezelők regisztrálása


// Admin menüelem az SMS kampányok oldalon egy gombbal a táblák manuális létrehozásához
function fcbip_add_create_tables_button() {
    // Csak a megfelelő oldalon és csak ha a táblák még nem léteznek
    if (!isset($_GET['page']) || $_GET['page'] !== 'fcbip-sms-campaigns') {
        return;
    }
    
    global $wpdb;
    $campaigns_table = $wpdb->prefix . 'fcbip_sms_campaigns';
    $stats_table = $wpdb->prefix . 'fcbip_sms_campaign_stats';
    
    $campaigns_exists = $wpdb->get_var("SHOW TABLES LIKE '$campaigns_table'") === $campaigns_table;
    $stats_exists = $wpdb->get_var("SHOW TABLES LIKE '$stats_table'") === $stats_table;
    
    if (!$campaigns_exists || !$stats_exists) {
        // HTML gomb hozzáadása
        echo '<div class="notice notice-warning" style="margin: 10px 0;">';
        echo '<p>Hiányzó adatbázis táblák észlelve! <a href="' . admin_url('admin.php?page=fcbip-sms-campaigns&action=create_tables') . '" class="button button-primary">Táblák létrehozása</a></p>';
        echo '</div>';
    }
}
add_action('admin_notices', 'fcbip_add_create_tables_button');

// Kézi tábla létrehozás kérelem kezelése
function fcbip_handle_manual_table_creation() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'fcbip-sms-campaigns' || !isset($_GET['action']) || $_GET['action'] !== 'create_tables') {
        return;
    }
    
    // Csak adminisztrátor végezheti el
    if (!current_user_can('manage_options')) {
        return;
    }
    
    fcbip_debug_log('Kézi adatbázis tábla létrehozás kezdeményezve');
    
    // Biztosítjuk, hogy a dbDelta() elérhető legyen
    if (!function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }
    
    // Adatbázis táblák létrehozása a migrációs osztállyal
    if (file_exists(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php')) {
        require_once(FCBIP_SMS_PLUGIN_DIR . 'database/migrations/BipSmsMigrator.php');
        \BipSmsMigrations\BipSmsMigrator::migrate();
        fcbip_debug_log('Adatbázis táblák létrehozva a BipSmsMigrator segítségével (kézi)');
        
        // Visszajelzés az admin számára
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>SMS adatbázis táblák sikeresen létrehozva!</p></div>';
        });
        
        // Frissítjük a verzió számot
        update_option('fcbip_sms_db_version', '1.0');
    } else {
        fcbip_debug_log('HIBA: BipSmsMigrator.php fájl nem található a kézi tábla létrehozás során!');
        
        // Hibaüzenet az admin számára
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>Hiba történt: A BipSmsMigrator.php fájl nem található!</p></div>';
        });
    }
}
add_action('admin_init', 'fcbip_handle_manual_table_creation');

// Form feldolgozás a kampány küldéshez
add_action('admin_init', function() {
    // Ellenőrizzük, hogy ez egy kampány form beküldés-e
    if (!isset($_POST['fcbip_sms_nonce']) || !wp_verify_nonce($_POST['fcbip_sms_nonce'], 'fcbip_sms_nonce')) {
        return;
    }
    
    fcbip_debug_log('SMS campaign form submitted');
    
    // Ellenőrizzük, hogy van-e kampány ID és státusz
    if (!isset($_POST['status']) || $_POST['status'] !== 'sending') {
        return;
    }
    
    // Kampány adatok lekérése
    $campaignId = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0;
    $campaignTitle = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $messageTemplate = isset($_POST['message_template']) ? sanitize_textarea_field($_POST['message_template']) : '';
    
    fcbip_debug_log("Campaign form submitted - ID: $campaignId, Title: $campaignTitle");
    
    // Ha nincs ID, de van cím és üzenet, akkor menteni kell először
    global $wpdb;
    $table = $wpdb->prefix . 'fcbip_sms_campaigns';
    
    if ($campaignId <= 0 && !empty($campaignTitle) && !empty($messageTemplate)) {
        // Új kampány létrehozása
        $wpdb->insert(
            $table,
            [
                'title' => $campaignTitle,
                'message_template' => $messageTemplate,
                'from_name' => isset($_POST['from_name']) ? sanitize_text_field($_POST['from_name']) : '',
                'target_type' => isset($_POST['target_type']) ? sanitize_text_field($_POST['target_type']) : 'list',
                'target_lists' => isset($_POST['target_lists']) ? maybe_serialize($_POST['target_lists']) : '',
                'target_tags' => isset($_POST['target_tags']) ? maybe_serialize($_POST['target_tags']) : '',
                'status' => 'working',
                'created_at' => current_time('mysql'),
                'sending_started_at' => current_time('mysql')
            ]
        );
        
        $campaignId = $wpdb->insert_id;
        fcbip_debug_log("New campaign created with ID: $campaignId");
    }
    
    if ($campaignId > 0) {
        // Kampány állapot frissítése
        $wpdb->update(
            $table,
            [
                'status' => 'working',
                'sending_started_at' => current_time('mysql')
            ],
            ['id' => $campaignId]
        );
        
        fcbip_debug_log("Campaign status updated to 'working'");
        
        // Kampány feldolgozás indítása
        if (class_exists('FluentCrmBipSms\Services\SmsCampaignProcessor')) {
            $processor = new FluentCrmBipSms\Services\SmsCampaignProcessor();
            $result = $processor->processCampaign($campaignId);
            
            fcbip_debug_log("Campaign processing started for ID: $campaignId");
            
            // Átirányítás sikerüzenettel
            wp_redirect(add_query_arg('message', 'campaign_started', admin_url('admin.php?page=fcbip-sms-campaigns')));
            exit;
        } else {
            fcbip_debug_log("Error: SmsCampaignProcessor class not found");
        }
    } else {
        fcbip_debug_log("Error: Unable to determine campaign ID");
    }
});

// AJAX kezelők hozzáadása
add_action('wp_ajax_fcbip_send_campaign_now', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nincs megfelelő jogosultságod!']);
    }

    $campaignId = isset($_REQUEST['campaign_id']) ? intval($_REQUEST['campaign_id']) : 0;
    
    if (!$campaignId) {
        wp_send_json_error(['message' => 'Érvénytelen kampány azonosító!']);
    }
    
    // Lekérjük a kampányt
    global $wpdb;
    $table = $wpdb->prefix . 'fcbip_sms_campaigns';
    $campaign = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d",
        $campaignId
    ));
    
    if (!$campaign) {
        wp_send_json_error(['message' => 'A kampány nem található!']);
        return;
    }
    
    // Kampány állapot frissítése "working"-re
    $wpdb->update(
        $table,
        [
            'status' => 'working', 
            'sending_started_at' => current_time('mysql')
        ],
        ['id' => $campaignId]
    );
    
    // Indítsuk el a kampány feldolgozást
    $processor = new FluentCrmBipSms\Services\SmsCampaignProcessor();
    $result = $processor->processCampaign($campaignId);
    
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    } else {
        wp_send_json_success(['message' => 'Az SMS kampány küldése megkezdődött.']);
    }
});

// REST API integration
require_once(FCBIP_SMS_PLUGIN_DIR . 'includes/rest-api-integration.php');


/**
 * BIP SMS callback kezelése
 * 
 * @param WP_REST_Request $request A REST kérés objektuma
 * @return WP_REST_Response A REST válasz objektuma
 */
function fcbip_handle_callback($request) {
    // A beérkező paraméterek kinyerése
    $params = $request->get_params();
    
    // A callback esemény naplózása
    fcbip_log('BIP SMS Callback érkezett: ' . print_r($params, true));
    
    // BIP SMS-től érkező paraméterek feldolgozása
    $reference_id = isset($params['referenceid']) ? sanitize_text_field($params['referenceid']) : '';
    $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
    $phone_number = isset($params['number']) ? sanitize_text_field($params['number']) : ''; // Új feldolgozás
    $price = isset($params['price']) ? floatval($params['price']) : 0; // Új feldolgozás
    
    // Ellenőrizzük, hogy van-e referenceid
    if (empty($reference_id)) {
        fcbip_log('Hiányzó reference ID a BIP SMS callback-ben');
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Hiányzó reference ID'
        ], 400);
    }
    
    // A referenceid szétbontása (formátum: kampány_id-előfizető_id)
    $ids = explode('-', $reference_id);
    if (count($ids) !== 2) {
        fcbip_log('Érvénytelen reference ID formátum: ' . $reference_id);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Érvénytelen reference ID formátum'
        ], 400);
    }
    
    $campaign_id = intval($ids[0]);
    $subscriber_id = intval($ids[1]);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'fcbip_sms_campaign_stats';
    
    // Ellenőrizzük, hogy létezik-e már a bejegyzés az adatbázisban
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE campaign_id = %d AND subscriber_id = %d",
        $campaign_id, $subscriber_id
    ));
    
    if ($existing) {
        // Frissítsük a meglévő bejegyzést az új adatokkal
        $result = $wpdb->update(
            $table_name,
            [
                'status' => $status,
                'phone_number' => $phone_number,
                'price' => $price,
                'updated_at' => current_time('mysql')
            ],
            [
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id
            ]
        );
        
        if ($result !== false) {
            fcbip_log("SMS kampány statisztika frissítve: Kampány ID: $campaign_id, Előfizető ID: $subscriber_id, Státusz: $status, Telefonszám: $phone_number, Ár: $price");
        } else {
            fcbip_log('Hiba az SMS kampány statisztika frissítése közben: ' . $wpdb->last_error);
        }
    } else {
        // Új bejegyzés létrehozása
        $result = $wpdb->insert(
            $table_name,
            [
                'campaign_id' => $campaign_id,
                'subscriber_id' => $subscriber_id,
                'phone_number' => $phone_number,
                'status' => $status,
                'price' => $price,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]
        );
        
        if ($result !== false) {
            fcbip_log("Új SMS kampány statisztika létrehozva: Kampány ID: $campaign_id, Előfizető ID: $subscriber_id, Státusz: $status, Telefonszám: $phone_number, Ár: $price");
        } else {
            fcbip_log('Hiba új SMS kampány statisztika létrehozása közben: ' . $wpdb->last_error);
        }
    }
    
    // Válasz a BIP SMS API-nak
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Callback feldolgozva'
    ], 200);
}

// A REST API végpont regisztrálása
add_action('rest_api_init', function () {
    register_rest_route('fluent-crm/v2', '/bip-sms-callback', [
        'methods' => 'GET',
        'callback' => 'fcbip_handle_callback',
        'permission_callback' => '__return_true'
    ]);
});