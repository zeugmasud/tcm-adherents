<?php
/**
 * Journal d'audit : trace « qui du bureau a fait quoi ».
 *
 * - Table dédiée wp_tcm_log (créée / mise à jour via dbDelta).
 * - Logger statique TCM_Log::add() appelé par les handlers d'écriture.
 * - Capture automatique des éditions de fiches via le hook acf/save_post.
 * - Écran admin « Journal » (sous-menu TC Mimet) : tableau paginé, filtres par
 *   membre du bureau, action, type d'objet, recherche et plage de dates.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Log {

	/** Version du schéma : incrémenter pour déclencher un dbDelta. */
	const DB_VERSION = '1';
	const OPT_DB     = 'tcm_log_db_version';

	/** Libellés lisibles des actions. */
	const ACTIONS = array(
		'create'    => 'Création',
		'update'    => 'Modification',
		'delete'    => 'Suppression',
		'toggle'    => 'Bascule',
		'send'      => 'Envoi',
		'merge'     => 'Fusion doublons',
		'import'    => 'Import',
		'normalize' => 'Normalisation',
	);

	/** Libellés lisibles des types d'objet. */
	const TYPES = array(
		'personne'    => 'Personne',
		'adherent'    => 'Adhérent',
		'reglement'   => 'Règlement',
		'commande'    => 'Commande',
		'creneau'     => 'Créneau',
		'inscription' => 'Inscription',
		'maintenance' => 'Maintenance',
	);

	/* =====================================================================
	 * Amorçage
	 * =================================================================== */

	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_install' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		// Capture auto des éditions de fiches (acf_form) — priorité tardive.
		add_action( 'acf/save_post', array( $this, 'on_acf_save' ), 20 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tcm_log';
	}

	/** Crée / met à jour la table si nécessaire. */
	public function maybe_install(): void {
		if ( get_option( self::OPT_DB ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_name VARCHAR(191) NOT NULL DEFAULT '',
			action VARCHAR(40) NOT NULL DEFAULT '',
			object_type VARCHAR(40) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			object_label VARCHAR(191) NOT NULL DEFAULT '',
			details TEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY object_type (object_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPT_DB, self::DB_VERSION );
	}

	/* =====================================================================
	 * Logger
	 * =================================================================== */

	/**
	 * Enregistre une entrée de journal.
	 *
	 * @param string $action      Clé d'action (create/update/delete/toggle/send/merge/import/normalize).
	 * @param string $object_type Clé de type (personne/adherent/reglement/…).
	 * @param int    $object_id   ID de l'objet concerné (0 si global).
	 * @param string $label       Libellé lisible (ex. nom de l'adhérent).
	 * @param string $details     Détail complémentaire (ex. « 25 € · chèque »).
	 */
	public static function add( string $action, string $object_type, int $object_id = 0, string $label = '', string $details = '' ): void {
		global $wpdb;

		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		if ( $user && $user->exists() ) {
			$uid   = (int) $user->ID;
			$uname = $user->display_name ?: $user->user_login;
		} else {
			$uid   = 0;
			$uname = 'Formulaire public';
		}

		$wpdb->insert(
			self::table(),
			array(
				'created_at'   => current_time( 'mysql' ),
				'user_id'      => $uid,
				'user_name'    => mb_substr( (string) $uname, 0, 191 ),
				'action'       => mb_substr( $action, 0, 40 ),
				'object_type'  => mb_substr( $object_type, 0, 40 ),
				'object_id'    => $object_id,
				'object_label' => mb_substr( $label, 0, 191 ),
				'details'      => $details,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/** Nom lisible d'un adhérent ou d'une personne (via la personne rattachée). */
	public static function person_label( int $post_id ): string {
		$type = get_post_type( $post_id );
		if ( TCM_CPT_ADHERENT === $type ) {
			$pid = (int) get_field( 'personne', $post_id );
		} else {
			$pid = $post_id;
		}
		if ( ! $pid ) {
			return '#' . $post_id;
		}
		$nom = trim( (string) get_field( 'nom', $pid ) . ' ' . (string) get_field( 'prenom', $pid ) );
		return $nom !== '' ? $nom : '#' . $post_id;
	}

	/* =====================================================================
	 * Capture automatique des éditions de fiches (acf_form)
	 * =================================================================== */

	public function on_acf_save( $post_id ): void {
		if ( ! is_numeric( $post_id ) ) {
			return; // options page, user, etc.
		}
		$post_id = (int) $post_id;
		$type    = get_post_type( $post_id );
		if ( TCM_CPT_ADHERENT !== $type && TCM_CPT_PERSONNE !== $type ) {
			return;
		}
		// Ne pas journaliser les imports en masse (ils n'utilisent pas acf_form,
		// mais on garde un garde-fou via une constante posée par les importeurs).
		if ( defined( 'TCM_BULK_IMPORT' ) && TCM_BULK_IMPORT ) {
			return;
		}
		$key = TCM_CPT_ADHERENT === $type ? 'adherent' : 'personne';
		self::add( 'update', $key, $post_id, self::person_label( $post_id ), 'Édition de fiche' );
	}

	/* =====================================================================
	 * Écran admin « Journal »
	 * =================================================================== */

	public function menu(): void {
		add_submenu_page(
			'tcm-adherents',
			__( 'Journal', 'tcm-adherents' ),
			__( 'Journal', 'tcm-adherents' ),
			'tcm_manage',
			'tcm-journal',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		global $wpdb;
		$table = self::table();

		// Filtres.
		$f_user   = isset( $_GET['u'] ) ? (int) $_GET['u'] : 0;
		$f_action = isset( $_GET['a'] ) ? sanitize_key( wp_unslash( $_GET['a'] ) ) : '';
		$f_type   = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : '';
		$f_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$f_from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$f_to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		// Construction de la clause WHERE.
		$where = array( '1=1' );
		$args  = array();
		if ( $f_user ) {
			$where[] = 'user_id = %d';
			$args[]  = $f_user;
		}
		if ( $f_action && isset( self::ACTIONS[ $f_action ] ) ) {
			$where[] = 'action = %s';
			$args[]  = $f_action;
		}
		if ( $f_type && isset( self::TYPES[ $f_type ] ) ) {
			$where[] = 'object_type = %s';
			$args[]  = $f_type;
		}
		if ( '' !== $f_search ) {
			$like    = '%' . $wpdb->esc_like( $f_search ) . '%';
			$where[] = '(object_label LIKE %s OR details LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f_from ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $f_from . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f_to ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $f_to . ' 23:59:59';
		}
		$where_sql = implode( ' AND ', $where );

		// Table absente (déploiement avant maybe_install) : message doux.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
			$this->maybe_install();
		}

		// Comptage total.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		// Lignes de la page.
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows_args = array_merge( $args, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_args ) ); // phpcs:ignore

		// Liste des utilisateurs présents dans le journal (pour le filtre).
		$users = $wpdb->get_results( "SELECT DISTINCT user_id, user_name FROM {$table} ORDER BY user_name ASC" ); // phpcs:ignore

		echo '<div class="wrap"><h1>' . esc_html__( 'Journal d’activité', 'tcm-adherents' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Historique des actions du bureau : créations, modifications, suppressions et envois.', 'tcm-adherents' ) . '</p>';

		// Barre de filtres.
		echo '<form method="get" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:end;">';
		echo '<input type="hidden" name="page" value="tcm-journal">';

		echo '<label>Membre<br><select name="u"><option value="0">Tous</option>';
		foreach ( $users as $u ) {
			$name = '' !== $u->user_name ? $u->user_name : ( $u->user_id ? '#' . $u->user_id : 'Formulaire public' );
			echo '<option value="' . (int) $u->user_id . '" ' . selected( $f_user, (int) $u->user_id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>Action<br><select name="a"><option value="">Toutes</option>';
		foreach ( self::ACTIONS as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f_action, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>Type<br><select name="t"><option value="">Tous</option>';
		foreach ( self::TYPES as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f_type, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>Du<br><input type="date" name="from" value="' . esc_attr( $f_from ) . '"></label>';
		echo '<label>Au<br><input type="date" name="to" value="' . esc_attr( $f_to ) . '"></label>';
		echo '<label>Recherche<br><input type="search" name="s" value="' . esc_attr( $f_search ) . '" placeholder="Nom, détail…"></label>';

		submit_button( __( 'Filtrer', 'tcm-adherents' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=tcm-journal' ) ) . '">Réinitialiser</a>';
		echo '</form>';

		echo '<p>' . esc_html( sprintf( _n( '%s entrée', '%s entrées', $total, 'tcm-adherents' ), number_format_i18n( $total ) ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:150px">Date</th><th style="width:160px">Membre</th><th style="width:120px">Action</th><th>Objet</th><th>Détail</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Aucune entrée pour ces critères.', 'tcm-adherents' ) . '</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				$act  = self::ACTIONS[ $r->action ] ?? $r->action;
				$typ  = self::TYPES[ $r->object_type ] ?? $r->object_type;
				$when = date_i18n( 'j M Y, H:i', strtotime( $r->created_at ) );
				$who  = '' !== $r->user_name ? $r->user_name : ( $r->user_id ? '#' . $r->user_id : 'Formulaire public' );

				// Lien vers la fiche quand c'est un objet rattaché à un adhérent.
				$label = $r->object_label;
				$obj   = esc_html( $typ ) . ' — ' . esc_html( $label );

				echo '<tr>';
				echo '<td>' . esc_html( $when ) . '</td>';
				echo '<td>' . esc_html( $who ) . '</td>';
				echo '<td><span class="tcm-log-act tcm-log-act-' . esc_attr( $r->action ) . '">' . esc_html( $act ) . '</span></td>';
				echo '<td>' . $obj . '</td>'; // phpcs:ignore — déjà échappé.
				echo '<td>' . esc_html( (string) $r->details ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		// Pagination.
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			$base = add_query_arg( array( 'u' => $f_user ?: null, 'a' => $f_action ?: null, 't' => $f_type ?: null, 's' => $f_search ?: null, 'from' => $f_from ?: null, 'to' => $f_to ?: null ), admin_url( 'admin.php?page=tcm-journal' ) );
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( array( // phpcs:ignore — sortie WP sûre.
				'base'      => add_query_arg( 'paged', '%#%', $base ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			) );
			echo '</div></div>';
		}

		echo '<style>
			.tcm-log-act{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;}
			.tcm-log-act-create{background:#e3f6ec;color:#157a48;}
			.tcm-log-act-update{background:#e7effe;color:#1f5fd0;}
			.tcm-log-act-delete{background:#fde2e1;color:#b42318;}
			.tcm-log-act-toggle{background:#efe7fe;color:#6b3fd0;}
			.tcm-log-act-send{background:#e6f6f6;color:#0f766e;}
			.tcm-log-act-merge,.tcm-log-act-import,.tcm-log-act-normalize{background:#fff3d6;color:#9a6700;}
		</style>';
		echo '</div>';
	}
}
