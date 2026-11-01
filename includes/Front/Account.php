<?php
/**
 * Login / register gate for the dashboards and the submission form.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static gate.
 */
class Account {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_dev3jb_register', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_dev3jb_register', array( __CLASS__, 'register' ) );
	}

	/**
	 * Gate markup.
	 *
	 * @param string $role  employer|candidate.
	 * @param string $title Heading.
	 * @param string $intro Intro text.
	 * @return string
	 */
	public static function gate( $role, $title, $intro ) {
		$here = (string) get_permalink();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$redirect = isset( $_GET['redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect'] ) ), $here ) : $here;
		$html     = '<div class="dev3jb dev3jb-gate">' . Forms::notices( array() );
		$html    .= '<div class="dev3jb-gate__intro"><h2 class="dev3jb-h">' . esc_html( $title ) . '</h2><p class="dev3jb-muted">' . esc_html( $intro ) . '</p></div>';
		$html    .= '<div class="dev3jb-gate__grid"><section class="dev3jb-box" aria-labelledby="dev3jb-login-h"><h3 class="dev3jb-h" id="dev3jb-login-h">' . esc_html__( 'Log in', 'dev3-ai-job-board' ) . '</h3>';
		$html    .= wp_login_form(
			array(
				'echo'           => false,
				'redirect'       => $redirect,
				'form_id'        => 'dev3jb-login-' . $role,
				'id_username'    => 'dev3jb-user-' . $role,
				'id_password'    => 'dev3jb-pass-' . $role,
				'id_remember'    => 'dev3jb-remember-' . $role,
				'id_submit'      => 'dev3jb-submit-' . $role,
				'label_username' => __( 'Email or username', 'dev3-ai-job-board' ),
			)
		);
		$html    .= '<p class="dev3jb-small"><a class="dev3jb-link" href="' . esc_url( wp_lostpassword_url( $here ) ) . '">' . esc_html__( 'Forgot your password?', 'dev3-ai-job-board' ) . '</a></p></section>';
		if ( Settings::get( 'allow_registration', true ) ) {
			$uid   = 'dev3jb-reg-' . $role;
			$html .= '<section class="dev3jb-box" aria-labelledby="' . esc_attr( $uid ) . '-h"><h3 class="dev3jb-h" id="' . esc_attr( $uid ) . '-h">' . esc_html( 'employer' === $role ? __( 'Create an employer account', 'dev3-ai-job-board' ) : __( 'Create a candidate account', 'dev3-ai-job-board' ) ) . '</h3>';
			$html .= '<form class="dev3jb-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Forms::guard_fields( 'register' );
			$html .= '<input type="hidden" name="role" value="' . esc_attr( $role ) . '"><input type="hidden" name="return" value="' . esc_url( $redirect ) . '">';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-name">' . esc_html__( 'Your name', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $uid ) . '-name" name="name" required autocomplete="name"></div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-email">' . esc_html__( 'Email', 'dev3-ai-job-board' ) . '</label><input type="email" id="' . esc_attr( $uid ) . '-email" name="email" required autocomplete="email"></div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-pass">' . esc_html__( 'Password', 'dev3-ai-job-board' ) . '</label><input type="password" id="' . esc_attr( $uid ) . '-pass" name="password" required minlength="8" autocomplete="new-password"><span class="dev3jb-help">' . esc_html__( 'At least 8 characters.', 'dev3-ai-job-board' ) . '</span></div>';
			$html .= '<button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--block">' . esc_html__( 'Create account', 'dev3-ai-job-board' ) . '</button></form></section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Registration handler.
	 */
	public static function register() {
		$back  = Forms::return_url( home_url( '/' ) );
		$guard = Forms::check_guard( 'register', 5 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		if ( ! Settings::get( 'allow_registration', true ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_closed', __( 'Registration is closed.', 'dev3-ai-job-board' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$name     = isset( $_POST['name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 80 ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
		$role     = isset( $_POST['role'] ) && 'employer' === $_POST['role'] ? Post_Types::ROLE_EMPLOYER : Post_Types::ROLE_CANDIDATE;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $name || ! is_email( $email ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_reg', __( 'Enter your name and a valid email address.', 'dev3-ai-job-board' ) ) );
		}
		if ( strlen( $password ) < 8 ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_reg', __( 'Choose a password with at least 8 characters.', 'dev3-ai-job-board' ) ) );
		}
		if ( email_exists( $email ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_reg', __( 'An account with this email already exists. Log in instead.', 'dev3-ai-job-board' ) ) );
		}
		$login = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = '' === $login ? 'user' : $login;
		$i     = 1;
		while ( username_exists( $login ) || '' === $login ) {
			$login = $base . $i;
			++$i;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			Forms::back( $back, $user_id );
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'dev3jb_user_registered', $user_id, $role );
		Forms::back( $back, 'registered' );
	}
}
