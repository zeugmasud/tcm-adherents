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

	const CANAUX = array( 'cheque' => 'Chèque', 'especes' => 'Espèces', 'cb' => 'CB', 'helloasso' => 'HelloAsso', 'virement' => 'Virement', 'aide' => 'Aide', 'autre' => 'Autre' );

	public function hooks(): void {
		add_shortcode( 'tcm_fiche', array( $this, 'sc_fiche' ) );
		add_shortcode( 'tcm_recap', array( $this, 'sc_recap' ) );
		add_shortcode( 'tcm_stats', array( $this, 'sc_stats' ) );
		add_shortcode( 'tcm_reglements', array( $this, 'sc_reglements' ) );
		add_action( 'admin_post_tcm_reinscrire', array( $this, 'handle_reinscrire' ) );
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
		$css = TCM_PATH . 'assets/tcm-frontoffice.css';
		$js  = TCM_PATH . 'assets/tcm-frontoffice.js';
		wp_enqueue_style( 'tcm-frontoffice', TCM_URL . 'assets/tcm-frontoffice.css', array( 'tcm-font-rubik' ), file_exists( $css ) ? filemtime( $css ) : TCM_VERSION );
		wp_enqueue_script( 'tcm-frontoffice', TCM_URL . 'assets/tcm-frontoffice.js', array(), file_exists( $js ) ? filemtime( $js ) : TCM_VERSION, true );
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
				'sexe'    => $this->sexe_from( get_field( 'civilite', $pid ) ),
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
			if ( 'g' === $r['sexe'] ) {
				$cls .= ' sexe-g';
			} elseif ( 'f' === $r['sexe'] ) {
				$cls .= ' sexe-f';
			}
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
		echo $id ? do_shortcode( '[tcm_fiche id="' . $id . '"]' ) : '<p>Sélectionnez un adhérent dans la liste.</p>';
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

	/** Sexe déduit de la civilité : 'g' (M./Monsieur) ou 'f' (Mme/Madame), '' sinon. */
	private function sexe_from( $civilite ): string {
		$c = strtolower( trim( (string) $civilite ) );
		if ( 'm.' === $c || 'm' === $c || 'monsieur' === $c ) {
			return 'g';
		}
		if ( 'mme' === $c || 'mme.' === $c || 'madame' === $c || 'mlle' === $c ) {
			return 'f';
		}
		return '';
	}

	/** Icône « check » — dossier complet. */
	private function icon_check(): string {
		return '<svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M8.2 13.3 4.9 10l-1.2 1.2 4.5 4.5 9-9L16 5.5z"/></svg>';
	}

	/** Icône « bouclier validé » — ADOC. */
	private function icon_shield(): string {
		return '<svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M10 1 3 4v5c0 4.4 3 8.5 7 10 4-1.5 7-5.6 7-10V4z"/><path fill="#fff" d="M8.8 12.2 6.3 9.7l1-1 1.5 1.5 3.4-3.4 1 1z"/></svg>';
	}

	/** Icône crayon — éditer l'adhésion. */
	private function icon_edit(): string {
		return '<svg class="tcm-act-ico" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 13.4 12.9 4.5l2.6 2.6L6.6 16H4zM14.1 3.3l1.1-1.1a1 1 0 0 1 1.4 0l1.2 1.2a1 1 0 0 1 0 1.4l-1.1 1.1z"/></svg>';
	}

	/** Icône personne — éditer les coordonnées. */
	private function icon_user(): string {
		return '<svg class="tcm-act-ico" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm0 1.6c-3.1 0-6.2 1.6-6.2 3.9V17h12.4v-1.5c0-2.3-3.1-3.9-6.2-3.9z"/></svg>';
	}

	/** Icône rafraîchir — réinscrire. */
	private function icon_refresh(): string {
		return '<svg class="tcm-act-ico" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M10 4V1L5.5 5 10 9V6a4 4 0 1 1-4 4H4a6 6 0 1 0 6-6z"/></svg>';
	}

	public function sc_stats( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$atts   = shortcode_atts( array( 'saison' => '' ), $atts );
		$saison = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : ( '' !== $atts['saison'] ? $atts['saison'] : 'all' );
		$cmp    = isset( $_GET['cmp'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp'] ) ) : '';

		$cur = $this->compute_stats( $saison );
		$ref = ( '' !== $cmp && $cmp !== $saison ) ? $this->compute_stats( $cmp ) : null;

		ob_start();
		echo $this->stats_selectors( $saison, $cmp );
		echo '<div class="tcm-stats">';
		echo $this->stat_tile( 'Personnes', $cur['personnes'], $ref['personnes'] ?? null );
		echo $this->stat_tile( 'Adhésions', $cur['adhesions'], $ref['adhesions'] ?? null );
		echo $this->stat_tile( 'Dossiers incomplets', $cur['incomplets'], $ref['incomplets'] ?? null, true, true );
		echo $this->stat_tile( 'Encaissé', $cur['encaisse'], $ref['encaisse'] ?? null, false, false, true );
		echo $this->stat_tile( 'ADOC validés', $cur['adoc'], $ref['adoc'] ?? null );
		echo '</div>';
		return (string) ob_get_clean();
	}

	private function stats_selectors( string $saison, string $cmp ): string {
		$terms = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ) );
		ob_start();
		echo '<form method="get" class="tcm-stats-bar">';
		echo '<label>Saison <select name="saison" onchange="this.form.submit()"><option value="all" ' . selected( $saison, 'all', false ) . '>Toutes</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select></label>';
		echo '<label>Comparer avec <select name="cmp" onchange="this.form.submit()"><option value="">—</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $cmp, $t->name, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select></label>';
		echo '</form>';
		return (string) ob_get_clean();
	}

	private function stat_tile( string $label, float $val, ?float $ref, bool $neutral = false, bool $is_warn_tile = false, bool $euro = false ): string {
		$disp = $euro ? number_format( $val, 0, ',', ' ' ) . ' €' : (string) (int) $val;
		ob_start();
		echo '<div class="tcm-stat' . ( $is_warn_tile ? ' is-warn' : '' ) . '">';
		echo '<span class="tcm-stat-title">' . esc_html( $label ) . '</span>';
		echo '<strong>' . esc_html( $disp ) . '</strong>';
		if ( null !== $ref ) {
			$d = $val - $ref;
			if ( abs( $d ) < 0.001 ) {
				$cls = 'flat'; $arrow = '='; $txt = '±0';
			} else {
				$up    = $d > 0;
				$arrow = $up ? '▲' : '▼';
				$cls   = $neutral ? 'flat' : ( $up ? 'up' : 'down' );
				$txt   = ( $up ? '+' : '−' ) . ( $euro ? number_format( abs( $d ), 0, ',', ' ' ) . ' €' : (string) (int) abs( $d ) );
			}
			echo '<span class="tcm-delta tcm-delta-' . $cls . '">' . $arrow . ' ' . esc_html( $txt ) . '</span>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/** Calcule les KPI pour une saison ('all' = toutes). */
	private function compute_stats( string $saison ): array {
		$all        = ( '' === $saison || 'all' === $saison );
		$saison_tax = $all ? array() : array( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison ) );

		$adh_ids = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => $saison_tax ?: array() ) );

		$persons = array();
		foreach ( $adh_ids as $a ) {
			$persons[ (int) get_field( 'personne', $a ) ] = 1;
		}

		$inc_tax   = $saison_tax;
		$inc_tax[] = array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => 'Incomplet' );
		if ( count( $inc_tax ) > 1 ) {
			$inc_tax['relation'] = 'AND';
		}
		$incomplets = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => $inc_tax ) );

		$adoc = get_posts( array(
			'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
			'tax_query' => $saison_tax ?: array(),
			'meta_query' => array( array( 'key' => 'adoc_valide', 'value' => '1', 'compare' => '=' ) ),
		) );

		$encaisse = 0.0;
		if ( $all ) {
			$regs = get_posts( array( 'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => 'statut', 'value' => 'valide' ) ) ) );
		} elseif ( $adh_ids ) {
			$regs = get_posts( array(
				'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
				'meta_query' => array( 'relation' => 'AND',
					array( 'key' => 'adherent', 'value' => $adh_ids, 'compare' => 'IN' ),
					array( 'key' => 'statut', 'value' => 'valide' ),
				),
			) );
		} else {
			$regs = array();
		}
		foreach ( $regs as $rid ) {
			$encaisse += (float) get_field( 'montant', $rid );
		}

		return array(
			'personnes'  => (float) count( $persons ),
			'adhesions'  => (float) count( $adh_ids ),
			'incomplets' => (float) count( $incomplets ),
			'adoc'       => (float) count( $adoc ),
			'encaisse'   => $encaisse,
		);
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

		// Notice après une opération CRUD règlement/commande.
		if ( isset( $_GET['msg'] ) ) {
			$m = array(
				'saved'   => array( 'ok', 'Enregistré.' ),
				'deleted' => array( 'ok', 'Supprimé.' ),
				'error'   => array( 'err', 'Opération impossible.' ),
			);
			$k = sanitize_key( wp_unslash( $_GET['msg'] ) );
			if ( isset( $m[ $k ] ) ) {
				echo '<div class="tcm-notice tcm-notice-' . esc_attr( $m[ $k ][0] ) . '">' . esc_html( $m[ $k ][1] ) . '</div>';
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
		echo '<a class="button button-primary tcm-act" href="' . $edit_adh . '" title="Éditer l’adhésion" aria-label="Éditer l’adhésion">' . $this->icon_edit() . '<span class="tcm-act-label">Éditer l’adhésion</span></a> ';
		echo '<a class="button tcm-act" href="' . $edit_per . '" title="Éditer les coordonnées" aria-label="Éditer les coordonnées">' . $this->icon_user() . '<span class="tcm-act-label">Éditer les coordonnées</span></a>';
		$sc = (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );
		if ( ! TCM_Logic::adherent_pour_saison( $pid, $sc ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tcm-reinscrire" onsubmit="return confirm(\'Réinscrire pour la saison ' . esc_js( $sc ) . ' ?\');">';
			wp_nonce_field( 'tcm_reinscrire' );
			echo '<input type="hidden" name="action" value="tcm_reinscrire">';
			echo '<input type="hidden" name="adherent" value="' . (int) $id . '">';
			echo '<button type="submit" class="button tcm-act" title="Réinscrire ' . esc_attr( $sc ) . '" aria-label="Réinscrire ' . esc_attr( $sc ) . '">' . $this->icon_refresh() . '<span class="tcm-act-label">Réinscrire ' . esc_html( $sc ) . '</span></button>';
			echo '</form>';
		}
		echo '</div>';
		echo '</div>';

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'coordonnees';
		if ( ! in_array( $active_tab, array( 'coordonnees', 'reglements', 'commandes', 'inscriptions', 'historique' ), true ) ) {
			$active_tab = 'coordonnees';
		}
		$act       = static function ( $t ) use ( $active_tab ) { return $active_tab === $t ? ' is-active' : ''; };
		$fiche_url = add_query_arg( 'id', $id, get_permalink() );
		$edit_reg  = (int) ( $_GET['edit_reg'] ?? 0 );
		$edit_cmd  = (int) ( $_GET['edit_cmd'] ?? 0 );

		echo '<div class="tcm-tabs">';
		echo '<div class="tcm-tabs-nav">';
		echo '<button type="button" class="tcm-tab' . $act( 'coordonnees' ) . '" data-tab="tcm-tab-coordonnees">Coordonnées</button>';
		echo '<button type="button" class="tcm-tab' . $act( 'reglements' ) . '" data-tab="tcm-tab-reglements">Règlements</button>';
		echo '<button type="button" class="tcm-tab' . $act( 'commandes' ) . '" data-tab="tcm-tab-commandes">Commandes</button>';
		echo '<button type="button" class="tcm-tab' . $act( 'inscriptions' ) . '" data-tab="tcm-tab-inscriptions">Cours</button>';
		echo '<button type="button" class="tcm-tab' . $act( 'historique' ) . '" data-tab="tcm-tab-historique">Historique</button>';
		echo '</div>';

		echo '<div class="tcm-tabpanel' . $act( 'coordonnees' ) . '" id="tcm-tab-coordonnees">';
		echo '<div class="tcm-fiche-section"><h3>Coordonnées</h3><ul class="tcm-coord">';
		foreach ( array( 'date_naissance' => 'Naissance', 'email' => 'Email', 'telephone' => 'Tél', 'adresse' => 'Adresse', 'cp' => 'CP', 'ville' => 'Ville' ) as $f => $label ) {
			$v = get_field( $f, $pid );
			if ( 'date_naissance' === $f ) { $v = $this->fr_date( $v ); }
			if ( 'telephone' === $f ) { $v = $this->fr_phone( $v ); }
			// Email et Téléphone : toujours affichés (placeholder si vide, à compléter via ADOC).
			$always = ( 'email' === $f || 'telephone' === $f );
			if ( $v ) {
				echo '<li><span>' . esc_html( $label ) . '</span> ' . esc_html( $v ) . '</li>';
			} elseif ( $always ) {
				echo '<li class="tcm-coord-empty"><span>' . esc_html( $label ) . '</span> <em>non renseigné</em></li>';
			}
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
		echo '<div class="tcm-tabpanel' . $act( 'reglements' ) . '" id="tcm-tab-reglements">';
		echo ( new TCM_Crud() )->reglements_section( $id, $regs, $fiche_url, $edit_reg );
		echo '</div>';

		$cmds = $this->children_of( TCM_CPT_COMMANDE, $id );
		echo '<div class="tcm-tabpanel' . $act( 'commandes' ) . '" id="tcm-tab-commandes">';
		echo ( new TCM_Crud() )->commandes_section( $id, $cmds, $fiche_url, $edit_cmd, (string) $saison );
		echo '</div>';

		echo '<div class="tcm-tabpanel' . $act( 'inscriptions' ) . '" id="tcm-tab-inscriptions">';
		echo ( new TCM_Planning() )->adherent_inscriptions_section( $id, (string) $saison, $fiche_url );
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
		echo '<div class="tcm-tabpanel' . $act( 'historique' ) . '" id="tcm-tab-historique">';
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

	/** Vue trésorier : tous les règlements, filtrables par saison. */
	public function sc_reglements( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$saison = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : 'all';
		$labels = array( 'valide' => 'Validé', 'en_attente' => 'En attente', 'rembourse' => 'Remboursé' );

		$regs  = get_posts( array( 'post_type' => TCM_CPT_REGLEMENT, 'posts_per_page' => -1, 'post_status' => 'publish' ) );
		$rows  = array();
		$total = 0.0;
		foreach ( $regs as $r ) {
			$aid = (int) get_field( 'adherent', $r->ID );
			$sai = (string) get_field( 'saison', $aid );
			if ( 'all' !== $saison && $sai !== $saison ) {
				continue;
			}
			$pid    = $aid ? (int) get_field( 'personne', $aid ) : 0;
			$m      = (float) get_field( 'montant', $r->ID );
			$total += $m;
			$rows[] = array(
				'date'    => (string) get_field( 'date_reglement', $r->ID ),
				'nom'     => $this->person_name( $pid ),
				'aid'     => $aid,
				'saison'  => $sai,
				'canal'   => self::CANAUX[ get_field( 'canal', $r->ID ) ] ?? (string) get_field( 'canal', $r->ID ),
				'montant' => $m,
				'statut'  => $labels[ get_field( 'statut', $r->ID ) ] ?? (string) get_field( 'statut', $r->ID ),
			);
		}
		usort( $rows, static function ( $a, $b ) {
			return strcasecmp( remove_accents( $a['nom'] ), remove_accents( $b['nom'] ) );
		} );

		ob_start();
		echo '<div class="tcm-recap">';
		echo '<form method="get" class="tcm-filtres">';
		echo '<select name="saison" onchange="this.form.submit()"><option value="all" ' . selected( $saison, 'all', false ) . '>Toutes saisons</option>';
		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ) );
		if ( ! is_wp_error( $saisons ) ) {
			foreach ( $saisons as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>Saison ' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select>';
		echo '<span class="tcm-count">' . count( $rows ) . ' règlements · <strong>' . esc_html( number_format( $total, 2, ',', ' ' ) ) . ' €</strong> encaissés</span>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p>Aucun règlement.</p></div>';
			return (string) ob_get_clean();
		}
		$bo = $this->page_url( 'back-office-adherents' );
		echo '<div class="tcm-table-wrap"><table class="tcm-table tcm-sortable"><thead><tr><th>Date</th><th>Adhérent</th><th>Saison</th><th>Canal</th><th>Montant</th><th>Statut</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$fic = esc_url( add_query_arg( array( 'id' => $r['aid'], 'tab' => 'reglements' ), $bo ) );
			echo '<tr>';
			echo '<td>' . esc_html( $this->fr_date( $r['date'] ) ) . '</td>';
			echo '<td><a class="tcm-link" href="' . $fic . '">' . esc_html( $r['nom'] ) . '</a></td>';
			echo '<td>' . esc_html( $r['saison'] ) . '</td>';
			echo '<td>' . esc_html( $r['canal'] ) . '</td>';
			echo '<td>' . esc_html( number_format( $r['montant'], 2, ',', ' ' ) ) . ' €</td>';
			echo '<td>' . esc_html( $r['statut'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		return (string) ob_get_clean();
	}

	/** Réinscrit la personne d'un adhérent pour la saison courante (si absente). */
	public function handle_reinscrire(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_reinscrire' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$adh      = (int) ( $_POST['adherent'] ?? 0 );
		$bo       = $this->page_url( 'back-office-adherents' );
		if ( ! $adh || get_post_type( $adh ) !== TCM_CPT_ADHERENT ) {
			wp_safe_redirect( $bo );
			exit;
		}
		$pid    = (int) get_field( 'personne', $adh );
		$saison = (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );

		$existing = TCM_Logic::adherent_pour_saison( $pid, $saison );
		if ( $existing ) {
			$target = (int) $existing;
		} else {
			$new = wp_insert_post( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'post_title' => 'Adhérent' ) );
			update_field( 'personne', $pid, $new );
			update_field( 'saison', $saison, $new );
			update_field( 'mineur', get_field( 'mineur', $adh ) ? 1 : 0, $new );
			foreach ( array( 'parent_mere_nom', 'parent_mere_tel', 'parent_pere_nom', 'parent_pere_tel', 'autre_contact', 'id_adoc' ) as $f ) {
				$v = get_field( $f, $adh );
				if ( '' !== $v && null !== $v ) {
					update_field( $f, $v, $new );
				}
			}
			update_field( 'dossier_complet', 0, $new );
			update_field( 'adoc_valide', 0, $new );
			update_field( 'nouvel_adherent', 0, $new );
			TCM_Taxonomies::sync_adherent( $new );
			$target = (int) $new;
		}
		wp_safe_redirect( add_query_arg( array( 'id' => $target, 'saison' => $saison, 'msg' => 'saved' ), $bo ) );
		exit;
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
			'posts_per_page' => -1,
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

		$fiche_url = $this->page_url( 'back-office-adherents' );
		$data = array();
		while ( $q->have_posts() ) {
			$q->the_post();
			$aid  = get_the_ID();
			$pid  = (int) get_field( 'personne', $aid );
			$regs = $this->children_of( TCM_CPT_REGLEMENT, $aid );
			$paye = 0.0;
			foreach ( $regs as $r ) {
				$paye += (float) get_field( 'montant', $r->ID );
			}
			$data[] = array(
				'aid'     => $aid,
				'nom'     => (string) get_field( 'nom', $pid ),
				'prenom'  => (string) get_field( 'prenom', $pid ),
				'dob'     => get_field( 'date_naissance', $pid ),
				'saison'  => (string) get_field( 'saison', $aid ),
				'complet' => (bool) get_field( 'dossier_complet', $aid ),
				'mineur'  => (bool) get_field( 'mineur', $aid ),
				'adoc'    => (bool) get_field( 'adoc_valide', $aid ),
				'paye'    => $paye,
				'email'   => (string) get_field( 'email', $pid ),
				'tel'     => get_field( 'telephone', $pid ),
			);
		}
		wp_reset_postdata();
		usort( $data, static function ( $a, $b ) {
			return strcasecmp( remove_accents( $a['nom'] ), remove_accents( $b['nom'] ) );
		} );

		echo '<div class="tcm-table-wrap"><table class="tcm-table tcm-recap-table tcm-sortable"><thead><tr>';
		echo '<th>Nom</th><th>Prénom</th><th>Naissance</th><th>Saison</th><th>Dossier</th><th>Mineur</th><th>ADOC</th><th>Payé</th><th>Email</th><th>Tél</th><th class="tcm-no-sort"></th>';
		echo '</tr></thead><tbody>';
		foreach ( $data as $d ) {
			$fiche = esc_url( add_query_arg( 'id', $d['aid'], $fiche_url ) );
			echo '<tr>';
			echo '<td>' . esc_html( $d['nom'] ) . '</td>';
			echo '<td>' . esc_html( $d['prenom'] ) . '</td>';
			echo '<td>' . esc_html( $this->fr_date( $d['dob'] ) ) . '</td>';
			echo '<td>' . esc_html( $d['saison'] ) . '</td>';
			echo '<td>' . ( $d['complet'] ? '✓' : '—' ) . '</td>';
			echo '<td>' . ( $d['mineur'] ? '✓' : '' ) . '</td>';
			echo '<td>' . ( $d['adoc'] ? '✓' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format( $d['paye'], 0, ',', ' ' ) ) . ' €</td>';
			echo '<td>' . esc_html( $d['email'] ) . '</td>';
			echo '<td>' . esc_html( $this->fr_phone( $d['tel'] ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . $fiche . '">Fiche</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '</div>';
		return (string) ob_get_clean();
	}
}
