<?php
/**
 * Applications + pipeline (stages, order, rating, notes timeline, match results).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository over dev3jb_applications / dev3jb_events.
 */
class Applications {

	const STAGES = array( 'new', 'screening', 'interview', 'offer', 'hired', 'rejected' );

	/**
	 * Employer-facing stage labels.
	 *
	 * @return array
	 */
	public static function stage_labels() {
		return array(
			'new'       => __( 'New', 'dev3-ai-job-board' ),
			'screening' => __( 'Screening', 'dev3-ai-job-board' ),
			'interview' => __( 'Interview', 'dev3-ai-job-board' ),
			'offer'     => __( 'Offer', 'dev3-ai-job-board' ),
			'hired'     => __( 'Hired', 'dev3-ai-job-board' ),
			'rejected'  => __( 'Not a fit', 'dev3-ai-job-board' ),
			'withdrawn' => __( 'Withdrawn', 'dev3-ai-job-board' ),
		);
	}

	/**
	 * Candidate-facing stage labels (neutral wording).
	 *
	 * @return array
	 */
	public static function candidate_labels() {
		return array(
			'new'       => __( 'Received', 'dev3-ai-job-board' ),
			'screening' => __( 'In review', 'dev3-ai-job-board' ),
			'interview' => __( 'Interviewing', 'dev3-ai-job-board' ),
			'offer'     => __( 'Offer', 'dev3-ai-job-board' ),
			'hired'     => __( 'Hired', 'dev3-ai-job-board' ),
			'rejected'  => __( 'Not selected', 'dev3-ai-job-board' ),
			'withdrawn' => __( 'Withdrawn', 'dev3-ai-job-board' ),
		);
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Tables::name( 'applications' );
	}

	/**
	 * Whether a job takes on-site applications right now.
	 *
	 * @param array $job Job.
	 * @return true|\WP_Error
	 */
	public static function can_apply( array $job ) {
		if ( 'publish' !== $job['status'] ) {
			return new \WP_Error( 'dev3jb_closed', __( 'This job is no longer open.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( $job['filled'] ) {
			return new \WP_Error( 'dev3jb_filled', __( 'This position has been filled.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( $job['deadline'] && strtotime( $job['deadline'] . ' 23:59:59' ) < time() ) {
			return new \WP_Error( 'dev3jb_deadline', __( 'The application deadline has passed.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( 'internal' !== $job['apply_method'] ) {
			return new \WP_Error( 'dev3jb_external', __( 'Apply for this job on the employer\'s website.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Creates an application.
	 *
	 * @param int   $job_id Job.
	 * @param array $data   { name, email, phone, cover_letter, candidate_id, file_id, source }.
	 * @return array|\WP_Error Application.
	 */
	public static function create( $job_id, array $data ) {
		global $wpdb;
		$job = Listings::get( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$open = self::can_apply( $job );
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		$email = sanitize_email( isset( $data['email'] ) ? (string) $data['email'] : '' );
		$name  = mb_substr( sanitize_text_field( isset( $data['name'] ) ? (string) $data['name'] : '' ), 0, 120 );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3jb_email', __( 'Enter a valid email address.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( '' === $name ) {
			return new \WP_Error( 'dev3jb_name', __( 'Enter your name.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$table    = self::table();
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_id = %d AND email = %s", $job_id, $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return new \WP_Error( 'dev3jb_duplicate', __( 'You have already applied for this job.', 'dev3-ai-job-board' ), array( 'status' => 409 ) );
		}
		$candidate = isset( $data['candidate_id'] ) ? (int) $data['candidate_id'] : 0;
		$resume_id = $candidate ? Resumes::id_for_user( $candidate ) : 0;
		$profile   = $resume_id ? Resumes::get( $resume_id ) : null;
		$file_id   = isset( $data['file_id'] ) ? (int) $data['file_id'] : 0;
		if ( ! $file_id && $profile && $profile['file_id'] ) {
			$file_id = (int) $profile['file_id'];
		}
		if ( $file_id ) {
			$file = Files::get( $file_id );
			if ( ! $file || ( $file['user_id'] && (int) $file['user_id'] !== $candidate ) ) {
				$file_id = 0;
			}
		}
		if ( \Dev3\AiJobBoard\Settings::get( 'resume_required', false ) && ! $file_id ) {
			return new \WP_Error( 'dev3jb_resume_required', __( 'Attach your resume (PDF, Word or text).', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$snapshot = $profile;
		if ( $snapshot ) {
			unset( $snapshot['file'] );
		}
		$now = current_time( 'mysql', true );
		$pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM {$table} WHERE job_id = %d AND stage = 'new'", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'job_id'       => (int) $job_id,
				'employer_id'  => (int) $job['author'],
				'candidate_id' => $candidate,
				'resume_id'    => $resume_id,
				'file_id'      => $file_id,
				'name'         => $name,
				'email'        => $email,
				'phone'        => mb_substr( sanitize_text_field( isset( $data['phone'] ) ? (string) $data['phone'] : '' ), 0, 40 ),
				'cover_letter' => mb_substr( sanitize_textarea_field( isset( $data['cover_letter'] ) ? (string) $data['cover_letter'] : '' ), 0, 5000 ),
				'profile'      => $snapshot ? wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
				'stage'        => 'new',
				'position'     => $pos + 1,
				'source'       => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'site',
				'token'        => md5( wp_generate_password( 32, true, true ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dev3jb_db', __( 'Could not save the application. Try again.', 'dev3-ai-job-board' ), array( 'status' => 500 ) );
		}
		$app = self::get( (int) $wpdb->insert_id );
		/**
		 * Fires after an application is created.
		 *
		 * @param array $app Application.
		 * @param array $job Job.
		 */
		do_action( 'dev3jb_application_created', $app, $job );
		return self::get( $app['id'] );
	}

	/**
	 * One application (full shape).
	 *
	 * @param int $id Application.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row, true ) : null;
	}

	/**
	 * Typed row.
	 *
	 * @param array $row  Raw.
	 * @param bool  $full Include profile/file/events.
	 * @return array
	 */
	private static function shape( array $row, $full = false ) {
		foreach ( array( 'id', 'job_id', 'employer_id', 'candidate_id', 'resume_id', 'file_id', 'position', 'rating' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['match_score'] = null === $row['match_score'] ? null : (int) $row['match_score'];
		$match              = $row['match_json'] ? json_decode( (string) $row['match_json'], true ) : null;
		$row['match']       = is_array( $match ) ? $match : null;
		unset( $row['match_json'], $row['token'] );
		$row['job_title']   = html_entity_decode( get_the_title( $row['job_id'] ), ENT_QUOTES, 'UTF-8' );
		$labels             = self::stage_labels();
		$row['stage_label'] = isset( $labels[ $row['stage'] ] ) ? $labels[ $row['stage'] ] : $row['stage'];
		$profile            = $row['profile'] ? json_decode( (string) $row['profile'], true ) : null;
		if ( $row['resume_id'] && ( $full || ! is_array( $profile ) ) ) {
			$live = Resumes::get( $row['resume_id'] );
			if ( $live ) {
				unset( $live['file'] );
				$profile = $live;
			}
		}
		$row['profile']  = is_array( $profile ) ? $profile : null;
		$row['headline'] = $row['profile'] ? (string) $row['profile']['headline'] : '';
		if ( $full ) {
			$row['file']   = $row['file_id'] ? Files::public_info( $row['file_id'] ) : null;
			$row['events'] = self::events( $row['id'] );
		}
		return $row;
	}

	/**
	 * Lists applications.
	 *
	 * @param array $args { job_id, employer_id, candidate_id, stage, search, page, per_page, orderby (date|score|position) }.
	 * @return array { items, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'job_id', 'employer_id', 'candidate_id' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$where[]  = "{$key} = %d";
				$params[] = (int) $args[ $key ];
			}
		}
		if ( ! empty( $args['stage'] ) ) {
			$where[]  = 'stage = %s';
			$params[] = sanitize_key( $args['stage'] );
		} elseif ( empty( $args['include_withdrawn'] ) ) {
			$where[] = "stage <> 'withdrawn'";
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'date';
		$order   = 'score' === $orderby ? 'match_score IS NULL, match_score DESC, id DESC' : ( 'position' === $orderby ? 'position ASC, id ASC' : 'id DESC' );
		$per     = isset( $args['per_page'] ) ? min( 500, max( 1, (int) $args['per_page'] ) ) : 50;
		$page    = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$sql_w   = implode( ' AND ', $where );
		$total   = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$params2 = array_merge( $params, array( $per, ( $page - 1 ) * $per ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY {$order} LIMIT %d OFFSET %d", $params2 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		return array(
			'items' => array_map(
				static function ( $r ) {
					return self::shape( $r );
				},
				(array) $rows
			),
			'total' => $total,
		);
	}

	/**
	 * Count for a job (not withdrawn).
	 *
	 * @param int $job_id Job.
	 * @return int
	 */
	public static function count_for_job( $job_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_id = %d AND stage <> 'withdrawn'", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Counts by stage.
	 *
	 * @param array $args { job_id, employer_id }.
	 * @return array
	 */
	public static function stage_counts( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['job_id'] ) ) {
			$where   .= ' AND job_id = %d';
			$params[] = (int) $args['job_id'];
		}
		if ( ! empty( $args['employer_id'] ) ) {
			$where   .= ' AND employer_id = %d';
			$params[] = (int) $args['employer_id'];
		}
		$sql  = "SELECT stage, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY stage";
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array_fill_keys( array_merge( self::STAGES, array( 'withdrawn' ) ), 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['stage'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Moves an application to a stage (and an index within it).
	 *
	 * @param int    $id      Application.
	 * @param string $stage   Stage.
	 * @param int    $index   0-based index in the target column (-1 = end).
	 * @param int    $user_id Actor.
	 * @return array|\WP_Error
	 */
	public static function move( $id, $stage, $index = -1, $user_id = 0 ) {
		global $wpdb;
		$app = self::get( $id );
		if ( ! $app ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Application not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $stage, self::STAGES, true ) ) {
			return new \WP_Error( 'dev3jb_stage', __( 'Unknown stage.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( 'withdrawn' === $app['stage'] ) {
			return new \WP_Error( 'dev3jb_withdrawn', __( 'The candidate withdrew this application.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$table = self::table();
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_id = %d AND stage = %s AND id <> %d ORDER BY position ASC, id ASC", $app['job_id'], $stage, $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = ( $index < 0 || $index > count( $ids ) ) ? count( $ids ) : (int) $index;
		array_splice( $ids, $index, 0, array( (int) $id ) );
		foreach ( $ids as $pos => $aid ) {
			$wpdb->update( $table, array( 'position' => $pos + 1 ), array( 'id' => $aid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( $app['stage'] !== $stage ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'stage'      => $stage,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $id )
			);
			$labels = self::stage_labels();
			self::add_event( $id, $user_id, 'stage', $labels[ $app['stage'] ] . ' → ' . $labels[ $stage ] );
			/**
			 * Fires when an application changes stage.
			 *
			 * @param array  $app      Application (new state).
			 * @param string $from     Previous stage.
			 * @param int    $user_id  Actor.
			 */
			do_action( 'dev3jb_application_stage', self::get( $id ), $app['stage'], $user_id );
		}
		return self::get( $id );
	}

	/**
	 * Sets the employer's star rating (0-5).
	 *
	 * @param int $id     Application.
	 * @param int $rating Rating.
	 * @return array|null
	 */
	public static function rate( $id, $rating ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'rating' => min( 5, max( 0, (int) $rating ) ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Stores a match result.
	 *
	 * @param int   $id     Application.
	 * @param int   $score  0-100.
	 * @param array $result Result (criteria, strengths, gaps, summary, source).
	 */
	public static function set_match( $id, $score, array $result ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'match_score' => min( 100, max( 0, (int) $score ) ),
				'match_json'  => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'match_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Candidate withdraws.
	 *
	 * @param int $id           Application.
	 * @param int $candidate_id Candidate.
	 * @return array|\WP_Error
	 */
	public static function withdraw( $id, $candidate_id ) {
		global $wpdb;
		$app = self::get( $id );
		if ( ! $app || ! $candidate_id || $app['candidate_id'] !== (int) $candidate_id ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Application not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'stage'      => 'withdrawn',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		self::add_event( $id, $candidate_id, 'stage', __( 'Withdrawn by the candidate', 'dev3-ai-job-board' ) );
		return self::get( $id );
	}

	/**
	 * Adds a timeline event.
	 *
	 * @param int    $id      Application.
	 * @param int    $user_id Author.
	 * @param string $kind    note|stage|email|match.
	 * @param string $body    Text.
	 * @return int Event id.
	 */
	public static function add_event( $id, $user_id, $kind, $body ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'events' ),
			array(
				'application_id' => (int) $id,
				'user_id'        => (int) $user_id,
				'kind'           => in_array( $kind, array( 'note', 'stage', 'email', 'match' ), true ) ? $kind : 'note',
				'body'           => mb_substr( sanitize_textarea_field( (string) $body ), 0, 3000 ),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Timeline (newest first).
	 *
	 * @param int $id Application.
	 * @return array
	 */
	public static function events( $id ) {
		global $wpdb;
		$table = Tables::name( 'events' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d ORDER BY id DESC LIMIT 100", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as &$r ) {
			$r['id']      = (int) $r['id'];
			$r['user_id'] = (int) $r['user_id'];
			$user         = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
			$r['author']  = $user ? $user->display_name : '';
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Deletes an application and its events (keeps the file when the profile still uses it).
	 *
	 * @param int $id Application.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$app = self::get( $id );
		if ( ! $app ) {
			return;
		}
		$wpdb->delete( Tables::name( 'events' ), array( 'application_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $app['file_id'] && ! $app['candidate_id'] ) {
			Files::delete( $app['file_id'] );
		}
	}

	/**
	 * Whether a user may manage an application (employer of the job or manager).
	 *
	 * @param array $app     Application.
	 * @param int   $user_id User.
	 * @return bool
	 */
	public static function employer_can( array $app, $user_id ) {
		return $user_id && ( (int) $app['employer_id'] === (int) $user_id || user_can( $user_id, 'edit_others_posts' ) );
	}

	/**
	 * Applications created in the last N days.
	 *
	 * @param int $days    Days.
	 * @param int $employer Employer filter.
	 * @return int
	 */
	public static function recent_count( $days = 30, $employer = 0 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		if ( $employer ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at > %s AND employer_id = %d", $since, $employer ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at > %s", $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Daily application counts for the last N days (dashboard chart).
	 *
	 * @param int $days Days.
	 * @return array [ { date, count } ]
	 */
	public static function daily( $days = 14 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at)", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$map   = wp_list_pluck( $rows, 'n', 'd' );
		$out   = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = array(
				'date'  => $d,
				'count' => isset( $map[ $d ] ) ? (int) $map[ $d ] : 0,
			);
		}
		return $out;
	}
}
