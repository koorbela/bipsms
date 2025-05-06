<?php

namespace FluentCrmBipSms\Automations;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class BipSmsAction extends BaseAction
{
    /**
     * Initialize the action
     */
    public function __construct()
    {
        // Ez a kritikus rész! Változtassuk vissza az eredeti értékre
        $this->actionName = 'bip_sms_send';
        $this->priority = 20;
        
        parent::__construct();
        
        // Regisztráljuk a szükséges hook-okat a beállítások kezeléséhez
        add_filter('fluentcrm_funnel_sequence_saving_' . $this->actionName, array($this, 'savingAction'), 10, 2);
        add_filter('fluentcrm_funnel_sequence_filtered_' . $this->actionName, array($this, 'gettingAction'), 10, 2);
        
        if (function_exists('fcbip_log')) {
            fcbip_log('BipSmsAction initialized with actionName: ' . $this->actionName);
        }
    }

    /**
     * Get the action block for the automation
     * 
     * @return array
     */
    public function getBlock()
    {
        return [
            'category'    => __('BIP SMS', 'fluent-crm-bip-sms'),
            'title'       => __('Send SMS via BIP', 'fluent-crm-bip-sms'),
            'description' => __('Send an SMS to the contact via BIP SMS service', 'fluent-crm-bip-sms'),
            'icon'        => 'fc-icon-sms', // FluentCRM ikon
        ];
    }

    /**
     * Get the settings fields for the action block
     * 
     * @return array
     */
    public function getBlockFields()
    {
        return [
            'title'     => __('Send SMS via BIP SMS Service', 'fluent-crm-bip-sms'),
            'sub_title' => __('Send an SMS to this contact using the BIP SMS API', 'fluent-crm-bip-sms'),
            'fields'    => [
                'phone_source' => [
                    'type'        => 'radio',
                    'label'       => __('Phone Number Source', 'fluent-crm-bip-sms'),
                    'options'     => [
                        [
                            'id' => 'contact',
                            'title' => __('Use Contact\'s Phone Number', 'fluent-crm-bip-sms')
                        ],
                        [
                            'id' => 'custom',
                            'title' => __('Use Custom Phone Number', 'fluent-crm-bip-sms')
                        ]
                    ],
                    'default'     => 'contact'
                ],
                'custom_phone' => [
                    'type'        => 'input-text',
                    'label'       => __('Custom Phone Number', 'fluent-crm-bip-sms'),
                    'placeholder' => __('Enter a phone number (e.g. 36201234567)', 'fluent-crm-bip-sms'),
                    'dependency'  => [
                        'depends_on' => 'phone_source',
                        'operator'   => '=',
                        'value'      => 'custom'
                    ]
                ],
                'message' => [
                    'type'        => 'input-text',
                    'label'       => __('SMS Message', 'fluent-crm-bip-sms'),
                    'placeholder' => __('Enter your SMS message here. You can use smart codes.', 'fluent-crm-bip-sms'),
                    'help'        => __('Available Smart Codes: {{contact.first_name}}, {{contact.last_name}}, {{contact.email}}', 'fluent-crm-bip-sms')
                ],
                'sender_id' => [
                    'type'        => 'input-text',
                    'label'       => __('Sender ID (optional)', 'fluent-crm-bip-sms'),
                    'placeholder' => __('Leave empty for default sender', 'fluent-crm-bip-sms'),
                    'help'        => __('Specify a custom sender ID if needed', 'fluent-crm-bip-sms')
                ]
            ]
        ];
    }

    /**
     * Handle the action execution
     * 
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     * @param \FluentCrm\App\Models\FunnelSubscriber $funnelSubscriber
     * @param \FluentCrm\App\Models\Funnel $funnel
     * @param array $sequence
     * @param int $funnelSubscriberId
     * @param \FluentCrm\App\Models\FunnelMetric $funnelMetric
     * 
     * @return boolean
     */
    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        
        // Parse the message for smart codes
        $message = Arr::get($settings, 'message', '');
        if (empty($message)) {
            $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'SMS message is empty');
            return false;
        }
        
        // Parse the message with smart codes
        $message = apply_filters('fluentcrm_parse_contact_variable', $message, $subscriber);
        
        // Get the phone number based on source selection
        $phoneSource = Arr::get($settings, 'phone_source', 'contact');
        $phoneNumber = '';
        
        if ($phoneSource === 'contact') {
            $phoneNumber = $subscriber->phone;
            if (empty($phoneNumber)) {
                $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'Contact does not have a phone number');
                return false;
            }
        } else {
            $phoneNumber = Arr::get($settings, 'custom_phone', '');
            if (empty($phoneNumber)) {
                $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'Custom phone number is empty');
                return false;
            }
        }
        
        // Format phone number (remove spaces and ensure it has country code)
        $phoneNumber = $this->formatPhoneNumber($phoneNumber);
        
        // Get sender ID if specified
        $senderId = Arr::get($settings, 'sender_id', '');
        
        // Prepare the API call and send the SMS
        if (class_exists('FCBIP_SMS_Service')) {
            try {
                $smsService = new \FCBIP_SMS_Service();
                // Itt módosítottuk send_sms helyett send metódust hívunk!
                $result = $smsService->send_sms($phoneNumber, $message);
                
                if ($result && isset($result['result']) && $result['result'] === 'OK') {
                    // Success
                    $note = sprintf(
                        __('SMS sent successfully to %s', 'fluent-crm-bip-sms'),
                        $phoneNumber
                    );
                    $funnelMetric->notes = $note;
                    $funnelMetric->save();
                    
                    if (function_exists('fcbip_log')) {
                        fcbip_log('SMS sent successfully: ' . $note);
                    }
                    
                    return true;
                } else {
                    // API call failed
                    $errorMessage = isset($result['message']) ? $result['message'] : 'Unknown error';
                    $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'API Error: ' . $errorMessage);
                    return false;
                }
            } catch (\Exception $e) {
                $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'Exception: ' . $e->getMessage());
                return false;
            }
        } else {
            $this->logError($funnelMetric, $funnelSubscriberId, $sequence, 'FCBIP_SMS_Service class not found');
            return false;
        }
    }
    
    /**
     * Log error to funnel metric and system log
     * 
     * @param object $funnelMetric
     * @param int $funnelSubscriberId
     * @param object $sequence
     * @param string $errorMessage
     * @return void
     */
    private function logError($funnelMetric, $funnelSubscriberId, $sequence, $errorMessage)
    {
        $note = sprintf(__('SMS sending failed: %s', 'fluent-crm-bip-sms'), $errorMessage);
        $funnelMetric->notes = $note;
        $funnelMetric->save();
        
        FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'failed');
        
        if (function_exists('fcbip_log')) {
            fcbip_log('SMS sending failed: ' . $errorMessage);
        }
    }
    
/**
 * A telefonszám formázása a BIP SMS API-nak megfelelő formátumra
 * 
 * @param string $phoneNumber
 * @return string
 */
private function formatPhoneNumber($phoneNumber)
{
    // Tisztítsuk a telefonszámot
    $phoneNumber = trim($phoneNumber);
    
    // Távolítsuk el a nemkívánatos karaktereket (szóköz, kötőjel, stb.)
    $phoneNumber = preg_replace('/[^\d+]/', '', $phoneNumber);
    
    // Ha a szám +36-tal kezdődik, távolítsuk el a + jelet
    if (strpos($phoneNumber, '+36') === 0) {
        $phoneNumber = substr($phoneNumber, 1);
    }
    // Ha a szám 06-tal kezdődik, cseréljük 36-ra
    else if (strpos($phoneNumber, '06') === 0) {
        $phoneNumber = '36' . substr($phoneNumber, 2);
    }
    // Ha a szám csak 0-val kezdődik, tegyük elé a 36-ot
    else if (strpos($phoneNumber, '0') === 0) {
        $phoneNumber = '36' . substr($phoneNumber, 1);
    }
    // Ha a szám nem 36-tal kezdődik, adjuk hozzá
    else if (strpos($phoneNumber, '36') !== 0) {
        $phoneNumber = '36' . $phoneNumber;
    }
    
    // Ellenőrizzük, hogy csak számokat tartalmaz-e
    if (!preg_match('/^[0-9]+$/', $phoneNumber)) {
        // Ha még mindig tartalmaz nem-szám karaktereket, távolítsuk el azokat
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    }
    
    if (function_exists('fcbip_log')) {
        fcbip_log('Formatted phone number: ' . $phoneNumber);
    }
    
    return $phoneNumber;
}


    
    /**
     * Process when saving the sequence
     * 
     * @param array $sequence
     * @param object $funnel
     * @return array
     */
    public function savingAction($sequence, $funnel)
    {
        // Egyszerű mentési logika - itt nincs szükség komplex adatstruktúrára
        // mint a SendEmailAction esetében
        return $sequence;
    }
    
    /**
     * Process when getting the sequence
     * 
     * @param array $sequence
     * @param object $funnel
     * @return array
     */
    public function gettingAction($sequence, $funnel)
    {
        // Biztosítsuk, hogy minden szükséges mező létezik
        if (empty($sequence['settings'])) {
            $sequence['settings'] = [];
        }
    
        // Alapértelmezett értékek beállítása, ha nem léteznek
        $defaults = [
            'phone_source' => 'contact',
            'custom_phone' => '',
            'message' => '',
            'sender_id' => ''
        ];
        
        $sequence['settings'] = wp_parse_args($sequence['settings'], $defaults);
        
        return $sequence;
    }
}
