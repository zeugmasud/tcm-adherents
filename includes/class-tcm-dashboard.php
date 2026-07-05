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

	private function page_url( string $slug ): string {
		$p = get_page_by_path( $slug );
		return $p ? get_permalink( $p ) : '#';
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
		$add_reg  = esc_url( add_query_arg( 'adherent', $id, $this->page_url( 'fiche-reglement' ) ) );
		$add_cmd  = esc_url( add_query_arg( 'adherent', $id, $this->page_url( 'fiche-commande' ) ) );

		ob_start();
		echo '<div class="tcm-fiche">';
		echo '<div class="tcm-fiche-header">';
		echo '<h2>' . esc_html( $nom ) . '</h2>';
		echo '<p class="tcm-meta">';
		echo 'Saison <strong>' . esc_html( $saison ) . '</strong> · ';
		echo '<span class="tcm-badge tcm-' . esc_attr( strtolower( $dossier ) ) . '">Dossier ' . esc_html( $dossier ) . '</span>';
		if ( get_field( 'mineur', $id ) ) { echo ' · <span class="tcm-badge">Mineur</span>'; }
		if ( get_field( 'adoc_valide', $id ) ) { echo ' · <span class="tcm-badge">ADOC ✓</span>'; }
		echo '</p>';
		echo '<a class="button button-primary" href="' . $edit_adh . '">Éditer l’adhésion</a> ';
		echo '<a class="button" href="' . $edit_per . '">Éditer les coordonnées</a>';
		echo '</div>';

		echo '<div class="tcm-fiche-section"><h3>Coordonnées</h3><ul class="tcm-coord">';
		foreach ( array( 'date_naissance' => 'Naissance', 'email' => 'Email', 'telephone' => 'Tél', 'adresse' => 'Adresse', 'cp' => 'CP', 'ville' => 'Ville' ) as $f => $label ) {
			$v = get_field( $f, $pid );
			if ( 'date_naissance' === $f ) { $v = $this->fr_date( $v ); }
			if ( $v ) { echo '<li><span>' . esc_html( $label ) . '</span> ' . esc_html( $v ) . '</li>'; }
		}
		echo '</ul>';
		if ( get_field( 'mineur', $id ) ) {
			echo '<p class="tcm-parents"><strong>Parents :</strong> ';
			echo esc_html( trim( (string) get_field( 'parent_mere_nom', $id ) . ' ' . (string) get_field( 'parent_mere_tel', $id ) . '  ' . (string) get_field( 'parent_pere_nom', $id ) . ' ' . (string) get_field( 'parent_pere_tel', $id ) ) );
			echo '</p>';
		}
		echo '</div>';

		$regs = $this->children_of( TCM_CPT_REGLEMENT, $id );
		$total = 0.0;
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

		$cmds = $this->children_of( TCM_CPT_COMMANDE, $id );
		echo '<div class="tcm-fiche-section"><h3>Commandes <a class="button button-small" href="' . $add_cmd . '">+ Ajouter</a></h3>';
		if ( $cmds ) {
			echo '<table class="tcm-table"><thead><tr><th>Libellé</th><th>Montant</th></tr></thead><tbody>';
			foreach ( $cmds as $c ) {
				echo '<tr><td>' . esc_html( get_field( 'libelle', $c->ID ) ) . '</td><td>' . esc_html( number_format( (float) get_field( 'montant', $c->ID ), 2, ',', ' ' ) ) . ' €</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Aucune commande.</p>';
		}
		echo '</div>';

		$ins = $this->children_of( TCM_CPT_INSCRIPTION, $id );
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

		$autres = get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'publish',
			'meta_key'       => 'personne',
			'meta_value'     => $pid,
			'exclude'        => array( $id ),
		) );
		if ( $autres ) {
			echo '<div class="tcm-fiche-section"><h3>Historique</h3><ul>';
			foreach ( $autres as $aid ) {
				$url = esc_url( add_query_arg( 'id', $aid, get_permalink() ) );
				echo '<li><a href="' . $url . '">Saison ' . esc_html( get_field( 'saison', $aid ) ) . '</a></li>';
			}
			echo '</ul></div>';
		}

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
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = max( 1, (int) ( $_GET['pg'] ?? 1 ) );

		$tax_query = array();
		if ( '' !== $saison ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}
		if ( '' !== $dossier ) {
			$tax_query[] = array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => $dossier );
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
			echo '<td>' . esc_html( get_field( 'telephone', $pid ) ) . '</td>';
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
