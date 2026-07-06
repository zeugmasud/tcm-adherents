<?php
/**
 * Dashboard CRM (Option B) — rendu serveur, données privées.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Dashboard {

	const CANAUX = array( 'cheque' => 'Chèque', 'especes' => 'Espèces', 'cb' => 'CB', 'helloasso' => 'HelloAsso', 'virement' => 'Virement' );

	public function hooks(): void {
		add_shortcode( 'tcm_fiche', array( $this, 'sc_fiche' ) );
		add_shortcode( 'tcm_recap', array( $this, 'sc_recap' ) );
		add_shortcode( 'tcm_stats', array( $this, 'sc_stats' ) );
		add_shortcode( 'tcm_crm', array( $this, 'sc_crm' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'acf/fields/post_object/result/name=adherent', function( $title, $post ) {
			$pid = $post instanceof WP_Post ? (int) get_field( 'personne', $post->ID ) : 0;
			$nom = $pid ? trim( (string) get_field( 'nom', $pid ) . ' ' . (string) get_field( 'prenom', $pid ) ) : $title;
			return $nom . ' — ' . get_field( 'saison', $post->ID );
		}, 10, 2 );

		add_action( 'acf/save_post', function( $post_id ) {
			if ( ! is_numeric( $post_id ) ) {
				return;
			}
			$post_id = (int) $post_id;
			if ( ! in_array( get_post_type( $post_id ), array( TCM_CPT_REGLEMENT, TCM_CPT_COMMANDE ), true ) ) {
				return;
			}
			if ( empty( get_field( 'adherent', $post_id ) ) && ! empty( $_GET['adherent'] ) ) {
				update_field( 'adherent', (int) wp_unslash( $_GET['adherent'] ), $post_id );
			}
		}, 20 );

		foreach ( array( 'field_tcm_reg_adherent', 'field_tcm_cmd_adherent' ) as $key ) {
			add_filter( "acf/load_value/key={$key}", array( $this, 'prefill_adherent' ), 10, 3 );
		}
	}

	public function prefill_adherent( $value, $post_id, $field ) {
		if ( 'new_post' === $post_id || 'new' === $post_id ) {
			$adherent_id = isset( $_GET['adherent'] ) ? (int) wp_unslash( $_GET['adherent'] ) : 0;
			if ( $adherent_id > 0 ) {
				return $adherent_id;
			}
		}
		return $value;
	}

	private function person_name( int $personne_id ): string {
		if ( ! $personne_id ) {
			return '—';
		}
		return trim( (string) get_field( 'nom', $personne_id ) . ' ' . (string) get_field( 'prenom', $personne_id ) );
	}

	private function children_of( string $cpt, int $adherent_id ): array {
		return get_posts( array(
			'post_type'      => $cpt,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_key'       => 'adherent',
			'meta_value'     => $adherent_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
	}

	private function fr_date( $ymd ): string {
		$ymd = preg_replace( '/\D/', '', (string) $ymd );
		return strlen( $ymd ) === 8 ? substr( $ymd, 6, 2 ) . '/' . substr( $ymd, 4, 2 ) . '/' . substr( $ymd, 0, 4 ) : (string) $ymd;
	}

	/** Téléphone FR : 10 chiffres avec le 0 de tête (souvent perdu à l'import), groupé par 2. */
	private function fr_phone( $tel ): string {
		$digits = preg_replace( '/\D/', '', (string) $tel );
		if ( '' === $digits ) {
			return '';
		}
		if ( 9 === strlen( $digits ) ) {
			$digits = '0' . $digits; // 0 initial perdu (numéro stocké sans le zéro).
		}
		if ( 10 !== strlen( $digits ) ) {
			return (string) $tel; // Format inattendu : on n'invente rien.
		}
		return trim( chunk_split( $digits, 2, ' ' ) );
	}

	private function page_url( string $slug ): string {
		$p = get_page_by_path( $slug );
		return $p ? get_permalink( $p ) : '#';
	}

	public function enqueue_assets(): void {
		// Police du back-office : Source Sans 3 (Google Fonts).
		wp_enqueue_style(
			'tcm-font-rubik',
			'https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'tcm-frontoffice', TCM_URL . 'assets/tcm-frontoffice.css', array( 'tcm-font-rubik' ), TCM_VERSION );
		wp_enqueue_script( 'tcm-frontoffice', TCM_URL . 'assets/tcm-frontoffice.js', array(), TCM_VERSION, true );
	}

	/**
	 * Master-detail : liste (gauche) triée alphabétiquement + fiche (droite).
	 * Filtre par saison (défaut = saison courante ; « all » = toutes).
	 * Chaque ligne affiche l'âge + icônes dossier complet / ADOC validé.
	 */
	public function sc_crm(): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}

		$id       = (int) ( $_GET['id'] ?? 0 );
		$s        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page_url = get_permalink(); // Page back-office, capturée avant toute boucle.

		// Saison : défaut = saison courante (réglage) ; « all » = toutes saisons.
		$saison_courante = (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );
		$saison = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : $saison_courante;

		$tax_query = array();
		if ( 'all' !== $saison && '' !== $saison ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}

		$q = new WP_Query( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			's'              => $s,
			'tax_query'      => $tax_query ?: array(),
		) );

		// Collecte puis tri alphabétique (accent-insensible) sur le nom affiché.
		$rows = array();
		while ( $q->have_posts() ) {
			$q->the_post();
			$aid = get_the_ID();
			$pid = (int) get_field( 'personne', $aid );
			$rows[] = array(
				'aid'     => $aid,
				'nom'     => $this->person_name( $pid ),
				'saison'  => (string) get_field( 'saison', $aid ),
				'age'     => $this->age_from( get_field( 'date_naissance', $pid ) ),
				'complet' => (bool) get_field( 'dossier_complet', $aid ),
				'adoc'    => (bool) get_field( 'adoc_valide', $aid ),
			);
		}
		wp_reset_postdata();
		usort( $rows, static function ( $a, $b ) {
			return strcasecmp( remove_accents( $a['nom'] ), remove_accents( $b['nom'] ) );
		} );

		// Saisons pour le sélecteur (les plus récentes en premier).
		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ) );

		ob_start();
		echo '<div class="tcm-crm"><div class="tcm-crm-list">';

		echo '<form method="get" action="' . esc_url( $page_url ) . '" class="tcm-crm-bar">';
		echo '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="Rechercher…">';
		echo '<select name="saison" onchange="this.form.submit()">';
		if ( ! is_wp_error( $saisons ) ) {
			foreach ( $saisons as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>Saison ' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '<option value="all" ' . selected( $saison, 'all', false ) . '>Toutes les saisons</option>';
		echo '</select>';
		echo '</form>';

		if ( empty( $rows ) ) {
			echo '<p class="tcm-crm-empty">Aucun adhérent pour cette sélection.</p>';
		}

		foreach ( $rows as $r ) {
			$cls  = ( $r['aid'] === $id ) ? ' is-active' : '';
			$href = esc_url( add_query_arg( array( 'id' => $r['aid'], 'saison' => $saison ), $page_url ) );
			$sub  = 'Saison ' . esc_html( $r['saison'] );
			if ( null !== $r['age'] ) {
				$sub .= ' · ' . (int) $r['age'] . ' ans';
			}
			echo '<a class="tcm-crm-row' . $cls . '" href="' . $href . '">';
			echo '<span class="tcm-crm-main"><strong>' . esc_html( $r['nom'] ) . '</strong><span class="tcm-crm-sub">' . $sub . '</span></span>';
			echo '<span class="tcm-crm-flags">';
			if ( $r['complet'] ) {
				echo '<span class="tcm-flag tcm-flag-ok" title="Dossier complet" aria-label="Dossier complet">' . $this->icon_check() . '</span>';
			}
			if ( $r['adoc'] ) {
				echo '<span class="tcm-flag tcm-flag-adoc" title="ADOC validé" aria-label="ADOC validé">' . $this->icon_shield() . '</span>';
			}
			echo '</span>';
			echo '</a>';
		}

		echo '</div><div class="tcm-crm-detail">';
		echo $id ? do_shortcode( '[tcm_fiche id="' . $id . '"]' ) : '<p>Sélectionnez un adhérent à gauche.</p>';
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/** Âge en années à partir d'une date stockée en Ymd, ou null si indisponible. */
	private function age_from( $ymd ): ?int {
		$ymd = preg_replace( '/\D/', '', (string) $ymd );
		if ( 8 !== strlen( (string) $ymd ) ) {
			return null;
		}
		$naissance = DateTime::createFromFormat( 'Ymd', $ymd );
		if ( ! $naissance ) {
			return null;
		}
		return (int) $naissance->diff( new DateTime( 'today' ) )->y;
	}

	/** Icône « check » — dossier complet. */
	private function icon_check(): string {
		return '<svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M8.2 13.3 4.9 10l-1.2 1.2 4.5 4.5 9-9L16 5.5z"/></svg>';
	}

	/** Icône « bouclier validé » — ADOC. */
	private function icon_shield(): string {
		return '<svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M10 1 3 4v5c0 4.4 3 8.5 7 10 4-1.5 7-5.6 7-10V4z"/><path fill="#fff" d="M8.8 12.2 6.3 9.7l1-1 1.5 1.5 3.4-3.4 1 1z"/></svg>';
	}

	public function sc_stats( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$atts = shortcode_atts( array( 'saison' => '' ), $atts );
		$saison = sanitize_text_field( $atts['saison'] );

		$tax_query = array();
		if ( '' !== $saison ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}

		$adh = new WP_Query( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => $tax_query ?: array(), 'no_found_rows' => true ) );
		$incomplets = new WP_Query( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => 'Incomplet' ) ), 'no_found_rows' => true ) );
		$adoc = new WP_Query( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => 'adoc_valide', 'value' => '1', 'compare' => '=' ) ), 'no_found_rows' => true ) );
		$regs = get_posts( array( 'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => 'statut', 'value' => 'valide', 'compare' => '=' ) ) ) );
		$encaisse = 0.0;
		foreach ( $regs as $rid ) {
			$encaisse += (float) get_field( 'montant', $rid );
		}

		// Personnes distinctes (identité stable, indépendantes de la saison).
		$personnes_pub = wp_count_posts( TCM_CPT_PERSONNE );
		$nb_personnes  = isset( $personnes_pub->publish ) ? (int) $personnes_pub->publish : 0;

		ob_start();
		echo '<div class="tcm-stats">';
		echo '<div class="tcm-stat"><span class="tcm-stat-title">Personnes</span><strong>' . esc_html( (string) $nb_personnes ) . '</strong></div>';
		echo '<div class="tcm-stat"><span class="tcm-stat-title">Adhésions</span><strong>' . esc_html( (string) $adh->post_count ) . '</strong></div>';
		echo '<div class="tcm-stat is-warn"><span class="tcm-stat-title">Dossiers incomplets</span><strong>' . esc_html( (string) $incomplets->post_count ) . '</strong></div>';
		echo '<div class="tcm-stat"><span class="tcm-stat-title">Encaissé</span><strong>' . esc_html( number_format( $encaisse, 0, ',', ' ' ) ) . ' €</strong></div>';
		echo '<div class="tcm-stat"><span class="tcm-stat-title">ADOC validés</span><strong>' . esc_html( (string) $adoc->post_count ) . '</strong></div>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function sc_fiche( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$atts = shortcode_atts( array( 'id' => '' ), $atts );
		$id   = (int) ( $atts['id'] ?: ( $_GET['id'] ?? 0 ) );
		if ( ! $id || get_post_type( $id ) !== TCM_CPT_ADHERENT ) {
			return '<p>Adhérent introuvable.</p>';
		}

		$pid    = (int) get_field( 'personne', $id );
		$nom    = $this->person_name( $pid );
		$saison = get_field( 'saison', $id );
		$dossier = get_field( 'dossier_complet', $id ) ? 'Complet' : 'Incomplet';

		$edit_adh = esc_url( add_query_arg( 'id', $id, $this->page_url( 'fiche-adherent' ) ) );
		$edit_per = esc_url( add_query_arg( 'id', $pid, $this->page_url( 'fiche-personne' ) ) );
		$add_reg  = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tcm_new_child&entity=reglement&adherent=' . $id ), 'tcm_new_child' ) );
		$add_cmd  = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tcm_new_child&entity=commande&adherent=' . $id ), 'tcm_new_child' ) );

		ob_start();
		echo '<div class="tcm-fiche">';

		// Notice après un envoi d'attestation (redirection avec ?facture=…).
		if ( isset( $_GET['facture'] ) ) {
			$codes = array(
				'sent'    => array( 'ok', 'Attestation envoyée par e-mail.' ),
				'error'   => array( 'err', 'Échec de la génération ou de l’envoi de l’attestation.' ),
				'noemail' => array( 'err', 'Aucune adresse e-mail enregistrée pour cet adhérent.' ),
			);
			$code = sanitize_key( wp_unslash( $_GET['facture'] ) );
			if ( isset( $codes[ $code ] ) ) {
				echo '<div class="tcm-notice tcm-notice-' . esc_attr( $codes[ $code ][0] ) . '">' . esc_html( $codes[ $code ][1] ) . '</div>';
			}
		}

		// En-tête : avatar (initiales) + identité (badges) + actions.
		$initiales = '';
		foreach ( preg_split( '/\s+/', trim( $nom ) ) as $mot ) {
			if ( '' !== $mot ) {
				$initiales .= mb_strtoupper( mb_substr( $mot, 0, 1 ) );
			}
		}
		$initiales = mb_substr( $initiales, 0, 2 );

		echo '<div class="tcm-fiche-header">';
		echo '<div class="tcm-avatar">' . esc_html( $initiales ) . '</div>';
		echo '<div class="tcm-fiche-ident">';
		echo '<h2>' . esc_html( $nom ) . '</h2>';
		echo '<p class="tcm-badges">';
		echo '<span class="tcm-badge tcm-badge-saison">Saison ' . esc_html( $saison ) . '</span>';
		echo '<span class="tcm-badge ' . ( 'Complet' === $dossier ? 'tcm-badge-ok' : 'tcm-badge-warn' ) . '">Dossier ' . esc_html( $dossier ) . '</span>';
		if ( get_field( 'mineur', $id ) ) { echo '<span class="tcm-badge">Mineur</span>'; }
		if ( get_field( 'adoc_valide', $id ) ) { echo '<span class="tcm-badge tcm-badge-ok">ADOC ✓</span>'; }
		echo '</p>';
		echo '</div>';
		echo '<div class="tcm-fiche-actions">';
		echo '<a class="button button-primary" href="' . $edit_adh . '">Éditer l’adhésion</a> ';
		echo '<a class="button" href="' . $edit_per . '">Éditer les coordonnées</a>';
		echo '</div>';
		echo '</div>';

		echo '<div class="tcm-tabs">';
		echo '<div class="tcm-tabs-nav">';
		echo '<button type="button" class="tcm-tab is-active" data-tab="tcm-tab-coordonnees">Coordonnées</button>';
		echo '<button type="button" class="tcm-tab" data-tab="tcm-tab-reglements">Règlements</button>';
		echo '<button type="button" class="tcm-tab" data-tab="tcm-tab-commandes">Commandes</button>';
		echo '<button type="button" class="tcm-tab" data-tab="tcm-tab-inscriptions">Inscriptions</button>';
		echo '<button type="button" class="tcm-tab" data-tab="tcm-tab-historique">Historique</button>';
		echo '</div>';

		echo '<div class="tcm-tabpanel is-active" id="tcm-tab-coordonnees">';
		echo '<div class="tcm-fiche-section"><h3>Coordonnées</h3><ul class="tcm-coord">';
		foreach ( array( 'date_naissance' => 'Naissance', 'email' => 'Email', 'telephone' => 'Tél', 'adresse' => 'Adresse', 'cp' => 'CP', 'ville' => 'Ville' ) as $f => $label ) {
			$v = get_field( $f, $pid );
			if ( 'date_naissance' === $f ) { $v = $this->fr_date( $v ); }
			if ( 'telephone' === $f ) { $v = $this->fr_phone( $v ); }
			if ( $v ) { echo '<li><span>' . esc_html( $label ) . '</span> ' . esc_html( $v ) . '</li>'; }
		}
		echo '</ul>';
		if ( get_field( 'mineur', $id ) ) {
			echo '<p class="tcm-parents"><strong>Parents :</strong> ';
			echo esc_html( trim( (string) get_field( 'parent_mere_nom', $id ) . ' ' . $this->fr_phone( get_field( 'parent_mere_tel', $id ) ) . '  ' . (string) get_field( 'parent_pere_nom', $id ) . ' ' . $this->fr_phone( get_field( 'parent_pere_tel', $id ) ) ) );
			echo '</p>';
		}
		echo '</div>';
		echo '</div>';

		$regs = $this->children_of( TCM_CPT_REGLEMENT, $id );
		$total = 0.0;
		echo '<div class="tcm-tabpanel" id="tcm-tab-reglements">';
		echo '<div class="tcm-fiche-section"><h3>Règlements <a class="button button-small" href="' . $add_reg . '">+ Ajouter</a></h3>';
		if ( $regs ) {
			echo '<table class="tcm-table"><thead><tr><th>Date</th><th>Canal</th><th>Montant</th><th>Statut</th></tr></thead><tbody>';
			foreach ( $regs as $r ) {
				$m = (float) get_field( 'montant', $r->ID );
				$total += $m;
				$canal = self::CANAUX[ get_field( 'canal', $r->ID ) ] ?? get_field( 'canal', $r->ID );
				echo '<tr><td>' . esc_html( $this->fr_date( get_field( 'date_reglement', $r->ID ) ) ) . '</td>';
				echo '<td>' . esc_html( $canal ) . '</td>';
				echo '<td>' . esc_html( number_format( $m, 2, ',', ' ' ) ) . ' €</td>';
				echo '<td>' . esc_html( get_field( 'statut', $r->ID ) ) . '</td></tr>';
			}
			echo '</tbody><tfoot><tr><th colspan="2">Total payé</th><th colspan="2">' . esc_html( number_format( $total, 2, ',', ' ' ) ) . ' €</th></tr></tfoot></table>';
		} else {
			echo '<p>Aucun règlement.</p>';
		}
		echo '</div>';
		echo '</div>';

		$cmds = $this->children_of( TCM_CPT_COMMANDE, $id );
		echo '<div class="tcm-tabpanel" id="tcm-tab-commandes">';
		echo '<div class="tcm-fiche-section"><h3>Commandes <a class="button button-small" href="' . $add_cmd . '">+ Ajouter</a></h3>';
		if ( $cmds ) {
			echo '<table class="tcm-table"><thead><tr><th>Libellé</th><th>Montant</th><th>Attestation</th></tr></thead><tbody>';
			foreach ( $cmds as $c ) {
				$pdf_url  = esc_url( TCM_Facture::url_pdf( $c->ID ) );
				$mail_url = esc_url( TCM_Facture::url_mail( $c->ID ) );
				echo '<tr><td>' . esc_html( get_field( 'libelle', $c->ID ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) get_field( 'montant', $c->ID ), 2, ',', ' ' ) ) . ' €</td>';
				echo '<td class="tcm-facture-actions"><a class="button button-small" href="' . $pdf_url . '" target="_blank" rel="noopener">Attestation PDF</a> ';
				echo '<a class="button button-small" href="' . $mail_url . '" onclick="return confirm(\'Envoyer cette attestation par e-mail à l’adhérent ?\');">Envoyer</a></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Aucune commande.</p>';
		}
		echo '</div>';
		echo '</div>';

		$ins = $this->children_of( TCM_CPT_INSCRIPTION, $id );
		echo '<div class="tcm-tabpanel" id="tcm-tab-inscriptions">';
		echo '<div class="tcm-fiche-section"><h3>Inscriptions</h3>';
		if ( $ins ) {
			echo '<ul class="tcm-inscriptions">';
			foreach ( $ins as $i ) {
				$cid = (int) get_field( 'creneau', $i->ID );
				echo '<li>' . esc_html( get_the_title( $cid ) ) . ' — <em>' . esc_html( get_field( 'statut', $i->ID ) ) . '</em></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>Aucune inscription.</p>';
		}
		echo '</div>';
		echo '</div>';

		$autres = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'publish',
			'meta_key'       => 'personne',
			'meta_value'     => $pid,
			'exclude'        => array( $id ),
		) );
		echo '<div class="tcm-tabpanel" id="tcm-tab-historique">';
		if ( $autres ) {
			echo '<div class="tcm-fiche-section"><h3>Historique</h3><ul>';
			foreach ( $autres as $aid ) {
				$url = esc_url( add_query_arg( 'id', $aid, get_permalink() ) );
				echo '<li><a href="' . $url . '">Saison ' . esc_html( get_field( 'saison', $aid ) ) . '</a></li>';
			}
			echo '</ul></div>';
		} else {
			echo '<div class="tcm-fiche-section"><h3>Historique</h3><p>Aucun historique.</p></div>';
		}
		echo '</div>';

		echo '</div>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function sc_recap( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$atts = shortcode_atts( array( 'par_page' => 50 ), $atts );

		$saison  = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : '';
		$dossier = isset( $_GET['dossier'] ) ? sanitize_text_field( wp_unslash( $_GET['dossier'] ) ) : '';
		$adoc    = isset( $_GET['adoc'] ) ? sanitize_text_field( wp_unslash( $_GET['adoc'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = max( 1, (int) ( $_GET['pg'] ?? 1 ) );

		$tax_query = array();
		if ( '' !== $saison ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}
		if ( '' !== $dossier ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => $dossier );
		}

		$meta_query = array();
		if ( 'oui' === $adoc ) {
			$meta_query[] = array( 'key' => 'adoc_valide', 'value' => '1', 'compare' => '=' );
		} elseif ( 'non' === $adoc ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => 'adoc_valide', 'value' => '1', 'compare' => '!=' ),
				array( 'key' => 'adoc_valide', 'compare' => 'NOT EXISTS' ),
			);
		}

		$q = new WP_Query( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['par_page'],
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
			'tax_query'      => $tax_query ?: array(),
			'meta_query'     => $meta_query ?: array(),
		) );

		ob_start();
		echo '<div class="tcm-recap">';

		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false ) );
		echo '<form method="get" class="tcm-filtres">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nom…"> ';
		echo '<select name="saison"><option value="">Toutes saisons</option>';
		if ( ! is_wp_error( $saisons ) ) {
			foreach ( $saisons as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select> ';
		echo '<select name="dossier"><option value="">Tous dossiers</option>';
		foreach ( array( 'Complet', 'Incomplet' ) as $d ) {
			echo '<option value="' . esc_attr( $d ) . '" ' . selected( $dossier, $d, false ) . '>' . esc_html( $d ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="adoc"><option value="">ADOC (tous)</option>';
		echo '<option value="oui" ' . selected( $adoc, 'oui', false ) . '>ADOC validé</option>';
		echo '<option value="non" ' . selected( $adoc, 'non', false ) . '>ADOC non validé</option>';
		echo '</select> <button class="button">Filtrer</button> ';
		echo '<span class="tcm-count">' . (int) $q->found_posts . ' adhérents</span>';
		echo '</form>';

		if ( ! $q->have_posts() ) {
			echo '<p>Aucun résultat.</p></div>';
			return (string) ob_get_clean();
		}

		$fiche_url = $this->page_url( 'fiche' );
		echo '<table class="tcm-table tcm-recap-table"><thead><tr>';
		echo '<th>Nom</th><th>Prénom</th><th>Naissance</th><th>Saison</th><th>Dossier</th><th>Mineur</th><th>ADOC</th><th>Payé</th><th>Email</th><th>Tél</th><th></th>';
		echo '</tr></thead><tbody>';

		while ( $q->have_posts() ) {
			$q->the_post();
			$aid = get_the_ID();
			$pid = (int) get_field( 'personne', $aid );

			$regs  = $this->children_of( TCM_CPT_REGLEMENT, $aid );
			$paye  = 0.0;
			foreach ( $regs as $r ) { $paye += (float) get_field( 'montant', $r->ID ); }

			$fiche = esc_url( add_query_arg( 'id', $aid, $fiche_url ) );
			echo '<tr>';
			echo '<td>' . esc_html( get_field( 'nom', $pid ) ) . '</td>';
			echo '<td>' . esc_html( get_field( 'prenom', $pid ) ) . '</td>';
			echo '<td>' . esc_html( $this->fr_date( get_field( 'date_naissance', $pid ) ) ) . '</td>';
			echo '<td>' . esc_html( get_field( 'saison', $aid ) ) . '</td>';
			echo '<td>' . ( get_field( 'dossier_complet', $aid ) ? '✓' : '—' ) . '</td>';
			echo '<td>' . ( get_field( 'mineur', $aid ) ? '✓' : '' ) . '</td>';
			echo '<td>' . ( get_field( 'adoc_valide', $aid ) ? '✓' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format( $paye, 0, ',', ' ' ) ) . ' €</td>';
			echo '<td>' . esc_html( get_field( 'email', $pid ) ) . '</td>';
			echo '<td>' . esc_html( $this->fr_phone( get_field( 'telephone', $pid ) ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . $fiche . '">Fiche</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $q->max_num_pages > 1 ) {
			echo '<div class="tcm-pagination">' . paginate_links( array(
				'base' => add_query_arg( 'pg', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages,
			) ) . '</div>';
		}
		echo '</div>';
		wp_reset_postdata();
		return (string) ob_get_clean();
	}
}
