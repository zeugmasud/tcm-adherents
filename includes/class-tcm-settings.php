<?php
/**
 * Réglages du plugin (secret webhook HelloAsso, formulaire d'inscription, saison courante).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Settings {

	const OPTION_GROUP = 'tcm_settings';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'tcm_saison_courante', array( $this, 'saison_courante' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Réglages', 'tcm-adherents' ),
			__( 'Réglages', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-settings',
			array( $this, 'render' )
		);
	}

	public function register(): void {
		register_setting( self::OPTION_GROUP, 'tcm_helloasso_secret', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'tcm_inscription_form_name', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'tcm_saison_courante', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => gmdate( 'Y' ) ) );
	}

	public function saison_courante( $default ) {
		$val = get_option( 'tcm_saison_courante', '' );
		return $val ? $val : $default;
	}

	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'TC Mimet — Réglages', 'tcm-adherents' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		echo '<table class="form-table">';

		echo '<tr><th><label for="tcm_saison_courante">Saison courante</label></th><td>'
			. '<input name="tcm_saison_courante" id="tcm_saison_courante" type="text" value="' . esc_attr( get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) ) . '"></td></tr>';

		echo '<tr><th><label for="tcm_inscription_form_name">Nom du formulaire d’inscription</label></th><td>'
			. '<input name="tcm_inscription_form_name" id="tcm_inscription_form_name" type="text" class="regular-text" value="' . esc_attr( get_option( 'tcm_inscription_form_name', '' ) ) . '">'
			. '<p class="description">Nom exact du formulaire Elementor à traiter (laisser vide pour traiter tout formulaire compatible).</p></td></tr>';

		echo '<tr><th><label for="tcm_helloasso_secret">Secret webhook HelloAsso</label></th><td>'
			. '<input name="tcm_helloasso_secret" id="tcm_helloasso_secret" type="password" class="regular-text" value="' . esc_attr( get_option( 'tcm_helloasso_secret', '' ) ) . '">'
			. '<p class="description">URL du webhook à déclarer chez HelloAsso : <code>' . esc_url( rest_url( 'tcm/v1/helloasso' ) ) . '</code></p></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
