<?php
/**
 * Planning : créneaux (groupés par jour / groupe) + inscriptions.
 *
 * - Shortcode [tcm_planning] : master-detail créneaux / inscrits, avec gestion
 *   des créneaux (créer / éditer / supprimer) et des inscriptions (inscrire /
 *   désinscrire un adhérent).
 * - Section réutilisable pour la fiche adhérent (onglet Inscriptions) : inscrire
 *   l'adhérent à un créneau / le désinscrire, avec liens croisés vers le planning.
 *
 * Handlers admin-post : tcm_creneau_save / tcm_creneau_delete /
 * tcm_inscription_add / tcm_inscription_delete.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Planning {

	const JOURS = array(
		'lundi'    => 'Lundi',
		'mardi'    => 'Mardi',
		'mercredi' => 'Mercredi',
		'jeudi'    => 'Jeudi',
		'vendredi' => 'Vendredi',
		'samedi'   => 'Samedi',
		'dimanche' => 'Dimanche',
	);

	public function hooks(): void {
		add_shortcode( 'tcm_planning', array( $this, 'sc_planning' ) );
		add_action( 'admin_post_tcm_creneau_save', array( $this, 'creneau_save' ) );
		add_action( 'admin_post_tcm_creneau_delete', array( $this, 'creneau_delete' ) );
		add_action( 'admin_post_tcm_inscription_add', array( $this, 'inscription_add' ) );
		add_action( 'admin_post_tcm_inscription_delete', array( $this, 'inscription_delete' ) );
	}

	/* =====================================================================
	 * Helpers données
	 * =================================================================== */

	private function inscrits( int $creneau_id ): array {
		return get_posts( array(
			'post_type'      => TCM_CPT_INSCRIPTION,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_key'       => 'creneau',
			'meta_value'     => $creneau_id,
		) );
	}

	private function adherent_inscriptions( int $adherent_id ): array {
		return get_posts( array(
			'post_type'      => TCM_CPT_INSCRIPTION,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_key'       => 'adherent',
			'meta_value'     => $adherent_id,
		) );
	}

	private function inscription_exists( int $adherent_id, int $creneau_id ): bool {
		$q = get_posts( array(
			'post_type'      => TCM_CPT_INSCRIPTION,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => 'adherent', 'value' => $adherent_id ),
				array( 'key' => 'creneau', 'value' => $creneau_id ),
			),
		) );
		return ! empty( $q );
	}

	private function person_name( int $adherent_id ): string {
		$pid = (int) get_field( 'personne', $adherent_id );
		return $pid ? trim( (string) get_field( 'nom', $pid ) . ' ' . (string) get_field( 'prenom', $pid ) ) : '—';
	}

	/** Adhérents d'une saison (id => nom), triés, hors ceux déjà inscrits au créneau donné. */
	private function adherents_for_saison( string $saison, int $exclude_creneau = 0 ): array {
		$ids = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => 'saison', 'value' => $saison ) ),
		) );
		$out = array();
		foreach ( $ids as $aid ) {
			if ( $exclude_creneau && $this->inscription_exists( $aid, $exclude_creneau ) ) {
				continue;
			}
			$out[ $aid ] = $this->person_name( $aid );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/** Créneaux d'une saison (id => libellé), hors ceux où l'adhérent est déjà inscrit. */
	private function creneaux_for_saison( string $saison, int $exclude_adherent = 0 ): array {
		$ids = get_posts( array(
			'post_type'      => TCM_CPT_CRENEAU,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => 'saison', 'value' => $saison ) ),
		) );
		$jorder = array_flip( array_keys( self::JOURS ) ); // lundi=0 … dimanche=6
		$items  = array();
		foreach ( $ids as $cid ) {
			if ( $exclude_adherent && $this->inscription_exists( $exclude_adherent, $cid ) ) {
				continue;
			}
			$jour    = (string) get_field( 'jour', $cid );
			$items[] = array(
				'cid'   => $cid,
				'sort'  => sprintf( '%02d-%s', $jorder[ $jour ] ?? 99, (string) get_field( 'heure_debut', $cid ) ),
				'label' => $this->creneau_label( $cid ),
			);
		}
		usort( $items, static function ( $a, $b ) {
			return strcmp( $a['sort'], $b['sort'] );
		} );
		$out = array();
		foreach ( $items as $it ) {
			$out[ $it['cid'] ] = $it['label'];
		}
		return $out;
	}

	private function creneau_label( int $cid ): string {
		$jour = self::JOURS[ get_field( 'jour', $cid ) ] ?? (string) get_field( 'jour', $cid );
		return trim( $jour . ' ' . get_field( 'heure_debut', $cid ) . '–' . get_field( 'heure_fin', $cid )
			. ' · ' . (string) get_field( 'type_cours', $cid ) );
	}

	/* =====================================================================
	 * Handlers (écriture)
	 * =================================================================== */

	private function guard( string $action ): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( $action ) ) {
			wp_die( 'Accès refusé.' );
		}
	}
	private function back( string $msg ): void {
		$u = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( ! $u ) {
			$u = wp_get_referer() ?: home_url();
		}
		wp_safe_redirect( add_query_arg( 'msg', $msg, $u ) );
		exit;
	}

	public function creneau_save(): void {
		$this->guard( 'tcm_creneau_save' );
		$cid = (int) ( $_POST['creneau_id'] ?? 0 );
		$id  = ( $cid && get_post_type( $cid ) === TCM_CPT_CRENEAU )
			? $cid
			: wp_insert_post( array( 'post_type' => TCM_CPT_CRENEAU, 'post_status' => 'publish', 'post_title' => 'Créneau' ) );

		foreach ( array( 'jour', 'heure_debut', 'heure_fin', 'type_cours', 'entraineur', 'saison' ) as $f ) {
			update_field( $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) ), $id );
		}
		update_field( 'capacite', (int) ( $_POST['capacite'] ?? 0 ), $id );

		$jour  = self::JOURS[ sanitize_key( $_POST['jour'] ?? '' ) ] ?? '';
		$titre = trim( sanitize_text_field( wp_unslash( $_POST['type_cours'] ?? 'Créneau' ) ) . ' — ' . $jour . ' ' . sanitize_text_field( wp_unslash( $_POST['heure_debut'] ?? '' ) ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => $titre ?: 'Créneau' ) );

		$u = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url();
		wp_safe_redirect( add_query_arg( array( 'creneau' => $id, 'msg' => 'saved' ), $u ) );
		exit;
	}

	public function creneau_delete(): void {
		$this->guard( 'tcm_creneau_delete' );
		$cid = (int) ( $_POST['creneau_id'] ?? 0 );
		if ( $cid && get_post_type( $cid ) === TCM_CPT_CRENEAU ) {
			foreach ( $this->inscrits( $cid ) as $i ) {
				wp_delete_post( $i->ID, true );
			}
			wp_delete_post( $cid, true );
		}
		$this->back( 'deleted' );
	}

	public function inscription_add(): void {
		$this->guard( 'tcm_inscription_add' );
		$adh = (int) ( $_POST['adherent'] ?? 0 );
		$cre = (int) ( $_POST['creneau'] ?? 0 );
		if ( $adh && $cre && get_post_type( $adh ) === TCM_CPT_ADHERENT && get_post_type( $cre ) === TCM_CPT_CRENEAU
			&& ! $this->inscription_exists( $adh, $cre ) ) {
			$id = wp_insert_post( array( 'post_type' => TCM_CPT_INSCRIPTION, 'post_status' => 'publish', 'post_title' => 'Inscription' ) );
			update_field( 'adherent', $adh, $id );
			update_field( 'creneau', $cre, $id );
			update_field( 'statut', sanitize_text_field( wp_unslash( $_POST['statut'] ?? 'confirme' ) ), $id );
			update_field( 'date_inscription', current_time( 'Ymd' ), $id );
		}
		$this->back( 'saved' );
	}

	public function inscription_delete(): void {
		$this->guard( 'tcm_inscription_delete' );
		$iid = (int) ( $_POST['inscription_id'] ?? 0 );
		if ( $iid && get_post_type( $iid ) === TCM_CPT_INSCRIPTION ) {
			wp_delete_post( $iid, true );
		}
		$this->back( 'deleted' );
	}

	/* =====================================================================
	 * Rendu — shortcode [tcm_planning]
	 * =================================================================== */

	public function sc_planning(): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$page_url = get_permalink();
		$sel      = (int) ( $_GET['creneau'] ?? 0 );
		$edit_cre = ! empty( $_GET['edit_creneau'] );
		$new_cre  = ! empty( $_GET['new_creneau'] );

		$saison_courante = (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );
		$saison          = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : $saison_courante;

		// Bascule d'affichage : par cours (défaut) ou par adhérent.
		$vue    = ( isset( $_GET['vue'] ) && 'adherents' === $_GET['vue'] ) ? 'adherents' : 'creneaux';
		$statut = isset( $_GET['statut'] ) ? sanitize_text_field( wp_unslash( $_GET['statut'] ) ) : 'all';
		if ( ! in_array( $statut, array( 'confirme', 'attente' ), true ) ) {
			$statut = 'all';
		}
		$switch = $this->vue_switch( $page_url, $saison, $vue );
		if ( 'adherents' === $vue ) {
			return $switch . $this->render_par_adherent( $page_url, $saison, $statut );
		}

		$meta = array();
		if ( 'all' !== $saison && '' !== $saison ) {
			$meta[] = array( 'key' => 'saison', 'value' => $saison, 'compare' => '=' );
		}
		$creneaux = get_posts( array(
			'post_type'      => TCM_CPT_CRENEAU,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => $meta ?: array(),
		) );

		// Regroupement jour -> groupe (type_cours) -> créneaux triés par heure.
		$tree = array();
		foreach ( $creneaux as $c ) {
			$j = (string) get_field( 'jour', $c->ID );
			$g = (string) get_field( 'type_cours', $c->ID );
			$tree[ $j ][ $g ][] = $c;
		}
		foreach ( $tree as &$groupes ) {
			ksort( $groupes, SORT_NATURAL );
			foreach ( $groupes as &$list ) {
				usort( $list, static function ( $a, $b ) {
					return strcmp( (string) get_field( 'heure_debut', $a->ID ), (string) get_field( 'heure_debut', $b->ID ) );
				} );
			}
			unset( $list );
		}
		unset( $groupes );

		ob_start();
		echo $switch;
		echo '<div class="tcm-planning"><div class="tcm-planning-list">';

		echo '<form method="get" action="' . esc_url( $page_url ) . '" class="tcm-crm-bar">';
		echo '<select name="saison" onchange="this.form.submit()">';
		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ) );
		if ( ! is_wp_error( $saisons ) ) {
			foreach ( $saisons as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>Saison ' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '<option value="all" ' . selected( $saison, 'all', false ) . '>Toutes les saisons</option>';
		echo '</select></form>';

		if ( ! $creneaux ) {
			echo '<p class="tcm-crm-empty">Aucun cours pour cette saison.</p>';
		}

		foreach ( self::JOURS as $slug => $label ) {
			if ( empty( $tree[ $slug ] ) ) {
				continue;
			}
			echo '<div class="tcm-day"><div class="tcm-day-title">' . esc_html( $label ) . '</div>';
			foreach ( $tree[ $slug ] as $groupe => $list ) {
				echo '<div class="tcm-group">';
				if ( '' !== $groupe ) {
					echo '<div class="tcm-group-title">' . esc_html( $groupe ) . '</div>';
				}
				foreach ( $list as $c ) {
					$cap    = (int) get_field( 'capacite', $c->ID );
					$nb     = count( $this->inscrits( $c->ID ) );
					$ratio  = $cap > 0 ? $nb / $cap : 0;
					$fill   = $ratio >= 1 ? 'tcm-fill-full' : ( $ratio >= 0.8 ? 'tcm-fill-warn' : 'tcm-fill-ok' );
					$active = ( $c->ID === $sel ) ? ' is-active' : '';
					$anim   = (string) get_field( 'entraineur', $c->ID );
					$href   = esc_url( add_query_arg( array( 'creneau' => $c->ID, 'saison' => $saison ), $page_url ) );
					echo '<a class="tcm-creneau-card' . $active . '" href="' . $href . '">';
					echo '<span class="tcm-creneau-time">' . esc_html( get_field( 'heure_debut', $c->ID ) ) . ' – ' . esc_html( get_field( 'heure_fin', $c->ID ) ) . '</span>';
					if ( $anim ) {
						echo '<span class="tcm-creneau-meta">' . esc_html( $anim ) . '</span>';
					}
					echo '<span class="tcm-creneau-fill ' . $fill . '">' . (int) $nb . ' / ' . (int) $cap . '</span>';
					echo '</a>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		// Bouton « nouveau créneau ».
		$new_url = esc_url( add_query_arg( array( 'new_creneau' => 1, 'saison' => $saison ), $page_url ) );
		echo '<div class="tcm-day"><a class="button button-small tcm-add-creneau" href="' . $new_url . '">+ Nouveau cours</a></div>';

		echo '</div><div class="tcm-planning-detail">';
		if ( $new_cre ) {
			echo '<div class="tcm-fiche-section"><h3>Nouveau cours</h3>';
			echo $this->creneau_form( 0, $page_url, array( 'saison' => $saison ) );
			echo '</div>';
		} elseif ( $sel && get_post_type( $sel ) === TCM_CPT_CRENEAU ) {
			echo $edit_cre ? $this->creneau_edit_panel( $sel, $page_url, $saison ) : $this->creneau_detail( $sel, $page_url, $saison );
		} else {
			echo '<p>Sélectionnez un cours, ou créez-en un.</p>';
		}
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/** Bascule Par cours / Par adhérent. */
	private function vue_switch( string $page_url, string $saison, string $vue ): string {
		$tab = static function ( string $v, string $label ) use ( $page_url, $saison, $vue ) {
			$url = add_query_arg( array( 'vue' => $v, 'saison' => $saison ), $page_url );
			$cls = 'tcm-vue-tab' . ( $v === $vue ? ' is-active' : '' );
			return '<a class="' . $cls . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		};
		return '<div class="tcm-vue-switch">' . $tab( 'creneaux', 'Par cours' ) . $tab( 'adherents', 'Par adhérent' ) . '</div>';
	}

	/**
	 * Vue « par adhérent » : liste des inscriptions de la saison, triée par
	 * adhérent, filtrable par statut (Validés / En attente).
	 */
	private function render_par_adherent( string $page_url, string $saison, string $statut ): string {
		$meta = array();
		if ( 'all' !== $saison && '' !== $saison ) {
			$meta[] = array( 'key' => 'saison', 'value' => $saison, 'compare' => '=' );
		}
		$creneaux = get_posts( array( 'post_type' => TCM_CPT_CRENEAU, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids', 'meta_query' => $meta ?: array() ) );
		$ins      = $creneaux ? get_posts( array(
			'post_type'      => TCM_CPT_INSCRIPTION,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array( array( 'key' => 'creneau', 'value' => $creneaux, 'compare' => 'IN' ) ),
		) ) : array();

		$rows = array();
		foreach ( $ins as $i ) {
			$st = (string) get_field( 'statut', $i->ID );
			if ( 'all' !== $statut && $st !== $statut ) {
				continue;
			}
			$aid    = (int) get_field( 'adherent', $i->ID );
			$rows[] = array(
				'aid'     => $aid,
				'nom'     => $this->person_name( $aid ),
				'creneau' => $this->creneau_label( (int) get_field( 'creneau', $i->ID ) ),
				'statut'  => $st,
			);
		}
		usort( $rows, static function ( $a, $b ) {
			return strcasecmp( remove_accents( $a['nom'] ), remove_accents( $b['nom'] ) );
		} );

		ob_start();
		echo '<div class="tcm-planning-adh">';
		echo '<form method="get" action="' . esc_url( $page_url ) . '" class="tcm-crm-bar">';
		echo '<input type="hidden" name="vue" value="adherents">';
		echo '<select name="saison" onchange="this.form.submit()">';
		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ) );
		if ( ! is_wp_error( $saisons ) ) {
			foreach ( $saisons as $t ) {
				echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>Saison ' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '<option value="all" ' . selected( $saison, 'all', false ) . '>Toutes les saisons</option>';
		echo '</select>';
		echo '<select name="statut" onchange="this.form.submit()">';
		echo '<option value="all" ' . selected( $statut, 'all', false ) . '>Tous statuts</option>';
		echo '<option value="confirme" ' . selected( $statut, 'confirme', false ) . '>Validés</option>';
		echo '<option value="attente" ' . selected( $statut, 'attente', false ) . '>En attente</option>';
		echo '</select>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p class="tcm-crm-empty">Aucune inscription pour cette sélection.</p>';
		} else {
			echo '<div class="tcm-rows">';
			foreach ( $rows as $r ) {
				$fic = esc_url( add_query_arg( array( 'id' => $r['aid'], 'tab' => 'inscriptions' ), $this->back_office_url() ) );
				echo '<div class="tcm-row"><div class="tcm-row-main">';
				echo '<a class="tcm-row-libelle tcm-link" href="' . $fic . '">' . esc_html( $r['nom'] ) . '</a>';
				echo '<span class="tcm-muted"> · ' . esc_html( $r['creneau'] ) . '</span>';
				echo '<span class="tcm-chip tcm-chip-' . esc_attr( $r['statut'] ) . '">' . esc_html( 'confirme' === $r['statut'] ? 'Confirmé' : 'Liste d’attente' ) . '</span>';
				echo '</div></div>';
			}
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	private function creneau_detail( int $cid, string $page_url, string $saison ): string {
		$cap      = (int) get_field( 'capacite', $cid );
		$ins      = $this->inscrits( $cid );
		$this_url = add_query_arg( array( 'creneau' => $cid, 'saison' => $saison ), $page_url );

		ob_start();
		echo '<div class="tcm-fiche-section">';
		echo '<div class="tcm-detail-head"><h3>' . esc_html( get_the_title( $cid ) ) . '</h3>';
		echo '<div class="tcm-row-actions">';
		echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'edit_creneau', 1, $this_url ) ) . '">Éditer</a>';
		echo $this->post_button( 'tcm_creneau_delete', 'creneau_id', $cid, $page_url, 'Supprimer ce cours et ses inscriptions ?', 'Supprimer', 'tcm-danger' );
		echo '</div></div>';

		echo '<p class="tcm-creneau-info">';
		echo esc_html( self::JOURS[ get_field( 'jour', $cid ) ] ?? (string) get_field( 'jour', $cid ) );
		echo ' · ' . esc_html( get_field( 'heure_debut', $cid ) . ' – ' . get_field( 'heure_fin', $cid ) );
		if ( get_field( 'entraineur', $cid ) ) {
			echo ' · ' . esc_html( get_field( 'entraineur', $cid ) );
		}
		echo ' · <strong>' . count( $ins ) . ' / ' . (int) $cap . '</strong> inscrits';
		echo '</p>';

		echo '<div class="tcm-rows">';
		foreach ( $ins as $i ) {
			$aid  = (int) get_field( 'adherent', $i->ID );
			$st   = (string) get_field( 'statut', $i->ID );
			$fic  = esc_url( add_query_arg( array( 'id' => $aid, 'tab' => 'inscriptions' ), $this->back_office_url() ) );
			echo '<div class="tcm-row"><div class="tcm-row-main">';
			echo '<a class="tcm-row-libelle tcm-link" href="' . $fic . '">' . esc_html( $this->person_name( $aid ) ) . '</a>';
			echo '<span class="tcm-chip tcm-chip-' . esc_attr( $st ) . '">' . esc_html( 'confirme' === $st ? 'Confirmé' : 'Liste d’attente' ) . '</span>';
			echo '</div><div class="tcm-row-actions">';
			echo $this->post_button( 'tcm_inscription_delete', 'inscription_id', $i->ID, (string) $this_url, 'Désinscrire cet adhérent ?', 'Retirer', 'tcm-danger' );
			echo '</div></div>';
		}
		if ( ! $ins ) {
			echo '<p>Aucun inscrit.</p>';
		}
		echo '</div>';

		// Formulaire « inscrire un adhérent ».
		$choix = $this->adherents_for_saison( (string) get_field( 'saison', $cid ), $cid );
		echo '<div class="tcm-add-block"><h4>Inscrire un adhérent</h4>';
		echo '<form class="tcm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_inscription_add' );
		echo '<input type="hidden" name="action" value="tcm_inscription_add">';
		echo '<input type="hidden" name="creneau" value="' . (int) $cid . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $this_url ) . '">';
		echo '<label>Adhérent<select name="adherent" required><option value="">—</option>';
		foreach ( $choix as $aid => $nom ) {
			echo '<option value="' . (int) $aid . '">' . esc_html( $nom ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Statut<select name="statut"><option value="confirme">Confirmé</option><option value="attente">Liste d’attente</option></select></label>';
		echo '<div class="tcm-inline-actions"><button type="submit" class="button button-primary button-small">Inscrire</button></div>';
		echo '</form></div>';

		echo '</div>';
		return (string) ob_get_clean();
	}

	private function creneau_edit_panel( int $cid, string $page_url, string $saison ): string {
		ob_start();
		echo '<div class="tcm-fiche-section"><h3>Éditer le cours</h3>';
		echo $this->creneau_form( $cid, $page_url, array(
			'jour'        => get_field( 'jour', $cid ),
			'heure_debut' => get_field( 'heure_debut', $cid ),
			'heure_fin'   => get_field( 'heure_fin', $cid ),
			'type_cours'  => get_field( 'type_cours', $cid ),
			'entraineur'  => get_field( 'entraineur', $cid ),
			'capacite'    => get_field( 'capacite', $cid ),
			'saison'      => get_field( 'saison', $cid ) ?: $saison,
		) );
		echo '</div>';
		return (string) ob_get_clean();
	}

	private function creneau_form( int $cid, string $page_url, array $v ): string {
		$v = wp_parse_args( $v, array( 'jour' => '', 'heure_debut' => '', 'heure_fin' => '', 'type_cours' => '', 'entraineur' => '', 'capacite' => 6, 'saison' => '' ) );
		$cancel = $cid
			? esc_url( add_query_arg( array( 'creneau' => $cid, 'saison' => $v['saison'] ), $page_url ) )
			: esc_url( add_query_arg( 'saison', $v['saison'], $page_url ) );

		ob_start();
		echo '<form class="tcm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_creneau_save' );
		echo '<input type="hidden" name="action" value="tcm_creneau_save">';
		echo '<input type="hidden" name="creneau_id" value="' . (int) $cid . '">';
		echo '<input type="hidden" name="redirect" value="' . $cancel . '">';
		echo '<label>Jour<select name="jour">';
		foreach ( self::JOURS as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $v['jour'], $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Début<input type="time" name="heure_debut" value="' . esc_attr( $v['heure_debut'] ) . '"></label>';
		echo '<label>Fin<input type="time" name="heure_fin" value="' . esc_attr( $v['heure_fin'] ) . '"></label>';
		echo '<label>Groupe / type<input type="text" name="type_cours" value="' . esc_attr( $v['type_cours'] ) . '"></label>';
		echo '<label>Animateur<input type="text" name="entraineur" value="' . esc_attr( $v['entraineur'] ) . '"></label>';
		echo '<label>Capacité<input type="number" name="capacite" min="0" value="' . esc_attr( $v['capacite'] ) . '"></label>';
		echo '<label>Saison<input type="text" name="saison" value="' . esc_attr( $v['saison'] ) . '"></label>';
		echo '<div class="tcm-inline-actions"><button type="submit" class="button button-primary button-small">Enregistrer</button>';
		echo ' <a class="button button-small" href="' . $cancel . '">Annuler</a></div>';
		echo '</form>';
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Rendu — section Inscriptions dans la fiche adhérent
	 * =================================================================== */

	public function adherent_inscriptions_section( int $adherent_id, string $saison, string $fiche_url ): string {
		$ins      = $this->adherent_inscriptions( $adherent_id );
		$this_url = add_query_arg( 'tab', 'inscriptions', $fiche_url );

		ob_start();
		echo '<div class="tcm-fiche-section"><h3>Cours</h3>';
		echo '<div class="tcm-rows">';
		foreach ( $ins as $i ) {
			$cid = (int) get_field( 'creneau', $i->ID );
			$st  = (string) get_field( 'statut', $i->ID );
			$lien = esc_url( add_query_arg( 'creneau', $cid, $this->planning_url() ) );
			echo '<div class="tcm-row"><div class="tcm-row-main">';
			echo '<a class="tcm-row-libelle tcm-link" href="' . $lien . '">' . esc_html( $this->creneau_label( $cid ) ) . '</a>';
			echo '<span class="tcm-chip tcm-chip-' . esc_attr( $st ) . '">' . esc_html( 'confirme' === $st ? 'Confirmé' : 'Liste d’attente' ) . '</span>';
			echo '</div><div class="tcm-row-actions">';
			echo $this->post_button( 'tcm_inscription_delete', 'inscription_id', $i->ID, (string) $this_url, 'Retirer cette inscription ?', 'Retirer', 'tcm-danger' );
			echo '</div></div>';
		}
		if ( ! $ins ) {
			echo '<p>Aucune inscription.</p>';
		}
		echo '</div>';

		$choix = $this->creneaux_for_saison( $saison, $adherent_id );
		echo '<div class="tcm-add-block"><h4>Inscrire à un cours</h4>';
		if ( $choix ) {
			echo '<form class="tcm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'tcm_inscription_add' );
			echo '<input type="hidden" name="action" value="tcm_inscription_add">';
			echo '<input type="hidden" name="adherent" value="' . (int) $adherent_id . '">';
			echo '<input type="hidden" name="redirect" value="' . esc_url( $this_url ) . '">';
			echo '<label>Créneau<select name="creneau" required><option value="">—</option>';
			foreach ( $choix as $cid => $lab ) {
				echo '<option value="' . (int) $cid . '">' . esc_html( $lab ) . '</option>';
			}
			echo '</select></label>';
			echo '<label>Statut<select name="statut"><option value="confirme">Confirmé</option><option value="attente">Liste d’attente</option></select></label>';
			echo '<div class="tcm-inline-actions"><button type="submit" class="button button-primary button-small">Inscrire</button></div>';
			echo '</form>';
		} else {
			echo '<p class="tcm-muted">Aucun cours disponible pour la saison ' . esc_html( $saison ) . '.</p>';
		}
		echo '</div>';

		echo '</div>';
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Utilitaires rendu
	 * =================================================================== */

	private function post_button( string $action, string $idname, int $post_id, string $redirect, string $confirm, string $label, string $extra_class = '' ): string {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tcm-del-form" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $idname ) . '" value="' . (int) $post_id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $redirect ) . '">';
		echo '<button type="submit" class="button button-small ' . esc_attr( $extra_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
		return (string) ob_get_clean();
	}

	private function planning_url(): string {
		$p = get_page_by_path( 'creneaux' );
		return $p ? get_permalink( $p ) : home_url( '/creneaux/' );
	}
	private function back_office_url(): string {
		$p = get_page_by_path( 'back-office-adherents' );
		return $p ? get_permalink( $p ) : home_url( '/back-office-adherents/' );
	}
}
