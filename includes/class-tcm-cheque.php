<?php
/**
 * Photos de chèques — stockage PROTÉGÉ (données bancaires).
 *
 * - Fichiers stockés hors médiathèque, dans uploads/tcm-cheques/, avec un nom
 *   non devinable et un .htaccess qui refuse l'accès direct.
 * - Jamais d'URL publique : la visualisation passe par un point d'accès
 *   (admin-post.php?action=tcm_cheque) réservé aux membres connectés disposant
 *   de la capacité tcm_manage.
 * - Rattachement au règlement via deux métas : _tcm_cheque_file / _tcm_cheque_mime.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Cheque {

	const META_FILE = '_tcm_cheque_file';
	const META_MIME = '_tcm_cheque_mime';

	/** Types image acceptés. */
	const MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' );

	public function hooks(): void {
		// Point d'accès protégé (connecté uniquement — pas de variante nopriv).
		add_action( 'admin_post_tcm_cheque', array( $this, 'serve' ) );
		// Nettoyage du fichier quand un règlement est supprimé (toutes voies).
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
	}

	/* =====================================================================
	 * Emplacement protégé
	 * =================================================================== */

	/** Chemin du dossier privé (le crée + pose les protections au besoin). */
	public static function dir_path(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'tcm-cheques';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		$idx = $dir . '/index.html';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, '' ); // phpcs:ignore
		}
		return $dir;
	}

	/* =====================================================================
	 * Écriture
	 * =================================================================== */

	/** Enregistre le fichier envoyé (champ $_FILES[$field]) pour un règlement. */
	public static function store_from_upload( int $reg_id, string $field ): bool {
		if ( empty( $_FILES[ $field ]['name'] ) || ! empty( $_FILES[ $field ]['error'] ) ) {
			return false;
		}
		$tmp = $_FILES[ $field ]['tmp_name'] ?? '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return false;
		}

		// Validation : vrai fichier image.
		$check = wp_check_filetype_and_ext( $tmp, sanitize_file_name( (string) $_FILES[ $field ]['name'] ) );
		$mime  = $check['type'] ?: '';
		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $tmp );
		}
		if ( ! in_array( $mime, self::MIMES, true ) && ! @getimagesize( $tmp ) ) { // phpcs:ignore
			return false;
		}
		if ( '' === $mime ) {
			$mime = 'image/jpeg';
		}

		$dir  = self::dir_path();
		$ext  = self::ext_for( $mime, (string) $_FILES[ $field ]['name'] );
		$name = $reg_id . '-' . wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest = trailingslashit( $dir ) . $name;

		if ( ! @move_uploaded_file( $tmp, $dest ) ) { // phpcs:ignore
			return false;
		}
		self::resize( $dest );
		@chmod( $dest, 0640 ); // phpcs:ignore

		// Remplace un éventuel fichier précédent.
		self::delete( $reg_id );
		update_post_meta( $reg_id, self::META_FILE, $name );
		update_post_meta( $reg_id, self::META_MIME, $mime );
		return true;
	}

	/**
	 * Importe un fichier déjà présent sur le serveur (migration AppSheet).
	 *
	 * @param int    $reg_id   Règlement cible.
	 * @param string $src_path Chemin absolu du fichier source (copié, non déplacé).
	 */
	public static function import_file( int $reg_id, string $src_path ): bool {
		if ( ! is_readable( $src_path ) ) {
			return false;
		}
		$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $src_path ) : '';
		if ( ! in_array( $mime, self::MIMES, true ) && ! @getimagesize( $src_path ) ) { // phpcs:ignore
			return false;
		}
		if ( '' === $mime ) {
			$mime = 'image/jpeg';
		}
		$dir  = self::dir_path();
		$ext  = self::ext_for( $mime, $src_path );
		$name = $reg_id . '-' . wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest = trailingslashit( $dir ) . $name;
		if ( ! @copy( $src_path, $dest ) ) { // phpcs:ignore
			return false;
		}
		self::resize( $dest );
		@chmod( $dest, 0640 ); // phpcs:ignore
		self::delete( $reg_id );
		update_post_meta( $reg_id, self::META_FILE, $name );
		update_post_meta( $reg_id, self::META_MIME, $mime );
		return true;
	}

	/** Supprime le fichier + les métas d'un règlement. */
	public static function delete( int $reg_id ): void {
		$name = (string) get_post_meta( $reg_id, self::META_FILE, true );
		if ( '' !== $name ) {
			$f = self::dir_path() . '/' . basename( $name );
			if ( file_exists( $f ) ) {
				@unlink( $f ); // phpcs:ignore
			}
		}
		delete_post_meta( $reg_id, self::META_FILE );
		delete_post_meta( $reg_id, self::META_MIME );
	}

	/* =====================================================================
	 * Lecture / affichage
	 * =================================================================== */

	public static function has( int $reg_id ): bool {
		$name = (string) get_post_meta( $reg_id, self::META_FILE, true );
		return '' !== $name && file_exists( self::dir_path() . '/' . basename( $name ) );
	}

	/** URL du point d'accès protégé (avec nonce lié au règlement). */
	public static function view_url( int $reg_id ): string {
		return add_query_arg(
			array(
				'action'   => 'tcm_cheque',
				'reg'      => $reg_id,
				'_wpnonce' => wp_create_nonce( 'tcm_cheque_' . $reg_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Sert le fichier — connecté + capacité + nonce obligatoires. */
	public function serve(): void {
		$reg = isset( $_GET['reg'] ) ? (int) $_GET['reg'] : 0;
		if ( ! current_user_can( 'tcm_manage' ) ) {
			status_header( 403 );
			exit;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tcm_cheque_' . $reg ) ) {
			status_header( 403 );
			exit;
		}
		$name = (string) get_post_meta( $reg, self::META_FILE, true );
		if ( '' === $name ) {
			status_header( 404 );
			exit;
		}
		$file = self::dir_path() . '/' . basename( $name );
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}
		$mime = (string) get_post_meta( $reg, self::META_MIME, true ) ?: 'application/octet-stream';
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="cheque-' . $reg . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	public function on_delete_post( $post_id ): void {
		if ( get_post_type( (int) $post_id ) === TCM_CPT_REGLEMENT ) {
			self::delete( (int) $post_id );
		}
	}

	/* =====================================================================
	 * Utilitaires
	 * =================================================================== */

	/** Redimensionne l'image pour tenir dans 600×800 (sans recadrage ni agrandissement). */
	private static function resize( string $path ): void {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return; // format non géré (ex. HEIC) : on conserve l'original.
		}
		$size = $editor->get_size();
		if ( is_array( $size ) && ( (int) $size['width'] <= 600 && (int) $size['height'] <= 800 ) ) {
			return; // déjà dans la boîte.
		}
		$editor->resize( 600, 800, false );
		$editor->save( $path );
	}

	private static function ext_for( string $mime, string $fallback_name ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/heic' => 'heic',
			'image/heif' => 'heic',
		);
		if ( isset( $map[ $mime ] ) ) {
			return $map[ $mime ];
		}
		$ext = strtolower( (string) pathinfo( $fallback_name, PATHINFO_EXTENSION ) );
		return preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ? $ext : 'jpg';
	}
}
