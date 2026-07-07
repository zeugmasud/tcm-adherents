<?php
/**
 * Génère des titres lisibles pour chaque CPT à l'enregistrement.
 * (Les CPT n'exposent pas de champ Titre à la saisie : il est calculé.)
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Titles {

	public function hooks(): void {
		// acf/save_post avec priorité > 10 : les champs sont déjà enregistrés.
		add_action( 'acf/save_post', array( $this, 'maybe_set_title' ), 20 );
		add_action( 'init', array( $this, 'maybe_reindex_titles' ) );
		// Affiche un titre lisible en admin (listes edit.php) même si le post_title
		// stocké est générique (posts créés par import / CRUD sans acf/save_post).
		add_filter( 'the_title', array( $this, 'admin_title' ), 10, 2 );
	}

	/** CPT gérés par ce module. */
	private static function types(): array {
		return array( TCM_CPT_PERSONNE, TCM_CPT_ADHERENT, TCM_CPT_REGLEMENT, TCM_CPT_COMMANDE, TCM_CPT_CRENEAU, TCM_CPT_INSCRIPTION );
	}

	/** Filtre the_title : en admin, remplace le titre stocké par le titre calculé. */
	public function admin_title( $title, $post_id = 0 ) {
		if ( ! is_admin() || ! $post_id || ! in_array( get_post_type( $post_id ), self::types(), true ) ) {
			return $title;
		}
		$computed = $this->compute_title( (int) $post_id );
		return '' !== $computed ? $computed : $title;
	}

	/** Nom lisible d'une Personne. */
	private function person_name( int $person_id ): string {
		if ( ! $person_id ) {
			return '';
		}
		return trim( (string) get_field( 'nom', $person_id ) . ' ' . (string) get_field( 'prenom', $person_id ) );
	}

	/** Nom de la Personne rattachée à un Adhérent. */
	private function adherent_name( int $adh_id ): string {
		return $adh_id ? $this->person_name( (int) get_field( 'personne', $adh_id ) ) : '';
	}

	public function maybe_reindex_titles(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['tcm_reindex_titles'] ) ) {
			return;
		}
		$posts = get_posts( array(
			'post_type'      => array( TCM_CPT_PERSONNE, TCM_CPT_ADHERENT, TCM_CPT_REGLEMENT, TCM_CPT_COMMANDE, TCM_CPT_CRENEAU, TCM_CPT_INSCRIPTION ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $posts as $post_id ) {
			$this->maybe_set_title( $post_id );
		}
		wp_safe_redirect( remove_query_arg( 'tcm_reindex_titles' ) );
		exit;
	}

	public function maybe_set_title( $post_id ): void {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}
		$post_id = (int) $post_id;
		$title   = $this->compute_title( $post_id );

		// Compare au titre BRUT stocké (pas get_the_title, filtré par admin_title).
		if ( '' === $title || (string) get_post_field( 'post_title', $post_id ) === $title ) {
			return;
		}

		// Évite une boucle infinie sur wp_update_post -> acf/save_post.
		remove_action( 'acf/save_post', array( $this, 'maybe_set_title' ), 20 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		add_action( 'acf/save_post', array( $this, 'maybe_set_title' ), 20 );
	}

	/**
	 * Calcule un titre lisible pour un CPT (sans enregistrer). Inclut le nom de
	 * la personne pour règlements / commandes / inscriptions.
	 */
	public function compute_title( int $post_id ): string {
		$title = '';
		switch ( get_post_type( $post_id ) ) {
			case TCM_CPT_PERSONNE:
				$title = $this->person_name( $post_id );
				$dob   = get_field( 'date_naissance', $post_id );
				if ( $dob ) {
					$title .= ' (' . self::fr_date( $dob ) . ')';
				}
				break;

			case TCM_CPT_ADHERENT:
				$name  = $this->adherent_name( $post_id );
				$title = ( '' !== $name ? $name : 'Adhérent' ) . ' — ' . get_field( 'saison', $post_id );
				break;

			case TCM_CPT_REGLEMENT:
				$name    = $this->adherent_name( (int) get_field( 'adherent', $post_id ) );
				$montant = number_format( (float) get_field( 'montant', $post_id ), 2, ',', ' ' );
				$title   = ( '' !== $name ? $name . ' — ' : '' ) . $montant . ' € (' . get_field( 'canal', $post_id ) . ')';
				break;

			case TCM_CPT_COMMANDE:
				$name    = $this->adherent_name( (int) get_field( 'adherent', $post_id ) );
				$libelle = (string) get_field( 'libelle', $post_id );
				$title   = ( '' !== $name ? $name . ' — ' : '' ) . ( '' !== $libelle ? $libelle : 'Commande' );
				break;

			case TCM_CPT_CRENEAU:
				$title = trim( ucfirst( (string) get_field( 'jour', $post_id ) ) . ' ' . get_field( 'heure_debut', $post_id ) . ' — ' . get_field( 'type_cours', $post_id ) );
				break;

			case TCM_CPT_INSCRIPTION:
				$name = $this->adherent_name( (int) get_field( 'adherent', $post_id ) );
				$cid  = (int) get_field( 'creneau', $post_id );
				$cre  = $cid ? trim( ucfirst( (string) get_field( 'jour', $cid ) ) . ' ' . get_field( 'heure_debut', $cid ) ) : '';
				$title = ( '' !== $name ? $name : 'Inscription' ) . ( '' !== $cre ? ' — ' . $cre : '' );
				break;

			default:
				return '';
		}
		return trim( $title );
	}

	private static function fr_date( string $ymd ): string {
		$ymd = preg_replace( '/\D/', '', $ymd );
		if ( strlen( $ymd ) !== 8 ) {
			return $ymd;
		}
		return substr( $ymd, 6, 2 ) . '/' . substr( $ymd, 4, 2 ) . '/' . substr( $ymd, 0, 4 );
	}
}
