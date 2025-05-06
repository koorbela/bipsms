<?php

/**
 * SMS státusz kezelő osztály
 */
class FCBIP_Status_Manager {
    
    /**
     * Státusz hierarchia
     */
    private static $status_hierarchy = [
        'error'      => 0,
        'failed'     => 0,
        'pending'    => 1,
        'processing' => 2,
        'sent'       => 3,
        'delivered'  => 4
    ];
    
    /**
     * Státusz fordítások
     */
    private static $status_labels = [
        'error'      => 'Hiba',
        'failed'     => 'Sikertelen',
        'pending'    => 'Függőben',
        'processing' => 'Feldolgozás alatt',
        'sent'       => 'Elküldve',
        'delivered'  => 'Kézbesítve'
    ];
    
    /**
     * Státusz címkék lekérése
     */
    public static function getStatusLabel($status) {
        return isset(self::$status_labels[$status]) 
               ? self::$status_labels[$status] 
               : ucfirst($status);
    }
    
    /**
     * Státusz frissítése, csak akkor, ha magasabb prioritású
     */
    public static function updateStatus($messageId, $newStatus) {
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_messages';
        
        // Jelenlegi státusz lekérése
        $currentStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE message_id = %s",
            $messageId
        ));
        
        if (!$currentStatus) {
            return false;
        }
        
        // Ellenőrizzük, hogy a státusz frissíthető-e
        $currentPriority = isset(self::$status_hierarchy[$currentStatus]) 
                          ? self::$status_hierarchy[$currentStatus] 
                          : -1;
                          
        $newPriority = isset(self::$status_hierarchy[$newStatus]) 
                      ? self::$status_hierarchy[$newStatus] 
                      : -1;
        
        // Csak akkor frissítünk, ha az új státusz magasabb prioritású
        if ($newPriority > $currentPriority) {
            $updateData = ['status' => $newStatus];
            
            // Ha kézbesítve, akkor beállítjuk a kézbesítési időt
            if ($newStatus === 'delivered') {
                $updateData['delivered_at'] = current_time('mysql');
            }
            
            $wpdb->update(
                $table,
                $updateData,
                ['message_id' => $messageId],
                null,
                ['%s']
            );
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Költség frissítése, csak akkor ha nincs még beállítva vagy nagyobb
     */
    public static function updateCost($messageId, $cost) {
        if (!is_numeric($cost) || $cost <= 0) {
            return false;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'fcbip_sms_messages';
        
        // Jelenlegi költség lekérése
        $currentCost = $wpdb->get_var($wpdb->prepare(
            "SELECT cost FROM {$table} WHERE message_id = %s",
            $messageId
        ));
        
        // Csak akkor frissítünk, ha nincs még költség vagy az új nagyobb
        if ($currentCost === null || $currentCost == 0 || $cost > $currentCost) {
            $wpdb->update(
                $table,
                ['cost' => $cost],
                ['message_id' => $messageId],
                ['%f'],
                ['%s']
            );
            
            return true;
        }
        
        return false;
    }
}