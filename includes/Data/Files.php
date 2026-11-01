<?php
/**
 * Private file storage for resumes and application attachments.
 *
 * Files live in uploads/dev3jb-private/<random dir>/<random name>.<ext>, the folder denies web access
 * (.htaccess, web.config, index.php), names are unguessable, and the only way to read a file is the
 * nonce-checked admin-post download with an ownership check (owner, the employer of an application
 * that uses it, or a manager).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static storage.
 */
class Files {

	const DIR = 'dev3jb-private';

	/**
	 * Allowed extensions => MIME.
	 *
	 * @var array
	 */
	const TYPES = array(
		'pdf'  => 'application/pdf',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'txt'  => 'text/plain',
	);

	/**
	 * Hooks (download handler).
	 */
	public static function init() {
		add_action( 'admin_post_dev3jb_file', array( __CLASS__, 'download' ) );
	}

	/**
	 * Base directory (created and protected on demand).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$upload = wp_upload_dir( null, false );
		return trailingslashit( $upload['basedir'] ) . self::DIR;
	}

	/**
	 * Creates the private folder with deny rules.
	 *
	 * @return bool
	 */
	public static function protect_dir() {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess'  => "# DEV3 AI Job Board: private files.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return true;
	}

	/**
	 * Detects an allowed type from name + content.
	 *
	 * @param string $path Temp path.
	 * @param string $name Original name.
	 * @return array|\WP_Error { ext, mime }
	 */
	public static function detect( $path, $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return new \WP_Error( 'dev3jb_file_type', __( 'Upload a PDF, Word (.docx) or text file.', 'dev3-ai-job-board' ), array( 'status' => 415 ) );
		}
		$head = (string) file_get_contents( $path, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ok   = true;
		if ( 'pdf' === $ext ) {
			$ok = 0 === strpos( $head, '%PDF' );
		} elseif ( 'docx' === $ext ) {
			$ok = 0 === strpos( $head, "PK\x03\x04" );
		} else {
			$sample = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$ok     = false === strpos( $sample, "\0" );
		}
		if ( ! $ok ) {
			return new \WP_Error( 'dev3jb_file_type', __( 'The file content does not match its type.', 'dev3-ai-job-board' ), array( 'status' => 415 ) );
		}
		return array(
			'ext'  => $ext,
			'mime' => self::TYPES[ $ext ],
		);
	}

	/**
	 * Stores a file privately.
	 *
	 * @param string $tmp     Source path (already validated as an upload by the caller).
	 * @param string $name    Original file name.
	 * @param int    $user_id Owner (0 for guest applications).
	 * @param string $kind    resume|attachment.
	 * @return array|\WP_Error File row.
	 */
	public static function store( $tmp, $name, $user_id, $kind = 'resume' ) {
		if ( ! is_readable( $tmp ) ) {
			return new \WP_Error( 'dev3jb_file_read', __( 'The upload failed. Try again.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > Settings::max_upload() ) {
			/* translators: %d: megabytes */
			return new \WP_Error( 'dev3jb_file_size', sprintf( __( 'The file must be smaller than %d MB.', 'dev3-ai-job-board' ), (int) Settings::get( 'max_upload_mb', 5 ) ), array( 'status' => 413 ) );
		}
		$type = self::detect( $tmp, $name );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		self::protect_dir();
		$sub = gmdate( 'Y' ) . '-' . wp_generate_password( 12, false, false );
		$dir = self::base_dir() . '/' . $sub;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'dev3jb_file_dir', __( 'Could not save the file.', 'dev3-ai-job-board' ), array( 'status' => 500 ) );
		}
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = strtolower( wp_generate_password( 24, false, false ) ) . '.' . $type['ext'];
		if ( ! copy( $tmp, $dir . '/' . $file ) ) {
			return new \WP_Error( 'dev3jb_file_dir', __( 'Could not save the file.', 'dev3-ai-job-board' ), array( 'status' => 500 ) );
		}
		wp_delete_file( $tmp );
		global $wpdb;
		$clean = sanitize_file_name( wp_basename( (string) $name ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'files' ),
			array(
				'user_id'    => (int) $user_id,
				'kind'       => 'attachment' === $kind ? 'attachment' : 'resume',
				'path'       => $sub . '/' . $file,
				'name'       => mb_substr( '' === $clean ? 'resume.' . $type['ext'] : $clean, 0, 190 ),
				'mime'       => $type['mime'],
				'size'       => $size,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * File row.
	 *
	 * @param int $id File.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'files' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['size']    = (int) $row['size'];
		return $row;
	}

	/**
	 * Absolute path of a stored file ('' if missing).
	 *
	 * @param array $file File row.
	 * @return string
	 */
	public static function path( array $file ) {
		$rel = (string) $file['path'];
		if ( false !== strpos( $rel, '..' ) ) {
			return '';
		}
		$path = self::base_dir() . '/' . $rel;
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Browser-safe info (no path) with a nonce download link for the current user.
	 *
	 * @param int $id File.
	 * @return array|null
	 */
	public static function public_info( $id ) {
		$file = self::get( $id );
		if ( ! $file ) {
			return null;
		}
		return array(
			'id'      => $file['id'],
			'name'    => $file['name'],
			'size'    => $file['size'],
			'mime'    => $file['mime'],
			'created' => $file['created_at'],
			'url'     => self::download_url( $file['id'] ),
		);
	}

	/**
	 * Download link (nonce bound to the current user).
	 *
	 * @param int $id File.
	 * @return string
	 */
	public static function download_url( $id ) {
		return add_query_arg(
			array(
				'action'   => 'dev3jb_file',
				'id'       => (int) $id,
				'_wpnonce' => wp_create_nonce( 'dev3jb_file_' . (int) $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Whether a user may read a file.
	 *
	 * @param array $file    File row.
	 * @param int   $user_id User.
	 * @return bool
	 */
	public static function can_access( array $file, $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( (int) $file['user_id'] === (int) $user_id && $file['user_id'] ) {
			return true;
		}
		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return true;
		}
		global $wpdb;
		$apps = Tables::name( 'applications' );
		// Employer of an application that carries this file (directly or through the candidate's profile file).
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$apps} WHERE file_id = %d AND employer_id = %d AND stage <> 'withdrawn'", $file['id'], $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * Filters file access.
		 *
		 * @param bool  $allowed Allowed.
		 * @param array $file    File.
		 * @param int   $user_id User.
		 */
		return (bool) apply_filters( 'dev3jb_file_access', $found > 0, $file, $user_id );
	}

	/**
	 * Streams a file (admin-post.php?action=dev3jb_file&id=…&_wpnonce=…).
	 */
	public static function download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dev3jb_file_' . $id ) ) {
			wp_die( esc_html__( 'This download link has expired. Reload the page and try again.', 'dev3-ai-job-board' ), '', array( 'response' => 403 ) );
		}
		$file = self::get( $id );
		if ( ! $file || ! self::can_access( $file, get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot open this file.', 'dev3-ai-job-board' ), '', array( 'response' => 403 ) );
		}
		$path = self::path( $file );
		if ( '' === $path ) {
			wp_die( esc_html__( 'The file is missing.', 'dev3-ai-job-board' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( 'application/pdf' === $file['mime'] ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( $file['name'] ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Deletes a file (row + disk).
	 *
	 * @param int $id File.
	 */
	public static function delete( $id ) {
		$file = self::get( $id );
		if ( ! $file ) {
			return;
		}
		$path = self::path( $file );
		if ( $path ) {
			wp_delete_file( $path );
		}
		global $wpdb;
		$wpdb->delete( Tables::name( 'files' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Files of a user.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = Tables::name( 'files' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, kind, size, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
