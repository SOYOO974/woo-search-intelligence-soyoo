<?php
/**
 * Importateur par lots depuis Search Analytics for WP
 *
 * Lit l'historique détaillé `{prefix}mwt_search_history` joint à `{prefix}mwt_search_terms`
 * (pagination par curseur d'ID, sans OFFSET), convertit les dates locales en UTC et
 * marque chaque ligne importée (`source = import`, `session_hash = mwt_<id>`) pour un
 * ré-import idempotent. Aucune donnée n'est extrapolée depuis la seule table agrégée.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Importer {

	public const BATCH_SIZE = 500;

	public static function init(): void {
		add_action( 'wp_ajax_woo_search_import_batch', [ __CLASS__, 'ajax_import_batch' ] );
	}

	/**
	 * Détection des tables héritées (résultat mis en cache 1 h).
	 *
	 * @param bool $force Ignorer le cache.
	 * @return array{has_terms: bool, has_history: bool, total: int}
	 */
	public static function get_legacy_tables_info( bool $force = false ): array {
		$cached = get_transient( 'wsi_legacy_info' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$terms   = $wpdb->prefix . 'mwt_search_terms';
		$history = $wpdb->prefix . 'mwt_search_history';

		$has_terms   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $terms ) ) ) === $terms;
		$has_history = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $history ) ) ) === $history;

		$total = 0;
		if ( $has_terms && $has_history ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history} h INNER JOIN {$terms} t ON h.term_id = t.id WHERE t.term IS NOT NULL AND t.term <> ''" );
		}

		$info = [
			'has_terms'   => $has_terms,
			'has_history' => $has_history,
			'total'       => $total,
		];
		set_transient( 'wsi_legacy_info', $info, HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Traitement d'un lot d'importation.
	 */
	public static function ajax_import_batch(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ], 403 );
		}

		$info = self::get_legacy_tables_info( true );
		if ( ! $info['has_terms'] || ! $info['has_history'] ) {
			wp_send_json_error( [ 'message' => __( 'Historique détaillé Search Analytics introuvable : import impossible sans les dates de recherche.', 'woo-search-intelligence-soyoo' ) ] );
		}

		global $wpdb;
		$cursor  = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$reset   = ! empty( $_POST['reset'] );
		$done    = isset( $_POST['done'] ) ? absint( $_POST['done'] ) : 0;
		$logs    = Woo_Search_Installer::tables()['logs'];
		$terms   = $wpdb->prefix . 'mwt_search_terms';
		$history = $wpdb->prefix . 'mwt_search_history';

		if ( 0 === $cursor && $reset ) {
			$wpdb->query( "DELETE FROM {$logs} WHERE source = 'import'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT h.id, t.term, h.datetime, h.count_posts
			FROM {$history} h
			INNER JOIN {$terms} t ON h.term_id = t.id
			WHERE h.id > %d AND t.term IS NOT NULL AND t.term <> ''
			ORDER BY h.id ASC
			LIMIT %d",
			$cursor,
			self::BATCH_SIZE
		) );

		$engine       = Woo_Search_Engine::instance();
		$values       = [];
		$placeholders = [];
		$next_cursor  = $cursor;

		foreach ( (array) $rows as $row ) {
			$next_cursor = (int) $row->id;
			$term        = mb_substr( trim( (string) $row->term ), 0, 190, 'UTF-8' );
			$display     = $engine->normalize_query( $term );
			if ( mb_strlen( $display, 'UTF-8' ) < 2 ) {
				continue;
			}

			$results  = max( 0, (int) $row->count_posts );
			$local_dt = (string) $row->datetime;
			$gmt_dt   = ( '' !== $local_dt && '0000-00-00 00:00:00' !== $local_dt ) ? get_gmt_from_date( $local_dt ) : gmdate( 'Y-m-d H:i:s' );

			array_push( $values, $term, $display, $results, $results > 0 ? 1 : 0, 'import', 'mwt_' . $row->id, $gmt_dt );
			$placeholders[] = '(%s, %s, %d, %d, %s, %s, %s)';
		}

		if ( ! empty( $placeholders ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "INSERT INTO {$logs} (query, normalized_query, results_count, has_results, source, session_hash, searched_at) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$fetched     = is_array( $rows ) ? count( $rows ) : 0;
		$done       += $fetched;
		$is_finished = $fetched < self::BATCH_SIZE;
		$total       = max( 1, $info['total'] );
		$percent     = $is_finished ? 100 : min( 99, (int) floor( $done / $total * 100 ) );

		if ( $is_finished ) {
			$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs} WHERE source = 'import'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			update_option( 'woo_search_imported_at', gmdate( 'Y-m-d H:i:s' ), false );
			update_option( 'woo_search_imported_count', $imported, false );
			if ( class_exists( 'Woo_Search_Admin' ) ) {
				Woo_Search_Admin::flush_kpis();
			}
		}

		wp_send_json_success( [
			'cursor'      => $next_cursor,
			'done'        => $done,
			'total'       => $info['total'],
			'percent'     => $percent,
			'is_finished' => $is_finished,
			'message'     => $is_finished
				/* translators: %d: nombre de recherches */
				? sprintf( __( 'Migration terminée : %d recherches importées.', 'woo-search-intelligence-soyoo' ), (int) get_option( 'woo_search_imported_count', 0 ) )
				/* translators: 1: pourcentage, 2: traités, 3: total */
				: sprintf( __( 'Progression : %1$d %% (%2$d / %3$d)', 'woo-search-intelligence-soyoo' ), $percent, min( $done, $info['total'] ), $info['total'] ),
		] );
	}
}
