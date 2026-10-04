<?php
/**
 * Tableau de bord d'administration
 *
 * 4 onglets : Paramètres & Index, Synonymes, Statistiques (CTR, conversions), 0 Résultat.
 * KPIs mis en cache 10 min, export CSV protégé contre l'injection de formules,
 * synonymes adressés par identifiant stable, statut « résolu » des termes sans résultat.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Admin {

	private const PAGE     = 'woo-search-intelligence';
	private const PER_PAGE = 25;
	private const RANGES   = [ 7, 30, 90, 0 ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 65 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'in_admin_header', [ $this, 'suppress_admin_notices' ], 1000 );
		add_action( 'admin_init', [ $this, 'handle_csv_export' ] );
		add_filter( 'plugin_action_links_' . WOO_SEARCH_INTEL_BASENAME, [ $this, 'plugin_action_links' ] );

		$ajax = [
			'save_settings', 'add_synonym', 'edit_synonym', 'delete_synonym', 'install_recommended_synonyms',
			'clear_cache', 'clear_logs', 'test_email', 'ignore_term', 'unignore_term', 'bulk_ignore_terms', 'index_batch',
		];
		foreach ( $ajax as $action ) {
			add_action( 'wp_ajax_woo_search_' . $action, [ $this, 'ajax_' . $action ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Socle
	 * ------------------------------------------------------------------ */

	public function suppress_admin_notices(): void {
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Search Intelligence', 'woo-search-intelligence-soyoo' ),
			__( 'Search Intelligence', 'woo-search-intelligence-soyoo' ),
			'manage_woocommerce',
			self::PAGE,
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * @param array<int|string, string> $links Liens.
	 * @return array<int|string, string>
	 */
	public function plugin_action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( $this->tab_url( 'settings' ) ) . '">' . esc_html__( 'Réglages', 'woo-search-intelligence-soyoo' ) . '</a>' );
		return $links;
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE ) ) {
			return;
		}

		wp_enqueue_style( 'woo-search-admin-css', WOO_SEARCH_INTEL_URL . 'assets/css/admin.css', [], WOO_SEARCH_INTEL_VERSION );
		wp_enqueue_script( 'woo-search-admin-js', WOO_SEARCH_INTEL_URL . 'assets/js/admin.js', [ 'jquery' ], WOO_SEARCH_INTEL_VERSION, true );

		wp_localize_script( 'woo-search-admin-js', 'wooSearchAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'woo_search_admin_nonce' ),
			'i18n'    => [
				'confirmClearLogs' => __( 'Supprimer définitivement les statistiques de plus de 90 jours ?', 'woo-search-intelligence-soyoo' ),
				'confirmIgnore'    => __( 'Ignorer ce terme ? Il sera exclu des statistiques et des alertes.', 'woo-search-intelligence-soyoo' ),
				'confirmBulk'      => __( 'Ignorer les termes sélectionnés ?', 'woo-search-intelligence-soyoo' ),
				'confirmDeleteSyn' => __( 'Supprimer ce synonyme ?', 'woo-search-intelligence-soyoo' ),
				'confirmRebuild'   => __( 'Reconstruire l\'index complet du catalogue ? La recherche reste fonctionnelle pendant l\'opération.', 'woo-search-intelligence-soyoo' ),
				'saving'           => __( 'Enregistrement…', 'woo-search-intelligence-soyoo' ),
				'error'            => __( 'Une erreur est survenue.', 'woo-search-intelligence-soyoo' ),
				'sessionExpired'   => __( 'Session expirée : rechargez la page.', 'woo-search-intelligence-soyoo' ),
				'replace'          => __( 'Remplacement', 'woo-search-intelligence-soyoo' ),
				'expand'           => __( 'Expansion', 'woo-search-intelligence-soyoo' ),
				'save'             => __( 'Enregistrer', 'woo-search-intelligence-soyoo' ),
				'cancel'           => __( 'Annuler', 'woo-search-intelligence-soyoo' ),
			],
		] );
	}

	/**
	 * @param array<string, scalar> $args Paramètres supplémentaires.
	 */
	private function tab_url( string $tab, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	private function check_ajax(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ], 403 );
		}
	}

	private function current_range( int $default = 30 ): int {
		$days = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $days, self::RANGES, true ) ? $days : $default;
	}

	/**
	 * Clauses WHERE communes : période (UTC) et termes ignorés.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function base_where( int $days ): array {
		$clauses = [ '1=1' ];
		$params  = [];

		if ( $days > 0 ) {
			$clauses[] = 'searched_at >= %s';
			$params[]  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		}

		$ignored = Woo_Search_Tracker::get_ignored_terms();
		if ( ! empty( $ignored ) ) {
			$clauses[] = 'normalized_query NOT IN (' . implode( ', ', array_fill( 0, count( $ignored ), '%s' ) ) . ')';
			$params    = array_merge( $params, $ignored );
		}

		return [ implode( ' AND ', $clauses ), $params ];
	}

	/**
	 * @param array<int, mixed> $params Paramètres.
	 */
	private function prepare( string $sql, array $params ): string {
		global $wpdb;
		return empty( $params ) ? $sql : (string) $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function human_ago( string $utc_datetime ): string {
		$ts = strtotime( $utc_datetime . ' UTC' );
		return $ts ? human_time_diff( $ts, time() ) : '—';
	}

	/* ---------------------------------------------------------------------
	 * KPIs
	 * ------------------------------------------------------------------ */

	public static function flush_kpis(): void {
		foreach ( self::RANGES as $days ) {
			delete_transient( 'wsi_kpis_' . $days );
		}
	}

	/**
	 * Indicateurs clés (cache 10 min), termes ignorés exclus.
	 * CTR et conversions calculés hors lignes importées (sans données de clic).
	 *
	 * @return array<string, mixed>
	 */
	public function get_search_kpis( int $days = 30 ): array {
		$cached = get_transient( 'wsi_kpis_' . $days );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table           = Woo_Search_Installer::tables()['logs'];
		[ $where, $params ] = $this->base_where( $days );

		// phpcs:disable WordPress.DB.PreparedSQL
		$row = $wpdb->get_row( $this->prepare(
			"SELECT COUNT(*) AS total,
				SUM(has_results = 0) AS zero_count,
				COUNT(DISTINCT CASE WHEN has_results = 0 THEN normalized_query END) AS zero_terms,
				SUM(match_mode IN ('corrected', 'any')) AS approx,
				SUM(source <> 'import') AS trackable,
				SUM(clicks > 0) AS clicked,
				AVG(CASE WHEN clicks > 0 THEN click_position END) AS avg_position,
				SUM(order_id > 0) AS orders,
				COALESCE(SUM(order_total), 0) AS revenue
			FROM {$table} WHERE {$where}",
			$params
		) );

		$top = $wpdb->get_var( $this->prepare(
			"SELECT normalized_query FROM {$table} WHERE {$where} GROUP BY normalized_query ORDER BY COUNT(*) DESC LIMIT 1",
			$params
		) );
		// phpcs:enable

		$total     = (int) ( $row->total ?? 0 );
		$trackable = (int) ( $row->trackable ?? 0 );
		$pct       = static fn( float $part, int $whole ): float => $whole > 0 ? round( $part / $whole * 100, 1 ) : 0.0;

		$kpis = [
			'total_searches'  => $total,
			'zero_count'      => (int) ( $row->zero_count ?? 0 ),
			'zero_terms'      => (int) ( $row->zero_terms ?? 0 ),
			'success_rate'    => $pct( (float) ( $total - (int) ( $row->zero_count ?? 0 ) ), $total ),
			'zero_rate'       => $pct( (float) ( $row->zero_count ?? 0 ), $total ),
			'approx_rate'     => $pct( (float) ( $row->approx ?? 0 ), $total ),
			'ctr'             => $pct( (float) ( $row->clicked ?? 0 ), $trackable ),
			'avg_position'    => round( (float) ( $row->avg_position ?? 0 ), 1 ),
			'orders'          => (int) ( $row->orders ?? 0 ),
			'revenue'         => (float) ( $row->revenue ?? 0 ),
			'conversion_rate' => $pct( (float) ( $row->orders ?? 0 ), $trackable ),
			'top_term'        => (string) $top,
		];

		set_transient( 'wsi_kpis_' . $days, $kpis, 10 * MINUTE_IN_SECONDS );
		return $kpis;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'woo-search-intelligence-soyoo' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = [
			'settings'     => '⚙️ ' . __( 'Paramètres & Index', 'woo-search-intelligence-soyoo' ),
			'synonyms'     => '📖 ' . __( 'Synonymes', 'woo-search-intelligence-soyoo' ),
			'analytics'    => '📊 ' . __( 'Statistiques', 'woo-search-intelligence-soyoo' ),
			'zero_results' => '🚨 ' . __( '0 Résultat & Opportunités', 'woo-search-intelligence-soyoo' ),
		];
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		$engine   = Woo_Search_Engine::instance();
		$indexer  = Woo_Search_Indexer::instance();
		$state    = $indexer->get_state();
		$synonyms = $engine->get_synonyms();
		$kpis     = $this->get_search_kpis( 30 );
		$legacy   = Woo_Search_Importer::get_legacy_tables_info();
		?>
		<div class="wrap woo-search-wrap">
			<div class="woo-admin-header">
				<div class="woo-admin-brand">
					<div class="woo-admin-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					</div>
					<div>
						<h1 class="woo-admin-title">WooCommerce Search Intelligence</h1>
						<p class="woo-admin-subtitle"><?php esc_html_e( 'Moteur de recherche indexé, correction orthographique, synonymes et mesure de la performance commerciale.', 'woo-search-intelligence-soyoo' ); ?></p>
					</div>
				</div>
				<div class="woo-admin-badges">
					<span class="woo-badge woo-badge-version">v<?php echo esc_html( WOO_SEARCH_INTEL_VERSION ); ?></span>
					<?php if ( $state['ready'] ) : ?>
						<span class="woo-badge woo-badge-hpos"><?php esc_html_e( 'Index actif', 'woo-search-intelligence-soyoo' ); ?></span>
					<?php else : ?>
						<span class="woo-badge woo-badge-warn"><?php esc_html_e( 'Index en construction', 'woo-search-intelligence-soyoo' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! $state['ready'] ) : ?>
				<div class="woo-banner-import woo-banner-warn">
					<div class="woo-banner-import-left">
						<span class="woo-banner-icon">⏳</span>
						<div>
							<strong><?php esc_html_e( 'Index du catalogue en cours de construction', 'woo-search-intelligence-soyoo' ); ?></strong>
							<p><?php esc_html_e( 'La recherche native WordPress assure l\'intérim. Vous pouvez accélérer la construction depuis l\'onglet Paramètres.', 'woo-search-intelligence-soyoo' ); ?></p>
						</div>
					</div>
					<a href="<?php echo esc_url( $this->tab_url( 'settings' ) . '#woo-index-card' ); ?>" class="woo-btn woo-btn-primary"><?php esc_html_e( 'Construire maintenant', 'woo-search-intelligence-soyoo' ); ?></a>
				</div>
			<?php elseif ( $legacy['has_history'] && ! get_option( 'woo_search_imported_at' ) && 'settings' !== $tab ) : ?>
				<div class="woo-banner-import">
					<div class="woo-banner-import-left">
						<span class="woo-banner-icon">📥</span>
						<div>
							<strong><?php esc_html_e( 'Historique Search Analytics for WP détecté', 'woo-search-intelligence-soyoo' ); ?></strong>
							<?php /* translators: %s: nombre de recherches */ ?>
							<p><?php echo esc_html( sprintf( __( '%s recherches historiques peuvent être importées.', 'woo-search-intelligence-soyoo' ), number_format_i18n( $legacy['total'] ) ) ); ?></p>
						</div>
					</div>
					<a href="<?php echo esc_url( $this->tab_url( 'settings' ) . '#woo-importer-card' ); ?>" class="woo-btn woo-btn-primary"><?php esc_html_e( 'Lancer la migration', 'woo-search-intelligence-soyoo' ); ?></a>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper woo-tabs-nav">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( 'synonyms' === $key ) : ?>
							<span class="woo-tab-count"><?php echo (int) count( $synonyms ); ?></span>
						<?php elseif ( 'zero_results' === $key && $kpis['zero_terms'] > 0 ) : ?>
							<span class="woo-tab-badge-alert" title="<?php esc_attr_e( 'Termes distincts sans résultat (30 jours)', 'woo-search-intelligence-soyoo' ); ?>"><?php echo (int) $kpis['zero_terms']; ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="woo-tab-body">
				<?php
				switch ( $tab ) {
					case 'synonyms':
						$this->render_tab_synonyms( $synonyms );
						break;
					case 'analytics':
						$this->render_tab_analytics();
						break;
					case 'zero_results':
						$this->render_tab_zero_results();
						break;
					default:
						$this->render_tab_settings( $engine, $state, $legacy );
				}
				?>
			</div>
		</div>
		<?php
	}

	private function checkbox( Woo_Search_Engine $engine, string $name, string $label, string $hint = '' ): void {
		?>
		<div class="woo-form-group">
			<label class="woo-checkbox-label">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $engine->get_option( $name ) ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( '' !== $hint ) : ?>
				<p class="woo-field-hint"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function number_field( Woo_Search_Engine $engine, string $name, string $label, int $min, int $max, string $hint = '' ): void {
		?>
		<div class="woo-form-group">
			<label for="woo_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" id="woo_<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" value="<?php echo esc_attr( (string) $engine->get_option( $name ) ); ?>" class="small-text">
			<?php if ( '' !== $hint ) : ?>
				<p class="woo-field-hint"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Onglet Paramètres & Index.
	 *
	 * @param array<string, mixed> $state  État de l'index.
	 * @param array<string, mixed> $legacy Tables héritées.
	 */
	private function render_tab_settings( Woo_Search_Engine $engine, array $state, array $legacy ): void {
		$indexer        = Woo_Search_Indexer::instance();
		$indexed        = $indexer->count_indexed();
		$publishable    = $indexer->count_publishable();
		$imported_at    = (string) get_option( 'woo_search_imported_at', '' );
		$imported_count = (int) get_option( 'woo_search_imported_count', 0 );
		$tables         = Woo_Search_Installer::tables();
		?>
		<div class="woo-settings-grid">
			<div class="woo-settings-main">
				<form id="woo-settings-form">
					<div class="woo-card">
						<h2 class="woo-card-title">🔍 <?php esc_html_e( 'Moteur & pertinence', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Tous les mots significatifs doivent être trouvés (titre, SKU, catégories/attributs, extrait). Le classement privilégie le SKU exact, puis la phrase dans le titre, puis les mots entiers.', 'woo-search-intelligence-soyoo' ); ?></p>
						<?php
						$this->number_field( $engine, 'min_chars', __( 'Nombre minimal de caractères', 'woo-search-intelligence-soyoo' ), 1, 5, __( 'Recommandé : 2.', 'woo-search-intelligence-soyoo' ) );
						$this->number_field( $engine, 'max_results', __( 'Produits affichés dans le live search', 'woo-search-intelligence-soyoo' ), 3, 20, __( 'Recommandé : 6.', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'integrate_search_page', __( 'Utiliser le moteur sur la page de résultats complète', 'woo-search-intelligence-soyoo' ), __( 'Garantit les mêmes résultats dans la liste déroulante et sur la page « Voir tous les résultats ».', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'enable_sku_variations', __( 'Recherche par SKU / EAN (parents et variations)', 'woo-search-intelligence-soyoo' ), __( 'SKU exact : priorité maximale. Début de SKU : seulement si la saisie contient un chiffre (≥ 3 caractères).', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'search_in_terms', __( 'Rechercher dans les catégories, marques, étiquettes et attributs', 'woo-search-intelligence-soyoo' ), __( 'Ex. « bâche verte » trouve un produit dont « verte » est une valeur d\'attribut.', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'search_in_excerpt', __( 'Rechercher dans la description courte', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'enable_spellcheck', __( 'Correction orthographique automatique', 'woo-search-intelligence-soyoo' ), __( 'Si aucun résultat : « tondeuze » est relancé en « tondeuse » à partir du vocabulaire de votre catalogue.', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'enable_partial_fallback', __( 'Afficher les correspondances partielles en dernier recours', 'woo-search-intelligence-soyoo' ), __( 'Si aucun produit ne contient tous les mots, affiche ceux qui en contiennent le plus.', 'woo-search-intelligence-soyoo' ) );
						?>
					</div>

					<div class="woo-card">
						<h2 class="woo-card-title">🎨 <?php esc_html_e( 'Affichage', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Données renvoyées au live search (thème ou interface intégrée).', 'woo-search-intelligence-soyoo' ); ?></p>
						<?php
						$this->checkbox( $engine, 'show_images', __( 'Miniatures produits', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'show_prices', __( 'Prix (promotions incluses)', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'show_sku_badge', __( 'Badge SKU', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'show_stock', __( 'Disponibilité', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'show_categories', __( 'Catégories suggérées', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'format_titles', __( 'Harmoniser les titres écrits en MAJUSCULES', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'enable_frontend_ui', __( 'Activer l\'interface de live search intégrée', 'woo-search-intelligence-soyoo' ), __( 'À laisser désactivé si le thème possède déjà sa propre liste déroulante (Bâches MFM, Jardin Naturel).', 'woo-search-intelligence-soyoo' ) );
						?>
						<div class="woo-form-group">
							<label for="woo_frontend_selector"><?php esc_html_e( 'Sélecteur CSS des champs de recherche (optionnel)', 'woo-search-intelligence-soyoo' ); ?></label>
							<input type="text" id="woo_frontend_selector" name="frontend_selector" value="<?php echo esc_attr( (string) $engine->get_option( 'frontend_selector' ) ); ?>" class="regular-text" placeholder='input[name="s"]'>
						</div>
					</div>

					<div class="woo-card">
						<h2 class="woo-card-title">📈 <?php esc_html_e( 'Mesure & confidentialité', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Empreinte de session salée et renouvelée chaque jour (aucune IP stockée). Les robots sont exclus.', 'woo-search-intelligence-soyoo' ); ?></p>
						<?php
						$this->checkbox( $engine, 'enable_click_tracking', __( 'Mesurer les clics sur les résultats (CTR, position)', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'enable_conversion_tracking', __( 'Attribuer les commandes à la recherche (cookie first-party 24 h)', 'woo-search-intelligence-soyoo' ) );
						$this->checkbox( $engine, 'exclude_admins', __( 'Exclure les gestionnaires de la boutique des statistiques', 'woo-search-intelligence-soyoo' ) );
						$this->number_field( $engine, 'log_retention_days', __( 'Durée de conservation des statistiques (jours, 0 = illimitée)', 'woo-search-intelligence-soyoo' ), 0, 3650 );
						$this->checkbox( $engine, 'delete_data_on_uninstall', __( 'Supprimer toutes les données à la désinstallation', 'woo-search-intelligence-soyoo' ) );
						?>
					</div>

					<div class="woo-card">
						<h2 class="woo-card-title">🚨 <?php esc_html_e( 'Alertes « 0 résultat »', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Envoyées en tâche de fond, sans impact sur la navigation des visiteurs.', 'woo-search-intelligence-soyoo' ); ?></p>
						<div class="woo-form-group">
							<label class="woo-checkbox-label">
								<input type="checkbox" name="enable_alerts" value="1" id="woo_enable_alerts" <?php checked( (bool) $engine->get_option( 'enable_alerts' ) ); ?>>
								<span><?php esc_html_e( 'Activer les alertes e-mail', 'woo-search-intelligence-soyoo' ); ?></span>
							</label>
						</div>
						<div class="woo-alerts-options" style="<?php echo $engine->get_option( 'enable_alerts' ) ? '' : 'display:none;'; ?>">
							<div class="woo-form-group">
								<label for="woo_alert_email"><?php esc_html_e( 'Destinataire', 'woo-search-intelligence-soyoo' ); ?></label>
								<input type="email" id="woo_alert_email" name="alert_email" value="<?php echo esc_attr( (string) $engine->get_option( 'alert_email' ) ); ?>" class="regular-text">
							</div>
							<div class="woo-form-group">
								<label><?php esc_html_e( 'Fréquence', 'woo-search-intelligence-soyoo' ); ?></label>
								<div class="woo-radio-stack">
									<label>
										<input type="radio" name="alert_mode" value="weekly" <?php checked( (string) $engine->get_option( 'alert_mode' ), 'weekly' ); ?>>
										<strong><?php esc_html_e( 'Rapport hebdomadaire (recommandé)', 'woo-search-intelligence-soyoo' ); ?></strong>
										<span class="woo-field-hint"><?php esc_html_e( 'Chaque lundi : synthèse, CTR, commandes et top 10 des recherches sans résultat.', 'woo-search-intelligence-soyoo' ); ?></span>
									</label>
									<label>
										<input type="radio" name="alert_mode" value="threshold" <?php checked( (string) $engine->get_option( 'alert_mode' ), 'threshold' ); ?>>
										<strong><?php esc_html_e( 'Alerte sur seuil', 'woo-search-intelligence-soyoo' ); ?></strong>
										<span class="woo-field-hint"><?php esc_html_e( 'Dès qu\'un terme est cherché sans résultat par X visiteurs différents sur 7 jours.', 'woo-search-intelligence-soyoo' ); ?></span>
									</label>
								</div>
							</div>
							<div class="woo-form-group" id="woo_threshold_group" style="<?php echo 'threshold' === $engine->get_option( 'alert_mode' ) ? '' : 'display:none;'; ?>">
								<label for="woo_alert_threshold"><?php esc_html_e( 'Seuil (visiteurs distincts)', 'woo-search-intelligence-soyoo' ); ?></label>
								<input type="number" id="woo_alert_threshold" name="alert_threshold" min="2" max="50" value="<?php echo esc_attr( (string) $engine->get_option( 'alert_threshold' ) ); ?>" class="small-text">
							</div>
							<div class="woo-form-group">
								<button type="button" class="woo-btn woo-btn-secondary" id="woo-btn-test-email">✉️ <?php esc_html_e( 'Envoyer un e-mail de test', 'woo-search-intelligence-soyoo' ); ?></button>
								<span id="woo-test-email-status" class="woo-inline-status"></span>
							</div>
						</div>
					</div>

					<div class="woo-form-actions">
						<button type="submit" class="woo-btn woo-btn-primary woo-btn-lg" id="woo-save-settings-btn">💾 <?php esc_html_e( 'Enregistrer les paramètres', 'woo-search-intelligence-soyoo' ); ?></button>
						<span id="woo-save-status" class="woo-inline-status"></span>
					</div>
				</form>
			</div>

			<div class="woo-settings-sidebar">
				<div class="woo-card" id="woo-index-card">
					<h3 class="woo-card-title woo-card-title-sm">🗂️ <?php esc_html_e( 'Index du catalogue', 'woo-search-intelligence-soyoo' ); ?></h3>
					<ul class="woo-tech-list">
						<li><strong><?php esc_html_e( 'Produits indexés :', 'woo-search-intelligence-soyoo' ); ?></strong> <?php echo esc_html( number_format_i18n( $indexed ) ); ?> / <?php echo esc_html( number_format_i18n( $publishable ) ); ?> <?php esc_html_e( 'publiés', 'woo-search-intelligence-soyoo' ); ?></li>
						<li><strong><?php esc_html_e( 'Statut :', 'woo-search-intelligence-soyoo' ); ?></strong>
							<?php
							$labels = [
								'ready'   => __( 'À jour (mise à jour en continu)', 'woo-search-intelligence-soyoo' ),
								'running' => __( 'Reconstruction en cours', 'woo-search-intelligence-soyoo' ),
								'queued'  => __( 'Reconstruction planifiée', 'woo-search-intelligence-soyoo' ),
								'empty'   => __( 'Non construit', 'woo-search-intelligence-soyoo' ),
							];
							echo esc_html( $labels[ $state['status'] ] ?? $state['status'] );
							?>
						</li>
						<?php if ( '' !== $state['finished_at'] ) : ?>
							<?php /* translators: %s: durée */ ?>
							<li><strong><?php esc_html_e( 'Dernière reconstruction :', 'woo-search-intelligence-soyoo' ); ?></strong> <?php echo esc_html( sprintf( __( 'il y a %s', 'woo-search-intelligence-soyoo' ), self::human_ago( $state['finished_at'] ) ) ); ?></li>
						<?php endif; ?>
					</ul>
					<p class="woo-field-hint"><?php esc_html_e( 'Les produits modifiés sont réindexés automatiquement. Une reconstruction complète n\'est utile qu\'après un import massif ou une modification du filtre d\'indexation.', 'woo-search-intelligence-soyoo' ); ?></p>
					<div class="woo-progress-container" id="woo-index-progress" style="display:none;">
						<div class="woo-progress-bar"><div class="woo-progress-fill" id="woo-index-progress-fill" style="width:0%;"></div></div>
						<div class="woo-progress-text" id="woo-index-progress-text">0%</div>
					</div>
					<button type="button" class="woo-btn woo-btn-primary woo-btn-block" id="woo-btn-rebuild-index">⚡ <?php esc_html_e( 'Reconstruire l\'index', 'woo-search-intelligence-soyoo' ); ?></button>
				</div>

				<div class="woo-card">
					<h3 class="woo-card-title woo-card-title-sm">🧹 <?php esc_html_e( 'Cache', 'woo-search-intelligence-soyoo' ); ?></h3>
					<p class="woo-card-desc">
						<?php
						echo wp_using_ext_object_cache()
							? esc_html__( 'Object cache externe détecté : résultats mis en cache 6 h, invalidés automatiquement à chaque modification du catalogue ou des synonymes.', 'woo-search-intelligence-soyoo' )
							: esc_html__( 'Pas d\'object cache externe : résultats calculés à la volée sur l\'index (quelques millisecondes), sans encombrer wp_options.', 'woo-search-intelligence-soyoo' );
						?>
					</p>
					<button type="button" class="woo-btn woo-btn-secondary woo-btn-block" id="woo-btn-clear-cache">🧹 <?php esc_html_e( 'Vider le cache de recherche', 'woo-search-intelligence-soyoo' ); ?></button>
				</div>

				<div class="woo-card" id="woo-importer-card">
					<h3 class="woo-card-title woo-card-title-sm">📥 <?php esc_html_e( 'Migration Search Analytics', 'woo-search-intelligence-soyoo' ); ?></h3>
					<?php if ( $legacy['has_history'] ) : ?>
						<?php /* translators: %s: nombre */ ?>
						<p class="woo-card-desc"><?php echo esc_html( sprintf( __( '%s recherches archivées détectées.', 'woo-search-intelligence-soyoo' ), number_format_i18n( $legacy['total'] ) ) ); ?></p>
						<?php if ( '' !== $imported_at ) : ?>
							<?php /* translators: 1: durée, 2: nombre */ ?>
							<div class="woo-alert-box woo-alert-success">✓ <?php echo esc_html( sprintf( __( 'Dernier import il y a %1$s (%2$s lignes)', 'woo-search-intelligence-soyoo' ), self::human_ago( $imported_at ), number_format_i18n( $imported_count ) ) ); ?></div>
						<?php endif; ?>
						<div class="woo-progress-container" id="woo-import-progress-container" style="display:none;">
							<div class="woo-progress-bar"><div class="woo-progress-fill" id="woo-import-progress-fill" style="width:0%;"></div></div>
							<div class="woo-progress-text" id="woo-import-progress-text">0%</div>
						</div>
						<label class="woo-checkbox-label woo-small-label">
							<input type="checkbox" id="woo-import-reset-check" value="1" checked>
							<span><?php esc_html_e( 'Remplacer l\'import précédent (évite les doublons)', 'woo-search-intelligence-soyoo' ); ?></span>
						</label>
						<button type="button" class="woo-btn woo-btn-primary woo-btn-block" id="woo-btn-start-import">🚀 <?php echo '' !== $imported_at ? esc_html__( 'Réimporter', 'woo-search-intelligence-soyoo' ) : esc_html__( 'Démarrer la migration', 'woo-search-intelligence-soyoo' ); ?></button>
					<?php elseif ( $legacy['has_terms'] ) : ?>
						<p class="woo-card-desc"><?php esc_html_e( 'Seule la table agrégée des termes existe : sans dates de recherche, aucun import fiable n\'est possible.', 'woo-search-intelligence-soyoo' ); ?></p>
					<?php else : ?>
						<p class="woo-card-desc"><?php esc_html_e( 'Aucune table Search Analytics for WP détectée.', 'woo-search-intelligence-soyoo' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="woo-card">
					<h3 class="woo-card-title woo-card-title-sm">🛡️ <?php esc_html_e( 'Architecture', 'woo-search-intelligence-soyoo' ); ?></h3>
					<ul class="woo-tech-list">
						<li><strong>Index :</strong> <code><?php echo esc_html( $tables['index'] ); ?></code></li>
						<li><strong>Logs :</strong> <code><?php echo esc_html( $tables['logs'] ); ?></code> (UTC)</li>
						<li><strong>Endpoint :</strong> <code>?wc-ajax=woo_live_search&amp;term=…</code></li>
						<li><strong>Tâches de fond :</strong> <?php echo function_exists( 'as_enqueue_async_action' ) ? 'Action Scheduler' : 'WP-Cron'; ?></li>
						<li><strong>Schéma :</strong> v<?php echo esc_html( Woo_Search_Installer::DB_VERSION ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Onglet Synonymes.
	 *
	 * @param array<int, array{id: string, from: string, to: string, type: string}> $synonyms Règles.
	 */
	private function render_tab_synonyms( array $synonyms ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- préremplissage d'un formulaire.
		$prefill_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$prefill_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';
		// phpcs:enable

		$active = [];
		foreach ( $synonyms as $rule ) {
			$active[ Woo_Search_Text::fold( $rule['from'] ) . '|' . Woo_Search_Text::fold( $rule['to'] ) ] = true;
		}
		$packs = Woo_Search_Engine::get_recommended_packs();

		/**
		 * Filtre les exemples affichés comme placeholders dans le formulaire d'ajout de synonymes.
		 *
		 * @param array<string, string> $placeholders Exemples par défaut ['from' => ..., 'to' => ...].
		 */
		$placeholders = (array) apply_filters(
			'woo_search_synonym_placeholders',
			[
				'from' => __( 'ex : basket, t-shirt', 'woo-search-intelligence-soyoo' ),
				'to'   => __( 'ex : sneaker, polo', 'woo-search-intelligence-soyoo' ),
			]
		);

		$placeholder_from = ! empty( $placeholders['from'] ) && is_string( $placeholders['from'] )
			? $placeholders['from']
			: __( 'ex : basket, t-shirt', 'woo-search-intelligence-soyoo' );

		$placeholder_to = ! empty( $placeholders['to'] ) && is_string( $placeholders['to'] )
			? $placeholders['to']
			: __( 'ex : sneaker, polo', 'woo-search-intelligence-soyoo' );
		?>
		<div class="woo-card">
			<h2 class="woo-card-title">➕ <?php esc_html_e( 'Nouvelle règle', 'woo-search-intelligence-soyoo' ); ?></h2>
			<p class="woo-card-desc"><?php esc_html_e( 'Expansion : élargit la recherche (le terme OU son équivalent). Remplacement : la saisie est réécrite avant la recherche. Accents et majuscules sont ignorés.', 'woo-search-intelligence-soyoo' ); ?></p>

			<form id="woo-add-synonym-form" class="woo-inline-form">
				<div class="woo-form-group woo-flex-2">
					<label for="woo_from_term"><?php esc_html_e( 'Quand le client recherche :', 'woo-search-intelligence-soyoo' ); ?></label>
					<input type="text" id="woo_from_term" name="from" value="<?php echo esc_attr( $prefill_from ); ?>" placeholder="<?php echo esc_attr( $placeholder_from ); ?>" required class="regular-text" <?php echo '' !== $prefill_from ? 'autofocus' : ''; ?>>
				</div>
				<div class="woo-form-group woo-flex-2">
					<label for="woo_to_term"><?php esc_html_e( 'Chercher aussi / à la place :', 'woo-search-intelligence-soyoo' ); ?></label>
					<input type="text" id="woo_to_term" name="to" value="<?php echo esc_attr( $prefill_to ); ?>" placeholder="<?php echo esc_attr( $placeholder_to ); ?>" required class="regular-text">
				</div>
				<div class="woo-form-group woo-flex-15">
					<label for="woo_rule_type"><?php esc_html_e( 'Comportement :', 'woo-search-intelligence-soyoo' ); ?></label>
					<select id="woo_rule_type" name="type">
						<option value="expand" selected><?php esc_html_e( 'Expansion', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="replace"><?php esc_html_e( 'Remplacement', 'woo-search-intelligence-soyoo' ); ?></option>
					</select>
				</div>
				<div>
					<button type="submit" class="woo-btn woo-btn-primary" id="woo-btn-add-synonym">➕ <?php esc_html_e( 'Ajouter', 'woo-search-intelligence-soyoo' ); ?></button>
				</div>
			</form>

			<div class="woo-toolbar">
				<div class="woo-toolbar-left">
					<input type="text" id="woo-synonyms-filter-input" placeholder="🔍 <?php esc_attr_e( 'Filtrer…', 'woo-search-intelligence-soyoo' ); ?>" class="woo-filter-input">
					<select id="woo-synonyms-filter-type" class="woo-filter-select">
						<option value=""><?php esc_html_e( 'Tous les types', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="replace"><?php esc_html_e( 'Remplacement', 'woo-search-intelligence-soyoo' ); ?></option>
						<option value="expand"><?php esc_html_e( 'Expansion', 'woo-search-intelligence-soyoo' ); ?></option>
					</select>
				</div>
				<div id="woo-synonyms-counter" class="woo-counter-badge"><strong><?php echo (int) count( $synonyms ); ?></strong> <?php esc_html_e( 'règle(s)', 'woo-search-intelligence-soyoo' ); ?></div>
			</div>

			<table class="woo-table" id="woo-synonyms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Saisie client', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Équivalence catalogue', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Comportement', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-actions"><?php esc_html_e( 'Actions', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $synonyms ) ) : ?>
						<tr class="woo-empty-row"><td colspan="4" class="woo-empty-cell"><?php esc_html_e( 'Aucun synonyme configuré.', 'woo-search-intelligence-soyoo' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $synonyms as $rule ) : ?>
						<tr data-id="<?php echo esc_attr( $rule['id'] ); ?>" data-from="<?php echo esc_attr( $rule['from'] ); ?>" data-to="<?php echo esc_attr( $rule['to'] ); ?>" data-type="<?php echo esc_attr( $rule['type'] ); ?>">
							<td><strong>« <?php echo esc_html( $rule['from'] ); ?> »</strong></td>
							<td>➔ <strong><?php echo esc_html( $rule['to'] ); ?></strong></td>
							<td><span class="woo-tag woo-tag-<?php echo esc_attr( $rule['type'] ); ?>"><?php echo 'replace' === $rule['type'] ? esc_html__( 'Remplacement', 'woo-search-intelligence-soyoo' ) : esc_html__( 'Expansion', 'woo-search-intelligence-soyoo' ); ?></span></td>
							<td class="woo-col-actions">
								<button type="button" class="woo-btn woo-btn-secondary woo-btn-xs woo-edit-synonym-btn">✏️ <?php esc_html_e( 'Modifier', 'woo-search-intelligence-soyoo' ); ?></button>
								<button type="button" class="woo-btn woo-btn-danger woo-btn-xs woo-delete-synonym-btn">🗑️ <?php esc_html_e( 'Supprimer', 'woo-search-intelligence-soyoo' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( ! empty( $packs ) ) : ?>
			<div class="woo-card">
				<div class="woo-card-header-flex">
					<div>
						<h2 class="woo-card-title">✨ <?php esc_html_e( 'Packs de synonymes suggérés', 'woo-search-intelligence-soyoo' ); ?></h2>
						<p class="woo-card-desc"><?php esc_html_e( 'Extensibles par boutique via le filtre woo_search_recommended_packs.', 'woo-search-intelligence-soyoo' ); ?></p>
					</div>
					<button type="button" class="woo-btn woo-btn-primary" id="woo-btn-install-recommended">⚡ <?php esc_html_e( 'Tout installer', 'woo-search-intelligence-soyoo' ); ?></button>
				</div>
				<div class="woo-packs-grid">
					<?php foreach ( $packs as $pack_name => $items ) : ?>
						<div class="woo-pack-box">
							<h4 class="woo-pack-title"><?php echo esc_html( (string) $pack_name ); ?></h4>
							<div class="woo-pack-items">
								<?php foreach ( (array) $items as $rec ) : ?>
									<?php
									$from = (string) ( $rec['from'] ?? '' );
									$to   = (string) ( $rec['to'] ?? '' );
									$type = 'replace' === ( $rec['type'] ?? '' ) ? 'replace' : 'expand';
									?>
									<div class="woo-pack-row">
										<div><strong><?php echo esc_html( $from ); ?></strong> ➔ <?php echo esc_html( $to ); ?> <span class="woo-muted-xs">(<?php echo 'replace' === $type ? esc_html__( 'rempl.', 'woo-search-intelligence-soyoo' ) : esc_html__( 'exp.', 'woo-search-intelligence-soyoo' ); ?>)</span></div>
										<div>
											<?php if ( isset( $active[ Woo_Search_Text::fold( $from ) . '|' . Woo_Search_Text::fold( $to ) ] ) ) : ?>
												<span class="woo-status-active">✓ <?php esc_html_e( 'Actif', 'woo-search-intelligence-soyoo' ); ?></span>
											<?php else : ?>
												<button type="button" class="woo-btn woo-btn-secondary woo-btn-xs woo-add-single-rec-btn" data-from="<?php echo esc_attr( $from ); ?>" data-to="<?php echo esc_attr( $to ); ?>" data-type="<?php echo esc_attr( $type ); ?>">+ <?php esc_html_e( 'Ajouter', 'woo-search-intelligence-soyoo' ); ?></button>
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

	private function render_range_buttons( string $tab, int $days ): void {
		$labels = [ 7 => '7j', 30 => '30j', 90 => '90j', 0 => __( 'Tout', 'woo-search-intelligence-soyoo' ) ];
		echo '<div class="woo-btn-group">';
		foreach ( $labels as $value => $label ) {
			printf(
				'<a href="%s" class="woo-btn %s">%s</a>',
				esc_url( $this->tab_url( $tab, [ 'range' => $value ] ) ),
				$value === $days ? 'woo-btn-primary' : 'woo-btn-secondary',
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	private function render_pagination( int $total_rows, int $paged ): void {
		$pages = (int) ceil( $total_rows / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="woo-pagination">';
		echo wp_kses_post( (string) paginate_links( [
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'total'     => $pages,
			'current'   => $paged,
		] ) );
		echo '</div>';
	}

	/**
	 * Onglet Statistiques.
	 */
	private function render_tab_analytics(): void {
		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];
		$days  = $this->current_range();
		$kpis  = $this->get_search_kpis( $days );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		[ $where, $params ] = $this->base_where( $days );

		// phpcs:disable WordPress.DB.PreparedSQL
		$total_rows = (int) $wpdb->get_var( $this->prepare( "SELECT COUNT(DISTINCT normalized_query) FROM {$table} WHERE {$where}", $params ) );
		$rows       = $wpdb->get_results( $this->prepare(
			"SELECT normalized_query, COUNT(*) AS qty, AVG(results_count) AS avg_results,
				SUM(clicks > 0) AS clicked, SUM(source <> 'import') AS trackable,
				SUM(order_id > 0) AS orders, COALESCE(SUM(order_total), 0) AS revenue, MAX(searched_at) AS last_seen
			FROM {$table} WHERE {$where}
			GROUP BY normalized_query
			ORDER BY qty DESC, last_seen DESC
			LIMIT %d OFFSET %d",
			array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		) );
		// phpcs:enable

		$period = 0 === $days ? __( 'Historique complet', 'woo-search-intelligence-soyoo' ) : sprintf( /* translators: %d: jours */ __( '%d derniers jours', 'woo-search-intelligence-soyoo' ), $days );
		?>
		<div class="woo-kpi-grid">
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Recherches', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val"><?php echo esc_html( number_format_i18n( $kpis['total_searches'] ) ); ?></div>
				<div class="woo-kpi-sub"><?php echo esc_html( $period ); ?></div>
			</div>
			<a href="<?php echo esc_url( $this->tab_url( 'zero_results', [ 'range' => $days ] ) ); ?>" class="woo-kpi-card woo-kpi-card-link" title="<?php esc_attr_e( 'Voir les recherches sans résultat', 'woo-search-intelligence-soyoo' ); ?>">
				<div class="woo-kpi-label">
					<span><?php esc_html_e( 'Sans résultat', 'woo-search-intelligence-soyoo' ); ?></span>
					<span class="woo-kpi-arrow" aria-hidden="true">&rarr;</span>
				</div>
				<div class="woo-kpi-val <?php echo $kpis['zero_rate'] > 10 ? 'is-bad' : ''; ?>"><?php echo esc_html( number_format_i18n( $kpis['zero_rate'], 1 ) ); ?> %</div>
				<?php /* translators: %s: nombre */ ?>
				<div class="woo-kpi-sub"><?php echo esc_html( sprintf( __( '%s recherches', 'woo-search-intelligence-soyoo' ), number_format_i18n( $kpis['zero_count'] ) ) ); ?></div>
			</a>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Résultats approchés', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val"><?php echo esc_html( number_format_i18n( $kpis['approx_rate'], 1 ) ); ?> %</div>
				<div class="woo-kpi-sub"><?php esc_html_e( 'Corrigés ou partiels', 'woo-search-intelligence-soyoo' ); ?></div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Taux de clic', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val is-good"><?php echo esc_html( number_format_i18n( $kpis['ctr'], 1 ) ); ?> %</div>
				<?php /* translators: %s: position */ ?>
				<div class="woo-kpi-sub"><?php echo $kpis['avg_position'] > 0 ? esc_html( sprintf( __( 'Position moyenne cliquée : %s', 'woo-search-intelligence-soyoo' ), number_format_i18n( $kpis['avg_position'], 1 ) ) ) : '—'; ?></div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Commandes après recherche', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val"><?php echo esc_html( number_format_i18n( $kpis['orders'] ) ); ?></div>
				<div class="woo-kpi-sub"><?php echo wp_kses_post( wc_price( $kpis['revenue'] ) ); ?> · <?php echo esc_html( number_format_i18n( $kpis['conversion_rate'], 1 ) ); ?> %</div>
			</div>
			<div class="woo-kpi-card">
				<div class="woo-kpi-label"><?php esc_html_e( 'Top recherche', 'woo-search-intelligence-soyoo' ); ?></div>
				<div class="woo-kpi-val woo-kpi-text"><?php echo '' !== $kpis['top_term'] ? esc_html( $kpis['top_term'] ) : '—'; ?></div>
				<div class="woo-kpi-sub"><?php esc_html_e( 'Requête la plus fréquente', 'woo-search-intelligence-soyoo' ); ?></div>
			</div>
		</div>

		<div class="woo-card">
			<div class="woo-card-header-flex">
				<div>
					<h2 class="woo-card-title">📊 <?php esc_html_e( 'Classement des requêtes', 'woo-search-intelligence-soyoo' ); ?></h2>
					<p class="woo-card-desc"><?php esc_html_e( 'Un CTR faible sur une requête fréquente signale des résultats peu pertinents : enrichissez les fiches ou ajoutez un synonyme.', 'woo-search-intelligence-soyoo' ); ?></p>
				</div>
				<div class="woo-header-actions">
					<?php $this->render_range_buttons( 'analytics', $days ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE, 'woo_export' => 'analytics', 'range' => $days ], admin_url( 'admin.php' ) ), 'woo_search_export' ) ); ?>" class="woo-btn woo-btn-secondary">📥 CSV</a>
				</div>
			</div>

			<table class="woo-table">
				<thead>
					<tr>
						<th class="woo-col-rank">#</th>
						<th><?php esc_html_e( 'Terme', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'Recherches', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'Produits (moy.)', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'CTR', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'Commandes', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Dernière', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-actions"><?php esc_html_e( 'Actions', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8" class="woo-empty-cell"><?php esc_html_e( 'Aucune recherche sur cette période.', 'woo-search-intelligence-soyoo' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( (array) $rows as $i => $row ) : ?>
						<?php
						$term      = (string) $row->normalized_query;
						$avg       = round( (float) $row->avg_results, 1 );
						$trackable = (int) $row->trackable;
						$ctr       = $trackable > 0 ? round( (int) $row->clicked / $trackable * 100 ) : null;
						?>
						<tr data-term="<?php echo esc_attr( $term ); ?>">
							<td class="woo-col-rank"><?php echo (int) ( ( $paged - 1 ) * self::PER_PAGE + $i + 1 ); ?></td>
							<td><strong>« <?php echo esc_html( $term ); ?> »</strong></td>
							<td class="woo-col-num"><strong><?php echo esc_html( number_format_i18n( (int) $row->qty ) ); ?></strong></td>
							<td class="woo-col-num"><span class="<?php echo $avg <= 0.0 ? 'is-bad' : 'is-good'; ?>"><?php echo esc_html( number_format_i18n( $avg, 1 ) ); ?></span></td>
							<td class="woo-col-num"><?php echo null === $ctr ? '—' : esc_html( $ctr . ' %' ); ?></td>
							<td class="woo-col-num"><?php echo (int) $row->orders > 0 ? esc_html( (int) $row->orders ) . ' · ' . wp_kses_post( wc_price( (float) $row->revenue ) ) : '—'; ?></td>
							<td><?php echo esc_html( self::human_ago( (string) $row->last_seen ) ); ?></td>
							<td class="woo-col-actions">
								<a href="<?php echo esc_url( $this->tab_url( 'synonyms', [ 'from' => $term ] ) ); ?>" class="woo-btn woo-btn-secondary woo-btn-xs">➕ <?php esc_html_e( 'Synonyme', 'woo-search-intelligence-soyoo' ); ?></a>
								<button type="button" class="woo-btn woo-btn-ignore woo-btn-xs woo-ignore-term-btn" data-term="<?php echo esc_attr( $term ); ?>">🚫 <?php esc_html_e( 'Ignorer', 'woo-search-intelligence-soyoo' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $total_rows, $paged ); ?>

			<div class="woo-card-footer">
				<?php /* translators: %s: nombre */ ?>
				<span><?php echo esc_html( sprintf( __( '%s requêtes uniques', 'woo-search-intelligence-soyoo' ), number_format_i18n( $total_rows ) ) ); ?></span>
				<button type="button" class="woo-btn woo-btn-danger woo-btn-xs" id="woo-btn-clear-logs">🗑️ <?php esc_html_e( 'Purger les logs de plus de 90 jours', 'woo-search-intelligence-soyoo' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Onglet 0 Résultat : chaque terme est re-testé sur le moteur actuel.
	 */
	private function render_tab_zero_results(): void {
		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];
		$days  = $this->current_range();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		[ $where, $params ] = $this->base_where( $days );

		// phpcs:disable WordPress.DB.PreparedSQL
		$total_rows = (int) $wpdb->get_var( $this->prepare( "SELECT COUNT(DISTINCT normalized_query) FROM {$table} WHERE has_results = 0 AND {$where}", $params ) );
		$rows       = $wpdb->get_results( $this->prepare(
			"SELECT normalized_query, COUNT(*) AS qty, COUNT(DISTINCT session_hash) AS visitors, MAX(searched_at) AS last_seen
			FROM {$table} WHERE has_results = 0 AND {$where}
			GROUP BY normalized_query
			ORDER BY visitors DESC, qty DESC, last_seen DESC
			LIMIT %d OFFSET %d",
			array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		) );
		// phpcs:enable

		$engine  = Woo_Search_Engine::instance();
		$ready   = Woo_Search_Indexer::instance()->is_ready();
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		$ignored = is_array( $ignored ) ? $ignored : [];
		?>
		<div class="woo-card">
			<div class="woo-card-header-flex">
				<div>
					<h2 class="woo-card-title">🚨 <?php esc_html_e( 'Demandes non satisfaites', 'woo-search-intelligence-soyoo' ); ?></h2>
					<p class="woo-card-desc"><?php esc_html_e( 'Chaque terme est re-testé sur le moteur actuel : « Résolu » signifie qu\'il trouve désormais des produits (synonyme ajouté, fiche enrichie ou correction automatique).', 'woo-search-intelligence-soyoo' ); ?></p>
				</div>
				<div class="woo-header-actions">
					<?php $this->render_range_buttons( 'zero_results', $days ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE, 'woo_export' => 'zero', 'range' => $days ], admin_url( 'admin.php' ) ), 'woo_search_export' ) ); ?>" class="woo-btn woo-btn-secondary">📥 CSV</a>
				</div>
			</div>

			<div class="woo-bulk-bar" id="woo-bulk-bar" style="display:none;">
				<span id="woo-bulk-count"></span>
				<button type="button" class="woo-btn woo-btn-danger woo-btn-xs" id="woo-bulk-ignore-btn">🚫 <?php esc_html_e( 'Ignorer la sélection', 'woo-search-intelligence-soyoo' ); ?></button>
			</div>

			<table class="woo-table" id="woo-zero-table">
				<thead>
					<tr>
						<th class="woo-col-check"><input type="checkbox" id="woo-select-all-zero"></th>
						<th><?php esc_html_e( 'Terme', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'Visiteurs', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-num"><?php esc_html_e( 'Recherches', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Dernière', 'woo-search-intelligence-soyoo' ); ?></th>
						<th><?php esc_html_e( 'Statut actuel', 'woo-search-intelligence-soyoo' ); ?></th>
						<th class="woo-col-actions"><?php esc_html_e( 'Actions', 'woo-search-intelligence-soyoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7" class="woo-empty-cell is-good">🎉 <?php esc_html_e( 'Aucune recherche sans résultat sur cette période.', 'woo-search-intelligence-soyoo' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( (array) $rows as $row ) : ?>
						<?php
						$term       = (string) $row->normalized_query;
						$suggestion = '';
						$status     = null;
						if ( $ready ) {
							$status = $engine->search( $term );
							if ( 0 === $status['total'] ) {
								$suggestion = $engine->suggest_correction( $term );
							}
						}
						?>
						<tr class="woo-zero-row" data-term="<?php echo esc_attr( $term ); ?>">
							<td class="woo-col-check"><input type="checkbox" class="woo-zero-cb" value="<?php echo esc_attr( $term ); ?>"></td>
							<td><strong class="is-bad">« <?php echo esc_html( $term ); ?> »</strong></td>
							<td class="woo-col-num"><span class="woo-badge-zero-count"><?php echo (int) $row->visitors; ?></span></td>
							<td class="woo-col-num"><?php echo (int) $row->qty; ?></td>
							<td><?php echo esc_html( self::human_ago( (string) $row->last_seen ) ); ?></td>
							<td>
								<?php if ( null === $status ) : ?>
									<span class="woo-muted">—</span>
								<?php elseif ( $status['total'] > 0 ) : ?>
									<?php /* translators: %d: nombre de produits */ ?>
									<span class="woo-pill woo-pill-ok">✓ <?php echo esc_html( sprintf( _n( 'Résolu : %d produit', 'Résolu : %d produits', $status['total'], 'woo-search-intelligence-soyoo' ), $status['total'] ) ); ?></span>
									<?php if ( 'corrected' === $status['mode'] ) : ?>
										<span class="woo-muted-xs"><?php echo esc_html( '→ ' . $status['corrected'] ); ?></span>
									<?php elseif ( 'any' === $status['mode'] ) : ?>
										<span class="woo-muted-xs"><?php esc_html_e( '(partiel)', 'woo-search-intelligence-soyoo' ); ?></span>
									<?php endif; ?>
								<?php elseif ( '' !== $suggestion ) : ?>
									<span class="woo-suggestion-pill"><?php esc_html_e( 'Proche de :', 'woo-search-intelligence-soyoo' ); ?> <?php echo esc_html( $suggestion ); ?></span>
								<?php else : ?>
									<span class="woo-pill woo-pill-bad"><?php esc_html_e( 'Toujours 0', 'woo-search-intelligence-soyoo' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="woo-col-actions">
								<?php if ( '' !== $suggestion ) : ?>
									<button type="button" class="woo-btn woo-btn-primary woo-btn-xs woo-quick-zero-btn" data-from="<?php echo esc_attr( $term ); ?>" data-to="<?php echo esc_attr( $suggestion ); ?>">⚡ <?php esc_html_e( 'Associer', 'woo-search-intelligence-soyoo' ); ?></button>
								<?php endif; ?>
								<a href="<?php echo esc_url( $this->tab_url( 'synonyms', [ 'from' => $term, 'to' => $suggestion ] ) ); ?>" class="woo-btn woo-btn-secondary woo-btn-xs" title="<?php esc_attr_e( 'Créer un synonyme', 'woo-search-intelligence-soyoo' ); ?>">✏️</a>
								<button type="button" class="woo-btn woo-btn-ignore woo-btn-xs woo-ignore-term-btn" data-term="<?php echo esc_attr( $term ); ?>">🚫</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $total_rows, $paged ); ?>
		</div>

		<div class="woo-card">
			<?php /* translators: %d: nombre */ ?>
			<h3 class="woo-card-title woo-card-title-sm">🚫 <?php echo esc_html( sprintf( __( 'Termes ignorés (%d)', 'woo-search-intelligence-soyoo' ), count( $ignored ) ) ); ?></h3>
			<p class="woo-card-desc"><?php esc_html_e( 'Exclus des statistiques, des exports et des alertes.', 'woo-search-intelligence-soyoo' ); ?></p>
			<div class="woo-ignored-list">
				<?php if ( empty( $ignored ) ) : ?>
					<p class="woo-muted"><?php esc_html_e( 'Aucun terme ignoré.', 'woo-search-intelligence-soyoo' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $ignored as $term ) : ?>
					<span class="woo-ignored-pill">
						<strong>« <?php echo esc_html( (string) $term ); ?> »</strong>
						<button type="button" class="woo-unignore-btn" data-term="<?php echo esc_attr( (string) $term ); ?>" title="<?php esc_attr_e( 'Réactiver', 'woo-search-intelligence-soyoo' ); ?>">×</button>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Export CSV
	 * ------------------------------------------------------------------ */

	/**
	 * Neutralise l'injection de formules (=, +, -, @, tabulation, retour chariot) dans Excel.
	 */
	private static function csv_cell( string $value ): string {
		return ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) ? "'" . $value : $value;
	}

	public function handle_csv_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		if ( ! isset( $_GET['woo_export'], $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'woo-search-intelligence-soyoo' ), 403 );
		}
		check_admin_referer( 'woo_search_export' );

		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];
		$type  = 'zero' === $_GET['woo_export'] ? 'zero' : 'analytics'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days  = $this->current_range();

		[ $where, $params ] = $this->base_where( $days );
		if ( 'zero' === $type ) {
			$where .= ' AND has_results = 0';
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $this->prepare(
			"SELECT normalized_query, COUNT(*) AS qty, COUNT(DISTINCT session_hash) AS visitors, AVG(results_count) AS avg_results,
				SUM(clicks > 0) AS clicked, SUM(source <> 'import') AS trackable, SUM(order_id > 0) AS orders,
				COALESCE(SUM(order_total), 0) AS revenue, MAX(searched_at) AS last_seen
			FROM {$table} WHERE {$where}
			GROUP BY normalized_query
			ORDER BY qty DESC",
			$params
		) );
		// phpcs:enable

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="recherches-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Terme', 'Recherches', 'Visiteurs', 'Produits (moyenne)', 'CTR %', 'Commandes', 'CA attribué', 'Dernière recherche' ], ';', '"', '' );

		foreach ( (array) $rows as $r ) {
			$trackable = (int) $r->trackable;
			fputcsv( $out, [
				self::csv_cell( (string) $r->normalized_query ),
				(int) $r->qty,
				(int) $r->visitors,
				number_format( (float) $r->avg_results, 1, ',', '' ),
				$trackable > 0 ? number_format( (int) $r->clicked / $trackable * 100, 1, ',', '' ) : '',
				(int) $r->orders,
				number_format( (float) $r->revenue, 2, ',', '' ),
				get_date_from_gmt( (string) $r->last_seen, 'Y-m-d H:i' ),
			], ';', '"', '' );
		}

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	public function ajax_save_settings(): void {
		$this->check_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié dans check_ajax().
		$post     = wp_unslash( $_POST );
		$bool     = static fn( string $key ): int => ! empty( $post[ $key ] ) ? 1 : 0;
		$email    = sanitize_email( (string) ( $post['alert_email'] ?? '' ) );
		$retention = absint( $post['log_retention_days'] ?? 730 );
		// phpcs:enable

		$settings = [
			'min_chars'                  => max( 1, min( 5, absint( $post['min_chars'] ?? 2 ) ) ),
			'max_results'                => max( 3, min( 20, absint( $post['max_results'] ?? 6 ) ) ),
			'enable_sku_variations'      => $bool( 'enable_sku_variations' ),
			'search_in_excerpt'          => $bool( 'search_in_excerpt' ),
			'search_in_terms'            => $bool( 'search_in_terms' ),
			'integrate_search_page'      => $bool( 'integrate_search_page' ),
			'enable_spellcheck'          => $bool( 'enable_spellcheck' ),
			'enable_partial_fallback'    => $bool( 'enable_partial_fallback' ),
			'show_images'                => $bool( 'show_images' ),
			'show_prices'                => $bool( 'show_prices' ),
			'show_sku_badge'             => $bool( 'show_sku_badge' ),
			'show_stock'                 => $bool( 'show_stock' ),
			'show_categories'            => $bool( 'show_categories' ),
			'format_titles'              => $bool( 'format_titles' ),
			'enable_frontend_ui'         => $bool( 'enable_frontend_ui' ),
			'frontend_selector'          => sanitize_text_field( (string) ( $post['frontend_selector'] ?? '' ) ),
			'enable_click_tracking'      => $bool( 'enable_click_tracking' ),
			'enable_conversion_tracking' => $bool( 'enable_conversion_tracking' ),
			'exclude_admins'             => $bool( 'exclude_admins' ),
			'enable_alerts'              => $bool( 'enable_alerts' ),
			'alert_email'                => is_email( $email ) ? $email : (string) get_option( 'admin_email' ),
			'alert_mode'                 => 'threshold' === ( $post['alert_mode'] ?? '' ) ? 'threshold' : 'weekly',
			'alert_threshold'            => max( 2, min( 50, absint( $post['alert_threshold'] ?? 5 ) ) ),
			'log_retention_days'         => 0 === $retention ? 0 : max( 30, min( 3650, $retention ) ),
			'delete_data_on_uninstall'   => $bool( 'delete_data_on_uninstall' ),
		];

		update_option( 'woo_search_settings', $settings );
		Woo_Search_Engine::instance()->load_options();
		Woo_Search_Indexer::bump_cache();

		wp_send_json_success( [ 'message' => __( 'Paramètres enregistrés.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * @return array{from: string, to: string, type: string}
	 */
	private function read_rule_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- vérifié dans check_ajax().
		return [
			'from' => trim( sanitize_text_field( wp_unslash( (string) ( $_POST['from'] ?? '' ) ) ) ),
			'to'   => trim( sanitize_text_field( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) ) ),
			'type' => 'replace' === ( $_POST['type'] ?? '' ) ? 'replace' : 'expand',
		];
		// phpcs:enable
	}

	/**
	 * @param array<int, array<string, string>> $rules  Règles existantes.
	 * @param array<string, string>             $rule   Règle candidate.
	 * @param string                            $ignore ID à ignorer (édition).
	 */
	private function is_duplicate_rule( array $rules, array $rule, string $ignore = '' ): bool {
		$key = Woo_Search_Text::fold( $rule['from'] ) . '|' . Woo_Search_Text::fold( $rule['to'] );
		foreach ( $rules as $existing ) {
			if ( $existing['id'] !== $ignore && Woo_Search_Text::fold( $existing['from'] ) . '|' . Woo_Search_Text::fold( $existing['to'] ) === $key ) {
				return true;
			}
		}
		return false;
	}

	public function ajax_add_synonym(): void {
		$this->check_ajax();
		$rule = $this->read_rule_from_request();

		if ( '' === $rule['from'] || '' === $rule['to'] ) {
			wp_send_json_error( [ 'message' => __( 'Les deux termes sont requis.', 'woo-search-intelligence-soyoo' ) ] );
		}
		if ( Woo_Search_Text::fold( $rule['from'] ) === Woo_Search_Text::fold( $rule['to'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Les deux termes sont identiques (accents et majuscules ignorés).', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine = Woo_Search_Engine::instance();
		$rules  = $engine->get_synonyms();
		if ( $this->is_duplicate_rule( $rules, $rule ) ) {
			wp_send_json_error( [ 'message' => __( 'Cette règle existe déjà.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$rule['id'] = wp_generate_uuid4();
		array_unshift( $rules, $rule );
		$engine->save_synonyms( $rules );

		/* translators: 1: terme saisi, 2: équivalence */
		wp_send_json_success( [ 'message' => sprintf( __( '« %1$s » associé à « %2$s ».', 'woo-search-intelligence-soyoo' ), $rule['from'], $rule['to'] ) ] );
	}

	public function ajax_edit_synonym(): void {
		$this->check_ajax();
		$id   = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$rule = $this->read_rule_from_request();

		if ( '' === $id || '' === $rule['from'] || '' === $rule['to'] ) {
			wp_send_json_error( [ 'message' => __( 'Données invalides.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine = Woo_Search_Engine::instance();
		$rules  = $engine->get_synonyms();
		if ( $this->is_duplicate_rule( $rules, $rule, $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Cette règle existe déjà.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$found = false;
		foreach ( $rules as &$existing ) {
			if ( $existing['id'] === $id ) {
				$existing = array_merge( $rule, [ 'id' => $id ] );
				$found    = true;
				break;
			}
		}
		unset( $existing );

		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Règle introuvable (modifiée entre-temps ?). Rechargez la page.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine->save_synonyms( $rules );
		wp_send_json_success( [ 'message' => __( 'Synonyme mis à jour.', 'woo-search-intelligence-soyoo' ) ] );
	}

	public function ajax_delete_synonym(): void {
		$this->check_ajax();
		$id     = sanitize_key( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$engine = Woo_Search_Engine::instance();
		$rules  = $engine->get_synonyms();
		$kept   = array_values( array_filter( $rules, static fn( array $r ): bool => $r['id'] !== $id ) );

		if ( count( $kept ) === count( $rules ) ) {
			wp_send_json_error( [ 'message' => __( 'Règle introuvable (déjà supprimée ?).', 'woo-search-intelligence-soyoo' ) ] );
		}

		$engine->save_synonyms( $kept );
		wp_send_json_success( [ 'message' => __( 'Synonyme supprimé.', 'woo-search-intelligence-soyoo' ) ] );
	}

	public function ajax_install_recommended_synonyms(): void {
		$this->check_ajax();
		$engine = Woo_Search_Engine::instance();
		$rules  = $engine->get_synonyms();
		$added  = 0;

		foreach ( Woo_Search_Engine::get_recommended_packs() as $items ) {
			foreach ( (array) $items as $rec ) {
				$rule = [
					'from' => (string) ( $rec['from'] ?? '' ),
					'to'   => (string) ( $rec['to'] ?? '' ),
					'type' => 'replace' === ( $rec['type'] ?? '' ) ? 'replace' : 'expand',
				];
				if ( '' === $rule['from'] || '' === $rule['to'] || $this->is_duplicate_rule( $rules, $rule ) ) {
					continue;
				}
				$rule['id'] = wp_generate_uuid4();
				$rules[]    = $rule;
				$added++;
			}
		}

		if ( $added > 0 ) {
			$engine->save_synonyms( $rules );
		}

		/* translators: %d: nombre */
		wp_send_json_success( [ 'message' => sprintf( __( '%d synonyme(s) installé(s).', 'woo-search-intelligence-soyoo' ), $added ) ] );
	}

	public function ajax_clear_cache(): void {
		$this->check_ajax();
		Woo_Search_Indexer::bump_cache();
		delete_transient( 'wsi_cats' );
		wp_send_json_success( [ 'message' => __( 'Cache de recherche vidé.', 'woo-search-intelligence-soyoo' ) ] );
	}

	public function ajax_clear_logs(): void {
		$this->check_ajax();
		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE searched_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::flush_kpis();
		wp_send_json_success( [ 'message' => __( 'Logs de plus de 90 jours supprimés.', 'woo-search-intelligence-soyoo' ) ] );
	}

	public function ajax_test_email(): void {
		$this->check_ajax();
		$email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Adresse e-mail invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: nom du site */
		$sent = wp_mail( $email, sprintf( __( '[Test] Alertes de recherche — %s', 'woo-search-intelligence-soyoo' ), $site ), "Bonjour,\n\nLes alertes de recherche de {$site} sont correctement configurées.\n" );

		if ( $sent ) {
			wp_send_json_success( [ 'message' => __( 'E-mail de test envoyé.', 'woo-search-intelligence-soyoo' ) ] );
		}
		wp_send_json_error( [ 'message' => __( 'Échec de l\'envoi : vérifiez la configuration SMTP.', 'woo-search-intelligence-soyoo' ) ] );
	}

	/**
	 * @param array<int, string> $terms Termes à ajouter.
	 */
	private function add_ignored( array $terms ): int {
		$engine  = Woo_Search_Engine::instance();
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		$ignored = is_array( $ignored ) ? array_values( array_map( 'strval', $ignored ) ) : [];
		$lower   = array_map( static fn( string $t ): string => mb_strtolower( $t, 'UTF-8' ), $ignored );
		$added   = 0;

		foreach ( $terms as $term ) {
			$norm = $engine->normalize_query( sanitize_text_field( $term ) );
			$key  = mb_strtolower( $norm, 'UTF-8' );
			if ( '' !== $norm && ! in_array( $key, $lower, true ) ) {
				$ignored[] = $norm;
				$lower[]   = $key;
				$added++;
			}
		}

		if ( $added > 0 ) {
			update_option( 'woo_search_ignored_terms', $ignored, false );
			self::flush_kpis();
		}
		return $added;
	}

	public function ajax_ignore_term(): void {
		$this->check_ajax();
		$term = trim( wp_unslash( (string) ( $_POST['term'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $term ) {
			wp_send_json_error( [ 'message' => __( 'Terme invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}
		$this->add_ignored( [ $term ] );
		/* translators: %s: terme */
		wp_send_json_success( [ 'message' => sprintf( __( '« %s » est désormais ignoré.', 'woo-search-intelligence-soyoo' ), sanitize_text_field( $term ) ) ] );
	}

	public function ajax_bulk_ignore_terms(): void {
		$this->check_ajax();
		$terms = isset( $_POST['terms'] ) && is_array( $_POST['terms'] ) ? array_map( 'strval', wp_unslash( $_POST['terms'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $terms ) ) {
			wp_send_json_error( [ 'message' => __( 'Aucun terme sélectionné.', 'woo-search-intelligence-soyoo' ) ] );
		}
		$added = $this->add_ignored( $terms );
		/* translators: %d: nombre */
		wp_send_json_success( [ 'message' => sprintf( __( '%d terme(s) ignoré(s).', 'woo-search-intelligence-soyoo' ), $added ) ] );
	}

	public function ajax_unignore_term(): void {
		$this->check_ajax();
		$term = mb_strtolower( trim( sanitize_text_field( wp_unslash( (string) ( $_POST['term'] ?? '' ) ) ) ), 'UTF-8' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $term ) {
			wp_send_json_error( [ 'message' => __( 'Terme invalide.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$ignored = get_option( 'woo_search_ignored_terms', [] );
		$ignored = is_array( $ignored ) ? $ignored : [];
		update_option( 'woo_search_ignored_terms', array_values( array_filter( $ignored, static fn( $t ): bool => mb_strtolower( (string) $t, 'UTF-8' ) !== $term ) ), false );
		self::flush_kpis();

		wp_send_json_success( [ 'message' => __( 'Terme réactivé.', 'woo-search-intelligence-soyoo' ) ] );
	}

	public function ajax_index_batch(): void {
		$this->check_ajax();
		$cursor = absint( $_POST['cursor'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( Woo_Search_Indexer::instance()->rebuild_batch( $cursor ) );
	}
}
