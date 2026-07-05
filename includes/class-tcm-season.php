<?php
/**
 * Duplication de saison : reconduit les créneaux (et, en option, réinscrit
 * les adhérents en liste d'attente) d'une saison N vers N+1.
 *
 * Les Personnes ne sont JAMAIS dupliquées (identité stable) ; on crée de
 * nouveaux Adhérents rattachés aux mêmes Personnes.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Season {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_duplicate_season', array( $this, 'handle_form' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Dupliquer une saison', 'tcm-adherents' ),
			__( 'Dupliquer une saison', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-duplicate-season',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Dupliquer une saison', 'tcm-adherents' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_duplicate_season' );
		echo '<input type="hidden" name="action" value="tcm_duplicate_season">';
		echo '<table class="form-table">';
		echo '<tr><th><label for="from">Saison source</label></th><td><input name="from" id="from" type="text" placeholder="2026" required></td></tr>';
		echo '<tr><th><label for="to">Saison cible</label></th><td><input name="to" id="to" type="text" placeholder="2027" required></td></tr>';
		echo '<tr><th>Créneaux</th><td><label><input type="checkbox" name="copy_creneaux" value="1" checked> Reconduire les créneaux</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Lancer la duplication', 'tcm-adherents' ) );
		echo '</form></div>';
	}

	public function handle_form(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_duplicate_season' ) ) {
			wp_die( 'Accès refusé.' );
		}

		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
		$copy_creneaux = ! empty( $_POST['copy_creneaux'] );

		$report = $this->duplicate( $from, $to, $copy_creneaux );

		set_transient( 'tcm_season_report', $report, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-duplicate-season&done=1' ) );
		exit;
	}

	/**
	 * @return array Compte-rendu.
	 */
	public function duplicate( string $from, string $to, bool $copy_creneaux ): array {
		$report = array( 'creneaux' => 0 );

		if ( $copy_creneaux ) {
			$creneaux = get_posts( array(
				'post_type'      => TCM_CPT_CRENEAU,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'meta_key'       => 'saison',
				'meta_value'     => $from,
			) );

			foreach ( $creneaux as $src ) {
				$new = wp_insert_post( array(
					'post_type'   => TCM_CPT_CRENEAU,
					'post_status' => 'publish',
					'post_title'  => get_the_title( $src ),
				) );
				foreach ( array( 'jour', 'heure_debut', 'heure_fin', 'type_cours', 'entraineur', 'capacite' ) as $f ) {
					update_field( $f, get_field( $f, $src ), $new );
				}
				update_field( 'saison', $to, $new );
				$report['creneaux']++;
			}
		}

		// TODO (option) : reconduction des adhérents / inscriptions selon la politique du club.
		return $report;
	}
}
