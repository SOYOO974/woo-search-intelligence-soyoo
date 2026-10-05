<?php
/**
 * Installation, migrations de schéma versionnées et tâches planifiées
 *
 * Les mises à jour arrivant par GitHub Releases (PUC) ne déclenchent pas le hook
 * d'activation : le schéma est donc comparé à chaque chargement via une option
 * autoloadée (`woo_search_db_version`) et migré automatiquement si nécessaire.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Installer {

	public const DB_VERSION = '2';

	public const CRON_WEEKLY_DIGEST = 'woo_search_weekly_digest_cron';
	public const CRON_DAILY         = 'woo_search_daily_maintenance';
	public const AS_GROUP           = 'woo-search-intelligence';

	/**
	 * Noms des tables.
	 *
	 * @return array{logs: string, index: string}
	 */
	public static function tables(): array {
		global $wpdb;
		return [
			'logs'  => $wpdb->prefix . 'woo_search_logs',
			'index' => $wpdb->prefix . 'woo_search_index',
		];
	}

	/**
	 * Réglages par défaut de l'extension.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return [
			'min_chars'                  => 2,
			'max_results'                => 6,
			'enable_sku_variations'      => 1,
			'search_in_excerpt'          => 1,
			'search_in_terms'            => 1,
			'integrate_search_page'      => 1,
			'enable_woodmart_integration' => 1,
			'enable_spellcheck'          => 1,
			'enable_partial_fallback'    => 1,
			'show_images'                => 1,
			'show_prices'                => 1,
			'show_sku_badge'             => 1,
			'show_stock'                 => 1,
			'show_categories'            => 1,
			'format_titles'              => 1,
			'enable_frontend_ui'         => 0,
			'frontend_selector'          => '',
			'enable_click_tracking'      => 1,
			'enable_conversion_tracking' => 1,
			'exclude_admins'             => 1,
			'enable_alerts'              => 0,
			'alert_email'                => (string) get_option( 'admin_email' ),
			'alert_mode'                 => 'weekly',
			'alert_threshold'            => 5,
			'log_retention_days'         => 730,
			'delete_data_on_uninstall'   => 0,
		];
	}

	/**
	 * Vérifie la version du schéma à chaque chargement (coût : 1 option autoloadée).
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== (string) get_option( 'woo_search_db_version', '' ) ) {
			self::install();
		}
	}

	/**
	 * Création / migration des tables, options et crons. Idempotent.
	 */
	public static function install(): void {
		global $wpdb;

		$tables          = self::tables();
		$charset_collate = $wpdb->get_charset_collate();

		// Journal des recherches. Toutes les dates sont stockées en UTC.
		$sql_logs = "CREATE TABLE {$tables['logs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			search_uid char(16) NOT NULL DEFAULT '',
			query varchar(191) NOT NULL DEFAULT '',
			normalized_query varchar(191) NOT NULL DEFAULT '',
			results_count int(10) unsigned NOT NULL DEFAULT 0,
			has_results tinyint(1) NOT NULL DEFAULT 1,
			source varchar(10) NOT NULL DEFAULT 'ajax',
			match_mode varchar(10) NOT NULL DEFAULT '',
			corrected_query varchar(191) NOT NULL DEFAULT '',
			session_hash char(32) NOT NULL DEFAULT '',
			clicks smallint(5) unsigned NOT NULL DEFAULT 0,
			clicked_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			click_position smallint(5) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_total decimal(14,2) NOT NULL DEFAULT 0.00,
			searched_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY search_uid (search_uid),
			KEY normalized_query (normalized_query),
			KEY session_time (session_hash,searched_at),
			KEY results_time (has_results,searched_at),
			KEY searched_at (searched_at)
		) {$charset_collate};";

		// Index de recherche dénormalisé : un produit = une ligne, textes pré-pliés et bordés d'espaces.
		$sql_index = "CREATE TABLE {$tables['index']} (
			product_id bigint(20) unsigned NOT NULL,
			title text NOT NULL,
			skus text NOT NULL,
			terms text NOT NULL,
			excerpt text NOT NULL,
			stock_status varchar(20) NOT NULL DEFAULT 'instock',
			total_sales bigint(20) unsigned NOT NULL DEFAULT 0,
			indexed_at datetime NOT NULL,
			PRIMARY KEY  (product_id),
			KEY stock_status (stock_status),
			KEY indexed_at (indexed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_logs );
		dbDelta( $sql_index );

		// Réglages : fusion non destructive avec les valeurs par défaut.
		$saved = get_option( 'woo_search_settings', [] );
		update_option( 'woo_search_settings', wp_parse_args( is_array( $saved ) ? $saved : [], self::default_settings() ) );

		add_option( 'woo_search_cache_gen', 1 );
		add_option( 'woo_search_ignored_terms', [], '', false );

		self::schedule_crons();

		// La (re)construction de l'index est déclenchée au prochain `init` (Action Scheduler disponible).
		update_option( 'woo_search_needs_rebuild', 1 );
		update_option( 'woo_search_db_version', self::DB_VERSION );
	}

	/**
	 * Planifie les crons récurrents s'ils sont absents.
	 */
	public static function schedule_crons(): void {
		if ( ! wp_next_scheduled( self::CRON_WEEKLY_DIGEST ) ) {
			wp_schedule_event( strtotime( 'next monday 06:00' ) ?: time(), 'weekly', self::CRON_WEEKLY_DIGEST );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/**
	 * Désactivation : arrêt des tâches planifiées, données conservées.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_WEEKLY_DIGEST );
		wp_clear_scheduled_hook( self::CRON_DAILY );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::AS_GROUP );
		}
	}

	/**
	 * Exécution asynchrone d'un hook : Action Scheduler (WooCommerce) si disponible,
	 * sinon WP-Cron ponctuel.
	 *
	 * @param string            $hook   Hook à déclencher.
	 * @param array<int, mixed> $args   Arguments.
	 * @param bool              $unique Ne pas empiler si une action identique est déjà en attente.
	 */
	public static function async( string $hook, array $args = [], bool $unique = false ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( $unique && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( $hook, $args, self::AS_GROUP );
			return;
		}

		if ( $unique && wp_next_scheduled( $hook, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), $hook, $args );
	}

	/**
	 * Planification différée (debounce) d'un hook.
	 *
	 * @param int               $delay Délai en secondes.
	 * @param string            $hook  Hook.
	 * @param array<int, mixed> $args  Arguments.
	 */
	public static function schedule_once( int $delay, string $hook, array $args = [] ): void {
		if ( function_exists( 'as_schedule_single_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::AS_GROUP ) ) {
				return;
			}
			as_schedule_single_action( time() + $delay, $hook, $args, self::AS_GROUP );
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	/**
	 * Suppression complète des données (appelée depuis uninstall.php si l'option est activée).
	 */
	public static function purge_all_data(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$options = [
			'woo_search_settings', 'woo_search_synonyms', 'woo_search_ignored_terms', 'woo_search_db_version',
			'woo_search_cache_gen', 'woo_search_needs_rebuild', 'woo_search_index_state', 'woo_search_vocab',
			'woo_search_vocab_dirty', 'woo_search_imported_at', 'woo_search_imported_count',
		];
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wsi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wsi\\_%'" );
	}
}
