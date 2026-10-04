<?php
/**
 * Dashboard d'Administration & Pilotage Décisionnel
 *
 * 4 Onglets : Paramètres, Synonymes, Statistiques (Search Analytics) et Recherches 0 Résultat.
 * Intègre la pagination haute performance, l'export CSV, la blacklist universelle et les alertes.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Search_Admin {

	/**
	 * Instance unique (Singleton)
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Initialisation de l'instance
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructeur
	 */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 65 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'in_admin_header', [ $this, 'suppress_admin_notices' ], 1000 );

		// Handlers AJAX de l'administration.
		add_action( 'wp_ajax_woo_search_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_woo_search_add_synonym', [ $this, 'ajax_add_synonym' ] );
		add_action( 'wp_ajax_woo_search_edit_synonym', [ $this, 'ajax_edit_synonym' ] );
		add_action( 'wp_ajax_woo_search_delete_synonym', [ $this, 'ajax_delete_synonym' ] );
		add_action( 'wp_ajax_woo_search_clear_cache', [ $this, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_woo_search_clear_logs', [ $this, 'ajax_clear_logs' ] );
		add_action( 'wp_ajax_woo_search_test_email', [ $this, 'ajax_test_email' ] );
		add_action( 'wp_ajax_woo_search_ignore_term', [ $this, 'ajax_ignore_term' ] );
		add_action( 'wp_ajax_woo_search_unignore_term', [ $this, 'ajax_unignore_term' ] );
		add_action( 'wp_ajax_woo_search_bulk_ignore_terms', [ $this, 'ajax_bulk_ignore_terms' ] );
		add_action( 'wp_ajax_woo_search_install_recommended_synonyms', [ $this, 'ajax_install_recommended_synonyms' ] );

		// Handler de téléchargement export CSV.
		add_action( 'admin_init', [ $this, 'handle_csv_export' ] );
	}

	/**
	 * Masque les notifications d'administration parasites sur l'écran Search Intelligence
	 */
	public function suppress_admin_notices(): void {
		if ( isset( $_GET['page'] ) && 'woo-search-intelligence' === $_GET['page'] ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	/**
	 * Déclare la page dans le sous-menu de WooCommerce
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Search Intelligence', 'woo-search-intelligence-soyoo' ),
			__( 'Search Intelligence', 'woo-search-intelligence-soyoo' ),
			'manage_woocommerce',
			'woo-search-intelligence',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Charge les styles CSS et scripts JS d'administration
	 *
	 * @param string $hook_suffix Identifiant de l'écran admin.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'woo-search-intelligence' ) ) {
			return;
		}

		wp_enqueue_style(
			'woo-search-admin-css',
			WOO_SEARCH_INTEL_URL . 'assets/css/admin.css',
			[],
			WOO_SEARCH_INTEL_VERSION
		);

		wp_enqueue_script(
			'woo-search-admin-js',
			WOO_SEARCH_INTEL_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WOO_SEARCH_INTEL_VERSION,
			true
		);

		wp_localize_script( 'woo-search-admin-js', 'wooSearchAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'woo_search_admin_nonce' ),
			'i18n'    => [
				'confirmClearLogs' => __( 'Confirmer la suppression des statistiques de recherche antérieures à 90 jours ?', 'woo-search-intelligence-soyoo' ),
				'confirmIgnore'    => __( 'Ignorer définitivement ce terme ? Il ne sera plus comptabilisé dans les analyses ni dans les alertes.', 'woo-search-intelligence-soyoo' ),
				'confirmBulk'      => __( 'Ignorer définitivement les termes sélectionnés ?', 'woo-search-intelligence-soyoo' ),
				'confirmDeleteSyn' => __( 'Supprimer ce synonyme ?', 'woo-search-intelligence-soyoo' ),
				'saving'           => __( 'Enregistrement en cours...', 'woo-search-intelligence-soyoo' ),
				'saved'            => __( 'Enregistré avec succès !', 'woo-search-intelligence-soyoo' ),
				'error'            => __( 'Une erreur est survenue.', 'woo-search-intelligence-soyoo' ),
			],
		] );
	}

	/**
	 * Calcule les indicateurs clés de performance (KPIs) en excluant les termes ignorés
	 *
	 * @param int $days Nombre de jours d'analyse (0 pour tout l'historique).
	 * @return array{total_searches: int, success_rate: float, zero_rate: float, zero_count: int, top_term: string}
	 */
	public function get_search_kpis( int $days = 30 ): array {
		global $wpdb;
		$table_logs = $wpdb->prefix . 'woo_search_logs';

		$default_stats = [
			'total_searches' => 0,
			'success_rate'   => 0.0,
			'zero_rate'      => 0.0,
			'zero_count'     => 0,
			'top_term'       => '',
		];

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_logs}'" ) !== $table_logs ) {
			return $default_stats;
		}

		$where_clauses = [ '1=1' ];
		$params        = [];

		if ( $days > 0 ) {
			$where_clauses[] = 'searched_at >= %s';
			$params[]        = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		}

		// Exclusion stricte des termes ignorés.
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$placeholders    = implode( ', ', array_fill( 0, count( $ignored ), '%s' ) );
			$where_clauses[] = "normalized_query NOT IN ({$placeholders})";
			foreach ( $ignored as $it ) {
				$params[] = (string) $it;
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$count_sql = "
			SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN has_results = 1 THEN 1 ELSE 0 END) as success_count,
				SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) as zero_count
			FROM {$table_logs}
			WHERE {$where_sql}
		";

		$row = ! empty( $params )
			? $wpdb->get_row( $wpdb->prepare( $count_sql, $params ) )
			: $wpdb->get_row( $count_sql );

		if ( ! $row || 0 === (int) $row->total ) {
			return $default_stats;
		}

		$total        = (int) $row->total;
		$success      = (int) $row->success_count;
		$zero         = (int) $row->zero_count;
		$success_rate = round( ( $success / $total ) * 100, 1 );
		$zero_rate    = round( ( $zero / $total ) * 100, 1 );

		// Terme le plus fréquent de la période.
		$top_sql = "
			SELECT normalized_query, COUNT(*) as qty
			FROM {$table_logs}
			WHERE {$where_sql}
			GROUP BY normalized_query
			ORDER BY qty DESC
			LIMIT 1
		";

		$top_row  = ! empty( $params )
			? $wpdb->get_row( $wpdb->prepare( $top_sql, $params ) )
			: $wpdb->get_row( $top_sql );
		$top_term = $top_row ? (string) $top_row->normalized_query : '';

		return [
			'total_searches' => $total,
			'success_rate'   => $success_rate,
			'zero_rate'      => $zero_rate,
			'zero_count'     => $zero,
			'top_term'       => $top_term,
		];
	}

	/**
	 * Rendu principal de la page d'administration
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les permissions nécessaires pour accéder à cette page.', 'woo-search-intelligence-soyoo' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$valid_tabs = [ 'settings', 'synonyms', 'analytics', 'zero_results' ];
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'settings';
		}

		$engine       = Woo_Search_Engine::instance();
		$synonyms     = get_option( 'woo_search_synonyms', [] );
		$stats        = $this->get_search_kpis( 30 );
		$legacy_info  = Woo_Search_Importer::get_legacy_tables_info();
		$imported_at  = get_option( 'woo_search_imported_at' );
		?>
		<div class="wrap woo-search-wrap">
			<!-- Header SOYOO -->
			<div class="woo-admin-header">
				<div class="woo-admin-brand">
					<div class="woo-admin-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="11" cy="11" r="8"></circle>
							<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
						</svg>
					</div>
					<div>
						<h1 class="woo-admin-title">WooCommerce Search Intelligence</h1>
						<p class="woo-admin-subtitle">Moteur de recherche propriétaire haute performance, synonymes intelligents et analytics décisionnels</p>
					</div>
				</div>
				<div class="woo-admin-badges">
					<span class="woo-badge woo-badge-version">v<?php echo esc_html( WOO_SEARCH_INTEL_VERSION ); ?></span>
					<span class="woo-badge woo-badge-hpos">HPOS Ready</span>
				</div>
			</div>

			<?php if ( ( $legacy_info['has_terms'] || $legacy_info['has_history'] ) && ! $imported_at && 'settings' !== $active_tab ) : ?>
				<!-- Bannière de rappel Import Search Analytics -->
				<div class="woo-banner-import">
					<div class="woo-banner-import-left">
						<span class="woo-banner-icon">📥</span>
						<div>
							<strong><?php esc_html_e( 'Historique Search Analytics for WP détecté dans votre base de données', 'woo-search-intelligence-soyoo' ); ?></strong>
							<p><?php echo esc_html( sprintf( __( '%d recherches historiques peuvent être importées en 1 clic dans Search Intelligence.', 'woo-search-intelligence-soyoo' ), $legacy_info['total'] ) ); ?></p>
						</div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=settings#woo-importer-card' ) ); ?>" class="woo-btn woo-btn-primary">
						<?php esc_html_e( 'Lancer la migration ➔', 'woo-search-intelligence-soyoo' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<!-- Navigation par onglets -->
			<nav class="nav-tab-wrapper woo-tabs-nav">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					⚙️ <?php esc_html_e( 'Paramètres & Migration', 'woo-search-intelligence-soyoo' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=synonyms' ) ); ?>" class="nav-tab <?php echo 'synonyms' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📖 <?php esc_html_e( 'Dictionnaire & Synonymes', 'woo-search-intelligence-soyoo' ); ?>
					<span class="woo-tab-count"><?php echo is_countable( $synonyms ) ? count( $synonyms ) : 0; ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=analytics' ) ); ?>" class="nav-tab <?php echo 'analytics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📊 <?php esc_html_e( 'Statistiques & Journal', 'woo-search-intelligence-soyoo' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=zero_results' ) ); ?>" class="nav-tab <?php echo 'zero_results' === $active_tab ? 'nav-tab-active' : ''; ?>">
					🚨 <?php esc_html_e( '0 Résultat & Opportunités', 'woo-search-intelligence-soyoo' ); ?>
					<?php if ( ! empty( $stats['zero_count'] ) ) : ?>
						<span class="woo-tab-badge-alert"><?php echo (int) $stats['zero_count']; ?></span>
					<?php endif; ?>
				</a>
			</nav>

			<!-- Contenu des onglets -->
			<div class="woo-tab-body">
				<?php
				switch ( $active_tab ) {
					case 'synonyms':
						$this->render_tab_synonyms( is_array( $synonyms ) ? $synonyms : [] );
						break;
					case 'analytics':
						$this->render_tab_analytics();
						break;
					case 'zero_results':
						$this->render_tab_zero_results();
						break;
					case 'settings':
					default:
						$this->render_tab_settings( $engine, $legacy_info );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Onglet 1 : Paramètres généraux, alertes et migration
	 *
	 * @param Woo_Search_Engine $engine Instance du moteur.
	 * @param array<string, mixed> $legacy_info Données sur les tables legacy.
	 */
	private function render_tab_settings( Woo_Search_Engine $engine, array $legacy_info ): void {
		$imported_at    = get_option( 'woo_search_imported_at' );
		$imported_count = (int) get_option( 'woo_search_imported_count', 0 );
		?>
		<div class="woo-settings-grid">
			<div class="woo-settings-main">
				<form id="woo-settings-form">
					<!-- Carte Moteur de recherche -->
					<div class="woo-card">
						<h2 class="woo-card-title">🔍 <?php esc_html_e( 'Moteur de Recherche & Scoring', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Configuration des seuils de déclenchement et des critères de pertinence du catalogue.', 'woo-search-intelligence-soyoo' ); ?></p>

						<div class="woo-form-group">
							<label for="woo_min_chars"><?php esc_html_e( 'Nombre minimal de caractères pour déclencher la recherche', 'woo-search-intelligence-soyoo' ); ?></label>
							<input type="number" id="woo_min_chars" name="min_chars" min="1" max="5" value="<?php echo esc_attr( (string) $engine->get_option( 'min_chars', 2 ) ); ?>" class="small-text">
							<p class="woo-field-hint"><?php esc_html_e( 'Recommandé : 2 caractères pour réactivité maximale.', 'woo-search-intelligence-soyoo' ); ?></p>
						</div>

						<div class="woo-form-group">
							<label for="woo_max_results"><?php esc_html_e( 'Nombre maximal de produits affichés en autocomplétion (Live Search)', 'woo-search-intelligence-soyoo' ); ?></label>
							<input type="number" id="woo_max_results" name="max_results" min="3" max="20" value="<?php echo esc_attr( (string) $engine->get_option( 'max_results', 6 ) ); ?>" class="small-text">
							<p class="woo-field-hint"><?php esc_html_e( 'Recommandé : 6 produits pour un affichage ergonomique sans ascenseur.', 'woo-search-intelligence-soyoo' ); ?></p>
						</div>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="enable_sku_variations" value="1" <?php checked( (bool) $engine->get_option( 'enable_sku_variations', 1 ) ); ?>>
								<span><?php esc_html_e( 'Activer la recherche prioritaire par référence SKU (Parents et Variations)', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
							<p class="woo-field-hint"><?php esc_html_e( 'Attribue un score maximal (+100 000) lorsqu\'un client saisit un code SKU exact ou partiel.', 'woo-search-intelligence-soyoo' ); ?></p>
						</div>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="search_in_excerpt" value="1" <?php checked( (bool) $engine->get_option( 'search_in_excerpt', 1 ) ); ?>>
								<span><?php esc_html_e( 'Rechercher également dans la courte description (Extrait)', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
							<p class="woo-field-hint"><?php esc_html_e( 'Permet de trouver des articles via des caractéristiques secondaires présentes dans l\'extrait.', 'woo-search-intelligence-soyoo' ); ?></p>
						</div>
					</div>

					<!-- Carte Affichage Front-End -->
					<div class="woo-card">
						<h2 class="woo-card-title">🎨 <?php esc_html_e( 'Éléments Affichés dans la Liste Déroulante', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Sélectionnez les métadonnées retournées dans l\'autocomplétion.', 'woo-search-intelligence-soyoo' ); ?></p>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="show_images" value="1" <?php checked( (bool) $engine->get_option( 'show_images', 1 ) ); ?>>
								<span><?php esc_html_e( 'Miniatures des produits (Packshots)', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="show_prices" value="1" <?php checked( (bool) $engine->get_option( 'show_prices', 1 ) ); ?>>
								<span><?php esc_html_e( 'Prix formaté WooCommerce (avec promotions éventuelles)', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="show_sku_badge" value="1" <?php checked( (bool) $engine->get_option( 'show_sku_badge', 1 ) ); ?>>
								<span><?php esc_html_e( 'Badge SKU / Référence produit', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="show_stock" value="1" <?php checked( (bool) $engine->get_option( 'show_stock', 1 ) ); ?>>
								<span><?php esc_html_e( 'Indicateur de disponibilité (En stock / Rupture)', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>
					</div>

					<!-- Carte Alertes & Notifications -->
					<div class="woo-card">
						<h2 class="woo-card-title">🚨 <?php esc_html_e( 'Alertes Décisionnelles (Recherches Sans Résultat)', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Soyez prévenu dès que des acheteurs potentiels recherchent des articles absents ou mal orthographiés.', 'woo-search-intelligence-soyoo' ); ?></p>

						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="enable_alerts" value="1" id="woo_enable_alerts" <?php checked( (bool) $engine->get_option( 'enable_alerts', 0 ) ); ?>>
								<span><?php esc_html_e( 'Activer l\'envoi automatique d\'alertes par e-mail', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>

						<div class="woo-alerts-options" style="<?php echo $engine->get_option( 'enable_alerts' ) ? '' : 'display:none;'; ?>">
							<div class="woo-form-group">
								<label for="woo_alert_email"><?php esc_html_e( 'Adresse e-mail de réception', 'woo-search-intelligence-soyoo' ); ?></label>
								<input type="email" id="woo_alert_email" name="alert_email" value="<?php echo esc_attr( (string) $engine->get_option( 'alert_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text">
							</div>

							<div class="woo-form-group">
								<label><?php esc_html_e( 'Fréquence d\'envoi', 'woo-search-intelligence-soyoo' ); ?></label>
								<div style="margin-top:6px; display:flex; flex-direction:column; gap:8px;">
									<label>
										<input type="radio" name="alert_mode" value="weekly" <?php checked( (string) $engine->get_option( 'alert_mode', 'weekly' ), 'weekly' ); ?>>
										<strong><?php esc_html_e( 'Rapport hebdomadaire complet (Recommandé)', 'woo-search-intelligence-soyoo' ); ?></strong>
										<span class="woo-field-hint" style="display:block;"><?php esc_html_e( 'Synthèse envoyée chaque début de semaine avec le Top 10 des termes sans résultat.', 'woo-search-intelligence-soyoo' ); ?></span>
									</label>
									<label>
										<input type="radio" name="alert_mode" value="threshold" <?php checked( (string) $engine->get_option( 'alert_mode' ), 'threshold' ); ?>>
										<strong><?php esc_html_e( 'Alerte instantanée sur seuil', 'woo-search-intelligence-soyoo' ); ?></strong>
										<span class="woo-field-hint" style="display:block;"><?php esc_html_e( 'Alerte immédiate dès qu\'un terme atteint X recherches infructueuses sur les 7 derniers jours.', 'woo-search-intelligence-soyoo' ); ?></span>
									</label>
								</div>
							</div>

							<div class="woo-form-group" id="woo_threshold_group" style="<?php echo 'threshold' === $engine->get_option( 'alert_mode' ) ? '' : 'display:none;'; ?>">
								<label for="woo_alert_threshold"><?php esc_html_e( 'Seuil d\'occurrences pour déclencher l\'alerte immédiate', 'woo-search-intelligence-soyoo' ); ?></label>
								<input type="number" id="woo_alert_threshold" name="alert_threshold" min="2" max="50" value="<?php echo esc_attr( (string) $engine->get_option( 'alert_threshold', 5 ) ); ?>" class="small-text">
							</div>

							<div class="woo-form-group">
								<button type="button" class="woo-btn woo-btn-secondary" id="woo-btn-test-email">
									✉️ <?php esc_html_e( 'Envoyer un e-mail de test', 'woo-search-intelligence-soyoo' ); ?>
								</button>
								<span id="woo-test-email-status" style="margin-left:10px; font-size:13px;"></span>
							</div>
						</div>
					</div>

					<div style="margin-top:20px;">
						<button type="submit" class="woo-btn woo-btn-primary woo-btn-lg" id="woo-save-settings-btn">
							💾 <?php esc_html_e( 'Enregistrer les paramètres', 'woo-search-intelligence-soyoo' ); ?>
						</button>
						<span id="woo-save-status" style="margin-left:12px; font-weight:600;"></span>
					</div>
				</form>
			</div>

			<!-- Colonne latérale : Statut système et Importateur -->
			<div class="woo-settings-sidebar">
				<!-- Carte Actions Cache & Système -->
				<div class="woo-card">
					<h3 class="woo-card-title" style="font-size:15px;">⚡ <?php esc_html_e( 'Cache & Performances', 'woo-search-intelligence-soyoo' ); ?></h3>
					<p class="woo-card-desc"><?php esc_html_e( 'Les résultats de recherche et suggestions sont mis en cache Transients pendant 12h.', 'woo-search-intelligence-soyoo' ); ?></p>
					<button type="button" class="woo-btn woo-btn-secondary" id="woo-btn-clear-cache" style="width:100%; justify-content:center;">
						🧹 <?php esc_html_e( 'Vider le cache de recherche', 'woo-search-intelligence-soyoo' ); ?>
					</button>
				</div>

				<!-- Carte Importateur Search Analytics for WP -->
				<div class="woo-card" id="woo-importer-card">
					<h3 class="woo-card-title" style="font-size:15px;">📥 <?php esc_html_e( 'Migration Search Analytics', 'woo-search-intelligence-soyoo' ); ?></h3>
					<?php if ( $legacy_info['has_terms'] || $legacy_info['has_history'] ) : ?>
						<p class="woo-card-desc">
							<?php echo esc_html( sprintf( __( 'Tables tierces détectées avec %d recherches archivées.', 'woo-search-intelligence-soyoo' ), $legacy_info['total'] ) ); ?>
						</p>

						<?php if ( $imported_at ) : ?>
							<div class="woo-alert-box woo-alert-success" style="margin-bottom:14px; font-size:12px;">
								✓ <?php echo esc_html( sprintf( __( 'Dernier import : %s (%d logs)', 'woo-search-intelligence-soyoo' ), human_time_diff( strtotime( (string) $imported_at ), current_time( 'timestamp' ) ), $imported_count ) ); ?>
							</div>
						<?php endif; ?>

						<div class="woo-progress-container" id="woo-import-progress-container" style="display:none; margin-bottom:14px;">
							<div class="woo-progress-bar">
								<div class="woo-progress-fill" id="woo-import-progress-fill" style="width:0%;"></div>
							</div>
							<div class="woo-progress-text" id="woo-import-progress-text">0%</div>
						</div>

						<label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:12px; color:#475569;">
							<input type="checkbox" id="woo-import-reset-check" value="1" checked>
							<span><?php esc_html_e( 'Remplacer l\'ancien import (évite les doublons)', 'woo-search-intelligence-soyoo' ); ?></span>
						</label>

						<button type="button" class="woo-btn woo-btn-primary" id="woo-btn-start-import" style="width:100%; justify-content:center;">
							🚀 <?php echo $imported_at ? esc_html__( 'Réimporter les données', 'woo-search-intelligence-soyoo' ) : esc_html__( 'Démarrer la migration batchée', 'woo-search-intelligence-soyoo' ); ?>
						</button>
					<?php else : ?>
						<p style="color:#64748b; font-size:13px; font-style:italic;">
							<?php esc_html_e( 'Aucune table résiduelle de l\'extension Search Analytics for WP détectée dans cette base.', 'woo-search-intelligence-soyoo' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Carte Info HPOS & Architecture -->
				<div class="woo-card">
					<h3 class="woo-card-title" style="font-size:15px;">🛡️ <?php esc_html_e( 'Architecture In-House', 'woo-search-intelligence-soyoo' ); ?></h3>
					<ul class="woo-tech-list">
						<li><strong>Table MySQL :</strong> <code>{$wpdb->prefix}woo_search_logs</code></li>
						<li><strong>Tracking :</strong> Universel (Live Search + Pages standards)</li>
						<li><strong>Anti-Bot :</strong> Shield natif User-Agent</li>
						<li><strong>Compatibilité :</strong> WooCommerce HPOS & Blocks</li>
						<li><strong>Hooks extensibles :</strong> <code>woo_search_normalize_query</code></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Onglet 2 : Dictionnaire et Synonymes
	 *
	 * @param array<int, array<string, mixed>> $synonyms Liste des synonymes existants.
	 */
	private function render_tab_synonyms( array $synonyms ): void {
		$active_keys = [];
		foreach ( $synonyms as $item ) {
			$from                 = mb_strtolower( trim( (string) $item['from'] ), 'UTF-8' );
			$active_keys[ $from ] = true;
		}

		$recommended = [
			'Général & E-Commerce' => [
				[ 'from' => 'cadeau',    'to' => 'coffret',   'type' => 'expand' ],
				[ 'from' => 'promo',     'to' => 'soldes',    'type' => 'expand' ],
				[ 'from' => 'reduction', 'to' => 'promotion', 'type' => 'expand' ],
			],
		];

		/**
		 * Filtre : woo_search_recommended_packs
		 * Permet d'alimenter les suggestions de packs par domaine métier.
		 */
		$recommended = apply_filters( 'woo_search_recommended_packs', $recommended );
		?>
		<!-- Formulaire d'ajout rapide -->
		<div class="woo-card">
			<h2 class="woo-card-title">➕ <?php esc_html_e( 'Créer une Nouvelle Règle de Synonyme', 'woo-search-intelligence-soyoo' ); ?></h2>
			<p class="woo-card-desc"><?php esc_html_e( 'Associez les termes cherchés par vos clients aux libellés exacts de votre catalogue.', 'woo-search-intelligence-soyoo' ); ?></p>

			<form id="woo-add-synonym-form" class="woo-inline-form">
				<div class="woo-form-group" style="flex:2;">
					<label for="woo_from_term"><?php esc_html_e( 'Quand le client recherche :', 'woo-search-intelligence-soyoo' ); ?></label>
					<input type="text" id="woo_from_term" name="from" placeholder="ex: auto, vetement, gateau" required class="regular-text">
				</div>

				<div class="woo-form-group" style="flex:2;">
					<label for="woo_to_term"><?php esc_html_e( 'Faire correspondre au terme catalogue :', 'woo-search-intelligence-soyoo' ); ?></label>
					<input type="text" id="woo_to_term" name="to" placeholder="ex: remorque, mode, biscuit" required class="regular-text">
				</div>

				<div class="woo-form-group" style="flex:1.5;">
					<label for="woo_rule_type"><?php esc_html_e( 'Comportement :', 'woo-search-intelligence-soyoo' ); ?></label>
					<select id="woo_rule_type" name="type" style="height:36px; width:100%;">
						<option value="replace"><?php esc_html_e( 'Remplacement strict (Redirige)', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="expand" selected><?php esc_html_e( 'Expansion (Élargit la recherche)', 'woo-search-intelligence-soyoo' ); ?></option>
					</select>
				</div>

				<div>
					<button type="submit" class="woo-btn woo-btn-primary" id="woo-btn-add-synonym">
						➕ <?php esc_html_e( 'Ajouter la règle', 'woo-search-intelligence-soyoo' ); ?>
					</button>
				</div>
			</form>

			<!-- Barre de recherche et filtrage instantané -->
			<div class="woo-toolbar">
				<div class="woo-toolbar-left">
					<input type="text" id="woo-synonyms-filter-input" placeholder="🔍 <?php esc_attr_e( 'Filtrer les synonymes...', 'woo-search-intelligence-soyoo' ); ?>" class="woo-filter-input">
					<select id="woo-synonyms-filter-type" class="woo-filter-select">
						<option value=""><?php esc_html_e( 'Tous les types', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="replace"><?php esc_html_e( 'Remplacement', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="expand"><?php esc_html_e( 'Expansion', 'woo-search-intelligence-soyoo' ); ?></option>
					</select>
				</div>
				<div id="woo-synonyms-counter" class="woo-counter-badge">
					<strong><?php echo count( $synonyms ); ?></strong> <?php esc_html_e( 'règle(s) configurée(s)', 'woo-search-intelligence-soyoo' ); ?>
				</div>
			</div>

			<!-- Table des synonymes -->
			<table class="woo-table" id="woo-synonyms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mot-clé saisi par le client', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Équivalence catalogue', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Comportement', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Actions', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $synonyms ) ) : ?>
						<tr class="woo-empty-row">
							<td colspan="4" style="text-align:center; color:#94a3b8; padding:30px;">
								<?php esc_html_e( 'Aucun synonyme configuré pour le moment.', 'woo-search-intelligence-soyoo' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $synonyms as $index => $item ) : ?>
							<?php
							$from_val = (string) ( $item['from'] ?? '' );
							$to_val   = (string) ( $item['to'] ?? '' );
							$type_val = (string) ( $item['type'] ?? 'expand' );
							?>
							<tr data-index="<?php echo esc_attr( (string) $index ); ?>"
								data-from="<?php echo esc_attr( $from_val ); ?>"
								data-to="<?php echo esc_attr( $to_val ); ?>"
								data-type="<?php echo esc_attr( $type_val ); ?>">
								<td><strong>« <?php echo esc_html( $from_val ); ?> »</strong></td>
								<td>➔ <strong><?php echo esc_html( $to_val ); ?></strong></td>
								<td>
									<?php if ( 'replace' === $type_val ) : ?>
										<span class="woo-tag woo-tag-replace"><?php esc_html_e( 'Remplacement', 'woo-search-intelligence-soyoo' ); ?></span>
									<?php else : ?>
										<span class="woo-tag woo-tag-expand"><?php esc_html_e( 'Expansion', 'woo-search-intelligence-soyoo' ); ?></span>
									<?php endif; ?>
								</td>
								<td style="text-align:right; white-space:nowrap;">
									<button type="button" class="woo-btn woo-btn-secondary woo-edit-synonym-btn" data-index="<?php echo esc_attr( (string) $index ); ?>" style="padding:4px 10px; font-size:12px; margin-right:6px;">
										✏️ <?php esc_html_e( 'Modifier', 'woo-search-intelligence-soyoo' ); ?>
									</button>
									<button type="button" class="woo-btn woo-btn-danger woo-delete-synonym-btn" data-index="<?php echo esc_attr( (string) $index ); ?>" style="padding:4px 10px; font-size:12px;">
										🗑️ <?php esc_html_e( 'Supprimer', 'woo-search-intelligence-soyoo' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Section Suggestions de Packs Recommandés -->
		<?php if ( ! empty( $recommended ) ) : ?>
			<div class="woo-card">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
					<div>
						<h2 class="woo-card-title" style="margin:0;">✨ <?php esc_html_e( 'Suggestions de Packs de Synonymes', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc" style="margin:0;"><?php esc_html_e( 'Gagnez du temps en installant des packs pré-configurés adaptés à votre catalogue.', 'woo-search-intelligence-soyoo' ); ?></p>
					</div>
					<button type="button" class="woo-btn woo-btn-primary" id="woo-btn-install-recommended">
						⚡ <?php esc_html_e( 'Installer tous les synonymes suggérés', 'woo-search-intelligence-soyoo' ); ?>
					</button>
				</div>

				<div class="woo-packs-grid">
					<?php foreach ( $recommended as $category_name => $items ) : ?>
						<div class="woo-pack-box">
							<h4 class="woo-pack-title"><?php echo esc_html( (string) $category_name ); ?></h4>
							<div class="woo-pack-items">
								<?php foreach ( $items as $rec ) : ?>
									<?php
									$key_from  = mb_strtolower( trim( (string) $rec['from'] ), 'UTF-8' );
									$is_active = isset( $active_keys[ $key_from ] );
									?>
									<div class="woo-pack-row">
										<div>
											<strong><?php echo esc_html( (string) $rec['from'] ); ?></strong> ➔ <?php echo esc_html( (string) $rec['to'] ); ?>
											<span style="color:#94a3b8; font-size:10px;">(<?php echo 'replace' === $rec['type'] ? esc_html__( 'rempl.', 'woo-search-intelligence-soyoo' ) : esc_html__( 'exp.', 'woo-search-intelligence-soyoo' ); ?>)</span>
										</div>
										<div>
											<?php if ( $is_active ) : ?>
												<span class="woo-status-active">✓ <?php esc_html_e( 'Actif', 'woo-search-intelligence-soyoo' ); ?></span>
											<?php else : ?>
												<button type="button" class="woo-btn woo-btn-secondary woo-add-single-rec-btn"
														data-from="<?php echo esc_attr( (string) $rec['from'] ); ?>"
														data-to="<?php echo esc_attr( (string) $rec['to'] ); ?>"
														data-type="<?php echo esc_attr( (string) $rec['type'] ); ?>"
														style="padding:3px 8px; font-size:11px;">
													+ <?php esc_html_e( 'Ajouter', 'woo-search-intelligence-soyoo' ); ?>
												</button>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Onglet 3 : Statistiques & Journal des Recherches (avec masquage universel et pagination)
	 */
	private function render_tab_analytics(): void {
		global $wpdb;
		$table_logs = $wpdb->prefix . 'woo_search_logs';
		$days       = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30;
		$kpis       = $this->get_search_kpis( $days );

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 25;
		$offset   = ( $paged - 1 ) * $per_page;

		$where_clauses = [ '1=1' ];
		$params        = [];

		if ( $days > 0 ) {
			$where_clauses[] = 'searched_at >= %s';
			$params[]        = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		}

		// Masquage universel : exclusion stricte des termes ignorés.
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$placeholders    = implode( ', ', array_fill( 0, count( $ignored ), '%s' ) );
			$where_clauses[] = "normalized_query NOT IN ({$placeholders})";
			foreach ( $ignored as $it ) {
				$params[] = (string) $it;
			}
		}

		$where_sql   = implode( ' AND ', $where_clauses );
		$top_queries = [];
		$total_rows  = 0;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_logs}'" ) === $table_logs ) {
			$count_query = "SELECT COUNT(DISTINCT normalized_query) FROM {$table_logs} WHERE {$where_sql}";
			$total_rows  = ! empty( $params )
				? (int) $wpdb->get_var( $wpdb->prepare( $count_query, $params ) )
				: (int) $wpdb->get_var( $count_query );

			$query_sql = "
				SELECT normalized_query, COUNT(*) as count, AVG(results_count) as avg_results, MAX(searched_at) as last_seen
				FROM {$table_logs}
				WHERE {$where_sql}
				GROUP BY normalized_query
				ORDER BY count DESC, last_seen DESC
				LIMIT %d OFFSET %d
			";

			$query_params = array_merge( $params, [ $per_page, $offset ] );
			$top_queries  = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );
		}

		$total_pages = $total_rows > 0 ? (int) ceil( $total_rows / $per_page ) : 1;
		?>
		<!-- Grille des 4 KPIs -->
		<div class="woo-kpi-grid">
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Recherches totales', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val"><?php echo number_format_i18n( $kpis['total_searches'] ); ?></div>
				<div class="woo-kpi-sub"><?php echo 0 === $days ? esc_html__( 'Historique complet', 'woo-search-intelligence-soyoo' ) : esc_html( sprintf( __( '%d derniers jours', 'woo-search-intelligence-soyoo' ), $days ) ); ?></div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Taux de succès', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val" style="color:#059669;"><?php echo esc_html( (string) $kpis['success_rate'] ); ?>%</div>
				<div class="woo-kpi-sub"><?php esc_html_e( 'Requêtes avec au moins 1 produit', 'woo-search-intelligence-soyoo' ); ?></div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Taux sans résultat', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val" style="color:<?php echo $kpis['zero_rate'] > 15 ? '#dc2626' : '#0f172a'; ?>;"><?php echo esc_html( (string) $kpis['zero_rate'] ); ?>%</div>
				<div class="woo-kpi-sub"><?php echo esc_html( sprintf( __( '%d requêtes à 0 produit', 'woo-search-intelligence-soyoo' ), $kpis['zero_count'] ) ); ?></div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Top recherche', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val" style="font-size:18px; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">
					<?php echo ! empty( $kpis['top_term'] ) ? esc_html( $kpis['top_term'] ) : '—'; ?>
				</div>
				<div class="woo-kpi-sub"><?php esc_html_e( 'Requête la plus demandée', 'woo-search-intelligence-soyoo' ); ?></div>
			</div>
		</div>

		<!-- Table des résultats -->
		<div class="woo-card">
			<div class="woo-card-header-flex">
				<div>
					<h2 class="woo-card-title" style="margin:0;">📊 <?php esc_html_e( 'Journal & Classement des Requêtes', 'woo-search-intelligence-soyoo' ); ?></h2>
					<p class="woo-card-desc" style="margin:0;"><?php esc_html_e( 'Mots-clés recherchés par vos visiteurs et nombre moyen d\'articles retournés.', 'woo-search-intelligence-soyoo' ); ?></p>
				</div>
				<div class="woo-header-actions">
					<div class="woo-btn-group">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=analytics&range=7' ) ); ?>" class="woo-btn <?php echo 7 === $days ? 'woo-btn-primary' : 'woo-btn-secondary'; ?>">7j</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=analytics&range=30' ) ); ?>" class="woo-btn <?php echo 30 === $days ? 'woo-btn-primary' : 'woo-btn-secondary'; ?>">30j</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=analytics&range=90' ) ); ?>" class="woo-btn <?php echo 90 === $days ? 'woo-btn-primary' : 'woo-btn-secondary'; ?>">90j</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=analytics&range=0' ) ); ?>" class="woo-btn <?php echo 0 === $days ? 'woo-btn-primary' : 'woo-btn-secondary'; ?>"><?php esc_html_e( 'Tout', 'woo-search-intelligence-soyoo' ); ?></a>
					</div>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=woo-search-intelligence&woo_export=analytics&range=' . $days ), 'woo_search_export' ) ); ?>" class="woo-btn woo-btn-secondary">
						📥 <?php esc_html_e( 'Exporter CSV', 'woo-search-intelligence-soyoo' ); ?>
					</a>
				</div>
			</div>

			<table class="woo-table">
				<thead>
					<tr>
						<th style="width:40px;">#</th>
						<th><?php esc_html_e( 'Terme recherché', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Recherches', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Moyenne de produits', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Dernière occurrence', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Actions rapides', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top_queries ) ) : ?>
						<tr>
							<td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">
								<?php esc_html_e( 'Aucune recherche enregistrée pour cette période.', 'woo-search-intelligence-soyoo' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $top_queries as $idx => $row ) : ?>
							<?php
							$rank         = $offset + $idx + 1;
							$norm_term    = (string) $row->normalized_query;
							$count_val    = (int) $row->count;
							$avg_val      = round( (float) $row->avg_results, 1 );
							$last_seen_ts = strtotime( (string) $row->last_seen );
							?>
							<tr class="woo-analytics-row" data-term="<?php echo esc_attr( $norm_term ); ?>">
								<td style="color:#94a3b8; font-weight:700;"><?php echo (int) $rank; ?></td>
								<td><strong>« <?php echo esc_html( $norm_term ); ?> »</strong></td>
								<td style="text-align:center;"><strong><?php echo (int) $count_val; ?></strong></td>
								<td style="text-align:center;">
									<span style="font-weight:700; color:<?php echo 0.0 === $avg_val ? '#dc2626' : '#059669'; ?>;">
										<?php echo (float) $avg_val; ?>
									</span>
								</td>
								<td><?php echo esc_html( human_time_diff( $last_seen_ts, current_time( 'timestamp' ) ) ); ?></td>
								<td style="text-align:right; white-space:nowrap;">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=synonyms&from=' . rawurlencode( $norm_term ) ) ); ?>" class="woo-btn woo-btn-secondary" style="font-size:11px; padding:3px 8px; margin-right:4px;">
										➕ <?php esc_html_e( 'Synonyme', 'woo-search-intelligence-soyoo' ); ?>
									</a>
									<button type="button" class="woo-btn woo-btn-ignore woo-ignore-term-btn" data-term="<?php echo esc_attr( $norm_term ); ?>" style="font-size:11px; padding:3px 8px;" title="<?php esc_attr_e( 'Masquer ce terme des statistiques et des alertes', 'woo-search-intelligence-soyoo' ); ?>">
										🚫 <?php esc_html_e( 'Ignorer', 'woo-search-intelligence-soyoo' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="woo-pagination">
					<?php
					echo paginate_links( [
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo; ' . __( 'Précédent', 'woo-search-intelligence-soyoo' ),
						'next_text' => __( 'Suivant', 'woo-search-intelligence-soyoo' ) . ' &raquo;',
						'total'     => $total_pages,
						'current'   => $paged,
					] );
					?>
				</div>
			<?php endif; ?>

			<div style="margin-top:24px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:16px;">
				<span style="font-size:12px; color:#64748b;">
					<?php echo esc_html( sprintf( __( 'Total : %d requêtes uniques identifiées', 'woo-search-intelligence-soyoo' ), $total_rows ) ); ?>
				</span>
				<button type="button" class="woo-btn woo-btn-danger" id="woo-btn-clear-logs" style="font-size:12px;">
					🗑️ <?php esc_html_e( 'Purger les logs anciens (> 90 jours)', 'woo-search-intelligence-soyoo' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Onglet 4 : Recherches Sans Résultat & Alertes (avec blacklist universelle et suggestions)
	 */
	private function render_tab_zero_results(): void {
		global $wpdb;
		$table_logs = $wpdb->prefix . 'woo_search_logs';

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 25;
		$offset   = ( $paged - 1 ) * $per_page;

		$ignored_terms = get_option( 'woo_search_ignored_terms', [] );
		$where_ignored = '';
		$params        = [];

		if ( ! empty( $ignored_terms ) && is_array( $ignored_terms ) ) {
			$placeholders  = implode( ', ', array_fill( 0, count( $ignored_terms ), '%s' ) );
			$where_ignored = " AND normalized_query NOT IN ({$placeholders}) ";
			foreach ( $ignored_terms as $it ) {
				$params[] = (string) $it;
			}
		}

		$zero_queries = [];
		$total_rows   = 0;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_logs}'" ) === $table_logs ) {
			$count_sql  = "SELECT COUNT(DISTINCT normalized_query) FROM {$table_logs} WHERE has_results = 0 {$where_ignored}";
			$total_rows = ! empty( $params )
				? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
				: (int) $wpdb->get_var( $count_sql );

			$query_sql = "
				SELECT normalized_query, COUNT(*) as count, MAX(searched_at) as last_seen
				FROM {$table_logs}
				WHERE has_results = 0
				{$where_ignored}
				GROUP BY normalized_query
				ORDER BY count DESC, last_seen DESC
				LIMIT %d OFFSET %d
			";

			$query_params = array_merge( $params, [ $per_page, $offset ] );
			$zero_queries = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );
		}

		$total_pages = $total_rows > 0 ? (int) ceil( $total_rows / $per_page ) : 1;
		?>
		<div class="woo-card">
			<div class="woo-card-header-flex">
				<div>
					<h2 class="woo-card-title" style="margin:0;">🚨 <?php esc_html_e( 'Demandes Clients Non Satisfaites (0 Résultat)', 'woo-search-intelligence-soyoo' ); ?></h2>
					<p class="woo-card-desc" style="margin:0;"><?php esc_html_e( 'Associez un synonyme en 1 clic vers une référence existante pour capter ces ventes perdues.', 'woo-search-intelligence-soyoo' ); ?></p>
				</div>
				<div>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=woo-search-intelligence&woo_export=zero' ), 'woo_search_export' ) ); ?>" class="woo-btn woo-btn-secondary">
						📥 <?php esc_html_e( 'Exporter CSV (0 Résultat)', 'woo-search-intelligence-soyoo' ); ?>
					</a>
				</div>
			</div>

			<!-- Barre d'action groupée -->
			<div class="woo-bulk-bar" id="woo-bulk-bar" style="display:none;">
				<span id="woo-bulk-count">0 sélectionné(s)</span>
				<button type="button" class="woo-btn woo-btn-danger" id="woo-bulk-ignore-btn" style="padding:4px 12px; font-size:12px;">
					🚫 <?php esc_html_e( 'Ignorer la sélection', 'woo-search-intelligence-soyoo' ); ?>
				</button>
			</div>

			<table class="woo-table" id="woo-zero-table">
				<thead>
					<tr>
						<th style="width:28px;"><input type="checkbox" id="woo-select-all-zero"></th>
						<th><?php esc_html_e( 'Terme introuvable', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Occurrences', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Dernière tentative', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Suggestion Catalogue', 'woo-search-intelligence-soyoo' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Actions rapides', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $zero_queries ) ) : ?>
						<tr>
							<td colspan="6" style="text-align:center; color:#059669; background:#ecfdf5; padding:30px; font-weight:600;">
								🎉 <?php esc_html_e( 'Aucune recherche sans résultat active enregistrée récemment !', 'woo-search-intelligence-soyoo' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $zero_queries as $row ) : ?>
							<?php
							$norm_term    = (string) $row->normalized_query;
							$suggested    = self::get_smart_suggestion( $norm_term );
							$last_seen_ts = strtotime( (string) $row->last_seen );
							?>
							<tr class="woo-zero-row" data-term="<?php echo esc_attr( $norm_term ); ?>">
								<td><input type="checkbox" class="woo-zero-cb" value="<?php echo esc_attr( $norm_term ); ?>"></td>
								<td><strong style="color:#dc2626; font-size:14px;">« <?php echo esc_html( $norm_term ); ?> »</strong></td>
								<td style="text-align:center;">
									<span class="woo-badge-zero-count"><?php echo (int) $row->count; ?></span>
								</td>
								<td><?php echo esc_html( human_time_diff( $last_seen_ts, current_time( 'timestamp' ) ) ); ?></td>
								<td>
									<?php if ( ! empty( $suggested ) ) : ?>
										<span class="woo-suggestion-pill">
											➔ <?php echo esc_html( $suggested ); ?>
										</span>
									<?php else : ?>
										<span style="color:#94a3b8; font-style:italic;">—</span>
									<?php endif; ?>
								</td>
								<td style="text-align:right; white-space:nowrap;">
									<?php if ( ! empty( $suggested ) ) : ?>
										<button type="button" class="woo-btn woo-btn-primary woo-quick-zero-btn"
												data-from="<?php echo esc_attr( $norm_term ); ?>"
												data-to="<?php echo esc_attr( $suggested ); ?>"
												style="font-size:12px; padding:4px 8px;">
											⚡ <?php esc_html_e( 'Associer 1 clic', 'woo-search-intelligence-soyoo' ); ?>
										</button>
									<?php endif; ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-search-intelligence&tab=synonyms&from=' . rawurlencode( $norm_term ) . '&to=' . rawurlencode( $suggested ) ) ); ?>"
									   class="woo-btn woo-btn-secondary" style="font-size:12px; padding:4px 8px; margin-left:4px;" title="<?php esc_attr_e( 'Créer synonyme personnalisé', 'woo-search-intelligence-soyoo' ); ?>">
										✏️
									</a>
									<button type="button" class="woo-btn woo-btn-ignore woo-ignore-term-btn"
											data-term="<?php echo esc_attr( $norm_term ); ?>"
											style="font-size:12px; padding:4px 8px; margin-left:4px;"
											title="<?php esc_attr_e( 'Ne plus afficher ce terme dans les alertes', 'woo-search-intelligence-soyoo' ); ?>">
										🚫 <?php esc_html_e( 'Ignorer', 'woo-search-intelligence-soyoo' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="woo-pagination">
					<?php
					echo paginate_links( [
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo; ' . __( 'Précédent', 'woo-search-intelligence-soyoo' ),
						'next_text' => __( 'Suivant', 'woo-search-intelligence-soyoo' ) . ' &raquo;',
						'total'     => $total_pages,
						'current'   => $paged,
					] );
					?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Section des Termes Ignorés Définitivement (Blacklist universelle) -->
		<div class="woo-card" style="margin-top:24px;">
			<h3 class="woo-card-title" style="font-size:15px;">
				🚫 <?php echo esc_html( sprintf( __( 'Termes Ignorés Définitivement (%d)', 'woo-search-intelligence-soyoo' ), is_countable( $ignored_terms ) ? count( $ignored_terms ) : 0 ) ); ?>
			</h3>
			<p class="woo-card-desc"><?php esc_html_e( 'Ces termes sont exclus des tableaux d\'analyse et ne déclenchent plus aucune alerte e-mail.', 'woo-search-intelligence-soyoo' ); ?></p>

			<div class="woo-ignored-list">
				<?php if ( empty( $ignored_terms ) ) : ?>
					<p style="color:#94a3b8; font-size:13px; margin:0; font-style:italic;"><?php esc_html_e( 'Aucun terme ignoré pour le moment.', 'woo-search-intelligence-soyoo' ); ?></p>
				<?php else : ?>
					<?php foreach ( $ignored_terms as $it ) : ?>
						<span class="woo-ignored-pill">
							<strong>« <?php echo esc_html( (string) $it ); ?> »</strong>
							<button type="button" class="woo-unignore-btn" data-term="<?php echo esc_attr( (string) $it ); ?>" title="<?php esc_attr_e( 'Réactiver ce terme', 'woo-search-intelligence-soyoo' ); ?>">×</button>
						</span>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Calcule une suggestion intelligente de catalogue pour un terme à 0 résultat
	 *
	 * @param string $term Terme introuvable.
	 * @return string Suggestion de produit ou terme proche.
	 */
	public static function get_smart_suggestion( string $term ): string {
		$clean = mb_strtolower( trim( $term ), 'UTF-8' );

		// Vérifie si un lemme ou terme raccourci donne des résultats.
		$words = array_filter( explode( ' ', $clean ), fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 3 );
		if ( ! empty( $words ) ) {
			$engine = Woo_Search_Engine::instance();
			foreach ( $words as $w ) {
				$lemmas = $engine->get_word_lemmas( $w );
				foreach ( $lemmas as $lem ) {
					if ( $lem !== $w ) {
						$test = $engine->query_catalog_candidates( $lem, 1 );
						if ( ! empty( $test['candidates'] ) ) {
							return $lem;
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Gestion du téléchargement des exports CSV
	 */
	public function handle_csv_export(): void {
		if ( ! isset( $_GET['woo_export'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'woo_search_export' );

		global $wpdb;
		$table_logs = $wpdb->prefix . 'woo_search_logs';
		$export_type = sanitize_key( $_GET['woo_export'] );
		$days        = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30;

		$where_clauses = [ '1=1' ];
		$params        = [];

		if ( 'zero' === $export_type ) {
			$where_clauses[] = 'has_results = 0';
		}

		if ( $days > 0 ) {
			$where_clauses[] = 'searched_at >= %s';
			$params[]        = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		}

		$ignored = get_option( 'woo_search_ignored_terms', [] );
		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$placeholders    = implode( ', ', array_fill( 0, count( $ignored ), '%s' ) );
			$where_clauses[] = "normalized_query NOT IN ({$placeholders})";
			foreach ( $ignored as $it ) {
				$params[] = (string) $it;
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$sql = "
			SELECT normalized_query, COUNT(*) as count, AVG(results_count) as avg_results, MAX(searched_at) as last_seen
			FROM {$table_logs}
			WHERE {$where_sql}
			GROUP BY normalized_query
			ORDER BY count DESC
		";

		$rows = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$filename = sprintf( 'recherches-export-%s-%s.csv', $export_type, date( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		// Insertion du BOM UTF-8 pour ouverture directe parfaite dans Microsoft Excel.
		fputs( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, [ 'Terme de recherche', 'Nombre de recherches', 'Moyenne de resultats', 'Derniere recherche' ], ';' );

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				fputcsv( $output, [
					$r->normalized_query,
					$r->count,
					round( (float) $r->avg_results, 1 ),
					$r->last_seen,
				], ';' );
			}
		}

		fclose( $output );
		exit;
	}

	/**
	 * AJAX Sauvegarde des réglages
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$settings = [
			'min_chars'             => max( 1, min( 5, absint( $_POST['min_chars'] ?? 2 ) ) ),
			'max_results'           => max( 3, min( 20, absint( $_POST['max_results'] ?? 6 ) ) ),
			'enable_sku_variations' => ! empty( $_POST['enable_sku_variations'] ) ? 1 : 0,
			'search_in_excerpt'     => ! empty( $_POST['search_in_excerpt'] ) ? 1 : 0,
			'show_images'           => ! empty( $_POST['show_images'] ) ? 1 : 0,
			'show_prices'           => ! empty( $_POST['show_prices'] ) ? 1 : 0,
			'show_sku_badge'        => ! empty( $_POST['show_sku_badge'] ) ? 1 : 0,
			'show_stock'            => ! empty( $_POST['show_stock'] ) ? 1 : 0,
			'enable_alerts'         => ! empty( $_POST['enable_alerts'] ) ? 1 : 0,
			'alert_email'           => sanitize_email( (string) ( $_POST['alert_email'] ?? get_option( 'admin_email' ) ) ),
			'alert_mode'            => in_array( $_POST['alert_mode'] ?? '', [ 'weekly', 'threshold' ], true ) ? $_POST['alert_mode'] : 'weekly',
			'alert_threshold'       => max( 2, absint( $_POST['alert_threshold'] ?? 5 ) ),
		];

		update_option( 'woo_search_settings', $settings );
		Woo_Search_Engine::instance()->load_options();
		Woo_Search_Engine::instance()->clear_search_transients();

		wp_send_json_success( [ 'message' => __( 'Paramètres enregistrés avec succès !', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * AJAX Ajout d'un synonyme
	 */
	public function ajax_add_synonym(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$from = trim( sanitize_text_field( (string) ( $_POST['from'] ?? '' ) ) );
		$to   = trim( sanitize_text_field( (string) ( $_POST['to'] ?? '' ) ) );
		$type = in_array( $_POST['type'] ?? '', [ 'replace', 'expand' ], true ) ? $_POST['type'] : 'expand';

		if ( empty( $from ) || empty( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'Les deux termes sont requis.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$synonyms = get_option( 'woo_search_synonyms', [] );
		if ( ! is_array( $synonyms ) ) {
			$synonyms = [];
		}

		// Ajout en début de liste.
		array_unshift( $synonyms, [
			'from' => $from,
			'to'   => $to,
			'type' => $type,
		] );

		update_option( 'woo_search_synonyms', $synonyms );
		Woo_Search_Engine::instance()->clear_search_transients();

		wp_send_json_success( [ 'message' => sprintf( __( 'Synonyme « %s » associé à « %s » !', 'woo-search-intelligence-soyoo' ), $from, $to ) ] );
	}

	/**
	 * AJAX Modification en ligne d'un synonyme
	 */
	public function ajax_edit_synonym(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$from  = trim( sanitize_text_field( (string) ( $_POST['from'] ?? '' ) ) );
		$to    = trim( sanitize_text_field( (string) ( $_POST['to'] ?? '' ) ) );
		$type  = in_array( $_POST['type'] ?? '', [ 'replace', 'expand' ], true ) ? $_POST['type'] : 'expand';

		if ( $index < 0 || empty( $from ) || empty( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'Données invalides.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$synonyms = get_option( 'woo_search_synonyms', [] );
		if ( ! is_array( $synonyms ) || ! isset( $synonyms[ $index ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Règle introuvable.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$synonyms[ $index ] = [
			'from' => $from,
			'to'   => $to,
			'type' => $type,
		];

		update_option( 'woo_search_synonyms', $synonyms );
		Woo_Search_Engine::instance()->clear_search_transients();

		wp_send_json_success( [ 'message' => __( 'Synonyme mis à jour avec succès.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * AJAX Suppression d'un synonyme
	 */
	public function ajax_delete_synonym(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$index    = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$synonyms = get_option( 'woo_search_synonyms', [] );

		if ( ! is_array( $synonyms ) || ! isset( $synonyms[ $index ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Règle introuvable.', 'woo-search-intelligence-soyoo' ) ] );
		}

		unset( $synonyms[ $index ] );
		update_option( 'woo_search_synonyms', array_values( $synonyms ) );
		Woo_Search_Engine::instance()->clear_search_transients();

		wp_send_json_success( [ 'message' => __( 'Synonyme supprimé avec succès.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * AJAX Purge du cache transients
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		Woo_Search_Engine::instance()->clear_search_transients();
		wp_send_json_success( [ 'message' => __( 'Cache de recherche purgé avec succès.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * AJAX Purge des logs anciens (> 90 jours)
	 */
	public function ajax_clear_logs(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		global $wpdb;
		$table           = $wpdb->prefix . 'woo_search_logs';
		$ninety_days_ago = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE searched_at < %s", $ninety_days_ago ) );

		wp_send_json_success( [ 'message' => __( 'Logs antérieurs à 90 jours purgés avec succès.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * AJAX Envoi d'un e-mail de test
	 */
	public function ajax_test_email(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$email = sanitize_email( (string) ( $_POST['email'] ?? get_option( 'admin_email' ) ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Adresse e-mail invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[Test] Alerte Moteur de Recherche — %s', $site_name );
		$message   = "Bonjour,\n\nCeci est un message de confirmation envoyé depuis l'administration de WooCommerce Search Intelligence.\n\nVotre système d'alertes décisionnelles est pleinement opérationnel !\n\nSOYOO";

		$sent = wp_mail( $email, $subject, $message );
		if ( $sent ) {
			wp_send_json_success( [ 'message' => __( 'E-mail de test envoyé avec succès !', 'woo-search-intelligence-soyoo' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Échec de l\'envoi de l\'e-mail. Vérifiez la configuration SMTP de votre serveur.', 'woo-search-intelligence-soyoo' ) ] );
		}
	}

	/**
	 * AJAX Ignorer définitivement un terme (Blacklist universelle)
	 */
	public function ajax_ignore_term(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$term = trim( sanitize_text_field( (string) ( $_POST['term'] ?? '' ) ) );
		if ( empty( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Terme invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine = Woo_Search_Engine::instance();
		$norm   = $engine->normalize_query( $term );

		$ignored       = get_option( 'woo_search_ignored_terms', [] );
		$ignored_lower = is_array( $ignored ) ? array_map( fn( $t ) => mb_strtolower( (string) $t, 'UTF-8' ), $ignored ) : [];

		if ( ! in_array( mb_strtolower( $norm, 'UTF-8' ), $ignored_lower, true ) ) {
			$ignored[] = $norm;
			update_option( 'woo_search_ignored_terms', $ignored );
		}

		wp_send_json_success( [
			'term'    => $norm,
			'message' => sprintf( __( 'Le terme « %s » a été ignoré définitivement.', 'woo-search-intelligence-soyoo' ), $term ),
		] );
	}

	/**
	 * AJAX Réactiver un terme ignoré
	 */
	public function ajax_unignore_term(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$term = trim( sanitize_text_field( (string) ( $_POST['term'] ?? '' ) ) );
		if ( empty( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Terme invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$term_lower  = mb_strtolower( $term, 'UTF-8' );
		$ignored     = get_option( 'woo_search_ignored_terms', [] );
		$new_ignored = [];

		if ( is_array( $ignored ) ) {
			$new_ignored = array_values( array_filter( $ignored, fn( $t ) => mb_strtolower( (string) $t, 'UTF-8' ) !== $term_lower ) );
		}

		update_option( 'woo_search_ignored_terms', $new_ignored );

		wp_send_json_success( [
			'message' => sprintf( __( 'Le terme « %s » a été réactivé avec succès.', 'woo-search-intelligence-soyoo' ), $term ),
		] );
	}

	/**
	 * AJAX Ignorer en masse des termes
	 */
	public function ajax_bulk_ignore_terms(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$terms = $_POST['terms'] ?? [];
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			wp_send_json_error( [ 'message' => __( 'Aucun terme sélectionné.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine        = Woo_Search_Engine::instance();
		$ignored       = get_option( 'woo_search_ignored_terms', [] );
		$ignored       = is_array( $ignored ) ? $ignored : [];
		$ignored_lower = array_map( fn( $t ) => mb_strtolower( (string) $t, 'UTF-8' ), $ignored );

		$added_count = 0;
		foreach ( $terms as $raw_term ) {
			$term = trim( sanitize_text_field( (string) $raw_term ) );
			if ( empty( $term ) ) {
				continue;
			}
			$norm       = $engine->normalize_query( $term );
			$norm_lower = mb_strtolower( $norm, 'UTF-8' );

			if ( ! in_array( $norm_lower, $ignored_lower, true ) ) {
				$ignored[]       = $norm;
				$ignored_lower[] = $norm_lower;
				$added_count++;
			}
		}

		if ( $added_count > 0 ) {
			update_option( 'woo_search_ignored_terms', $ignored );
		}

		wp_send_json_success( [
			'count'   => count( $terms ),
			'added'   => $added_count,
			'message' => sprintf( __( '%d terme(s) ignoré(s) définitivement.', 'woo-search-intelligence-soyoo' ), count( $terms ) ),
		] );
	}

	/**
	 * AJAX Installation de tous les synonymes recommandés
	 */
	public function ajax_install_recommended_synonyms(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$existing      = get_option( 'woo_search_synonyms', [] );
		$existing      = is_array( $existing ) ? $existing : [];
		$existing_keys = [];

		foreach ( $existing as $item ) {
			$existing_keys[ mb_strtolower( trim( (string) $item['from'] ), 'UTF-8' ) ] = true;
		}

		$recommended = [
			'Général' => [
				[ 'from' => 'cadeau',    'to' => 'coffret',   'type' => 'expand' ],
				[ 'from' => 'promo',     'to' => 'soldes',    'type' => 'expand' ],
				[ 'from' => 'reduction', 'to' => 'promotion', 'type' => 'expand' ],
			],
		];
		$recommended = apply_filters( 'woo_search_recommended_packs', $recommended );

		$added = 0;
		foreach ( $recommended as $items ) {
			foreach ( $items as $rec ) {
				$key = mb_strtolower( trim( (string) $rec['from'] ), 'UTF-8' );
				if ( ! isset( $existing_keys[ $key ] ) ) {
					$existing[]            = [
						'from' => (string) $rec['from'],
						'to'   => (string) $rec['to'],
						'type' => (string) $rec['type'],
					];
					$existing_keys[ $key ] = true;
					$added++;
				}
			}
		}

		update_option( 'woo_search_synonyms', $existing );
		Woo_Search_Engine::instance()->clear_search_transients();

		wp_send_json_success( [
			'added'   => $added,
			'message' => sprintf( __( '%d nouveau(x) synonyme(s) installé(s) avec succès !', 'woo-search-intelligence-soyoo' ), $added ),
		] );
	}
}
