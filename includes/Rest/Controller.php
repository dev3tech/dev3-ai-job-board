<?php
/**
 * Shared REST helpers: logged-in permission, uploads, light shapes.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Rest;

use Dev3\AiJobBoard\Core3\Rest\Base_Controller;
use Dev3\AiJobBoard\Data\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the plugin controllers.
 */
abstract class Controller extends Base_Controller {

	/**
	 * Permission: any logged-in user with a valid wp_rest nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function logged_in( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'dev3jb_login', __( 'Please log in first.', 'dev3-ai-job-board' ), array( 'status' => 401 ) );
		}
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Stores an uploaded file from a multipart request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $field   File field.
	 * @param int              $user_id Owner (0 = guest).
	 * @param string           $kind    resume|attachment.
	 * @return array|null|\WP_Error File row, null when no file was sent.
	 */
	protected function store_upload( \WP_REST_Request $request, $field, $user_id, $kind ) {
		$files = $request->get_file_params();
		if ( empty( $files[ $field ] ) || ( isset( $files[ $field ]['error'] ) && UPLOAD_ERR_NO_FILE === (int) $files[ $field ]['error'] ) ) {
			return null;
		}
		$file = $files[ $field ];
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'dev3jb_upload', __( 'The upload failed. Try again.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		/**
		 * Allows tests to pass files that did not come through PHP's upload handler.
		 *
		 * @param bool $skip Skip is_uploaded_file().
		 */
		if ( ! apply_filters( 'dev3jb_skip_upload_check', false ) && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'dev3jb_upload', __( 'The upload failed. Try again.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return Files::store( $file['tmp_name'], isset( $file['name'] ) ? (string) $file['name'] : 'resume', $user_id, $kind );
	}
}
