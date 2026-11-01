<?php
/**
 * Job alerts: saved searches emailed as a daily or weekly digest of new jobs.
 *
 * Guests confirm by email first (double opt-in); logged-in users are confirmed at once.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository over dev3jb_alerts.
 */
class Alerts {

	const KEYS = array( 'q', 'category', 'type', 'location', 'workplace', 'salary_min' );

	/**
	 * Subscribes to a search.
	 *
	 * @param array  $query     Search args.
	 * @param string $email     Email.
	 * @param int    $user_id   User.
	 * @param string $frequency daily|weekly.
	 * @return array|\WP_Error
	 */
	public static function create( array $query, $email, $user_id = 0, $frequency = 'daily' ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3jb_alert_email', __( 'Enter a valid email address.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$clean = self::clean_query( $query );
		$table = Tables::name( 'alerts' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count >= 20 ) {
			return new \WP_Error( 'dev3jb_alert_limit', __( 'You already have 20 job alerts.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'user_id'    => (int) $user_id,
				'email'      => $email,
				'label'      => mb_substr( self::label( $clean ), 0, 160 ),
				'query'      => wp_json_encode( $clean ),
				'frequency'  => 'weekly' === $frequency ? 'weekly' : 'daily',
				'token'      => wp_generate_password( 32, false, false ),
				'confirmed'  => $user_id ? 1 : 0,
				'last_sent'  => current_time( 'mysql', true ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$alert = self::get( (int) $wpdb->insert_id );
		if ( $alert && ! $alert['confirmed'] ) {
			do_action( 'dev3jb_alert_confirm', $alert );
		}
		return $alert;
	}

	/**
	 * Keeps known search keys only.
	 *
	 * @param array $query Raw.
	 * @return array
	 */
	public static function clean_query( array $query ) {
		$out = array();
		foreach ( self::KEYS as $k ) {
			if ( isset( $query[ $k ] ) && '' !== $query[ $k ] && array() !== $query[ $k ] ) {
				$out[ $k ] = is_array( $query[ $k ] ) ? array_values( map_deep( $query[ $k ], 'sanitize_text_field' ) ) : mb_substr( sanitize_text_field( (string) $query[ $k ] ), 0, 120 );
			}
		}
		if ( isset( $out['salary_min'] ) ) {
			$out['salary_min'] = (string) max( 0, (int) $out['salary_min'] );
		}
		return $out;
	}

	/**
	 * Human label for a query.
	 *
	 * @param array $query Query.
	 * @return string
	 */
	public static function label( array $query ) {
		$parts = array();
		if ( ! empty( $query['q'] ) ) {
			$parts[] = '"' . $query['q'] . '"';
		}
		$tax = array(
			'category' => Post_Types::CATEGORY,
			'type'     => Post_Types::TYPE,
			'location' => Post_Types::LOCATION,
		);
		foreach ( $tax as $key => $taxonomy ) {
			if ( empty( $query[ $key ] ) ) {
				continue;
			}
			$slug    = is_array( $query[ $key ] ) ? (string) reset( $query[ $key ] ) : (string) $query[ $key ];
			$term    = get_term_by( 'slug', $slug, $taxonomy );
			$parts[] = $term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : $slug;
		}
		if ( ! empty( $query['workplace'] ) ) {
			$labels = Listings::workplace_labels();
			$wp     = is_array( $query['workplace'] ) ? $query['workplace'] : explode( ',', (string) $query['workplace'] );
			$names  = array();
			foreach ( $wp as $w ) {
				if ( isset( $labels[ $w ] ) ) {
					$names[] = $labels[ $w ];
				}
			}
			if ( $names ) {
				$parts[] = implode( '/', $names );
			}
		}
		if ( ! empty( $query['salary_min'] ) ) {
			/* translators: %s: amount */
			$parts[] = sprintf( __( '%s+', 'dev3-ai-job-board' ), Listings::money( (float) $query['salary_min'] ) );
		}
		return $parts ? implode( ' · ', $parts ) : __( 'All new jobs', 'dev3-ai-job-board' );
	}

	/**
	 * One alert.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$row['id']        = (int) $row['id'];
		$row['user_id']   = (int) $row['user_id'];
		$row['confirmed'] = (bool) $row['confirmed'];
		$q                = json_decode( (string) $row['query'], true );
		$row['query']     = is_array( $q ) ? $q : array();
		return $row;
	}

	/**
	 * Alerts of a user.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * All alerts (admin).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Confirms by token (double opt-in link).
	 *
	 * @param int    $id    Alert.
	 * @param string $token Token.
	 * @return bool
	 */
	public static function confirm( $id, $token ) {
		global $wpdb;
		$alert = self::get( $id );
		if ( ! $alert || '' === (string) $token || ! hash_equals( (string) $alert['token'], (string) $token ) ) {
			return false;
		}
		$wpdb->update( Tables::name( 'alerts' ), array( 'confirmed' => 1 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Deletes by id (owner/manager) or by token (unsubscribe link).
	 *
	 * @param int    $id      Id.
	 * @param int    $user_id Owner (0 to use token).
	 * @param string $token   Token.
	 * @return bool
	 */
	public static function delete( $id, $user_id = 0, $token = '' ) {
		global $wpdb;
		$alert = self::get( $id );
		if ( ! $alert ) {
			return false;
		}
		$ok = ( $user_id && ( $alert['user_id'] === (int) $user_id || user_can( $user_id, 'manage_options' ) ) ) || ( '' !== $token && hash_equals( (string) $alert['token'], (string) $token ) );
		if ( ! $ok ) {
			return false;
		}
		return (bool) $wpdb->delete( Tables::name( 'alerts' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Jobs that match an alert, published after a date.
	 *
	 * @param array  $alert Alert.
	 * @param string $since GMT datetime.
	 * @param int    $limit Max.
	 * @return array Jobs.
	 */
	public static function matches( array $alert, $since, $limit = 10 ) {
		$args             = (array) $alert['query'];
		$args['per_page'] = $limit;
		$filter           = static function ( $query ) use ( $since ) {
			$query['date_query'] = array(
				array(
					'after'     => $since,
					'column'    => 'post_date_gmt',
					'inclusive' => false,
				),
			);
			return $query;
		};
		add_filter( 'dev3jb_search_query', $filter );
		$result = Listings::search( $args );
		remove_filter( 'dev3jb_search_query', $filter );
		return $result['items'];
	}

	/**
	 * Digest run: daily alerts every day, weekly alerts every 7 days.
	 *
	 * @param int $now Time (tests).
	 * @return int Emails sent.
	 */
	public static function send_digests( $now = 0 ) {
		global $wpdb;
		$now   = $now ? $now : time();
		$table = Tables::name( 'alerts' );
		$rows  = $wpdb->get_col( "SELECT id FROM {$table} WHERE confirmed = 1 ORDER BY id ASC LIMIT 5000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent  = 0;
		foreach ( array_map( 'intval', $rows ) as $id ) {
			$alert = self::get( $id );
			if ( ! $alert ) {
				continue;
			}
			$gap  = 'weekly' === $alert['frequency'] ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS;
			$last = $alert['last_sent'] ? strtotime( $alert['last_sent'] . ' UTC' ) : 0;
			if ( $last && $now - $last < $gap - HOUR_IN_SECONDS ) {
				continue;
			}
			$jobs = self::matches( $alert, (string) $alert['last_sent'], 10 );
			$wpdb->update( $table, array( 'last_sent' => gmdate( 'Y-m-d H:i:s', $now ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $jobs && \Dev3\AiJobBoard\Emails\Mailer::alert_digest( $alert, $jobs ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Unsubscribe / confirm URL.
	 *
	 * @param array  $alert  Alert.
	 * @param string $action unsubscribe|confirm.
	 * @return string
	 */
	public static function link( array $alert, $action ) {
		return add_query_arg(
			array(
				'dev3jb_alert' => 'confirm' === $action ? 'confirm' : 'unsubscribe',
				'id'           => (int) $alert['id'],
				'token'        => $alert['token'],
			),
			home_url( '/' )
		);
	}
}
