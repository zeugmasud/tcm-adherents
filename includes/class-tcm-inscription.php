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
	}

	private function saison(): string {
		return (string) apply_filters( 'tcm_saison_courante', get_option( 'tcm_saison_courante', gmdate( 'Y' ) ) );
	}

	/* =====================================================================
	 * Rendu du formulaire
	 * =================================================================== */

	public function sc_form( $atts ): string {
		$saison = $this->saison();

		ob_start();
		echo '<div class="tcm-insc">';

		$msg = isset( $_GET['insc'] ) ? sanitize_key( wp_unslash( $_GET['insc'] ) ) : '';
		if ( 'ok' === $msg ) {
			echo '<div class="tcm-insc-ok"><h3>Inscription enregistrée ✔</h3>'
				. '<p>Merci ! Votre demande d’inscription pour la saison ' . esc_html( $saison ) . ' a bien été enregistrée. '
				. 'Le bureau du club reviendra vers vous pour finaliser le dossier.</p></div>';
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
				'tech'    => 'Une erreur technique est survenue, merci de réessayer.',
			);
			$e = isset( $_GET['e'] ) ? sanitize_key( wp_unslash( $_GET['e'] ) ) : 'tech';
			echo '<div class="tcm-insc-err">' . esc_html( $codes[ $e ] ?? $codes['tech'] ) . '</div>';
		}

		echo '<h2 class="tcm-insc-title">Inscription — saison ' . esc_html( $saison ) . '</h2>';

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
		echo '<fieldset class="tcm-insc-chg-wrap" data-tcm-chg-wrap hidden><legend>Coordonnées</legend>';
		$this->field_radio( 'changement', 'Vos coordonnées ont-elles changé depuis l’an dernier ?', array( 'non' => 'Non', 'oui' => 'Oui' ), false );
		echo '</fieldset>';

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
		$this->field_radio( 'attestation_demandee', 'Désirez-vous une attestation de paiement ?', array( 'non' => 'Non', 'oui' => 'Oui' ), false );
		echo '</fieldset>';

		// Consentements.
		echo '<fieldset><legend>Autorisations</legend>';
		$this->field_consent( 'reglement_interieur', 'Je reconnais avoir pris connaissance du <a href="' . esc_url( home_url( '/reglement-interieur/' ) ) . '" target="_blank" rel="noopener">Règlement Intérieur</a> affiché au club et m’engage à le respecter.', true );
		$this->field_consent( 'assurance_info', 'Je reconnais être informé(e) de l’intérêt de souscrire à un contrat de dommages corporels (cf. information FFT).', true );
		$this->field_radio( 'autorisation_photo', 'Droit à l’image : j’autorise le TC Mimet à utiliser les photos prises de moi-même ou de mon enfant pour communiquer (site, presse locale), à titre gracieux.', array( 'oui' => 'Oui, j’autorise', 'non' => 'Non' ), true );
		echo '</fieldset>';

		echo '<div class="tcm-insc-submit"><button type="submit" class="tcm-insc-btn">Envoyer mon inscription</button></div>';
		echo '</form>';

		$this->inline_script();

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
	}
	if(dob){ dob.addEventListener('change', toggleMineur); dob.addEventListener('input', toggleMineur); }
	f.querySelectorAll('[name="deja_adherent"]').forEach(function(r){ r.addEventListener('change', apply); });
	f.querySelectorAll('[name="changement"]').forEach(function(r){ r.addEventListener('change', apply); });
	toggleMineur(); apply();
})();
</script>
		<?php
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

		$deja       = sanitize_text_field( wp_unslash( $_POST['deja_adherent'] ?? '' ) );
		$changement = ( 'oui' === sanitize_text_field( wp_unslash( $_POST['changement'] ?? '' ) ) );
		// Le bloc coordonnées est présenté pour une nouvelle inscription ou un changement déclaré.
		$coords_attendues = ( 'oui' !== $deja ) || $changement;

		if ( '' === $nom || '' === $prenom || 8 !== strlen( $dob ) ) {
			$this->back( $redirect, 'err', 'champs' );
		}
		if ( $coords_attendues && ( '' === $email || '' === $tel ) ) {
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
		if ( ! $ri || ! $assurance || ( 'oui' !== $photo_raw && 'non' !== $photo_raw ) ) {
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

		$this->back( $redirect, 'ok', '' );
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
}
