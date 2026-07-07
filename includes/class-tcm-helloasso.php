<?php
/**
 * Ingestion des paiements HelloAsso (CB) via webhook.
 *
 * HelloAsso API v5 : notifications Order + Payment, signées HMAC-SHA256
 * dans l'en-tête x-ha-signature. On vérifie la signature, puis on crée un
 * Règlement de canal "helloasso" rattaché à l'Adhérent (rapprochement Nom+DOB
 * ou email quand disponible).
 *
 * Le secret est stocké dans l'option 'tcm_helloasso_secret' (à renseigner en admin).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_HelloAsso {

	const ROUTE_NS   = 'tcm/v1';
	const ROUTE_PATH = '/helloasso';

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_shortcode( 'tcm_helloasso', array( $this, 'sc_embed' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_helloasso_settings', array( $this, 'save_settings' ) );
	}

	/* =====================================================================
	 * Phase 1 — Page publique avec formulaire HelloAsso intégré
	 * =================================================================== */

	/** Shortcode [tcm_helloasso] : rend le formulaire HelloAsso intégré. */
	public function sc_embed( $atts ): string {
		$embed = (string) get_option( 'tcm_helloasso_embed', '' );
		$intro = (string) get_option( 'tcm_helloasso_intro', '' );

		ob_start();
		echo '<div class="tcm-ha">';
		if ( '' !== $intro ) {
			echo '<div class="tcm-ha-intro">' . wp_kses_post( wpautop( $intro ) ) . '</div>';
		}

		if ( '' === trim( $embed ) ) {
			if ( current_user_can( 'tcm_manage' ) ) {
				echo '<div class="tcm-ha-empty">Formulaire HelloAsso non configuré. Collez votre code d’intégration dans <strong>TC Mimet → Paiement en ligne</strong>.</div>';
			} else {
				echo '<div class="tcm-ha-empty">Le paiement en ligne sera bientôt disponible. Contactez le club à <a href="mailto:contact@tcmimet.fr">contact@tcmimet.fr</a>.</div>';
			}
		} else {
			// Contenu fourni par l'admin (confiance) : soit un shortcode
			// [helloasso …] (plugin HelloAsso), soit un iframe brut. do_shortcode
			// exécute le premier et laisse le second inchangé.
			echo '<div class="tcm-ha-embed">' . do_shortcode( $embed ) . '</div>';
		}
		echo '</div>';

		echo '<style>'
			. '.tcm-ha{max-width:760px;margin:0 auto;font-family:"Rubik",sans-serif;}'
			. '.tcm-ha-intro{margin:0 0 22px;font-size:16px;line-height:1.6;color:#1A1815;}'
			. '.tcm-ha-embed{position:relative;}'
			. '.tcm-ha-embed iframe{width:100%!important;min-height:740px;border:0;display:block;}'
			. '.tcm-ha-empty{background:#FBF7EF;border:1px solid #eadfc7;border-radius:12px;padding:22px 24px;color:#6b6659;}'
			. '</style>';
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Réglages (code d'intégration + secret webhook + slug)
	 * =================================================================== */

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Paiement en ligne', 'tcm-adherents' ),
			__( 'Paiement en ligne', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-paiement',
			array( $this, 'render_settings' )
		);
	}

	public function render_settings(): void {
		$embed  = (string) get_option( 'tcm_helloasso_embed', '' );
		$intro  = (string) get_option( 'tcm_helloasso_intro', '' );
		$secret = (string) get_option( 'tcm_helloasso_secret', '' );
		$slug   = (string) get_option( 'tcm_helloasso_slug', '' );
		$saved  = isset( $_GET['msg'] ) && 'saved' === $_GET['msg'];

		echo '<div class="wrap"><h1>' . esc_html__( 'Paiement en ligne (HelloAsso)', 'tcm-adherents' ) . '</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
		}
		echo '<p>Collez ci-dessous le <strong>code d’intégration HelloAsso</strong> (bloc <code>&lt;iframe id="haWidget"…&gt;</code> depuis « Partager → Intégrer le formulaire »). Il s’affiche via le shortcode <code>[tcm_helloasso]</code> sur la page de règlement.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_helloasso_settings' );
		echo '<input type="hidden" name="action" value="tcm_helloasso_settings">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="tcm-ha-embed">Code d’intégration</label></th><td>'
			. '<textarea id="tcm-ha-embed" name="embed" rows="6" class="large-text code">' . esc_textarea( $embed ) . '</textarea>'
			. '<p class="description">Le code iframe fourni par HelloAsso.</p></td></tr>';
		echo '<tr><th><label for="tcm-ha-intro">Texte d’introduction</label></th><td>'
			. '<textarea id="tcm-ha-intro" name="intro" rows="3" class="large-text">' . esc_textarea( $intro ) . '</textarea>'
			. '<p class="description">Optionnel, affiché au-dessus du formulaire.</p></td></tr>';
		echo '<tr><th><label for="tcm-ha-slug">Slug organisation HelloAsso</label></th><td>'
			. '<input type="text" id="tcm-ha-slug" name="slug" value="' . esc_attr( $slug ) . '" class="regular-text" placeholder="tennis-club-mimet">'
			. '<p class="description">Optionnel — utile pour l’API / le webhook (phase 2).</p></td></tr>';
		echo '<tr><th><label for="tcm-ha-secret">Secret webhook</label></th><td>'
			. '<input type="text" id="tcm-ha-secret" name="secret" value="' . esc_attr( $secret ) . '" class="regular-text">'
			. '<p class="description">Pour la vérification HMAC des notifications HelloAsso. URL du webhook : <code>' . esc_html( home_url( '/wp-json/' . self::ROUTE_NS . self::ROUTE_PATH ) ) . '</code></p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Enregistrer', 'tcm-adherents' ) );
		echo '</form>';

		echo '</div>';
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_helloasso_settings' ) ) {
			wp_die( 'Accès refusé.' );
		}
		// Le contenu peut être un shortcode [helloasso …] (conservé tel quel) ou un
		// iframe brut (nettoyé via kses restreint).
		$allowed   = array( 'iframe' => array( 'id' => true, 'src' => true, 'style' => true, 'width' => true, 'height' => true, 'frameborder' => true, 'scrolling' => true, 'allow' => true, 'allowtransparency' => true, 'referrerpolicy' => true, 'title' => true, 'name' => true, 'class' => true, 'loading' => true, 'sandbox' => true ), 'div' => array( 'class' => true, 'style' => true, 'id' => true ), 'p' => array( 'class' => true, 'style' => true ), 'a' => array( 'href' => true, 'class' => true, 'style' => true, 'target' => true, 'rel' => true ) );
		$embed_raw = trim( (string) wp_unslash( $_POST['embed'] ?? '' ) );
		$embed_val = ( '' !== $embed_raw && '[' === $embed_raw[0] ) ? $embed_raw : wp_kses( $embed_raw, $allowed );

		update_option( 'tcm_helloasso_embed', $embed_val );
		update_option( 'tcm_helloasso_intro', sanitize_textarea_field( wp_unslash( $_POST['intro'] ?? '' ) ) );
		update_option( 'tcm_helloasso_slug', sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ) );
		update_option( 'tcm_helloasso_secret', sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=tcm-paiement&msg=saved' ) );
		exit;
	}

	public function register_route(): void {
		register_rest_route( self::ROUTE_NS, self::ROUTE_PATH, array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => '__return_true', // Auth = signature HMAC (ci-dessous).
		) );
	}

	/**
	 * URL du webhook à déclarer côté HelloAsso :
	 *   https://dev.tcmimet.fr/wp-json/tcm/v1/helloasso
	 */
	public function handle( WP_REST_Request $request ) {
		$raw    = $request->get_body();
		$secret = (string) get_option( 'tcm_helloasso_secret', '' );

		if ( '' === $secret || ! $this->verify_signature( $raw, $request->get_header( 'x-ha-signature' ), $secret ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_signature' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_payload' ), 400 );
		}

		// On ne traite que les événements de paiement effectifs.
		$event = $payload['eventType'] ?? '';
		if ( 'Payment' !== $event ) {
			return new WP_REST_Response( array( 'ok' => true, 'skipped' => $event ), 200 );
		}

		$this->create_reglement_from_payment( $payload['data'] ?? array() );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private function verify_signature( string $raw, ?string $received, string $secret ): bool {
		if ( ! $received ) {
			return false;
		}
		$computed = hash_hmac( 'sha256', $raw, $secret );
		return hash_equals( $computed, $received );
	}

	/**
	 * Crée le Règlement. Rapprochement de l'adhérent :
	 *  1) via metadata.adherent_id si transmise à l'initialisation du paiement,
	 *  2) sinon via Nom+DOB du payeur,
	 *  3) sinon Règlement "non rapproché" à traiter en admin.
	 *
	 * TODO (à finaliser avec un vrai payload) : mapping exact des champs HelloAsso.
	 */
	private function create_reglement_from_payment( array $data ): void {
		$montant = isset( $data['amount'] ) ? ( (float) $data['amount'] / 100 ) : 0.0; // HelloAsso = centimes.
		$ref     = (string) ( $data['id'] ?? '' );

		// Idempotence : ne pas recréer si la référence existe déjà.
		if ( $ref && $this->reglement_exists( $ref ) ) {
			return;
		}

		$adherent_id = $this->match_adherent( $data );

		$post_id = wp_insert_post( array(
			'post_type'   => TCM_CPT_REGLEMENT,
			'post_status' => 'publish',
			'post_title'  => 'Règlement HelloAsso ' . $ref,
		) );

		if ( is_wp_error( $post_id ) ) {
			return;
		}

		update_field( 'montant', $montant, $post_id );
		update_field( 'canal', 'helloasso', $post_id );
		update_field( 'ref_helloasso', $ref, $post_id );
		update_field( 'statut', 'valide', $post_id );
		update_field( 'date_reglement', gmdate( 'Ymd' ), $post_id );
		if ( $adherent_id ) {
			update_field( 'adherent', $adherent_id, $post_id );
		}
	}

	private function reglement_exists( string $ref ): bool {
		return ! empty( get_posts( array(
			'post_type'      => TCM_CPT_REGLEMENT,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => 'any',
			'meta_key'       => 'ref_helloasso',
			'meta_value'     => $ref,
		) ) );
	}

	/**
	 * @return int ID Adhérent ou 0.
	 */
	private function match_adherent( array $data ): int {
		// 1) metadata explicite.
		if ( ! empty( $data['metadata']['adherent_id'] ) ) {
			return (int) $data['metadata']['adherent_id'];
		}

		// 2) Nom + DOB du payeur (si présents dans le payload).
		$payer = $data['payer'] ?? array();
		if ( ! empty( $payer['lastName'] ) && ! empty( $payer['dateOfBirth'] ) ) {
			$key    = TCM_Dedup::make_key( $payer['lastName'], $payer['firstName'] ?? '', gmdate( 'Ymd', strtotime( $payer['dateOfBirth'] ) ) );
			$person = TCM_Dedup::find_by_key( $key );
			if ( $person ) {
				$saison = (string) apply_filters( 'tcm_saison_courante', gmdate( 'Y' ) );
				return TCM_Logic::adherent_pour_saison( $person, $saison );
			}
		}

		return 0;
	}
}
