<?php
/**
 * Enregistrement des Custom Post Types du modèle de données.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_CPT {

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * @return array<string,string> Libellé => slug.
	 */
	public static function all_slugs(): array {
		return array(
			'Personnes'    => TCM_CPT_PERSONNE,
			'Adhérents'    => TCM_CPT_ADHERENT,
			'Règlements'   => TCM_CPT_REGLEMENT,
			'Commandes'    => TCM_CPT_COMMANDE,
			'Créneaux'     => TCM_CPT_CRENEAU,
			'Inscriptions' => TCM_CPT_INSCRIPTION,
		);
	}

	public function register(): void {
		$this->register_cpt( TCM_CPT_PERSONNE, 'Personne', 'Personnes', 'dashicons-id' );
		$this->register_cpt( TCM_CPT_ADHERENT, 'Adhérent', 'Adhérents', 'dashicons-groups' );
		$this->register_cpt( TCM_CPT_REGLEMENT, 'Règlement', 'Règlements', 'dashicons-money-alt' );
		$this->register_cpt( TCM_CPT_COMMANDE, 'Commande', 'Commandes', 'dashicons-cart' );
		$this->register_cpt( TCM_CPT_CRENEAU, 'Créneau', 'Créneaux', 'dashicons-calendar-alt' );
		$this->register_cpt( TCM_CPT_INSCRIPTION, 'Inscription', 'Inscriptions', 'dashicons-yes-alt' );
	}

	private function register_cpt( string $slug, string $singular, string $plural, string $icon ): void {
		$labels = array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'add_new'            => __( 'Ajouter', 'tcm-adherents' ),
			'add_new_item'       => sprintf( __( 'Ajouter : %s', 'tcm-adherents' ), $singular ),
			'edit_item'          => sprintf( __( 'Modifier : %s', 'tcm-adherents' ), $singular ),
			'new_item'           => sprintf( __( 'Nouveau : %s', 'tcm-adherents' ), $singular ),
			'view_item'          => sprintf( __( 'Voir : %s', 'tcm-adherents' ), $singular ),
			'search_items'       => sprintf( __( 'Rechercher : %s', 'tcm-adherents' ), $plural ),
			'not_found'          => __( 'Aucun résultat', 'tcm-adherents' ),
			'all_items'          => $plural,
		);

		register_post_type(
			$slug,
			array(
				'labels'              => $labels,
				'public'              => false,          // Pas d'archive publique par défaut.
				'show_ui'             => true,
				'show_in_menu'        => 'tcm-adherents', // Regroupé sous le menu TC Mimet.
				'show_in_rest'        => true,            // Nécessaire pour Elementor / REST / éditeur.
				'menu_icon'           => $icon,
				'supports'            => array( 'title', 'custom-fields' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
			)
		);
	}
}
