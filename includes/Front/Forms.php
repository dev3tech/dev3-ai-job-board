<?php
/**
 * Front-end form helpers: anti-spam guard fields (honeypot + signed time trap + nonce) and redirects.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Core3\Core\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
class Forms {

	/**
	 * Hidden guard fields for a form posting to admin-post.php.
	 *
	 * @param string $action Action suffix.
	 * @return string
	 */
	public static function guard_fields( $action ) {
		$ts   = time();
		$html = '<input type="hidden" name="action" value="dev3jb_' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="dev3jb_t" value="' . esc_attr( $ts . '.' . substr( wp_hash( $ts . '|dev3jb|' . $action ), 0, 16 ) ) . '">'
			. '<div class="dev3jb-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'dev3-ai-job-board' ) . '<input type="text" name="dev3jb_hp" value="" tabindex="-1" autocomplete="off"></label></div>'
			. wp_nonce_field( 'dev3jb_' . $action, '_dev3jb_nonce', true, false );
		return $html;
	}

	/**
	 * Checks honeypot, time trap, nonce and rate limit.
	 *
	 * @param string $action   Action.
	 * @param int    $limit    Per minute per IP.
	 * @param int    $min_secs Minimum seconds on the form (0 for quick actions).
	 * @return true|\WP_Error
	 */
	public static function check_guard( $action, $limit = 10, $min_secs = 2 ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this method IS the verification.
		if ( ! empty( $_POST['dev3jb_hp'] ) ) {
			return new \WP_Error( 'dev3jb_spam', __( 'Your submission looks like spam.', 'dev3-ai-job-board' ) );
		}
		$t     = isset( $_POST['dev3jb_t'] ) ? sanitize_text_field( wp_unslash( $_POST['dev3jb_t'] ) ) : '';
		$parts = explode( '.', $t );
		if ( 2 !== count( $parts ) || ! hash_equals( substr( wp_hash( $parts[0] . '|dev3jb|' . $action ), 0, 16 ), $parts[1] ) || time() - (int) $parts[0] < $min_secs ) {
			return new \WP_Error( 'dev3jb_too_fast', __( 'Please take a moment to fill in the form, then try again.', 'dev3-ai-job-board' ) );
		}
		$nonce = isset( $_POST['_dev3jb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dev3jb_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, 'dev3jb_' . $action ) ) {
			return new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) );
		}
		if ( ! Rate_Limiter::hit( 'form_' . $action, Rate_Limiter::client_ip(), (int) apply_filters( 'dev3jb_form_rate_limit', $limit, $action ) ) ) {
			return new \WP_Error( 'dev3jb_rate_limited', __( 'Too many submissions. Please wait a minute.', 'dev3-ai-job-board' ) );
		}
		return true;
	}

	/**
	 * Simple action link/button form (nonce-protected POST).
	 *
	 * @param string $action  Action suffix.
	 * @param array  $fields  Hidden fields.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 * @param string $confirm Confirmation text (JS).
	 * @return string
	 */
	public static function action_button( $action, array $fields, $label, $classes = 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm', $confirm = '' ) {
		$html  = '<form method="post" class="dev3jb-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' data-dev3jb-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="dev3jb_' . esc_attr( $action ) . '">' . wp_nonce_field( 'dev3jb_' . $action, '_dev3jb_nonce', true, false );
		foreach ( $fields as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$html .= '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
		return $html;
	}

	/**
	 * Verifies an action_button() nonce.
	 *
	 * @param string $action Action suffix.
	 * @return bool
	 */
	public static function check_action( $action ) {
		$nonce = isset( $_POST['_dev3jb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_dev3jb_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return is_user_logged_in() && (bool) wp_verify_nonce( $nonce, 'dev3jb_' . $action );
	}

	/**
	 * Redirect with a notice or error.
	 *
	 * @param string           $url    Base URL.
	 * @param string|\WP_Error $result Notice key or error.
	 * @param array            $args   Extra query args.
	 */
	public static function back( $url, $result, array $args = array() ) {
		$url = remove_query_arg( array( 'dev3jb_notice', 'dev3jb_error' ), $url );
		if ( is_wp_error( $result ) ) {
			$args['dev3jb_error'] = rawurlencode( mb_substr( $result->get_error_message(), 0, 300 ) );
		} elseif ( '' !== (string) $result ) {
			$args['dev3jb_notice'] = $result;
		}
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Return URL from the form (same site only).
	 *
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function return_url( $fallback = '' ) {
		$url = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = wp_validate_redirect( $url, '' );
		return '' !== $url ? $url : ( $fallback ? $fallback : home_url( '/' ) );
	}

	/**
	 * Notice HTML from the query string.
	 *
	 * @param array $messages notice key => text.
	 * @return string
	 */
	public static function notices( array $messages ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$html = '';
		if ( isset( $_GET['dev3jb_error'] ) ) {
			$html .= '<div class="dev3jb-notice dev3jb-notice--error" role="alert">' . esc_html( mb_substr( sanitize_text_field( rawurldecode( wp_unslash( $_GET['dev3jb_error'] ) ) ), 0, 300 ) ) . '</div>';
		}
		if ( isset( $_GET['dev3jb_notice'] ) ) {
			$key = sanitize_key( wp_unslash( $_GET['dev3jb_notice'] ) );
			if ( isset( $messages[ $key ] ) ) {
				$html .= '<div class="dev3jb-notice dev3jb-notice--ok" role="status">' . esc_html( $messages[ $key ] ) . '</div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $html;
	}
}
