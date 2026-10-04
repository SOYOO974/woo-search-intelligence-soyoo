<?php
/**
 * Indexeur de catalogue
 *
 * Maintient la table dénormalisée `{prefix}woo_search_index` : une ligne par produit
 * recherchable (publié, visibilité « visible » ou « recherche »), avec titre, SKU
 * (parent + variations + GTIN), taxonomies/attributs et extrait pré-pliés.
 *
 * - Mise à jour incrémentale sur les hooks CRUD WooCommerce (traitée au shutdown,
 *   déportée en Action Scheduler au-delà de 50 produits pour les synchros ERP).
 * - Reconstruction complète par lots (AJAX admin avec progression, ou en tâche de fond).
 * - Vocabulaire du catalogue pour la correction orthographique.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Indexer {

	public const BATCH_SIZE        = 200;
	public const INLINE_LIMIT      = 50;
	public const HOOK_BATCH        = 'woo_search_index_batch';
	public const HOOK_REBUILD      = 'woo_search_rebuild_index';
	public const HOOK_VOCAB        = 'woo_search_build_vocab';
	private const STALE_RUN_SECONDS = 900;

	private static ?self $instance = null;

	/**
	 * File d'attente des produits à réindexer (clé = ID).
	 *
	 * @var array<int, true>
	 */
	private array $queue = [];

	private bool $shutdown_registered = false;

	/**
	 * Vocabulaire mémoïsé.
	 *
	 * @var array<string, array<string, int>>|null
	 */
	private ?array $vocabulary = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Produits.
		add_action( 'woocommerce_new_product', [ $this, 'queue_product' ] );
		add_action( 'woocommerce_update_product', [ $this, 'queue_product' ] );
		add_action( 'woocommerce_new_product_variation', [ $this, 'queue_product' ] );
		add_action( 'woocommerce_update_product_variation', [ $this, 'queue_product' ] );
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'queue_product' ] );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'queue_product' ] );
		add_action( 'transition_post_status', [ $this, 'on_transition_status' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'on_post_removed' ] );
		add_action( 'before_delete_post', [ $this, 'on_post_removed' ] );
		add_action( 'untrashed_post', [ $this, 'queue_product' ] );

		// Taxonomies (renommage d'une catégorie, d'une marque, d'une valeur d'attribut).
		add_action( 'edited_term', [ $this, 'on_term_changed' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'on_term_deleted' ], 10, 5 );

		// Tâches de fond.
		add_action( self::HOOK_BATCH, [ $this, 'process_ids' ] );
		add_action( self::HOOK_REBUILD, [ $this, 'background_rebuild_step' ] );
		add_action( self::HOOK_VOCAB, [ $this, 'build_vocabulary' ] );

		add_action( 'init', [ $this, 'maybe_trigger_rebuild' ], 30 );
	}

	/* ---------------------------------------------------------------------
	 * État de l'index
	 * ------------------------------------------------------------------ */

	/**
	 * État persistant de l'index.
	 *
	 * @return array{status: string, ready: bool, started_at: string, finished_at: string, updated_at: int, processed: int, total: int}
	 */
	public function get_state(): array {
		$state = get_option( 'woo_search_index_state', [] );
		return wp_parse_args( is_array( $state ) ? $state : [], [
			'status'      => 'empty',
			'ready'       => false,
			'started_at'  => '',
			'finished_at' => '',
			'updated_at'  => 0,
			'processed'   => 0,
			'total'       => 0,
		] );
	}

	/**
	 * @param array<string, mixed> $changes Modifications.
	 */
	private function update_state( array $changes ): void {
		update_option( 'woo_search_index_state', array_merge( $this->get_state(), $changes ), false );
	}

	/**
	 * L'index a-t-il été construit au moins une fois ?
	 */
	public function is_ready(): bool {
		return (bool) $this->get_state()['ready'];
	}

	public function count_indexed(): int {
		global $wpdb;
		$table = Woo_Search_Installer::tables()['index'];
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function count_publishable(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
	}

	/**
	 * Invalide tous les caches de recherche (clés versionnées : fonctionne avec Redis comme avec wp_options).
	 */
	public static function bump_cache(): void {
		update_option( 'woo_search_cache_gen', (int) get_option( 'woo_search_cache_gen', 1 ) + 1 );
	}

	/* ---------------------------------------------------------------------
	 * File d'attente incrémentale
	 * ------------------------------------------------------------------ */

	/**
	 * Ajoute un produit (ou le parent d'une variation) à la file de réindexation.
	 *
	 * @param mixed $post_id ID du produit ou de la variation.
	 */
	public function queue_product( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$type = get_post_type( $post_id );
		if ( 'product_variation' === $type ) {
			$post_id = (int) wp_get_post_parent_id( $post_id );
		} elseif ( 'product' !== $type ) {
			return;
		}

		if ( $post_id <= 0 ) {
			return;
		}

		$this->queue[ $post_id ] = true;

		if ( ! $this->shutdown_registered ) {
			$this->shutdown_registered = true;
			add_action( 'shutdown', [ $this, 'flush_queue' ], 5 );
		}
	}

	/**
	 * @param mixed $new_status Nouveau statut.
	 * @param mixed $old_status Ancien statut.
	 * @param mixed $post       Post.
	 */
	public function on_transition_status( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) && $new_status !== $old_status ) {
			$this->queue_product( $post->ID );
		}
	}

	/**
	 * @param mixed $post_id ID supprimé ou mis à la corbeille.
	 */
	public function on_post_removed( $post_id ): void {
		$post_id = (int) $post_id;
		$type    = get_post_type( $post_id );

		if ( 'product' === $type ) {
			$this->delete_row( $post_id );
			unset( $this->queue[ $post_id ] );
			self::bump_cache();
		} elseif ( 'product_variation' === $type ) {
			$this->queue_product( $post_id );
		}
	}

	/**
	 * Traite la file au shutdown : en direct si petite, sinon par lots asynchrones.
	 */
	public function flush_queue(): void {
		if ( empty( $this->queue ) ) {
			return;
		}

		$ids         = array_keys( $this->queue );
		$this->queue = [];

		if ( count( $ids ) <= self::INLINE_LIMIT ) {
			$this->process_ids( $ids );
		} else {
			foreach ( array_chunk( $ids, 100 ) as $chunk ) {
				Woo_Search_Installer::async( self::HOOK_BATCH, [ $chunk ] );
			}
		}
	}

	/**
	 * Indexe une liste d'IDs puis invalide les caches.
	 *
	 * @param mixed $ids Liste d'IDs.
	 */
	public function process_ids( $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		_prime_post_caches( $ids, true, true );
		update_object_term_cache( $ids, 'product' );

		foreach ( $ids as $id ) {
			$this->index_product( $id );
		}

		self::bump_cache();
		update_option( 'woo_search_vocab_dirty', 1, false );
		Woo_Search_Installer::schedule_once( 10 * MINUTE_IN_SECONDS, self::HOOK_VOCAB );
	}

	/**
	 * @param mixed $term_id  ID du terme.
	 * @param mixed $tt_id    ID de taxonomie.
	 * @param mixed $taxonomy Taxonomie.
	 */
	public function on_term_changed( $term_id, $tt_id, $taxonomy ): void {
		$taxonomy = (string) $taxonomy;
		if ( ! $this->is_indexed_taxonomy( $taxonomy ) ) {
			return;
		}
		$objects = get_objects_in_term( (int) $term_id, $taxonomy );
		$this->queue_many( is_array( $objects ) ? $objects : [] );
	}

	/**
	 * @param mixed $term         Terme.
	 * @param mixed $tt_id        ID de taxonomie.
	 * @param mixed $taxonomy     Taxonomie.
	 * @param mixed $deleted_term Terme supprimé.
	 * @param mixed $object_ids   Objets associés.
	 */
	public function on_term_deleted( $term, $tt_id, $taxonomy, $deleted_term = null, $object_ids = [] ): void {
		if ( $this->is_indexed_taxonomy( (string) $taxonomy ) ) {
			$this->queue_many( is_array( $object_ids ) ? $object_ids : [] );
		}
	}

	/**
	 * @param array<int, mixed> $ids IDs.
	 */
	private function queue_many( array $ids ): void {
		if ( count( $ids ) > 2000 ) {
			update_option( 'woo_search_needs_rebuild', 1 );
			return;
		}
		foreach ( $ids as $id ) {
			$this->queue_product( $id );
		}
	}

	private function is_indexed_taxonomy( string $taxonomy ): bool {
		return str_starts_with( $taxonomy, 'pa_' ) || in_array( $taxonomy, $this->get_taxonomies(), true );
	}

	/**
	 * Taxonomies indexées (catégories, étiquettes, marques).
	 *
	 * @return array<int, string>
	 */
	private function get_taxonomies(): array {
		$taxonomies = (array) apply_filters( 'woo_search_index_taxonomies', [ 'product_cat', 'product_tag', 'product_brand', 'pwb-brand', 'yith_product_brand' ] );
		return array_values( array_filter( array_map( 'strval', $taxonomies ), 'taxonomy_exists' ) );
	}

	/* ---------------------------------------------------------------------
	 * Indexation d'un produit
	 * ------------------------------------------------------------------ */

	/**
	 * Indexe (ou retire) un produit selon son éligibilité.
	 */
	public function index_product( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product_Variation ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		if ( ! $product instanceof WC_Product ) {
			$this->delete_row( $product_id );
			return;
		}

		$product_id = $product->get_id();

		if ( 'publish' !== $product->get_status() || in_array( $product->get_catalog_visibility(), [ 'hidden', 'catalog' ], true ) ) {
			$this->delete_row( $product_id );
			return;
		}

		$data = [
			'title'   => $product->get_name(),
			'skus'    => $this->collect_skus( $product ),
			'terms'   => $this->collect_terms( $product ),
			'excerpt' => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 1500, 'UTF-8' ),
		];

		/**
		 * Filtre : woo_search_index_data
		 * Permet d'enrichir l'index d'un produit (champs ACF, marque ERP, référence fournisseur…).
		 * Les valeurs sont des textes bruts : le pliage est appliqué ensuite.
		 *
		 * @param array{title: string, skus: array<int, string>, terms: array<int, string>, excerpt: string} $data
		 * @param WC_Product $product
		 */
		$data = (array) apply_filters( 'woo_search_index_data', $data, $product );

		$skus = [];
		foreach ( (array) ( $data['skus'] ?? [] ) as $sku ) {
			$compact = Woo_Search_Text::compact( (string) $sku );
			if ( '' !== $compact ) {
				$skus[ $compact ] = true;
			}
		}

		$terms_tokens = array_unique( Woo_Search_Text::tokens( Woo_Search_Text::fold( implode( ' ', array_map( 'strval', (array) ( $data['terms'] ?? [] ) ) ) ) ) );

		global $wpdb;
		$wpdb->replace(
			Woo_Search_Installer::tables()['index'],
			[
				'product_id'   => $product_id,
				'title'        => self::pad( Woo_Search_Text::fold( (string) ( $data['title'] ?? '' ) ) ),
				'skus'         => self::pad( implode( ' ', array_keys( $skus ) ) ),
				'terms'        => self::pad( implode( ' ', $terms_tokens ) ),
				'excerpt'      => self::pad( Woo_Search_Text::fold( (string) ( $data['excerpt'] ?? '' ) ) ),
				'stock_status' => (string) $product->get_stock_status(),
				'total_sales'  => max( 0, (int) $product->get_total_sales() ),
				'indexed_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Entoure un texte plié d'espaces pour permettre les correspondances « début de mot »
	 * (`LIKE '% mot%'`) et « mot entier » (`LIKE '% mot %'`).
	 */
	public static function pad( string $folded ): string {
		return '' === $folded ? ' ' : ' ' . $folded . ' ';
	}

	/**
	 * SKU parent + variations publiées + GTIN/EAN.
	 *
	 * @return array<int, string>
	 */
	private function collect_skus( WC_Product $product ): array {
		global $wpdb;

		$skus = [ (string) $product->get_sku() ];
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$skus[] = (string) $product->get_global_unique_id();
		}

		if ( $product->is_type( 'variable' ) || $product->has_child() ) {
			$values = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_parent = %d
				AND p.post_type = 'product_variation'
				AND p.post_status = 'publish'
				AND pm.meta_key IN ('_sku', '_global_unique_id')
				AND pm.meta_value <> ''",
				$product->get_id()
			) );
			foreach ( (array) $values as $value ) {
				$skus[] = (string) $value;
			}
		}

		return array_values( array_filter( array_unique( $skus ) ) );
	}

	/**
	 * Catégories (avec ancêtres), étiquettes, marques et valeurs d'attributs.
	 *
	 * @return array<int, string>
	 */
	private function collect_terms( WC_Product $product ): array {
		$names = [];

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$terms = get_the_terms( $product->get_id(), $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( in_array( $term->slug, [ 'uncategorized', 'non-classe' ], true ) ) {
					continue;
				}
				$names[] = $term->name;
				if ( is_taxonomy_hierarchical( $taxonomy ) ) {
					foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
						$ancestor = get_term( (int) $ancestor_id, $taxonomy );
						if ( $ancestor instanceof WP_Term ) {
							$names[] = $ancestor->name;
						}
					}
				}
			}
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				foreach ( (array) $attribute->get_terms() as $term ) {
					if ( $term instanceof WP_Term ) {
						$names[] = $term->name;
					}
				}
			} else {
				foreach ( (array) $attribute->get_options() as $option ) {
					$names[] = (string) $option;
				}
			}
		}

		return $names;
	}

	private function delete_row( int $product_id ): void {
		global $wpdb;
		$wpdb->delete( Woo_Search_Installer::tables()['index'], [ 'product_id' => $product_id ], [ '%d' ] );
	}

	/* ---------------------------------------------------------------------
	 * Reconstruction complète
	 * ------------------------------------------------------------------ */

	/**
	 * Déclenche une reconstruction en tâche de fond si nécessaire (installation, migration, gros changement de taxonomie).
	 */
	public function maybe_trigger_rebuild(): void {
		if ( ! get_option( 'woo_search_needs_rebuild' ) ) {
			return;
		}

		$state = $this->get_state();
		$fresh = in_array( $state['status'], [ 'running', 'queued' ], true ) && ( time() - (int) $state['updated_at'] ) < self::STALE_RUN_SECONDS;
		if ( $fresh ) {
			return;
		}

		$this->update_state( [ 'status' => 'queued', 'updated_at' => time() ] );
		Woo_Search_Installer::async( self::HOOK_REBUILD, [ 0 ], true );
	}

	/**
	 * Étape de reconstruction en tâche de fond (chaînée).
	 *
	 * @param mixed $cursor Dernier ID traité.
	 */
	public function background_rebuild_step( $cursor = 0 ): void {
		$result = $this->rebuild_batch( (int) $cursor );
		if ( ! $result['done'] ) {
			Woo_Search_Installer::async( self::HOOK_REBUILD, [ $result['cursor'] ] );
		}
	}

	/**
	 * Indexe un lot de produits publiés (pagination par curseur d'ID).
	 *
	 * @param int $cursor Dernier ID traité (0 = démarrage).
	 * @return array{processed: int, total: int, cursor: int, done: bool, percent: int}
	 */
	public function rebuild_batch( int $cursor ): array {
		global $wpdb;

		if ( 0 === $cursor ) {
			$this->update_state( [
				'status'     => 'running',
				'started_at' => gmdate( 'Y-m-d H:i:s' ),
				'processed'  => 0,
				'total'      => $this->count_publishable(),
				'updated_at' => time(),
			] );
		}

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			$cursor,
			self::BATCH_SIZE
		) ) );

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, true, true );
			update_object_term_cache( $ids, 'product' );
			foreach ( $ids as $id ) {
				$this->index_product( $id );
			}
		}

		$state     = $this->get_state();
		$processed = (int) $state['processed'] + count( $ids );
		$total     = max( 1, (int) $state['total'] );
		$done      = count( $ids ) < self::BATCH_SIZE;
		$next      = empty( $ids ) ? $cursor : (int) end( $ids );

		$this->update_state( [ 'processed' => $processed, 'updated_at' => time() ] );

		if ( $done ) {
			$this->finalize_rebuild();
		}

		return [
			'processed' => $processed,
			'total'     => $total,
			'cursor'    => $next,
			'done'      => $done,
			'percent'   => $done ? 100 : min( 99, (int) floor( $processed / $total * 100 ) ),
		];
	}

	/**
	 * Fin de reconstruction : purge des lignes obsolètes, vocabulaire, invalidation des caches.
	 */
	private function finalize_rebuild(): void {
		global $wpdb;

		$state = $this->get_state();
		$table = Woo_Search_Installer::tables()['index'];

		if ( '' !== $state['started_at'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE indexed_at < %s", $state['started_at'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$this->update_state( [
			'status'      => 'ready',
			'ready'       => true,
			'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'  => time(),
		] );

		delete_option( 'woo_search_needs_rebuild' );
		$this->build_vocabulary();
		self::bump_cache();
	}

	/**
	 * Synchronisation quotidienne légère des ventes et du stock depuis la table lookup WooCommerce
	 * (les commandes mettent à jour `total_sales` sans déclencher de sauvegarde produit).
	 */
	public function sync_lookup_metrics(): void {
		global $wpdb;
		$table  = Woo_Search_Installer::tables()['index'];
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookup ) ) ) !== $lookup ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} i INNER JOIN {$lookup} l ON l.product_id = i.product_id SET i.total_sales = l.total_sales, i.stock_status = COALESCE(l.stock_status, i.stock_status)" );
		self::bump_cache();
	}

	/* ---------------------------------------------------------------------
	 * Vocabulaire (correction orthographique)
	 * ------------------------------------------------------------------ */

	/**
	 * Construit le vocabulaire du catalogue : [première lettre => [mot => fréquence]].
	 */
	public function build_vocabulary(): void {
		global $wpdb;
		$table  = Woo_Search_Installer::tables()['index'];
		$freq   = [];
		$cursor = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT product_id, title, terms FROM {$table} WHERE product_id > %d ORDER BY product_id ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cursor
			), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row['product_id'];
				foreach ( Woo_Search_Text::tokens( trim( $row['title'] . ' ' . $row['terms'] ) ) as $word ) {
					$len = strlen( $word );
					if ( $len >= 3 && $len <= 30 && ctype_alpha( $word ) ) {
						$freq[ $word ] = ( $freq[ $word ] ?? 0 ) + 1;
					}
				}
			}
		} while ( is_array( $rows ) && count( $rows ) === 2000 );

		$vocabulary = [];
		foreach ( $freq as $word => $count ) {
			$word                             = (string) $word;
			$vocabulary[ $word[0] ][ $word ] = $count;
		}

		update_option( 'woo_search_vocab', $vocabulary, false );
		delete_option( 'woo_search_vocab_dirty' );
		$this->vocabulary = $vocabulary;
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	public function get_vocabulary(): array {
		if ( null === $this->vocabulary ) {
			$vocab            = get_option( 'woo_search_vocab', [] );
			$this->vocabulary = is_array( $vocab ) ? $vocab : [];
		}
		return $this->vocabulary;
	}
}
