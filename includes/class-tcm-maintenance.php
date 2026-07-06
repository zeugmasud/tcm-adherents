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

		// --- Backfill ADOC -------------------------------------------------
		$file  = TCM_PATH . self::CONTACTS_FILE;
		$ready = file_exists( $file );
		echo '<hr><h2>' . esc_html__( 'Backfill ADOC (email / téléphone)', 'tcm-adherents' ) . '</h2>';
		echo '<p>' . esc_html__( 'Complète les email et téléphones manquants des personnes à partir de l’export de contacts ADOC :', 'tcm-adherents' )
			. ' <code>' . esc_html( self::CONTACTS_FILE ) . '</code>. ' . esc_html__( 'Les valeurs déjà renseignées ne sont jamais écrasées.', 'tcm-adherents' ) . '</p>';

		if ( ! $ready ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Fichier adoc-contacts.json absent : lancez d’abord la synchro ADOC pour le générer, puis déployez-le.', 'tcm-adherents' ) . '</p></div>';
		} else {
			$contacts = json_decode( (string) file_get_contents( $file ), true );
			$n        = is_array( $contacts ) ? count( $contacts ) : 0;
			echo '<p><em>' . esc_html( sprintf( 'Prêt : %d contacts ADOC dans le fichier.', $n ) ) . '</em></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Compléter les coordonnées vides depuis ADOC ?\');">';
		wp_nonce_field( 'tcm_adoc_backfill' );
		echo '<input type="hidden" name="action" value="tcm_adoc_backfill">';
		submit_button( __( 'Lancer le backfill ADOC', 'tcm-adherents' ), 'secondary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '</div>';
	}

	/* =====================================================================
	 * Normalisation batch
	 * =================================================================== */

	public function handle_normalize(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_normalize_all' ) ) {
			wp_die( 'Accès refusé.' );
		}
		@set_time_limit( 0 );

		$touched = 0;

		// Personnes : nom, prénom, téléphone.
		$persons = get_posts( array( 'post_type' => TCM_CPT_PERSONNE, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
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

			// Titre de la fiche = "NOM Prénom" pour rester cohérent.
			if ( $dirty ) {
				wp_update_post( array( 'ID' => $pid, 'post_title' => trim( $nnom . ' ' . $npre ) ) );
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

		// Index par idAdoc et par clé Nom+Prénom+DOB.
		$by_id  = array();
		$by_key = array();
		foreach ( $contacts as $c ) {
			$email = isset( $c['email'] ) ? sanitize_email( (string) $c['email'] ) : '';
			$tel   = isset( $c['telephone'] ) ? TCM_Normalize::phone( (string) $c['telephone'] ) : '';
			$entry = array( 'email' => $email, 'telephone' => $tel );
			if ( ! empty( $c['idAdoc'] ) ) {
				$by_id[ (string) $c['idAdoc'] ] = $entry;
			}
			if ( ! empty( $c['nom'] ) && ! empty( $c['prenom'] ) ) {
				$key = TCM_Dedup::make_key( (string) $c['nom'], (string) $c['prenom'], (string) ( $c['date_naissance'] ?? '' ) );
				$by_key[ $key ] = $entry;
			}
		}

		$filled_email = 0;
		$filled_tel   = 0;
		$matched      = 0;

		$persons = get_posts( array( 'post_type' => TCM_CPT_PERSONNE, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
		foreach ( $persons as $pid ) {
			$cur_email = (string) get_field( 'email', $pid );
			$cur_tel   = (string) get_field( 'telephone', $pid );
			if ( '' !== $cur_email && '' !== $cur_tel ) {
				continue; // rien à compléter.
			}

			$entry = $this->contact_for_person( $pid, $by_id, $by_key );
			if ( ! $entry ) {
				continue;
			}
			$matched++;

			if ( '' === $cur_email && ! empty( $entry['email'] ) ) {
				update_field( 'email', $entry['email'], $pid );
				$filled_email++;
			}
			if ( '' === $cur_tel && ! empty( $entry['telephone'] ) ) {
				update_field( 'telephone', $entry['telephone'], $pid );
				$filled_tel++;
			}
		}

		$this->finish( sprintf(
			'Backfill ADOC terminé : %d personnes appariées · %d email complétés · %d téléphones complétés.',
			$matched, $filled_email, $filled_tel
		) );
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
