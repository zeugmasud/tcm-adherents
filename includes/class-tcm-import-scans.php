<?php
/**
 * Import des scans de chèques de l'ancienne app (AppSheet → stockage protégé).
 *
 * - Fichier de correspondance : data/scans-map.json (généré depuis l'export
 *   Sheet), chaque entrée = { file, cle, saison, montant, date }.
 * - Images : envoyées en ZIP via le formulaire, ou déposées par FTP dans
 *   uploads/tcm-scans-import/.
 * - Rapprochement : Personne (cle_dedup) → Adhérent de la saison → Règlement
 *   par montant + date. Idempotent : ne réattache pas un scan déjà présent.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Import_Scans {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_tcm_import_scans', array( $this, 'handle' ) );
		add_action( 'admin_post_tcm_import_comments', array( $this, 'handle_comments' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Import scans chèques', 'tcm-adherents' ),
			__( 'Import scans', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-import-scans',
			array( $this, 'render' )
		);
	}

	private function map_path(): string {
		return TCM_PATH . 'data/scans-map.json';
	}

	private function comments_path(): string {
		return TCM_PATH . 'data/comments-map.json';
	}

	/* =====================================================================
	 * Écran
	 * =================================================================== */

	public function render(): void {
		$report = get_transient( 'tcm_scans_report' );
		delete_transient( 'tcm_scans_report' );

		$map   = $this->load_map();
		$total = count( $map );

		echo '<div class="wrap"><h1>' . esc_html__( 'Import des scans de chèques', 'tcm-adherents' ) . '</h1>';

		if ( is_array( $report ) ) {
			echo '<div class="notice notice-success"><p><strong>Import terminé.</strong> '
				. esc_html( sprintf(
					'%d attaché(s), %d déjà présent(s), %d sans image, %d règlement introuvable, %d personne introuvable, %d adhérent introuvable.',
					$report['ok'], $report['already'], count( $report['no_image'] ), count( $report['no_reglement'] ), count( $report['no_person'] ), count( $report['no_adherent'] )
				) ) . '</p></div>';
			foreach ( array(
				'no_reglement' => 'Règlement introuvable (montant/date)',
				'no_person'    => 'Personne introuvable',
				'no_adherent'  => 'Adhérent introuvable pour la saison',
				'no_image'     => 'Image absente du ZIP/dossier',
			) as $k => $lbl ) {
				if ( ! empty( $report[ $k ] ) ) {
					echo '<p><strong>' . esc_html( $lbl ) . '</strong> : <code>' . esc_html( implode( ', ', array_slice( $report[ $k ], 0, 60 ) ) ) . '</code></p>';
				}
			}
		}

		echo '<p>Fichier de correspondance : <strong>' . esc_html( (string) $total ) . '</strong> scans référencés.</p>';
		echo '<p>Dépose le ZIP des images (dossier <code>Reglements_Images</code>), ou place-les par FTP dans <code>wp-content/uploads/tcm-scans-import/</code> puis lance sans fichier.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" onsubmit="return confirm(\'Lancer l\\\'import des scans ?\');">';
		wp_nonce_field( 'tcm_import_scans' );
		echo '<input type="hidden" name="action" value="tcm_import_scans">';
		echo '<p><input type="file" name="scans_zip" accept=".zip"></p>';
		submit_button( __( 'Importer les scans', 'tcm-adherents' ) );
		echo '</form>';

		// --- Import des commentaires -------------------------------------
		$creport = get_transient( 'tcm_comments_report' );
		delete_transient( 'tcm_comments_report' );
		$cmap = json_decode( (string) ( is_readable( $this->comments_path() ) ? file_get_contents( $this->comments_path() ) : '[]' ), true ); // phpcs:ignore
		$cmap = is_array( $cmap ) ? $cmap : array();

		echo '<hr><h2>' . esc_html__( 'Import des commentaires', 'tcm-adherents' ) . '</h2>';
		if ( is_array( $creport ) ) {
			echo '<div class="notice notice-success"><p><strong>Import terminé.</strong> '
				. esc_html( sprintf(
					'%d commentaire(s) importé(s), %d déjà présent(s), %d règlement introuvable, %d personne/adhérent introuvable.',
					$creport['ok'], $creport['already'], count( $creport['no_reglement'] ), $creport['no_target']
				) ) . '</p></div>';
			if ( ! empty( $creport['no_reglement'] ) ) {
				echo '<p><strong>Règlement introuvable</strong> : <code>' . esc_html( implode( ', ', array_slice( $creport['no_reglement'], 0, 60 ) ) ) . '</code></p>';
			}
		}
		echo '<p><strong>' . esc_html( (string) count( $cmap ) ) . '</strong> commentaires référencés. (Aucun fichier à envoyer.)</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Importer les commentaires ?\');">';
		wp_nonce_field( 'tcm_import_comments' );
		echo '<input type="hidden" name="action" value="tcm_import_comments">';
		submit_button( __( 'Importer les commentaires', 'tcm-adherents' ), 'secondary' );
		echo '</form>';

		echo '</div>';
	}

	private function load_map(): array {
		$path = $this->map_path();
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore
		return is_array( $data ) ? $data : array();
	}

	/* =====================================================================
	 * Traitement
	 * =================================================================== */

	public function handle(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_import_scans' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 ); // phpcs:ignore

		$map = $this->load_map();
		$rep = array(
			'ok' => 0, 'already' => 0,
			'no_image' => array(), 'no_reglement' => array(), 'no_person' => array(), 'no_adherent' => array(),
		);

		// Source des images : ZIP envoyé, sinon dossier FTP de repli.
		$base    = '';
		$cleanup = '';
		if ( ! empty( $_FILES['scans_zip']['name'] ) && empty( $_FILES['scans_zip']['error'] ) && class_exists( 'ZipArchive' ) ) {
			$work = trailingslashit( get_temp_dir() ) . 'tcm-scans-' . wp_generate_password( 8, false, false );
			wp_mkdir_p( $work );
			$zip = new ZipArchive();
			if ( true === $zip->open( $_FILES['scans_zip']['tmp_name'] ) ) {
				$zip->extractTo( $work );
				$zip->close();
				$base    = $work;
				$cleanup = $work;
			}
		}
		if ( '' === $base ) {
			$up   = wp_upload_dir();
			$base = trailingslashit( $up['basedir'] ) . 'tcm-scans-import';
		}

		// Index nom de fichier -> chemin (récursif).
		$index = array();
		if ( is_dir( $base ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$index[ $f->getFilename() ] = $f->getPathname();
				}
			}
		}

		foreach ( $map as $m ) {
			$person = TCM_Dedup::find_by_key( (string) $m['cle'] );
			if ( ! $person ) {
				$rep['no_person'][] = $m['file'];
				continue;
			}
			$aids = get_posts( array(
				'post_type'      => TCM_CPT_ADHERENT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => 'personne', 'value' => $person ),
					array( 'key' => 'saison', 'value' => (string) $m['saison'] ),
				),
			) );
			if ( ! $aids ) {
				$rep['no_adherent'][] = $m['file'];
				continue;
			}

			$regs = get_posts( array(
				'post_type'      => TCM_CPT_REGLEMENT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => 'adherent', 'value' => $aids, 'compare' => 'IN' ) ),
			) );

			$matches = array();
			foreach ( $regs as $r ) {
				$mont = round( (float) get_field( 'montant', $r->ID ), 2 );
				$dr   = preg_replace( '/\D/', '', (string) get_field( 'date_reglement', $r->ID ) );
				if ( abs( $mont - round( (float) $m['montant'], 2 ) ) < 0.01 && $dr === (string) $m['date'] ) {
					$matches[] = (int) $r->ID;
				}
			}
			if ( ! $matches ) {
				$rep['no_reglement'][] = $m['file'];
				continue;
			}

			// Premier règlement correspondant sans scan.
			$target = 0;
			foreach ( $matches as $rid ) {
				if ( ! TCM_Cheque::has( $rid ) ) {
					$target = $rid;
					break;
				}
			}
			if ( ! $target ) {
				$rep['already']++; // tous les règlements correspondants ont déjà un scan.
				continue;
			}

			$img = $index[ $m['file'] ] ?? '';
			if ( '' === $img || ! is_readable( $img ) ) {
				$rep['no_image'][] = $m['file'];
				continue;
			}

			if ( TCM_Cheque::import_file( $target, $img ) ) {
				$rep['ok']++;
				$adh = (int) get_field( 'adherent', $target );
				TCM_Log::add( 'update', 'reglement', $adh, $adh ? TCM_Log::person_label( $adh ) : '#' . $target, 'Scan importé (AppSheet)' );
			} else {
				$rep['no_image'][] = $m['file'];
			}
		}

		if ( '' !== $cleanup ) {
			$this->rrmdir( $cleanup );
		}

		set_transient( 'tcm_scans_report', $rep, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import-scans' ) );
		exit;
	}

	/** Importe les commentaires (montant + date) sur les règlements. */
	public function handle_comments(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_import_comments' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 ); // phpcs:ignore

		$map = json_decode( (string) ( is_readable( $this->comments_path() ) ? file_get_contents( $this->comments_path() ) : '[]' ), true ); // phpcs:ignore
		$map = is_array( $map ) ? $map : array();
		$rep = array( 'ok' => 0, 'already' => 0, 'no_reglement' => array(), 'no_target' => 0 );

		foreach ( $map as $m ) {
			$person = TCM_Dedup::find_by_key( (string) $m['cle'] );
			if ( ! $person ) {
				$rep['no_target']++;
				continue;
			}
			$aids = get_posts( array(
				'post_type'      => TCM_CPT_ADHERENT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => 'personne', 'value' => $person ),
					array( 'key' => 'saison', 'value' => (string) $m['saison'] ),
				),
			) );
			if ( ! $aids ) {
				$rep['no_target']++;
				continue;
			}
			$regs    = get_posts( array(
				'post_type'      => TCM_CPT_REGLEMENT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => 'adherent', 'value' => $aids, 'compare' => 'IN' ) ),
			) );
			$matches = array();
			foreach ( $regs as $r ) {
				$mont = round( (float) get_field( 'montant', $r->ID ), 2 );
				$dr   = preg_replace( '/\D/', '', (string) get_field( 'date_reglement', $r->ID ) );
				if ( abs( $mont - round( (float) $m['montant'], 2 ) ) < 0.01 && $dr === (string) $m['date'] ) {
					$matches[] = (int) $r->ID;
				}
			}
			if ( ! $matches ) {
				$rep['no_reglement'][] = $m['cle'] . ' ' . $m['montant'] . '€';
				continue;
			}

			// Règlement correspondant encore sans commentaire (répartit les chèques différés).
			$target = 0;
			foreach ( $matches as $rid ) {
				if ( '' === trim( (string) get_field( 'commentaire', $rid ) ) ) {
					$target = $rid;
					break;
				}
			}
			if ( ! $target ) {
				$rep['already']++;
				continue;
			}
			update_field( 'commentaire', (string) $m['commentaire'], $target );
			$rep['ok']++;
			$adh = (int) get_field( 'adherent', $target );
			TCM_Log::add( 'update', 'reglement', $adh, $adh ? TCM_Log::person_label( $adh ) : '#' . $target, 'Commentaire importé : ' . $m['commentaire'] );
		}

		set_transient( 'tcm_comments_report', $rep, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import-scans' ) );
		exit;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
