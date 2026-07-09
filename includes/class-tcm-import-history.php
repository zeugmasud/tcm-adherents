<?php
/**
 * Import HISTORIQUE additif (saisons antérieures : 2024, 2025).
 *
 * Contrairement à TCM_Import_Full (wipe & reload), cet import est NON destructif :
 * - il ne supprime rien ;
 * - il déduplique les personnes via TCM_Dedup::resolve_or_create (Nom+Prénom+DOB),
 *   donc une personne déjà présente (2026/2027) est réutilisée, pas dupliquée ;
 * - il saute une adhésion déjà présente pour la même personne + saison (idempotent) ;
 * - il crée les règlements et commandes rattachés.
 *
 * Source : un JSON téléversé (structure { adherents: [ { person, saison, flags,
 * parents, reglements[], commandes[] } ] }). Le JSON contient des données de
 * mineurs (RGPD) : rien n'est stocké sur disque, tout est traité en mémoire.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Import_History {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_import_history', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Import historique', 'tcm-adherents' ),
			__( 'Import historique', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-import-history',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$report = get_transient( 'tcm_import_history_report' );
		delete_transient( 'tcm_import_history_report' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Import historique (saisons antérieures)', 'tcm-adherents' ) . '</h1>';
		echo '<p>' . esc_html__( 'Import additif et dédupliqué (Nom + Prénom + date de naissance). Ne supprime rien, ne touche pas aux saisons existantes, réutilise les personnes déjà en base. Idempotent : une adhésion déjà présente pour la même personne et saison est ignorée.', 'tcm-adherents' ) . '</p>';

		if ( is_array( $report ) ) {
			if ( isset( $report['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Erreur :</strong> ' . esc_html( $report['error'] ) . '</p></div>';
			} else {
				$mode = ! empty( $report['dry'] ) ? 'SIMULATION (rien écrit)' : 'IMPORT RÉEL';
				$seasons = array();
				foreach ( (array) ( $report['seasons'] ?? array() ) as $s => $n ) {
					$seasons[] = $s . ' : ' . (int) $n;
				}
				echo '<div class="notice notice-' . ( ! empty( $report['dry'] ) ? 'info' : 'success' ) . '"><p><strong>' . esc_html( $mode ) . '</strong><br>'
					. sprintf(
						'Personnes : %d créées, %d réutilisées &nbsp;·&nbsp; Adhésions : %d créées, %d ignorées (déjà présentes) &nbsp;·&nbsp; Règlements : %d &nbsp;·&nbsp; Commandes : %d',
						(int) $report['pers_created'], (int) $report['pers_reused'], (int) $report['adh_created'],
						(int) $report['adh_skipped'], (int) $report['reglements'], (int) $report['commandes']
					)
					. '<br>Saisons : ' . esc_html( implode( ' · ', $seasons ) ) . '</p></div>';
			}
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_import_history' );
		echo '<input type="hidden" name="action" value="tcm_import_history">';
		echo '<p><label><strong>Fichier JSON</strong> &nbsp;<input type="file" name="history_json" accept=".json,application/json" required></label></p>';
		echo '<p><label><input type="checkbox" name="dry" value="1" checked> Simulation (ne rien écrire — recommandé pour un premier passage)</label></p>';
		submit_button( __( 'Lancer l’import historique', 'tcm-adherents' ), 'primary', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	public function handle(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_import_history' ) ) {
			wp_die( 'Accès refusé.' );
		}

		$dry = ! empty( $_POST['dry'] );

		if ( empty( $_FILES['history_json']['tmp_name'] ) || ! is_uploaded_file( $_FILES['history_json']['tmp_name'] ) ) {
			$this->finish( array( 'error' => 'Aucun fichier reçu.' ) );
		}
		$raw  = (string) file_get_contents( $_FILES['history_json']['tmp_name'] );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['adherents'] ) || ! is_array( $data['adherents'] ) ) {
			$this->finish( array( 'error' => 'JSON invalide (clé "adherents" attendue).' ) );
		}

		$this->finish( $this->run( $data['adherents'], $dry ) );
	}

	private function finish( array $report ): void {
		set_transient( 'tcm_import_history_report', $report, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import-history' ) );
		exit;
	}

	/**
	 * @param array $adherents Liste des adhésions à importer.
	 * @param bool  $dry       true = simulation (aucune écriture).
	 */
	public function run( array $adherents, bool $dry ): array {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$rep = array(
			'dry' => $dry, 'pers_created' => 0, 'pers_reused' => 0,
			'adh_created' => 0, 'adh_skipped' => 0, 'reglements' => 0, 'commandes' => 0,
			'seasons' => array(),
		);
		$seen = array(); // cle_dedup -> person_id (0 en simulation pour une nouvelle personne)

		foreach ( $adherents as $a ) {
			$saison = (string) ( $a['saison'] ?? '' );
			if ( ! preg_match( '/^\d{4}$/', $saison ) ) {
				continue;
			}
			$rep['seasons'][ $saison ] = ( $rep['seasons'][ $saison ] ?? 0 ) + 1;

			$p   = (array) ( $a['person'] ?? array() );
			$key = TCM_Dedup::make_key( (string) ( $p['nom'] ?? '' ), (string) ( $p['prenom'] ?? '' ), (string) ( $p['date_naissance'] ?? '' ) );

			// Résolution de la personne (dédup).
			if ( array_key_exists( $key, $seen ) ) {
				$pid = $seen[ $key ];
			} else {
				$existing = TCM_Dedup::find_by_key( $key );
				if ( $existing ) {
					$pid = $existing;
					$rep['pers_reused']++;
				} else {
					$rep['pers_created']++;
					$pid = $dry ? 0 : TCM_Dedup::resolve_or_create( $p );
				}
				$seen[ $key ] = $pid;
			}

			// Adhésion déjà présente pour cette personne + saison ? (idempotence)
			if ( $pid ) {
				$dup = get_posts( array(
					'post_type'      => TCM_CPT_ADHERENT,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_query'     => array( 'relation' => 'AND',
						array( 'key' => 'personne', 'value' => $pid ),
						array( 'key' => 'saison', 'value' => $saison ),
					),
				) );
				if ( $dup ) {
					$rep['adh_skipped']++;
					continue;
				}
			}

			// Simulation : on compte ce qui serait créé, sans écrire.
			if ( $dry ) {
				$rep['adh_created']++;
				$rep['reglements'] += count( (array) ( $a['reglements'] ?? array() ) );
				$rep['commandes']  += count( (array) ( $a['commandes'] ?? array() ) );
				continue;
			}

			// Création de l'adhésion.
			$aid = wp_insert_post( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'post_title' => 'Adhérent' ) );
			if ( ! $aid || is_wp_error( $aid ) ) {
				continue;
			}
			update_field( 'personne', $pid, $aid );
			update_field( 'saison', $saison, $aid );
			foreach ( array( 'dossier_complet', 'mineur', 'changement', 'autorisation_photo', 'adoc_valide' ) as $f ) {
				update_field( $f, empty( $a[ $f ] ) ? 0 : 1, $aid );
			}
			foreach ( array( 'id_adoc', 'parent_mere_nom', 'parent_mere_tel', 'parent_pere_nom', 'parent_pere_tel', 'autre_contact', 'commentaires' ) as $f ) {
				if ( ! empty( $a[ $f ] ) ) {
					update_field( $f, (string) $a[ $f ], $aid );
				}
			}
			TCM_Taxonomies::sync_adherent( $aid );
			$rep['adh_created']++;

			// Règlements.
			foreach ( (array) ( $a['reglements'] ?? array() ) as $r ) {
				$id = wp_insert_post( array( 'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'post_title' => 'Règlement' ) );
				if ( ! $id || is_wp_error( $id ) ) {
					continue;
				}
				update_field( 'adherent', $aid, $id );
				update_field( 'montant', (float) ( $r['montant'] ?? 0 ), $id );
				update_field( 'canal', (string) ( $r['canal'] ?? 'autre' ), $id );
				if ( ! empty( $r['date_reglement'] ) ) {
					update_field( 'date_reglement', (string) $r['date_reglement'], $id );
				}
				update_field( 'statut', (string) ( $r['statut'] ?? 'valide' ), $id );
				$rep['reglements']++;
			}

			// Commandes.
			foreach ( (array) ( $a['commandes'] ?? array() ) as $c ) {
				$id = wp_insert_post( array( 'post_type' => TCM_CPT_COMMANDE, 'post_status' => 'publish', 'post_title' => 'Commande' ) );
				if ( ! $id || is_wp_error( $id ) ) {
					continue;
				}
				update_field( 'adherent', $aid, $id );
				update_field( 'libelle', (string) ( $c['libelle'] ?? '' ), $id );
				update_field( 'montant', (float) ( $c['montant'] ?? 0 ), $id );
				update_field( 'saison', (string) ( $c['saison'] ?? $saison ), $id );
				$rep['commandes']++;
			}
		}

		return $rep;
	}
}
