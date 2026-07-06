<?php
/**
 * Protection des pages back-office front-end.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TCM_Access {

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'guard' ) );
	}

	/**
	 * Slugs des pages protégées.
	 *
	 * @return string[]
	 */
	private function protected_slugs(): array {
		$slugs = array( 'back-office-adherents' );
		return (array) apply_filters( 'tcm_protected_slugs', $slugs );
	}

	/**
	 * Protège les pages front-end dont le slug est dans la liste.
	 */
	public function guard(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_name, $this->protected_slugs(), true ) ) {
			return;
		}

		if ( current_user_can( 'tcm_manage' ) ) {
			return;
		}

		$redirect_to = get_permalink( $post );
		if ( ! $redirect_to ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $redirect_to ) );
			exit;
		}

		wp_die(
			sprintf(
				'<h1>%s</h1><p>%s</p>',
				esc_html__( 'Accès refusé', 'tcm-adherents' ),
				esc_html__( 'Vous n’avez pas les droits nécessaires pour consulter cette page.', 'tcm-adherents' )
			),
			esc_html__( 'Accès refusé', 'tcm-adherents' ),
			array( 'response' => 403 )
		);
	}
}
