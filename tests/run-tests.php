<?php
/**
 * Tests unitaires CLI de la couche de normalisation (sans WordPress).
 *
 * Usage : php tests/run-tests.php
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

define( 'WOO_SEARCH_INTEL_TESTING', true );
require_once __DIR__ . '/../includes/class-search-text.php';

$failures = 0;
$count    = 0;

/**
 * Assertion d'égalité stricte.
 *
 * @param mixed  $expected Valeur attendue.
 * @param mixed  $actual   Valeur obtenue.
 * @param string $label    Libellé du test.
 */
function check( $expected, $actual, string $label ): void {
	global $failures, $count;
	$count++;
	if ( $expected !== $actual ) {
		$failures++;
		echo "✗ {$label}\n    attendu : " . var_export( $expected, true ) . "\n    obtenu  : " . var_export( $actual, true ) . "\n";
		return;
	}
	echo "✓ {$label}\n";
}

// --- Pliage ---------------------------------------------------------------
check( 'bache pe 4 x 5 m oeillets', Woo_Search_Text::fold( 'Bâche PE 4x5m - Œillets' ), 'fold : accents, ligature, dimensions' );
check( 'l huile d olive 500 g', Woo_Search_Text::fold( "L'huile d’olive 500g" ), 'fold : apostrophes et contenance' );
check( 'cafe the cereales', Woo_Search_Text::fold( 'Café &amp; <b>Thé</b> — Céréales' ), 'fold : entités HTML et balises' );
check( '2 5 x 3 m', Woo_Search_Text::fold( '2,5×3 m' ), 'fold : décimale et signe ×' );
check( '', Woo_Search_Text::fold( '' ), 'fold : chaîne vide' );
check( 'ab123x', Woo_Search_Text::compact( 'AB-123/x' ), 'compact : SKU' );
check( "l'huile d'olive", Woo_Search_Text::display_normalize( "  L'HUILE   d'olive " ), 'display_normalize' );

// --- Racinisation -----------------------------------------------------------
check( [ 'baches', 'bache', 'bach' ], Woo_Search_Text::stem_forms( 'baches' ), 'stem : baches' );
check( [ 'bache' ], Woo_Search_Text::minimal_prefixes( [ 'baches', 'bache' ] ), 'minimal_prefixes : baches/bache' );
check( [ 'noix' ], Woo_Search_Text::stem_forms( 'noix' ), 'stem : noix invariable (pas de « noi » → noir)' );
check( [ 'jus' ], Woo_Search_Text::stem_forms( 'jus' ), 'stem : jus (pas de « ju » → jute)' );
check( [ 'prix' ], Woo_Search_Text::stem_forms( 'prix' ), 'stem : prix invariable' );
check( [ 'bus' ], Woo_Search_Text::stem_forms( 'bus' ), 'stem : bus invariable' );
check( [ 'cactus' ], Woo_Search_Text::stem_forms( 'cactus' ), 'stem : cactus (-us conservé)' );
check( [ 'clous', 'clou' ], Woo_Search_Text::stem_forms( 'clous' ), 'stem : clous → clou' );
check( [ 'gateaux', 'gateau' ], Woo_Search_Text::stem_forms( 'gateaux' ), 'stem : gateaux → gateau' );
check( [ 'bocaux', 'bocal', 'bocail' ], Woo_Search_Text::stem_forms( 'bocaux' ), 'stem : bocaux → bocal' );
check( [ 'choux', 'chou' ], Woo_Search_Text::stem_forms( 'choux' ), 'stem : choux → chou' );
check( [ 'vertes', 'verte', 'vert' ], Woo_Search_Text::stem_forms( 'vertes' ), 'stem : vertes → vert' );
check( [ 'vert' ], Woo_Search_Text::minimal_prefixes( Woo_Search_Text::stem_forms( 'vertes' ) ), 'minimal_prefixes : vertes' );
check( [ '400' ], Woo_Search_Text::stem_forms( '400' ), 'stem : numérique inchangé' );
check( [ 'taux' ], Woo_Search_Text::stem_forms( 'taux' ), 'stem : taux invariable' );

// --- Titres ---------------------------------------------------------------
check( 'Bache pvc verte', Woo_Search_Text::format_title( 'BACHE PVC VERTE' ), 'format_title : sans acronymes' );
check( 'Bache PVC verte', Woo_Search_Text::format_title( 'BACHE PVC VERTE', [ '/\bpvc\b/u' => 'PVC' ] ), 'format_title : acronyme restauré' );
check( 'Bâche PVC Verte', Woo_Search_Text::format_title( 'Bâche PVC Verte' ), 'format_title : titre propre inchangé' );

// --- Correction orthographique ------------------------------------------------
$vocab = [
	't' => [ 'tondeuse' => 12, 'tendeur' => 30, 'terreau' => 8 ],
	'b' => [ 'bache' => 50, 'biscuit' => 4 ],
	'e' => [ 'engrais' => 9, 'electrique' => 6 ],
];
check( 'tondeuse', Woo_Search_Text::best_correction( 'tondeuze', $vocab ), 'correction : tondeuze → tondeuse' );
check( 'electrique', Woo_Search_Text::best_correction( 'electriqe', $vocab ), 'correction : electriqe → electrique' );
check( 'engrais', Woo_Search_Text::best_correction( 'angrais', $vocab ), 'correction : faute sur 1re lettre' );
check( '', Woo_Search_Text::best_correction( 'piscine', $vocab ), 'correction : aucun mot proche' );
check( true, Woo_Search_Text::is_known_prefix( 'tondeu', $vocab ), 'known_prefix : frappe en cours' );
check( false, Woo_Search_Text::is_known_prefix( 'tondeuz', $vocab ), 'known_prefix : faute' );

// --- Tracker : filtrage des requêtes d'affinage / facettes -------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$wp_filter = [];
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_filter;
		$wp_filter[ $tag ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		global $wp_filter;
		if ( isset( $wp_filter[ $tag ] ) ) {
			foreach ( $wp_filter[ $tag ] as $callback ) {
				$value = call_user_func( $callback, $value, ...$args );
			}
		}
		return $value;
	}
}

require_once __DIR__ . '/../includes/class-search-tracker.php';

// Tests des paramètres par défaut
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [] ), 'tracker : GET vide non ignoré' );
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture' ] ), 'tracker : recherche standard non ignorée' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'orderby' => 'price' ] ), 'tracker : ignore orderby par défaut' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'min_price' => '10' ] ), 'tracker : ignore min_price par défaut' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'max_price' => '50' ] ), 'tracker : ignore max_price par défaut' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'rating_filter' => '4' ] ), 'tracker : ignore rating_filter par défaut' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'filter_couleur' => 'bleu' ] ), 'tracker : ignore préfixe filter_' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'query_type_couleur' => 'or' ] ), 'tracker : ignore préfixe query_type_' );

// Clés custom AVANT l'application du filtre
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_cat' => 'interieur' ] ), 'tracker : cdc_cat non ignoré avant filtre' );
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_brand' => 'tollens' ] ), 'tracker : cdc_brand non ignoré avant filtre' );
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_stock' => '1' ] ), 'tracker : cdc_stock non ignoré avant filtre' );

// Enregistrement du filtre personnalisé par le thème (ex: Comptoir de Cambaie _cpd)
add_filter( 'woo_search_ignored_query_params', function ( array $keys ): array {
	return array_merge( $keys, [ 'cdc_cat', 'cdc_brand', 'cdc_stock', 'custom_facet_*' ] );
} );

// Clés custom APRÈS l'application du filtre
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_cat' => 'interieur' ] ), 'tracker : cdc_cat ignoré après filtre' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_brand' => 'tollens' ] ), 'tracker : cdc_brand ignoré après filtre' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'cdc_stock' => '1' ] ), 'tracker : cdc_stock ignoré après filtre' );
check( true, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'custom_facet_dimension' => '4x5' ] ), 'tracker : wildcard custom_facet_* ignoré après filtre' );
check( false, Woo_Search_Tracker::is_ignored_refinement_query( [ 's' => 'peinture', 'unrelated_param' => 'val' ] ), 'tracker : paramètre arbitraire non ignoré' );

// Vérification de la prise en compte de $_GET par défaut
$_GET = [ 's' => 'peinture', 'cdc_cat' => 'exterieur' ];
check( true, Woo_Search_Tracker::is_ignored_refinement_query(), 'tracker : détection automatique dans $_GET avec filtre' );
$_GET = [ 's' => 'peinture' ];
check( false, Woo_Search_Tracker::is_ignored_refinement_query(), 'tracker : détection automatique dans $_GET sans filtre' );

// Vérification de l'interception précoce de intercept_standard_search
if ( ! function_exists( 'is_admin' ) ) { function is_admin(): bool { return false; } }
if ( ! function_exists( 'is_search' ) ) { function is_search(): bool { return true; } }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax(): bool { return false; } }
if ( ! function_exists( 'is_paged' ) ) { function is_paged(): bool { return false; } }

$_GET = [ 's' => 'peinture', 'cdc_brand' => 'tollens' ];
$intercept_aborted_early = true;
try {
	// Si le filtre ne fonctionnait pas, l'exécution continuerait vers should_track() et échouerait
	// faute de mocks complexes (Woo_Search_Engine, wp_query, etc.).
	Woo_Search_Tracker::intercept_standard_search();
} catch ( \Throwable $e ) {
	$intercept_aborted_early = false;
}
check( true, $intercept_aborted_early, 'intercept_standard_search : arrêt précoce garanti avec paramètre filtré' );

echo "\n{$count} tests, {$failures} échec(s).\n";
exit( $failures > 0 ? 1 : 0 );
