<?php
/**
 * Tracker Universel de Recherches & Bouclier Anti-Robots
 *
 * Intercepte 100% des recherches (Live AJAX + recherche standard par formulaire/URL),
 * filtre les crawlers et robots, prévient le flood et pilote les alertes décisionnelles.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Search_Tracker {

	/**
	 * Initialisation des hooks d'écoute
	 */
	public static function init(): void {
		// Interception des requêtes standards de recherche (formulaire ou URL).
		add_action( 'template_redirect', [ __CLASS__, 'intercept_standard_search' ], 20 );

		// Hook du cron hebdomadaire de digest.
		add_action( 'woo_search_weekly_digest_cron', [ __CLASS__, 'send_weekly_digest_email' ] );
	}

	/**
	 * Détecte si la requête provient d'un crawler ou robot (Anti-Bot Shield)
	 *
	 * @param string|null $user_agent Chaîne User-Agent à analyser.
	 * @return bool True si robot détecté, False pour un visiteur humain réel.
	 */
	public static function is_bot( ?string $user_agent = null ): bool {
		if ( null === $user_agent ) {
			$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		}

		$user_agent = trim( (string) $user_agent );
		if ( empty( $user_agent ) ) {
			// Requête sans User-Agent : scraper ou script suspect.
			return true;
		}

		$bot_regex = '/(bot|crawler|spider|slurp|facebookexternalhit|meta-externalagent|bytespider|semrush|ahrefs|petalbot|yandex|googlebot|bingbot|duckduckbot|sogou|dotbot|rogerbot|exabot|screaming frog|uptimerobot|wp_rocket|curl|wget|python|urllib|guzzle|headless|lighthouse|pagespeed|phantomjs)/i';

		$is_bot = (bool) preg_match( $bot_regex, $user_agent );

		/**
		 * Filtre : woo_search_is_bot
		 * Permet d'ajuster ou d'enrichir la détection anti-robots.
		 *
		 * @param bool   $is_bot Statut de détection.
		 * @param string $user_agent User-Agent du client.
		 */
		return (bool) apply_filters( 'woo_search_is_bot', $is_bot, $user_agent );
	}

	/**
	 * Intercepte les soumissions du formulaire de recherche standard WordPress / WooCommerce
	 */
	public static function intercept_standard_search(): void {
		if ( is_admin() || ! is_search() ) {
			return;
		}

		// Ne pas doubler si la requête est exécutée en AJAX ou REST.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Filtrage immédiat des robots.
		if ( self::is_bot() ) {
			return;
		}

		$query = get_search_query();
		if ( empty( $query ) && isset( $_GET['s'] ) ) {
			$query = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}

		$query = trim( (string) $query );
		if ( empty( $query ) ) {
			return;
		}

		global $wp_query;
		$results_count = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;

		// Enregistrement différé au shutdown pour ne pas ralentir le TTFB de la page.
		add_action( 'shutdown', function () use ( $query, $results_count ): void {
			self::log_search( $query, $results_count );
		} );
	}

	/**
	 * Enregistre la recherche dans la table de logs dédiée
	 *
	 * @param string $query Terme brut recherché.
	 * @param int    $results_count Nombre de produits/résultats retournés.
	 */
	public static function log_search( string $query, int $results_count ): void {
		if ( self::is_bot() ) {
			return;
		}

		$clean_query = trim( sanitize_text_field( $query ) );
		if ( mb_strlen( $clean_query, 'UTF-8' ) < 2 ) {
			return;
		}

		$engine     = Woo_Search_Engine::instance();
		$norm_query = $engine->normalize_query( $clean_query );

		global $wpdb;
		$table_logs = $wpdb->prefix . 'woo_search_logs';

		// Calcul d'une empreinte de session anonymisée.
		$user_agent   = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$ip_address   = $_SERVER['REMOTE_ADDR'] ?? '';
		$session_hash = md5( wp_get_session_token() . '_' . $ip_address . '_' . $user_agent );

		// Mécanisme Anti-flood : consolidation de la frappe si envoyée dans les 15 secondes.
		$recent_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table_logs}
			WHERE session_hash = %s 
			AND normalized_query = %s
			AND searched_at >= %s
			LIMIT 1",
			$session_hash,
			$norm_query,
			date( 'Y-m-d H:i:s', time() - 15 )
		) );

		if ( $recent_id ) {
			$wpdb->update(
				$table_logs,
				[
					'results_count' => (int) $results_count,
					'has_results'   => $results_count > 0 ? 1 : 0,
					'searched_at'   => current_time( 'mysql' ),
				],
				[ 'id' => (int) $recent_id ],
				[ '%d', '%d', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$wpdb->insert(
			$table_logs,
			[
				'query'            => mb_substr( $clean_query, 0, 190, 'UTF-8' ),
				'normalized_query' => mb_substr( $norm_query, 0, 190, 'UTF-8' ),
				'results_count'    => (int) $results_count,
				'has_results'      => $results_count > 0 ? 1 : 0,
				'session_hash'     => $session_hash,
				'searched_at'      => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s' ]
		);

		// Déclenchement d'alerte immédiate sur seuil si 0 résultat.
		if ( 0 === $results_count && (bool) $engine->get_option( 'enable_alerts' ) && 'threshold' === $engine->get_option( 'alert_mode' ) ) {
			self::maybe_send_threshold_alert( $norm_query );
		}
	}

	/**
	 * Déclenche une alerte e-mail si un terme sans résultat dépasse le seuil configuré
	 *
	 * @param string $norm_query Terme normalisé.
	 */
	public static function maybe_send_threshold_alert( string $norm_query ): void {
		// Exclusion des termes blacklistés/ignorés par l'administrateur.
		$ignored = get_option( 'woo_search_ignored_terms', [] );
		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$norm_lower    = mb_strtolower( $norm_query, 'UTF-8' );
			$ignored_lower = array_map( fn( $t ) => mb_strtolower( (string) $t, 'UTF-8' ), $ignored );
			if ( in_array( $norm_lower, $ignored_lower, true ) ) {
				return;
			}
		}

		global $wpdb;
		$engine     = Woo_Search_Engine::instance();
		$threshold  = max( 2, absint( $engine->get_option( 'alert_threshold', 5 ) ) );
		$table_logs = $wpdb->prefix . 'woo_search_logs';

		// Nombre de sessions distinctes ayant cherché ce terme à 0 résultat sur les 7 derniers jours.
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT session_hash) FROM {$table_logs}
			WHERE normalized_query = %s
			AND has_results = 0
			AND searched_at >= %s",
			$norm_query,
			date( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
		) );

		if ( $count >= $threshold ) {
			$alert_sent_key = 'woo_alert_sent_' . md5( $norm_query );
			if ( ! get_transient( $alert_sent_key ) ) {
				$to      = sanitize_email( (string) $engine->get_option( 'alert_email', get_option( 'admin_email' ) ) );
				$subject = sprintf( '[Alerte Recherche] Le terme « %s » recherché %d fois sans résultat', $norm_query, $count );

				$site_name = get_bloginfo( 'name' );
				$admin_url = admin_url( 'admin.php?page=woo-search-intelligence&tab=zero_results' );

				$message  = "Bonjour,\n\n";
				$message .= sprintf( "Le terme de recherche « %s » a été recherché %d fois par des visiteurs sur %s sans retourner aucun résultat produit.\n\n", $norm_query, $count, $site_name );
				$message .= "Pour convertir ces visiteurs en acheteurs, vous pouvez :\n";
				$message .= "1. Créer un synonyme vers une référence existante depuis l'administration.\n";
				$message .= "2. Créer une nouvelle référence catalogue ou enrichir la fiche produit existante.\n\n";
				$message .= "Consulter les termes sans résultat : " . $admin_url . "\n\n";
				$message .= "WooCommerce Search Intelligence — SOYOO";

				wp_mail( $to, $subject, $message );
				set_transient( $alert_sent_key, 1, 3 * DAY_IN_SECONDS );
			}
		}
	}

	/**
	 * Envoi du rapport hebdomadaire des termes à 0 résultat (Cron)
	 */
	public static function send_weekly_digest_email(): void {
		$engine = Woo_Search_Engine::instance();
		if ( ! $engine->get_option( 'enable_alerts' ) || 'weekly' !== $engine->get_option( 'alert_mode' ) ) {
			return;
		}

		global $wpdb;
		$table_logs     = $wpdb->prefix . 'woo_search_logs';
		$seven_days_ago = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		$ignored       = get_option( 'woo_search_ignored_terms', [] );
		$where_ignored = '';
		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$placeholders  = implode( ', ', array_fill( 0, count( $ignored ), '%s' ) );
			$where_ignored = " AND normalized_query NOT IN ({$placeholders}) ";
		}

		$query = "
			SELECT normalized_query, COUNT(*) as count 
			FROM {$table_logs}
			WHERE has_results = 0 
			{$where_ignored}
			AND searched_at >= %s
			GROUP BY normalized_query
			ORDER BY count DESC
			LIMIT 10
		";

		if ( ! empty( $ignored ) && is_array( $ignored ) ) {
			$params = array_merge( $ignored, [ $seven_days_ago ] );
			$sql    = $wpdb->prepare( $query, $params );
		} else {
			$sql = $wpdb->prepare( $query, $seven_days_ago );
		}

		$rows = $wpdb->get_results( $sql );
		if ( empty( $rows ) ) {
			return;
		}

		$to        = sanitize_email( (string) $engine->get_option( 'alert_email', get_option( 'admin_email' ) ) );
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[Rapport Recherche %s] Top 10 des recherches sans résultat de la semaine', $site_name );

		$message  = "Bonjour,\n\n";
		$message .= sprintf( "Voici le récapitulatif hebdomadaire des termes les plus recherchés sur %s qui n'ont retourné aucun produit :\n\n", $site_name );

		foreach ( $rows as $r ) {
			$message .= sprintf( "• « %s » : %d recherche(s)\n", $r->normalized_query, (int) $r->count );
		}

		$message .= "\nVous pouvez associer ces termes à des produits ou catégories existantes en 1 clic :\n";
		$message .= admin_url( 'admin.php?page=woo-search-intelligence&tab=zero_results' ) . "\n\n";
		$message .= "WooCommerce Search Intelligence — SOYOO";

		wp_mail( $to, $subject, $message );
	}
}
