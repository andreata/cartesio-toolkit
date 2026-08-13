<?php
/**
 * Modulo: guardrail sugli ambienti non-production.
 *
 * Blocca email, webhook e pagamenti reali fuori dalla produzione, così staging
 * e locale non possono fare danni verso il mondo esterno.
 *
 * @package Cartesio\Toolkit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_ENV' ) || 'production' === WP_ENV ) {
	return;
}

/**
 * Blocca l'invio di email verso destinatari non in allowlist.
 *
 * L'allowlist si configura con CARTESIO_MAIL_ALLOWLIST nel .env (domini separati
 * da virgola). Vuota = nessuna email esce.
 */
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		$allowlist = array_filter(
			array_map( 'trim', explode( ',', (string) getenv( 'CARTESIO_MAIL_ALLOWLIST' ) ) )
		);

		$recipients = (array) ( isset( $atts['to'] ) ? $atts['to'] : array() );

		foreach ( $recipients as $recipient ) {
			$domain = substr( strrchr( $recipient, '@' ), 1 );

			if ( $domain && in_array( strtolower( $domain ), array_map( 'strtolower', $allowlist ), true ) ) {
				return $short_circuit; // Destinatario interno: lascia passare.
			}
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[cartesio-env-guard] Email bloccata (%s) verso: %s',
					isset( $atts['subject'] ) ? $atts['subject'] : 'senza oggetto',
					implode( ', ', $recipients )
				)
			);
		}

		return true; // Finge l'invio riuscito senza spedire nulla.
	},
	10,
	2
);

/**
 * WooCommerce: nessun webhook esce da staging.
 */
add_filter( 'woocommerce_webhook_should_deliver', '__return_false', 999 );

/**
 * WooCommerce: gateway di pagamento in sandbox o disattivati.
 */
add_filter(
	'woocommerce_available_payment_gateways',
	function ( $gateways ) {
		foreach ( $gateways as $id => $gateway ) {
			// I gateway che espongono una modalità test la usano forzatamente.
			if ( isset( $gateway->settings['testmode'] ) ) {
				$gateways[ $id ]->settings['testmode'] = 'yes';
				$gateways[ $id ]->testmode             = true;
				continue;
			}

			// Gli altri restano solo se sono metodi offline (bonifico, contrassegno).
			if ( ! in_array( $id, array( 'bacs', 'cheque', 'cod' ), true ) ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	},
	999
);

/**
 * Action Scheduler / cron Woo: opzionalmente in pausa su staging.
 */
if ( '1' === (string) getenv( 'CARTESIO_DISABLE_SCHEDULER' ) ) {
	add_filter( 'action_scheduler_disable_default_runner', '__return_true' );
	add_filter( 'action_scheduler_queue_runner_batch_size', '__return_zero' );
}

/**
 * Badge dell'ambiente nella admin bar: impossibile confondere staging e produzione.
 */
add_action(
	'admin_bar_menu',
	function ( $bar ) {
		$colors = array(
			'development' => '#2271b1',
			'staging'     => '#d63638',
		);

		$color = isset( $colors[ WP_ENV ] ) ? $colors[ WP_ENV ] : '#8c8f94';

		$bar->add_node(
			array(
				'id'    => 'cartesio-env',
				'title' => strtoupper( WP_ENV ),
				'href'  => false,
				'meta'  => array(
					'html' => sprintf(
						'<style>#wp-admin-bar-cartesio-env > .ab-item{background:%s !important;color:#fff !important;font-weight:700 !important;}</style>',
						esc_attr( $color )
					),
				),
			)
		);
	},
	5
);

/**
 * Nessun servizio esterno di analytics o tracking fuori dalla produzione.
 */
add_filter( 'woocommerce_apply_tracking', '__return_false' );
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
