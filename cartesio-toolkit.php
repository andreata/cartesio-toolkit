<?php
/**
 * Plugin Name: Cartesio Toolkit
 * Plugin URI:  https://github.com/andreata/cartesio-toolkit
 * Description: Infrastruttura condivisa dei progetti Cartesio: migrazioni versionate, guardrail sugli ambienti, separazione fra editing di contenuti e di struttura.
 * Version:     1.0.1
 * Author:      Cartesio
 * License:     Proprietary
 * Text Domain: cartesio
 *
 * Questo pacchetto è una dipendenza Composer, non codice di progetto:
 * NON va modificato dentro il singolo sito. Le correzioni si fanno sul repo
 * cartesio/toolkit e arrivano ai siti con `composer update cartesio/toolkit`.
 *
 * @package Cartesio\Toolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'CARTESIO_TOOLKIT_VERSION', '1.0.1' );
define( 'CARTESIO_TOOLKIT_DIR', __DIR__ );

/**
 * Moduli attivi.
 *
 * Un progetto può disattivarne uno definendo la costante nel proprio
 * config/application.php, per esempio:
 *
 *     Config::define( 'CARTESIO_TOOLKIT_MODULES', array( 'migrations', 'env-guard' ) );
 *
 * @return array
 */
function cartesio_toolkit_modules() {
	$default = array( 'migrations', 'env-guard', 'editor-lock' );

	$modules = defined( 'CARTESIO_TOOLKIT_MODULES' ) ? (array) CARTESIO_TOOLKIT_MODULES : $default;

	/**
	 * Filtra i moduli del toolkit da caricare.
	 *
	 * @param array $modules Slug dei moduli.
	 */
	return apply_filters( 'cartesio_toolkit_modules', $modules );
}

foreach ( cartesio_toolkit_modules() as $cartesio_module ) {
	$cartesio_module_file = CARTESIO_TOOLKIT_DIR . '/includes/' . $cartesio_module . '.php';

	if ( is_readable( $cartesio_module_file ) ) {
		require_once $cartesio_module_file;
	}
}

unset( $cartesio_module, $cartesio_module_file );
