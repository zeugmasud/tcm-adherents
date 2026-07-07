<?php
/**
 * CTA « Espace adhérent » dans le menu public + modale de connexion.
 *
 * - Injecte un bouton CTA (charte club) dans le menu principal du site public,
 *   pointant vers /tableau-de-bord/.
 * - Si le visiteur n'est pas connecté, un clic ouvre une modale de connexion
 *   (login AJAX via wp_signon) au lieu d'aller sur wp-login.php ; à la réussite,
 *   redirection vers le tableau de bord.
 * - Neutralisé dans le back-office (pages du shell tcm-crm-shell).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Front_Login {

	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'modal' ) );
		add_action( 'wp_ajax_nopriv_tcm_login', array( $this, 'ajax_login' ) );
		add_action( 'wp_ajax_tcm_login', array( $this, 'ajax_login' ) );
	}

	/** URL du tableau de bord (cible du CTA). */
	private function dashboard_url(): string {
		$p = get_page_by_path( 'tableau-de-bord' );
		return $p ? get_permalink( $p ) : home_url( '/tableau-de-bord/' );
	}

	/** Libellé du CTA (filtrable). */
	private function cta_label(): string {
		return (string) apply_filters( 'tcm_cta_label', 'Espace adhérent' );
	}

	public function assets(): void {
		$css = TCM_PATH . 'assets/tcm-login.css';
		$js  = TCM_PATH . 'assets/tcm-login.js';

		// Police Rubik (charte club) pour le CTA + la modale.
		wp_enqueue_style( 'tcm-font-rubik-front', 'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;900&display=swap', array(), null );
		wp_enqueue_style( 'tcm-login', TCM_URL . 'assets/tcm-login.css', array( 'tcm-font-rubik-front' ), file_exists( $css ) ? filemtime( $css ) : TCM_VERSION );
		wp_enqueue_script( 'tcm-login', TCM_URL . 'assets/tcm-login.js', array(), file_exists( $js ) ? filemtime( $js ) : TCM_VERSION, true );

		wp_localize_script( 'tcm-login', 'TCM_LOGIN', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'tcm_login' ),
			'loggedIn'  => is_user_logged_in() ? 1 : 0,
			'dashboard' => $this->dashboard_url(),
			'label'     => $this->cta_label(),
		) );
	}

	public function modal(): void {
		?>
<div class="tcm-login-modal" id="tcm-login-modal" aria-hidden="true">
	<div class="tcm-login-box" role="dialog" aria-modal="true" aria-label="Connexion">
		<button type="button" class="tcm-login-close" aria-label="Fermer">&times;</button>
		<h3><?php echo esc_html( $this->cta_label() ); ?></h3>
		<p class="tcm-login-sub">Connectez-vous pour accéder à votre tableau de bord.</p>
		<form class="tcm-login-form">
			<label>Identifiant ou e-mail
				<input type="text" name="log" autocomplete="username" required>
			</label>
			<label>Mot de passe
				<input type="password" name="pwd" autocomplete="current-password" required>
			</label>
			<label class="tcm-login-remember"><input type="checkbox" name="remember"> Se souvenir de moi</label>
			<div class="tcm-login-err" role="alert"></div>
			<button type="submit" class="tcm-cta tcm-login-submit">Se connecter</button>
			<a class="tcm-login-forgot" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Mot de passe oublié ?</a>
		</form>
	</div>
</div>
		<?php
	}

	public function ajax_login(): void {
		check_ajax_referer( 'tcm_login', 'nonce' );

		$creds = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) ( $_POST['pwd'] ?? '' ),
			'remember'      => ! empty( $_POST['remember'] ),
		);

		if ( '' === $creds['user_login'] || '' === $creds['user_password'] ) {
			wp_send_json_error( array( 'message' => 'Merci de renseigner votre identifiant et votre mot de passe.' ) );
		}

		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			// Message générique (ne révèle pas si l'identifiant existe).
			wp_send_json_error( array( 'message' => 'Identifiant ou mot de passe incorrect.' ) );
		}

		wp_send_json_success( array( 'redirect' => $this->dashboard_url() ) );
	}
}
