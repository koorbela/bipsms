(function($) {
    'use strict';
    
    // React komponens a BIP SMS Action-höz
    const BipSmsAction = function(props) {
        const { action, onSettingsChange, onError } = props;
        const { settings } = action;
        
        // Alapértelmezett beállítások
        const defaultSettings = {
            message: '',
            recipient_type: 'phone',
            custom_phone: ''
        };
        
        // Egyesítsük az aktuális és az alapértelmezett beállításokat
        const currentSettings = Object.assign({}, defaultSettings, settings);
        
        // Beállítások frissítése
        const updateSettings = (key, value) => {
            const newSettings = { ...currentSettings };
            newSettings[key] = value;
            onSettingsChange(newSettings);
        };
        
        // Komponens renderelése
        return React.createElement('div', { className: 'fc_bip_sms_wrapper' },
            // Címsor
            React.createElement('div', { className: 'fc-automation-block-title' },
                React.createElement('h3', {}, 'BIP SMS küldése')
            ),
            
            // Üzenet szövegdoboz
            React.createElement('div', { className: 'fc-field-wrap' },
                React.createElement('label', {}, 'SMS Üzenet'),
                React.createElement('textarea', {
                    value: currentSettings.message,
                    onChange: (e) => updateSettings('message', e.target.value),
                    placeholder: 'Írd be az SMS szövegét...',
                    className: 'fc-field-input'
                })
            ),
            
            // Címzett típusa
            React.createElement('div', { className: 'fc-field-wrap' },
                React.createElement('label', {}, 'Telefonszám'),
                React.createElement('div', { className: 'fc-field-input-group' },
                    React.createElement('select', {
                        value: currentSettings.recipient_type,
                        onChange: (e) => updateSettings('recipient_type', e.target.value),
                        className: 'fc-field-input'
                    },
                        React.createElement('option', { value: 'phone' }, 'Kapcsolat telefonszáma'),
                        React.createElement('option', { value: 'custom' }, 'Egyedi telefonszám')
                    )
                )
            ),
            
            // Egyedi telefonszám (csak ha a típus 'custom')
            currentSettings.recipient_type === 'custom' && 
            React.createElement('div', { className: 'fc-field-wrap' },
                React.createElement('label', {}, 'Egyedi telefonszám'),
                React.createElement('input', {
                    type: 'text',
                    value: currentSettings.custom_phone,
                    onChange: (e) => updateSettings('custom_phone', e.target.value),
                    placeholder: 'Pl.: 36301234567',
                    className: 'fc-field-input'
                })
            ),
            
            // Segítség
            React.createElement('div', { className: 'fc-field-wrap fc-hint' },
                React.createElement('p', {}, 
                    'A telefonszámot csak számjegyekkel add meg, pl. 36301234567.'
                )
            )
        );
    };
    
    // Regisztráljuk a komponenst a FluentCRM számára
    if (window.FluentCRMApp && window.FluentCRMApp.registerAction) {
        window.FluentCRMApp.registerAction('bip_sms_send', BipSmsAction);
    }
    
})(jQuery);
