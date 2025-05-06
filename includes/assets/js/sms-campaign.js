(function($) {
    'use strict';
    
    // Karakter számláló
    function updateSmsCharCount() {
        var messageText = $('#fcbip_message_template').val();
        var charCount = messageText.length;
        var smsCount = Math.ceil(charCount / 160);
        
        $('#fcbip_char_count').text(charCount);
        $('#fcbip_sms_count').text(smsCount);
    }
    
    // SMS előnézet
    function previewSms() {
        var messageText = $('#fcbip_message_template').val();
        
        if (!messageText) {
            $('#fcbip_preview_content').html('<div class="notice notice-warning"><p>Nincs megjeleníthető üzenet.</p></div>');
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
                $('#fcbip_preview_button').prop('disabled', true).text('Előnézet betöltése...');
                $('#fcbip_preview_content').html('<div class="notice notice-info"><p>Előnézet betöltése...</p></div>');
            },
            success: function(response) {
                if (response.success && response.data) {
                    var previewText = response.data.preview || '';
                    $('#fcbip_preview_content').html('<div class="sms-preview-box">' + previewText.replace(/\n/g, '<br>') + '</div>');
                } else {
                    var errorMsg = (response.data && response.data.message) ? response.data.message : 'Hiba történt az előnézet betöltésekor.';
                    $('#fcbip_preview_content').html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                }
            },
            error: function() {
                $('#fcbip_preview_content').html('<div class="notice notice-error"><p>Hiba történt a kérés során. Kérjük, próbáld újra később.</p></div>');
            },
            complete: function() {
                $('#fcbip_preview_button').prop('disabled', false).text('Előnézet');
            }
        });
    }
    
// Teszt SMS küldés
$('#fcbip_test_button').on('click', function() {
    console.log('Test button clicked'); // Debug üzenet
    
    var messageText = $('#message_template').val();
    var testPhone = $('#test_phone').val();
    
    console.log('Message:', messageText);
    console.log('Phone:', testPhone);
    console.log('Security token present:', $('#fcbip_sms_nonce').val() ? 'Yes' : 'No');
    
    // AJAX kérés
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
            console.log('Sending AJAX request...');
            $('#fcbip_test_button').prop('disabled', true).text('Küldés...');
        },
        success: function(response) {
            console.log('AJAX success:', response);
            if (response.success) {
                alert('Teszt SMS sikeresen elküldve!');
            } else {
                alert('Hiba: ' + (response.data && response.data.message ? response.data.message : 'Ismeretlen hiba'));
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX error:', textStatus, errorThrown);
            console.log('Response:', jqXHR.responseText);
            alert('AJAX hiba: ' + textStatus);
        },
        complete: function() {
            console.log('AJAX request completed');
            $('#fcbip_test_button').prop('disabled', false).text('Teszt SMS küldése');
        }
    });
});

    
    // Kampány mentése piszkozatként
    function saveCampaignAsDraft() {
        var campaignData = {
            title: $('#fcbip_campaign_title').val(),
            message_template: $('#fcbip_message_template').val(),
            from_name: $('#fcbip_from_name').val(),
            target_type: $('#fcbip_target_type').val(),
            target_lists: getSelectedValues('#fcbip_target_lists'),
            target_tags: getSelectedValues('#fcbip_target_tags'),
            target_segment: $('#fcbip_target_segment').val()
        };
        
        // Ütemezés beállítása, ha van
        if ($('input[name="send_type"]:checked').val() === 'scheduled') {
            campaignData.scheduled_at = $('#fcbip_scheduled_at').val();
        }
        
        // Kampány ID hozzáadása, ha szerkesztés mód
        var campaignId = $('#fcbip_campaign_id').val();
        if (campaignId) {
            campaignData.id = campaignId;
        }
        
        $('#fcbip_save_draft_button').prop('disabled', true).text('Mentés...');
        
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
        $('#fcbip_test_button').prop('disabled', true).text('Küldés...');
        console.log('AJAX kérés indítása:', {
            action: 'fcbip_send_test_sms_campaign',
            phone: testPhone,
            security: 'SECURITY_TOKEN' // Ne logoljuk a valódi security tokent
        });
    },
    success: function(response) {
        console.log('AJAX válasz:', response);
        if (response.success) {
            alert('Teszt SMS sikeresen elküldve!');
        } else {
            alert('Hiba történt: ' + (response.data ? response.data.message : 'Ismeretlen hiba'));
        }
    },
    error: function(jqXHR, textStatus, errorThrown) {
        console.error('AJAX hiba:', textStatus, errorThrown);
        console.log('Válasz:', jqXHR.responseText);
        alert('Kommunikációs hiba történt! Részletek a konzolban.');
    },
    complete: function() {
        $('#fcbip_test_button').prop('disabled', false).text('Teszt SMS küldése');
    }
});

    }
    
    // Segédfüggvény a többszörös kiválasztások értékeinek kinyeréséhez
    function getSelectedValues(selector) {
        var values = [];
        $(selector + ' option:selected').each(function() {
            values.push($(this).val());
        });
        return values;
    }
    
    // Eseménykezelők inicializálása
    $(document).ready(function() {
        // Karakter számláló
        $('#fcbip_message_template').on('input', updateSmsCharCount);
        updateSmsCharCount();
        
        // Előnézet gomb
        $('#fcbip_preview_button').on('click', function(e) {
            e.preventDefault();
            previewSms();
        });
        
        // Teszt SMS gomb
        $('#fcbip_test_button').on('click', function(e) {
            e.preventDefault();
            sendTestSms();
        });
        
        // Mentés piszkozatként gomb
        $('#fcbip_save_draft_button').on('click', function(e) {
            e.preventDefault();
            saveCampaignAsDraft();
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
            } else {
                $('#schedule_container').hide();
            }
        });
        
        // Kezdeti állapot beállítása az ütemezés kapcsolóhoz
        $('input[name="send_type"]:checked').trigger('change');
        
        // Ha már van ütemezett időpont, állítsuk át a választót
        if ($('#fcbip_scheduled_at').val()) {
            $('input[name="send_type"][value="scheduled"]').prop('checked', true).trigger('change');
        }
        
        // Dátum és idő választó inicializálása - ha rendelkezésre áll a datepicker
        if ($.fn.datepicker) {
            $('#fcbip_scheduled_at').datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: 0
            });
        }
    });
})(jQuery);

