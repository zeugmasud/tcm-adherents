<?php
/**
 * Ingestion du formulaire d'inscription (Elementor Pro Forms).
 *
 * On se branche sur l'action 'elementor_pro/forms/new_record'. Le formulaire
 * porte l'UI ; ce hook porte la logique : normalisation, dédoublonnage Nom+DOB,
 * résolution/création de la Personne, création de l'Adhérent de la saison.
 *
 * Pré-requis côté formulaire Elementor : donner aux champs les "ID de champ"
 * attendus dans self::map_fields() (nom, prenom, date_naissance, email, ...).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Form_Ingest {

	/**
	 * ID du formulaire d'inscription à traiter (option, réglable en admin).
	 * Vide = on traite tout formulaire portant les champs attendus.
	 */
	private function target_form_name(): string {
		return (string) get_option( 'tcm_inscription_form_name', '' );
	}

	public function hooks(): void {
		// 2 arguments : ($record, $handler).
		add_action( 'elementor_pro/forms/new_record', array( $this, 'ingest' ), 10, 2 );
	}

	public function ingest( $record, $handler ): void {
		$settings = $record->get( 'form_settings' );
		$wanted   = $this->target_form_name();
		if ( '' !== $wanted && ( $settings['form_name'] ?? '' ) !== $wanted ) {
			return;
		}

		$fields = $this->flatten( $record->get( 'fields' ) );
		$data   = $this->map_fields( $fields );

		if ( empty( $data['nom'] ) || empty( $data['date_naissance'] ) ) {
			return; // Sécurité : sans Nom+DOB, pas de résolution d'identité.
		}

		$personne_id = TCM_Dedup::resolve_or_create( $data );
		if ( ! $personne_id ) {
			return;
		}

		$saison = $data['saison'] ?: (string) apply_filters( 'tcm_saison_courante', gmdate( 'Y' ) );

		// Un seul Adhérent par personne x saison.
		$adherent_id = TCM_Logic::adherent_pour_saison( $personne_id, $saison );
		if ( ! $adherent_id ) {
			$adherent_id = wp_insert_post( array(
				'post_type'   => TCM_CPT_ADHERENT,
				'post_status' => 'publish',
				'post_title'  => 'Adhérent',
			) );
			update_field( 'personne', $personne_id, $adherent_id );
			update_field( 'saison', $saison, $adherent_id );
		}

		// Champs propres à l'adhésion.
		foreach ( array( 'mineur', 'nouvel_adherent', 'changement', 'autorisation_photo',
			'parent_mere_nom', 'parent_mere_tel', 'parent_pere_nom', 'parent_pere_tel',
			'autre_contact', 'commentaires' ) as $f ) {
			if ( isset( $data[ $f ] ) && '' !== $data[ $f ] ) {
				update_field( $f, $data[ $f ], $adherent_id );
			}
		}

		// Dossier complet recalculé.
		update_field( 'dossier_complet', TCM_Logic::evaluate_dossier_complet( $adherent_id ), $adherent_id );

		// Index taxonomies (Saison / Dossier) pour le filtrage Elementor.
		TCM_Taxonomies::sync_adherent( $adherent_id );
	}

	/**
	 * Transforme le tableau Elementor en [ id_champ => valeur ].
	 */
	private function flatten( array $fields ): array {
		$out = array();
		foreach ( $fields as $id => $field ) {
			$out[ $id ] = $field['value'] ?? '';
		}
		return $out;
	}

	/**
	 * Mappe les ID de champs Elementor vers les clés internes.
	 * ADAPTER les clés de gauche aux "ID de champ" définis dans le formulaire.
	 */
	private function map_fields( array $f ): array {
		return array(
			'civilite'          => $f['civilite'] ?? '',
			'nom'               => $f['nom'] ?? '',
			'prenom'            => $f['prenom'] ?? '',
			'date_naissance'    => $this->to_ymd( $f['date_naissance'] ?? '' ),
			'email'             => $f['email'] ?? '',
			'telephone'         => $f['telephone'] ?? '',
			'adresse'           => $f['adresse'] ?? '',
			'cp'                => $f['cp'] ?? '',
			'ville'             => $f['ville'] ?? '',
			'saison'            => $f['saison'] ?? '',
			'mineur'            => $f['mineur'] ?? '',
			'nouvel_adherent'   => $f['nouvel_adherent'] ?? '',
			'changement'        => $f['changement'] ?? '',
			'autorisation_photo'=> $f['autorisation_photo'] ?? '',
			'parent_mere_nom'   => $f['parent_mere_nom'] ?? '',
			'parent_mere_tel'   => $f['parent_mere_tel'] ?? '',
			'parent_pere_nom'   => $f['parent_pere_nom'] ?? '',
			'parent_pere_tel'   => $f['parent_pere_tel'] ?? '',
			'autre_contact'     => $f['autre_contact'] ?? '',
			'commentaires'      => $f['commentaires'] ?? '',
		);
	}

	private function to_ymd( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Ymd', $ts ) : preg_replace( '/\D/', '', $value );
	}
}
