<?php
/**
 * Interface front-office protégée (Option B — privacy-first).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Frontoffice {

	/**
	 * Correspondance entité -> [ CPT, clé de groupe ACF, libellé, slug de page formulaire ].
	 *
	 * @return array<string, array{cpt:string, group:string, label:string, form_page:string}>
	 */
	private function entities(): array {
		return array(
			'adherent'  => array( 'cpt' => TCM_CPT_ADHERENT,  'group' => 'group_tcm_adherent',  'label' => 'Adhérent',  'form_page' => 'fiche-adherent' ),
			'personne'  => array( 'cpt' => TCM_CPT_PERSONNE,  'group' => 'group_tcm_personne',  'label' => 'Personne',   'form_page' => 'fiche-personne' ),
			'reglement' => array( 'cpt' => TCM_CPT_REGLEMENT, 'group' => 'group_tcm_reglement', 'label' => 'Règlement', 'form_page' => 'fiche-reglement' ),
			'commande'  => array( 'cpt' => TCM_CPT_COMMANDE,  'group' => 'group_tcm_commande',  'label' => 'Commande',  'form_page' => 'fiche-commande' ),
		);
	}

	/** Slugs des pages front-office, protégées par TCM_Access. */
	private function pages(): array {
		return array( 'back-office-adherents', 'fiche', 'recap', 'fiche-adherent', 'fiche-personne', 'fiche-reglement', 'fiche-commande', 'tableau-de-bord' );
	}

	public function hooks(): void {
		add_action( 'init', array( $this, 'ensure_role' ) );
		add_filter( 'tcm_protected_slugs', array( $this, 'protect_pages' ) );
		add_action( 'admin_post_tcm_new_child', array( $this, 'new_child' ) );

		add_shortcode( 'tcm_form', array( $this, 'sc_form' ) );
		add_shortcode( 'tcm_liste', array( $this, 'sc_liste' ) );

		add_action( 'template_redirect', array( $this, 'maybe_acf_form_head' ), 1 );
	}

	// -------------------------------------------------------------------------
	// Rôle "Bureau" + protection des pages
	// -------------------------------------------------------------------------
	public function ensure_role(): void {
		if ( ! get_role( 'tcm_bureau' ) ) {
			add_role( 'tcm_bureau', 'Bureau TC Mimet', array( 'read' => true ) );
		}
		$r = get_role( 'tcm_bureau' );
		if ( $r && ! $r->has_cap( 'tcm_manage' ) ) {
			$r->add_cap( 'tcm_manage' );
		}
	}

	public function protect_pages( array $slugs ): array {
		return array_unique( array_merge( $slugs, $this->pages() ) );
	}

	public function new_child(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_new_child' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$map = array( 'reglement' => TCM_CPT_REGLEMENT, 'commande' => TCM_CPT_COMMANDE );
		$entity   = sanitize_key( $_GET['entity'] ?? '' );
		$cpt      = $map[ $entity ] ?? '';
		$adherent = (int) ( $_GET['adherent'] ?? 0 );
		if ( ! $cpt || ! $adherent ) {
			wp_die( 'Paramètres manquants.' );
		}
		$id = wp_insert_post( array( 'post_type' => $cpt, 'post_status' => 'publish', 'post_title' => ucfirst( $entity ) ) );
		update_field( 'adherent', $adherent, $id );
		if ( 'reglement' === $entity ) {
			update_field( 'date_reglement', current_time( 'Ymd' ), $id );
		}
		$page = 'reglement' === $entity ? 'fiche-reglement' : 'fiche-commande';
		wp_safe_redirect( add_query_arg( 'id', $id, get_permalink( get_page_by_path( $page ) ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// acf_form_head (uniquement sur les pages contenant [tcm_form])
	// -------------------------------------------------------------------------
	public function maybe_acf_form_head(): void {
		if ( ! function_exists( 'acf_form_head' ) || ! is_page() ) {
			return;
		}
		$slug = get_queried_object() ? get_queried_object()->post_name : '';
		if ( in_array( $slug, array( 'fiche-adherent', 'fiche-reglement', 'fiche-commande', 'fiche-personne' ), true ) ) {
			acf_form_head();
		}
	}

	// -------------------------------------------------------------------------
	// Shortcode [tcm_form entity="adherent" id="new|123"]
	// -------------------------------------------------------------------------
	public function sc_form( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		if ( ! function_exists( 'acf_form' ) ) {
			return '<p>ACF Pro requis.</p>';
		}

		$atts = shortcode_atts( array( 'entity' => 'adherent', 'id' => '' ), $atts );
		$ent  = $this->entities()[ $atts['entity'] ] ?? null;
		if ( ! $ent ) {
			return '<p>Entité inconnue.</p>';
		}

		$id  = $atts['id'] ?: ( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : 'new' );
		$new = ( 'new' === $id || '' === $id );

		$args = array(
			'post_id'            => $new ? 'new' : (int) $id,
			'field_groups'       => array( $ent['group'] ),
			'post_title'         => false,
			'post_content'       => false,
			'uploader'           => 'basic',
			'honeypot'           => false,
			'submit_value'       => $new ? 'Créer' : 'Enregistrer',
			'updated_message'    => $ent['label'] . ' enregistré.',
			'html_submit_button' => '<input type="submit" class="button button-primary" value="%s" />',
		);
		if ( $new ) {
			$args['new_post'] = array( 'post_type' => $ent['cpt'], 'post_status' => 'publish' );
			$args['return']   = add_query_arg( 'cree', 1, get_permalink( get_page_by_path( 'back-office-adherents' ) ) );
		}

		ob_start();
		echo '<div class="tcm-form tcm-form-' . esc_attr( $atts['entity'] ) . '">';
		acf_form( $args );
		echo '</div>';
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode [tcm_liste entity="adherent"]
	// Filtres via GET : ?saison=2026&dossier=Incomplet&s=nom
	// -------------------------------------------------------------------------
	public function sc_liste( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '<p>Accès réservé.</p>';
		}
		$atts = shortcode_atts( array( 'entity' => 'adherent', 'par_page' => 25 ), $atts );
		$ent  = $this->entities()[ $atts['entity'] ] ?? null;
		if ( ! $ent ) {
			return '<p>Entité inconnue.</p>';
		}

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
			'post_type'      => $ent['cpt'],
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['par_page'],
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
			'tax_query'      => $tax_query ?: array(),
		) );

		ob_start();
		echo '<div class="tcm-liste">';
		$this->render_filters( $ent, $saison, $dossier, $search );

		if ( ! $q->have_posts() ) {
			echo '<p>Aucun résultat.</p></div>';
			return (string) ob_get_clean();
		}

		echo '<table class="tcm-table"><thead><tr>';
		echo '<th>Nom</th><th>Saison</th><th>Dossier</th><th>Contact</th><th></th>';
		echo '</tr></thead><tbody>';

		$form_url = get_permalink( get_page_by_path( $ent['form_page'] ) );
		while ( $q->have_posts() ) {
			$q->the_post();
			$aid   = get_the_ID();
			$pid   = (int) get_field( 'personne', $aid );
			$nom   = $pid ? trim( get_field( 'nom', $pid ) . ' ' . get_field( 'prenom', $pid ) ) : '—';
			$saiso = get_field( 'saison', $aid );
			$dos   = get_field( 'dossier_complet', $aid ) ? 'Complet' : 'Incomplet';
			$mail  = $pid ? get_field( 'email', $pid ) : '';
			$tel   = $pid ? get_field( 'telephone', $pid ) : '';
			$edit  = esc_url( add_query_arg( 'id', $aid, $form_url ) );

			echo '<tr>';
			echo '<td>' . esc_html( $nom ) . '</td>';
			echo '<td>' . esc_html( $saiso ) . '</td>';
			echo '<td><span class="tcm-badge tcm-' . esc_attr( strtolower( $dos ) ) . '">' . esc_html( $dos ) . '</span></td>';
			echo '<td>' . esc_html( trim( $mail . ' ' . $tel ) ) . '</td>';
			echo '<td><a class="button" href="' . $edit . '">Éditer</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->render_pagination( $q, $paged );
		echo '</div>';
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	private function render_filters( array $ent, string $saison, string $dossier, string $search ): void {
		$saisons = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false ) );
		echo '<form method="get" class="tcm-filtres">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nom…"> ';
		echo '<select name="saison"><option value="">Toutes saisons</option>';
		foreach ( (array) $saisons as $t ) {
			if ( is_wp_error( $saisons ) ) {
				break;
			}
			echo '<option value="' . esc_attr( $t->name ) . '" ' . selected( $saison, $t->name, false ) . '>' . esc_html( $t->name ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="dossier"><option value="">Tous dossiers</option>';
		foreach ( array( 'Complet', 'Incomplet' ) as $d ) {
			echo '<option value="' . esc_attr( $d ) . '" ' . selected( $dossier, $d, false ) . '>' . esc_html( $d ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">Filtrer</button>';
		echo '</form>';
	}

	private function render_pagination( WP_Query $q, int $paged ): void {
		if ( $q->max_num_pages < 2 ) {
			return;
		}
		echo '<div class="tcm-pagination">';
		echo paginate_links( array(
			'base'      => add_query_arg( 'pg', '%#%' ),
			'format'    => '',
			'current'   => $paged,
			'total'     => $q->max_num_pages,
			'prev_text' => '‹',
			'next_text' => '›',
		) );
		echo '</div>';
	}
}
