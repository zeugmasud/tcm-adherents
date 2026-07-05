<?php
/**
 * Calculs métier réutilisables (back-office, planning, portail).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Logic {

	/**
	 * Nombre d'inscriptions d'un créneau pour un statut donné.
	 */
	public static function count_inscriptions( int $creneau_id, string $statut = 'confirme' ): int {
		$ids = get_posts( array(
			'post_type'      => TCM_CPT_INSCRIPTION,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'publish',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => 'creneau', 'value' => $creneau_id ),
				array( 'key' => 'statut', 'value' => $statut ),
			),
		) );
		return count( $ids );
	}

	/**
	 * Places restantes = capacité - confirmés (jamais négatif).
	 */
	public static function places_restantes( int $creneau_id ): int {
		$capacite  = (int) get_field( 'capacite', $creneau_id );
		$confirmes = self::count_inscriptions( $creneau_id, 'confirme' );
		return max( 0, $capacite - $confirmes );
	}

	public static function nb_attente( int $creneau_id ): int {
		return self::count_inscriptions( $creneau_id, 'attente' );
	}

	/**
	 * Tous les Adhérents (toutes saisons) rattachés à une Personne.
	 *
	 * @return int[] IDs d'Adhérents.
	 */
	public static function adherents_de_personne( int $personne_id ): array {
		return get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'meta_key'       => 'personne',
			'meta_value'     => $personne_id,
		) );
	}

	/**
	 * L'Adhérent d'une Personne pour une saison donnée (ou 0).
	 */
	public static function adherent_pour_saison( int $personne_id, string $saison ): int {
		$found = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => 'personne', 'value' => $personne_id ),
				array( 'key' => 'saison', 'value' => $saison ),
			),
		) );
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Évalue si le dossier d'un adhérent est complet.
	 * Règle par défaut ci-dessous ; à affiner selon vos exigences réelles,
	 * filtrable via 'tcm_dossier_complet'.
	 */
	public static function evaluate_dossier_complet( int $adherent_id ): bool {
		$personne_id = (int) get_field( 'personne', $adherent_id );
		$has_identity = $personne_id
			&& get_field( 'nom', $personne_id )
			&& get_field( 'date_naissance', $personne_id );

		$has_reglement = ! empty( get_posts( array(
			'post_type'      => TCM_CPT_REGLEMENT,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array( 'key' => 'adherent', 'value' => $adherent_id ),
				array( 'key' => 'statut', 'value' => 'valide' ),
			),
		) ) );

		$complet = (bool) ( $has_identity && $has_reglement );

		/**
		 * Permet d'ajouter vos propres critères (certificat médical, etc.).
		 */
		return (bool) apply_filters( 'tcm_dossier_complet', $complet, $adherent_id );
	}
}
