<?php
/**
 * Attestations de paiement (PDF) par commande.
 *
 * Génère un PDF « Attestation de paiement » (gabarit du club) pour une commande
 * d'adhérent, via Dompdf (embarqué dans vendor/ — `composer require dompdf/dompdf`).
 * Deux actions admin-post :
 *   - tcm_facture_pdf  : télécharge le PDF.
 *   - tcm_facture_mail : envoie le PDF par e-mail à l'adhérent (exp. contact@tcmimet.fr).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Facture {

	const EXPEDITEUR = 'Tennis Club de Mimet <contact@tcmimet.fr>';

	public function hooks(): void {
		add_action( 'admin_post_tcm_facture_pdf', array( $this, 'handle_pdf' ) );
		add_action( 'admin_post_tcm_facture_mail', array( $this, 'handle_mail' ) );
	}

	/* ---------------------------------------------------------------------
	 * URLs (utilisées par la fiche)
	 * ------------------------------------------------------------------- */

	public static function url_pdf( int $commande_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=tcm_facture_pdf&commande=' . $commande_id ),
			'tcm_facture_' . $commande_id
		);
	}

	public static function url_mail( int $commande_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=tcm_facture_mail&commande=' . $commande_id ),
			'tcm_facture_mail_' . $commande_id
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	public function handle_pdf(): void {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$cmd = (int) ( $_GET['commande'] ?? 0 );
		check_admin_referer( 'tcm_facture_' . $cmd );

		$pdf = $this->generate_pdf( $cmd );
		if ( null === $pdf ) {
			wp_die( 'Impossible de générer l’attestation (Dompdf absent ou commande invalide).' );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="attestation-' . $cmd . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- flux binaire PDF.
		exit;
	}

	public function handle_mail(): void {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$cmd = (int) ( $_GET['commande'] ?? 0 );
		check_admin_referer( 'tcm_facture_mail_' . $cmd );

		$redirect = wp_get_referer() ?: home_url();
		$data     = $this->commande_data( $cmd );

		if ( ! $data ) {
			wp_safe_redirect( add_query_arg( 'facture', 'error', $redirect ) );
			exit;
		}
		if ( '' === $data['email'] ) {
			wp_safe_redirect( add_query_arg( 'facture', 'noemail', $redirect ) );
			exit;
		}

		$pdf = $this->generate_pdf( $cmd );
		if ( null === $pdf ) {
			wp_safe_redirect( add_query_arg( 'facture', 'error', $redirect ) );
			exit;
		}

		// PDF en pièce jointe via un fichier temporaire dans les uploads.
		$upload = wp_upload_dir();
		$tmp    = trailingslashit( $upload['basedir'] ) . 'attestation-' . $cmd . '.pdf';
		file_put_contents( $tmp, $pdf ); // phpcs:ignore

		$subject = 'Attestation de paiement — Tennis Club de Mimet';
		$body    = "Bonjour,\n\nVeuillez trouver ci-joint votre attestation de paiement "
			. 'pour la saison ' . $data['saison'] . ".\n\nSportivement,\nTennis Club de Mimet";
		$headers = array( 'From: ' . self::EXPEDITEUR );

		$ok = wp_mail( $data['email'], $subject, $body, $headers, array( $tmp ) );
		@unlink( $tmp ); // phpcs:ignore

		if ( $ok ) {
			$adh = (int) get_field( 'adherent', $cmd );
			TCM_Log::add( 'send', 'commande', $adh ?: $cmd, $adh ? TCM_Log::person_label( $adh ) : '#' . $cmd, 'Attestation envoyée à ' . $data['email'] );
		}

		wp_safe_redirect( add_query_arg( 'facture', $ok ? 'sent' : 'error', $redirect ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Génération
	 * ------------------------------------------------------------------- */

	/** Charge Dompdf (vendor/) à la demande. */
	private function dompdf_available(): bool {
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return true;
		}
		$autoload = TCM_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
		return class_exists( '\Dompdf\Dompdf' );
	}

	/** Données de l'attestation à partir d'une commande, ou null si invalide. */
	private function commande_data( int $cmd_id ): ?array {
		if ( ! $cmd_id || get_post_type( $cmd_id ) !== TCM_CPT_COMMANDE ) {
			return null;
		}
		$adherent_id = (int) get_field( 'adherent', $cmd_id );
		$pid         = $adherent_id ? (int) get_field( 'personne', $adherent_id ) : 0;
		if ( ! $pid ) {
			return null;
		}
		return array(
			'num'     => $cmd_id,
			'nom'     => trim( (string) get_field( 'prenom', $pid ) . ' ' . (string) get_field( 'nom', $pid ) ),
			'montant' => (float) get_field( 'montant', $cmd_id ),
			'saison'  => (string) get_field( 'saison', $adherent_id ),
			'date'    => date_i18n( 'j F Y' ),
			'email'   => (string) get_field( 'email', $pid ),
		);
	}

	/** @return string|null Le PDF (octets) ou null si indisponible. */
	private function generate_pdf( int $cmd_id ): ?string {
		if ( ! $this->dompdf_available() ) {
			return null;
		}
		$data = $this->commande_data( $cmd_id );
		if ( ! $data ) {
			return null;
		}

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $this->render_html( $data ), 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	/** Image du plugin encodée en data URI (robuste vs chemins Dompdf). */
	private function img_data_uri( string $rel ): string {
		$path = TCM_PATH . $rel;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return 'data:image/png;base64,' . base64_encode( (string) file_get_contents( $path ) );
	}

	/** HTML de l'attestation (gabarit du club) avec les variables remplacées. */
	private function render_html( array $d ): string {
		$tpl = $this->template();
		return strtr(
			$tpl,
			array(
				'{{LOGO}}'      => $this->img_data_uri( 'assets/facture/logo.png' ),
				'{{SIGNATURE}}' => $this->img_data_uri( 'assets/facture/signature.png' ),
				'{{NUM}}'       => esc_html( (string) $d['num'] ),
				'{{NOM}}'       => esc_html( $d['nom'] ),
				'{{MONTANT}}'   => esc_html( number_format( $d['montant'], 2, ',', ' ' ) ),
				'{{SAISON}}'    => esc_html( (string) $d['saison'] ),
				'{{DATE}}'      => esc_html( $d['date'] ),
			)
		);
	}

	/** Gabarit HTML (attestation de paiement TC Mimet). */
	private function template(): string {
		return <<<'HTML'
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<style>
  @page { margin: 55px 60px; }
  body { font-family: "DejaVu Sans", Arial, sans-serif; color:#000; font-size:12pt; }
  .center { text-align:center; }
  .logo { width:120px; height:120px; }
  .title { font-size:16pt; font-weight:bold; margin:10px 0 2px; }
  .subtitle { font-size:16pt; color:#999999; font-weight:bold; margin-bottom:18px; }
  .num { font-size:12pt; font-weight:bold; margin:20px 0; }
  p { margin:0 0 12px; line-height:1.45; }
  .italic { font-style:italic; }
  .sign { width:205px; margin-top:8px; }
  .footer { text-align:center; font-size:9pt; margin-top:34px; }
</style></head>
<body>
  <div class="center"><img class="logo" src="{{LOGO}}" alt=""></div>
  <div class="center title">ATTESTATION DE PAIEMENT</div>
  <div class="center subtitle">Tennis Club de Mimet</div>

  <p class="num">N&deg;{{NUM}}</p>

  <p>Je soussigné Colombani Jean-Charles<br>Trésorier du Tennis Club de Mimet</p>

  <p>Certifie que {{NOM}} nous a versé(e) la somme de :<br>{{MONTANT}} &euro; qui correspond à l’acquittement de la licence et de l’adhésion au Tennis Club de Mimet pour la saison {{SAISON}}</p>

  <p>Mimet le {{DATE}}</p>

  <p>Sportivement</p>
  <p class="italic">Trésorier Tennis Club de Mimet</p>

  <div><img class="sign" src="{{SIGNATURE}}" alt=""></div>

  <div class="footer">TC MIMET ASSOCIATION LOI 1901 N&deg;SIREN 448 94 005</div>
</body></html>
HTML;
	}
}
