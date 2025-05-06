<?php
/**
 * Helper functions for BIP SMS plugin
 */

if (!defined('ABSPATH')) exit; // Biztonsági kilépés közvetlen hozzáférés esetén

/**
 * Get available smart codes for SMS campaigns
 * 
 * @return array
 */
function fcbip_get_smart_codes()
{
    if (function_exists('fluentcrm_get_available_smart_codes')) {
        // Ha létezik a FluentCRM függvény, használjuk azt
        return fluentcrm_get_available_smart_codes();
    }
    
    // Ha nem létezik, adjunk vissza egy alapértelmezett listát
    return [
        'contact' => [
            'title' => 'Kapcsolat',
            'shortcodes' => [
                'contact.first_name' => ['title' => 'Keresztnév'],
                'contact.last_name' => ['title' => 'Vezetéknév'],
                'contact.full_name' => ['title' => 'Teljes név'],
                'contact.email' => ['title' => 'Email cím'],
                'contact.phone' => ['title' => 'Telefonszám']
            ]
        ],
        'general' => [
            'title' => 'Általános',
            'shortcodes' => [
                'site.title' => ['title' => 'Weboldal címe'],
                'site.url' => ['title' => 'Weboldal URL'],
                'unsubscribe_url' => ['title' => 'Leiratkozás URL']
            ]
        ]
    ];
}
