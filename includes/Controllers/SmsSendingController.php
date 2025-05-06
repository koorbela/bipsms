<?php
/**
 *  SmsSendingController
 *
 *  AJAX-végpont az SMS-kampányok indításához.
 *  – Nonce-védelem
 *  – Jogosultság-ellenőrzés (manage_options)
 *  – Kampány-ID validálás
 *  – Aszinkron feldolgozás Action Schedulerrel
 *
 *  2025-04-25 – első implementáció
 */

namespace FCBipSms\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SmsSendingController {

	/**
	 * Bootstrap
	 */
	public static function init() {
		// Admin-oldali AJAX-hívás (wp.ajax.post)
		add_action( 'wp_ajax_fcbip_send_sms_campaign', [ __CLASS__, 'handleAjax' ] );
	}

	/**
	 * AJAX handler
	 */
	public static function handleAjax() {

		// Nonce
		check_ajax_referer( 'fcbip_send_sms', 'nonce' );

		// Jogosultság
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'fcbip' ), 403 );
		}

		// Kampány-ID
		$campaignId = absint( $_POST['campaign_id'] ?? 0 );
		if ( ! $campaignId ) {
			wp_send_json_error( esc_html__( 'Invalid campaign ID', 'fcbip' ), 400 );
		}

		/**
		 * Aszinkron feldolgozás
		 *  – a valódi küldést a fcbip_process_sms_campaign action végzi,
		 *    amit a SmsScheduler osztályban definiálunk.
		 */
		as_enqueue_async_action(
			'fcbip_process_sms_campaign',
			[ 'campaign_id' => $campaignId ],
			'FCBipSms'
		);

		wp_send_json_success( [ 'enqueued' => true, 'campaign_id' => $campaignId ] );
	}
}

// Bootstrap
FCBipSms\Controllers\SmsSendingController::init();
