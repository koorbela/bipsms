<?php

namespace FluentCrmBipSms\Controllers;

class SmsCampaignController
{
    public function index()
    {
        // SMS kampányok listázása
        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaigns-index.php';
    }
    
    public function create()
    {
        // Új SMS kampány létrehozása űrlap
        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php';
    }
    
    public function store()
    {
        // Új SMS kampány mentése
    }
    
    public function edit($campaignId)
    {
        // SMS kampány szerkesztése
        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-editor.php';
    }
    
    public function update($campaignId)
    {
        // SMS kampány frissítése
    }
    
    public function delete($campaignId)
    {
        // SMS kampány törlése
    }
    
    public function report($campaignId)
    {
        // SMS kampány jelentések
        include FCBIP_SMS_PLUGIN_DIR . 'templates/sms-campaign-reports.php';
    }
}
