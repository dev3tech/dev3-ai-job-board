<?php
/**
 * Package checkout: free packages activate at once; paid packages go through Stripe Checkout,
 * WooCommerce or manual/offline payment (the admin marks it paid). A job waiting for the package
 * stays "Awaiting payment" until the payment completes.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Billing;

use Dev3\AiJobBoard\Core3\Core\Encryption;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\UserPackages;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Checkout {

	const SECRETS = 'dev3jb_payment_secrets';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_return' ) );
		Woo::init();
	}

	/**
	 * Decrypted secret.
	 *
	 * @param string $key stripe_secret|stripe_webhook.
	 * @return string
	 */
	public static function secret( $key ) {
		$const = 'DEV3JB_' . strtoupper( $key );
		if ( defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		$all = get_option( self::SECRETS, array() );
		return is_array( $all ) && ! empty( $all[ $key ] ) ? Encryption::decrypt( (string) $all[ $key ] ) : '';
	}

	/**
	 * Saves secrets (masked values are ignored, '' clears).
	 *
	 * @param array $input Input.
	 */
	public static function save_secrets( array $input ) {
		$all = get_option( self::SECRETS, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( array( 'stripe_secret', 'stripe_webhook' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $input[ $key ] ) );
			if ( false !== strpos( $value, '•' ) ) {
				continue;
			}
			$all[ $key ] = '' === $value ? '' : Encryption::encrypt( $value );
		}
		update_option( self::SECRETS, $all, false );
	}

	/**
	 * Public view of payment configuration.
	 *
	 * @return array
	 */
	public static function config() {
		$mask = static function ( $v ) {
			return '' === $v ? '' : str_repeat( '•', 8 ) . substr( $v, -4 );
		};
		$sk   = self::secret( 'stripe_secret' );
		return array(
			'stripe_secret'      => $mask( $sk ),
			'stripe_secret_set'  => '' !== $sk,
			'stripe_test_mode'   => 0 === strpos( $sk, 'sk_test_' ),
			'stripe_webhook'     => $mask( self::secret( 'stripe_webhook' ) ),
			'stripe_webhook_set' => '' !== self::secret( 'stripe_webhook' ),
			'webhook_url'        => esc_url_raw( rest_url( 'dev3jb/v1/stripe/webhook' ) ),
			'woocommerce'        => class_exists( 'WooCommerce' ),
		);
	}

	/**
	 * Gateway that will actually be used (falls back to manual when the chosen one is not ready).
	 *
	 * @return string
	 */
	public static function gateway() {
		$gateway = (string) Settings::get( 'payment_gateway', 'manual' );
		if ( 'woocommerce' === $gateway && ! class_exists( 'WooCommerce' ) ) {
			$gateway = 'manual';
		}
		if ( 'stripe' === $gateway && '' === self::secret( 'stripe_secret' ) ) {
			$gateway = 'manual';
		}
		return $gateway;
	}

	/**
	 * Employer dashboard URL with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return string
	 */
	public static function dashboard_url( $notice = '' ) {
		$url = add_query_arg( 'dev3jb_tab', 'jobs', Settings::page_url( 'employer' ) );
		return $notice ? add_query_arg( 'dev3jb_notice', $notice, $url ) : $url;
	}

	/**
	 * Uses an existing package credit for a job.
	 *
	 * @param int $job_id          Job.
	 * @param int $user_package_id User package.
	 * @param int $user_id         Owner.
	 * @return array|\WP_Error { status, redirect }
	 */
	public static function use_credit( $job_id, $user_package_id, $user_id ) {
		$package = UserPackages::get( $user_package_id );
		if ( ! $package || (int) $package['user_id'] !== (int) $user_id || 'active' !== $package['status'] || $package['remaining'] < 1 ) {
			return new \WP_Error( 'dev3jb_no_credits', __( 'That package has no job posts left.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$status = Listings::apply_package( $job_id, $package );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return array(
			'status'   => $status,
			'redirect' => self::dashboard_url( 'publish' === $status ? 'published' : 'submitted' ),
			'payment'  => null,
		);
	}

	/**
	 * Buys a package (optionally for a waiting job).
	 *
	 * @param int    $user_id    Payer.
	 * @param string $package_id Package definition id.
	 * @param int    $job_id     Job (0 = just buy credits).
	 * @return array|\WP_Error { status, redirect, payment }
	 */
	public static function start( $user_id, $package_id, $job_id = 0 ) {
		$package = Packages::get( (string) $package_id );
		if ( ! $package || ! $package['active'] ) {
			return new \WP_Error( 'dev3jb_package', __( 'Choose an available package.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( $job_id && ! Listings::owner_can( $job_id, $user_id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( ! Packages::is_paid( $package ) ) {
			$granted = UserPackages::grant( $user_id, $package, 'active' );
			if ( ! $job_id ) {
				return array(
					'status'   => 'active',
					'redirect' => self::dashboard_url( 'package' ),
					'payment'  => null,
				);
			}
			return self::use_credit( $job_id, $granted['id'], $user_id );
		}
		$pending = UserPackages::grant( $user_id, $package, 'pending' );
		if ( $job_id && 'publish' !== get_post_status( $job_id ) ) {
			update_post_meta( $job_id, '_dev3jb_user_package', 0 );
			wp_update_post(
				array(
					'ID'          => $job_id,
					'post_status' => Post_Types::UNPAID,
				)
			);
		}
		$gateway = self::gateway();
		$payment = Payments::create( $user_id, $package, $pending['id'], $job_id, $gateway );
		$title   = $job_id ? html_entity_decode( get_the_title( $job_id ), ENT_QUOTES, 'UTF-8' ) : '';
		if ( 'stripe' === $gateway ) {
			$session = Stripe::create_session( $payment, $package, $title, self::dashboard_url() );
			if ( is_wp_error( $session ) ) {
				Payments::set_status( $payment['id'], 'failed' );
				return $session;
			}
			Payments::set_ref( $payment['id'], $session['id'] );
			return array(
				'status'   => Post_Types::UNPAID,
				'redirect' => $session['url'],
				'payment'  => Payments::get( $payment['id'] ),
			);
		}
		if ( 'woocommerce' === $gateway ) {
			$url = Woo::add_to_cart( $payment, $package );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			return array(
				'status'   => Post_Types::UNPAID,
				'redirect' => $url,
				'payment'  => Payments::get( $payment['id'] ),
			);
		}
		/**
		 * Fires when an offline payment is created (the admin marks it paid later).
		 *
		 * @param array $payment Payment.
		 */
		do_action( 'dev3jb_manual_payment_created', $payment );
		return array(
			'status'   => Post_Types::UNPAID,
			'redirect' => self::dashboard_url( 'manual' ),
			'payment'  => $payment,
		);
	}

	/**
	 * Stripe success return (?dev3jb_return=stripe&session_id=…): verify with Stripe, then activate.
	 */
	public static function handle_return() {
		if ( empty( $_GET['dev3jb_return'] ) || 'stripe' !== $_GET['dev3jb_return'] || empty( $_GET['session_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result     = Stripe::verify_session( $session_id );
		wp_safe_redirect( self::dashboard_url( is_wp_error( $result ) ? 'payment_pending' : 'paid' ) );
		exit;
	}
}
