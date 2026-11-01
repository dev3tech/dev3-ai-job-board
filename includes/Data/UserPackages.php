<?php
/**
 * Packages bought (or granted) to employers: job credits that are used one per job post.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository over dev3jb_user_packages.
 */
class UserPackages {

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Tables::name( 'user_packages' );
	}

	/**
	 * Grants a package to a user.
	 *
	 * @param int    $user_id    User.
	 * @param array  $package    Package definition.
	 * @param string $status     active|pending.
	 * @param int    $payment_id Payment.
	 * @return array
	 */
	public static function grant( $user_id, array $package, $status = 'active', $payment_id = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'       => (int) $user_id,
				'package_id'    => $package['id'],
				'name'          => $package['name'],
				'job_limit'     => (int) $package['job_limit'],
				'jobs_used'     => 0,
				'duration_days' => (int) $package['duration_days'],
				'featured'      => $package['featured'] ? 1 : 0,
				'status'        => 'pending' === $status ? 'pending' : 'active',
				'payment_id'    => (int) $payment_id,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! $id ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Typed row.
	 *
	 * @param array $row Raw.
	 * @return array
	 */
	private static function shape( array $row ) {
		foreach ( array( 'id', 'user_id', 'job_limit', 'jobs_used', 'duration_days', 'payment_id' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['featured']  = (bool) $row['featured'];
		$row['remaining'] = max( 0, $row['job_limit'] - $row['jobs_used'] );
		return $row;
	}

	/**
	 * Active packages with credits left.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function available( $user_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status = 'active' AND jobs_used < job_limit ORDER BY id ASC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	/**
	 * All packages of a user.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 100", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	/**
	 * Uses one credit (atomic: only when credits remain).
	 *
	 * @param int $id Package.
	 * @return array|\WP_Error
	 */
	public static function consume( $id ) {
		global $wpdb;
		$table = self::table();
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET jobs_used = jobs_used + 1, status = IF(jobs_used >= job_limit, 'used', status) WHERE id = %d AND status = 'active' AND jobs_used < job_limit", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $done ) {
			return new \WP_Error( 'dev3jb_no_credits', __( 'This package has no job posts left.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return self::get( $id );
	}

	/**
	 * Activates a pending package (after payment).
	 *
	 * @param int $id Package.
	 * @return array|null
	 */
	public static function activate( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'active' ), array( 'id' => (int) $id, 'status' => 'pending' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		return self::get( $id );
	}
}
