<?php
/**
 * Moteur de recherche e-commerce
 *
 * Interroge l'index dédié avec une sémantique « tous les mots » (ET) sur titre,
 * SKU, taxonomies/attributs et extrait, scoring multi-paliers, synonymes,
 * racinisation française, correction orthographique automatique et repli
 * « correspondance partielle ». Alimente à la fois le live search AJAX et la
 * page de résultats standard (pre_get_posts) pour des résultats strictement identiques.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Search_Engine {

	public const MAX_QUERY_CHARS = 100;
	public const MAX_TOKENS      = 8;
	public const CACHE_GROUP     = 'woo_search';
	public const CACHE_TTL       = 6 * HOUR_IN_SECONDS;

	private static ?self $instance = null;

	/**
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Résultats mémoïsés pour la requête HTTP courante.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $memo = [];

	/**
	 * @var array<int, array{from: string, from_tokens: array<int, string>, to: string, type: string}>|null
	 */
	private ?array $compiled_synonyms = null;

	/**
	 * Actions AJAX publiques servies par le moteur.
	 *
	 * @var array<int, string>
	 */
	private array $ajax_actions = [];

	/**
	 * Résultat de la recherche de la page courante (pour le tracker et l'avis de correction).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $page_result = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_options();

		add_action( 'init', [ $this, 'register_ajax_actions' ], 5 );
		add_action( 'init', [ $this, 'maybe_seed_synonyms' ], 20 );

		add_action( 'pre_get_posts', [ $this, 'integrate_search_page' ], 1000 );
		add_filter( 'posts_search', [ $this, 'neutralize_native_search' ], 1000, 2 );
		add_filter( 'posts_search_orderby', [ $this, 'neutralize_native_search' ], 1000, 2 );
		add_filter( 'posts_pre_query', [ $this, 'short_circuit_ajax_main_query' ], 10, 2 );
		add_action( 'woocommerce_before_shop_loop', [ $this, 'render_search_notice' ], 5 );

		foreach ( [ 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ] as $hook ) {
			add_action( $hook, [ Woo_Search_Indexer::class, 'bump_cache' ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------ */

	public function load_options(): void {
		$saved         = get_option( 'woo_search_settings', [] );
		$this->options = wp_parse_args( is_array( $saved ) ? $saved : [], Woo_Search_Installer::default_settings() );
		$this->memo    = [];
	}

	/**
	 * @param string $key     Clé.
	 * @param mixed  $default Valeur par défaut.
	 * @return mixed
	 */
	public function get_option( string $key, mixed $default = null ): mixed {
		return $this->options[ $key ] ?? $default;
	}

	private function opt( string $key ): bool {
		return ! empty( $this->options[ $key ] );
	}

	/* ---------------------------------------------------------------------
	 * Endpoints AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Enregistre les actions AJAX sur `init` (après le thème) pour que les thèmes clients
	 * puissent déclarer leurs alias historiques via le filtre `woo_search_ajax_actions`.
	 */
	public function register_ajax_actions(): void {
		/**
		 * Filtre : woo_search_ajax_actions
		 * Ex. : add_filter( 'woo_search_ajax_actions', fn( $a ) => array_merge( $a, [ 'mfm_live_search' ] ) );
		 */
		$actions            = (array) apply_filters( 'woo_search_ajax_actions', [ 'woo_live_search' ] );
		$this->ajax_actions = array_values( array_unique( array_filter( array_map( 'sanitize_key', $actions ) ) ) );

		foreach ( $this->ajax_actions as $action ) {
			add_action( 'wc_ajax_' . $action, [ $this, 'ajax_live_search' ] );
			add_action( 'wp_ajax_' . $action, [ $this, 'ajax_live_search' ] );
			add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'ajax_live_search' ] );
		}
	}

	/**
	 * Évite que WordPress exécute sa propre recherche native (requête principale)
	 * lorsque le live search est appelé via `?wc-ajax=…&s=…`.
	 *
	 * @param mixed $posts Résultat pré-calculé.
	 * @param mixed $query Requête.
	 * @return mixed
	 */
	public function short_circuit_ajax_main_query( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof WP_Query || ! $query->is_main_query() || empty( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $posts;
		}

		$action = sanitize_key( wp_unslash( (string) $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, $this->ajax_actions, true ) || 'wsi_click' === $action ) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			return [];
		}
		return $posts;
	}

	/**
	 * Point d'entrée du live search.
	 */
	public function ajax_live_search(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- endpoint public en lecture seule.
		$raw = '';
		foreach ( [ 'term', 's', 'q' ] as $param ) {
			if ( isset( $_REQUEST[ $param ] ) && is_string( $_REQUEST[ $param ] ) && '' !== $_REQUEST[ $param ] ) {
				$raw = sanitize_text_field( wp_unslash( $_REQUEST[ $param ] ) );
				break;
			}
		}
		// phpcs:enable

		$raw    = trim( mb_substr( $raw, 0, self::MAX_QUERY_CHARS, 'UTF-8' ) );
		$result = '' !== $raw ? $this->search( $raw, [ 'live' => true ] ) : $this->empty_result( '' );

		$uid      = Woo_Search_Tracker::new_uid();
		$limit    = max( 1, absint( $this->get_option( 'max_results', 6 ) ) );
		$products = $this->format_products( array_slice( $result['ids'], 0, $limit ), $result['sku_matches'], $uid );

		$payload = [
			'products'        => $products,
			'categories'      => $this->opt( 'show_categories' ) ? $result['categories'] : [],
			'total_count'     => count( $products ),
			'total_found'     => (int) $result['total'],
			'see_all_url'     => $this->get_results_url( $raw ),
			'search_uid'      => $uid,
			'query'           => $result['query'],
			'corrected_query' => $result['corrected'],
			'match_mode'      => $result['mode'],
			'notice'          => $this->get_notice( $result ),
			'display'         => [
				'images' => $this->opt( 'show_images' ),
				'prices' => $this->opt( 'show_prices' ),
				'sku'    => $this->opt( 'show_sku_badge' ),
				'stock'  => $this->opt( 'show_stock' ),
			],
		];

		if ( '' !== $raw ) {
			Woo_Search_Tracker::log_search( $raw, (int) $result['total'], [
				'source'    => 'ajax',
				'mode'      => $result['mode'],
				'corrected' => $result['corrected'],
				'uid'       => $uid,
			] );
		}

		/**
		 * Filtre : woo_search_live_response
		 * Permet aux thèmes d'enrichir la réponse JSON (badges, attributs…).
		 */
		wp_send_json_success( apply_filters( 'woo_search_live_response', $payload, $result ) );
	}

	/**
	 * URL de la page de résultats complète.
	 */
	public function get_results_url( string $raw ): string {
		return add_query_arg(
			[
				's'         => rawurlencode( $raw ),
				'post_type' => 'product',
			],
			home_url( '/' )
		);
	}

	/**
	 * Formate les produits pour la réponse JSON (rendu frais : prix et stock jamais mis en cache).
	 *
	 * @param array<int, int>    $ids         IDs ordonnés.
	 * @param array<int, string> $sku_matches SKU correspondants par produit.
	 * @param string             $uid         Identifiant de recherche (suivi des clics).
	 * @return array<int, array<string, mixed>>
	 */
	public function format_products( array $ids, array $sku_matches, string $uid ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		_prime_post_caches( $ids, true, true );

		$image_size = (string) apply_filters( 'woo_search_image_size', 'woocommerce_thumbnail' );
		$acronyms   = $this->get_title_acronyms();
		$tracking   = $this->opt( 'enable_click_tracking' );
		$products   = [];
		$position   = 0;

		if ( $this->opt( 'show_images' ) ) {
			$image_ids = [];
			foreach ( $ids as $id ) {
				$thumb = (int) get_post_thumbnail_id( $id );
				if ( $thumb > 0 ) {
					$image_ids[] = $thumb;
				}
			}
			if ( ! empty( $image_ids ) ) {
				_prime_post_caches( $image_ids, false, true );
			}
		}

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$position++;
			$name      = $product->get_name();
			$permalink = (string) $product->get_permalink();
			if ( $tracking && '' !== $uid ) {
				$permalink .= '#wsi=' . $uid . '.' . $id . '.' . $position;
			}

			$image_url = '';
			if ( $this->opt( 'show_images' ) ) {
				$image_id  = (int) $product->get_image_id();
				$image_url = $image_id ? (string) wp_get_attachment_image_url( $image_id, $image_size ) : (string) wc_placeholder_img_src( $image_size );
			}

			$sku_match = $sku_matches[ $id ] ?? '';

			$products[] = [
				'id'           => $id,
				'title'        => $this->opt( 'format_titles' ) ? Woo_Search_Text::format_title( $name, $acronyms ) : html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'permalink'    => $permalink,
				'image_url'    => $image_url,
				'price_html'   => $this->opt( 'show_prices' ) ? (string) $product->get_price_html() : '',
				'sku'          => $this->opt( 'show_sku_badge' ) ? ( '' !== $sku_match ? $sku_match : (string) $product->get_sku() ) : '',
				'is_sku_match' => '' !== $sku_match,
				'in_stock'     => $product->is_in_stock(),
				'stock_status' => (string) $product->get_stock_status(),
				'position'     => $position,
			];
		}

		return $products;
	}

	/* ---------------------------------------------------------------------
	 * Intégration de la page de résultats standard
	 * ------------------------------------------------------------------ */

	/**
	 * Remplace la recherche native par le moteur sur la page de résultats produits.
	 *
	 * @param mixed $query Requête.
	 */
	public function integrate_search_page( $query ): void {
		if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		if ( ! $this->opt( 'integrate_search_page' ) || ! Woo_Search_Indexer::instance()->is_ready() ) {
			return;
		}

		$post_type  = $query->get( 'post_type' );
		$is_product = 'product' === $post_type || ( is_array( $post_type ) && [ 'product' ] === array_values( $post_type ) );
		if ( ! $is_product ) {
			return;
		}

		$raw = trim( wp_unslash( (string) $query->get( 's' ) ) );
		if ( '' === $raw ) {
			return;
		}

		$result            = $this->search( $raw, [ 'live' => false ] );
		$this->page_result = $result;

		$ids      = $result['ids'];
		$existing = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( ! empty( $existing ) ) {
			$ids = array_values( array_intersect( $ids, $existing ) );
		}

		$query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
		$query->set( 'wsi_engine', 1 );

		// Ordre de pertinence du moteur, sauf si le visiteur a choisi un tri explicite (prix, popularité…).
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $orderby || 'relevance' === $orderby ) {
			$query->set( 'orderby', 'post__in' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Désactive le SQL de recherche natif quand le moteur a fourni `post__in`.
	 *
	 * @param mixed $sql   Fragment SQL.
	 * @param mixed $query Requête.
	 * @return mixed
	 */
	public function neutralize_native_search( $sql, $query ) {
		return ( $query instanceof WP_Query && $query->get( 'wsi_engine' ) ) ? '' : $sql;
	}

	/**
	 * Résultat moteur de la page de recherche courante (lu par le tracker).
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_page_result(): ?array {
		return $this->page_result;
	}

	/**
	 * Avis « Résultats pour … » au-dessus de la grille produits.
	 */
	public function render_search_notice(): void {
		if ( null === $this->page_result || ! is_search() ) {
			return;
		}
		$notice = $this->get_notice( $this->page_result );
		if ( '' !== $notice ) {
			echo '<div class="woocommerce-info woo-search-notice">' . esc_html( $notice ) . '</div>';
		}
	}

	/**
	 * @param array<string, mixed> $result Résultat moteur.
	 */
	public function get_notice( array $result ): string {
		if ( 'corrected' === $result['mode'] ) {
			/* translators: 1: requête saisie, 2: requête corrigée */
			return sprintf( __( 'Aucun résultat pour « %1$s ». Résultats affichés pour « %2$s ».', 'woo-search-intelligence-soyoo' ), $result['query'], $result['corrected'] );
		}
		if ( 'any' === $result['mode'] ) {
			return __( 'Aucun produit ne contient tous les mots recherchés : voici les résultats les plus proches.', 'woo-search-intelligence-soyoo' );
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Recherche
	 * ------------------------------------------------------------------ */

	/**
	 * Prépare une requête brute.
	 *
	 * @return array{raw: string, display: string, folded: string}
	 */
	public function prepare_query( string $raw ): array {
		$raw     = trim( mb_substr( $raw, 0, self::MAX_QUERY_CHARS, 'UTF-8' ) );
		$display = Woo_Search_Text::display_normalize( $raw );

		/**
		 * Filtre : woo_search_normalize_query
		 * Règles métier spécifiques à une boutique (appliquées avant le pliage).
		 *
		 * @param string $display Requête normalisée (minuscules, espaces).
		 * @param string $raw     Requête brute.
		 */
		$display = trim( (string) apply_filters( 'woo_search_normalize_query', $display, $raw ) );

		return [
			'raw'     => $raw,
			'display' => $display,
			'folded'  => Woo_Search_Text::fold( $display ),
		];
	}

	/**
	 * Requête normalisée utilisée pour le regroupement des statistiques.
	 */
	public function normalize_query( string $raw ): string {
		return $this->prepare_query( $raw )['display'];
	}

	/**
	 * @return array{query: string, ids: array<int, int>, total: int, mode: string, corrected: string, sku_matches: array<int, string>, categories: array<int, array<string, mixed>>}
	 */
	private function empty_result( string $display, string $mode = 'none' ): array {
		return [
			'query'       => $display,
			'ids'         => [],
			'total'       => 0,
			'mode'        => $mode,
			'corrected'   => '',
			'sku_matches' => [],
			'categories'  => [],
		];
	}

	/**
	 * Recherche principale.
	 *
	 * Modes retournés : `all` (tous les mots), `corrected` (après correction orthographique),
	 * `any` (correspondance partielle), `fallback` (index non construit), `none`.
	 *
	 * @param string               $raw  Requête brute.
	 * @param array<string, mixed> $args `live` (bool) : dernier jeton numérique traité en préfixe et catégories calculées.
	 * @return array{query: string, ids: array<int, int>, total: int, mode: string, corrected: string, sku_matches: array<int, string>, categories: array<int, array<string, mixed>>}
	 */
	public function search( string $raw, array $args = [] ): array {
		$live     = ! empty( $args['live'] );
		$prepared = $this->prepare_query( $raw );
		$display  = $prepared['display'];
		$folded   = $prepared['folded'];

		if ( mb_strlen( $display, 'UTF-8' ) < max( 1, absint( $this->get_option( 'min_chars', 2 ) ) ) || '' === $folded ) {
			return $this->empty_result( $display );
		}

		$cache_key = 'q_' . md5( $folded . '|' . ( $live ? 'l' : 'p' ) . '|' . (int) get_option( 'woo_search_cache_gen', 1 ) );
		if ( isset( $this->memo[ $cache_key ] ) ) {
			return $this->memo[ $cache_key ];
		}
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['ids'] ) ) {
			$cached['query']          = $display;
			$this->memo[ $cache_key ] = $cached;
			return $cached;
		}

		$result = Woo_Search_Indexer::instance()->is_ready()
			? $this->search_index( $display, $folded, $live )
			: $this->search_fallback( $display, $prepared['raw'] );

		// Cache persistant uniquement avec un object cache externe (Redis) : évite de gonfler wp_options
		// d'un transient par frappe ; les requêtes sur l'index dédié restent de l'ordre de la milliseconde.
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );
		$this->memo[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Recherche sur l'index dédié avec correction et repli partiel.
	 *
	 * @return array{query: string, ids: array<int, int>, total: int, mode: string, corrected: string, sku_matches: array<int, string>, categories: array<int, array<string, mixed>>}
	 */
	private function search_index( string $display, string $folded, bool $live ): array {
		$plan = $this->build_plan( $folded, $live );
		if ( empty( $plan['groups'] ) && '' === $plan['compact'] ) {
			return $this->empty_result( $display );
		}

		$result = $this->empty_result( $display );
		$rows   = $this->run_query( $plan, 'all' );
		$mode   = 'all';

		if ( empty( $rows ) && $this->opt( 'enable_spellcheck' ) ) {
			$corrected = $this->correct( $folded );
			if ( '' !== $corrected ) {
				$corrected_plan = $this->build_plan( $corrected, $live );
				$rows           = $this->run_query( $corrected_plan, 'all' );
				if ( ! empty( $rows ) ) {
					$mode                = 'corrected';
					$plan                = $corrected_plan;
					$result['corrected'] = $corrected;
				}
			}
		}

		if ( empty( $rows ) && $this->opt( 'enable_partial_fallback' ) && count( $plan['groups'] ) > 1 ) {
			$rows = $this->run_query( $plan, 'any' );
			$mode = 'any';
		}

		if ( empty( $rows ) ) {
			$result['categories'] = $live ? $this->match_categories( $plan ) : [];
			return $result;
		}

		foreach ( $rows as $row ) {
			$pid             = (int) $row['product_id'];
			$result['ids'][] = $pid;
			$sku             = $this->find_matching_sku( (string) $row['skus'], $plan['compact'] );
			if ( '' !== $sku ) {
				$result['sku_matches'][ $pid ] = $sku;
			}
		}

		$result['total']      = count( $result['ids'] );
		$result['mode']       = $mode;
		$result['categories'] = $live ? $this->match_categories( $plan ) : [];

		return $result;
	}

	/**
	 * Recherche native de secours tant que l'index n'est pas construit.
	 *
	 * @return array{query: string, ids: array<int, int>, total: int, mode: string, corrected: string, sku_matches: array<int, string>, categories: array<int, array<string, mixed>>}
	 */
	private function search_fallback( string $display, string $raw ): array {
		$query = new WP_Query( [
			'post_type'        => 'product',
			'post_status'      => 'publish',
			's'                => $raw,
			'fields'           => 'ids',
			'posts_per_page'   => $this->max_ids(),
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => [ 'exclude-from-search' ],
					'operator' => 'NOT IN',
				],
			],
		] );

		$result          = $this->empty_result( $display, 'fallback' );
		$result['ids']   = array_map( 'intval', (array) $query->posts );
		$result['total'] = count( $result['ids'] );
		if ( 0 === $result['total'] ) {
			$result['mode'] = 'none';
		}
		return $result;
	}

	private function max_ids(): int {
		return max( 20, (int) apply_filters( 'woo_search_max_ids', 500 ) );
	}

	/**
	 * Construit le plan de requête : groupes de jetons (chaque groupe doit matcher),
	 * formes réduites, expansions de synonymes et forme compacte pour les SKU.
	 *
	 * @return array{phrase: string, compact: string, token_count: int, groups: array<int, array{prefix: array<int, string>, exact: array<int, string>}>}
	 */
	private function build_plan( string $folded, bool $live ): array {
		$compact = str_replace( ' ', '', $folded );
		$phrase  = $this->apply_replace_synonyms( $folded );
		$tokens  = array_slice( Woo_Search_Text::tokens( $phrase ), 0, self::MAX_TOKENS * 2 );
		$last    = count( $tokens ) - 1;
		$stop    = $this->get_stop_words();
		$except  = $this->get_stem_exceptions();

		$significant = [];
		foreach ( $tokens as $i => $token ) {
			if ( Woo_Search_Text::is_numeric_token( $token ) || ( strlen( $token ) >= 2 && ! in_array( $token, $stop, true ) ) ) {
				$significant[ $i ] = $token;
			}
		}
		if ( empty( $significant ) ) {
			foreach ( $tokens as $i => $token ) {
				if ( strlen( $token ) >= 2 ) {
					$significant[ $i ] = $token;
				}
			}
		}

		$groups = [];
		foreach ( $significant as $i => $token ) {
			if ( Woo_Search_Text::is_numeric_token( $token ) ) {
				$groups[ $i ] = [
					'prefix' => ( $live && $i === $last ) ? [ $token ] : [],
					'exact'  => [ $token ],
				];
				continue;
			}
			$forms        = Woo_Search_Text::stem_forms( $token, $except );
			$groups[ $i ] = [
				'prefix' => Woo_Search_Text::minimal_prefixes( $forms ),
				'exact'  => $forms,
			];
		}

		// Synonymes d'expansion : alternative (OU) au sein du groupe concerné.
		foreach ( $this->get_compiled_synonyms() as $rule ) {
			if ( 'expand' !== $rule['type'] ) {
				continue;
			}
			$n = count( $rule['from_tokens'] );
			for ( $i = 0; $i + $n - 1 <= $last; $i++ ) {
				$match = true;
				for ( $j = 0; $j < $n; $j++ ) {
					$candidate = $tokens[ $i + $j ];
					if ( $candidate !== $rule['from_tokens'][ $j ] && ! in_array( $rule['from_tokens'][ $j ], Woo_Search_Text::stem_forms( $candidate, $except ), true ) ) {
						$match = false;
						break;
					}
				}
				if ( ! $match ) {
					continue;
				}

				if ( 1 === $n && isset( $groups[ $i ] ) ) {
					$groups[ $i ]['prefix'] = Woo_Search_Text::minimal_prefixes( array_merge( $groups[ $i ]['prefix'], [ $rule['to'] ] ) );
					$groups[ $i ]['exact'][] = $rule['to'];
				} elseif ( $n > 1 ) {
					$span = implode( ' ', array_slice( $tokens, $i, $n ) );
					for ( $j = 0; $j < $n; $j++ ) {
						unset( $groups[ $i + $j ] );
					}
					$groups[ $i ] = [
						'prefix' => [ $span, $rule['to'] ],
						'exact'  => [ $span, $rule['to'] ],
					];
				}
				break;
			}
		}

		ksort( $groups );

		return [
			'phrase'      => $phrase,
			'compact'     => strlen( $compact ) >= 2 ? $compact : '',
			'token_count' => count( $tokens ),
			'groups'      => array_slice( array_values( $groups ), 0, self::MAX_TOKENS ),
		];
	}

	/**
	 * Exécute la requête de scoring sur l'index.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @param string               $mode `all` ou `any`.
	 * @return array<int, array{product_id: string, skus: string}>
	 */
	private function run_query( array $plan, string $mode ): array {
		global $wpdb;

		$table       = Woo_Search_Installer::tables()['index'];
		$use_sku     = $this->opt( 'enable_sku_variations' );
		$use_terms   = $this->opt( 'search_in_terms' );
		$use_excerpt = $this->opt( 'search_in_excerpt' );
		$groups      = $plan['groups'];
		$compact     = (string) $plan['compact'];
		$phrase      = (string) $plan['phrase'];

		$like = static function ( string $column, string $pattern ) use ( $wpdb ): string {
			return $column . ' LIKE ' . $wpdb->prepare( '%s', $pattern );
		};

		// Début de mot (ou mot entier pour les nombres hors frappe en cours).
		$cond = static function ( string $column, array $group ) use ( $like, $wpdb ): string {
			$parts = [];
			foreach ( $group['prefix'] as $prefix ) {
				$parts[] = $like( $column, '% ' . $wpdb->esc_like( $prefix ) . '%' );
			}
			if ( empty( $group['prefix'] ) ) {
				foreach ( $group['exact'] as $word ) {
					$parts[] = $like( $column, '% ' . $wpdb->esc_like( $word ) . ' %' );
				}
			}
			return '(' . implode( ' OR ', $parts ) . ')';
		};

		// Mot entier.
		$word_cond = static function ( string $column, array $group ) use ( $like, $wpdb ): string {
			$parts = [];
			foreach ( array_unique( $group['exact'] ) as $word ) {
				$parts[] = $like( $column, '% ' . $wpdb->esc_like( $word ) . ' %' );
			}
			return '(' . implode( ' OR ', $parts ) . ')';
		};

		$group_matches = [];
		$score_parts   = [];

		foreach ( $groups as $group ) {
			$fields = [ $cond( 'i.title', $group ) ];
			if ( $use_sku ) {
				$fields[] = $cond( 'i.skus', $group );
			}
			if ( $use_terms ) {
				$fields[] = $cond( 'i.terms', $group );
			}
			if ( $use_excerpt ) {
				$fields[] = $cond( 'i.excerpt', $group );
			}
			$group_matches[] = '(' . implode( ' OR ', $fields ) . ')';

			$score_parts[] = '(CASE WHEN ' . $word_cond( 'i.title', $group ) . ' THEN 300 ELSE 0 END)';
			$score_parts[] = '(CASE WHEN ' . $cond( 'i.title', $group ) . ' THEN 150 ELSE 0 END)';
			if ( $use_terms ) {
				$score_parts[] = '(CASE WHEN ' . $cond( 'i.terms', $group ) . ' THEN 80 ELSE 0 END)';
			}
			if ( $use_sku ) {
				$score_parts[] = '(CASE WHEN ' . $cond( 'i.skus', $group ) . ' THEN 50 ELSE 0 END)';
			}
			if ( $use_excerpt ) {
				$score_parts[] = '(CASE WHEN ' . $cond( 'i.excerpt', $group ) . ' THEN 30 ELSE 0 END)';
			}
		}

		$sku_conditions = [];
		if ( $use_sku && '' !== $compact ) {
			$sku_exact        = $like( 'i.skus', '% ' . $wpdb->esc_like( $compact ) . ' %' );
			$sku_conditions[] = $sku_exact;
			$score_parts[]    = "(CASE WHEN {$sku_exact} THEN 100000 ELSE 0 END)";

			if ( strlen( $compact ) >= 3 && preg_match( '/\d/', $compact ) ) {
				$sku_prefix       = $like( 'i.skus', '% ' . $wpdb->esc_like( $compact ) . '%' );
				$sku_conditions[] = $sku_prefix;
				$score_parts[]    = "(CASE WHEN {$sku_prefix} THEN 20000 ELSE 0 END)";
			}
		}

		if ( '' !== $phrase ) {
			$score_parts[] = '(CASE WHEN i.title = ' . $wpdb->prepare( '%s', ' ' . $phrase . ' ' ) . ' THEN 15000 ELSE 0 END)';
			$score_parts[] = '(CASE WHEN ' . $like( 'i.title', ' ' . $wpdb->esc_like( $phrase ) . '%' ) . ' THEN 10000 ELSE 0 END)';
			if ( (int) $plan['token_count'] >= 2 ) {
				$score_parts[] = '(CASE WHEN ' . $like( 'i.title', '% ' . $wpdb->esc_like( $phrase ) . '%' ) . ' THEN 5000 ELSE 0 END)';
			}
		}

		if ( count( $groups ) >= 2 ) {
			$all_title = [];
			foreach ( $groups as $group ) {
				$all_title[] = $cond( 'i.title', $group );
			}
			$score_parts[] = '(CASE WHEN ' . implode( ' AND ', $all_title ) . ' THEN 2000 ELSE 0 END)';
		}

		$score_parts[] = "(CASE i.stock_status WHEN 'instock' THEN 20 WHEN 'onbackorder' THEN 10 ELSE 0 END)";

		$where_parts = [];
		if ( ! empty( $group_matches ) ) {
			$where_parts[] = '(' . implode( 'all' === $mode ? ' AND ' : ' OR ', $group_matches ) . ')';
		}
		$where_parts = array_merge( $where_parts, $sku_conditions );
		if ( empty( $where_parts ) ) {
			return [];
		}

		$where = '(' . implode( ' OR ', $where_parts ) . ')';
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$where .= " AND i.stock_status <> 'outofstock'";
		}

		$score = implode( ' + ', $score_parts );
		$limit = $this->max_ids();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments préparés individuellement.
		$sql = "SELECT i.product_id, i.skus, ({$score}) AS score FROM {$table} i WHERE {$where} ORDER BY score DESC, i.total_sales DESC, i.product_id DESC LIMIT {$limit}";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Retrouve le SKU (parent ou variation) qui a déclenché la correspondance.
	 */
	private function find_matching_sku( string $skus, string $compact ): string {
		if ( '' === $compact || ! $this->opt( 'enable_sku_variations' ) ) {
			return '';
		}
		$prefix_allowed = strlen( $compact ) >= 3 && preg_match( '/\d/', $compact );
		foreach ( Woo_Search_Text::tokens( trim( $skus ) ) as $sku ) {
			if ( $sku === $compact || ( $prefix_allowed && str_starts_with( $sku, $compact ) ) ) {
				return strtoupper( $sku );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Correction orthographique
	 * ------------------------------------------------------------------ */

	/**
	 * Corrige les jetons inconnus du catalogue (distance d'édition ≤ 2).
	 *
	 * @param string $folded Requête pliée.
	 * @return string Requête corrigée, ou chaîne vide si aucune correction.
	 */
	public function correct( string $folded ): string {
		$vocabulary = Woo_Search_Indexer::instance()->get_vocabulary();
		if ( empty( $vocabulary ) ) {
			return '';
		}

		$tokens  = Woo_Search_Text::tokens( $folded );
		$stop    = $this->get_stop_words();
		$except  = $this->get_stem_exceptions();
		$changed = false;

		foreach ( $tokens as $i => $token ) {
			if ( strlen( $token ) < 4 || ! ctype_alpha( $token ) || in_array( $token, $stop, true ) ) {
				continue;
			}
			$known = false;
			foreach ( Woo_Search_Text::stem_forms( $token, $except ) as $form ) {
				if ( Woo_Search_Text::is_known_prefix( $form, $vocabulary ) ) {
					$known = true;
					break;
				}
			}
			if ( $known ) {
				continue;
			}
			$fix = Woo_Search_Text::best_correction( $token, $vocabulary );
			if ( '' !== $fix && $fix !== $token ) {
				$tokens[ $i ] = $fix;
				$changed      = true;
			}
		}

		return $changed ? implode( ' ', $tokens ) : '';
	}

	/**
	 * Suggestion de correction pour une requête brute (onglet 0 résultat).
	 */
	public function suggest_correction( string $raw ): string {
		return $this->correct( $this->prepare_query( $raw )['folded'] );
	}

	/* ---------------------------------------------------------------------
	 * Catégories suggérées
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $plan Plan de requête.
	 * @return array<int, array{id: int, name: string, permalink: string, count: int}>
	 */
	private function match_categories( array $plan ): array {
		if ( empty( $plan['groups'] ) ) {
			return [];
		}

		$matches = [];
		foreach ( $this->get_categories() as $cat ) {
			$haystack = $cat['folded'];
			$ok       = true;
			foreach ( $plan['groups'] as $group ) {
				$found = false;
				foreach ( $group['prefix'] as $prefix ) {
					if ( str_contains( $haystack, ' ' . $prefix ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found && empty( $group['prefix'] ) ) {
					foreach ( $group['exact'] as $word ) {
						if ( str_contains( $haystack, ' ' . $word . ' ' ) ) {
							$found = true;
							break;
						}
					}
				}
				if ( ! $found ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$matches[] = [
					'id'        => $cat['id'],
					'name'      => $cat['name'],
					'permalink' => $cat['permalink'],
					'count'     => $cat['count'],
				];
			}
		}

		usort( $matches, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		return array_slice( $matches, 0, (int) apply_filters( 'woo_search_max_categories', 3 ) );
	}

	/**
	 * Catégories produits non vides (cache versionné 24 h).
	 *
	 * @return array<int, array{id: int, name: string, permalink: string, count: int, folded: string}>
	 */
	private function get_categories(): array {
		$gen    = (int) get_option( 'woo_search_cache_gen', 1 );
		$cached = get_transient( 'wsi_cats' );
		if ( is_array( $cached ) && ( $cached['gen'] ?? 0 ) === $gen && is_array( $cached['data'] ?? null ) ) {
			return $cached['data'];
		}

		$categories = [];
		$terms      = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		] );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term || in_array( $term->slug, [ 'non-classe', 'uncategorized' ], true ) ) {
					continue;
				}
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$categories[] = [
					'id'        => (int) $term->term_id,
					'name'      => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'permalink' => (string) $link,
					'count'     => (int) $term->count,
					'folded'    => Woo_Search_Indexer::pad( Woo_Search_Text::fold( $term->name ) ),
				];
			}
		}

		set_transient( 'wsi_cats', [ 'gen' => $gen, 'data' => $categories ], DAY_IN_SECONDS );
		return $categories;
	}

	/* ---------------------------------------------------------------------
	 * Synonymes
	 * ------------------------------------------------------------------ */

	/**
	 * Dictionnaire initial.
	 *
	 * @return array<int, array{from: string, to: string, type: string}>
	 */
	public static function get_base_default_synonyms(): array {
		return [
			[ 'from' => 'cadeau', 'to' => 'coffret', 'type' => 'expand' ],
			[ 'from' => 'promo', 'to' => 'promotion', 'type' => 'expand' ],
			[ 'from' => 'reduction', 'to' => 'promotion', 'type' => 'expand' ],
		];
	}

	/**
	 * Packs recommandés affichés dans l'admin.
	 *
	 * @return array<string, array<int, array{from: string, to: string, type: string}>>
	 */
	public static function get_recommended_packs(): array {
		/**
		 * Filtre : woo_search_recommended_packs
		 */
		return (array) apply_filters( 'woo_search_recommended_packs', [
			'Général & E-Commerce' => self::get_base_default_synonyms(),
		] );
	}

	/**
	 * Initialise le dictionnaire une seule fois (sur `init`, après chargement du thème
	 * pour que le filtre `woo_search_default_synonyms` des boutiques soit pris en compte).
	 */
	public function maybe_seed_synonyms(): void {
		if ( false !== get_option( 'woo_search_synonyms', false ) ) {
			return;
		}
		$defaults = (array) apply_filters( 'woo_search_default_synonyms', self::get_base_default_synonyms() );
		$this->save_synonyms( $defaults );
	}

	/**
	 * Règles normalisées, chacune dotée d'un identifiant stable.
	 *
	 * @return array<int, array{id: string, from: string, to: string, type: string}>
	 */
	public function get_synonyms(): array {
		$raw = get_option( 'woo_search_synonyms', [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$rules   = [];
		$changed = false;
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) || '' === trim( (string) ( $rule['from'] ?? '' ) ) || '' === trim( (string) ( $rule['to'] ?? '' ) ) ) {
				$changed = true;
				continue;
			}
			if ( empty( $rule['id'] ) ) {
				$rule['id'] = wp_generate_uuid4();
				$changed    = true;
			}
			$rules[] = [
				'id'   => (string) $rule['id'],
				'from' => (string) $rule['from'],
				'to'   => (string) $rule['to'],
				'type' => 'replace' === ( $rule['type'] ?? '' ) ? 'replace' : 'expand',
			];
		}

		if ( $changed ) {
			update_option( 'woo_search_synonyms', $rules );
		}

		return $rules;
	}

	/**
	 * @param array<int, array<string, mixed>> $rules Règles.
	 */
	public function save_synonyms( array $rules ): void {
		$clean = [];
		foreach ( $rules as $rule ) {
			$from = trim( sanitize_text_field( (string) ( $rule['from'] ?? '' ) ) );
			$to   = trim( sanitize_text_field( (string) ( $rule['to'] ?? '' ) ) );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$clean[] = [
				'id'   => ! empty( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : wp_generate_uuid4(),
				'from' => $from,
				'to'   => $to,
				'type' => 'replace' === ( $rule['type'] ?? '' ) ? 'replace' : 'expand',
			];
		}

		update_option( 'woo_search_synonyms', $clean );
		$this->compiled_synonyms = null;
		$this->memo              = [];
		Woo_Search_Indexer::bump_cache();
	}

	/**
	 * Règles pliées prêtes pour le moteur.
	 *
	 * @return array<int, array{from: string, from_tokens: array<int, string>, to: string, type: string}>
	 */
	private function get_compiled_synonyms(): array {
		if ( null !== $this->compiled_synonyms ) {
			return $this->compiled_synonyms;
		}

		$this->compiled_synonyms = [];
		$raw                     = get_option( 'woo_search_synonyms', [] );
		foreach ( is_array( $raw ) ? $raw : [] as $rule ) {
			$from = Woo_Search_Text::fold( (string) ( $rule['from'] ?? '' ) );
			$to   = Woo_Search_Text::fold( (string) ( $rule['to'] ?? '' ) );
			if ( '' === $from || '' === $to || $from === $to ) {
				continue;
			}
			$this->compiled_synonyms[] = [
				'from'        => $from,
				'from_tokens' => Woo_Search_Text::tokens( $from ),
				'to'          => $to,
				'type'        => 'replace' === ( $rule['type'] ?? '' ) ? 'replace' : 'expand',
			];
		}

		return $this->compiled_synonyms;
	}

	/**
	 * Applique les règles de remplacement (mots entiers) sur la requête pliée.
	 */
	private function apply_replace_synonyms( string $folded ): string {
		foreach ( $this->get_compiled_synonyms() as $rule ) {
			if ( 'replace' !== $rule['type'] ) {
				continue;
			}
			$folded = (string) preg_replace( '/(?<=^| )' . preg_quote( $rule['from'], '/' ) . '(?= |$)/', $rule['to'], $folded );
		}
		return trim( (string) preg_replace( '/ {2,}/', ' ', $folded ) );
	}

	/* ---------------------------------------------------------------------
	 * Listes filtrables
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, string>
	 */
	private function get_stop_words(): array {
		static $words = null;
		if ( null === $words ) {
			$words = array_map( 'strval', (array) apply_filters( 'woo_search_stop_words', Woo_Search_Text::STOP_WORDS ) );
		}
		return $words;
	}

	/**
	 * @return array<int, string>
	 */
	private function get_stem_exceptions(): array {
		static $words = null;
		if ( null === $words ) {
			$words = array_map( 'strval', (array) apply_filters( 'woo_search_stem_exceptions', Woo_Search_Text::STEM_EXCEPTIONS ) );
		}
		return $words;
	}

	/**
	 * Acronymes restaurés dans les titres reformatés (regex => remplacement).
	 *
	 * @return array<string, string>
	 */
	private function get_title_acronyms(): array {
		static $map = null;
		if ( null === $map ) {
			$list = (array) apply_filters( 'woo_search_title_acronyms', [ 'LED', 'USB', 'UV', 'PVC', 'TV', 'HD', 'TTC', 'HT', 'XL', 'XXL', 'XS', 'DIY' ] );
			$map  = [];
			foreach ( $list as $acronym ) {
				$acronym = (string) $acronym;
				if ( '' !== $acronym ) {
					$map[ '/\b' . preg_quote( mb_strtolower( $acronym, 'UTF-8' ), '/' ) . '\b/u' ] = $acronym;
				}
			}
		}
		return $map;
	}
}
