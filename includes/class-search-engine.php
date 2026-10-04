<?php
/**
 * Moteur de Recherche E-Commerce Haute Performance
 *
 * Scoring SQL multi-paliers, lemmatisation française, synonymes intelligents,
 * normalisation modulaire par hooks, et cache transients.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Search_Engine {

	/**
	 * Instance unique (Singleton)
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Nom de la table de logs
	 *
	 * @var string
	 */
	private string $table_logs;

	/**
	 * Options du moteur
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Accès à l'instance unique
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
		global $wpdb;
		$this->table_logs = $wpdb->prefix . 'woo_search_logs';
		$this->load_options();
		$this->init_hooks();
	}

	/**
	 * Charge les réglages depuis wp_options
	 */
	public function load_options(): void {
		$defaults = [
			'min_chars'             => 2,
			'max_results'           => 6,
			'enable_sku_variations' => 1,
			'search_in_excerpt'     => 1,
			'show_images'           => 1,
			'show_prices'           => 1,
			'show_sku_badge'        => 1,
			'show_stock'            => 1,
			'enable_alerts'         => 0,
			'alert_email'           => get_option( 'admin_email' ),
			'alert_mode'            => 'weekly', // 'weekly' ou 'threshold'
			'alert_threshold'       => 5,
		];

		$saved         = get_option( 'woo_search_settings', [] );
		$this->options = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
	}

	/**
	 * Récupère une option de configuration
	 *
	 * @param string $key Clé de l'option.
	 * @param mixed  $default Valeur par défaut.
	 * @return mixed
	 */
	public function get_option( string $key, mixed $default = null ): mixed {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * Enregistre les hooks WordPress et WooCommerce
	 */
	private function init_hooks(): void {
		// Endpoints AJAX natifs WooCommerce (wc_ajax) pour performances optimales.
		add_action( 'wc_ajax_woo_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wc_ajax_nopriv_woo_live_search', [ $this, 'ajax_live_search' ] );

		// Endpoints standard admin-ajax.
		add_action( 'wp_ajax_woo_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wp_ajax_nopriv_woo_live_search', [ $this, 'ajax_live_search' ] );

		// Alias de transition fluide pour les thèmes existants (Bâches MFM & Jardin Naturel).
		add_action( 'wp_ajax_mfm_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wp_ajax_nopriv_mfm_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wc_ajax_mfm_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wc_ajax_nopriv_mfm_live_search', [ $this, 'ajax_live_search' ] );

		add_action( 'wp_ajax_jd_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wp_ajax_nopriv_jd_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wc_ajax_jd_live_search', [ $this, 'ajax_live_search' ] );
		add_action( 'wc_ajax_nopriv_jd_live_search', [ $this, 'ajax_live_search' ] );

		// Invalidation automatique des caches lors des modifications de produits.
		add_action( 'save_post_product', [ $this, 'clear_search_transients' ] );
		add_action( 'delete_post', [ $this, 'clear_search_transients' ] );
		add_action( 'woocommerce_update_product', [ $this, 'clear_search_transients' ] );

		// Vérification paresseuse de la table MySQL de logs.
		add_action( 'admin_init', [ $this, 'maybe_create_tables' ] );
	}

	/**
	 * Vérifie et recrée la table si absente
	 */
	public function maybe_create_tables(): void {
		global $wpdb;
		if ( get_transient( 'woo_search_table_verified' ) ) {
			return;
		}

		$table_name = $this->table_logs;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			woo_search_intel_activate();
		}
		set_transient( 'woo_search_table_verified', 1, DAY_IN_SECONDS );
	}

	/**
	 * Normalisation universelle de la requête de recherche
	 *
	 * Nettoie la casse UTF-8, les espaces et propose un hook découplé
	 * pour injecter les spécificités métiers des boutiques clientes (dimensions, contenances, etc.).
	 *
	 * @param string $query Terme brut saisi par l'internaute.
	 * @return string Terme normalisé.
	 */
	public function normalize_query( string $query ): string {
		$clean = trim( $query );
		if ( empty( $clean ) ) {
			return '';
		}

		// Passage en minuscules sécurisé avec encodage UTF-8.
		$clean = mb_strtolower( $clean, 'UTF-8' );

		// Nettoyage des espaces multiples et caractères invisibles.
		$clean = (string) preg_replace( '/\s+/u', ' ', $clean );

		/**
		 * Filtre : woo_search_normalize_query
		 * Permet à chaque boutique cliente d'injecter ses règles spécifiques (ex: dimensions "2x3" -> "2 x 3", contenances "500g" -> "500 g").
		 *
		 * @param string $clean Requête nettoyée standard.
		 * @param string $query Requête d'origine brute.
		 */
		$clean = (string) apply_filters( 'woo_search_normalize_query', $clean, $query );

		return trim( $clean );
	}

	/**
	 * Génère automatiquement les variantes singulier / pluriel pour le français
	 * et les correspondances d'accents fréquentes.
	 *
	 * @param string $word Mot individuel à lemmatiser.
	 * @return array<int, string> Liste des lemmes et variantes.
	 */
	public function get_word_lemmas( string $word ): array {
		$lemmas = [ $word ];
		$len    = mb_strlen( $word, 'UTF-8' );
		if ( $len < 3 ) {
			return $lemmas;
		}

		// 1. Terminaisons en -eaux / -eau (ex: gâteaux ↔ gâteau, rouleaux ↔ rouleau, morceaux ↔ morceau).
		if ( mb_substr( $word, -4, 4, 'UTF-8' ) === 'eaux' ) {
			$lemmas[] = mb_substr( $word, 0, $len - 1, 'UTF-8' );
		} elseif ( mb_substr( $word, -3, 3, 'UTF-8' ) === 'eau' ) {
			$lemmas[] = $word . 'x';
		}

		// 2. Terminaisons en -aux / -al / -ail (ex: bocaux ↔ bocal, métaux ↔ métal, travaux ↔ travail).
		elseif ( mb_substr( $word, -3, 3, 'UTF-8' ) === 'aux' ) {
			$stem     = mb_substr( $word, 0, $len - 3, 'UTF-8' );
			$lemmas[] = $stem . 'al';
			$lemmas[] = $stem . 'ail';
		} elseif ( mb_substr( $word, -2, 2, 'UTF-8' ) === 'al' ) {
			$stem     = mb_substr( $word, 0, $len - 2, 'UTF-8' );
			$lemmas[] = $stem . 'aux';
		}

		// 3. Terminaisons en -s (ex: baches ↔ bache, biscuits ↔ biscuit, graines ↔ graine, huiles ↔ huile).
		elseif ( mb_substr( $word, -1, 1, 'UTF-8' ) === 's' ) {
			$singular = mb_substr( $word, 0, $len - 1, 'UTF-8' );
			if ( mb_strlen( $singular, 'UTF-8' ) >= 2 ) {
				$lemmas[] = $singular;
			}
		} else {
			// Si singulier standard, dériver la variante plurielle avec 's'.
			$lemmas[] = $word . 's';
		}

		// 4. Terminaisons en -x (autre que aux / eaux).
		if ( mb_substr( $word, -1, 1, 'UTF-8' ) === 'x' && mb_substr( $word, -3, 3, 'UTF-8' ) !== 'aux' && mb_substr( $word, -4, 4, 'UTF-8' ) !== 'eaux' ) {
			$singular_x = mb_substr( $word, 0, $len - 1, 'UTF-8' );
			if ( mb_strlen( $singular_x, 'UTF-8' ) >= 2 ) {
				$lemmas[] = $singular_x;
			}
		}

		// 5. Variantes d'accents fréquentes du catalogue e-commerce français.
		$default_accent_map = [
			'the'       => [ 'thé', 'thes', 'thés' ],
			'thé'       => [ 'the', 'thés', 'thes' ],
			'thes'      => [ 'thés', 'the', 'thé' ],
			'thés'      => [ 'thes', 'thé', 'the' ],
			'cafe'      => [ 'café', 'cafes', 'cafés' ],
			'café'      => [ 'cafe', 'cafés', 'cafes' ],
			'pate'      => [ 'pâte', 'pates', 'pâtes' ],
			'pâte'      => [ 'pate', 'pâtes', 'pates' ],
			'pates'     => [ 'pâtes', 'pate', 'pâte' ],
			'pâtes'     => [ 'pates', 'pâte', 'pate' ],
			'bache'     => [ 'bâche', 'baches', 'bâches' ],
			'bâche'     => [ 'bache', 'bâches', 'baches' ],
			'baches'    => [ 'bâches', 'bache', 'bâche' ],
			'bâches'    => [ 'baches', 'bache', 'bâche' ],
			'oeillet'   => [ 'œillet', 'oeillets', 'œillets' ],
			'œillet'    => [ 'oeillet', 'œillets', 'oeillets' ],
			'oeillets'  => [ 'œillets', 'oeillet', 'œillet' ],
			'œillets'   => [ 'oeillets', 'oeillet', 'œillet' ],
			'cereale'   => [ 'céréale', 'cereales', 'céréales' ],
			'céréale'   => [ 'cereale', 'céréales', 'cereales' ],
			'legume'    => [ 'légume', 'legumes', 'légumes' ],
			'légume'    => [ 'legume', 'légumes', 'legumes' ],
			'ble'       => [ 'blé', 'bles', 'blés' ],
			'blé'       => [ 'ble', 'blés', 'bles' ],
			'epice'     => [ 'épice', 'epices', 'épices' ],
			'épice'     => [ 'epice', 'épices', 'epices' ],
			'biere'     => [ 'bière', 'bieres', 'bières' ],
			'bière'     => [ 'biere', 'bières', 'bieres' ],
			'gateau'    => [ 'gâteau', 'gateaux', 'gâteaux' ],
			'gâteau'    => [ 'gateau', 'gâteaux', 'gateaux' ],
			'seche'     => [ 'séché', 'seches', 'séchés', 'séchée', 'séchées' ],
			'séché'     => [ 'seche', 'séchés', 'seches', 'séchée' ],
			'cable'     => [ 'câble', 'cables', 'câbles' ],
			'câble'     => [ 'cable', 'câbles', 'cables' ],
			'elastique' => [ 'élastique', 'elastiques', 'élastiques' ],
			'élastique' => [ 'elastique', 'élastiques', 'elastiques' ],
		];

		/**
		 * Filtre : woo_search_accent_map
		 * Permet d'étendre la table de correspondances phonétiques et accentuées.
		 */
		$accent_map = apply_filters( 'woo_search_accent_map', $default_accent_map, $word );

		foreach ( $lemmas as $item ) {
			if ( isset( $accent_map[ $item ] ) && is_array( $accent_map[ $item ] ) ) {
				foreach ( $accent_map[ $item ] as $mapped ) {
					$lemmas[] = $mapped;
				}
			}
		}

		return array_values( array_unique( $lemmas ) );
	}

	/**
	 * Dictionnaire par défaut de base
	 *
	 * @return array<int, array{from: string, to: string, type: string}>
	 */
	public static function get_base_default_synonyms(): array {
		return [
			[ 'from' => 'vetement',  'to' => 'mode',      'type' => 'expand' ],
			[ 'from' => 'habits',    'to' => 'mode',      'type' => 'expand' ],
			[ 'from' => 'cadeau',    'to' => 'coffret',   'type' => 'expand' ],
			[ 'from' => 'promo',     'to' => 'soldes',    'type' => 'expand' ],
			[ 'from' => 'reduction', 'to' => 'promotion', 'type' => 'expand' ],
		];
	}

	/**
	 * Application du dictionnaire de synonymes
	 *
	 * @param string $query Terme normalisé.
	 * @return array{query: string, expanded: array<int, string>}
	 */
	public function apply_synonyms( string $query ): array {
		$synonyms = get_option( 'woo_search_synonyms', null );
		if ( $synonyms === null || ! is_array( $synonyms ) ) {
			/**
			 * Filtre : woo_search_default_synonyms
			 * Permet d'injecter un dictionnaire par défaut initial spécifique à un secteur ou un site client.
			 */
			$synonyms = apply_filters( 'woo_search_default_synonyms', self::get_base_default_synonyms() );
			update_option( 'woo_search_synonyms', $synonyms );
		}

		$clean_query    = mb_strtolower( trim( $query ), 'UTF-8' );
		$expanded_terms = [];

		foreach ( $synonyms as $rule ) {
			if ( empty( $rule['from'] ) || empty( $rule['to'] ) ) {
				continue;
			}

			$from = mb_strtolower( trim( (string) $rule['from'] ), 'UTF-8' );
			$to   = mb_strtolower( trim( (string) $rule['to'] ), 'UTF-8' );
			$type = $rule['type'] ?? 'replace';

			// Correspondance mot entier (\b) insensible à la casse.
			$pattern = '/\b' . preg_quote( $from, '/' ) . '\b/ui';
			if ( preg_match( $pattern, $clean_query ) ) {
				if ( 'replace' === $type ) {
					$clean_query = (string) preg_replace( $pattern, $to, $clean_query );
				} elseif ( 'expand' === $type ) {
					$expanded_terms[] = $to;
				}
			}
		}

		return [
			'query'    => trim( $clean_query ),
			'expanded' => array_values( array_unique( $expanded_terms ) ),
		];
	}

	/**
	 * Formate proprement les titres de produits issus de l'ERP ou mal typographiés (tout majuscules)
	 *
	 * @param string $title Titre brut.
	 * @return string Titre harmonisé.
	 */
	public static function format_product_title( string $title ): string {
		if ( empty( $title ) ) {
			return $title;
		}

		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = trim( $title );

		// Vérification si le titre est criard (majuscules > 60%).
		$upper_count = preg_match_all( '/[A-ZÀ-Ý]/u', $title );
		$lower_count = preg_match_all( '/[a-zà-ÿ]/u', $title );
		$total_alpha = ( false !== $upper_count ? $upper_count : 0 ) + ( false !== $lower_count ? $lower_count : 0 );

		if ( $total_alpha >= 4 && ( $upper_count / $total_alpha ) > 0.60 ) {
			$lower = mb_strtolower( $title, 'UTF-8' );
			$first = mb_substr( $lower, 0, 1, 'UTF-8' );
			$rest  = mb_substr( $lower, 1, null, 'UTF-8' );
			$title = mb_strtoupper( $first, 'UTF-8' ) . $rest;

			$acronyms = [
				'/\bpe\b/ui'   => 'PE',
				'/\bpvc\b/ui'  => 'PVC',
				'/\bepdm\b/ui' => 'EPDM',
				'/\buv\b/ui'   => 'UV',
				'/\bttc\b/ui'  => 'TTC',
				'/\bht\b/ui'   => 'HT',
				'/\bbio\b/ui'  => 'Bio',
			];
			$title = (string) preg_replace( array_keys( $acronyms ), array_values( $acronyms ), $title );
		}

		return $title;
	}

	/**
	 * Recherche de candidats dans le catalogue WooCommerce avec scoring multi-paliers
	 *
	 * Paliers de pertinence :
	 * Tier 0 : Correspondance exacte SKU (+100 000)
	 * Tier 1 : Titre commençant par la requête exacte (+10 000)
	 * Tier 2 : Titre contenant l'expression exacte (+5 000)
	 * Tier 3 : Titre contenant TOUS les mots significatifs (+2 000)
	 * Tier 4 : Bonus par mot distinct présent dans le titre (+150 / mot)
	 * Tier 5 : Bonus par mot dans l'extrait / description (+30 / mot)
	 * Tier 6 : Bonus produit en stock (+20)
	 *
	 * @param string $raw_query Terme saisi par l'utilisateur.
	 * @param int    $limit     Nombre maximum de candidats à récupérer.
	 * @return array{clean_query: string, candidates: array<int, array{score: int, total_sales: int, sku_info: ?array{sku: string, is_variation: bool}}>}
	 */
	public function query_catalog_candidates( string $raw_query, int $limit = 50 ): array {
		global $wpdb;

		$clean_query = $this->normalize_query( $raw_query );
		$min_chars   = absint( $this->get_option( 'min_chars', 2 ) );

		if ( mb_strlen( $clean_query, 'UTF-8' ) < $min_chars ) {
			return [
				'clean_query' => $clean_query,
				'candidates'  => [],
			];
		}

		// Application des synonymes.
		$synonym_result = $this->apply_synonyms( $clean_query );
		$search_term    = $synonym_result['query'];
		$expanded_terms = $synonym_result['expanded'];

		// Version avec séparateurs typographiques remplacés par des espaces.
		$search_term_spaced = (string) preg_replace( '/[\-_’\'\/]+/u', ' ', $search_term );
		$search_term_spaced = (string) preg_replace( '/\s+/u', ' ', trim( $search_term_spaced ) );

		// Mots distincts (longueur >= 2 caractères).
		$raw_words = array_filter(
			explode( ' ', $search_term_spaced ),
			fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 2
		);
		$raw_words = array_values( array_unique( $raw_words ) );

		// Mots de liaison français ignorés pour l'exigence "contient tous les mots".
		$stop_words        = [ 'de', 'du', 'des', 'le', 'la', 'les', 'un', 'une', 'pour', 'par', 'avec', 'sans', 'dans', 'et', 'en', 'au', 'aux' ];
		$significant_words = [];
		foreach ( $raw_words as $rw ) {
			if ( ! in_array( $rw, $stop_words, true ) ) {
				$significant_words[] = $rw;
			}
		}
		if ( empty( $significant_words ) ) {
			$significant_words = $raw_words;
		}

		// Variantes et lemmes par mot.
		$words_lemmas = [];
		foreach ( $raw_words as $w ) {
			$words_lemmas[ $w ] = $this->get_word_lemmas( $w );
		}

		// 1. RECHERCHE PAR SKU (Priorité Tier 0 = Score 100 000).
		$matched_by_sku = [];
		if ( $this->get_option( 'enable_sku_variations', 1 ) ) {
			$sku_clean = str_replace( ' ', '', $search_term );
			$sku_like  = '%' . $wpdb->esc_like( $sku_clean ) . '%';

			$sku_query = "
				SELECT 
					IF(p.post_type = 'product_variation', p.post_parent, p.ID) AS product_id,
					pm.meta_value AS matched_sku,
					IF(p.post_type = 'product_variation', 1, 0) AS is_variation
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = '_sku' 
				AND pm.meta_value != ''
				AND pm.meta_value LIKE %s
				AND p.post_status = 'publish'
				LIMIT 50
			";

			$sku_results = $wpdb->get_results( $wpdb->prepare( $sku_query, $sku_like ) );
			if ( ! empty( $sku_results ) ) {
				foreach ( $sku_results as $row ) {
					$pid = (int) $row->product_id;
					if ( ! isset( $matched_by_sku[ $pid ] ) ) {
						$matched_by_sku[ $pid ] = [
							'sku'          => (string) $row->matched_sku,
							'is_variation' => (bool) $row->is_variation,
						];
					}
				}
			}
		}

		// 2. EXCLUSION DES PRODUITS MASQUÉS DU CATALOGUE.
		$visibility_term = get_term_by( 'name', 'exclude-from-search', 'product_visibility' );
		$exclude_tax_sql = '';
		if ( $visibility_term && ! is_wp_error( $visibility_term ) ) {
			$exclude_tax_sql = "AND p.ID NOT IN (
				SELECT object_id FROM {$wpdb->term_relationships} 
				WHERE term_taxonomy_id = " . (int) $visibility_term->term_taxonomy_id . '
			)';
		}

		// 3. CONSTRUCTION DU SCORING SQL MULTI-PALIERS.
		$prefix_exact  = $wpdb->esc_like( $search_term ) . '%';
		$prefix_spaced = $wpdb->esc_like( $search_term_spaced ) . '%';
		$phrase_exact  = '%' . $wpdb->esc_like( $search_term ) . '%';
		$phrase_spaced = '%' . $wpdb->esc_like( $search_term_spaced ) . '%';

		$title_normalized_sql = "REPLACE(REPLACE(p.post_title, '-', ' '), '\'', ' ')";

		// Tier 1 : Titre commençant par la requête exacte (+10 000).
		$prefix_case = '(CASE WHEN p.post_title LIKE ' . $wpdb->prepare( '%s', $prefix_exact ) . " OR {$title_normalized_sql} LIKE " . $wpdb->prepare( '%s', $prefix_spaced ) . ' THEN 10000 ELSE 0 END)';

		// Tier 2 : Titre contenant la phrase exacte (+5 000).
		$phrase_case = '(CASE WHEN p.post_title LIKE ' . $wpdb->prepare( '%s', $phrase_exact ) . " OR {$title_normalized_sql} LIKE " . $wpdb->prepare( '%s', $phrase_spaced ) . ' THEN 5000 ELSE 0 END)';

		// Tier 3 : Titre contenant TOUS les mots significatifs (+2 000).
		$all_words_sql_checks  = [];
		$word_title_case_parts = [];
		$where_title_parts     = [
			'p.post_title LIKE ' . $wpdb->prepare( '%s', $phrase_exact ),
			"{$title_normalized_sql} LIKE " . $wpdb->prepare( '%s', $phrase_spaced ),
		];

		foreach ( $significant_words as $sw ) {
			$lemmas    = $words_lemmas[ $sw ] ?? [ $sw ];
			$lemmas_or = [];
			foreach ( $lemmas as $lem ) {
				$lem_like            = '%' . $wpdb->esc_like( $lem ) . '%';
				$lem_sql             = "{$title_normalized_sql} LIKE " . $wpdb->prepare( '%s', $lem_like );
				$lemmas_or[]         = $lem_sql;
				$where_title_parts[] = $lem_sql;
			}
			$sw_check                = '(' . implode( ' OR ', $lemmas_or ) . ')';
			$all_words_sql_checks[]  = $sw_check;
			$word_title_case_parts[] = "(CASE WHEN {$sw_check} THEN 150 ELSE 0 END)";
		}

		// Prise en compte des termes étendus (synonymes de type 'expand').
		foreach ( $expanded_terms as $ext ) {
			$ext_like            = '%' . $wpdb->esc_like( $ext ) . '%';
			$where_title_parts[] = 'p.post_title LIKE ' . $wpdb->prepare( '%s', $ext_like );
		}

		$all_words_case  = ! empty( $all_words_sql_checks ) ? '(CASE WHEN (' . implode( ' AND ', $all_words_sql_checks ) . ') THEN 2000 ELSE 0 END)' : '0';
		$word_title_case = ! empty( $word_title_case_parts ) ? implode( ' + ', $word_title_case_parts ) : '0';

		// Tier 5 : Bonus par mot dans l'extrait (+30).
		$excerpt_case_parts  = [];
		$where_excerpt_parts = [];
		if ( $this->get_option( 'search_in_excerpt', 1 ) ) {
			$where_excerpt_parts[] = 'p.post_excerpt LIKE ' . $wpdb->prepare( '%s', $phrase_exact );
			foreach ( $significant_words as $sw ) {
				$sw_like               = '%' . $wpdb->esc_like( $sw ) . '%';
				$excerpt_case_parts[]  = '(CASE WHEN p.post_excerpt LIKE ' . $wpdb->prepare( '%s', $sw_like ) . ' THEN 30 ELSE 0 END)';
				$where_excerpt_parts[] = 'p.post_excerpt LIKE ' . $wpdb->prepare( '%s', $sw_like );
			}
		}
		$excerpt_case = ! empty( $excerpt_case_parts ) ? implode( ' + ', $excerpt_case_parts ) : '0';

		// Tier 6 : Bonus produit en stock (+20).
		$stock_case = "(CASE WHEN COALESCE(lookup.stock_status, 'instock') = 'instock' THEN 20 ELSE 0 END)";

		// Score SQL global de pertinence.
		$score_sql = "({$prefix_case} + {$phrase_case} + {$all_words_case} + {$word_title_case} + {$excerpt_case} + {$stock_case})";

		$where_title_sql   = '(' . implode( ' OR ', array_unique( $where_title_parts ) ) . ')';
		$where_excerpt_sql = ! empty( $where_excerpt_parts ) ? ' OR (' . implode( ' OR ', array_unique( $where_excerpt_parts ) ) . ')' : '';

		$limit_fetch = max( 60, $limit * 2 );

		$sql = "
			SELECT DISTINCT p.ID, p.post_title,
				COALESCE(lookup.stock_status, 'instock') AS stock_status,
				COALESCE(lookup.total_sales, 0) AS total_sales,
				{$score_sql} AS relevance_score
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup lookup ON p.ID = lookup.product_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			{$exclude_tax_sql}
			AND (
				{$where_title_sql}
				{$where_excerpt_sql}
			)
			ORDER BY 
				relevance_score DESC,
				(COALESCE(lookup.stock_status, 'instock') = 'instock') DESC,
				COALESCE(lookup.total_sales, 0) DESC,
				p.ID DESC
			LIMIT {$limit_fetch}
		";

		$db_results         = $wpdb->get_results( $sql );
		$product_candidates = [];

		// Incorpore d'abord les correspondances SKU directes (Score 100 000).
		foreach ( $matched_by_sku as $pid => $sku_info ) {
			$product_candidates[ $pid ] = [
				'score'       => 100000,
				'total_sales' => 0,
				'sku_info'    => $sku_info,
			];
		}

		// Incorpore les correspondances SQL par pertinence.
		if ( ! empty( $db_results ) ) {
			foreach ( $db_results as $row ) {
				$pid   = (int) $row->ID;
				$score = (int) $row->relevance_score;
				$sales = isset( $row->total_sales ) ? (int) $row->total_sales : 0;

				if ( ! isset( $product_candidates[ $pid ] ) ) {
					$product_candidates[ $pid ] = [
						'score'       => $score,
						'total_sales' => $sales,
						'sku_info'    => null,
					];
				} else {
					$product_candidates[ $pid ]['score']      += $score;
					$product_candidates[ $pid ]['total_sales'] = max( $product_candidates[ $pid ]['total_sales'], $sales );
				}
			}
		}

		// Tri décroissant strict par score, puis total des ventes.
		uasort( $product_candidates, function ( array $a, array $b ): int {
			if ( $b['score'] !== $a['score'] ) {
				return $b['score'] - $a['score'];
			}
			return ( $b['total_sales'] ?? 0 ) - ( $a['total_sales'] ?? 0 );
		} );

		return [
			'clean_query' => $clean_query,
			'candidates'  => $product_candidates,
		];
	}

	/**
	 * Recherche de produits optimisée pour le live search
	 *
	 * @param string   $raw_query Terme saisi par l'utilisateur.
	 * @param int|null $limit Nombre max de résultats (défaut : option admin).
	 * @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, total_count: int}
	 */
	public function search_products( string $raw_query, ?int $limit = null ): array {
		if ( $limit === null ) {
			$limit = absint( $this->get_option( 'max_results', 6 ) );
		}

		$clean_query = $this->normalize_query( $raw_query );
		$min_chars   = absint( $this->get_option( 'min_chars', 2 ) );

		if ( mb_strlen( $clean_query, 'UTF-8' ) < $min_chars ) {
			return [
				'products'    => [],
				'categories'  => [],
				'total_count' => 0,
			];
		}

		// Cache Transients de 12 heures.
		$cache_key = 'woo_srch_' . md5( $clean_query . '_' . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Interrogation du moteur de pertinence.
		$search_data        = $this->query_catalog_candidates( $raw_query, $limit );
		$product_candidates = $search_data['candidates'];
		$selected_ids       = array_slice( array_keys( $product_candidates ), 0, $limit );

		// Pré-chargement des posts et postmeta en 1 requête pour éviter le N+1.
		if ( ! empty( $selected_ids ) ) {
			_prime_post_caches( $selected_ids, true, true );
		}

		$formatted_products = [];
		foreach ( $selected_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}

			$clean_title  = self::format_product_title( $product->get_name() );
			$image_id     = $product->get_image_id();
			$image_url    = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : (string) wc_placeholder_img_src( 'thumbnail' );
			$display_sku  = (string) $product->get_sku();
			$is_sku_match = false;

			if ( ! empty( $product_candidates[ $pid ]['sku_info'] ) ) {
				$display_sku  = $product_candidates[ $pid ]['sku_info']['sku'];
				$is_sku_match = true;
			}

			$formatted_products[] = [
				'id'           => $pid,
				'title'        => $clean_title,
				'permalink'    => $product->get_permalink(),
				'image_url'    => $image_url,
				'price_html'   => $this->get_option( 'show_prices', 1 ) ? $product->get_price_html() : '',
				'sku'          => $this->get_option( 'show_sku_badge', 1 ) ? $display_sku : '',
				'is_sku_match' => $is_sku_match,
				'in_stock'     => $product->is_in_stock(),
			];
		}

		// Recherche de catégories correspondantes suggérées avec mémoïsation en cache 24h.
		$matching_categories = [];
		$all_categories      = get_transient( 'woo_search_all_cats' );
		if ( false === $all_categories || ! is_array( $all_categories ) ) {
			$cat_raw = get_terms( [
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			] );

			$all_categories = [];
			if ( ! empty( $cat_raw ) && ! is_wp_error( $cat_raw ) ) {
				foreach ( $cat_raw as $term ) {
					if ( in_array( $term->slug, [ 'non-classe', 'uncategorized' ], true ) ) {
						continue;
					}
					$all_categories[] = [
						'id'        => $term->term_id,
						'name'      => $term->name,
						'permalink' => get_term_link( $term ),
						'count'     => $term->count,
						'search'    => mb_strtolower( $term->name, 'UTF-8' ) . ' ' . $term->slug,
					];
				}
			}
			set_transient( 'woo_search_all_cats', $all_categories, DAY_IN_SECONDS );
		}

		if ( ! empty( $all_categories ) ) {
			$cat_found = 0;
			foreach ( $all_categories as $cat ) {
				if ( mb_strpos( (string) $cat['search'], $clean_query ) !== false ) {
					$matching_categories[] = [
						'id'        => $cat['id'],
						'name'      => $cat['name'],
						'permalink' => $cat['permalink'],
						'count'     => $cat['count'],
					];
					$cat_found++;
					if ( $cat_found >= 3 ) {
						break;
					}
				}
			}
		}

		$result = [
			'products'    => $formatted_products,
			'categories'  => $matching_categories,
			'total_count' => count( $formatted_products ),
		];

		set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Retourne les IDs de produits correspondants à une recherche (pour filtrer la boucle boutique)
	 *
	 * @param string $raw_query Terme recherché.
	 * @param int    $limit Nombre max d'identifiants (défaut : 150).
	 * @return array<int, int>
	 */
	public function get_matching_product_ids( string $raw_query, int $limit = 150 ): array {
		$clean_query = $this->normalize_query( $raw_query );
		$min_chars   = absint( $this->get_option( 'min_chars', 2 ) );

		if ( mb_strlen( $clean_query, 'UTF-8' ) < $min_chars ) {
			return [];
		}

		$cache_key = 'woo_srch_ids_' . md5( $clean_query . '_' . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$search_data        = $this->query_catalog_candidates( $raw_query, $limit );
		$product_candidates = $search_data['candidates'];
		$matching_ids       = array_slice( array_keys( $product_candidates ), 0, $limit );

		set_transient( $cache_key, $matching_ids, 12 * HOUR_IN_SECONDS );
		return $matching_ids;
	}

	/**
	 * Point d'entrée AJAX pour la recherche en direct
	 */
	public function ajax_live_search(): void {
		$raw_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( empty( $raw_query ) && isset( $_GET['term'] ) ) {
			$raw_query = sanitize_text_field( wp_unslash( $_GET['term'] ) );
		}
		$raw_query = trim( $raw_query );

		if ( empty( $raw_query ) ) {
			wp_send_json_success( [
				'products'    => [],
				'categories'  => [],
				'total_count' => 0,
			] );
		}

		// Cache HTTP public de 10 minutes pour les visiteurs non connectés.
		if ( ! is_user_logged_in() && ! headers_sent() ) {
			header( 'Cache-Control: public, max-age=600' );
		}

		$results = $this->search_products( $raw_query );

		// Construction de l'URL vers la page boutique/catalogue complète.
		$shop_page_url         = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/boutique/' );
		$results['see_all_url'] = add_query_arg( 's', rawurlencode( $raw_query ), $shop_page_url );

		// Journalisation asynchrone différée au shutdown pour préserver le temps de réponse.
		$total_count = (int) $results['total_count'];
		add_action( 'shutdown', function () use ( $raw_query, $total_count ): void {
			Woo_Search_Tracker::log_search( $raw_query, $total_count );
		} );

		wp_send_json_success( $results );
	}

	/**
	 * Purge tous les transients de recherche
	 */
	public function clear_search_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woo_srch_%' OR option_name LIKE '_transient_timeout_woo_srch_%'" );
		delete_transient( 'woo_search_all_cats' );
	}
}
