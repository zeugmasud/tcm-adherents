<?php
/**
 * Orchestrateur principal.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TCM_Plugin {

	/** @var TCM_Plugin|null */
	private static $instance = null;

	public static function instance(): TCM_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Branche tous les modules.
	 */
	public function boot(): void {
		// Structure de données.
		( new TCM_CPT() )->hooks();
		( new TCM_Taxonomies() )->hooks();
		( new TCM_ACF_Fields() )->hooks();
		( new TCM_Titles() )->hooks();

		// Logique métier.
		( new TCM_HelloAsso() )->hooks();
		( new TCM_Form_Ingest() )->hooks();
		( new TCM_Season() )->hooks();
		( new TCM_Import() )->hooks();
		( new TCM_Settings() )->hooks();
		( new TCM_Access() )->hooks();
		( new TCM_Frontoffice() )->hooks();
		( new TCM_Dashboard() )->hooks();
		( new TCM_Shell() )->hooks();
		( new TCM_Chart() )->hooks();
		( new TCM_Facture() )->hooks();
		( new TCM_Crud() )->hooks();
		( new TCM_Planning() )->hooks();
		( new TCM_Normalize() )->hooks();
		( new TCM_Inscription() )->hooks();
		( new TCM_Import_Full() )->hooks();
		( new TCM_Maintenance() )->hooks();
		( new TCM_Front_Login() )->hooks();

		// Admin. Priorité 9 : le menu parent DOIT être enregistré avant les
		// sous-menus (Importer/Réglages/Dupliquer), sinon leur accès direct est
		// refusé par WordPress ("vous n'avez pas l'autorisation").
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 9 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Menu de regroupement en admin. Les CPT s'y accrochent (show_in_menu).
	 */
	public function admin_menu(): void {
		add_menu_page(
			__( 'TC Mimet', 'tcm-adherents' ),
			__( 'TC Mimet', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-adherents',
			array( $this, 'render_dashboard' ),
			'dashicons-groups',
			26
		);
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'TC Mimet — Gestion des adhérents', 'tcm-adherents' ) . '</h1>';
		echo '<p>' . esc_html__( 'Tableau de bord à venir : compteurs par saison, dossiers incomplets, places restantes.', 'tcm-adherents' ) . '</p>';

		// Petits compteurs de démarrage (utiles dès l'activation).
		foreach ( TCM_CPT::all_slugs() as $label => $slug ) {
			$count = wp_count_posts( $slug );
			$total = isset( $count->publish ) ? (int) $count->publish : 0;
			echo '<p><strong>' . esc_html( $label ) . '</strong> : ' . esc_html( (string) $total ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Avertit si ACF Pro n'est pas actif (les champs ne se chargeront pas sans lui).
	 */
	public function dependency_notice(): void {
		if ( function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>TC Mimet :</strong> '
			. esc_html__( 'Advanced Custom Fields PRO est requis pour les champs des adhérents. Merci de l’activer.', 'tcm-adherents' )
			. '</p></div>';
	}
}
