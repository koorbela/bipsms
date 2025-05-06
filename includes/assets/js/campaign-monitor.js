(function($) {
    'use strict';
    
    // Kampány monitorozás alapbeállításai
    var settings = {
        updateInterval: 10000, // 10 másodperc
        campaignId: 0,
        isActive: false
    };
    
    // Kampány statisztikák frissítése
    function updateCampaignStats() {
        if (!settings.isActive || !settings.campaignId) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'GET',
            data: {
                action: 'fcbip_get_campaign_stats',
                campaign_id: settings.campaignId,
                security: $('#fcbip_monitor_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    // Statisztikák frissítése
                    updateStatDisplay(response.data);
                    
                    // Folytatjuk a monitorozást, ha a kampány még aktív
                    if (response.data.is_active) {
                        setTimeout(updateCampaignStats, settings.updateInterval);
                    } else {
                        settings.isActive = false;
                        $('#fcbip-monitoring-status').text('Kampány befejezve');
                    }
                } else {
                    console.error('Error fetching stats:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    }
    
    // Statisztikák megjelenítésének frissítése
    function updateStatDisplay(stats) {
        // Fő számláló elemek frissítése
        $('.fcbip-stat-count-total').text(stats.total);
        $('.fcbip-stat-count-sent').text(stats.sent);
        $('.fcbip-stat-count-delivered').text(stats.delivered);
        $('.fcbip-stat-count-failed').text(stats.failed);
        $('.fcbip-stat-count-pending').text(stats.pending);
        $('.fcbip-stat-count-processing').text(stats.processing);
        
        // Költség frissítése
        $('.fcbip-stat-count-cost').text(stats.total_cost.toFixed(2) + ' Ft');
        
        // Grafikonok frissítése (ha van)
        if (typeof updateStatsChart === 'function') {
            updateStatsChart(stats);
        }
        
        // Üzenet lista frissítése (ha van)
        if (stats.messages && stats.messages.length > 0) {
            updateMessagesList(stats.messages);
        }
    }
    
    // Üzenet lista frissítése
    function updateMessagesList(messages) {
        var $tableBody = $('#fcbip-messages-table tbody');
        
        // Csak akkor frissítjük a listát, ha van tábla
        if ($tableBody.length === 0) {
            return;
        }
        
        // Üzenetek feldolgozása és beszúrása
        messages.forEach(function(message) {
            // Meglévő sor keresése
            var $existingRow = $tableBody.find('tr[data-message-id="' + message.message_id + '"]');
            
            // Státusz és költség fordítása
            var statusLabel = translateStatus(message.status);
            var costDisplay = (message.cost > 0) ? message.cost.toFixed(2) + ' Ft' : '-';
            
            if ($existingRow.length > 0) {
                // Sor frissítése
                $existingRow.find('.message-status').text(statusLabel)
                           .removeClass('status-pending status-processing status-sent status-delivered status-failed')
                           .addClass('status-' + message.status);
                
                $existingRow.find('.message-cost').text(costDisplay);
            } else {
                // Új sor hozzáadása
                var newRow = '<tr data-message-id="' + message.message_id + '">' +
                             '<td>' + message.phone + '</td>' +
                             '<td>' + message.sent_at + '</td>' +
                             '<td><span class="message-status status-' + message.status + '">' + statusLabel + '</span></td>' +
                             '<td class="message-cost">' + costDisplay + '</td>' +
                             '</tr>';
                
                $tableBody.append(newRow);
            }
        });
    }
    
    // Státusz fordítása
    function translateStatus(status) {
        var translations = {
            'pending': 'Függőben',
            'processing': 'Feldolgozás alatt',
            'sent': 'Elküldve',
            'delivered': 'Kézbesítve',
            'failed': 'Sikertelen',
            'error': 'Hiba'
        };
        
        return translations[status] || status;
    }
    
    // Monitorozás inicializálása
    function initCampaignMonitor(campaignId) {
        settings.campaignId = campaignId;
        settings.isActive = true;
        
        // Első frissítés azonnal
        updateCampaignStats();
        
        // Állapot jelző
        $('#fcbip-campaign-monitor').show();
        $('#fcbip-monitoring-status').text('Kampány státusz figyelése aktív...');
    }
    
    // Monitorozás kikapcsolása
    function stopCampaignMonitor() {
        settings.isActive = false;
        $('#fcbip-monitoring-status').text('Kampány figyelés leállítva');
    }
    
    // Nyilvános API
    window.fcbipCampaignMonitor = {
        start: initCampaignMonitor,
        stop: stopCampaignMonitor,
        refresh: updateCampaignStats
    };
    
    // Automatikus inicializálás, ha van kampányazonosító
    $(document).ready(function() {
        var campaignId = $('#fcbip-campaign-id').val();
        if (campaignId) {
            initCampaignMonitor(campaignId);
        }
    });
    
})(jQuery);