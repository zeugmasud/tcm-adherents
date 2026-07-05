<?php
/**
 * Import historique one-shot depuis l'export du Google Sheet (CSV).
 *
 * Deux modes :
 *  - WP-CLI : wp tcm import /chemin/export.csv [--dry-run]
 *  - Écran admin (TC Mimet -> Importer) : copier-coller du CSV, dry-run puis exécution.
 *
 * Applique la résolution d'identité Nom+Prénom+DOB (TCM_Dedup) et les corrections
 * de réconciliation (variantes d'orthographe / champs inversés) ci-dessous.
 *
 * Séparateur détecté automatiquement (l'export réel est en virgule).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Import {

	public function hooks(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'tcm import', array( $this, 'cli_import' ) );
		}
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_import_csv', array( $this, 'handle_admin_import' ) );
		add_action( 'admin_post_tcm_reset_import', array( $this, 'handle_reset' ) );
	}

	// -------------------------------------------------------------------------
	// Corrections de réconciliation (issues de l'analyse du CSV réel)
	// Clé = "nom normalisé|prenom normalisé|AAAAMMJJ" -> [Nom canonique, Prénom canonique]
	// -------------------------------------------------------------------------
	public static function corrections(): array {
		return apply_filters( 'tcm_name_corrections', array(
			// Nom composé tronqué -> forme canonique.
			'grousset|camille|20110928'       => array( 'Grousset Ricou', 'Camille' ),
			'grousset ricou|camille|20110928' => array( 'Grousset Ricou', 'Camille' ),
			// Champs Nom/Prénom inversés + casse -> forme canonique.
			'nathan|talpaert|20171130'        => array( 'Talpaert', 'Nathan' ),
			'talpaert|nathan|20171130'        => array( 'Talpaert', 'Nathan' ),
			// (D'Amore : apostrophe droite/typographique -> déjà unifié par la normalisation.)
		) );
	}

	// -------------------------------------------------------------------------
	// WP-CLI
	// -------------------------------------------------------------------------
	public function cli_import( array $args, array $assoc_args ): void {
		$path = $args[0] ?? '';
		if ( ! $path || ! file_exists( $path ) ) {
			WP_CLI::error( "Fichier introuvable : $path" );
		}
		$report = $this->run( file_get_contents( $path ), isset( $assoc_args['dry-run'] ) );
		WP_CLI::success( sprintf(
			'Lignes: %d | Personnes créées: %d | Adhérents créés: %d | Ignorées: %d%s',
			$report['rows'], $report['personnes'], $report['adherents'], $report['skipped'],
			$report['dry_run'] ? ' (DRY-RUN)' : ''
		) );
	}

	// -------------------------------------------------------------------------
	// Écran admin (copier-coller)
	// -------------------------------------------------------------------------
	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Importer (CSV)', 'tcm-adherents' ),
			__( 'Importer (CSV)', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-import',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$report = get_transient( 'tcm_import_report' );
		delete_transient( 'tcm_import_report' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Import CSV des adhérents', 'tcm-adherents' ) . '</h1>';

		if ( is_array( $report ) ) {
			if ( isset( $report['reindex'] ) ) {
				echo '<div class="notice notice-success"><p><strong>RÉINDEXATION</strong> — '
					. sprintf( esc_html__( '%d adhérents réindexés (taxonomies Saison / Dossier).', 'tcm-adherents' ), (int) $report['reindex'] ) . '</p></div>';
			} elseif ( isset( $report['reset'] ) ) {
				echo '<div class="notice notice-warning"><p><strong>RÉINITIALISATION</strong> — '
					. sprintf( esc_html__( '%d éléments supprimés. Base vidée.', 'tcm-adherents' ), (int) $report['reset'] ) . '</p></div>';
			} else {
				$mode = $report['dry_run'] ? 'SIMULATION (dry-run)' : 'IMPORT EXÉCUTÉ';
				echo '<div class="notice notice-success"><p><strong>' . esc_html( $mode ) . '</strong> — '
					. sprintf(
						esc_html__( 'Lignes : %1$d · Personnes créées : %2$d · Adhérents créés : %3$d · Ignorées : %4$d', 'tcm-adherents' ),
						$report['rows'], $report['personnes'], $report['adherents'], $report['skipped']
					) . '</p></div>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_import_csv' );
		echo '<input type="hidden" name="action" value="tcm_import_csv">';
		echo '<p>' . esc_html__( 'Collez le contenu du CSV (en-têtes en 1re ligne). Faites d’abord une simulation.', 'tcm-adherents' ) . '</p>';
		echo '<textarea name="csv" id="tcm-csv" rows="12" style="width:100%;font-family:monospace;"></textarea>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Simulation (ne rien écrire)', 'tcm-adherents' ) . '</label></p>';
		submit_button( __( 'Lancer', 'tcm-adherents' ) );
		echo '</form>';

		// Zone de réinitialisation (dev).
		$np = (int) ( wp_count_posts( TCM_CPT_PERSONNE )->publish ?? 0 );
		$na = (int) ( wp_count_posts( TCM_CPT_ADHERENT )->publish ?? 0 );
		echo '<hr><h2>' . esc_html__( 'Réinitialiser', 'tcm-adherents' ) . '</h2>';
		echo '<p>' . sprintf( esc_html__( 'Actuellement en base : %1$d personnes, %2$d adhérents. La réinitialisation supprime toutes les données importées (personnes, adhérents, règlements, commandes, inscriptions) pour repartir propre.', 'tcm-adherents' ), $np, $na ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Supprimer toutes les données importées ? Action irréversible.\');">';
		wp_nonce_field( 'tcm_reset_import' );
		echo '<input type="hidden" name="action" value="tcm_reset_import">';
		submit_button( __( 'Vider les données importées', 'tcm-adherents' ), 'delete' );
		echo '</form>';

		TCM_Taxonomies::render_reindex_button();

		echo '</div>';
	}

	public function handle_reset(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_reset_import' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );
		$deleted = 0;
		$types   = array( TCM_CPT_INSCRIPTION, TCM_CPT_REGLEMENT, TCM_CPT_COMMANDE, TCM_CPT_ADHERENT, TCM_CPT_PERSONNE );
		foreach ( $types as $type ) {
			$ids = get_posts( array(
				'post_type'      => $type,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			) );
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
				$deleted++;
			}
		}
		set_transient( 'tcm_import_report', array( 'rows' => 0, 'personnes' => 0, 'adherents' => 0, 'skipped' => 0, 'dry_run' => false, 'reset' => $deleted ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import' ) );
		exit;
	}

	public function handle_admin_import(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_import_csv' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );
		$csv     = (string) wp_unslash( $_POST['csv'] ?? '' );
		$dry_run = ! empty( $_POST['dry_run'] );
		$report  = $this->run( $csv, $dry_run );
		set_transient( 'tcm_import_report', $report, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-import' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Cœur de l'import
	// -------------------------------------------------------------------------
	public function run( string $csv, bool $dry_run ): array {
		$rows   = $this->parse_csv( $csv );
		$report = array( 'rows' => count( $rows ), 'personnes' => 0, 'adherents' => 0, 'skipped' => 0, 'dry_run' => $dry_run );

		// Simulation : compte en mémoire les Personnes et Adhérents distincts
		// (dédoublonnage Nom+Prénom+DOB, corrections appliquées) sans rien écrire.
		if ( $dry_run ) {
			$p = array();
			$a = array();
			foreach ( $rows as $r ) {
				$data = $this->map_row( $r );
				if ( empty( $data['nom'] ) || empty( $data['date_naissance'] ) ) {
					$report['skipped']++;
					continue;
				}
				$pkey = TCM_Dedup::make_key( $data['nom'], $data['prenom'], $data['date_naissance'] );
				$p[ $pkey ] = true;
				if ( '' !== $data['saison'] ) {
					$a[ $pkey . '|' . $data['saison'] ] = true;
				}
			}
			$report['personnes'] = count( $p );
			$report['adherents'] = count( $a );
			return $report;
		}

		foreach ( $rows as $r ) {
			$data = $this->map_row( $r );
			if ( empty( $data['nom'] ) || empty( $data['date_naissance'] ) ) {
				$report['skipped']++;
				continue;
			}

			$existing    = TCM_Dedup::find_by_key( TCM_Dedup::make_key( $data['nom'], $data['prenom'], $data['date_naissance'] ) );
			$personne_id = TCM_Dedup::resolve_or_create( $data );
			if ( ! $existing ) {
				$report['personnes']++;
			}

			$saison = $data['saison'];
			if ( $saison && ! TCM_Logic::adherent_pour_saison( $personne_id, $saison ) ) {
				$adherent_id = wp_insert_post( array(
					'post_type'   => TCM_CPT_ADHERENT,
					'post_status' => 'publish',
					'post_title'  => 'Adhérent',
				) );
				update_field( 'personne', $personne_id, $adherent_id );
				update_field( 'saison', $saison, $adherent_id );
				foreach ( $data['adhesion'] as $k => $v ) {
					if ( '' !== $v ) {
						update_field( $k, $v, $adherent_id );
					}
				}
				TCM_Taxonomies::sync_adherent( $adherent_id );
				$report['adherents']++;
			}
		}
		return $report;
	}

	private function parse_csv( string $csv ): array {
		$csv = trim( $csv );
		if ( '' === $csv ) {
			return array();
		}
		// Détection du délimiteur sur la 1re ligne.
		$first = strtok( $csv, "\r\n" );
		$delim = ( substr_count( $first, ',' ) >= substr_count( $first, ';' ) ) ? ',' : ';';

		// fgetcsv sur un flux mémoire : gère correctement les champs entre
		// guillemets contenant des sauts de ligne (Commentaire, Adresse…).
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $csv );
		rewind( $fh );

		$headers = fgetcsv( $fh, 0, $delim );
		if ( ! $headers ) {
			fclose( $fh );
			return array();
		}
		$headers = array_map( 'trim', $headers );

		$out = array();
		while ( ( $vals = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			// Ignore les vraies lignes vides.
			if ( 1 === count( $vals ) && ( null === $vals[0] || '' === trim( (string) $vals[0] ) ) ) {
				continue;
			}
			$vals  = array_pad( $vals, count( $headers ), '' );
			$out[] = array_combine( $headers, array_slice( $vals, 0, count( $headers ) ) );
		}
		fclose( $fh );
		return $out;
	}

	private function map_row( array $r ): array {
		$nom    = trim( $r['Nom'] ?? '' );
		$prenom = trim( $r['Prenom'] ?? $r['Prénom'] ?? '' );
		$dob    = $this->to_ymd( $r['Date de naissance'] ?? '' );

		// Application des corrections (variantes / inversions).
		$key = TCM_Dedup::normalize( $nom ) . '|' . TCM_Dedup::normalize( $prenom ) . '|' . $dob;
		$corr = self::corrections();
		if ( isset( $corr[ $key ] ) ) {
			$nom    = $corr[ $key ][0];
			$prenom = $corr[ $key ][1];
		}

		$email = trim( $r['Email'] ?? '' ) ?: trim( $r['New email'] ?? '' );
		$tel   = trim( $r['Tel'] ?? '' ) ?: trim( $r['New tel'] ?? '' );
		$adr   = trim( $r['Adresse'] ?? '' ) ?: trim( $r['New adresse'] ?? '' );

		return array(
			'civilite'       => trim( $r['Civilite'] ?? $r['Civilité'] ?? '' ),
			'nom'            => $nom,
			'prenom'         => $prenom,
			'date_naissance' => $dob,
			'email'          => $email,
			'telephone'      => $tel,
			'adresse'        => $adr,
			'cp'             => trim( $r['Cp'] ?? '' ),
			'ville'          => trim( $r['Ville'] ?? '' ),
			'saison'         => trim( (string) ( $r['Saison'] ?? '' ) ),
			// Champs propres à l'adhésion (par saison).
			'adhesion'       => array(
				'nouvel_adherent'    => $this->bool( $r['Adherent'] ?? '', true ), // Adherent=FALSE => nouvel adhérent.
				'changement'         => $this->bool( $r['Changement coordonnees'] ?? '' ),
				'mineur'             => $this->bool( $r['Mineur'] ?? '' ),
				'autorisation_photo' => $this->bool( $r['Autorisation photo'] ?? '' ),
				'adoc_valide'        => $this->bool( $r['Adoc'] ?? '' ),
				'dossier_complet'    => $this->bool( $r['Dossier Complet'] ?? '' ),
				'parent_mere_nom'    => trim( $r['Mere'] ?? '' ),
				'parent_mere_tel'    => trim( $r['Tel mere'] ?? '' ),
				'parent_pere_nom'    => trim( $r['Pere'] ?? '' ),
				'parent_pere_tel'    => trim( $r['Tel pere'] ?? '' ),
				'autre_contact'      => trim( $r['Personne a prevenir'] ?? '' ),
				'commentaires'       => trim( $r['Commentaire'] ?? '' ),
			),
		);
	}

	/**
	 * TRUE/FALSE -> 1/0. $invert : renvoie l'inverse (pour Adherent -> nouvel_adherent).
	 */
	private function bool( string $v, bool $invert = false ): string {
		$v = strtoupper( trim( $v ) );
		if ( '' === $v ) {
			return '';
		}
		$t = ( 'TRUE' === $v || '1' === $v || 'OUI' === $v );
		if ( $invert ) {
			$t = ! $t;
		}
		return $t ? '1' : '0';
	}

	private function to_ymd( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Ymd', $ts ) : preg_replace( '/\D/', '', $value );
	}
}
