<?php
/**
 * Formulaire d'inscription public (natif).
 *
 * Shortcode [tcm_inscription] : formulaire public + handler serveur qui
 * normalise, dédoublonne (Nom+DOB via TCM_Dedup), refuse la double inscription
 * sur la saison courante, et crée l'Adhérent de la saison.
 *
 * Saison = réglage « Saison courante » (filtre tcm_saison_courante).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Inscription {

	public function hooks(): void {
		add_shortcode( 'tcm_inscription', array( $this, 'sc_form' ) );
		add_action( 'admin_post_tcm_inscription', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_tcm_inscription', array( $this, 'handle' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_tcm_insc_settings', array( $this, 'save_settings' ) );
	}

	private function saison(): string {
		return (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );
	}

	/** Réglages du formulaire (valeurs par défaut + option). */
	private function settings(): array {
		$defaults = array(
			'intro'            => '',
			'success_title'    => 'Inscription enregistrée ✔',
			'success_msg'      => 'Merci ! Votre demande d’inscription pour la saison {saison} a bien été enregistrée. Le bureau du club reviendra vers vous pour finaliser le dossier.',
			'submit_label'     => 'Envoyer mon inscription',
			'ri_url'           => home_url( '/reglement-interieur/' ),
			'show_attestation' => 1,
			'show_changement'  => 1,
			'require_address'  => 0,
			'require_photo'    => 1,
			// reCAPTCHA v3 (anti-spam Google). Vide = désactivé.
			'recaptcha_site'      => '',
			'recaptcha_secret'    => '',
			'recaptcha_threshold' => 0.5,
			// E-mails.
			'mail_from'           => 'contact@tcmimet.fr',
			'mail_from_name'      => 'Tennis Club de Mimet',
			'mail_ack_on'         => 1,
			'mail_ack_subject'    => 'Votre demande d’inscription au Tennis Club de Mimet',
			'mail_ack_body'       => "Bonjour {prenom} {nom},\n\nNous avons bien reçu votre demande d’inscription pour la saison {saison}. Le bureau du club va l’étudier et reviendra vers vous pour finaliser le dossier.\n\nSportivement,\nLe Tennis Club de Mimet",
			'mail_notify_on'      => 1,
			'mail_notify_to'      => get_option( 'admin_email' ),
			'mail_notify_subject' => 'Nouvelle inscription — {nom} {prenom} ({saison})',
		);
		$saved = get_option( 'tcm_insc_settings', array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/* =====================================================================
	 * Rendu du formulaire
	 * =================================================================== */

	public function sc_form( $atts ): string {
		$saison = $this->saison();
		$s      = $this->settings();

		ob_start();
		echo '<div class="tcm-insc">';

		$msg = isset( $_GET['insc'] ) ? sanitize_key( wp_unslash( $_GET['insc'] ) ) : '';
		if ( 'ok' === $msg ) {
			$body = str_replace( '{saison}', $saison, (string) $s['success_msg'] );
			echo '<div class="tcm-insc-ok"><h3>' . esc_html( $s['success_title'] ) . '</h3>'
				. '<p>' . esc_html( $body ) . '</p></div>';
			echo '</div>';
			return (string) ob_get_clean();
		}
		if ( 'err' === $msg ) {
			$codes = array(
				'champs'  => 'Merci de remplir les champs obligatoires (nom, prénom, date de naissance, e-mail, téléphone).',
				'parent'  => 'Pour un adhérent mineur, merci d’indiquer au moins un représentant légal (nom et téléphone).',
				'consent' => 'Merci d’accepter le règlement intérieur, l’information assurance et de répondre au droit à l’image.',
				'doublon' => 'Une inscription existe déjà pour cette personne sur la saison ' . $saison . '.',
				'nonce'   => 'Session expirée, merci de renvoyer le formulaire.',
				'captcha' => 'La vérification anti-spam a échoué. Merci de réessayer (activez le JavaScript).',
				'tech'    => 'Une erreur technique est survenue, merci de réessayer.',
			);
			$e = isset( $_GET['e'] ) ? sanitize_key( wp_unslash( $_GET['e'] ) ) : 'tech';
			echo '<div class="tcm-insc-err">' . esc_html( $codes[ $e ] ?? $codes['tech'] ) . '</div>';
		}

		echo '<h2 class="tcm-insc-title">Inscription — saison ' . esc_html( $saison ) . '</h2>';
		if ( '' !== trim( (string) $s['intro'] ) ) {
			echo '<div class="tcm-insc-intro">' . wp_kses_post( wpautop( $s['intro'] ) ) . '</div>';
		}

		echo '<form class="tcm-insc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_inscription', 'tcm_insc_nonce' );
		echo '<input type="hidden" name="action" value="tcm_inscription">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( get_permalink() ) . '">';
		// Honeypot anti-spam (caché).
		echo '<div class="tcm-hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

		// Déjà adhérent du club ?
		echo '<fieldset><legend>Votre situation</legend>';
		$this->field_radio( 'deja_adherent', 'Déjà adhérent(e) du club ?', array( 'non' => 'Non, nouvelle inscription', 'oui' => 'Oui, renouvellement' ), true );
		echo '</fieldset>';

		echo '<fieldset><legend>Adhérent(e)</legend><div class="tcm-insc-grid">';
		$this->field_select( 'civilite', 'Civilité', array( '' => '—', 'Mme' => 'Madame', 'M.' => 'Monsieur' ), true );
		$this->field( 'nom', 'Nom', 'text', true );
		$this->field( 'prenom', 'Prénom', 'text', true );
		$this->field( 'date_naissance', 'Date de naissance', 'date', true );
		echo '</div></fieldset>';

		// Représentant légal — affiché conditionnellement (mineur) via JS.
		echo '<fieldset class="tcm-insc-mineur" data-tcm-mineur hidden><legend>Représentant légal (obligatoire pour un mineur)</legend><div class="tcm-insc-grid">';
		$this->field( 'parent_mere_nom', 'Nom / prénom mère', 'text', false );
		$this->field( 'parent_mere_tel', 'Tél. mère', 'tel', false );
		$this->field( 'parent_pere_nom', 'Nom / prénom père', 'text', false );
		$this->field( 'parent_pere_tel', 'Tél. père', 'tel', false );
		$this->field( 'autre_contact', 'Autre personne à prévenir', 'text', false, 'tcm-col-2' );
		echo '</div></fieldset>';

		// Changement de coordonnées — question posée aux renouvellements uniquement (JS).
		if ( $s['show_changement'] ) {
			echo '<fieldset class="tcm-insc-chg-wrap" data-tcm-chg-wrap hidden><legend>Coordonnées</legend>';
			$this->field_radio( 'changement', 'Vos coordonnées ont-elles changé depuis l’an dernier ?', array( 'non' => 'Non', 'oui' => 'Oui' ), false );
			echo '</fieldset>';
		}

		// Coordonnées — affichées pour une nouvelle inscription ou si changement déclaré (JS).
		echo '<fieldset class="tcm-insc-coords" data-tcm-coords hidden><legend>Vos coordonnées</legend><div class="tcm-insc-grid">';
		$this->field( 'email', 'E-mail', 'email', false );
		$this->field( 'telephone', 'Téléphone', 'tel', false );
		$this->field( 'adresse', 'Adresse', 'text', false, 'tcm-col-2' );
		$this->field( 'cp', 'Code postal', 'text', false );
		$this->field( 'ville', 'Ville', 'text', false );
		echo '</div></fieldset>';

		echo '<fieldset><legend>Informations complémentaires</legend>';
		echo '<div class="tcm-insc-field tcm-col-2"><label for="tcm-insc-comm">Commentaires</label>';
		echo '<textarea id="tcm-insc-comm" name="commentaires" rows="3"></textarea></div>';
		if ( $s['show_attestation'] ) {
			$this->field_radio( 'attestation_demandee', 'Désirez-vous une attestation de paiement ?', array( 'non' => 'Non', 'oui' => 'Oui' ), false );
		}
		echo '</fieldset>';

		// Consentements.
		echo '<fieldset><legend>Autorisations</legend>';
		$this->field_consent( 'reglement_interieur', 'Je reconnais avoir pris connaissance du <a href="' . esc_url( $s['ri_url'] ) . '" target="_blank" rel="noopener">Règlement Intérieur</a> affiché au club et m’engage à le respecter.', true );
		$this->field_consent( 'assurance_info', 'Je reconnais être informé(e) de l’intérêt de souscrire à un contrat de dommages corporels (cf. information FFT).', true );
		$this->field_radio( 'autorisation_photo', 'Droit à l’image : j’autorise le TC Mimet à utiliser les photos prises de moi-même ou de mon enfant pour communiquer (site, presse locale), à titre gracieux.', array( 'oui' => 'Oui, j’autorise', 'non' => 'Non' ), (bool) $s['require_photo'] );
		echo '</fieldset>';

		// reCAPTCHA v3 : champ caché qui portera le token (rempli en JS avant l'envoi).
		if ( '' !== trim( (string) $s['recaptcha_site'] ) ) {
			echo '<input type="hidden" name="tcm_recaptcha_token" value="">';
		}

		echo '<div class="tcm-insc-submit"><button type="submit" class="tcm-insc-btn">' . esc_html( $s['submit_label'] ) . '</button></div>';
		echo '</form>';

		$this->inline_script();
		$this->recaptcha_script( (string) $s['recaptcha_site'] );

		echo '</div>';
		return (string) ob_get_clean();
	}

	/** Affichage conditionnel : bloc mineur (âge), question changement (renouvellement), nouvelles coordonnées. */
	private function inline_script(): void {
		?>
<script>
(function(){
	var f = document.currentScript.closest('.tcm-insc').querySelector('.tcm-insc-form');
	if(!f){ return; }
	var dob   = f.querySelector('[name="date_naissance"]');
	var mineur= f.querySelector('[data-tcm-mineur]');
	var chgW  = f.querySelector('[data-tcm-chg-wrap]');
	var coords= f.querySelector('[data-tcm-coords]');

	function ageFrom(v){
		if(!v){ return null; }
		var d = new Date(v); if(isNaN(d)){ return null; }
		var t = new Date(), a = t.getFullYear()-d.getFullYear();
		var m = t.getMonth()-d.getMonth();
		if(m<0 || (m===0 && t.getDate()<d.getDate())){ a--; }
		return a;
	}
	function val(name){
		var el = f.querySelector('[name="'+name+'"]:checked');
		return el ? el.value : '';
	}
	// Rend obligatoires (ou non) les coordonnées ; le « required » doit être
	// dynamique car un champ requis masqué bloque l'envoi de façon invisible.
	function setCoordsRequired(on){
		['email','telephone','adresse','cp','ville'].forEach(function(n){
			var el = f.querySelector('[name="'+n+'"]');
			if(!el){ return; }
			el.required = on;
			var wrap = el.closest('.tcm-insc-field');
			var lab  = wrap ? wrap.querySelector('label') : null;
			if(lab){
				var star = lab.querySelector('.tcm-req');
				if(on && !star){ star = document.createElement('span'); star.className = 'tcm-req'; star.textContent = ' *'; lab.appendChild(star); }
				else if(!on && star){ star.remove(); }
			}
		});
	}
	function toggleMineur(){
		var a = ageFrom(dob && dob.value);
		if(mineur){ mineur.hidden = !(a!==null && a<18); }
	}
	function apply(){
		var deja = val('deja_adherent');
		// Question « changement » : uniquement pour un renouvellement.
		if(chgW){ chgW.hidden = (deja !== 'oui'); }
		// Coordonnées : nouvelle inscription, ou renouvellement avec changement déclaré.
		var show = (deja === 'non') || (deja === 'oui' && val('changement') === 'oui');
		if(coords){ coords.hidden = !show; }
		setCoordsRequired(show);
	}
	if(dob){ dob.addEventListener('change', toggleMineur); dob.addEventListener('input', toggleMineur); }
	f.querySelectorAll('[name="deja_adherent"]').forEach(function(r){ r.addEventListener('change', apply); });
	f.querySelectorAll('[name="changement"]').forEach(function(r){ r.addEventListener('change', apply); });
	toggleMineur(); apply();
})();
</script>
		<?php
	}

	/** Charge reCAPTCHA v3 et remplit le token juste avant l'envoi natif du formulaire. */
	private function recaptcha_script( string $site ): void {
		$site = trim( $site );
		if ( '' === $site ) {
			return;
		}
		?>
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo rawurlencode( $site ); ?>"></script>
<script>
(function(){
	var site = <?php echo wp_json_encode( $site ); // phpcs:ignore ?>;
	var form = document.querySelector('.tcm-insc .tcm-insc-form');
	var field = form && form.querySelector('[name="tcm_recaptcha_token"]');
	if(!form || !field){ return; }
	var done = false;
	form.addEventListener('submit', function(e){
		if(done){ return; } // 2e passage : on laisse partir.
		e.preventDefault();
		if(typeof grecaptcha === 'undefined'){ done = true; form.submit(); return; }
		grecaptcha.ready(function(){
			grecaptcha.execute(site, {action:'inscription'}).then(function(token){
				field.value = token || '';
				done = true; form.submit();
			}).catch(function(){ done = true; form.submit(); });
		});
	});
})();
</script>
		<?php
	}

	/**
	 * Vérifie le token reCAPTCHA v3 côté serveur. Retourne true si l'anti-spam est
	 * désactivé (pas de secret) ou si le score est suffisant. En cas d'échec réseau
	 * vers Google, on ne bloque pas l'inscription (tolérance).
	 */
	private function recaptcha_ok( array $s ): bool {
		$secret = trim( (string) ( $s['recaptcha_secret'] ?? '' ) );
		if ( '' === $secret ) {
			return true;
		}
		$token = isset( $_POST['tcm_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['tcm_recaptcha_token'] ) ) : '';
		if ( '' === $token ) {
			return false;
		}
		$resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'timeout' => 8,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return true; // Google injoignable : on laisse passer plutôt que bloquer une vraie inscription.
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}
		$threshold = (float) ( $s['recaptcha_threshold'] ?? 0.5 );
		$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
		return $score >= $threshold;
	}

	private function field( string $name, string $label, string $type = 'text', bool $required = false, string $extra_class = '' ): void {
		$id = 'tcm-insc-' . $name;
		echo '<div class="tcm-insc-field ' . esc_attr( $extra_class ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span class="tcm-req">*</span>' : '' ) . '</label>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . '>';
		echo '</div>';
	}

	private function field_select( string $name, string $label, array $choices, bool $required = false ): void {
		$id = 'tcm-insc-' . $name;
		echo '<div class="tcm-insc-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span class="tcm-req">*</span>' : '' ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . '>';
		foreach ( $choices as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $lab ) . '</option>';
		}
		echo '</select></div>';
	}

	private function field_radio( string $name, string $label, array $choices, bool $required = false ): void {
		echo '<div class="tcm-insc-radio tcm-col-2">';
		echo '<span class="tcm-insc-radiolabel">' . esc_html( $label ) . ( $required ? ' <span class="tcm-req">*</span>' : '' ) . '</span>';
		echo '<div class="tcm-insc-radios">';
		foreach ( $choices as $k => $lab ) {
			echo '<label class="tcm-insc-radio-opt"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '"' . ( $required ? ' required' : '' ) . '> <span>' . esc_html( $lab ) . '</span></label>';
		}
		echo '</div></div>';
	}

	private function field_consent( string $name, string $html, bool $required = false ): void {
		echo '<div class="tcm-insc-consent"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $required ? ' required' : '' ) . '> <span>' . wp_kses_post( $html ) . ( $required ? ' <span class="tcm-req">*</span>' : '' ) . '</span></label></div>';
	}

	/* =====================================================================
	 * Handler
	 * =================================================================== */

	public function handle(): void {
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url();

		// Anti-spam : nonce + honeypot.
		if ( ! isset( $_POST['tcm_insc_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['tcm_insc_nonce'] ), 'tcm_inscription' ) ) {
			$this->back( $redirect, 'err', 'nonce' );
		}
		if ( ! empty( $_POST['website'] ) ) {
			$this->back( $redirect, 'ok', '' ); // bot : on fait comme si c'était bon, sans rien créer.
		}

		$nom    = TCM_Normalize::nom( sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ) );
		$prenom = TCM_Normalize::prenom( sanitize_text_field( wp_unslash( $_POST['prenom'] ?? '' ) ) );
		$dob    = $this->to_ymd( sanitize_text_field( wp_unslash( $_POST['date_naissance'] ?? '' ) ) );
		$email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$tel    = TCM_Normalize::phone( sanitize_text_field( wp_unslash( $_POST['telephone'] ?? '' ) ) );

		$s          = $this->settings();

		// Anti-spam reCAPTCHA v3 (si configuré) : vérification du score côté serveur.
		if ( ! $this->recaptcha_ok( $s ) ) {
			$this->back( $redirect, 'err', 'captcha' );
		}

		$deja       = sanitize_text_field( wp_unslash( $_POST['deja_adherent'] ?? '' ) );
		$changement = ( 'oui' === sanitize_text_field( wp_unslash( $_POST['changement'] ?? '' ) ) );
		// Le bloc coordonnées est présenté pour une nouvelle inscription ou un changement déclaré.
		$coords_attendues = ( 'oui' !== $deja ) || $changement;

		if ( '' === $nom || '' === $prenom || 8 !== strlen( $dob ) ) {
			$this->back( $redirect, 'err', 'champs' );
		}
		// Nouvelle inscription ou changement de coordonnées : e-mail, téléphone,
		// adresse, code postal et ville sont tous obligatoires.
		if ( $coords_attendues && (
			'' === $email
			|| '' === $tel
			|| '' === sanitize_text_field( wp_unslash( $_POST['adresse'] ?? '' ) )
			|| '' === sanitize_text_field( wp_unslash( $_POST['cp'] ?? '' ) )
			|| '' === sanitize_text_field( wp_unslash( $_POST['ville'] ?? '' ) ) ) ) {
			$this->back( $redirect, 'err', 'champs' );
		}

		$minor = $this->is_minor( $dob );

		// Contacts des représentants légaux (obligatoires si mineur : au moins un parent nom + tél).
		$parents = array();
		foreach ( array( 'parent_mere_nom', 'parent_mere_tel', 'parent_pere_nom', 'parent_pere_tel', 'autre_contact' ) as $f ) {
			$parents[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) );
		}
		if ( $minor ) {
			$has_mere = '' !== $parents['parent_mere_nom'] && '' !== $parents['parent_mere_tel'];
			$has_pere = '' !== $parents['parent_pere_nom'] && '' !== $parents['parent_pere_tel'];
			if ( ! $has_mere && ! $has_pere ) {
				$this->back( $redirect, 'err', 'parent' );
			}
		}

		// Consentements obligatoires : règlement intérieur + info assurance + réponse droit à l'image.
		$ri        = ! empty( $_POST['reglement_interieur'] );
		$assurance = ! empty( $_POST['assurance_info'] );
		$photo_raw = sanitize_text_field( wp_unslash( $_POST['autorisation_photo'] ?? '' ) );
		$photo_ok  = ! (bool) $s['require_photo'] || 'oui' === $photo_raw || 'non' === $photo_raw;
		if ( ! $ri || ! $assurance || ! $photo_ok ) {
			$this->back( $redirect, 'err', 'consent' );
		}

		$data = array(
			'civilite'       => sanitize_text_field( wp_unslash( $_POST['civilite'] ?? '' ) ),
			'nom'            => $nom,
			'prenom'         => $prenom,
			'date_naissance' => $dob,
			'email'          => $email,
			'telephone'      => $tel,
			'adresse'        => sanitize_text_field( wp_unslash( $_POST['adresse'] ?? '' ) ),
			'cp'             => sanitize_text_field( wp_unslash( $_POST['cp'] ?? '' ) ),
			'ville'          => sanitize_text_field( wp_unslash( $_POST['ville'] ?? '' ) ),
		);

		$person = TCM_Dedup::resolve_or_create( $data );
		if ( ! $person ) {
			$this->back( $redirect, 'err', 'tech' );
		}

		// Renouvellement : rafraîchir les coordonnées de la personne si changement déclaré
		// (resolve_or_create ne complète que les champs vides ; ici on écrase avec les nouvelles valeurs).
		if ( $changement ) {
			if ( '' !== $email ) { update_field( 'email', $email, $person ); }
			if ( '' !== $tel )   { update_field( 'telephone', $tel, $person ); }
			foreach ( array( 'adresse', 'cp', 'ville' ) as $f ) {
				if ( '' !== $data[ $f ] ) { update_field( $f, $data[ $f ], $person ); }
			}
		}

		$saison = $this->saison();

		// Anti-doublon : une seule adhésion par personne × saison.
		if ( TCM_Logic::adherent_pour_saison( $person, $saison ) ) {
			$this->back( $redirect, 'err', 'doublon' );
		}

		// Nouvel adhérent : réponse explicite « déjà adhérent » si fournie, sinon déduit de l'historique.
		$deja = sanitize_text_field( wp_unslash( $_POST['deja_adherent'] ?? '' ) );
		if ( 'oui' === $deja ) {
			$nouvel = 0;
		} elseif ( 'non' === $deja ) {
			$nouvel = 1;
		} else {
			$prior  = get_posts( array( 'post_type' => TCM_CPT_ADHERENT, 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish', 'meta_key' => 'personne', 'meta_value' => $person ) );
			$nouvel = empty( $prior ) ? 1 : 0;
		}

		$aid = wp_insert_post( array( 'post_type' => TCM_CPT_ADHERENT, 'post_status' => 'publish', 'post_title' => 'Adhérent' ) );
		if ( ! $aid || is_wp_error( $aid ) ) {
			$this->back( $redirect, 'err', 'tech' );
		}
		update_field( 'personne', $person, $aid );
		update_field( 'saison', $saison, $aid );
		update_field( 'mineur', $minor ? 1 : 0, $aid );
		update_field( 'nouvel_adherent', $nouvel, $aid );
		update_field( 'changement', $changement ? 1 : 0, $aid );
		update_field( 'autorisation_photo', 'oui' === $photo_raw ? 1 : 0, $aid );
		update_field( 'reglement_interieur', 1, $aid );
		update_field( 'assurance_info', 1, $aid );
		update_field( 'attestation_demandee', 'oui' === sanitize_text_field( wp_unslash( $_POST['attestation_demandee'] ?? '' ) ) ? 1 : 0, $aid );
		update_field( 'dossier_complet', 0, $aid );
		update_field( 'adoc_valide', 0, $aid );
		foreach ( $parents as $f => $v ) {
			if ( '' !== $v ) {
				update_field( $f, $v, $aid );
			}
		}
		$comm = sanitize_textarea_field( wp_unslash( $_POST['commentaires'] ?? '' ) );
		if ( '' !== $comm ) {
			update_field( 'commentaires', $comm, $aid );
		}
		TCM_Taxonomies::sync_adherent( $aid );

		// E-mails : accusé de réception à l'adhérent + notification au bureau.
		$this->send_mails( $s, $person, array(
			'prenom'    => $prenom,
			'nom'       => $nom,
			'saison'    => $saison,
			'email'     => $email,
			'telephone' => $tel,
			'dob'       => $dob,
			'mineur'    => $minor,
			'nouvel'    => $nouvel,
		) );

		$this->back( $redirect, 'ok', '' );
	}

	/** Envoie l'accusé de réception (adhérent) et la notification (bureau). */
	private function send_mails( array $s, int $person, array $ctx ): void {
		$from      = $s['mail_from'] ? $s['mail_from'] : 'contact@tcmimet.fr';
		$from_name = $s['mail_from_name'] ? $s['mail_from_name'] : 'Tennis Club de Mimet';
		$headers   = array( 'From: ' . $from_name . ' <' . $from . '>', 'Content-Type: text/plain; charset=UTF-8' );

		$vars = array(
			'{prenom}' => (string) $ctx['prenom'],
			'{nom}'    => (string) $ctx['nom'],
			'{saison}' => (string) $ctx['saison'],
			'{email}'  => (string) $ctx['email'],
		);

		// 1. Accusé de réception à l'adhérent.
		$to_applicant = $ctx['email'] ? $ctx['email'] : (string) get_field( 'email', $person );
		if ( ! empty( $s['mail_ack_on'] ) && is_email( $to_applicant ) ) {
			wp_mail(
				$to_applicant,
				strtr( (string) $s['mail_ack_subject'], $vars ),
				strtr( (string) $s['mail_ack_body'], $vars ),
				$headers
			);
		}

		// 2. Notification au bureau (une ou plusieurs adresses, séparées par des virgules).
		$notify_to = $this->parse_emails( (string) $s['mail_notify_to'] );
		if ( ! empty( $s['mail_notify_on'] ) && $notify_to ) {
			$d      = preg_replace( '/\D/', '', (string) $ctx['dob'] );
			$dob_fr = 8 === strlen( $d ) ? substr( $d, 6, 2 ) . '/' . substr( $d, 4, 2 ) . '/' . substr( $d, 0, 4 ) : (string) $ctx['dob'];
			$lines  = array(
				'Nouvelle demande d’inscription — saison ' . $ctx['saison'],
				'',
				'Nom : ' . $ctx['nom'] . ' ' . $ctx['prenom'],
				'Naissance : ' . $dob_fr . ( $ctx['mineur'] ? ' (mineur)' : '' ),
				'E-mail : ' . ( $ctx['email'] ? $ctx['email'] : '—' ),
				'Téléphone : ' . ( $ctx['telephone'] ? $ctx['telephone'] : '—' ),
				'Type : ' . ( $ctx['nouvel'] ? 'nouvelle inscription' : 'renouvellement' ),
				'',
				'Back-office : ' . admin_url( 'admin.php?page=tcm-adherents' ),
			);
			$nheaders = $headers;
			if ( is_email( $to_applicant ) ) {
				$nheaders[] = 'Reply-To: ' . $to_applicant;
			}
			wp_mail( $notify_to, strtr( (string) $s['mail_notify_subject'], $vars ), implode( "\n", $lines ), $nheaders );
		}
	}

	/** Découpe une liste d'e-mails séparés par des virgules et ne garde que les valides. */
	private function parse_emails( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[,;]+/', $raw ) as $mail ) {
			$mail = sanitize_email( trim( $mail ) );
			if ( is_email( $mail ) ) {
				$out[] = $mail;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function is_minor( string $ymd ): bool {
		if ( 8 !== strlen( $ymd ) ) {
			return false;
		}
		$bd = DateTime::createFromFormat( 'Ymd', $ymd );
		return $bd ? ( (int) $bd->diff( new DateTime( 'today' ) )->y ) < 18 : false;
	}

	private function to_ymd( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m ) ) {
			return sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Ymd', $ts ) : preg_replace( '/\D/', '', $value );
	}

	private function back( string $redirect, string $status, string $code ): void {
		$args = array( 'insc' => $status );
		if ( '' !== $code ) {
			$args['e'] = $code;
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/* =====================================================================
	 * Réglages (back-office)
	 * =================================================================== */

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Formulaire d’adhésion', 'tcm-adherents' ),
			__( 'Formulaire d’adhésion', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-form-adhesion',
			array( $this, 'render_settings' )
		);
	}

	public function render_settings(): void {
		$s      = $this->settings();
		$saison = $this->saison();
		$saved  = isset( $_GET['msg'] ) && 'saved' === $_GET['msg'];

		echo '<div class="wrap"><h1>' . esc_html__( 'Formulaire d’adhésion — réglages', 'tcm-adherents' ) . '</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
		}
		echo '<p>Pilote le formulaire public <code>[tcm_inscription]</code> (page « Inscription »). '
			. 'La saison provient du réglage « Saison courante » (actuellement <strong>' . esc_html( $saison ) . '</strong>, modifiable dans TC Mimet → Réglages).</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_insc_settings' );
		echo '<input type="hidden" name="action" value="tcm_insc_settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ti-intro">Texte d’introduction</label></th><td>'
			. '<textarea id="ti-intro" name="intro" rows="3" class="large-text">' . esc_textarea( $s['intro'] ) . '</textarea>'
			. '<p class="description">Affiché sous le titre, au-dessus du formulaire. Optionnel.</p></td></tr>';

		echo '<tr><th><label for="ti-submit">Libellé du bouton</label></th><td>'
			. '<input type="text" id="ti-submit" name="submit_label" value="' . esc_attr( $s['submit_label'] ) . '" class="regular-text"></td></tr>';

		echo '<tr><th><label for="ti-ri">URL du Règlement Intérieur</label></th><td>'
			. '<input type="url" id="ti-ri" name="ri_url" value="' . esc_attr( $s['ri_url'] ) . '" class="regular-text"></td></tr>';

		echo '<tr><th>Champs affichés / obligatoires</th><td>';
		echo '<label><input type="checkbox" name="show_attestation" value="1" ' . checked( $s['show_attestation'], 1, false ) . '> Afficher la question « Désirez-vous une attestation ? »</label><br>';
		echo '<label><input type="checkbox" name="show_changement" value="1" ' . checked( $s['show_changement'], 1, false ) . '> Proposer le bloc « changement de coordonnées » aux renouvellements</label><br>';
		echo '<label><input type="checkbox" name="require_address" value="1" ' . checked( $s['require_address'], 1, false ) . '> Rendre l’adresse (rue, CP, ville) obligatoire</label><br>';
		echo '<label><input type="checkbox" name="require_photo" value="1" ' . checked( $s['require_photo'], 1, false ) . '> Rendre la réponse « droit à l’image » obligatoire</label>';
		echo '</td></tr>';

		// reCAPTCHA v3.
		echo '<tr><th>reCAPTCHA v3 (anti-spam Google)</th><td>';
		echo '<p style="margin:0 0 4px;"><strong>Clé de site</strong></p><input type="text" name="recaptcha_site" value="' . esc_attr( $s['recaptcha_site'] ) . '" class="large-text" autocomplete="off">';
		echo '<p style="margin:12px 0 4px;"><strong>Clé secrète</strong></p><input type="text" name="recaptcha_secret" value="' . esc_attr( $s['recaptcha_secret'] ) . '" class="large-text" autocomplete="off">';
		echo '<p style="margin:12px 0 4px;"><strong>Seuil de score</strong> (0 = tout accepter, 1 = très strict)</p><input type="number" name="recaptcha_threshold" value="' . esc_attr( (string) $s['recaptcha_threshold'] ) . '" min="0" max="1" step="0.1" class="small-text">';
		echo '<p class="description">Laissez les clés vides pour désactiver. Clés à créer sur <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha/admin</a> (type <strong>reCAPTCHA v3</strong>, domaine <code>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>). Score conseillé : 0.5.</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ti-st">Titre du message de confirmation</label></th><td>'
			. '<input type="text" id="ti-st" name="success_title" value="' . esc_attr( $s['success_title'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th><label for="ti-sm">Message de confirmation</label></th><td>'
			. '<textarea id="ti-sm" name="success_msg" rows="3" class="large-text">' . esc_textarea( $s['success_msg'] ) . '</textarea>'
			. '<p class="description">Affiché après l’envoi. <code>{saison}</code> est remplacé par la saison.</p></td></tr>';

		// --- E-mails --------------------------------------------------------
		echo '<tr><th scope="row"><h2 style="margin:18px 0 0;">E-mails à la soumission</h2></th><td style="padding-top:24px;"></td></tr>';

		echo '<tr><th>Expéditeur</th><td>'
			. 'Nom <input type="text" name="mail_from_name" value="' . esc_attr( $s['mail_from_name'] ) . '" class="regular-text"> &nbsp; '
			. 'Adresse <input type="email" name="mail_from" value="' . esc_attr( $s['mail_from'] ) . '" class="regular-text">'
			. '<p class="description">Expéditeur commun aux deux e-mails.</p></td></tr>';

		echo '<tr><th>Accusé de réception (adhérent)</th><td>'
			. '<label><input type="checkbox" name="mail_ack_on" value="1" ' . checked( $s['mail_ack_on'], 1, false ) . '> Envoyer un e-mail de confirmation à l’adhérent</label>'
			. '<p style="margin:12px 0 4px;"><strong>Objet</strong></p><input type="text" name="mail_ack_subject" value="' . esc_attr( $s['mail_ack_subject'] ) . '" class="large-text">'
			. '<p style="margin:12px 0 4px;"><strong>Message</strong></p><textarea name="mail_ack_body" rows="6" class="large-text">' . esc_textarea( $s['mail_ack_body'] ) . '</textarea>'
			. '<p class="description">Variables : <code>{prenom}</code>, <code>{nom}</code>, <code>{saison}</code>, <code>{email}</code>.</p></td></tr>';

		echo '<tr><th>Notification (bureau)</th><td>'
			. '<label><input type="checkbox" name="mail_notify_on" value="1" ' . checked( $s['mail_notify_on'], 1, false ) . '> Prévenir le bureau à chaque nouvelle inscription</label>'
			. '<p style="margin:12px 0 4px;"><strong>Destinataire(s)</strong></p><input type="text" name="mail_notify_to" value="' . esc_attr( $s['mail_notify_to'] ) . '" class="large-text" placeholder="contact@tcmimet.fr, membre2@exemple.fr">'
			. '<p class="description">Une ou plusieurs adresses, séparées par des virgules. Chacune recevra la notification.</p>'
			. '<p style="margin:12px 0 4px;"><strong>Objet</strong></p><input type="text" name="mail_notify_subject" value="' . esc_attr( $s['mail_notify_subject'] ) . '" class="large-text">'
			. '<p class="description">Le corps liste automatiquement les infos de la demande. Variables d’objet : <code>{prenom}</code>, <code>{nom}</code>, <code>{saison}</code>.</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Enregistrer', 'tcm-adherents' ) );
		echo '</form></div>';
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( 'tcm_insc_settings' ) ) {
			wp_die( 'Accès refusé.' );
		}
		update_option( 'tcm_insc_settings', array(
			'intro'            => sanitize_textarea_field( wp_unslash( $_POST['intro'] ?? '' ) ),
			'submit_label'     => sanitize_text_field( wp_unslash( $_POST['submit_label'] ?? '' ) ) ?: 'Envoyer mon inscription',
			'ri_url'           => esc_url_raw( wp_unslash( $_POST['ri_url'] ?? '' ) ),
			'show_attestation' => empty( $_POST['show_attestation'] ) ? 0 : 1,
			'show_changement'  => empty( $_POST['show_changement'] ) ? 0 : 1,
			'require_address'  => empty( $_POST['require_address'] ) ? 0 : 1,
			'require_photo'    => empty( $_POST['require_photo'] ) ? 0 : 1,
			'recaptcha_site'      => sanitize_text_field( wp_unslash( $_POST['recaptcha_site'] ?? '' ) ),
			'recaptcha_secret'    => sanitize_text_field( wp_unslash( $_POST['recaptcha_secret'] ?? '' ) ),
			'recaptcha_threshold' => max( 0, min( 1, (float) ( $_POST['recaptcha_threshold'] ?? 0.5 ) ) ),
			'success_title'    => sanitize_text_field( wp_unslash( $_POST['success_title'] ?? '' ) ),
			'success_msg'      => sanitize_textarea_field( wp_unslash( $_POST['success_msg'] ?? '' ) ),
			'mail_from'           => sanitize_email( wp_unslash( $_POST['mail_from'] ?? '' ) ),
			'mail_from_name'      => sanitize_text_field( wp_unslash( $_POST['mail_from_name'] ?? '' ) ),
			'mail_ack_on'         => empty( $_POST['mail_ack_on'] ) ? 0 : 1,
			'mail_ack_subject'    => sanitize_text_field( wp_unslash( $_POST['mail_ack_subject'] ?? '' ) ),
			'mail_ack_body'       => sanitize_textarea_field( wp_unslash( $_POST['mail_ack_body'] ?? '' ) ),
			'mail_notify_on'      => empty( $_POST['mail_notify_on'] ) ? 0 : 1,
			'mail_notify_to'      => implode( ', ', $this->parse_emails( wp_unslash( $_POST['mail_notify_to'] ?? '' ) ) ),
			'mail_notify_subject' => sanitize_text_field( wp_unslash( $_POST['mail_notify_subject'] ?? '' ) ),
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=tcm-form-adhesion&msg=saved' ) );
		exit;
	}
}
