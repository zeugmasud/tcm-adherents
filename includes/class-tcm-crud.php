<?php
/**
 * CRUD inline des règlements et commandes, rendu dans la fiche adhérent
 * (onglets Règlements / Commandes). Remplace le parcours par pages séparées :
 * ajout sous la table, édition ligne par ligne, suppression avec confirmation.
 *
 * Handlers admin-post : tcm_reg_save / tcm_reg_delete / tcm_cmd_save / tcm_cmd_delete.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Crud {

	public function hooks(): void {
		add_action( 'admin_post_tcm_reg_save', array( $this, 'reg_save' ) );
		add_action( 'admin_post_tcm_reg_delete', array( $this, 'reg_delete' ) );
		add_action( 'admin_post_tcm_cmd_save', array( $this, 'cmd_save' ) );
		add_action( 'admin_post_tcm_cmd_delete', array( $this, 'cmd_delete' ) );
	}

	private function canaux(): array {
		return TCM_Dashboard::CANAUX;
	}
	private function statuts(): array {
		return array( 'valide' => 'Validé', 'en_attente' => 'En attente', 'rembourse' => 'Remboursé' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers (écriture)
	 * ------------------------------------------------------------------- */

	public function reg_save(): void {
		$this->guard( 'tcm_reg_save' );
		$adh = (int) ( $_POST['adherent'] ?? 0 );
		$rid = (int) ( $_POST['reg_id'] ?? 0 );
		if ( ! $adh || get_post_type( $adh ) !== TCM_CPT_ADHERENT ) {
			$this->back( 'reglements', 'error' );
		}
		$id = ( $rid && get_post_type( $rid ) === TCM_CPT_REGLEMENT )
			? $rid
			: wp_insert_post( array( 'post_type' => TCM_CPT_REGLEMENT, 'post_status' => 'publish', 'post_title' => 'Règlement' ) );

		update_field( 'adherent', $adh, $id );
		update_field( 'montant', $this->to_float( $_POST['montant'] ?? 0 ), $id );
		$canal = sanitize_text_field( wp_unslash( $_POST['canal'] ?? '' ) );
		if ( isset( $this->canaux()[ $canal ] ) ) {
			update_field( 'canal', $canal, $id );
		}
		$ymd = $this->input_to_ymd( $_POST['date'] ?? '' );
		if ( $ymd ) {
			update_field( 'date_reglement', $ymd, $id );
		}
		update_field( 'statut', sanitize_text_field( wp_unslash( $_POST['statut'] ?? 'valide' ) ), $id );

		$this->back( 'reglements', 'saved' );
	}

	public function reg_delete(): void {
		$this->guard( 'tcm_reg_delete' );
		$rid = (int) ( $_POST['reg_id'] ?? 0 );
		if ( $rid && get_post_type( $rid ) === TCM_CPT_REGLEMENT ) {
			wp_delete_post( $rid, true );
		}
		$this->back( 'reglements', 'deleted' );
	}

	public function cmd_save(): void {
		$this->guard( 'tcm_cmd_save' );
		$adh = (int) ( $_POST['adherent'] ?? 0 );
		$cid = (int) ( $_POST['cmd_id'] ?? 0 );
		if ( ! $adh || get_post_type( $adh ) !== TCM_CPT_ADHERENT ) {
			$this->back( 'commandes', 'error' );
		}
		$id = ( $cid && get_post_type( $cid ) === TCM_CPT_COMMANDE )
			? $cid
			: wp_insert_post( array( 'post_type' => TCM_CPT_COMMANDE, 'post_status' => 'publish', 'post_title' => 'Commande' ) );

		update_field( 'adherent', $adh, $id );
		update_field( 'libelle', sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) ), $id );
		update_field( 'montant', $this->to_float( $_POST['montant'] ?? 0 ), $id );
		update_field( 'saison', sanitize_text_field( wp_unslash( $_POST['saison'] ?? '' ) ), $id );

		$this->back( 'commandes', 'saved' );
	}

	public function cmd_delete(): void {
		$this->guard( 'tcm_cmd_delete' );
		$cid = (int) ( $_POST['cmd_id'] ?? 0 );
		if ( $cid && get_post_type( $cid ) === TCM_CPT_COMMANDE ) {
			wp_delete_post( $cid, true );
		}
		$this->back( 'commandes', 'deleted' );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'tcm_manage' ) || ! check_admin_referer( $action ) ) {
			wp_die( 'Accès refusé.' );
		}
	}

	private function back( string $tab, string $msg ): void {
		$url = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( ! $url ) {
			$url = wp_get_referer() ?: home_url();
		}
		wp_safe_redirect( add_query_arg( array( 'tab' => $tab, 'msg' => $msg ), $url ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Conversions
	 * ------------------------------------------------------------------- */

	private function to_float( $v ): float {
		return (float) str_replace( ',', '.', (string) $v );
	}
	private function fr_date( $ymd ): string {
		$ymd = preg_replace( '/\D/', '', (string) $ymd );
		return 8 === strlen( $ymd ) ? substr( $ymd, 6, 2 ) . '/' . substr( $ymd, 4, 2 ) . '/' . substr( $ymd, 0, 4 ) : (string) $ymd;
	}
	private function ymd_to_input( $ymd ): string {
		$ymd = preg_replace( '/\D/', '', (string) $ymd );
		return 8 === strlen( $ymd ) ? substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 ) : '';
	}
	private function input_to_ymd( $v ): string {
		$d = preg_replace( '/\D/', '', (string) $v );
		return 8 === strlen( $d ) ? $d : '';
	}

	/* ---------------------------------------------------------------------
	 * Rendu — section Règlements
	 * ------------------------------------------------------------------- */

	public function reglements_section( int $adh, array $regs, string $fiche_url, int $edit_id ): string {
		ob_start();
		$total = 0.0;
		echo '<div class="tcm-fiche-section"><h3>Règlements</h3>';
		echo '<div class="tcm-rows">';

		foreach ( $regs as $r ) {
			$m      = (float) get_field( 'montant', $r->ID );
			$total += $m;
			if ( $edit_id === $r->ID ) {
				echo '<div class="tcm-row tcm-row--edit">';
				echo $this->reg_form( $adh, $fiche_url, $r->ID, array(
					'date'    => get_field( 'date_reglement', $r->ID ),
					'canal'   => get_field( 'canal', $r->ID ),
					'montant' => $m,
					'statut'  => get_field( 'statut', $r->ID ),
				), 'Enregistrer' );
				echo '</div>';
			} else {
				$canal    = $this->canaux()[ get_field( 'canal', $r->ID ) ] ?? get_field( 'canal', $r->ID );
				$statut_k = get_field( 'statut', $r->ID );
				$statut   = $this->statuts()[ $statut_k ] ?? $statut_k;
				$edit     = esc_url( add_query_arg( array( 'tab' => 'reglements', 'edit_reg' => $r->ID ), $fiche_url ) );
				echo '<div class="tcm-row">';
				echo '<div class="tcm-row-main">';
				echo '<span class="tcm-row-date">' . esc_html( $this->fr_date( get_field( 'date_reglement', $r->ID ) ) ) . '</span>';
				echo '<span class="tcm-row-canal">' . esc_html( $canal ) . '</span>';
				echo '<span class="tcm-row-montant">' . esc_html( number_format( $m, 2, ',', ' ' ) ) . ' €</span>';
				echo '<span class="tcm-chip tcm-chip-' . esc_attr( $statut_k ) . '">' . esc_html( $statut ) . '</span>';
				echo '</div>';
				echo '<div class="tcm-row-actions"><a class="button button-small" href="' . $edit . '">Éditer</a>'
					. $this->delete_form( 'tcm_reg_delete', 'reg_id', $r->ID, $adh, $fiche_url, 'Supprimer ce règlement ?' ) . '</div>';
				echo '</div>';
			}
		}
		if ( $regs ) {
			echo '<div class="tcm-row tcm-row--total">Total payé <strong>' . esc_html( number_format( $total, 2, ',', ' ' ) ) . ' €</strong></div>';
		}
		echo '</div>';

		// Formulaire d'ajout (masqué si on est déjà en édition d'une ligne).
		if ( ! $edit_id ) {
			echo '<div class="tcm-add-block"><h4>Ajouter un règlement</h4>';
			echo $this->reg_form( $adh, $fiche_url, 0, array( 'date' => current_time( 'Ymd' ), 'canal' => 'cheque', 'montant' => '', 'statut' => 'valide' ), 'Ajouter' );
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	private function reg_form( int $adh, string $redirect, int $reg_id, array $v, string $label ): string {
		ob_start();
		echo '<form class="tcm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_reg_save' );
		echo '<input type="hidden" name="action" value="tcm_reg_save">';
		echo '<input type="hidden" name="adherent" value="' . (int) $adh . '">';
		echo '<input type="hidden" name="reg_id" value="' . (int) $reg_id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $redirect ) . '">';
		echo '<label>Date<input type="date" name="date" value="' . esc_attr( $this->ymd_to_input( $v['date'] ) ) . '"></label>';
		echo '<label>Canal<select name="canal">';
		foreach ( $this->canaux() as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $v['canal'], $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Montant (€)<input type="number" step="0.01" name="montant" value="' . esc_attr( $v['montant'] ) . '"></label>';
		echo '<label>Statut<select name="statut">';
		foreach ( $this->statuts() as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $v['statut'], $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		echo '<div class="tcm-inline-actions"><button type="submit" class="button button-primary button-small">' . esc_html( $label ) . '</button>';
		if ( $reg_id ) {
			echo ' <a class="button button-small" href="' . esc_url( add_query_arg( 'tab', 'reglements', $redirect ) ) . '">Annuler</a>';
		}
		echo '</div></form>';
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Rendu — section Commandes (avec boutons attestation)
	 * ------------------------------------------------------------------- */

	public function commandes_section( int $adh, array $cmds, string $fiche_url, int $edit_id, string $saison_defaut ): string {
		ob_start();
		echo '<div class="tcm-fiche-section"><h3>Commandes'
			. ' <button type="button" class="tcm-calc-open" title="Calculette" aria-label="Ouvrir la calculette">'
			. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="8" y2="10"/><line x1="12" y1="10" x2="12" y2="10"/><line x1="16" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="18"/><line x1="8" y1="18" x2="12" y2="18"/></svg>'
			. '</button></h3>';
		echo '<div class="tcm-rows">';

		foreach ( $cmds as $c ) {
			if ( $edit_id === $c->ID ) {
				echo '<div class="tcm-row tcm-row--edit">';
				echo $this->cmd_form( $adh, $fiche_url, $c->ID, array(
					'libelle' => get_field( 'libelle', $c->ID ),
					'montant' => (float) get_field( 'montant', $c->ID ),
					'saison'  => get_field( 'saison', $c->ID ),
				), 'Enregistrer' );
				echo '</div>';
			} else {
				$edit = esc_url( add_query_arg( array( 'tab' => 'commandes', 'edit_cmd' => $c->ID ), $fiche_url ) );
				echo '<div class="tcm-row">';
				echo '<div class="tcm-row-main">';
				echo '<span class="tcm-row-libelle">' . esc_html( get_field( 'libelle', $c->ID ) ) . '</span>';
				echo '<span class="tcm-row-montant">' . esc_html( number_format( (float) get_field( 'montant', $c->ID ), 2, ',', ' ' ) ) . ' €</span>';
				echo '</div>';
				echo '<div class="tcm-row-actions">';
				echo '<a class="button button-small" href="' . esc_url( TCM_Facture::url_pdf( $c->ID ) ) . '" target="_blank" rel="noopener">PDF</a> ';
				echo '<a class="button button-small" href="' . esc_url( TCM_Facture::url_mail( $c->ID ) ) . '" onclick="return confirm(\'Envoyer cette attestation par e-mail ?\');">Envoyer</a> ';
				echo '<a class="button button-small" href="' . $edit . '">Éditer</a>';
				echo $this->delete_form( 'tcm_cmd_delete', 'cmd_id', $c->ID, $adh, $fiche_url, 'Supprimer cette commande ?' );
				echo '</div>';
				echo '</div>';
			}
		}
		echo '</div>';

		if ( ! $edit_id ) {
			echo '<div class="tcm-add-block"><h4>Ajouter une commande</h4>';
			echo $this->cmd_form( $adh, $fiche_url, 0, array( 'libelle' => '', 'montant' => '', 'saison' => $saison_defaut ), 'Ajouter' );
			echo '</div>';
		}
		echo '</div>';

		// Calculette (aide au calcul), ouverte par le picto de l'en-tête.
		echo $this->calc_modal();

		echo '</div>';
		return (string) ob_get_clean();
	}

	/** Modale de calculette (rendue une fois dans la section Commandes). */
	private function calc_modal(): string {
		$keys = array(
			array( 'C', 'C', 'fn' ), array( '⌫', 'back', 'fn' ), array( '%', '%', 'op' ), array( '÷', '/', 'op' ),
			array( '7', '7', '' ), array( '8', '8', '' ), array( '9', '9', '' ), array( '×', '*', 'op' ),
			array( '4', '4', '' ), array( '5', '5', '' ), array( '6', '6', '' ), array( '−', '-', 'op' ),
			array( '1', '1', '' ), array( '2', '2', '' ), array( '3', '3', '' ), array( '+', '+', 'op' ),
			array( '0', '0', 'span2' ), array( ',', '.', '' ), array( '=', '=', 'eq' ),
		);
		ob_start();
		echo '<div class="tcm-calc-backdrop" id="tcm-calc" aria-hidden="true">';
		echo '<div class="tcm-calc" role="dialog" aria-label="Calculette">';
		echo '<div class="tcm-calc-head"><strong>Calculette</strong><button type="button" class="tcm-calc-close" aria-label="Fermer">&times;</button></div>';
		echo '<div class="tcm-calc-screen"><div class="tcm-calc-expr"></div><div class="tcm-calc-val">0</div></div>';
		echo '<div class="tcm-calc-keys">';
		foreach ( $keys as $k ) {
			echo '<button type="button" class="' . esc_attr( $k[2] ) . '" data-k="' . esc_attr( $k[1] ) . '">' . esc_html( $k[0] ) . '</button>';
		}
		echo '</div></div></div>';
		return (string) ob_get_clean();
	}

	private function cmd_form( int $adh, string $redirect, int $cmd_id, array $v, string $label ): string {
		ob_start();
		echo '<form class="tcm-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tcm_cmd_save' );
		echo '<input type="hidden" name="action" value="tcm_cmd_save">';
		echo '<input type="hidden" name="adherent" value="' . (int) $adh . '">';
		echo '<input type="hidden" name="cmd_id" value="' . (int) $cmd_id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $redirect ) . '">';
		echo '<label>Libellé<input type="text" name="libelle" value="' . esc_attr( $v['libelle'] ) . '"></label>';
		echo '<label>Montant (€)<input type="number" step="0.01" name="montant" value="' . esc_attr( $v['montant'] ) . '"></label>';
		echo '<label>Saison<input type="text" name="saison" value="' . esc_attr( $v['saison'] ) . '"></label>';
		echo '<div class="tcm-inline-actions"><button type="submit" class="button button-primary button-small">' . esc_html( $label ) . '</button>';
		if ( $cmd_id ) {
			echo ' <a class="button button-small" href="' . esc_url( add_query_arg( 'tab', 'commandes', $redirect ) ) . '">Annuler</a>';
		}
		echo '</div></form>';
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Bouton de suppression (mini-formulaire POST)
	 * ------------------------------------------------------------------- */

	private function delete_form( string $action, string $idname, int $post_id, int $adh, string $redirect, string $confirm ): string {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tcm-del-form" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $idname ) . '" value="' . (int) $post_id . '">';
		echo '<input type="hidden" name="adherent" value="' . (int) $adh . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $redirect ) . '">';
		echo '<button type="submit" class="button button-small tcm-danger">Supprimer</button>';
		echo '</form>';
		return (string) ob_get_clean();
	}
}
