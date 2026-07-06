<?php
/**
 * Normalisation des saisies (formulaire public ET back-office admin).
 *
 * - Nom  : MAJUSCULES.
 * - Prénom : Capitalisé (1re lettre de chaque mot, tirets gérés).
 * - Téléphone : 10 chiffres avec le 0 de tête.
 *
 * Appliqué globalement via des filtres acf/update_value (déclenchés sur toute
 * écriture ACF : formulaire, acf_form admin, import, handlers).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Normalize {

	public function hooks(): void {
		add_filter( 'acf/update_value/key=field_tcm_pers_nom', array( $this, 'f_nom' ), 10, 1 );
		add_filter( 'acf/update_value/key=field_tcm_pers_prenom', array( $this, 'f_prenom' ), 10, 1 );
		add_filter( 'acf/update_value/key=field_tcm_pers_tel', array( $this, 'f_phone' ), 10, 1 );
		add_filter( 'acf/update_value/key=field_tcm_adh_mere_tel', array( $this, 'f_phone' ), 10, 1 );
		add_filter( 'acf/update_value/key=field_tcm_adh_pere_tel', array( $this, 'f_phone' ), 10, 1 );
	}

	public function f_nom( $v ) {
		return is_string( $v ) ? self::nom( $v ) : $v;
	}
	public function f_prenom( $v ) {
		return is_string( $v ) ? self::prenom( $v ) : $v;
	}
	public function f_phone( $v ) {
		return is_string( $v ) ? self::phone( $v ) : $v;
	}

	/* --------------------------------------------------------------------- */

	/** Nom : MAJUSCULES, espaces compactés. */
	public static function nom( string $v ): string {
		$v = trim( preg_replace( '/\s+/', ' ', $v ) );
		return $v === '' ? '' : mb_strtoupper( $v, 'UTF-8' );
	}

	/** Prénom : 1re lettre de chaque mot en majuscule (espaces et tirets), reste en minuscule. */
	public static function prenom( string $v ): string {
		$v = trim( preg_replace( '/\s+/', ' ', $v ) );
		if ( '' === $v ) {
			return '';
		}
		$v = mb_strtolower( $v, 'UTF-8' );
		return preg_replace_callback(
			'/(^|[\s\-\'’])(\p{L})/u',
			static function ( $m ) {
				return $m[1] . mb_strtoupper( $m[2], 'UTF-8' );
			},
			$v
		);
	}

	/** Téléphone FR : 10 chiffres avec 0 de tête ; sinon renvoie l'entrée inchangée. */
	public static function phone( string $v ): string {
		$d = preg_replace( '/\D/', '', $v );
		if ( 11 === strlen( $d ) && 0 === strpos( $d, '33' ) ) {
			$d = '0' . substr( $d, 2 );
		}
		if ( 9 === strlen( $d ) ) {
			$d = '0' . $d;
		}
		return 10 === strlen( $d ) ? $d : trim( $v );
	}
}
