<?php
/**
 * Résolution d'identité par Nom + Date de naissance (normalisés).
 *
 * Règle métier (audit) : le User ID du formulaire change chaque saison ;
 * l'email est partagé en famille et parfois manquant. La clé fiable est
 * Nom normalisé + DOB (0 DOB manquante, distingue les jumeaux).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Dedup {

	/**
	 * Normalise une chaîne de nom : minuscules, sans accents, espaces compactés.
	 */
	public static function normalize( string $value ): string {
		$value = trim( $value );
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Construit la clé de dédoublonnage : "nom prenom|AAAAMMJJ".
	 *
	 * @param string $dob Date de naissance au format Ymd (ex : 20101026).
	 */
	public static function make_key( string $nom, string $prenom, string $dob ): string {
		$dob = preg_replace( '/\D/', '', $dob );
		return self::normalize( $nom . ' ' . $prenom ) . '|' . $dob;
	}

	/**
	 * Retrouve la Personne par clé, ou la crée. Retourne l'ID Personne.
	 *
	 * @param array $data nom, prenom, date_naissance (Ymd), civilite, email, telephone, adresse, cp, ville.
	 */
	public static function resolve_or_create( array $data ): int {
		$key = self::make_key(
			$data['nom'] ?? '',
			$data['prenom'] ?? '',
			$data['date_naissance'] ?? ''
		);

		$existing = self::find_by_key( $key );
		if ( $existing ) {
			// On complète les coordonnées de contact si elles étaient vides.
			self::maybe_fill_contact( $existing, $data );
			return $existing;
		}

		return self::create( $key, $data );
	}

	public static function find_by_key( string $key ): int {
		$found = get_posts( array(
			'post_type'      => TCM_CPT_PERSONNE,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'meta_key'       => 'cle_dedup',
			'meta_value'     => $key,
		) );
		return $found ? (int) $found[0] : 0;
	}

	private static function create( string $key, array $data ): int {
		$title = trim( ( $data['nom'] ?? '' ) . ' ' . ( $data['prenom'] ?? '' ) );
		$id    = wp_insert_post( array(
			'post_type'   => TCM_CPT_PERSONNE,
			'post_status' => 'publish',
			'post_title'  => $title !== '' ? $title : 'Personne',
		), true );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_field( 'cle_dedup', $key, $id );
		foreach ( array( 'civilite', 'nom', 'prenom', 'date_naissance', 'email', 'telephone', 'adresse', 'cp', 'ville' ) as $f ) {
			if ( isset( $data[ $f ] ) && '' !== $data[ $f ] ) {
				update_field( $f, $data[ $f ], $id );
			}
		}
		return (int) $id;
	}

	/**
	 * Complète email/tél/adresse seulement s'ils sont vides côté Personne.
	 */
	private static function maybe_fill_contact( int $person_id, array $data ): void {
		foreach ( array( 'email', 'telephone', 'adresse', 'cp', 'ville' ) as $f ) {
			$current = get_field( $f, $person_id );
			if ( ( ! $current || '' === $current ) && ! empty( $data[ $f ] ) ) {
				update_field( $f, $data[ $f ], $person_id );
			}
		}
	}
}
