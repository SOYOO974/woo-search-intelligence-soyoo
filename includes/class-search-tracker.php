<?php
/**
 * Tracker universel de recherches, clics et conversions
 *
 * - Journalise le live search AJAX et la page de résultats standard (dates UTC).
 * - Consolide la frappe progressive du live search (« ton » → « tondeuse » = 1 ligne).
 * - Écrit en base APRÈS l'envoi de la réponse (fastcgi_finish_request) : impact TTFB nul.
 * - Bouclier anti-robots, exclusion des gestionnaires de la boutique.
 * - Suivi des clics (CTR, position) et attribution des commandes à la recherche.
 * - Alertes « 0 résultat » en tâche de fond, rapport hebdomadaire, rétention automatique.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Tracker {

	public const MERGE_WINDOW   = 45;
	public const HOOK_THRESHOLD = 'woo_search_threshold_check';
	public const COOKIE         = 'wsi_ref';

	/**
	 * Tâches à exécuter après l'envoi de la réponse.
	 *
	 * @var array<int, callable>
	 */
	private static array $deferred = [];

	private static bool $shutdown_registered = false;

	private static string $page_uid = '';

	/**
	 * Produits affichés sur la page de résultats : [URL normalisée => [ID, position]].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private static array $page_items = [];

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'intercept_standard_search' ], 5 );

		add_action( Woo_Search_Installer::CRON_WEEKLY_DIGEST, [ __CLASS__, 'send_weekly_digest_email' ] );
		add_action( Woo_Search_Installer::CRON_DAILY, [ __CLASS__, 'daily_maintenance' ] );
		add_action( self::HOOK_THRESHOLD, [ __CLASS__, 'maybe_send_threshold_alert' ] );

		add_action( 'wc_ajax_wsi_click', [ __CLASS__, 'ajax_click' ] );
		add_action( 'wp_ajax_wsi_click', [ __CLASS__, 'ajax_click' ] );
		add_action( 'wp_ajax_nopriv_wsi_click', [ __CLASS__, 'ajax_click' ] );

		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'attribute_order' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'attribute_order' ] );

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_front' ] );
	}

	/* ---------------------------------------------------------------------
	 * Filtrage du trafic
	 * ------------------------------------------------------------------ */

	/**
	 * Détecte un robot, un outil d'audit ou un aperçu de lien.
	 */
	public static function is_bot( ?string $user_agent = null ): bool {
		if ( null === $user_agent ) {
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$user_agent = trim( $user_agent );

		$is_bot = '' === $user_agent || (bool) preg_match(
			'/(bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|meta-externalagent|bytespider|semrush|ahrefs|petalbot|yandex|baidu|sogou|exabot|mj12|blexbot|dataforseo|screaming frog|uptimerobot|pingdom|statuscake|wp_rocket|wp-rocket|curl|wget|python|urllib|guzzle|okhttp|java\/|go-http|headless|lighthouse|pagespeed|gtmetrix|phantomjs|selenium|puppeteer|playwright|gptbot|claudebot|ccbot|perplexity|whatsapp|telegram|discord|slack|skype|linkedin|pinterest|ia_archiver)/i',
			$user_agent
		);

		/**
		 * Filtre : woo_search_is_bot
		 *
		 * @param bool   $is_bot     Statut de détection.
		 * @param string $user_agent User-Agent.
		 */
		return (bool) apply_filters( 'woo_search_is_bot', $is_bot, $user_agent );
	}

	/**
	 * Le trafic courant doit-il être mesuré ?
	 */
	public static function should_track(): bool {
		if ( wp_doing_cron() || self::is_bot() ) {
			return false;
		}
		if ( Woo_Search_Engine::instance()->get_option( 'exclude_admins', 1 ) && current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Identifiant aléatoire d'une recherche (lien recherche → clic → commande).
	 */
	public static function new_uid(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Empreinte de session pseudonyme : HMAC salé (clés WordPress) avec rotation quotidienne.
	 * Non réversible, ne permet pas de suivre un visiteur d'un jour à l'autre.
	 */
	private static function session_hash(): string {
		$ip = class_exists( 'WC_Geolocation' ) ? (string) WC_Geolocation::get_ip_address() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return wp_hash( $ip . '|' . $ua . '|' . gmdate( 'Y-m-d' ), 'nonce' );
	}

	/* ---------------------------------------------------------------------
	 * Page de résultats standard
	 * ------------------------------------------------------------------ */

	/**
	 * Journalise la recherche standard (priorité 5 : avant la redirection WooCommerce
	 * vers la fiche produit quand il n'y a qu'un seul résultat).
	 */
	public static function intercept_standard_search(): void {
		if ( is_admin() || ! is_search() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Pagination, tri et filtres = affinage d'une recherche déjà comptée.
		if ( is_paged() || self::is_ignored_refinement_query() ) {
			return;
		}

		if ( ! self::should_track() ) {
			return;
		}

		$query = trim( (string) get_search_query( false ) );
		if ( '' === $query ) {
			return;
		}

		global $wp_query;
		$results_count = (int) ( $wp_query->found_posts ?? 0 );
		$engine_result = Woo_Search_Engine::instance()->get_page_result();
		$uid           = self::new_uid();

		self::$page_uid = $uid;
		$position       = 0;
		foreach ( (array) ( $wp_query->posts ?? [] ) as $post ) {
			if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
				$position++;
				self::$page_items[ self::normalize_url( (string) get_permalink( $post ) ) ] = [ (int) $post->ID, $position ];
			}
		}

		self::log_search( $query, $results_count, [
			'source'    => 'page',
			'mode'      => is_array( $engine_result ) ? (string) $engine_result['mode'] : 'native',
			'corrected' => is_array( $engine_result ) ? (string) $engine_result['corrected'] : '',
			'uid'       => $uid,
		] );
	}

	private static function normalize_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		return ( $parts['host'] ?? '' ) . $path;
	}

	/**
	 * Détermine si la requête courante (ou le jeu de paramètres fourni) contient des clés de facettes,
	 * de tri ou d'affinage qui ne doivent pas être comptabilisées comme une nouvelle recherche.
	 *
	 * @param array<string, mixed>|null $query_params Paramètres de requête (défaut : $_GET).
	 * @return bool
	 */
	public static function is_ignored_refinement_query( ?array $query_params = null ): bool {
		$params = null !== $query_params ? $query_params : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $params ) ) {
			return false;
		}

		/**
		 * Clés de requête GET (ou préfixes terminés par *) indiquant un affinage ou un filtre (recherche déjà comptée).
		 *
		 * @param array<int, string> $ignored_keys Paramètres ou préfixes ignorés par défaut.
		 */
		$ignored_keys   = (array) apply_filters( 'woo_search_ignored_query_params', [ 'orderby', 'min_price', 'max_price', 'rating_filter' ] );
		$exact_ignored  = [];
		$prefix_ignored = [ 'filter_', 'query_type_' ];

		foreach ( $ignored_keys as $ignored ) {
			$ignored = (string) $ignored;
			if ( str_ends_with( $ignored, '*' ) ) {
				$prefix_ignored[] = substr( $ignored, 0, -1 );
			} else {
				$exact_ignored[] = $ignored;
			}
		}

		foreach ( array_keys( $params ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, $exact_ignored, true ) ) {
				return true;
			}
			foreach ( $prefix_ignored as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Journalisation
	 * ------------------------------------------------------------------ */

	/**
	 * Enregistre une recherche (écriture différée après l'envoi de la réponse).
	 *
	 * @param string               $query         Requête brute.
	 * @param int                  $results_count Nombre total de produits trouvés.
	 * @param array<string, mixed> $context       `source` (ajax|page), `mode`, `corrected`, `uid`.
	 */
	public static function log_search( string $query, int $results_count, array $context = [] ): void {
		if ( ! self::should_track() ) {
			return;
		}

		$raw     = mb_substr( trim( sanitize_text_field( $query ) ), 0, 190, 'UTF-8' );
		$display = Woo_Search_Engine::instance()->normalize_query( $raw );
		if ( mb_strlen( $display, 'UTF-8' ) < 2 ) {
			return;
		}

		$row = [
			'search_uid'       => preg_match( '/^[a-f0-9]{16}$/', (string) ( $context['uid'] ?? '' ) ) ? (string) $context['uid'] : '',
			'query'            => $raw,
			'normalized_query' => $display,
			'results_count'    => max( 0, min( 4294967295, $results_count ) ),
			'has_results'      => $results_count > 0 ? 1 : 0,
			'source'           => 'page' === ( $context['source'] ?? '' ) ? 'page' : 'ajax',
			'match_mode'       => substr( sanitize_key( (string) ( $context['mode'] ?? '' ) ), 0, 10 ),
			'corrected_query'  => mb_substr( (string) ( $context['corrected'] ?? '' ), 0, 190, 'UTF-8' ),
			'session_hash'     => self::session_hash(),
			'searched_at'      => gmdate( 'Y-m-d H:i:s' ),
		];

		self::defer( static function () use ( $row ): void {
			self::write_log( $row );
		} );
	}

	/**
	 * Écriture avec consolidation :
	 * - live search : une frappe qui prolonge (ou raccourcit) la précédente dans la fenêtre remplace la ligne ;
	 * - page : fusionne avec la recherche live identique qui l'a précédée (touche Entrée).
	 *
	 * @param array<string, mixed> $row Ligne.
	 */
	private static function write_log( array $row ): void {
		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];

		$recent = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, normalized_query, source, clicks FROM {$table} WHERE session_hash = %s AND searched_at >= %s ORDER BY id DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row['session_hash'],
			gmdate( 'Y-m-d H:i:s', time() - self::MERGE_WINDOW )
		), ARRAY_A );

		$target_id = 0;
		foreach ( (array) $recent as $previous ) {
			if ( (int) $previous['clicks'] > 0 ) {
				continue; // Recherche aboutie : on ne la réécrit pas.
			}
			$prev_query = (string) $previous['normalized_query'];
			$same       = $prev_query === $row['normalized_query'];
			$typing     = 'ajax' === $row['source'] && 'ajax' === $previous['source']
				&& ( str_starts_with( $row['normalized_query'], $prev_query ) || str_starts_with( $prev_query, $row['normalized_query'] ) );

			if ( $same || $typing ) {
				$target_id = (int) $previous['id'];
				break;
			}
		}

		$formats = [ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ];

		if ( $target_id > 0 ) {
			$update = $row;
			unset( $update['session_hash'] );
			$wpdb->update( $table, $update, [ 'id' => $target_id ], [ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
		} else {
			$wpdb->insert( $table, $row, $formats );
		}

		$engine = Woo_Search_Engine::instance();
		if ( 0 === (int) $row['has_results'] && $engine->get_option( 'enable_alerts' ) && 'threshold' === $engine->get_option( 'alert_mode' ) ) {
			Woo_Search_Installer::async( self::HOOK_THRESHOLD, [ (string) $row['normalized_query'] ], true );
		}
	}

	/**
	 * Diffère une tâche après l'envoi complet de la réponse HTTP.
	 */
	public static function defer( callable $task ): void {
		self::$deferred[] = $task;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			// Priorité 1000 : après wp_ob_end_flush_all (priorité 1) et l'écriture des caches de page.
			add_action( 'shutdown', [ __CLASS__, 'run_deferred' ], 1000 );
		}
	}

	public static function run_deferred(): void {
		if ( empty( self::$deferred ) ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		foreach ( self::$deferred as $task ) {
			try {
				$task();
			} catch ( \Throwable $e ) {
				error_log( '[Woo Search Intelligence] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		self::$deferred = [];
	}

	/* ---------------------------------------------------------------------
	 * Clics & conversions
	 * ------------------------------------------------------------------ */

	/**
	 * Enregistre un clic sur un résultat (navigator.sendBeacon depuis le front).
	 */
	public static function ajax_click(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- balise publique, données non sensibles et validées.
		$uid = isset( $_POST['uid'] ) ? sanitize_key( wp_unslash( (string) $_POST['uid'] ) ) : '';
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$pos = isset( $_POST['pos'] ) ? min( 65535, absint( $_POST['pos'] ) ) : 0;
		// phpcs:enable

		if ( ! preg_match( '/^[a-f0-9]{16}$/', $uid ) || $pid <= 0 ) {
			wp_send_json_error( null, 400 );
		}

		if ( Woo_Search_Engine::instance()->get_option( 'enable_click_tracking', 1 ) && ! self::is_bot() ) {
			global $wpdb;
			$table = Woo_Search_Installer::tables()['logs'];
			// MySQL/MariaDB évaluent les affectations de gauche à droite : `clicks` est incrémenté en dernier.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				SET clicked_product_id = IF(clicks = 0, %d, clicked_product_id),
					click_position = IF(clicks = 0, %d, click_position),
					clicks = LEAST(clicks + 1, 65535)
				WHERE search_uid = %s AND searched_at >= %s
				LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pid,
				$pos,
				$uid,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			) );
		}

		wp_send_json_success();
	}

	/**
	 * Attribue la commande à la dernière recherche cliquée (cookie first-party `wsi_ref`, 24 h).
	 *
	 * @param mixed $order Commande.
	 */
	public static function attribute_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! Woo_Search_Engine::instance()->get_option( 'enable_conversion_tracking', 1 ) ) {
			return;
		}

		$uid = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $uid ) || '' !== (string) $order->get_meta( '_wsi_search_uid' ) ) {
			return;
		}

		$order->update_meta_data( '_wsi_search_uid', $uid );
		$order->save_meta_data();

		global $wpdb;
		$table = Woo_Search_Installer::tables()['logs'];
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET order_id = %d, order_total = %f WHERE search_uid = %s AND order_id = 0 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id(),
			(float) $order->get_total(),
			$uid
		) );

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, '/' );
		}
	}

	/**
	 * Script front : suivi des clics (live search via fragment #wsi, page de résultats via la carte des produits)
	 * et interface de live search optionnelle.
	 */
	public static function enqueue_front(): void {
		$engine   = Woo_Search_Engine::instance();
		$tracking = (bool) $engine->get_option( 'enable_click_tracking', 1 );
		$ui       = (bool) $engine->get_option( 'enable_frontend_ui', 0 );

		if ( ( ! $tracking && ! $ui ) || ! class_exists( 'WC_AJAX' ) ) {
			return;
		}

		wp_register_script(
			'woo-search-front',
			WOO_SEARCH_INTEL_URL . 'assets/js/frontend.js',
			[],
			WOO_SEARCH_INTEL_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_localize_script( 'woo-search-front', 'wsiFront', [
			'clickUrl'   => WC_AJAX::get_endpoint( 'wsi_click' ),
			'searchUrl'  => WC_AJAX::get_endpoint( 'woo_live_search' ),
			'tracking'   => $tracking,
			'conversion' => $tracking && (bool) $engine->get_option( 'enable_conversion_tracking', 1 ),
			'cookie'     => self::COOKIE,
			'page'       => '' !== self::$page_uid ? [
				'uid'   => self::$page_uid,
				'items' => self::$page_items,
			] : null,
			'ui'         => $ui,
			'selector'   => (string) $engine->get_option( 'frontend_selector', '' ),
			'minChars'   => max( 1, absint( $engine->get_option( 'min_chars', 2 ) ) ),
			'i18n'       => [
				'products'   => __( 'Produits', 'woo-search-intelligence-soyoo' ),
				'categories' => __( 'Catégories', 'woo-search-intelligence-soyoo' ),
				/* translators: %d: nombre de produits */
				'seeAll'     => __( 'Voir les %d résultats', 'woo-search-intelligence-soyoo' ),
				'noResults'  => __( 'Aucun produit trouvé.', 'woo-search-intelligence-soyoo' ),
				'outOfStock' => __( 'Rupture', 'woo-search-intelligence-soyoo' ),
			],
		] );
		wp_enqueue_script( 'woo-search-front' );

		if ( $ui ) {
			wp_enqueue_style( 'woo-search-front', WOO_SEARCH_INTEL_URL . 'assets/css/frontend.css', [], WOO_SEARCH_INTEL_VERSION );
		}
	}

	/* ---------------------------------------------------------------------
	 * Alertes & maintenance
	 * ------------------------------------------------------------------ */

	/**
	 * Termes ignorés (blacklist), en minuscules.
	 *
	 * @return array<int, string>
	 */
	public static function get_ignored_terms(): array {
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		return is_array( $ignored ) ? array_values( array_map( static fn( $t ): string => mb_strtolower( (string) $t, 'UTF-8' ), $ignored ) ) : [];
	}

	private static function alert_recipient(): string {
		$email = sanitize_email( (string) Woo_Search_Engine::instance()->get_option( 'alert_email', '' ) );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Alerte instantanée (exécutée en tâche de fond) si un terme sans résultat franchit le seuil.
	 *
	 * @param mixed $norm_query Terme normalisé.
	 */
	public static function maybe_send_threshold_alert( $norm_query ): void {
		$norm_query = (string) $norm_query;
		if ( '' === $norm_query || in_array( mb_strtolower( $norm_query, 'UTF-8' ), self::get_ignored_terms(), true ) ) {
			return;
		}

		$alert_key = 'wsi_alert_' . md5( $norm_query );
		if ( get_transient( $alert_key ) ) {
			return;
		}

		global $wpdb;
		$engine    = Woo_Search_Engine::instance();
		$threshold = max( 2, absint( $engine->get_option( 'alert_threshold', 5 ) ) );
		$table     = Woo_Search_Installer::tables()['logs'];

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT session_hash) FROM {$table} WHERE normalized_query = %s AND has_results = 0 AND searched_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$norm_query,
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
		) );

		if ( $count < $threshold ) {
			return;
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$admin_url = admin_url( 'admin.php?page=woo-search-intelligence&tab=zero_results' );
		/* translators: 1: terme, 2: nombre */
		$subject = sprintf( __( '[Recherche] « %1$s » cherché %2$d fois sans résultat', 'woo-search-intelligence-soyoo' ), $norm_query, $count );

		$message  = "Bonjour,\n\n";
		$message .= sprintf( "Le terme « %s » a été recherché par %d visiteurs différents sur %s ces 7 derniers jours, sans aucun produit trouvé.\n\n", $norm_query, $count, $site_name );
		$message .= "Pistes :\n";
		$message .= "1. Créer un synonyme vers un produit existant.\n";
		$message .= "2. Compléter le titre, les attributs ou la description courte du produit concerné.\n";
		$message .= "3. Référencer le produit s'il est absent du catalogue.\n\n";
		$message .= 'Détail : ' . $admin_url . "\n";

		wp_mail( self::alert_recipient(), $subject, $message );
		set_transient( $alert_key, 1, 3 * DAY_IN_SECONDS );
	}

	/**
	 * Rapport hebdomadaire : synthèse + top 10 des recherches sans résultat.
	 */
	public static function send_weekly_digest_email(): void {
		$engine = Woo_Search_Engine::instance();
		if ( ! $engine->get_option( 'enable_alerts' ) || 'weekly' !== $engine->get_option( 'alert_mode' ) ) {
			return;
		}

		global $wpdb;
		$table   = Woo_Search_Installer::tables()['logs'];
		$since   = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$ignored = self::get_ignored_terms();

		$where  = 'searched_at >= %s';
		$params = [ $since ];
		if ( ! empty( $ignored ) ) {
			$where .= ' AND normalized_query NOT IN (' . implode( ', ', array_fill( 0, count( $ignored ), '%s' ) ) . ')';
			$params = array_merge( $params, $ignored );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$summary = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(has_results = 0) AS zero, SUM(clicks > 0) AS clicked, SUM(order_id > 0) AS orders, SUM(order_total) AS revenue FROM {$table} WHERE {$where}",
			$params
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT normalized_query, COUNT(*) AS qty FROM {$table} WHERE has_results = 0 AND {$where} GROUP BY normalized_query ORDER BY qty DESC LIMIT 10",
			$params
		) );
		// phpcs:enable

		if ( ! $summary || 0 === (int) $summary->total ) {
			return;
		}

		$total     = (int) $summary->total;
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: nom du site */
		$subject = sprintf( __( '[Recherche %s] Rapport hebdomadaire', 'woo-search-intelligence-soyoo' ), $site_name );

		$message  = "Bonjour,\n\nSynthèse des 7 derniers jours sur {$site_name} :\n\n";
		$message .= sprintf( "• Recherches : %d\n", $total );
		$message .= sprintf( "• Sans résultat : %d (%s %%)\n", (int) $summary->zero, number_format_i18n( (int) $summary->zero / $total * 100, 1 ) );
		$message .= sprintf( "• Avec clic sur un produit : %s %%\n", number_format_i18n( (int) $summary->clicked / $total * 100, 1 ) );
		$message .= sprintf( "• Commandes après recherche : %d (%s)\n\n", (int) $summary->orders, html_entity_decode( wp_strip_all_tags( wc_price( (float) $summary->revenue ) ), ENT_QUOTES, 'UTF-8' ) );

		if ( ! empty( $rows ) ) {
			$message .= "Top des recherches sans résultat :\n";
			foreach ( $rows as $r ) {
				$message .= sprintf( "• « %s » : %d\n", $r->normalized_query, (int) $r->qty );
			}
			$message .= "\n";
		}

		$message .= 'Tableau de bord : ' . admin_url( 'admin.php?page=woo-search-intelligence&tab=zero_results' ) . "\n";

		wp_mail( self::alert_recipient(), $subject, $message );
	}

	/**
	 * Maintenance quotidienne : rétention des logs, synchro ventes/stock, vocabulaire, auto-réparation des crons.
	 */
	public static function daily_maintenance(): void {
		global $wpdb;

		$days = absint( Woo_Search_Engine::instance()->get_option( 'log_retention_days', 730 ) );
		if ( $days > 0 ) {
			$table  = Woo_Search_Installer::tables()['logs'];
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			for ( $i = 0; $i < 50; $i++ ) {
				$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE searched_at < %s LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $deleted < 5000 ) {
					break;
				}
			}
		}

		$indexer = Woo_Search_Indexer::instance();
		if ( $indexer->is_ready() ) {
			$indexer->sync_lookup_metrics();
			if ( get_option( 'woo_search_vocab_dirty' ) ) {
				$indexer->build_vocabulary();
			}
		}

		Woo_Search_Installer::schedule_crons();
	}
}
