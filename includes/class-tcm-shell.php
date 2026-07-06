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
			array( 'label' => 'Récapitulatif',    'slug' => 'recap' ),
			array( 'label' => 'Créneaux',         'slug' => 'creneaux' ),
			array( 'label' => 'Règlements',       'slug' => 'reglements' ),
			array( 'label' => 'Réglages',         'url'  => admin_url( 'admin.php?page=tcm-adherents' ) ),
		);
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
			$force = array( 'fiche-adherent', 'fiche-personne', 'fiche-reglement', 'fiche-commande' );

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

		ob_start();
		echo '<aside class="tcm-sidebar">';
		echo '<div class="tcm-brand"><span class="tcm-brand-mark"></span><span class="tcm-brand-name">TC Mimet</span></div>';
		echo '<nav class="tcm-nav">';

		foreach ( $this->nav_items() as $item ) {
			$active = '';
			if ( isset( $item['url'] ) ) {
				$url = $item['url'];
			} else {
				$page = get_page_by_path( $item['slug'] );
				$url  = $page ? get_permalink( $page ) : '#';
				if ( $page && (int) $page->ID === $current ) {
					$active = ' is-active';
				}
			}
			echo '<a class="tcm-nav-item' . $active . '" href="' . esc_url( $url ) . '">'
				. '<span class="tcm-nav-ico"></span>'
				. '<span class="tcm-nav-label">' . esc_html( $item['label'] ) . '</span>'
				. '</a>';
		}

		echo '</nav>';
		echo '</aside>';
		return (string) ob_get_clean();
	}
}
