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
