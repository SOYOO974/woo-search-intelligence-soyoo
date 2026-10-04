<?php
/**
 * Importateur Sécurisé par Batchs depuis Search Analytics for WP
 *
 * Détecte les tables mwt_search_terms et mwt_search_history,
 * gère la migration séquentielle par lots avec barre de progression temps réel,
 * sans timeout serveur ni duplication de données.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Search_Importer {

	/**
	 * Initialisation des hooks AJAX de migration
	 */
	public static function init(): void {
		add_action( 'wp_ajax_woo_search_import_batch', [ __CLASS__, 'ajax_import_batch' ] );
		add_action( 'wp_ajax_woo_search_get_import_info', [ __CLASS__, 'ajax_get_import_info' ] );
	}

	/**
	 * Vérifie la présence des tables de l'extension Search Analytics for WP
	 *
	 * @return array{has_terms: bool, has_history: bool, total: int}
	 */
	public static function get_legacy_tables_info(): array {
		global $wpdb;

		$sa_terms_table   = $wpdb->prefix . 'mwt_search_terms';
		$sa_history_table = $wpdb->prefix . 'mwt_search_history';

		$has_terms   = ( $wpdb->get_var( "SHOW TABLES LIKE '{$sa_terms_table}'" ) === $sa_terms_table );
		$has_history = ( $wpdb->get_var( "SHOW TABLES LIKE '{$sa_history_table}'" ) === $sa_history_table );

		$total = 0;
		if ( $has_history && $has_terms ) {
			$total = (int) $wpdb->get_var( "
				SELECT COUNT(*) 
				FROM {$sa_history_table} h
				INNER JOIN {$sa_terms_table} t ON h.term_id = t.id
				WHERE t.term IS NOT NULL AND t.term != ''
			" );
		} elseif ( $has_terms ) {
			$total = (int) $wpdb->get_var( "
				SELECT COUNT(*) 
				FROM {$sa_terms_table}
				WHERE term IS NOT NULL AND term != ''
			" );
		}

		return [
			'has_terms'   => $has_terms,
			'has_history' => $has_history,
			'total'       => $total,
		];
	}

	/**
	 * Retourne les statistiques et le statut de l'importation via AJAX
	 */
	public static function ajax_get_import_info(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$info           = self::get_legacy_tables_info();
		$imported_at    = get_option( 'woo_search_imported_at' );
		$imported_count = (int) get_option( 'woo_search_imported_count', 0 );

		wp_send_json_success( [
			'has_tables'     => ( $info['has_terms'] || $info['has_history'] ),
			'has_history'    => $info['has_history'],
			'total_records'  => $info['total'],
			'imported_at'    => $imported_at ? (string) $imported_at : null,
			'imported_count' => $imported_count,
		] );
	}

	/**
	 * Traitement d'un lot d'importation par AJAX
	 */
	public static function ajax_import_batch(): void {
		check_ajax_referer( 'woo_search_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'woo-search-intelligence-soyoo' ) ] );
		}

		global $wpdb;

		$info = self::get_legacy_tables_info();
		if ( ! $info['has_terms'] ) {
			wp_send_json_error( [ 'message' => __( 'Aucune table Search Analytics détectée dans la base de données.', 'woo-search-intelligence-soyoo' ) ] );
		}

		$offset     = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? max( 50, min( 500, (int) $_POST['batch_size'] ) ) : 250;
		$reset      = ! empty( $_POST['reset'] );

		$table_logs       = $wpdb->prefix . 'woo_search_logs';
		$sa_terms_table   = $wpdb->prefix . 'mwt_search_terms';
		$sa_history_table = $wpdb->prefix . 'mwt_search_history';

		// Si premier lot et reset demandé, on purge les logs déjà importés pour éviter les doublons.
		if ( 0 === $offset && $reset ) {
			$wpdb->query( "DELETE FROM {$table_logs} WHERE session_hash LIKE 'mwt_%'" );
		}

		$engine   = Woo_Search_Engine::instance();
		$imported = 0;
		$total    = $info['total'];

		if ( $info['has_history'] ) {
			// Importation précise depuis l'historique complet des recherches.
			$query = $wpdb->prepare(
				"SELECT h.id, t.term, h.datetime, h.count_posts 
				FROM {$sa_history_table} h
				INNER JOIN {$sa_terms_table} t ON h.term_id = t.id
				WHERE t.term IS NOT NULL AND t.term != ''
				ORDER BY h.id ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			);

			$rows = $wpdb->get_results( $query );

			if ( ! empty( $rows ) ) {
				$batch_values = [];
				$placeholders = [];

				foreach ( $rows as $row ) {
					$term = trim( (string) $row->term );
					if ( mb_strlen( $term, 'UTF-8' ) < 2 ) {
						continue;
					}

					$norm          = $engine->normalize_query( $term );
					$results_count = max( 0, (int) $row->count_posts );
					$has_results   = $results_count > 0 ? 1 : 0;
					$session_hash  = 'mwt_' . $row->id;
					$searched_at   = ! empty( $row->datetime ) ? $row->datetime : current_time( 'mysql' );

					$batch_values[] = mb_substr( $term, 0, 190, 'UTF-8' );
					$batch_values[] = mb_substr( $norm, 0, 190, 'UTF-8' );
					$batch_values[] = $results_count;
					$batch_values[] = $has_results;
					$batch_values[] = $session_hash;
					$batch_values[] = $searched_at;

					$placeholders[] = '(%s, %s, %d, %d, %s, %s)';
					$imported++;
				}

				if ( ! empty( $placeholders ) ) {
					$sql = "INSERT INTO {$table_logs} (query, normalized_query, results_count, has_results, session_hash, searched_at) VALUES " . implode( ', ', $placeholders );
					$wpdb->query( $wpdb->prepare( $sql, $batch_values ) );
				}
			}
		} else {
			// Fallback si seule la table des termes agrégés existe.
			$query = $wpdb->prepare(
				"SELECT id, term, total_count 
				FROM {$sa_terms_table}
				WHERE term IS NOT NULL AND term != ''
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			);

			$terms_rows = $wpdb->get_results( $query );

			if ( ! empty( $terms_rows ) ) {
				foreach ( $terms_rows as $t ) {
					$term = trim( (string) $t->term );
					if ( mb_strlen( $term, 'UTF-8' ) < 2 ) {
						continue;
					}

					$norm  = $engine->normalize_query( $term );
					$count = max( 1, (int) $t->total_count );

					// Génération d'enregistrements représentatifs (jusqu'à 3 entrées par terme pour modéliser le volume).
					for ( $i = 0; $i < min( $count, 3 ); $i++ ) {
						$wpdb->insert(
							$table_logs,
							[
								'query'            => mb_substr( $term, 0, 190, 'UTF-8' ),
								'normalized_query' => mb_substr( $norm, 0, 190, 'UTF-8' ),
								'results_count'    => 1,
								'has_results'      => 1,
								'session_hash'     => 'mwt_term_' . $t->id . '_' . $i,
								'searched_at'      => current_time( 'mysql' ),
							],
							[ '%s', '%s', '%d', '%d', '%s', '%s' ]
						);
						$imported++;
					}
				}
			}
		}

		$new_offset  = $offset + $batch_size;
		$is_finished = ( $new_offset >= $total ) || ( 0 === $imported && $offset > 0 );
		$percent     = ( $total > 0 ) ? min( 100, (int) round( ( $new_offset / $total ) * 100 ) ) : 100;

		if ( $is_finished ) {
			$percent = 100;
			$total_imported_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_logs} WHERE session_hash LIKE 'mwt_%'" );
			update_option( 'woo_search_imported_at', current_time( 'mysql' ) );
			update_option( 'woo_search_imported_count', $total_imported_count );
		}

		wp_send_json_success( [
			'imported_batch' => $imported,
			'new_offset'     => $new_offset,
			'total'          => $total,
			'percent'        => $percent,
			'is_finished'    => $is_finished,
			'message'        => $is_finished
				? sprintf( __( 'Migration terminée avec succès ! (%d recherches importées)', 'woo-search-intelligence-soyoo' ), $total )
				: sprintf( __( 'Progression : %d%% (%d / %d)', 'woo-search-intelligence-soyoo' ), $percent, min( $new_offset, $total ), $total ),
		] );
	}
}
