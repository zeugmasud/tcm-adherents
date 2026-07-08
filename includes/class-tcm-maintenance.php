<?php
/**
 * Outils de maintenance (one-shot) : normalisation des fiches existantes et
 * backfill des coordonnées manquantes depuis ADOC.
 *
 * - Normalisation : réapplique nom (MAJ), prénom (Capitalisé), téléphone (10
 *   chiffres) à toutes les Personnes + tél parents des Adhérents. Utile pour les
 *   données importées avant l'ajout de TCM_Normalize.
 * - Backfill ADOC : remplit email/téléphone vides des Personnes à partir d'un
 *   export de contacts ADOC (import-data/adoc-contacts.json), matché par idAdoc
 *   (via l'Adhérent) puis par Nom+Prénom+DOB. N'écrase jamais une valeur présente.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Maintenance {

	const CONTACTS_FILE = 'import-data/adoc-contacts.json';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_normalize_all', array( $this, 'handle_normalize' ) );
		add_action( 'admin_post_tcm_adoc_backfill', array( $this, 'handle_backfill' ) );
		add_action( 'admin_post_tcm_adoc_import', array( $this, 'handle_import' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Maintenance', 'tcm-adherents' ),
			__( 'Maintenance', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-maintenance',
			array( $this, 'render' )
		);
	}

	/* =====================================================================
	 * Écran
	 * =================================================================== */

	public function render(): void {
		$report = get_transient( 'tcm_maintenance_report' );
		delete_transient( 'tcm_maintenance_report' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Maintenance des données', 'tcm-adherents' ) . '</h1>';

		if ( is_array( $report ) && isset( $report['msg'] ) ) {
			$class = ! empty( $report['error'] ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $report['msg'] ) . '</p></div>';
		}

		// --- Normalisation -------------------------------------------------
		echo '<h2>' . esc_html__( 'Normaliser noms & téléphones', 'tcm-adherents' ) . '</h2>';
		echo '<p>' . esc_html__( 'Réapplique le format : NOM en majuscules, Prénom capitalisé, téléphones à 10 chiffres (0 de tête), sur toutes les personnes et les téléphones des parents.', 'tcm-adherents' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Réappliquer le format à toutes les fiches ?\');">';
		wp_nonce_field( 'tcm_normalize_all' );
		echo '<input type="hidden" name="action" value="tcm_normalize_all">';
		submit_button( __( 'Normaliser toutes les fiches', 'tcm-adherents' ), 'primary', 'submit', false );
		echo '</form>';

		// --- Import ADOC par upload CSV -----------------------------------
		echo '<hr><h2>' . esc_html__( 'Import ADOC (email / téléphone) par fichier CSV', 'tcm-adherents' ) . '</h2>';
		echo '<p>' . esc_html__( 'Déposez l’export FFT « Détaillé » ou l’onglet adocDetail enregistré en CSV. Depuis Google Sheets : Fichier → Télécharger → CSV ; depuis Excel : Enregistrer sous → CSV. Les colonnes sont détectées automatiquement (Nom, Prénom, Email, Téléphone, idAdoc / identifiantMembre, Date de naissance). Complète les email/téléphones vides et écrit l’idAdoc manquant sur les adhérents (bouton « Fiche ADOC »), sans jamais écraser une valeur existante.', 'tcm-adherents' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_adoc_import' );
		echo '<input type="hidden" name="action" value="tcm_adoc_import">';
		echo '<p><input type="file" name="adoc_csv" accept=".csv,.txt" required></p>';
		submit_button( __( 'Importer & compléter depuis ADOC', 'tcm-adherents' ), 'primary', 'submit', false );
		echo '</form>';

		// --- Doublons de saison (lecture seule) ---------------------------
		echo '<hr><h2>' . esc_html__( 'Doublons (une personne avec plusieurs fiches sur une même saison)', 'tcm-adherents' ) . '</h2>';
		$dups = $this->season_duplicates();
		if ( ! $dups ) {
			echo '<p>' . esc_html__( 'Aucun doublon détecté.', 'tcm-adherents' ) . '</p>';
		} else {
			$bo     = get_page_by_path( 'back-office-adherents' );
			$bo_url = $bo ? get_permalink( $bo ) : home_url( '/back-office-adherents/' );
			echo '<p>' . esc_html__( 'Ouvrez chaque fiche en double et supprimez celle à retirer dans le back-office.', 'tcm-adherents' ) . '</p>';
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Saison</th><th>Personne</th><th>Fiches adhérent</th></tr></thead><tbody>';
			foreach ( $dups as $d ) {
				echo '<tr><td>' . esc_html( $d['saison'] ) . '</td><td>' . esc_html( $d['name'] ) . '</td><td>';
				foreach ( $d['adherents'] as $aid ) {
					echo '<a href="' . esc_url( add_query_arg( 'id', $aid, $bo_url ) ) . '" target="_blank">#' . (int) $aid . '</a> ';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Détecte les doublons : même personne rattachée à plusieurs fiches adhérent
	 * sur une même saison (cause de l'écart Personnes ≠ Adhésions). Lecture seule.
	 *
	 * @return array<int,array{saison:string,name:string,adherents:int[]}>
	 */
	private function season_duplicates(): array {
		$adh = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		$map = array();
		foreach ( $adh as $a ) {
			$pid   = (int) get_field( 'personne', $a );
			$terms = wp_get_post_terms( $a, TCM_Taxonomies::TAX_SAISON, array( 'fields' => 'names' ) );
			$saison = ( is_wp_error( $terms ) || empty( $terms ) ) ? '(sans saison)' : $terms[0];
			$map[ $saison . '|' . $pid ][] = (int) $a;
		}
		$out = array();
		foreach ( $map as $key => $aids ) {
			if ( count( $aids ) < 2 ) {
				continue;
			}
			list( $saison, $pid ) = explode( '|', $key, 2 );
			$pid  = (int) $pid;
			$name = $pid ? trim( (string) get_field( 'nom', $pid ) . ' ' . (string) get_field( 'prenom', $pid ) ) : '(fiche personne manquante)';
			$out[] = array( 'saison' => $saison, 'name' => ( '' !== $name ? $name : '#' . $pid ), 'adherents' => $aids );
		}
		return $out;
	}

	/* =====================================================================
	 * Normalisation batch
	 * =================================================================== */

	public function handle_normalize(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_normalize_all' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );
		global $wpdb;

		$touched = 0;

		// Personnes : nom, prénom, téléphone.
		$persons = get_posts( array( 'post_type' => TCM_CPT_PERSONNE, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $persons as $pid ) {
			$nom  = (string) get_field( 'nom', $pid );
			$pre  = (string) get_field( 'prenom', $pid );
			$tel  = (string) get_field( 'telephone', $pid );
			$dirty = false;

			$nnom = TCM_Normalize::nom( $nom );
			if ( $nnom !== $nom ) { update_field( 'nom', $nnom, $pid ); $dirty = true; }
			$npre = TCM_Normalize::prenom( $pre );
			if ( $npre !== $pre ) { update_field( 'prenom', $npre, $pid ); $dirty = true; }
			$ntel = TCM_Normalize::phone( $tel );
			if ( '' !== $tel && $ntel !== $tel ) { update_field( 'telephone', $ntel, $pid ); $dirty = true; }

			// Titre "NOM Prénom (jj/mm/aaaa)" cohérent avec TCM_Titles, écrit en SQL
			// direct : pas de wp_update_post (évite révisions + hooks save_post lourds).
			if ( $dirty ) {
				$title = trim( $nnom . ' ' . $npre );
				$d     = preg_replace( '/\D/', '', (string) get_field( 'date_naissance', $pid ) );
				if ( 8 === strlen( $d ) ) {
					$title .= ' (' . substr( $d, 6, 2 ) . '/' . substr( $d, 4, 2 ) . '/' . substr( $d, 0, 4 ) . ')';
				}
				$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $pid ) );
				clean_post_cache( $pid );
				$touched++;
			}
		}

		// Adhérents : téléphones parents.
		$adh = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
		foreach ( $adh as $aid ) {
			foreach ( array( 'parent_mere_tel', 'parent_pere_tel' ) as $f ) {
				$v = (string) get_field( $f, $aid );
				if ( '' === $v ) { continue; }
				$nv = TCM_Normalize::phone( $v );
				if ( $nv !== $v ) { update_field( $f, $nv, $aid ); }
			}
		}

		$this->finish( sprintf( 'Normalisation terminée : %d fiches personnes mises à jour (sur %d).', $touched, count( $persons ) ) );
	}

	/* =====================================================================
	 * Backfill ADOC
	 * =================================================================== */

	public function handle_backfill(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_adoc_backfill' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );

		$file = TCM_PATH . self::CONTACTS_FILE;
		if ( ! file_exists( $file ) ) {
			$this->finish( 'Fichier adoc-contacts.json introuvable.', true );
		}
		$contacts = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $contacts ) ) {
			$this->finish( 'JSON de contacts ADOC invalide.', true );
		}

		$rep = $this->run_backfill( $contacts );
		$this->finish( sprintf(
			'Backfill ADOC terminé : %d personnes appariées · %d email · %d téléphones · %d idAdoc écrits.',
			$rep['matched'], $rep['email'], $rep['tel'], $rep['idadoc']
		) );
	}

	/**
	 * Import ADOC par upload d'un CSV (export FFT « Détaillé » ou onglet adocDetail).
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_adoc_import' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );

		if ( empty( $_FILES['adoc_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['adoc_csv']['tmp_name'] ) ) {
			$this->finish( 'Aucun fichier reçu.', true );
		}
		$raw = (string) file_get_contents( $_FILES['adoc_csv']['tmp_name'] );
		if ( '' === trim( $raw ) ) {
			$this->finish( 'Fichier vide.', true );
		}

		$contacts = $this->parse_csv( $raw );
		if ( empty( $contacts ) ) {
			$this->finish( 'Aucun contact exploitable : colonnes Nom / Email / Téléphone non détectées. Vérifiez que le fichier est bien un CSV avec une ligne d’en-tête.', true );
		}

		$rep = $this->run_backfill( $contacts );
		$this->finish( sprintf(
			'Import ADOC : %d contacts lus · %d personnes appariées · %d email · %d téléphones · %d idAdoc écrits.',
			count( $contacts ), $rep['matched'], $rep['email'], $rep['tel'], $rep['idadoc']
		) );
	}

	/**
	 * Cœur du backfill : complète email/tél vides des Personnes et écrit l'idAdoc
	 * manquant sur leurs Adhérents, depuis une liste de contacts (idAdoc, nom,
	 * prenom, date_naissance, email, telephone). N'écrase jamais une valeur existante.
	 *
	 * @return array{matched:int,email:int,tel:int,idadoc:int}
	 */
	private function run_backfill( array $contacts ): array {
		$by_id  = array();
		$by_key = array();
		foreach ( $contacts as $c ) {
			$email  = isset( $c['email'] ) ? sanitize_email( (string) $c['email'] ) : '';
			$tel    = isset( $c['telephone'] ) ? TCM_Normalize::phone( (string) $c['telephone'] ) : '';
			$idadoc = ! empty( $c['idAdoc'] ) ? preg_replace( '/\D/', '', (string) $c['idAdoc'] ) : '';
			if ( '' === $email && '' === $tel && '' === $idadoc ) {
				continue;
			}
			$entry = array( 'email' => $email, 'telephone' => $tel, 'idadoc' => $idadoc );
			if ( '' !== $idadoc ) {
				$by_id[ $idadoc ] = $entry;
			}
			if ( ! empty( $c['nom'] ) && ! empty( $c['prenom'] ) ) {
				$key = TCM_Dedup::make_key( (string) $c['nom'], (string) $c['prenom'], (string) ( $c['date_naissance'] ?? '' ) );
				$by_key[ $key ] = $entry;
			}
		}

		$filled_email = 0;
		$filled_tel   = 0;
		$filled_idadoc = 0;
		$matched       = 0;

		$persons = get_posts( array( 'post_type' => TCM_CPT_PERSONNE, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $persons as $pid ) {
			// Appariement par clé Nom+Prénom+DOB (les adhérents n'ont pas encore
			// forcément d'idAdoc, donc on ne peut pas se fier au by_id ici).
			$key   = TCM_Dedup::make_key( (string) get_field( 'nom', $pid ), (string) get_field( 'prenom', $pid ), (string) get_field( 'date_naissance', $pid ) );
			$entry = $by_key[ $key ] ?? $this->contact_for_person( $pid, $by_id, $by_key );
			if ( ! $entry ) {
				continue;
			}
			$matched++;

			$cur_email = (string) get_field( 'email', $pid );
			$cur_tel   = (string) get_field( 'telephone', $pid );
			if ( '' === $cur_email && ! empty( $entry['email'] ) ) {
				update_field( 'email', $entry['email'], $pid );
				$filled_email++;
			}
			if ( '' === $cur_tel && ! empty( $entry['telephone'] ) ) {
				update_field( 'telephone', $entry['telephone'], $pid );
				$filled_tel++;
			}

			// idAdoc : écrit sur chaque Adhérent de la personne qui n'en a pas.
			if ( ! empty( $entry['idadoc'] ) ) {
				$adh = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'personne', 'meta_value' => $pid, 'no_found_rows' => true ) );
				foreach ( $adh as $aid ) {
					if ( '' === (string) get_field( 'id_adoc', $aid ) ) {
						update_field( 'id_adoc', $entry['idadoc'], $aid );
						$filled_idadoc++;
					}
				}
			}
		}

		return array( 'matched' => $matched, 'email' => $filled_email, 'tel' => $filled_tel, 'idadoc' => $filled_idadoc );
	}

	/**
	 * Parse un CSV ADOC en liste de contacts. Détecte le délimiteur, l'encodage
	 * (UTF-8 / Windows-1252), la ligne d'en-tête et mappe les colonnes par mots-clés.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function parse_csv( string $raw ): array {
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252, ISO-8859-1' );
		}
		$raw   = preg_replace( "/\r\n?/", "\n", $raw );
		$lines = array_values( array_filter( explode( "\n", $raw ), static function ( $l ) { return '' !== trim( $l ); } ) );
		if ( ! $lines ) {
			return array();
		}
		$delim = ( substr_count( $lines[0], ';' ) >= substr_count( $lines[0], ',' ) ) ? ';' : ',';

		// Trouver la ligne d'en-tête dans les premières lignes (l'export FFT en a 2).
		$header_idx = -1;
		$map        = array();
		$max        = min( 6, count( $lines ) );
		for ( $i = 0; $i < $max; $i++ ) {
			$cols = str_getcsv( $lines[ $i ], $delim );
			$m    = $this->detect_columns( $cols );
			if ( isset( $m['nom'] ) && ( isset( $m['email'] ) || isset( $m['telephone'] ) || isset( $m['idAdoc'] ) ) ) {
				$header_idx = $i;
				$map        = $m;
				break;
			}
		}
		if ( $header_idx < 0 ) {
			return array();
		}

		$out   = array();
		$count = count( $lines );
		for ( $i = $header_idx + 1; $i < $count; $i++ ) {
			$cols = str_getcsv( $lines[ $i ], $delim );
			$get  = static function ( $k ) use ( $map, $cols ) {
				return ( isset( $map[ $k ] ) && isset( $cols[ $map[ $k ] ] ) ) ? trim( (string) $cols[ $map[ $k ] ] ) : '';
			};
			$nom    = $get( 'nom' );
			$idadoc = $get( 'idAdoc' );
			if ( '' === $nom && '' === $idadoc ) {
				continue;
			}
			$out[] = array(
				'idAdoc'         => $idadoc,
				'nom'            => $nom,
				'prenom'         => $get( 'prenom' ),
				'date_naissance' => $this->to_ymd_any( $get( 'dob' ) ),
				'email'          => $get( 'email' ),
				'telephone'      => $get( 'telephone' ),
			);
		}
		return $out;
	}

	/** Détecte les indices de colonnes par mots-clés (sans accents, sans espaces). */
	private function detect_columns( array $headers ): array {
		$map = array();
		foreach ( $headers as $idx => $h ) {
			$k = str_replace( ' ', '', TCM_Dedup::normalize( (string) $h ) );
			if ( '' === $k ) {
				continue;
			}
			if ( ! isset( $map['idAdoc'] ) && ( false !== strpos( $k, 'idadoc' ) || false !== strpos( $k, 'identifiantmembre' ) ) ) { $map['idAdoc'] = $idx; continue; }
			if ( ! isset( $map['email'] ) && ( false !== strpos( $k, 'mail' ) || false !== strpos( $k, 'courriel' ) ) ) { $map['email'] = $idx; continue; }
			if ( ! isset( $map['telephone'] ) && ( false !== strpos( $k, 'tel' ) || false !== strpos( $k, 'phone' ) || false !== strpos( $k, 'portable' ) || false !== strpos( $k, 'mobile' ) ) ) { $map['telephone'] = $idx; continue; }
			if ( ! isset( $map['dob'] ) && false !== strpos( $k, 'naissance' ) ) { $map['dob'] = $idx; continue; }
			// « prenom » avant « nom » (prenom contient nom).
			if ( ! isset( $map['prenom'] ) && false !== strpos( $k, 'prenom' ) ) { $map['prenom'] = $idx; continue; }
			if ( ! isset( $map['nom'] ) && false !== strpos( $k, 'nom' ) ) { $map['nom'] = $idx; continue; }
		}
		return $map;
	}

	/** Convertit une date (jj/mm/aaaa, aaaa-mm-jj, etc.) en Ymd ; '' si vide/invalide. */
	private function to_ymd_any( string $v ): string {
		$v = trim( $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
		}
		if ( preg_match( '#^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})$#', $v, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[1], $m[2], $m[3] );
		}
		$digits = preg_replace( '/\D/', '', $v );
		return 8 === strlen( $digits ) ? $digits : '';
	}

	/**
	 * Retrouve un contact ADOC pour une Personne : d'abord par idAdoc (via ses
	 * Adhérents), sinon par clé Nom+Prénom+DOB.
	 *
	 * @return array{email:string,telephone:string}|null
	 */
	private function contact_for_person( int $pid, array $by_id, array $by_key ): ?array {
		// 1. idAdoc porté par un des Adhérents de la personne.
		$adh = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_key'       => 'personne',
			'meta_value'     => $pid,
		) );
		foreach ( $adh as $aid ) {
			$idadoc = (string) get_field( 'id_adoc', $aid );
			if ( '' !== $idadoc && isset( $by_id[ $idadoc ] ) ) {
				return $by_id[ $idadoc ];
			}
		}

		// 2. Repli par Nom+Prénom+DOB.
		$key = TCM_Dedup::make_key(
			(string) get_field( 'nom', $pid ),
			(string) get_field( 'prenom', $pid ),
			(string) get_field( 'date_naissance', $pid )
		);
		return $by_key[ $key ] ?? null;
	}

	private function finish( string $msg, bool $error = false ): void {
		set_transient( 'tcm_maintenance_report', array( 'msg' => $msg, 'error' => $error ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-maintenance' ) );
		exit;
	}
}
