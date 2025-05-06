<?php

// Biztonsági ellenőrzés
if (!defined('ABSPATH')) {
    exit;
}

wp_enqueue_style('fcbip-sms-campaign');
wp_enqueue_script('fcbip-sms-campaign');
wp_enqueue_script('jquery-ui-datepicker');
wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

// Lekérjük a FluentCRM listákat, címkéket és szegmenseket
$lists = \FluentCrm\App\Models\Lists::orderBy('title', 'ASC')->get();
$tags = \FluentCrm\App\Models\Tag::orderBy('title', 'ASC')->get();

// Kampány adatok betöltése szerkesztéshez
$campaignId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$campaign = null;
if ($campaignId) {
    global $wpdb;
    $table = $wpdb->prefix . 'fcbip_sms_campaigns';
    $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $campaignId));
}

$isEdit = !empty($campaign);
$pageTitle = $isEdit ? __('Edit SMS Campaign', 'fluentcrm-bip-sms') : __('Create SMS Campaign', 'fluentcrm-bip-sms');

// Alapértelmezett értékek
$defaults = [
    'title' => '',
    'status' => 'draft',
    'message_template' => '',
    'from_name' => get_option('fcbip_default_sender', ''),
    'scheduled_at' => '',
    'target_type' => 'list',
    'target_lists' => [],
    'target_tags' => [],
    'target_segment' => ''
];

// Kampány adatok betöltése vagy alapértelmezett értékek használata
$campaignData = $isEdit ? [
    'title' => $campaign->title,
    'status' => $campaign->status,
    'message_template' => $campaign->message_template,
    'from_name' => $campaign->from_name,
    'scheduled_at' => $campaign->scheduled_at,
    'target_type' => $campaign->target_type,
    'target_lists' => maybe_unserialize($campaign->target_lists),
    'target_tags' => maybe_unserialize($campaign->target_tags),
    'target_segment' => $campaign->target_segment
] : $defaults;

// Smart kódok előkészítése
$smartCodes = fcbip_get_smart_codes();
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html($pageTitle); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=fluentcrm-admin&route=sms-campaigns'); ?>" class="page-title-action"><?php _e('Back to Campaigns', 'fluentcrm-bip-sms'); ?></a>
    
    <form method="post" id="fcbip-campaign-form">
        <?php wp_nonce_field('fcbip_sms_nonce', 'fcbip_sms_nonce'); ?>
        <input type="hidden" id="fcbip_campaign_id" name="campaign_id" value="<?php echo esc_attr($campaignId); ?>" />
        
        <div class="fcbip-campaign-container">
            <div class="fcbip-form-row">
                <label for="fcbip_campaign_title"><?php _e('Campaign Title', 'fluentcrm-bip-sms'); ?></label>
                <input type="text" id="fcbip_campaign_title" name="title" value="<?php echo esc_attr($campaignData['title']); ?>" required>
                <div class="fcbip-text-info"><?php _e('Enter a name to identify this campaign (only visible to you)', 'fluentcrm-bip-sms'); ?></div>
            </div>
            
            <div class="fcbip-form-row">
                <label for="fcbip_from_name"><?php _e('Sender Name', 'fluentcrm-bip-sms'); ?></label>
                <input type="text" id="fcbip_from_name" name="from_name" value="<?php echo esc_attr($campaignData['from_name']); ?>" maxlength="11">
                <div class="fcbip-text-info"><?php _e('Leave blank to use the default sender from settings. Max 11 characters.', 'fluentcrm-bip-sms'); ?></div>
            </div>
            
            <div class="fcbip-form-row">
                <label for="fcbip_target_type"><?php _e('Select Recipients', 'fluentcrm-bip-sms'); ?></label>
                <select id="fcbip_target_type" name="target_type">
                    <option value="list" <?php selected($campaignData['target_type'], 'list'); ?>><?php _e('Lists', 'fluentcrm-bip-sms'); ?></option>
                    <option value="tag" <?php selected($campaignData['target_type'], 'tag'); ?>><?php _e('Tags', 'fluentcrm-bip-sms'); ?></option>
                </select>
                
                <div id="fcbip_target_list_selector" class="target-selector" style="margin-top: 10px;">
                    <label><?php _e('Select Lists', 'fluentcrm-bip-sms'); ?></label>
                    <div>
                        <?php foreach ($lists as $list): ?>
                        <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                            <input type="checkbox" name="target_lists[]" value="<?php echo $list->id; ?>" <?php checked(in_array($list->id, (array)$campaignData['target_lists'])); ?>>
                            <?php echo esc_html($list->title); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div id="fcbip_target_tag_selector" class="target-selector" style="margin-top: 10px;">
                    <label><?php _e('Select Tags', 'fluentcrm-bip-sms'); ?></label>
                    <div>
                        <?php foreach ($tags as $tag): ?>
                        <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                            <input type="checkbox" name="target_tags[]" value="<?php echo $tag->id; ?>" <?php checked(in_array($tag->id, (array)$campaignData['target_tags'])); ?>>
                            <?php echo esc_html($tag->title); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="fcbip-form-row">
                <label for="fcbip_message_template"><?php _e('SMS Message', 'fluentcrm-bip-sms'); ?></label>
                <textarea id="fcbip_message_template" name="message_template" rows="8" required><?php echo esc_textarea($campaignData['message_template']); ?></textarea>
                <div class="fcbip-text-info">
                    <?php _e('Characters: ', 'fluentcrm-bip-sms'); ?><span id="fcbip_char_count">0</span> 
                    | <?php _e('SMS count: ', 'fluentcrm-bip-sms'); ?><span id="fcbip_sms_count">0</span>
                </div>
            </div>
            
            <div class="fcbip-form-row">
                <label><?php _e('Available Smart Codes', 'fluentcrm-bip-sms'); ?></label>
                <div class="smart-codes-wrapper" style="margin-bottom: 15px;">
                    <?php foreach ($smartCodes as $code => $label): ?>
                        <button type="button" class="button button-small" 
                                onclick="document.getElementById('fcbip_message_template').value += ' {{<?php echo esc_attr($code); ?>}}'; updateSmsCharCount();"
                                style="margin: 3px">
                            {{<?php echo esc_html($code); ?>}}
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="fcbip-form-row">
                <label for="fcbip_preview_button"><?php _e('Preview & Test', 'fluentcrm-bip-sms'); ?></label>
                <button type="button" id="fcbip_preview_button" class="button"><?php _e('Preview', 'fluentcrm-bip-sms'); ?></button>
                
                <div class="fcbip-preview-box" id="fcbip_preview_content">
                    <em><?php _e('SMS preview will appear here', 'fluentcrm-bip-sms'); ?></em>
                </div>
                
                <div style="margin-top: 15px;">
                    <input type="text" id="fcbip_test_phone" placeholder="<?php _e('Test Phone Number', 'fluentcrm-bip-sms'); ?>" style="width: 200px;">
                    <button type="button" id="fcbip_test_button" class="button"><?php _e('Send Test SMS', 'fluentcrm-bip-sms'); ?></button>
                </div>
            </div>
            
            <div class="fcbip-form-row">
                <label><?php _e('Sending Time', 'fluentcrm-bip-sms'); ?></label>
                
                <div class="sending-options" style="margin-bottom: 10px;">
                    <label style="display: inline-block; margin-right: 20px;">
                        <input type="radio" name="send_type" value="immediately" checked> 
                        <?php _e('Send immediately', 'fluentcrm-bip-sms'); ?>
                    </label>
                    <label style="display: inline-block;">
                        <input type="radio" name="send_type" value="scheduled"> 
                        <?php _e('Schedule for later', 'fluentcrm-bip-sms'); ?>
                    </label>
                </div>
                
                <div id="schedule_container" style="display: none;">
                    <label for="fcbip_scheduled_at"><?php _e('Scheduled Date and Time:', 'fluentcrm-bip-sms'); ?></label>
                    <input type="datetime-local" id="fcbip_scheduled_at" name="scheduled_at" value="<?php echo esc_attr($campaignData['scheduled_at']); ?>" class="widefat">
                </div>
            </div>
            
            <div class="fcbip-form-row" style="margin-top: 30px;">
                <button type="button" id="fcbip_save_draft_button" class="button button-secondary"><?php _e('Save as Draft', 'fluentcrm-bip-sms'); ?></button>
                
                <span id="immediate_button_container">
                    <button type="submit" name="status" value="sending" data-id="<?php echo esc_attr($campaignId); ?>" class="button button-primary"><?php _e('Send Now', 'fluentcrm-bip-sms'); ?></button>
                </span>
                
                <span id="schedule_button_container" style="display: none;">
                    <?php if ($isEdit && $campaignData['status'] == 'scheduled'): ?>
                        <button type="submit" name="status" value="scheduled" class="button button-primary"><?php _e('Update Schedule', 'fluentcrm-bip-sms'); ?></button>
                    <?php else: ?>
                        <button type="submit" name="status" value="scheduled" class="button button-primary"><?php _e('Schedule Campaign', 'fluentcrm-bip-sms'); ?></button>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Karakter számláló funkció
    function updateSmsCharCount() {
        var messageText = $('#fcbip_message_template').val();
        var charCount = messageText.length;
        var smsCount = Math.ceil(charCount / 160);
        
        $('#fcbip_char_count').text(charCount);
        $('#fcbip_sms_count').text(smsCount);
    }
    
    // Kezdeti számolás és eseményfigyelés
    updateSmsCharCount();
    $('#fcbip_message_template').on('input', updateSmsCharCount);
    
    // Előnézet gomb
    $('#fcbip_preview_button').on('click', function(e) {
               e.preventDefault();
        var messageText = $('#fcbip_message_template').val();
        
        if (!messageText) {
            $('#fcbip_preview_content').html('<div class="notice notice-warning"><p><?php _e('Please enter a message first', 'fluentcrm-bip-sms'); ?></p></div>');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fcbip_preview_sms',
                message: messageText,
                security: $('#fcbip_sms_nonce').val()
            },
            beforeSend: function() {
                $('#fcbip_preview_button').prop('disabled', true).text('<?php _e('Loading preview...', 'fluentcrm-bip-sms'); ?>');
                $('#fcbip_preview_content').html('<div class="notice notice-info"><p><?php _e('Loading preview...', 'fluentcrm-bip-sms'); ?></p></div>');
            },
            success: function(response) {
                if (response.success && response.data) {
                    var previewText = response.data.preview || '';
                    $('#fcbip_preview_content').html('<div class="sms-preview-box">' + previewText.replace(/\n/g, '<br>') + '</div>');
                } else {
                    var errorMsg = (response.data && response.data.message) ? response.data.message : '<?php _e('Error loading preview', 'fluentcrm-bip-sms'); ?>';
                    $('#fcbip_preview_content').html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                }
            },
            error: function() {
                $('#fcbip_preview_content').html('<div class="notice notice-error"><p><?php _e('Error occurred during the request. Please try again later.', 'fluentcrm-bip-sms'); ?></p></div>');
            },
            complete: function() {
                $('#fcbip_preview_button').prop('disabled', false).text('<?php _e('Preview', 'fluentcrm-bip-sms'); ?>');
            }
        });
    });
    
    // Teszt SMS gomb
    $('#fcbip_test_button').on('click', function(e) {
        e.preventDefault();
        var testPhone = $('#fcbip_test_phone').val();
        var messageText = $('#fcbip_message_template').val();
        
        if (!messageText) {
            alert('<?php _e('Please enter a message first', 'fluentcrm-bip-sms'); ?>');
            return;
        }
        
        if (!testPhone) {
            alert('<?php _e('Please enter a phone number for the test SMS', 'fluentcrm-bip-sms'); ?>');
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fcbip_send_test_sms_campaign',
                message: messageText,
                phone: testPhone,
                security: $('#fcbip_sms_nonce').val()
            },
            beforeSend: function() {
                $('#fcbip_test_button').prop('disabled', true).text('<?php _e('Sending...', 'fluentcrm-bip-sms'); ?>');
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data && response.data.message ? response.data.message : '<?php _e('Test SMS sent successfully!', 'fluentcrm-bip-sms'); ?>');
                } else {
                    alert('<?php _e('Error:', 'fluentcrm-bip-sms'); ?> ' + (response.data && response.data.message ? response.data.message : '<?php _e('Failed to send test SMS', 'fluentcrm-bip-sms'); ?>'));
                }
            },
            error: function() {
                alert('<?php _e('Error occurred during the request. Please try again later.', 'fluentcrm-bip-sms'); ?>');
            },
            complete: function() {
                $('#fcbip_test_button').prop('disabled', false).text('<?php _e('Send Test SMS', 'fluentcrm-bip-sms'); ?>');
            }
        });
    });
    
    // Mentés piszkozatként gomb
    $('#fcbip_save_draft_button').on('click', function(e) {
        e.preventDefault();
        
        var campaignData = {
            id: $('#fcbip_campaign_id').val(),
            title: $('#fcbip_campaign_title').val(),
            message_template: $('#fcbip_message_template').val(),
            from_name: $('#fcbip_from_name').val(),
            target_type: $('#fcbip_target_type').val(),
            target_lists: [],
            target_tags: []
        };
        
        // Listák összegyűjtése
        $('input[name="target_lists[]"]:checked').each(function() {
            campaignData.target_lists.push($(this).val());
        });
        
        // Címkék összegyűjtése
        $('input[name="target_tags[]"]:checked').each(function() {
            campaignData.target_tags.push($(this).val());
        });
        
        // Ütemezés beállítása, ha van
        if ($('input[name="send_type"]:checked').val() === 'scheduled') {
            campaignData.scheduled_at = $('#fcbip_scheduled_at').val();
        }
        
        $(this).prop('disabled', true).text('<?php _e('Saving...', 'fluentcrm-bip-sms'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fcbip_save_sms_campaign',
                campaign: campaignData,
                status: 'draft',
                security: $('#fcbip_sms_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data && response.data.message ? response.data.message : '<?php _e('Campaign successfully saved as draft', 'fluentcrm-bip-sms'); ?>');
                    
                    // Ha új kampány, frissítsük az ID-t és az URL-t
                    if (response.data && response.data.campaign_id && !campaignData.id) {
                        $('#fcbip_campaign_id').val(response.data.campaign_id);
                        // URL frissítése a kampány ID-val
                        window.location.href = '<?php echo admin_url('admin.php?page=fluentcrm-admin&route=sms-campaigns&action=edit&id='); ?>' + response.data.campaign_id;
                    }
                } else {
                    alert(response.data && response.data.message ? response.data.message : '<?php _e('Error saving campaign', 'fluentcrm-bip-sms'); ?>');
                }
            },
            error: function() {
                alert('<?php _e('Error occurred during the request. Please try again later.', 'fluentcrm-bip-sms'); ?>');
            },
            complete: function() {
                $('#fcbip_save_draft_button').prop('disabled', false).text('<?php _e('Save as Draft', 'fluentcrm-bip-sms'); ?>');
            }
        });
    });
    
    // Címke, lista és szegmens választó
    $('#fcbip_target_type').on('change', function() {
        var selectedType = $(this).val();
        $('.target-selector').hide();
        $('#fcbip_target_' + selectedType + '_selector').show();
    }).trigger('change');
    
    // Küldési mód váltás figyelése
    $('input[name="send_type"]').on('change', function() {
        if ($(this).val() === 'scheduled') {
            $('#schedule_container').show();
            $('#immediate_button_container').hide();
            $('#schedule_button_container').show();
        } else {
            $('#schedule_container').hide();
            $('#immediate_button_container').show();
            $('#schedule_button_container').hide();
        }
    });
    
    // Kezdeti állapot beállítása
    $('input[name="send_type"]:checked').trigger('change');
    
    // Ha már van ütemezett időpont, állítsuk át a választót
    if ($('#fcbip_scheduled_at').val()) {
        $('input[name="send_type"][value="scheduled"]').prop('checked', true).trigger('change');
    }
    
    // Definináljuk a globális funkciót is, amit a SmartCode gombok használnak
    window.updateSmsCharCount = updateSmsCharCount;
});
</script>
