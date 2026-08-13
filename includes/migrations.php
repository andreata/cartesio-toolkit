<?php
/**
 * Modulo: migrazioni versionate del database.
 *
 * Registra i comandi WP-CLI `wp cartesio migrate`, `migrate-status`, `migrate-mark`
 * e `drift`. Le migration vivono in db/migrations/ nel repo del progetto.
 *
 * @package Cartesio\Toolkit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Gestione delle migrazioni del database.
 *
 * Una migration è un file PHP in db/migrations/ che restituisce una closure.
 * Il nome del file è il suo identificativo ed è usato per l'ordinamento:
 *
 *     2026_08_12_1030_woo_shipping_zones.php
 *
 * La closure riceve un logger e deve essere IDEMPOTENTE: rieseguirla non deve
 * rompere nulla. Il tracking evita comunque le riesecuzioni.
 */
final class CARTESIO_Migrations {

	/**
	 * Opzione in cui viene tracciato lo stato.
	 *
	 * @var string
	 */
	const OPTION = 'cartesio_migrations_applied';

	/**
	 * Directory delle migration.
	 *
	 * @return string
	 */
	public static function dir() {
		if ( defined( 'CARTESIO_MIGRATIONS_DIR' ) ) {
			return rtrim( CARTESIO_MIGRATIONS_DIR, '/' );
		}

		$root = defined( 'CARTESIO_ROOT_DIR' ) ? CARTESIO_ROOT_DIR : dirname( ABSPATH, 2 );

		return rtrim( $root, '/' ) . '/db/migrations';
	}

	/**
	 * Migration presenti su disco, ordinate per nome.
	 *
	 * @return array<string,string> id => path
	 */
	public static function available() {
		$files = glob( self::dir() . '/*.php' );

		if ( empty( $files ) ) {
			return array();
		}

		sort( $files, SORT_STRING );

		$out = array();
		foreach ( $files as $file ) {
			$out[ basename( $file, '.php' ) ] = $file;
		}

		return $out;
	}

	/**
	 * Migration già applicate.
	 *
	 * @return array<string,string> id => data ISO
	 */
	public static function applied() {
		$applied = get_option( self::OPTION, array() );

		return is_array( $applied ) ? $applied : array();
	}

	/**
	 * Migration da applicare.
	 *
	 * @return array<string,string>
	 */
	public static function pending() {
		return array_diff_key( self::available(), self::applied() );
	}

	/**
	 * Registra una migration come applicata.
	 *
	 * @param string $id Identificativo.
	 * @return void
	 */
	public static function mark( $id ) {
		$applied        = self::applied();
		$applied[ $id ] = gmdate( 'c' );

		ksort( $applied );

		update_option( self::OPTION, $applied, false );
	}

	/**
	 * Rimuove una migration dal registro.
	 *
	 * @param string $id Identificativo.
	 * @return void
	 */
	public static function unmark( $id ) {
		$applied = self::applied();
		unset( $applied[ $id ] );

		update_option( self::OPTION, $applied, false );
	}
}

/**
 * Comandi WP-CLI per le migrazioni e la diagnostica del progetto.
 */
final class CARTESIO_CLI_Command {

	/**
	 * Applica le migration in sospeso.
	 *
	 * ## OPTIONS
	 *
	 * [--pretend]
	 * : Mostra cosa verrebbe applicato senza eseguire nulla.
	 *
	 * [--only=<id>]
	 * : Applica una sola migration, anche se già registrata.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cartesio migrate
	 *     wp cartesio migrate --pretend
	 *     wp cartesio migrate --only=2026_08_12_1030_woo_shipping_zones
	 *
	 * @param array $args       Argomenti posizionali.
	 * @param array $assoc_args Opzioni.
	 * @return void
	 */
	public function migrate( $args, $assoc_args ) {
		$pretend = isset( $assoc_args['pretend'] );
		$only    = isset( $assoc_args['only'] ) ? $assoc_args['only'] : null;

		if ( $only ) {
			$available = CARTESIO_Migrations::available();

			if ( ! isset( $available[ $only ] ) ) {
				WP_CLI::error( sprintf( 'Migration "%s" non trovata in %s', $only, CARTESIO_Migrations::dir() ) );
			}

			$queue = array( $only => $available[ $only ] );
		} else {
			$queue = CARTESIO_Migrations::pending();
		}

		if ( empty( $queue ) ) {
			WP_CLI::success( 'Nessuna migration in sospeso.' );
			return;
		}

		WP_CLI::log( sprintf( '%d migration da applicare su %s.', count( $queue ), WP_ENV ) );

		foreach ( $queue as $id => $file ) {
			if ( $pretend ) {
				WP_CLI::log( '  [pretend] ' . $id );
				continue;
			}

			$start = microtime( true );

			$migration = require $file;

			if ( ! is_callable( $migration ) ) {
				WP_CLI::error( sprintf( 'La migration "%s" non restituisce una closure.', $id ) );
			}

			try {
				$migration(
					function ( $message ) {
						WP_CLI::log( '      ' . $message );
					}
				);
			} catch ( \Throwable $e ) {
				WP_CLI::error(
					sprintf(
						"Migration \"%s\" fallita: %s\nNessuna migration successiva è stata applicata.",
						$id,
						$e->getMessage()
					)
				);
			}

			CARTESIO_Migrations::mark( $id );

			WP_CLI::log(
				sprintf( '  ✓ %s (%.2fs)', $id, microtime( true ) - $start )
			);
		}

		if ( ! $pretend ) {
			wp_cache_flush();
			WP_CLI::success( 'Migration applicate.' );
		}
	}

	/**
	 * Mostra lo stato delle migration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cartesio migrate-status
	 *
	 * @return void
	 */
	public function migrate_status() {
		$available = CARTESIO_Migrations::available();
		$applied   = CARTESIO_Migrations::applied();

		if ( empty( $available ) ) {
			WP_CLI::warning( 'Nessuna migration trovata in ' . CARTESIO_Migrations::dir() );
			return;
		}

		$rows = array();
		foreach ( $available as $id => $file ) {
			$rows[] = array(
				'migration' => $id,
				'stato'     => isset( $applied[ $id ] ) ? 'applicata' : 'IN SOSPESO',
				'data'      => isset( $applied[ $id ] ) ? $applied[ $id ] : '—',
			);
		}

		// Migration registrate ma non più presenti su disco: segnale di drift.
		foreach ( array_diff_key( $applied, $available ) as $id => $date ) {
			$rows[] = array(
				'migration' => $id,
				'stato'     => 'ORFANA (file mancante)',
				'data'      => $date,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'migration', 'stato', 'data' ) );
	}

	/**
	 * Registra una migration come applicata senza eseguirla.
	 *
	 * Utile quando si adotta il sistema su un sito esistente in cui la modifica
	 * è già stata fatta a mano.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identificativo della migration.
	 *
	 * [--undo]
	 * : Rimuove invece la registrazione.
	 *
	 * @param array $args       Argomenti posizionali.
	 * @param array $assoc_args Opzioni.
	 * @return void
	 */
	public function migrate_mark( $args, $assoc_args ) {
		$id = $args[0];

		if ( isset( $assoc_args['undo'] ) ) {
			CARTESIO_Migrations::unmark( $id );
			WP_CLI::success( sprintf( '"%s" rimossa dal registro.', $id ) );
			return;
		}

		CARTESIO_Migrations::mark( $id );
		WP_CLI::success( sprintf( '"%s" registrata come applicata.', $id ) );
	}

	/**
	 * Verifica se un record di stili globali è quello vuoto creato da WordPress.
	 *
	 * Si decodifica il JSON invece di confrontare la stringa: WordPress non
	 * garantisce la formattazione e basta uno spazio in più per far sembrare
	 * modificati stili che nessuno ha toccato.
	 *
	 * @param string $contenuto Contenuto del post.
	 * @return bool
	 */
	private static function stili_globali_vuoti( $contenuto ) {
		$contenuto = trim( $contenuto );

		if ( '' === $contenuto ) {
			return true;
		}

		$dati = json_decode( $contenuto, true );

		if ( ! is_array( $dati ) ) {
			return false;
		}

		// Conta solo ciò che un utente può aver scritto dal Site Editor.
		foreach ( array( 'styles', 'settings' ) as $chiave ) {
			if ( ! empty( $dati[ $chiave ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rileva il drift: contenuti in DB che dovrebbero vivere su file.
	 *
	 * Quando qualcuno modifica un template dal Site Editor, WordPress salva un
	 * post che fa override del file del tema. Da quel momento il repo mente.
	 * Questo comando elenca tutti gli override presenti.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Elimina gli override trovati, ripristinando i file del tema.
	 *
	 * [--porcelain]
	 * : Output minimale, exit code 1 se c'è drift. Utile in CI o nel deploy.
	 *
	 * [--include-menus]
	 * : Considera drift anche i menu (wp_navigation). Di default sono esclusi:
	 * su un block theme i menu vivono solo nel database, come i contenuti,
	 * e segnalarli sempre renderebbe il controllo inutilizzabile.
	 *
	 * @param array $args       Argomenti posizionali.
	 * @param array $assoc_args Opzioni.
	 * @return void
	 */
	public function drift( $args, $assoc_args ) {
		$types = array(
			'wp_template'      => 'Template',
			'wp_template_part' => 'Parte di template',
			'wp_global_styles' => 'Stili globali',
		);

		if ( isset( $assoc_args['include-menus'] ) ) {
			$types['wp_navigation'] = 'Menu di navigazione';
		}

		$found = array();

		foreach ( $types as $type => $label ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				)
			);

			foreach ( $posts as $post ) {
				// Gli stili globali di default vengono creati da WP: contano solo se modificati.
				if ( 'wp_global_styles' === $type && self::stili_globali_vuoti( $post->post_content ) ) {
					continue;
				}

				$found[] = array(
					'tipo'       => $label,
					'slug'       => $post->post_name,
					'id'         => $post->ID,
					'modificato' => $post->post_modified,
				);
			}
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			if ( empty( $found ) ) {
				return;
			}

			foreach ( $found as $row ) {
				WP_CLI::log( $row['tipo'] . ': ' . $row['slug'] );
			}

			WP_CLI::halt( 1 );
		}

		if ( empty( $found ) ) {
			WP_CLI::success( 'Nessun drift: struttura e stili vivono interamente su file.' );
			return;
		}

		WP_CLI::warning( sprintf( '%d override presenti nel database.', count( $found ) ) );
		WP_CLI\Utils\format_items( 'table', $found, array( 'tipo', 'slug', 'id', 'modificato' ) );

		if ( ! isset( $assoc_args['reset'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Per riportare tutto ai file del tema: wp cartesio drift --reset' );
			WP_CLI::log( 'Attenzione: le modifiche fatte dal Site Editor andranno perse.' );
			return;
		}

		WP_CLI::confirm( 'Eliminare tutti gli override e ripristinare i file del tema?', $assoc_args );

		foreach ( $found as $row ) {
			wp_delete_post( $row['id'], true );
			WP_CLI::log( '  ✓ rimosso ' . $row['slug'] );
		}

		wp_cache_flush();
		WP_CLI::success( 'Override rimossi.' );
	}
}

WP_CLI::add_command( 'cartesio migrate', array( 'CARTESIO_CLI_Command', 'migrate' ) );
WP_CLI::add_command( 'cartesio migrate-status', array( 'CARTESIO_CLI_Command', 'migrate_status' ) );
WP_CLI::add_command( 'cartesio migrate-mark', array( 'CARTESIO_CLI_Command', 'migrate_mark' ) );
WP_CLI::add_command( 'cartesio drift', array( 'CARTESIO_CLI_Command', 'drift' ) );
