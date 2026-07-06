<?php
/**
 * Import complet (wipe & reload) depuis un JSON pré-mappé.
 *
 * Le JSON (import-data/import.json) est généré hors-ligne à partir des Google
 * Sheets « Adherents » et « PlanningTennisMimet_Data » (ETL : dédoublonnage,
 * split Nom/Prénom, enrichissement adocDetail, jointures). Ici on se contente
 * de vider puis recréer les 6 entités en résolvant les clés -> post_id.
 *
 * RGPD : import.json contient des données personnelles (dont mineurs). Il est
 * exclu de git et doit être supprimé du serveur après l'import.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Import_Full {

	const FILE = 'import-data/import.json';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_import_full', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Import complet', 'tcm-adherents' ),
			__( 'Import complet', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-import-full',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$report = get_transient( 'tcm_import_full_report' );
		delete_transient( 'tcm_import_full_report' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Import complet (wipe & reload)', 'tcm-adherents' ) . '</h1>';

		if ( is_array( $report ) ) {
			if ( isset( $report['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Erreur :</strong> ' . esc_html( $report['error'] ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p><strong>IMPORT TERMINÉ</strong> — '
					. sprintf(
						'%d supprimés · %d personnes · %d adhérents · %d règlements · %d commandes · %d créneaux · %d inscriptions',
						(int) $report['deleted'], (int) $report['personnes'], (int) $report['adherents'],
						(int) $report['reglements'], (int) $report['commandes'], (int) $report['creneaux'], (int) $report['inscriptions']
					) . '</p></div>';
			}
		}

		$file  = TCM_PATH . self::FILE;
		$ready = file_exists( $file );

		echo '<p>' . esc_html__( 'Vide toutes les données (personnes, adhérents, règlements, commandes, créneaux, inscriptions) puis recharge tout depuis :', 'tcm-adherents' )
			. ' <code>' . esc_html( self::FILE ) . '</code></p>';

		if ( ! $ready ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Fichier import.json absent : déployez-le avant de lancer.', 'tcm-adherents' ) . '</p></div>';
		} else {
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $data ) ) {
				echo '<p><em>' . sprintf(
					'Prêt à importer : %d personnes, %d adhérents, %d règlements, %d commandes, %d créneaux, %d inscriptions.',
					count( $data['personnes'] ?? array() ), count( $data['adherents'] ?? array() ),
					count( $data['reglements'] ?? array() ), count( $data['commandes'] ?? array() ),
					count( $data['creneaux'] ?? array() ), count( $data['inscriptions'] ?? array() )
				) . '</em></p>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Vider TOUTES les données puis réimporter ? Action irréversible.\');">';
		wp_nonce_field( 'tcm_import_full' );
		echo '<input type="hidden" name="action" value="tcm_import_full">';
		submit_button( __( 'Lancer l’import complet', 'tcm-adherents' ), 'primary', 'submit', true, $ready ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '</div>';
	}

	public function handle(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_import_full' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$report = $this->run();
		set_transient( 'tcm_import_full_report', $report, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import-full' ) );
		exit;
	}

	public function run(): array {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$file = TCM_PATH . self::FILE;
		if ( ! file_exists( $file ) ) {
			return array( 'error' => 'import.json introuvable.' );
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			return array( 'error' => 'JSON invalide.' );
		}

		$rep = array(
			'deleted' => $this->wipe(),
			'personnes' => 0, 'adherents' => 0, 'reglements' => 0,
			'commandes' => 0, 'creneaux' => 0, 'inscriptions' => 0,
		);

		// 1. Personnes ------------------------------------------------------
		$pmap = array(); // cle_dedup (import) -> post_id
		foreach ( (array) ( $data['personnes'] ?? array() ) as $p ) {
			$pid = wp_insert_post( array(
				'post_type'   => TCM_CPT_PERSONNE,
				'post_status' => 'publish',
				'post_title'  => trim( ( $p['nom'] ?? '' ) . ' ' . ( $p['prenom'] ?? '' ) ) ?: 'Personne',
			) );
			if ( ! $pid || is_wp_error( $pid ) ) {
				continue;
			}
			foreach ( array( 'civilite', 'nom', 'prenom', 'date_naissance', 'email', 'telephone', 'adresse', 'cp', 'ville' ) as $f ) {
				if ( isset( $p[ $f ] ) && '' !== $p[ $f ] ) {
					update_field( $f, $p[ $f ], $pid );
				}
			}
			update_field( 'cle_dedup', TCM_Dedup::make_key( $p['nom'] ?? '', $p['prenom'] ?? '', $p['date_naissance'] ?? '' ), $pid );
			$pmap[ $p['cle_dedup'] ] = $pid;
			$rep['personnes']++;
		}

		// 2. Adhérents ------------------------------------------------------
		$amap = array(); // cle ET cle_new -> post_id
		foreach ( (array) ( $data['adherents'] ?? array() ) as $a ) {
			$person = $pmap[ $a['personne_cle'] ] ?? 0;
			if ( ! $person ) {
				continue;
			}
			$aid = wp_insert_post( array(
				'post_type'   => TCM_CPT_ADHERENT,
				'post_status' => 'publish',
				'post_title'  => 'Adhérent',
			) );
			if ( ! $aid || is_wp_error( $aid ) ) {
				continue;
			}
			update_field( 'personne', $person, $aid );
			update_field( 'saison', (string) $a['saison'], $aid );
			foreach ( array( 'dossier_complet', 'mineur', 'nouvel_adherent', 'changement', 'autorisation_photo', 'adoc_valide' ) as $f ) {
				update_field( $f, empty( $a[ $f ] ) ? 0 : 1, $aid );
			}
			if ( ! empty( $a['id_adoc'] ) ) {
				update_field( 'id_adoc', (string) $a['id_adoc'], $aid );
			}
			TCM_Taxonomies::sync_adherent( $aid );

			$amap[ $a['cle'] ] = $aid;
			if ( ! empty( $a['cle_new'] ) ) {
				$amap[ $a['cle_new'] ] = $aid;
			}
			$rep['adherents']++;
		}

		// 3. Règlements -----------------------------------------------------
		foreach ( (array) ( $data['reglements'] ?? array() ) as $r ) {
			$aid = $amap[ $r['adherent_cle'] ] ?? 0;
			if ( ! $aid ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'post_title' => 'Règlement' ) );
			update_field( 'adherent', $aid, $id );
			update_field( 'montant', (float) $r['montant'], $id );
			update_field( 'canal', $r['canal'], $id );
			if ( ! empty( $r['date_reglement'] ) ) {
				update_field( 'date_reglement', $r['date_reglement'], $id );
			}
			update_field( 'statut', $r['statut'] ?? 'valide', $id );
			$rep['reglements']++;
		}

		// 4. Commandes ------------------------------------------------------
		foreach ( (array) ( $data['commandes'] ?? array() ) as $c ) {
			$aid = $amap[ $c['adherent_cle'] ] ?? 0;
			if ( ! $aid ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => TCM_CPT_COMMANDE, 'post_status' => 'publish', 'post_title' => 'Commande' ) );
			update_field( 'adherent', $aid, $id );
			update_field( 'libelle', $c['libelle'] ?? '', $id );
			update_field( 'montant', (float) $c['montant'], $id );
			update_field( 'saison', (string) ( $c['saison'] ?? '' ), $id );
			$rep['commandes']++;
		}

		// 5. Créneaux -------------------------------------------------------
		$cmap = array(); // cle_creneau -> post_id
		foreach ( (array) ( $data['creneaux'] ?? array() ) as $c ) {
			$titre = trim( ( $c['type_cours'] ?: 'Créneau' ) . ' — ' . ucfirst( $c['jour'] ?? '' ) . ' ' . ( $c['heure_debut'] ?? '' ) );
			$id    = wp_insert_post( array( 'post_type' => TCM_CPT_CRENEAU, 'post_status' => 'publish', 'post_title' => $titre ) );
			foreach ( array( 'jour', 'heure_debut', 'heure_fin', 'type_cours', 'entraineur', 'capacite', 'saison' ) as $f ) {
				if ( isset( $c[ $f ] ) && '' !== $c[ $f ] ) {
					update_field( $f, $c[ $f ], $id );
				}
			}
			$cmap[ $c['cle_creneau'] ] = $id;
			$rep['creneaux']++;
		}

		// 6. Inscriptions ---------------------------------------------------
		foreach ( (array) ( $data['inscriptions'] ?? array() ) as $i ) {
			$aid = $amap[ $i['adherent_cle'] ] ?? 0;
			$cid = $cmap[ $i['creneau_cle'] ] ?? 0;
			if ( ! $aid || ! $cid ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => TCM_CPT_INSCRIPTION, 'post_status' => 'publish', 'post_title' => 'Inscription' ) );
			update_field( 'adherent', $aid, $id );
			update_field( 'creneau', $cid, $id );
			update_field( 'statut', $i['statut'] ?? 'attente', $id );
			if ( ! empty( $i['date_inscription'] ) ) {
				update_field( 'date_inscription', $i['date_inscription'], $id );
			}
			$rep['inscriptions']++;
		}

		return $rep;
	}

	/** Vide les 6 CPT. @return int nombre d'éléments supprimés. */
	private function wipe(): int {
		$n     = 0;
		$types = array( TCM_CPT_INSCRIPTION, TCM_CPT_REGLEMENT, TCM_CPT_COMMANDE, TCM_CPT_ADHERENT, TCM_CPT_PERSONNE, TCM_CPT_CRENEAU );
		foreach ( $types as $type ) {
			$ids = get_posts( array( 'post_type' => $type, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
				$n++;
			}
		}
		return $n;
	}
}
