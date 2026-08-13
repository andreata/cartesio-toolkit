<?php
/**
 * Modulo: separazione fra editing di contenuti e di struttura.
 *
 * Il cliente compone contenuti con i pattern e i blocchi del progetto; template,
 * stili globali e struttura restano nei file del tema.
 *
 * @package Cartesio\Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profili di editing riconosciuti, dal più chiuso al più aperto.
 */
const CARTESIO_EDITING_PROFILES = array( 'contenuti', 'composizione', 'struttura' );

/**
 * Profilo di editing del sito.
 *
 * Si dichiara per progetto con `define( 'CARTESIO_EDITING', '...' )` in
 * config/application.php. Il default è il profilo più chiuso: un sito che non
 * dichiara nulla non deve trovarsi il Site Editor aperto per distrazione.
 *
 * - contenuti     Il cliente riempie testi e immagini dentro strutture bloccate.
 *                 Nessun inseritore: l'editor si comporta come un form.
 * - composizione  Il cliente compone la pagina con i blocchi in allowlist.
 *                 Template e stili globali restano nei file del tema.
 * - struttura     Il Site Editor è aperto ai ruoli di cartesio_site_editor_roles().
 *                 Richiede un block theme.
 *
 * @return string
 */
function cartesio_editing_profile() {
	$profile = defined( 'CARTESIO_EDITING' ) ? CARTESIO_EDITING : 'contenuti';

	if ( ! in_array( $profile, CARTESIO_EDITING_PROFILES, true ) ) {
		$profile = 'contenuti';
	}

	/**
	 * Filtra il profilo di editing del sito.
	 *
	 * @param string $profile Uno fra contenuti, composizione, struttura.
	 */
	$profile = apply_filters( 'cartesio_editing_profile', $profile );

	return in_array( $profile, CARTESIO_EDITING_PROFILES, true ) ? $profile : 'contenuti';
}

/**
 * Verifica se il profilo del sito arriva almeno a un dato livello.
 *
 * @param string $livello Profilo minimo richiesto.
 * @return bool
 */
function cartesio_editing_almeno( $livello ) {
	$ordine = array_flip( CARTESIO_EDITING_PROFILES );

	if ( ! isset( $ordine[ $livello ] ) ) {
		return false;
	}

	return $ordine[ cartesio_editing_profile() ] >= $ordine[ $livello ];
}

/**
 * Ruoli che mantengono l'accesso completo al Site Editor.
 * Di norma solo chi lavora anche sul repo.
 *
 * @return array
 */
function cartesio_site_editor_roles() {
	/**
	 * Filtra i ruoli abilitati al Site Editor.
	 *
	 * @param array $roles Slug dei ruoli.
	 */
	return apply_filters( 'cartesio_site_editor_roles', array( 'administrator' ) );
}

/**
 * Verifica se l'utente corrente può editare la struttura del sito.
 *
 * @return bool
 */
function cartesio_can_edit_structure() {
	$user = wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	// In produzione anche gli admin passano dal repo: nessuno edita i template dal browser.
	if ( defined( 'WP_ENV' ) && 'production' === WP_ENV && ! defined( 'CARTESIO_ALLOW_SITE_EDITOR' ) ) {
		return false;
	}

	// Solo il profilo più aperto concede la struttura, e solo su un block theme:
	// sui temi classici il Site Editor non esiste e sbloccarlo non significa nulla.
	if ( ! cartesio_editing_almeno( 'struttura' ) || ! wp_is_block_theme() ) {
		return false;
	}

	return (bool) array_intersect( cartesio_site_editor_roles(), (array) $user->roles );
}

/**
 * Rimuove la capability che apre il Site Editor a chi non deve averla.
 */
add_filter(
	'map_meta_cap',
	function ( $caps, $cap ) {
		$structural = array( 'edit_theme_options', 'customize', 'edit_theme_option' );

		if ( in_array( $cap, $structural, true ) && ! cartesio_can_edit_structure() ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	},
	10,
	2
);

/**
 * Applica il profilo di editing all'editor a blocchi.
 *
 * Il pezzo che conta è `templateLock => contentOnly` sul profilo "contenuti":
 * l'inseritore sparisce e restano modificabili solo i campi di testo e le
 * immagini già previsti dal template. Per il cliente l'editor diventa un form,
 * e nessuno può improvvisare un layout. È l'unico modo di ottenerlo restando
 * su core, senza page builder e senza layout serializzato in postmeta.
 */
add_filter(
	'block_editor_settings_all',
	function ( $settings ) {
		if ( cartesio_can_edit_structure() ) {
			return $settings;
		}

		$settings['supportsTemplateMode']                  = false;
		$settings['supportsLayout']                        = true;
		$settings['__experimentalDisableCustomLineHeight'] = true;

		if ( 'contenuti' === cartesio_editing_profile() ) {
			$settings['templateLock'] = 'contentOnly';
		}

		return $settings;
	},
	999
);

/**
 * Nasconde le voci di menu strutturali.
 */
add_action(
	'admin_menu',
	function () {
		if ( cartesio_can_edit_structure() ) {
			return;
		}

		remove_submenu_page( 'themes.php', 'themes.php' );
		remove_submenu_page( 'themes.php', 'site-editor.php' );
		remove_submenu_page( 'themes.php', 'site-editor.php?path=%2Fpatterns' );
	},
	999
);

/**
 * Blocca l'accesso diretto a site-editor.php via URL.
 */
add_action(
	'admin_init',
	function () {
		global $pagenow;

		if ( 'site-editor.php' === $pagenow && ! cartesio_can_edit_structure() ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}
);

/**
 * Registra i pattern del tema come categoria dedicata,
 * così il cliente trova subito i blocchi approvati.
 */
add_action(
	'init',
	function () {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			'cartesio',
			array( 'label' => __( 'Blocchi del progetto', 'cartesio' ) )
		);
	}
);

/**
 * Rimuove i pattern remoti del Pattern Directory: il cliente usa solo i nostri.
 */
add_filter( 'should_load_remote_block_patterns', '__return_false' );
