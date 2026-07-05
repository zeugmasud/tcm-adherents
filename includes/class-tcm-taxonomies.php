<?php
/**
 * Taxonomies pour le filtrage natif (Elementor Loop Grid + colonnes admin).
 *
 * - tcm_saison  : dimension saison (Adhérents, Créneaux, Commandes)
 * - tcm_dossier : statut du dossier (Complet / Incomplet) sur les Adhérents
 *
 * Elementor Pro ne filtre les Loop Grids que par TAXONOMIE (pas par champ ACF),
 * d'où ce doublage : la donnée reste dans ACF, la taxonomie sert d'index filtrable.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Taxonomies {

	const TAX_SAISON  = 'tcm_saison';
	const TAX_DOSSIER = 'tcm_dossier';

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'admin_post_tcm_reindex_tax', array( $this, 'handle_reindex' ) );
	}

	public function register(): void {
		register_taxonomy( self::TAX_SAISON, array( TCM_CPT_ADHERENT, TCM_CPT_CRENEAU, TCM_CPT_COMMANDE ), array(
			'labels'            => array( 'name' => 'Saisons', 'singular_name' => 'Saison' ),
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_in_rest'      => true,       // requis pour Elementor / éditeur.
			'show_admin_column' => true,       // bonus : colonne filtrable en admin.
			'rewrite'           => false,
		) );

		register_taxonomy( self::TAX_DOSSIER, array( TCM_CPT_ADHERENT ), array(
			'labels'            => array( 'name' => 'Dossiers', 'singular_name' => 'Dossier' ),
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => false,
		) );
	}

	/**
	 * Aligne les termes de taxonomie d'un adhérent sur ses champs ACF.
	 */
	public static function sync_adherent( int $adherent_id ): void {
		$saison = (string) get_field( 'saison', $adherent_id );
		if ( '' !== $saison ) {
			wp_set_object_terms( $adherent_id, $saison, self::TAX_SAISON, false );
		}
		$dossier = get_field( 'dossier_complet', $adherent_id ) ? 'Complet' : 'Incomplet';
		wp_set_object_terms( $adherent_id, $dossier, self::TAX_DOSSIER, false );
	}

	/**
	 * Réindexe tous les adhérents existants (backfill).
	 *
	 * @return int Nombre d'adhérents traités.
	 */
	public static function reindex_all(): int {
		$ids = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			self::sync_adherent( $id );
			if ( class_exists( 'TCM_Titles' ) ) {
				( new TCM_Titles() )->maybe_set_title( $id );
			}
		}
		return count( $ids );
	}

	public function handle_reindex(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_reindex_tax' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );
		$n = self::reindex_all();
		set_transient( 'tcm_import_report', array( 'rows' => 0, 'personnes' => 0, 'adherents' => 0, 'skipped' => 0, 'dry_run' => false, 'reindex' => $n ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import' ) );
		exit;
	}

	/**
	 * Bouton de réindexation, affiché sur la page d'import.
	 */
	public static function render_reindex_button(): void {
		echo '<hr><h2>' . esc_html__( 'Réindexer les taxonomies', 'tcm-adherents' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aligne les taxonomies Saison / Dossier sur les champs ACF pour tous les adhérents (à lancer une fois après mise à jour).', 'tcm-adherents' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_reindex_tax' );
		echo '<input type="hidden" name="action" value="tcm_reindex_tax">';
		submit_button( __( 'Réindexer maintenant', 'tcm-adherents' ), 'secondary' );
		echo '</form>';
	}
}
