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

echo "\n{$count} tests, {$failures} échec(s).\n";
exit( $failures > 0 ? 1 : 0 );
