<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

// Get saved settings
$api_key = get_option('fcbip_api_key', '');
$api_user = get_option('fcbip_api_user', '');
$default_sender = get_option('fcbip_default_sender', '');
$test_mode = get_option('fcbip_test_mode', 'no');
$log_enabled = get_option('fcbip_enable_logs', 'yes');
?>

<div class="wrap">
    <h1><?php echo esc_html__('BIP SMS Settings for FluentCRM', 'fluentcrm-bip-sms'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <div class="notice notice-info">
        <p>
            <?php echo esc_html__('Configure your BIP SMS service settings to send SMS messages to your FluentCRM contacts. Get your API credentials from', 'fluentcrm-bip-sms'); ?>
            <a href="https://sms.bipkampany.hu/" target="_blank">BIP SMS Service</a>.
        </p>
    </div>

    <div id="fcbip-tabs" class="nav-tab-wrapper">
        <a href="#settings" class="nav-tab nav-tab-active"><?php echo esc_html__('API Settings', 'fluentcrm-bip-sms'); ?></a>
        <a href="#test" class="nav-tab"><?php echo esc_html__('Test SMS', 'fluentcrm-bip-sms'); ?></a>
        <a href="#logs" class="nav-tab"><?php echo esc_html__('Logs', 'fluentcrm-bip-sms'); ?></a>
    </div>

    <div id="fcbip-settings" class="tab-content active">
        <form method="post" action="options.php">
            <?php
            settings_fields('fcbip_settings');
            do_settings_sections('fcbip_settings');
            ?>
            
            <table class="form-table">
                <tr valign="top">
    <th scope="row"><?php echo esc_html__('API Email cím', 'fluentcrm-bip-sms'); ?></th>
    <td>
        <input type="text" name="fcbip_api_user" value="<?php echo esc_attr($api_user); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('Add meg a BIP SMS szolgáltatáshoz tartozó email címed.', 'fluentcrm-bip-sms'); ?></p>
    </td>
</tr>

<tr valign="top">
    <th scope="row"><?php echo esc_html__('API Jelszó', 'fluentcrm-bip-sms'); ?></th>
    <td>
        <input type="password" name="fcbip_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('Add meg a BIP SMS fiókodhoz tartozó jelszót.', 'fluentcrm-bip-sms'); ?></p>
    </td>
</tr>
                
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Default Sender ID', 'fluentcrm-bip-sms'); ?></th>
                    <td>
                        <input type="text" name="fcbip_default_sender" value="<?php echo esc_attr($default_sender); ?>" class="regular-text" />
                        <p class="description"><?php echo esc_html__('Default sender ID for SMS messages. Leave empty to use the system default.', 'fluentcrm-bip-sms'); ?></p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Test Mode', 'fluentcrm-bip-sms'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="fcbip_test_mode" value="yes" <?php checked($test_mode, 'yes'); ?> />
                            <?php echo esc_html__('Enable test mode (SMS will not be sent, only simulated)', 'fluentcrm-bip-sms'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('Enable Logs', 'fluentcrm-bip-sms'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="fcbip_enable_logs" value="yes" <?php checked($log_enabled, 'yes'); ?> />
                            <?php echo esc_html__('Enable detailed logging for troubleshooting', 'fluentcrm-bip-sms'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
            
            <?php if (!empty($api_key) && !empty($api_user)): ?>
            <div class="fcbip-balance-check">
                <button type="button" id="fcbip-check-balance" class="button button-secondary">
                    <?php echo esc_html__('Check Balance', 'fluentcrm-bip-sms'); ?>
                </button>
                <span id="fcbip-balance-result"></span>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <div id="fcbip-test" class="tab-content" style="display: none;">
        <h2><?php echo esc_html__('Test SMS Message', 'fluentcrm-bip-sms'); ?></h2>
        
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php echo esc_html__('Phone Number', 'fluentcrm-bip-sms'); ?></th>
                <td>
                    <input type="text" id="fcbip-test-phone" class="regular-text" placeholder="+36201234567" />
                    <p class="description"><?php echo esc_html__('Enter phone number with country code (e.g., +36201234567)', 'fluentcrm-bip-sms'); ?></p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row"><?php echo esc_html__('Message', 'fluentcrm-bip-sms'); ?></th>
                <td>
                    <textarea id="fcbip-test-message" class="large-text" rows="5" placeholder="Enter test message..."></textarea>
                    <p class="description"><?php echo esc_html__('Enter the SMS message to send', 'fluentcrm-bip-sms'); ?></p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row"></th>
                <td>
                    <button type="button" id="fcbip-send-test" class="button button-primary">
                        <?php echo esc_html__('Send Test SMS', 'fluentcrm-bip-sms'); ?>
                    </button>
                    <span id="fcbip-test-result"></span>
                </td>
            </tr>
        </table>
    </div>

    <div id="fcbip-logs" class="tab-content" style="display: none;">
        <h2><?php echo esc_html__('SMS Logs', 'fluentcrm-bip-sms'); ?></h2>
        
        <?php
        $log_file = FCBIP_SMS_PLUGIN_DIR . 'bip_sms_debug.log';
        if (file_exists($log_file) && is_readable($log_file)) {
            $logs = file_get_contents($log_file);
            $logs = nl2br(esc_html($logs));
            echo '<div class="fcbip-log-container" style="background: #f8f8f8; padding: 15px; max-height: 500px; overflow-y: auto; font-family: monospace;">';
            echo $logs;
            echo '</div>';
            
            echo '<p><button type="button" id="fcbip-clear-logs" class="button button-secondary">' . esc_html__('Clear Logs', 'fluentcrm-bip-sms') . '</button></p>';
        } else {
            echo '<p>' . esc_html__('No logs found or log file is not readable.', 'fluentcrm-bip-sms') . '</p>';
        }
        ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab Navigation
    $('#fcbip-tabs a').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href').substring(1);
        
        // Update active tab
        $('#fcbip-tabs a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show target content, hide others
        $('.tab-content').hide();
        $('#fcbip-' + target).show();
    });
    
    // Check Balance
    $('#fcbip-check-balance').on('click', function() {
        var $button = $(this);
        var $result = $('#fcbip-balance-result');
        
        $button.prop('disabled', true);
        $result.html('<span style="color:blue;">Checking balance...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fcbip_get_balance',
                _wpnonce: '<?php echo wp_create_nonce('fcbip_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color:green;">Balance: ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color:red;">Error: ' + response.data + '</span>');
                }
            },
            error: function() {
                $result.html('<span style="color:red;">Network error. Please try again.</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Send Test SMS
    $('#fcbip-send-test').on('click', function() {
        var $button = $(this);
        var $result = $('#fcbip-test-result');
        var phone = $('#fcbip-test-phone').val();
        var message = $('#fcbip-test-message').val();
        
        if (!phone || !message) {
            $result.html('<span style="color:red;">Please enter both phone number and message.</span>');
            return;
        }
        
        $button.prop('disabled', true);
        $result.html('<span style="color:blue;">Sending SMS...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fcbip_test_sms',
                phone: phone,
                message: message,
                _wpnonce: '<?php echo wp_create_nonce('fcbip_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color:green;">Success: ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color:red;">Error: ' + response.data + '</span>');
                }
            },
            error: function() {
                $result.html('<span style="color:red;">Network error. Please try again.</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Clear logs
    $('#fcbip-clear-logs').on('click', function() {
        if (confirm('<?php echo esc_js(__('Are you sure you want to clear the logs?', 'fluentcrm-bip-sms')); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fcbip_clear_logs',
                    _wpnonce: '<?php echo wp_create_nonce('fcbip_admin_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('.fcbip-log-container').html('');
                    }
                }
            });
        }
    });
});
</script>

<style>
.tab-content {
    margin-top: 20px;
}
.fcbip-balance-check {
    margin-top: 20px;
}
#fcbip-balance-result, #fcbip-test-result {
    margin-left: 10px;
    vertical-align: middle;
}
</style>
