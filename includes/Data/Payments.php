<?php
/**
 * Package payments (manual/offline, Stripe Checkout, WooCommerce orders).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository over dev3jb_payments.
 */
class Payments {

	/**
	 * Creates a pending payment.
	 *
	 * @param int    $user_id         Payer.
	 * @param array  $package         Package definition.
	 * @param int    $user_package_id Pending user package.
	 * @param int    $job_id          Job waiting for the package (0 = none).
	 * @param string $gateway         Gateway.
	 * @return array
	 */
	public static function create( $user_id, array $package, $user_package_id, $job_id, $gateway ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'payments' ),
			array(
				'user_id'         => (int) $user_id,
				'package_id'      => $package['id'],
				'user_package_id' => (int) $user_package_id,
				'job_id'          => (int) $job_id,
				'amount'          => (float) $package['price'],
				'currency'        => (string) \Dev3\AiJobBoard\Settings::get( 'currency', 'USD' ),
				'gateway'         => sanitize_key( $gateway ),
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql', true ),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * One payment.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'payments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * By gateway reference.
	 *
	 * @param string $ref Reference.
	 * @return array|null
	 */
	public static function by_ref( $ref ) {
		if ( '' === (string) $ref ) {
			return null;
		}
		global $wpdb;
		$table = Tables::name( 'payments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway_ref = %s ORDER BY id DESC LIMIT 1", $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Typed row.
	 *
	 * @param array $row Raw.
	 * @return array
	 */
	private static function shape( array $row ) {
		foreach ( array( 'id', 'user_id', 'user_package_id', 'job_id' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['amount']       = (float) $row['amount'];
		$row['job_title']    = $row['job_id'] ? html_entity_decode( get_the_title( $row['job_id'] ), ENT_QUOTES, 'UTF-8' ) : '';
		$package             = Packages::get( $row['package_id'] );
		$row['package_name'] = $package ? $package['name'] : $row['package_id'];
		$user                = get_userdata( $row['user_id'] );
		$row['user_name']    = $user ? $user->display_name : '';
		$row['user_email']   = $user ? $user->user_email : '';
		return $row;
	}

	/**
	 * Sets the gateway reference.
	 *
	 * @param int    $id  Payment.
	 * @param string $ref Ref.
	 */
	public static function set_ref( $id, $ref ) {
		global $wpdb;
		$wpdb->update( Tables::name( 'payments' ), array( 'gateway_ref' => mb_substr( (string) $ref, 0, 190 ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Marks paid (idempotent), activates the package and submits the waiting job.
	 *
	 * @param int $id Payment.
	 * @return array|null
	 */
	public static function complete( $id ) {
		global $wpdb;
		$payment = self::get( $id );
		if ( ! $payment || 'paid' === $payment['status'] ) {
			return $payment;
		}
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'payments' ),
			array(
				'status'  => 'paid',
				'paid_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => (int) $id,
				'status' => $payment['status'],
			)
		);
		if ( $updated ) {
			$package = UserPackages::activate( $payment['user_package_id'] );
			if ( $package && $payment['job_id'] && Post_Types::UNPAID === get_post_status( $payment['job_id'] ) ) {
				Listings::apply_package( $payment['job_id'], $package );
			}
			/**
			 * Fires once when a package payment completes.
			 *
			 * @param array $payment Payment.
			 */
			do_action( 'dev3jb_payment_completed', self::get( $id ) );
		}
		return self::get( $id );
	}

	/**
	 * Sets a non-paid status (failed, cancelled, refunded).
	 *
	 * @param int    $id     Payment.
	 * @param string $status Status.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( in_array( $status, array( 'pending', 'failed', 'cancelled', 'refunded' ), true ) ) {
			$wpdb->update( Tables::name( 'payments' ), array( 'status' => $status ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Latest payments.
	 *
	 * @param int $limit   Limit.
	 * @param int $user_id User filter.
	 * @return array
	 */
	public static function recent( $limit = 100, $user_id = 0 ) {
		global $wpdb;
		$table = Tables::name( 'payments' );
		$rows  = $user_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Revenue of paid payments in the last N days.
	 *
	 * @param int $days Days.
	 * @return float
	 */
	public static function revenue( $days = 30 ) {
		global $wpdb;
		$table = Tables::name( 'payments' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE status = 'paid' AND paid_at > %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
