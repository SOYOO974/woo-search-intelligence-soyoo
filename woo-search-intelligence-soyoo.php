<?php
/**
 * Plugin Name: WooCommerce Search Intelligence by SOYOO
 * Plugin URI: https://github.com/SOYOO974/woo-search-intelligence-soyoo
 * Description: Moteur de recherche e-commerce propriétaire pour WooCommerce : index dédié, correction orthographique, synonymes, lemmatisation française, intégration de la page de résultats, mesure des clics et des commandes issues de la recherche, alertes « 0 résultat » et tableau de bord décisionnel.
 * Version: 1.1.1
 * Author: SOYOO (Julien Vanwinsberghe)
 * Author URI: https://soyoo.re
 * Text Domain: woo-search-intelligence-soyoo
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.2
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes globales du plugin.
define( 'WOO_SEARCH_INTEL_VERSION', '1.1.1' );
define( 'WOO_SEARCH_INTEL_FILE', __FILE__ );
define( 'WOO_SEARCH_INTEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_SEARCH_INTEL_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_SEARCH_INTEL_BASENAME', plugin_basename( __FILE__ ) );
define( 'WOO_SEARCH_INTEL_GITHUB_REPO', 'https://github.com/SOYOO974/woo-search-intelligence-soyoo/' );

// Initialisation du vérificateur de mises à jour (Plugin Update Checker v5.6).
$woo_search_intel_update_checker = null;
if ( file_exists( WOO_SEARCH_INTEL_PATH . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once WOO_SEARCH_INTEL_PATH . 'plugin-update-checker/plugin-update-checker.php';
	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$woo_search_intel_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WOO_SEARCH_INTEL_GITHUB_REPO,
			WOO_SEARCH_INTEL_FILE,
			'woo-search-intelligence-soyoo'
		);
		$woo_search_intel_update_checker->setBranch( 'main' );
		if ( method_exists( $woo_search_intel_update_checker->getVcsApi(), 'enableReleaseAssets' ) ) {
			$woo_search_intel_update_checker->getVcsApi()->enableReleaseAssets();
		}
	}
}

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

// Socle sans dépendance WooCommerce (normalisation du texte, schéma, tâches planifiées).
require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-text.php';
require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-installer.php';

/*
 * Activation : création des tables et des crons. Les mises à jour livrées par GitHub
 * Releases ne passent pas par ce hook : Woo_Search_Installer::maybe_upgrade() prend le relais.
 */
register_activation_hook( WOO_SEARCH_INTEL_FILE, [ 'Woo_Search_Installer', 'install' ] );
register_deactivation_hook( WOO_SEARCH_INTEL_FILE, [ 'Woo_Search_Installer', 'deactivate' ] );

/**
 * Initialisation après le chargement des extensions.
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
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-indexer.php';
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-engine.php';
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-tracker.php';
	require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-importer.php';

	if ( is_admin() ) {
		require_once WOO_SEARCH_INTEL_PATH . 'includes/class-search-admin.php';
	}

	// Migration automatique du schéma après une mise à jour (1 option autoloadée).
	Woo_Search_Installer::maybe_upgrade();

	// Démarrage des modules (l'indexeur d'abord : le moteur s'appuie sur son état).
	Woo_Search_Indexer::instance();
	Woo_Search_Engine::instance();
	Woo_Search_Tracker::init();
	Woo_Search_Importer::init();

	if ( is_admin() ) {
		Woo_Search_Admin::instance();
	}
} );

add_action( 'init', function (): void {
	load_plugin_textdomain( 'woo-search-intelligence-soyoo', false, dirname( WOO_SEARCH_INTEL_BASENAME ) . '/languages' );
} );
