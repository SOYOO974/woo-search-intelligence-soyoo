<?php
/**
 * Couche de normalisation textuelle (sans dépendance WordPress)
 *
 * Utilisée à l'identique par l'indexeur et par le moteur de requête :
 * ce qui est indexé et ce qui est cherché passent par le même pliage
 * (minuscules, accents, ligatures, ponctuation, frontières chiffres/lettres).
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'WOO_SEARCH_INTEL_TESTING' ) ) {
	exit;
}

final class Woo_Search_Text {

	/**
	 * Table de translittération (appliquée après mb_strtolower).
	 */
	private const FOLD_MAP = [
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
		'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
		'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
		'œ' => 'oe', 'ß' => 'ss', 'š' => 's', 'ś' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
		'ý' => 'y', 'ÿ' => 'y', 'ł' => 'l', 'ř' => 'r', 'ť' => 't', 'ď' => 'd',
		'×' => ' x ', '²' => '2', '³' => '3', '½' => ' 1 2 ', '°' => ' ',
	];

	/**
	 * Mots vides français (non significatifs pour l'exigence « tous les mots »).
	 */
	public const STOP_WORDS = [
		'a', 'au', 'aux', 'avec', 'ce', 'ces', 'd', 'dans', 'de', 'des', 'du', 'en', 'et',
		'l', 'la', 'le', 'les', 'leur', 'ou', 'par', 'pour', 'sans', 'se', 'sur', 'un', 'une',
		'the', 'of', 'and', 'for', 'with',
	];

	/**
	 * Mots invariables terminés par -s / -x dont la réduction créerait du bruit
	 * (ex. « noix » → « noi » matcherait « noir »).
	 */
	public const STEM_EXCEPTIONS = [
		'abus', 'ananas', 'anis', 'avis', 'biais', 'bois', 'bras', 'brebis', 'bus', 'cassis', 'chassis',
		'colis', 'concours', 'corps', 'cours', 'croix', 'dais', 'dessous', 'dessus', 'deux', 'doux', 'engrais',
		'fois', 'frais', 'gaz', 'gros', 'houx', 'jus', 'lilas', 'mais', 'marais', 'matelas', 'mois',
		'noix', 'palais', 'paradis', 'parcours', 'pas', 'pois', 'poids', 'prix', 'puits', 'radis', 'relais',
		'repas', 'roux', 'souris', 'tapis', 'temps', 'tournevis', 'toux', 'trois', 'velours', 'vernis', 'vis',
		'creux', 'mieux', 'vieux', 'faux', 'taux', 'choix', 'voix', 'paix', 'perdrix',
	];

	/**
	 * Table de pliage effective (FOLD_MAP + filtre `woo_search_accent_map`), résolue une fois.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $fold_map = null;

	/**
	 * Table de translittération effective. Le filtre `woo_search_accent_map` permet d'ajouter
	 * des équivalences (ex. caractères d'une langue étrangère) ; toute modification impose
	 * une reconstruction de l'index pour rester cohérente avec les requêtes.
	 *
	 * @return array<string, string>
	 */
	public static function fold_map(): array {
		if ( null === self::$fold_map ) {
			$map = self::FOLD_MAP;
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'woo_search_accent_map', $map );
				if ( is_array( $filtered ) ) {
					$map = array_map( 'strval', $filtered );
				}
				// Avant le chargement du thème, les filtres de functions.php ne sont pas encore branchés.
				if ( ! did_action( 'after_setup_theme' ) ) {
					return $map;
				}
			}
			self::$fold_map = $map;
		}
		return self::$fold_map;
	}

	/**
	 * Pliage complet d'un texte libre vers l'alphabet [a-z0-9 ].
	 *
	 * « Bâche PE 4x5m - Œillets » → « bache pe 4 x 5 m oeillets »
	 *
	 * @param string $text Texte brut (HTML toléré).
	 * @return string Texte plié, mots séparés par un espace simple.
	 */
	public static function fold( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = strtr( $text, self::fold_map() );

		// Tout ce qui n'est pas alphanumérique ASCII devient séparateur.
		$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );

		// Frontières chiffres / lettres : « 4x5m » → « 4 x 5 m », « 500g » → « 500 g ».
		$text = (string) preg_replace( '/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', ' ', $text );

		$text = (string) preg_replace( '/ {2,}/', ' ', $text );

		return trim( $text );
	}

	/**
	 * Forme compacte (sans séparateur) utilisée pour les SKU / EAN.
	 *
	 * « AB-123/x » → « ab123x »
	 */
	public static function compact( string $text ): string {
		return str_replace( ' ', '', self::fold( $text ) );
	}

	/**
	 * Découpe un texte plié en jetons.
	 *
	 * @return array<int, string>
	 */
	public static function tokens( string $folded ): array {
		if ( '' === $folded ) {
			return [];
		}
		return array_values( array_filter( explode( ' ', $folded ), static fn( string $t ): bool => '' !== $t ) );
	}

	/**
	 * Indique si un jeton est purement numérique.
	 */
	public static function is_numeric_token( string $token ): bool {
		return '' !== $token && ctype_digit( $token );
	}

	/**
	 * Normalisation « lisible » d'une requête pour l'affichage et le regroupement des statistiques
	 * (casse et espaces uniquement, accents conservés).
	 */
	public static function display_normalize( string $text ): string {
		$text = trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return mb_substr( trim( $text ), 0, 190, 'UTF-8' );
	}

	/**
	 * Formes réduites d'un jeton (pluriel → singulier, féminin → masculin).
	 *
	 * Toutes les formes retournées incluent le jeton d'origine en première position.
	 * La recherche se faisant par préfixe de mot, une forme réduite qui est un préfixe
	 * de l'original ne fait qu'élargir le rappel ; d'où la liste d'exceptions pour
	 * les mots invariables dont le radical est le préfixe d'autres mots courants.
	 *
	 * @param string             $token      Jeton plié.
	 * @param array<int, string> $exceptions Mots à ne jamais réduire.
	 * @return array<int, string>
	 */
	public static function stem_forms( string $token, array $exceptions = self::STEM_EXCEPTIONS ): array {
		$forms = [ $token ];
		$len   = strlen( $token );

		if ( $len < 4 || self::is_numeric_token( $token ) || in_array( $token, $exceptions, true ) ) {
			return $forms;
		}

		$base = $token;

		if ( str_ends_with( $token, 'eaux' ) ) {
			// gateaux → gateau, rouleaux → rouleau.
			$base = substr( $token, 0, -1 );
		} elseif ( str_ends_with( $token, 'aux' ) && $len >= 5 ) {
			// bocaux → bocal, travaux → travail, chevaux → cheval.
			$stem    = substr( $token, 0, -3 );
			$forms[] = $stem . 'al';
			$forms[] = $stem . 'ail';
			$base    = $stem . 'al';
		} elseif ( str_ends_with( $token, 'eux' ) || str_ends_with( $token, 'oux' ) ) {
			// jeux → jeu, choux → chou, bijoux → bijou.
			$base = substr( $token, 0, -1 );
		} elseif ( str_ends_with( $token, 's' ) && ! str_ends_with( $token, 'ss' ) && ( ! str_ends_with( $token, 'us' ) || str_ends_with( $token, 'ous' ) ) ) {
			// baches → bache, clous → clou ; cactus / virus inchangés.
			$base = substr( $token, 0, -1 );
		}

		if ( $base !== $token && strlen( $base ) >= 3 ) {
			$forms[] = $base;
		}

		// Féminin : « vertes » → « verte » → « vert », « sechee » → « seche ».
		if ( strlen( $base ) >= 5 && str_ends_with( $base, 'e' ) ) {
			$forms[] = substr( $base, 0, -1 );
		}

		return array_values( array_unique( $forms ) );
	}

	/**
	 * Réduit une liste de formes à l'ensemble minimal de préfixes
	 * (« vertes », « verte », « vert » → « vert »).
	 *
	 * @param array<int, string> $forms Formes.
	 * @return array<int, string>
	 */
	public static function minimal_prefixes( array $forms ): array {
		$forms = array_values( array_unique( array_filter( $forms, static fn( $f ): bool => '' !== $f ) ) );
		usort( $forms, static fn( string $a, string $b ): int => strlen( $a ) <=> strlen( $b ) );

		$kept = [];
		foreach ( $forms as $form ) {
			$covered = false;
			foreach ( $kept as $prefix ) {
				if ( str_starts_with( $form, $prefix ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$kept[] = $form;
			}
		}
		return $kept;
	}

	/**
	 * Mise en forme des titres criards (majuscules > 60 %) issus d'un ERP.
	 *
	 * @param string                $title    Titre brut.
	 * @param array<string, string> $acronyms Acronymes à restaurer (regex => remplacement).
	 */
	public static function format_title( string $title, array $acronyms = [] ): string {
		$title = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $title ) {
			return $title;
		}

		$upper = (int) preg_match_all( '/\p{Lu}/u', $title );
		$lower = (int) preg_match_all( '/\p{Ll}/u', $title );
		$total = $upper + $lower;

		if ( $total < 4 || ( $upper / $total ) <= 0.60 ) {
			return $title;
		}

		$lowered = mb_strtolower( $title, 'UTF-8' );
		$title   = mb_strtoupper( mb_substr( $lowered, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $lowered, 1, null, 'UTF-8' );

		if ( ! empty( $acronyms ) ) {
			$title = (string) preg_replace( array_keys( $acronyms ), array_values( $acronyms ), $title );
		}

		return $title;
	}

	/**
	 * Distance d'édition bornée : retourne la meilleure correction d'un jeton dans un vocabulaire.
	 *
	 * @param string                              $token      Jeton plié (≥ 4 caractères).
	 * @param array<string, array<string, int>>   $vocabulary Vocabulaire indexé par première lettre : [lettre => [mot => fréquence]].
	 * @return string Mot corrigé, ou chaîne vide si aucune correction fiable.
	 */
	public static function best_correction( string $token, array $vocabulary ): string {
		$len = strlen( $token );
		if ( $len < 4 || self::is_numeric_token( $token ) ) {
			return '';
		}

		$max_distance = $len <= 5 ? 1 : 2;
		$first        = $token[0];

		$search = static function ( array $bucket, int $max ) use ( $token, $len ): array {
			$best      = '';
			$best_dist = PHP_INT_MAX;
			$best_freq = -1;
			foreach ( $bucket as $word => $freq ) {
				$word = (string) $word;
				if ( abs( strlen( $word ) - $len ) > $max ) {
					continue;
				}
				$dist = levenshtein( $token, $word );
				if ( $dist > $max ) {
					continue;
				}
				if ( $dist < $best_dist || ( $dist === $best_dist && (int) $freq > $best_freq ) ) {
					$best      = $word;
					$best_dist = $dist;
					$best_freq = (int) $freq;
				}
			}
			return [ $best, $best_dist ];
		};

		[ $best ] = $search( $vocabulary[ $first ] ?? [], $max_distance );
		if ( '' !== $best ) {
			return $best;
		}

		// Faute sur la première lettre : recherche globale plus stricte (distance 1).
		if ( $len >= 5 ) {
			$global      = '';
			$global_freq = -1;
			foreach ( $vocabulary as $letter => $bucket ) {
				if ( (string) $letter === $first ) {
					continue;
				}
				[ $candidate ] = $search( $bucket, 1 );
				if ( '' !== $candidate && (int) ( $bucket[ $candidate ] ?? 0 ) > $global_freq ) {
					$global      = $candidate;
					$global_freq = (int) $bucket[ $candidate ];
				}
			}
			return $global;
		}

		return '';
	}

	/**
	 * Indique si un jeton est « connu » : un mot du vocabulaire commence par lui
	 * (couvre la frappe en cours dans le live search).
	 *
	 * @param string                            $token      Jeton plié.
	 * @param array<string, array<string, int>> $vocabulary Vocabulaire.
	 */
	public static function is_known_prefix( string $token, array $vocabulary ): bool {
		if ( '' === $token ) {
			return true;
		}
		foreach ( array_keys( $vocabulary[ $token[0] ] ?? [] ) as $word ) {
			if ( str_starts_with( (string) $word, $token ) ) {
				return true;
			}
		}
		return false;
	}
}
