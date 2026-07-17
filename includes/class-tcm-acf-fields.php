<?php
/**
 * Groupes de champs ACF (déclarés en PHP => versionnables, pas de dépendance à la BD).
 *
 * Les relations sont matérialisées par des champs post_object stockés côté "enfant"
 * (ex : Règlement -> Adhérent), ce qui donne une relation 1->N propre et requêtable.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_ACF_Fields {

	public function hooks(): void {
		add_action( 'acf/init', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return; // ACF Pro absent : voir l'avertissement admin.
		}

		$this->group_personne();
		$this->group_adherent();
		$this->group_reglement();
		$this->group_commande();
		$this->group_creneau();
		$this->group_inscription();
	}

	private function location( string $cpt ): array {
		return array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $cpt ) ) );
	}

	// -------------------------------------------------------------------------
	// PERSONNE — identité stable
	// -------------------------------------------------------------------------
	private function group_personne(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_personne',
			'title'    => 'Personne',
			'location' => $this->location( TCM_CPT_PERSONNE ),
			'fields'   => array(
				array( 'key' => 'field_tcm_pers_civilite', 'label' => 'Civilité', 'name' => 'civilite', 'type' => 'select',
					'choices' => array( 'Mme' => 'Mme', 'M.' => 'M.' ), 'allow_null' => 1 ),
				array( 'key' => 'field_tcm_pers_nom', 'label' => 'Nom', 'name' => 'nom', 'type' => 'text', 'required' => 1 ),
				array( 'key' => 'field_tcm_pers_prenom', 'label' => 'Prénom', 'name' => 'prenom', 'type' => 'text', 'required' => 1 ),
				array( 'key' => 'field_tcm_pers_dob', 'label' => 'Date de naissance', 'name' => 'date_naissance', 'type' => 'date_picker',
					'display_format' => 'd/m/Y', 'return_format' => 'Ymd', 'required' => 1 ),
				array( 'key' => 'field_tcm_pers_cle', 'label' => 'Clé de dédoublonnage', 'name' => 'cle_dedup', 'type' => 'text',
					'instructions' => 'Générée automatiquement (nom normalisé + date de naissance). Ne pas éditer.', 'readonly' => 1 ),
				array( 'key' => 'field_tcm_pers_email', 'label' => 'Email (contact)', 'name' => 'email', 'type' => 'email' ),
				array( 'key' => 'field_tcm_pers_tel', 'label' => 'Téléphone', 'name' => 'telephone', 'type' => 'text' ),
				array( 'key' => 'field_tcm_pers_adresse', 'label' => 'Adresse', 'name' => 'adresse', 'type' => 'text' ),
				array( 'key' => 'field_tcm_pers_cp', 'label' => 'Code postal', 'name' => 'cp', 'type' => 'text' ),
				array( 'key' => 'field_tcm_pers_ville', 'label' => 'Ville', 'name' => 'ville', 'type' => 'text' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// ADHÉRENT — 1 par personne x saison
	// -------------------------------------------------------------------------
	private function group_adherent(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_adherent',
			'title'    => 'Adhérent',
			'location' => $this->location( TCM_CPT_ADHERENT ),
			'fields'   => array(
				array( 'key' => 'field_tcm_adh_personne', 'label' => 'Personne', 'name' => 'personne', 'type' => 'post_object',
					'post_type' => array( TCM_CPT_PERSONNE ), 'return_format' => 'id', 'required' => 1, 'ui' => 1 ),
				// Saison stockée en TEXTE (leçon locale FR : éviter les soucis de séparateur de milliers).
				array( 'key' => 'field_tcm_adh_saison', 'label' => 'Saison', 'name' => 'saison', 'type' => 'text',
					'instructions' => 'Ex : 2026', 'required' => 1 ),
				array( 'key' => 'field_tcm_adh_dossier', 'label' => 'Dossier complet', 'name' => 'dossier_complet', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_mineur', 'label' => 'Mineur', 'name' => 'mineur', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_new', 'label' => 'Nouvel adhérent', 'name' => 'nouvel_adherent', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_chg', 'label' => 'Changement de coordonnées', 'name' => 'changement', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_photo', 'label' => 'Autorisation photo', 'name' => 'autorisation_photo', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_adocval', 'label' => 'ADOC validé', 'name' => 'adoc_valide', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_idadoc', 'label' => 'idAdoc', 'name' => 'id_adoc', 'type' => 'text' ),
				// Contacts parents (mineurs).
				array( 'key' => 'field_tcm_adh_mere_nom', 'label' => 'Nom/prénom mère', 'name' => 'parent_mere_nom', 'type' => 'text' ),
				array( 'key' => 'field_tcm_adh_mere_tel', 'label' => 'Tél mère', 'name' => 'parent_mere_tel', 'type' => 'text' ),
				array( 'key' => 'field_tcm_adh_pere_nom', 'label' => 'Nom/prénom père', 'name' => 'parent_pere_nom', 'type' => 'text' ),
				array( 'key' => 'field_tcm_adh_pere_tel', 'label' => 'Tél père', 'name' => 'parent_pere_tel', 'type' => 'text' ),
				array( 'key' => 'field_tcm_adh_autre', 'label' => 'Autre contact (accident)', 'name' => 'autre_contact', 'type' => 'text' ),
					// Consentements (formulaire d'inscription).
					array( 'key' => 'field_tcm_adh_ri', 'label' => 'Règlement intérieur accepté', 'name' => 'reglement_interieur', 'type' => 'true_false', 'ui' => 1 ),
					array( 'key' => 'field_tcm_adh_assurance', 'label' => 'Information assurance reçue', 'name' => 'assurance_info', 'type' => 'true_false', 'ui' => 1 ),
					array( 'key' => 'field_tcm_adh_attest', 'label' => 'Attestation demandée', 'name' => 'attestation_demandee', 'type' => 'true_false', 'ui' => 1 ),
				array( 'key' => 'field_tcm_adh_docs', 'label' => 'Documents', 'name' => 'documents', 'type' => 'repeater', 'layout' => 'table',
					'sub_fields' => array(
						array( 'key' => 'field_tcm_adh_doc_label', 'label' => 'Libellé', 'name' => 'libelle', 'type' => 'text' ),
						array( 'key' => 'field_tcm_adh_doc_file', 'label' => 'Fichier', 'name' => 'fichier', 'type' => 'file', 'return_format' => 'url' ),
					),
				),
				array( 'key' => 'field_tcm_adh_comm', 'label' => 'Commentaires', 'name' => 'commentaires', 'type' => 'textarea', 'rows' => 3 ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// RÈGLEMENT
	// -------------------------------------------------------------------------
	private function group_reglement(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_reglement',
			'title'    => 'Règlement',
			'location' => $this->location( TCM_CPT_REGLEMENT ),
			'fields'   => array(
				array( 'key' => 'field_tcm_reg_adherent', 'label' => 'Adhérent', 'name' => 'adherent', 'type' => 'post_object',
					'post_type' => array( TCM_CPT_ADHERENT ), 'return_format' => 'id', 'required' => 1, 'ui' => 1 ),
				array( 'key' => 'field_tcm_reg_montant', 'label' => 'Montant (€)', 'name' => 'montant', 'type' => 'number', 'step' => '0.01' ),
				array( 'key' => 'field_tcm_reg_canal', 'label' => 'Canal', 'name' => 'canal', 'type' => 'select',
					'choices' => array( 'cheque' => 'Chèque', 'especes' => 'Espèces', 'cb' => 'CB', 'helloasso' => 'HelloAsso', 'virement' => 'Virement', 'aide' => 'Aide (C-jeune / Pass Sport)', 'autre' => 'Autre' ), 'required' => 1 ),
				array( 'key' => 'field_tcm_reg_date', 'label' => 'Date', 'name' => 'date_reglement', 'type' => 'date_picker',
					'display_format' => 'd/m/Y', 'return_format' => 'Ymd' ),
				array( 'key' => 'field_tcm_reg_ref', 'label' => 'Référence HelloAsso', 'name' => 'ref_helloasso', 'type' => 'text',
					'instructions' => 'Rempli automatiquement pour les paiements CB.' ),
				array( 'key' => 'field_tcm_reg_statut', 'label' => 'Statut', 'name' => 'statut', 'type' => 'select',
					'choices' => array( 'valide' => 'Validé', 'en_attente' => 'En attente', 'rembourse' => 'Remboursé' ), 'default_value' => 'valide' ),
				array( 'key' => 'field_tcm_reg_commentaire', 'label' => 'Commentaire', 'name' => 'commentaire', 'type' => 'textarea',
					'rows' => 2, 'instructions' => 'Note interne (ex. mois d’encaissement d’un chèque différé).' ),
				// La photo du chèque n'est PAS un champ ACF/média public : elle est
				// stockée hors médiathèque et servie aux seuls membres connectés
				// (voir TCM_Cheque). Méta technique : _tcm_cheque_file / _tcm_cheque_mime.
			),
		) );
	}

	// -------------------------------------------------------------------------
	// COMMANDE
	// -------------------------------------------------------------------------
	private function group_commande(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_commande',
			'title'    => 'Commande',
			'location' => $this->location( TCM_CPT_COMMANDE ),
			'fields'   => array(
				array( 'key' => 'field_tcm_cmd_adherent', 'label' => 'Adhérent', 'name' => 'adherent', 'type' => 'post_object',
					'post_type' => array( TCM_CPT_ADHERENT ), 'return_format' => 'id', 'required' => 1, 'ui' => 1 ),
				array( 'key' => 'field_tcm_cmd_libelle', 'label' => 'Libellé', 'name' => 'libelle', 'type' => 'text' ),
				array( 'key' => 'field_tcm_cmd_montant', 'label' => 'Montant (€)', 'name' => 'montant', 'type' => 'number', 'step' => '0.01' ),
				array( 'key' => 'field_tcm_cmd_saison', 'label' => 'Saison', 'name' => 'saison', 'type' => 'text' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// CRÉNEAU
	// -------------------------------------------------------------------------
	private function group_creneau(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_creneau',
			'title'    => 'Créneau',
			'location' => $this->location( TCM_CPT_CRENEAU ),
			'fields'   => array(
				array( 'key' => 'field_tcm_cre_jour', 'label' => 'Jour', 'name' => 'jour', 'type' => 'select',
					'choices' => array( 'lundi' => 'Lundi', 'mardi' => 'Mardi', 'mercredi' => 'Mercredi', 'jeudi' => 'Jeudi', 'vendredi' => 'Vendredi', 'samedi' => 'Samedi', 'dimanche' => 'Dimanche' ) ),
				array( 'key' => 'field_tcm_cre_hd', 'label' => 'Heure début', 'name' => 'heure_debut', 'type' => 'time_picker', 'display_format' => 'H:i', 'return_format' => 'H:i' ),
				array( 'key' => 'field_tcm_cre_hf', 'label' => 'Heure fin', 'name' => 'heure_fin', 'type' => 'time_picker', 'display_format' => 'H:i', 'return_format' => 'H:i' ),
				array( 'key' => 'field_tcm_cre_type', 'label' => 'Type de cours', 'name' => 'type_cours', 'type' => 'text' ),
				array( 'key' => 'field_tcm_cre_entraineur', 'label' => 'Entraîneur', 'name' => 'entraineur', 'type' => 'text' ),
				array( 'key' => 'field_tcm_cre_capacite', 'label' => 'Capacité', 'name' => 'capacite', 'type' => 'number', 'default_value' => 8 ),
				array( 'key' => 'field_tcm_cre_saison', 'label' => 'Saison', 'name' => 'saison', 'type' => 'text' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// INSCRIPTION — liaison Adhérent <-> Créneau
	// -------------------------------------------------------------------------
	private function group_inscription(): void {
		acf_add_local_field_group( array(
			'key'      => 'group_tcm_inscription',
			'title'    => 'Inscription',
			'location' => $this->location( TCM_CPT_INSCRIPTION ),
			'fields'   => array(
				array( 'key' => 'field_tcm_ins_adherent', 'label' => 'Adhérent', 'name' => 'adherent', 'type' => 'post_object',
					'post_type' => array( TCM_CPT_ADHERENT ), 'return_format' => 'id', 'required' => 1, 'ui' => 1 ),
				array( 'key' => 'field_tcm_ins_creneau', 'label' => 'Créneau', 'name' => 'creneau', 'type' => 'post_object',
					'post_type' => array( TCM_CPT_CRENEAU ), 'return_format' => 'id', 'required' => 1, 'ui' => 1 ),
				array( 'key' => 'field_tcm_ins_statut', 'label' => 'Statut', 'name' => 'statut', 'type' => 'select',
					'choices' => array( 'confirme' => 'Confirmé', 'attente' => 'Liste d’attente' ), 'default_value' => 'attente', 'required' => 1 ),
				array( 'key' => 'field_tcm_ins_date', 'label' => 'Date d’inscription', 'name' => 'date_inscription', 'type' => 'date_picker',
					'display_format' => 'd/m/Y', 'return_format' => 'Ymd' ),
			),
		) );
	}
}
