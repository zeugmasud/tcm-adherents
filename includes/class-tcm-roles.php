<?php
/**
 * Rôles et capacités.
 *
 * - Capacité 'tcm_manage' ajoutée à l'administrateur pour piloter le back-office.
 * - Rôle 'tcm_adherent' pour le portail (accès en lecture/édition de son dossier).
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Roles {

	public static function add_roles_and_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'tcm_manage' );
		}

		add_role(
			'tcm_adherent',
			__( 'Adhérent TC Mimet', 'tcm-adherents' ),
			array(
				'read' => true,
			)
		);
	}

	public static function remove_roles_and_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'tcm_manage' );
		}
		remove_role( 'tcm_adherent' );
	}
}
