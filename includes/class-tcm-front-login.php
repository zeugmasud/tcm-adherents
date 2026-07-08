<?php
/**
 * CTA « Espace adhérent » dans le menu public + modale de connexion.
 *
 * - Injecte un bouton CTA (charte club) dans le menu principal du site public,
 *   pointant vers /tableau-de-bord/.
 * - Si le visiteur n'est pas connecté, un clic ouvre une modale de connexion.
 *   Le formulaire se soumet NATIVEMENT vers wp-login.php (endpoint standard) :
 *   c'est la seule méthode qui pose un cookie de session fiable sur cette config
 *   (proxy Plesk + plugin de consentement cookies). Une connexion initiée hors
 *   wp-login (front-office, REST, admin-ajax) authentifie mais le cookie n'est
 *   pas reconnu sur la redirection immédiate.
 * - Neutralisé dans le back-office (pages du shell tcm-crm-shell) via le JS.
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
	}

	/** URL du tableau de bord (cible du CTA). */
	private function dashboard_url(): string {
		$p = get_page_by_path( 'tableau-de-bord' );
		return $p ? get_permalink( $p ) : home_url( '/tableau-de-bord/' );
	}

	/** Libellé du CTA (filtrable). */
	private function cta_label(): string {
		return (string) apply_filters( 'tcm_cta_label', 'Connexion' );
	}

	public function assets(): void {
		$css = TCM_PATH . 'assets/tcm-login.css';
		$js  = TCM_PATH . 'assets/tcm-login.js';

		// Police Rubik (charte club) pour le CTA + la modale.
		wp_enqueue_style( 'tcm-font-rubik-front', 'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;900&display=swap', array(), null );
		wp_enqueue_style( 'tcm-login', TCM_URL . 'assets/tcm-login.css', array( 'tcm-font-rubik-front' ), file_exists( $css ) ? filemtime( $css ) : TCM_VERSION );
		wp_enqueue_script( 'tcm-login', TCM_URL . 'assets/tcm-login.js', array(), file_exists( $js ) ? filemtime( $js ) : TCM_VERSION, true );

		wp_localize_script( 'tcm-login', 'TCM_LOGIN', array(
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
		<form class="tcm-login-form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->dashboard_url() ); ?>">
			<label>Identifiant ou e-mail
				<input type="text" name="log" autocomplete="username" required>
			</label>
			<label>Mot de passe
				<input type="password" name="pwd" autocomplete="current-password" required>
			</label>
			<label class="tcm-login-remember"><input type="checkbox" name="rememberme" value="forever"> Se souvenir de moi</label>
			<div class="tcm-login-err" role="alert"></div>
			<button type="submit" class="tcm-login-submit">Se connecter</button>
			<a class="tcm-login-forgot" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Mot de passe oublié ?</a>
		</form>
	</div>
</div>
		<?php
	}
}
