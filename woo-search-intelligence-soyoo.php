<?php
/**
 * Plugin Name: WooCommerce Search Intelligence by SOYOO
 * Plugin URI: https://github.com/SOYOO974/woo-search-intelligence-soyoo
 * Description: Moteur de recherche e-commerce propriétaire haute performance pour WooCommerce. Lemmatisation française, synonymes intelligents, scoring SQL multi-paliers, tracking universel (AJAX et recherche standard), bouclier anti-robots et tableau de bord décisionnel.
 * Version: 1.0.0
 * Author: SOYOO (Julien Vanwinsberghe)
 * Author URI: https://soyoo.re
 * Text Domain: woo-search-intelligence-soyoo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 * WC tested up to: 9.3
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes globales du plugin.
define( 'WOO_SEARCH_INTEL_VERSION', '1.0.0' );
define( 'WOO_SEARCH_INTEL_FILE', __FILE__ );
define( 'WOO_SEARCH_INTEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_SEARCH_INTEL_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_SEARCH_INTEL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Déclaration officielle de compatibilité WooCommerce HPOS (High-Performance Order Storage)
 * et Cart/Checkout Blocks.
 */
add_action( 'before_woocommerce_init', function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOO_SEARCH_INTEL_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOO_SEARCH_INTEL_FILE, true );
	}
} );

/**
 * Création et mise à jour de la table MySQL dédiée lors de l'activation
 */
function woo_search_intel_activate(): void {
	global $wpdb;

	$table_logs      = $wpdb->prefix . 'woo_search_logs';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_logs} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		query varchar(191) NOT NULL,
		normalized_query varchar(191) NOT NULL,
		results_count smallint(5) unsigned NOT NULL DEFAULT 0,
		has_results tinyint(1) NOT NULL DEFAULT 1,
		session_hash char(32) NOT NULL DEFAULT '',
		searched_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY query (query),
		KEY normalized_query (normalized_query),
		KEY has_results (has_results),
		KEY searched_at (searched_at)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Planification du cron hebdomadaire d'alertes si activé.
	if ( ! wp_next_scheduled( 'woo_search_weekly_digest_cron' ) ) {
		wp_schedule_event( time(), 'weekly', 'woo_search_weekly_digest_cron' );
	}

	set_transient( 'woo_search_table_verified', 1, DAY_IN_SECONDS );
}
register_activation_hook( WOO_SEARCH_INTEL_FILE, 'woo_search_intel_activate' );

/**
 * Nettoyage lors de la désactivation du plugin
 */
function woo_search_intel_deactivate(): void {
	$timestamp = wp_next_scheduled( 'woo_search_weekly_digest_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'woo_search_weekly_digest_cron' );
	}
	delete_transient( 'woo_search_table_verified' );
	delete_transient( 'woo_search_all_cats' );
}
register_deactivation_hook( WOO_SEARCH_INTEL_FILE, 'woo_search_intel_deactivate' );

/**
 * Initialisation après le chargement des extensions
 */
add_action( 'plugins_loaded', function (): void {
	// Vérification de la présence active de WooCommerce.
	if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) {
		add_action( 'admin_notices', function (): void {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Search Intelligence (SOYOO)', 'woo-search-intelligence-soyoo' ); ?></strong> :
					<?php esc_html_e( 'Cette extension requiert que WooCommerce soit installé et activé pour fonctionner.', 'woo-search-intelligence-soyoo' ); ?>
				</p>
			</div>
			<?php
		} );
		return;
	}

	// Chargement des composants internes de l'extension.
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-engine.php';
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-tracker.php';
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-importer.php';

	if ( is_admin() ) {
		require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-admin.php';
	}

	// Démarrage des modules.
	Woo_Search_Engine::instance();
	Woo_Search_Tracker::init();
	Woo_Search_Importer::init();

	if ( is_admin() ) {
		Woo_Search_Admin::instance();
	}
} );
