<?php
/**
 * Shell / app-shell du back-office (Option B, rendu plugin).
 *
 * - Enregistre le template de page « TC Mimet — CRM » (slug tcm-crm-shell) et
 *   le charge depuis le plugin (templates/tcm-crm-shell.php).
 * - Fournit le shortcode [tcm_sidebar] (marque + navigation, entrée active).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Shell {

	/** Slug du template de page (valeur de _wp_page_template). */
	const TEMPLATE = 'tcm-crm-shell';

	public function hooks(): void {
		add_filter( 'theme_page_templates', array( $this, 'register_template' ) );
		add_filter( 'template_include', array( $this, 'load_template' ) );
		add_shortcode( 'tcm_sidebar', array( $this, 'sc_sidebar' ) );
	}

	/**
	 * Entrées de la barre latérale : label => slug de page (interne) ou url.
	 */
	private function nav_items(): array {
		return array(
			array( 'label' => 'Tableau de bord', 'slug' => 'tableau-de-bord' ),
			array( 'label' => 'Adhérents',        'slug' => 'back-office-adherents' ),
			array( 'label' => 'Cours',            'slug' => 'creneaux' ),
			array( 'label' => 'Règlements',       'slug' => 'reglements' ),
		);
	}

	/** Rend une entrée de navigation (marque .is-active pour la page courante). */
	private function nav_item( string $label, string $slug, int $current ): string {
		$page = get_page_by_path( $slug );
		$url  = $page ? get_permalink( $page ) : '#';
		$act  = ( $page && (int) $page->ID === $current ) ? ' is-active' : '';
		return '<a class="tcm-nav-item' . $act . '" href="' . esc_url( $url ) . '">'
			. '<span class="tcm-nav-ico"></span><span class="tcm-nav-label">' . esc_html( $label ) . '</span></a>';
	}

	/**
	 * Ajoute le template au sélecteur « Attributs de page » de l'éditeur.
	 */
	public function register_template( array $templates ): array {
		$templates[ self::TEMPLATE ] = __( 'TC Mimet — CRM', 'tcm-adherents' );
		return $templates;
	}

	/**
	 * Charge le template plugin quand la page courante l'a sélectionné.
	 * On lit la meta par ID (Elementor peut vider post_content).
	 */
	public function load_template( string $template ): string {
		if ( is_singular() ) {
			$id       = get_queried_object_id();
			$assigned = get_post_meta( $id, '_wp_page_template', true );
			$slug     = get_post_field( 'post_name', $id );

			// Pages d'édition ACF : shell forcé par slug (évite de dépendre d'une
			// meta _wp_page_template, pas toujours accessible en écriture).
			$force = array( 'fiche-adherent', 'fiche-personne', 'fiche-reglement', 'fiche-commande', 'creneaux', 'reglements' );

			if ( self::TEMPLATE === $assigned || in_array( $slug, $force, true ) ) {
				$custom = TCM_PATH . 'templates/tcm-crm-shell.php';
				if ( file_exists( $custom ) ) {
					return $custom;
				}
			}
		}
		return $template;
	}

	/**
	 * Barre latérale : marque + navigation. L'entrée de la page courante est
	 * marquée .is-active.
	 */
	public function sc_sidebar(): string {
		$current = (int) get_queried_object_id();

		$user = wp_get_current_user();

		ob_start();
		echo '<aside class="tcm-sidebar">';

		$logo = apply_filters( 'tcm_sidebar_logo', home_url( '/wp-content/uploads/2026/07/Logos-Noir-Vide.webp' ) );
		$dash = get_page_by_path( 'tableau-de-bord' );
		$home = $dash ? get_permalink( $dash ) : home_url( '/' );

		echo '<div class="tcm-sidebar-top">';
		echo '<a class="tcm-brand" href="' . esc_url( $home ) . '" aria-label="Tennis Club Mimet">';
		if ( $logo ) {
			echo '<img class="tcm-brand-logo" src="' . esc_url( $logo ) . '" alt="Tennis Club Mimet">';
		} else {
			echo '<span class="tcm-brand-mark"></span><span class="tcm-brand-name">TC Mimet</span>';
		}
		echo '</a>';
		echo '<button type="button" class="tcm-burger" aria-label="Menu" aria-expanded="false"><span></span><span></span><span></span></button>';
		echo '</div>';

		echo '<div class="tcm-sidebar-body">';
		echo '<nav class="tcm-nav">';
		foreach ( $this->nav_items() as $item ) {
			echo $this->nav_item( $item['label'], $item['slug'], $current );
		}
		echo '</nav>';

		// Pied de barre : Récapitulatif + compte (remplace la barre admin WP).
		echo '<div class="tcm-sidebar-footer">';
		echo $this->nav_item( 'Récapitulatif', 'recap', $current );
		echo '<div class="tcm-user">';
		echo '<span class="tcm-user-name">' . esc_html( $user->display_name ) . '</span>';
		echo '<a class="tcm-user-link" href="' . esc_url( admin_url() ) . '">Administration</a>';
		echo '<a class="tcm-user-link" href="' . esc_url( wp_logout_url( home_url() ) ) . '">Déconnexion</a>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '</aside>';
		return (string) ob_get_clean();
	}
}
