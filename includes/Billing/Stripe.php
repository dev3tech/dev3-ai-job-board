<?php
/**
 * Stripe Checkout (hosted payment page): create a session, verify it on return, and a signed webhook.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Billing;

use Dev3\AiJobBoard\Data\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Static gateway.
 */
class Stripe {

	const API = 'https://api.stripe.com/v1/';

	const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Amount in the smallest currency unit.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	public static function minor( $amount, $currency ) {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * API call.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Path.
	 * @param array  $body   Form body.
	 * @return array|\WP_Error
	 */
	private static function call( $method, $path, array $body = array() ) {
		$secret = Checkout::secret( 'stripe_secret' );
		if ( '' === $secret ) {
			return new \WP_Error( 'dev3jb_stripe_key', __( 'Stripe is not configured.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
		);
		if ( $body ) {
			$args['body'] = $body;
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'dev3jb_stripe_network', __( 'Could not reach Stripe. Try again.', 'dev3-ai-job-board' ), array( 'status' => 502 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Stripe rejected the request.', 'dev3-ai-job-board' );
			return new \WP_Error( 'dev3jb_stripe_error', sanitize_text_field( $msg ), array( 'status' => 502 ) );
		}
		return $data;
	}

	/**
	 * Creates a Checkout Session.
	 *
	 * @param array  $payment   Payment.
	 * @param array  $plan      Package.
	 * @param string $title     Job title (or package name).
	 * @param string $dashboard Dashboard URL.
	 * @return array|\WP_Error { id, url }
	 */
	public static function create_session( array $payment, array $plan, $title, $dashboard ) {
		$user = get_userdata( $payment['user_id'] );
		$body = array(
			'mode'                                   => 'payment',
			'client_reference_id'                    => (string) $payment['id'],
			'metadata[payment_id]'                   => (string) $payment['id'],
			'metadata[job_id]'                       => (string) $payment['job_id'],
			'line_items[0][quantity]'                => 1,
			'line_items[0][price_data][currency]'    => strtolower( $payment['currency'] ),
			'line_items[0][price_data][unit_amount]' => self::minor( $payment['amount'], $payment['currency'] ),
			'line_items[0][price_data][product_data][name]' => mb_substr( $title ? $plan['name'] . ' – ' . $title : $plan['name'], 0, 250 ),
			'success_url'                            => add_query_arg( 'dev3jb_return', 'stripe', home_url( '/' ) ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'                             => add_query_arg( 'dev3jb_notice', 'payment_cancelled', $dashboard ),
		);
		if ( $user && is_email( $user->user_email ) ) {
			$body['customer_email'] = $user->user_email;
		}
		$data = self::call( 'POST', 'checkout/sessions', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new \WP_Error( 'dev3jb_stripe_error', __( 'Stripe did not return a checkout page.', 'dev3-ai-job-board' ), array( 'status' => 502 ) );
		}
		return array(
			'id'  => (string) $data['id'],
			'url' => esc_url_raw( (string) $data['url'] ),
		);
	}

	/**
	 * Verifies a session with Stripe and completes the matching payment.
	 *
	 * @param string $session_id Session id.
	 * @return array|\WP_Error Payment.
	 */
	public static function verify_session( $session_id ) {
		if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ) {
			return new \WP_Error( 'dev3jb_stripe_session', __( 'Invalid checkout session.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$payment = Payments::by_ref( $session_id );
		if ( ! $payment ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Payment not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( 'paid' === $payment['status'] ) {
			return $payment;
		}
		$data = self::call( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::complete_from_session( $data );
	}

	/**
	 * Completes the payment for a paid session object.
	 *
	 * @param array $session Session.
	 * @return array|\WP_Error
	 */
	public static function complete_from_session( array $session ) {
		$payment = Payments::by_ref( isset( $session['id'] ) ? (string) $session['id'] : '' );
		$ref     = isset( $session['client_reference_id'] ) ? (string) $session['client_reference_id'] : '';
		if ( ! $payment || (string) $payment['id'] !== $ref ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Payment not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( 'paid' !== ( isset( $session['payment_status'] ) ? $session['payment_status'] : '' ) ) {
			return new \WP_Error( 'dev3jb_unpaid', __( 'The payment is not complete yet.', 'dev3-ai-job-board' ), array( 'status' => 402 ) );
		}
		if ( isset( $session['amount_total'] ) && (int) $session['amount_total'] < self::minor( $payment['amount'], $payment['currency'] ) ) {
			return new \WP_Error( 'dev3jb_amount', __( 'The paid amount does not match the package.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return Payments::complete( $payment['id'] );
	}

	/**
	 * Verifies a Stripe-Signature header (v1 HMAC-SHA256, 5-minute tolerance).
	 *
	 * @param string $payload Raw body.
	 * @param string $header  Header.
	 * @param string $secret  Webhook secret.
	 * @param int    $now     Time (tests).
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $now = 0 ) {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$t    = 0;
		$sigs = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sigs[] = $kv[1];
			}
		}
		$now = $now ? $now : time();
		if ( ! $t || abs( $now - $t ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Webhook handler (REST): checkout.session.completed / async_payment_succeeded.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function webhook( \WP_REST_Request $request ) {
		$payload = (string) $request->get_body();
		if ( ! self::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), Checkout::secret( 'stripe_webhook' ) ) ) {
			return new \WP_REST_Response( array( 'error' => 'bad signature' ), 400 );
		}
		$event = json_decode( $payload, true );
		$type  = is_array( $event ) && isset( $event['type'] ) ? (string) $event['type'] : '';
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && isset( $event['data']['object'] ) ) {
			$res = self::complete_from_session( (array) $event['data']['object'] );
			return new \WP_REST_Response( array( 'ok' => ! is_wp_error( $res ) ), 200 );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
