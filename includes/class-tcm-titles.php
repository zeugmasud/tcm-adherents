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
	}

	public function maybe_set_title( $post_id ): void {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}
		$post_id = (int) $post_id;
		$type    = get_post_type( $post_id );
		$title   = '';

		switch ( $type ) {
			case TCM_CPT_PERSONNE:
				$title = trim( get_field( 'nom', $post_id ) . ' ' . get_field( 'prenom', $post_id ) );
				$dob   = get_field( 'date_naissance', $post_id );
				if ( $dob ) {
					$title .= ' (' . self::fr_date( $dob ) . ')';
				}
				break;

			case TCM_CPT_ADHERENT:
				$pid   = (int) get_field( 'personne', $post_id );
				$name  = $pid ? trim( get_field( 'nom', $pid ) . ' ' . get_field( 'prenom', $pid ) ) : 'Adhérent';
				$title = $name . ' — ' . get_field( 'saison', $post_id );
				break;

			case TCM_CPT_REGLEMENT:
				$title = sprintf( 'Règlement %s € — %s', get_field( 'montant', $post_id ), get_field( 'canal', $post_id ) );
				break;

			case TCM_CPT_COMMANDE:
				$title = 'Commande ' . get_field( 'libelle', $post_id );
				break;

			case TCM_CPT_CRENEAU:
				$title = sprintf( '%s %s — %s', get_field( 'jour', $post_id ), get_field( 'heure_debut', $post_id ), get_field( 'type_cours', $post_id ) );
				break;

			case TCM_CPT_INSCRIPTION:
				$aid   = (int) get_field( 'adherent', $post_id );
				$title = 'Inscription #' . $post_id . ( $aid ? ' (adh. ' . $aid . ')' : '' );
				break;

			default:
				return;
		}

		$title = trim( $title );
		if ( '' === $title || get_the_title( $post_id ) === $title ) {
			return;
		}

		// Évite une boucle infinie sur wp_update_post -> acf/save_post.
		remove_action( 'acf/save_post', array( $this, 'maybe_set_title' ), 20 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		add_action( 'acf/save_post', array( $this, 'maybe_set_title' ), 20 );
	}

	private static function fr_date( string $ymd ): string {
		$ymd = preg_replace( '/\D/', '', $ymd );
		if ( strlen( $ymd ) !== 8 ) {
			return $ymd;
		}
		return substr( $ymd, 6, 2 ) . '/' . substr( $ymd, 4, 2 ) . '/' . substr( $ymd, 0, 4 );
	}
}
