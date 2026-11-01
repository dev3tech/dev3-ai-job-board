<?php
/**
 * Job repository: read/write the job post + meta, search, expiry.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository.
 */
class Listings {

	const WORKPLACES  = array( 'onsite', 'hybrid', 'remote' );
	const PERIODS     = array( 'hour', 'day', 'week', 'month', 'year' );
	const EXPERIENCE  = array( '', 'entry', 'mid', 'senior', 'lead', 'executive' );
	const APPLY_TYPES = array( 'internal', 'url', 'email' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/**
	 * Labels for workplace values.
	 *
	 * @return array
	 */
	public static function workplace_labels() {
		return array(
			'onsite' => __( 'On-site', 'dev3-ai-job-board' ),
			'hybrid' => __( 'Hybrid', 'dev3-ai-job-board' ),
			'remote' => __( 'Remote', 'dev3-ai-job-board' ),
		);
	}

	/**
	 * Labels for experience levels.
	 *
	 * @return array
	 */
	public static function experience_labels() {
		return array(
			'entry'     => __( 'Entry level', 'dev3-ai-job-board' ),
			'mid'       => __( 'Mid level', 'dev3-ai-job-board' ),
			'senior'    => __( 'Senior', 'dev3-ai-job-board' ),
			'lead'      => __( 'Lead / Principal', 'dev3-ai-job-board' ),
			'executive' => __( 'Executive', 'dev3-ai-job-board' ),
		);
	}

	/**
	 * Labels for salary periods.
	 *
	 * @return array
	 */
	public static function period_labels() {
		return array(
			'hour'  => __( 'per hour', 'dev3-ai-job-board' ),
			'day'   => __( 'per day', 'dev3-ai-job-board' ),
			'week'  => __( 'per week', 'dev3-ai-job-board' ),
			'month' => __( 'per month', 'dev3-ai-job-board' ),
			'year'  => __( 'per year', 'dev3-ai-job-board' ),
		);
	}

	/**
	 * Whether a post is a job.
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	public static function is_job( $id ) {
		return $id && Post_Types::JOB === get_post_type( $id );
	}

	/**
	 * Whether the user owns (or may manage) a job.
	 *
	 * @param int $job_id  Job.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function owner_can( $job_id, $user_id ) {
		$post = get_post( $job_id );
		if ( ! $post || Post_Types::JOB !== $post->post_type || ! $user_id ) {
			return false;
		}
		return (int) $post->post_author === (int) $user_id || user_can( $user_id, 'edit_others_posts' );
	}

	/**
	 * Term list of a post.
	 *
	 * @param int    $id       Post.
	 * @param string $taxonomy Taxonomy.
	 * @return array [ {id, name, slug} ]
	 */
	public static function post_terms( $id, $taxonomy ) {
		$terms = get_the_terms( $id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'id'   => (int) $t->term_id,
				'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'slug' => $t->slug,
			);
		}
		return $out;
	}

	/**
	 * Full job shape.
	 *
	 * @param int $id Job.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( $id );
		if ( ! $post || Post_Types::JOB !== $post->post_type ) {
			return null;
		}
		$m                   = static function ( $key, $fallback = '' ) use ( $id ) {
			$v = get_post_meta( $id, '_dev3jb_' . $key, true );
			return '' === $v || false === $v ? $fallback : $v;
		};
		$company             = Companies::summary( (int) $m( 'company', 0 ) );
		$min                 = $m( 'salary_min', '' );
		$max                 = $m( 'salary_max', '' );
		$job                 = array(
			'id'              => (int) $post->ID,
			'title'           => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'          => $post->post_status,
			'content'         => (string) $post->post_content,
			'excerpt'         => (string) $post->post_excerpt,
			'author'          => (int) $post->post_author,
			'company'         => $company,
			'company_id'      => $company ? $company['id'] : 0,
			'categories'      => self::post_terms( $id, Post_Types::CATEGORY ),
			'types'           => self::post_terms( $id, Post_Types::TYPE ),
			'locations'       => self::post_terms( $id, Post_Types::LOCATION ),
			'skills'          => self::post_terms( $id, Post_Types::SKILL ),
			'workplace'       => (string) $m( 'workplace', 'onsite' ),
			'remote_region'   => (string) $m( 'remote_region' ),
			'address'         => (string) $m( 'address' ),
			'salary_min'      => '' === $min ? null : (float) $min,
			'salary_max'      => '' === $max ? null : (float) $max,
			'salary_currency' => (string) $m( 'salary_currency', Settings::get( 'currency', 'USD' ) ),
			'salary_period'   => (string) $m( 'salary_period', 'year' ),
			'salary_hidden'   => (bool) $m( 'salary_hidden', false ),
			'experience'      => (string) $m( 'experience' ),
			'apply_method'    => (string) $m( 'apply_method', 'internal' ),
			'apply_url'       => (string) $m( 'apply_url' ),
			'apply_email'     => (string) $m( 'apply_email' ),
			'expires'         => (string) $m( 'expires' ),
			'deadline'        => (string) $m( 'deadline' ),
			'featured'        => (bool) $m( 'featured', false ),
			'filled'          => (bool) $m( 'filled', false ),
			'views'           => (int) $m( 'views', 0 ),
			'user_package'    => (int) $m( 'user_package', 0 ),
			'date'            => self::post_date_gmt( $post ),
			'modified'        => get_post_modified_time( 'Y-m-d H:i:s', true, $post ),
			'url'             => (string) get_permalink( $post ),
		);
		$job['applications'] = Applications::count_for_job( $job['id'] );
		$job['salary_text']  = self::salary_text( $job );
		/**
		 * Filters the job shape used everywhere (REST, templates, schema).
		 *
		 * @param array $job Job.
		 */
		return apply_filters( 'dev3jb_job', $job );
	}

	/**
	 * GMT post date (drafts and pending posts have no GMT date yet).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function post_date_gmt( $post ) {
		$gmt = get_post_time( 'Y-m-d H:i:s', true, $post );
		return $gmt ? (string) $gmt : get_gmt_from_date( $post->post_date );
	}

	/**
	 * Display salary ("$80,000 – $95,000 per year").
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function salary_text( array $job ) {
		if ( ! empty( $job['salary_hidden'] ) || ( null === $job['salary_min'] && null === $job['salary_max'] ) ) {
			return '';
		}
		$cur    = $job['salary_currency'];
		$period = self::period_labels();
		$per    = isset( $period[ $job['salary_period'] ] ) ? ' ' . $period[ $job['salary_period'] ] : '';
		$min    = null !== $job['salary_min'] ? self::money( $job['salary_min'], $cur ) : '';
		$max    = null !== $job['salary_max'] ? self::money( $job['salary_max'], $cur ) : '';
		if ( $min && $max && $min !== $max ) {
			return $min . ' – ' . $max . $per;
		}
		if ( $min && ! $max ) {
			/* translators: %s: amount */
			return sprintf( __( 'From %s', 'dev3-ai-job-board' ), $min ) . $per;
		}
		if ( $max && ! $min ) {
			/* translators: %s: amount */
			return sprintf( __( 'Up to %s', 'dev3-ai-job-board' ), $max ) . $per;
		}
		return ( $min ? $min : $max ) . $per;
	}

	/**
	 * Money string.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		$currency = $currency ? strtoupper( $currency ) : (string) Settings::get( 'currency', 'USD' );
		$symbols  = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'JPY' => '¥',
			'INR' => '₹',
			'AUD' => 'A$',
			'CAD' => 'C$',
			'AED' => 'AED ',
			'KRW' => '₩',
			'CHF' => 'CHF ',
			'SGD' => 'S$',
		);
		$decimals = floor( (float) $amount ) === (float) $amount ? 0 : 2;
		$num      = number_format_i18n( (float) $amount, $decimals );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $num : $num . ' ' . $currency;
	}

	/**
	 * Sanitizes job input (from admin, employer REST or the submission form).
	 *
	 * @param array $in Raw input.
	 * @return array Clean values (only keys that were present).
	 */
	public static function sanitize( array $in ) {
		$out = array();
		if ( isset( $in['title'] ) ) {
			$out['title'] = mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 160 );
		}
		if ( isset( $in['content'] ) ) {
			$out['content'] = wp_kses_post( (string) $in['content'] );
		}
		if ( isset( $in['excerpt'] ) ) {
			$out['excerpt'] = mb_substr( sanitize_textarea_field( (string) $in['excerpt'] ), 0, 300 );
		}
		if ( isset( $in['company_id'] ) ) {
			$cid               = (int) $in['company_id'];
			$out['company_id'] = $cid && Post_Types::COMPANY === get_post_type( $cid ) ? $cid : 0;
		}
		foreach ( array( 'categories', 'types', 'locations' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = array_values( array_filter( array_map( 'intval', (array) $in[ $key ] ) ) );
			}
		}
		if ( isset( $in['skills'] ) ) {
			$skills = is_array( $in['skills'] ) ? $in['skills'] : explode( ',', (string) $in['skills'] );
			$clean  = array();
			foreach ( $skills as $s ) {
				$s = is_array( $s ) && isset( $s['name'] ) ? $s['name'] : $s;
				$s = trim( mb_substr( sanitize_text_field( (string) $s ), 0, 40 ) );
				if ( '' !== $s && ! isset( $clean[ mb_strtolower( $s ) ] ) ) {
					$clean[ mb_strtolower( $s ) ] = $s;
				}
			}
			$out['skills'] = array_slice( array_values( $clean ), 0, 30 );
		}
		if ( isset( $in['workplace'] ) ) {
			$out['workplace'] = in_array( $in['workplace'], self::WORKPLACES, true ) ? $in['workplace'] : 'onsite';
		}
		foreach ( array( 'remote_region', 'address' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, 160 );
			}
		}
		foreach ( array( 'salary_min', 'salary_max' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$v           = is_string( $in[ $key ] ) ? preg_replace( '/[^0-9.]/', '', $in[ $key ] ) : $in[ $key ];
				$out[ $key ] = ( '' === $v || null === $v ) ? '' : max( 0, round( (float) $v, 2 ) );
			}
		}
		if ( isset( $out['salary_min'], $out['salary_max'] ) && '' !== $out['salary_min'] && '' !== $out['salary_max'] && $out['salary_min'] > $out['salary_max'] ) {
			$tmp               = $out['salary_min'];
			$out['salary_min'] = $out['salary_max'];
			$out['salary_max'] = $tmp;
		}
		if ( isset( $in['salary_currency'] ) ) {
			$cur                    = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['salary_currency'] ) );
			$out['salary_currency'] = 3 === strlen( $cur ) ? $cur : (string) Settings::get( 'currency', 'USD' );
		}
		if ( isset( $in['salary_period'] ) ) {
			$out['salary_period'] = in_array( $in['salary_period'], self::PERIODS, true ) ? $in['salary_period'] : 'year';
		}
		if ( isset( $in['experience'] ) ) {
			$out['experience'] = in_array( $in['experience'], self::EXPERIENCE, true ) ? $in['experience'] : '';
		}
		if ( isset( $in['apply_method'] ) ) {
			$out['apply_method'] = in_array( $in['apply_method'], self::APPLY_TYPES, true ) ? $in['apply_method'] : 'internal';
		}
		if ( isset( $in['apply_url'] ) ) {
			$out['apply_url'] = esc_url_raw( (string) $in['apply_url'], array( 'http', 'https' ) );
		}
		if ( isset( $in['apply_email'] ) ) {
			$out['apply_email'] = sanitize_email( (string) $in['apply_email'] );
		}
		foreach ( array( 'expires', 'deadline' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = self::date_or_empty( (string) $in[ $key ] );
			}
		}
		foreach ( array( 'salary_hidden', 'featured', 'filled' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = (bool) $in[ $key ] && 'false' !== $in[ $key ] && '0' !== $in[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Y-m-d or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function date_or_empty( $value ) {
		$value = substr( trim( $value ), 0, 10 );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && strtotime( $value ) ? $value : '';
	}

	/**
	 * Validates a clean job for saving.
	 *
	 * @param array $data Clean data.
	 * @param bool  $is_new New job.
	 * @return true|\WP_Error
	 */
	public static function validate( array $data, $is_new ) {
		if ( $is_new && empty( $data['title'] ) ) {
			return new \WP_Error( 'dev3jb_title', __( 'Add a job title.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['title'] ) && '' === $data['title'] ) {
			return new \WP_Error( 'dev3jb_title', __( 'Add a job title.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['apply_method'] ) && 'url' === $data['apply_method'] && empty( $data['apply_url'] ) ) {
			return new \WP_Error( 'dev3jb_apply_url', __( 'Add the application link, or choose another way to apply.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['apply_method'] ) && 'email' === $data['apply_method'] && empty( $data['apply_email'] ) ) {
			return new \WP_Error( 'dev3jb_apply_email', __( 'Add the email address for applications.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Creates or updates a job.
	 *
	 * @param array  $data   Clean data (from sanitize()).
	 * @param int    $id     Job id (0 = new).
	 * @param int    $author Author for new jobs.
	 * @param string $status Status for new jobs (or forced status).
	 * @return int|\WP_Error
	 */
	public static function save( array $data, $id = 0, $author = 0, $status = '' ) {
		$is_new = ! $id;
		if ( ! $is_new && ! self::is_job( $id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$valid = self::validate( $data, $is_new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$post = array( 'post_type' => Post_Types::JOB );
		if ( isset( $data['title'] ) ) {
			$post['post_title'] = $data['title'];
		}
		if ( isset( $data['content'] ) ) {
			$post['post_content'] = $data['content'];
		}
		if ( isset( $data['excerpt'] ) ) {
			$post['post_excerpt'] = $data['excerpt'];
		}
		if ( $status ) {
			$post['post_status'] = $status;
		}
		if ( $is_new ) {
			$post['post_author'] = $author ? (int) $author : get_current_user_id();
			if ( ! $status ) {
				$post['post_status'] = 'draft';
			}
			$id = wp_insert_post( wp_slash( $post ), true );
		} else {
			$post['ID'] = (int) $id;
			$id         = wp_update_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$tax = array(
			'categories' => Post_Types::CATEGORY,
			'types'      => Post_Types::TYPE,
			'locations'  => Post_Types::LOCATION,
		);
		foreach ( $tax as $key => $taxonomy ) {
			if ( isset( $data[ $key ] ) ) {
				wp_set_object_terms( $id, $data[ $key ], $taxonomy );
			}
		}
		if ( isset( $data['skills'] ) ) {
			wp_set_object_terms( $id, $data['skills'], Post_Types::SKILL );
		}
		$meta = array( 'company_id' => 'company', 'workplace', 'remote_region', 'address', 'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'experience', 'apply_method', 'apply_url', 'apply_email', 'expires', 'deadline' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		foreach ( $meta as $key => $name ) {
			$field = is_string( $key ) ? $key : $name;
			if ( array_key_exists( $field, $data ) ) {
				update_post_meta( $id, '_dev3jb_' . $name, $data[ $field ] );
			}
		}
		foreach ( array( 'salary_hidden', 'featured', 'filled' ) as $flag ) {
			if ( isset( $data[ $flag ] ) ) {
				update_post_meta( $id, '_dev3jb_' . $flag, $data[ $flag ] ? 1 : 0 );
			}
		}
		if ( $is_new && ! isset( $data['featured'] ) ) {
			update_post_meta( $id, '_dev3jb_featured', 0 );
		}
		if ( $is_new && ! isset( $data['workplace'] ) ) {
			update_post_meta( $id, '_dev3jb_workplace', 'onsite' );
		}
		/**
		 * Fires after a job is saved by DEV3 AI Job Board.
		 *
		 * @param int   $id     Job.
		 * @param array $data   Clean data.
		 * @param bool  $is_new New.
		 */
		do_action( 'dev3jb_job_saved', (int) $id, $data, $is_new );
		return (int) $id;
	}

	/**
	 * On publish: default expiry, notify listeners.
	 *
	 * @param string   $new_status New.
	 * @param string   $old_status Old.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( Post_Types::JOB !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$expires = (string) get_post_meta( $post->ID, '_dev3jb_expires', true );
			if ( '' === $expires || strtotime( $expires . ' 23:59:59' ) < time() ) {
				$pkg  = UserPackages::get( (int) get_post_meta( $post->ID, '_dev3jb_user_package', true ) );
				$days = $pkg ? (int) $pkg['duration_days'] : (int) Settings::get( 'job_duration', 30 );
				self::set_expiry( $post->ID, $days );
			}
			delete_post_meta( $post->ID, '_dev3jb_expiry_notified' );
			/**
			 * Fires when a job goes live.
			 *
			 * @param int    $job_id     Job.
			 * @param string $old_status Previous status.
			 */
			do_action( 'dev3jb_job_published', (int) $post->ID, $old_status );
		}
		if ( 'pending' === $new_status ) {
			do_action( 'dev3jb_job_pending', (int) $post->ID );
		}
		if ( Post_Types::EXPIRED === $new_status ) {
			do_action( 'dev3jb_job_expired', (int) $post->ID );
		}
	}

	/**
	 * Sets the expiry date N days from today.
	 *
	 * @param int $id   Job.
	 * @param int $days Days.
	 * @return string Y-m-d.
	 */
	public static function set_expiry( $id, $days ) {
		$date = gmdate( 'Y-m-d', time() + max( 1, (int) $days ) * DAY_IN_SECONDS );
		update_post_meta( $id, '_dev3jb_expires', $date );
		return $date;
	}

	/**
	 * Status the job gets after its package is ready (moderation setting).
	 *
	 * @param int $id Job.
	 * @return string
	 */
	public static function submit_status( $id ) {
		unset( $id );
		return 'publish' === Settings::get( 'submission_moderation', 'pending' ) ? 'publish' : 'pending';
	}

	/**
	 * Attaches a user package to a job (consumes a credit) and moves it to its submit status.
	 *
	 * @param int   $job_id  Job.
	 * @param array $package User package (active, with credits).
	 * @return string|\WP_Error New status.
	 */
	public static function apply_package( $job_id, array $package ) {
		if ( ! self::is_job( $job_id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( (int) get_post_meta( $job_id, '_dev3jb_user_package', true ) !== (int) $package['id'] ) {
			$used = UserPackages::consume( $package['id'] );
			if ( is_wp_error( $used ) ) {
				return $used;
			}
			update_post_meta( $job_id, '_dev3jb_user_package', (int) $package['id'] );
		}
		update_post_meta( $job_id, '_dev3jb_featured', $package['featured'] ? 1 : 0 );
		$current = get_post_status( $job_id );
		$status  = 'publish' === $current ? 'publish' : self::submit_status( $job_id );
		if ( 'publish' === $current ) {
			self::set_expiry( $job_id, (int) $package['duration_days'] );
		}
		wp_update_post(
			array(
				'ID'          => $job_id,
				'post_status' => $status,
			)
		);
		return $status;
	}

	/**
	 * Relists an expired (or filled) job with a package credit.
	 *
	 * @param int   $job_id  Job.
	 * @param array $package User package.
	 * @return string|\WP_Error
	 */
	public static function relist( $job_id, array $package ) {
		if ( ! self::is_job( $job_id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$used = UserPackages::consume( $package['id'] );
		if ( is_wp_error( $used ) ) {
			return $used;
		}
		update_post_meta( $job_id, '_dev3jb_user_package', (int) $package['id'] );
		update_post_meta( $job_id, '_dev3jb_filled', 0 );
		update_post_meta( $job_id, '_dev3jb_featured', $package['featured'] ? 1 : 0 );
		self::set_expiry( $job_id, (int) $package['duration_days'] );
		$status = self::submit_status( $job_id );
		wp_update_post(
			array(
				'ID'          => $job_id,
				'post_status' => $status,
			)
		);
		return $status;
	}

	/**
	 * Daily: expire jobs past their date and warn employers N days before.
	 *
	 * @param int $now Time (tests).
	 * @return array { expired, notified }
	 */
	public static function check_expiry( $now = 0 ) {
		$now   = $now ? $now : time();
		$today = gmdate( 'Y-m-d', $now );
		$ids   = get_posts(
			array(
				'post_type'      => Post_Types::JOB,
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_dev3jb_expires',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_dev3jb_expires',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => Post_Types::EXPIRED,
				)
			);
		}
		$notified = 0;
		$days     = (int) Settings::get( 'expiry_notice_days', 5 );
		if ( $days > 0 ) {
			$soon = get_posts(
				array(
					'post_type'      => Post_Types::JOB,
					'post_status'    => 'publish',
					'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_dev3jb_expires',
							'value'   => array( $today, gmdate( 'Y-m-d', $now + $days * DAY_IN_SECONDS ) ),
							'compare' => 'BETWEEN',
							'type'    => 'DATE',
						),
						array(
							'key'     => '_dev3jb_expiry_notified',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			foreach ( $soon as $id ) {
				update_post_meta( $id, '_dev3jb_expiry_notified', 1 );
				do_action( 'dev3jb_job_expiring', (int) $id );
				++$notified;
			}
		}
		return array(
			'expired'  => count( $ids ),
			'notified' => $notified,
		);
	}

	/**
	 * Search for the public listing and the admin table.
	 *
	 * @param array $args { q, category, type, location, workplace (string|array), salary_min, posted (days), company, featured, status, author, page, per_page, orderby (date|salary|title), order, include_filled }.
	 * @return array { items, total, pages, page }
	 */
	public static function search( array $args ) {
		$per_page = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : (int) Settings::get( 'per_page', 10 );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query    = array(
			'post_type'      => Post_Types::JOB,
			'post_status'    => isset( $args['status'] ) && '' !== $args['status'] ? $args['status'] : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'tax_query'      => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( ! empty( $args['q'] ) ) {
			$query['s'] = mb_substr( (string) $args['q'], 0, 120 );
		}
		$tax_map = array(
			'category' => Post_Types::CATEGORY,
			'type'     => Post_Types::TYPE,
			'location' => Post_Types::LOCATION,
			'skill'    => Post_Types::SKILL,
		);
		foreach ( $tax_map as $key => $taxonomy ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$values = array_filter( array_map( 'sanitize_title', is_array( $args[ $key ] ) ? $args[ $key ] : explode( ',', (string) $args[ $key ] ) ) );
			if ( $values ) {
				$query['tax_query'][] = array(
					'taxonomy'         => $taxonomy,
					'field'            => 'slug',
					'terms'            => array_values( $values ),
					'include_children' => true,
				);
			}
		}
		if ( ! empty( $args['workplace'] ) ) {
			$wp = array_values( array_intersect( self::WORKPLACES, is_array( $args['workplace'] ) ? $args['workplace'] : explode( ',', (string) $args['workplace'] ) ) );
			if ( $wp ) {
				$query['meta_query'][] = array(
					'key'     => '_dev3jb_workplace',
					'value'   => $wp,
					'compare' => 'IN',
				);
			}
		}
		if ( ! empty( $args['salary_min'] ) ) {
			$query['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => '_dev3jb_salary_max',
					'value'   => (float) $args['salary_min'],
					'compare' => '>=',
					'type'    => 'DECIMAL(14,2)',
				),
				array(
					'key'     => '_dev3jb_salary_min',
					'value'   => (float) $args['salary_min'],
					'compare' => '>=',
					'type'    => 'DECIMAL(14,2)',
				),
			);
		}
		if ( ! empty( $args['company'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_dev3jb_company',
				'value' => (int) $args['company'],
			);
		}
		if ( ! empty( $args['featured'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_dev3jb_featured',
				'value' => 1,
			);
		}
		if ( empty( $args['include_filled'] ) && 'publish' === $query['post_status'] && ! empty( $args['hide_filled'] ) ) {
			$query['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => '_dev3jb_filled',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_dev3jb_filled',
					'value' => 0,
				),
			);
		}
		if ( ! empty( $args['posted'] ) ) {
			$query['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', time() - (int) $args['posted'] * DAY_IN_SECONDS ),
					'column'    => 'post_date_gmt',
					'inclusive' => true,
				),
			);
		}
		if ( ! empty( $args['author'] ) ) {
			$query['author'] = (int) $args['author'];
		}
		$order   = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'date';
		if ( 'salary' === $orderby ) {
			$query['meta_key'] = '_dev3jb_salary_max'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query['orderby']  = array(
				'meta_value_num' => $order,
				'date'           => 'DESC',
			);
		} elseif ( 'title' === $orderby ) {
			$query['orderby'] = array( 'title' => $order );
		} elseif ( ! empty( $args['featured_first'] ) ) {
			// Every job saved through Listings::save() has the meta (0 or 1), so the named clause keeps them all.
			$query['meta_query']['featured_order'] = array(
				'key'     => '_dev3jb_featured',
				'type'    => 'NUMERIC',
				'compare' => 'EXISTS',
			);
			$query['orderby']                      = array(
				'featured_order' => 'DESC',
				'date'           => $order,
			);
		} else {
			$query['orderby'] = array( 'date' => $order );
		}
		if ( count( $query['tax_query'] ) > 1 ) {
			$query['tax_query']['relation'] = 'AND';
		}
		/**
		 * Filters the WP_Query args of a job search.
		 *
		 * @param array $query Query args.
		 * @param array $args  Search args.
		 */
		$query = apply_filters( 'dev3jb_search_query', $query, $args );
		$q     = new \WP_Query( $query );
		$items = array();
		foreach ( $q->posts as $post ) {
			$job = self::get( $post->ID );
			if ( $job ) {
				$items[] = $job;
			}
		}
		return array(
			'items' => $items,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * Status counts for the admin tabs.
	 *
	 * @param int $author Author filter.
	 * @return array
	 */
	public static function counts( $author = 0 ) {
		$out = array();
		foreach ( array( 'publish', 'pending', 'draft', Post_Types::UNPAID, Post_Types::EXPIRED ) as $status ) {
			$q              = new \WP_Query(
				array(
					'post_type'      => Post_Types::JOB,
					'post_status'    => $status,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'author'         => $author ? (int) $author : '',
				)
			);
			$out[ $status ] = (int) $q->found_posts;
		}
		return $out;
	}

	/**
	 * Plain-text summary of a job for AI prompts and matching (no employer contact data).
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function to_text( array $job ) {
		$lines   = array( 'Title: ' . $job['title'] );
		$types   = wp_list_pluck( $job['types'], 'name' );
		$cats    = wp_list_pluck( $job['categories'], 'name' );
		$locs    = wp_list_pluck( $job['locations'], 'name' );
		$skills  = wp_list_pluck( $job['skills'], 'name' );
		$lines[] = 'Workplace: ' . $job['workplace'] . ( $job['remote_region'] ? ' (' . $job['remote_region'] . ')' : '' );
		if ( $types ) {
			$lines[] = 'Type: ' . implode( ', ', $types );
		}
		if ( $cats ) {
			$lines[] = 'Category: ' . implode( ', ', $cats );
		}
		if ( $locs ) {
			$lines[] = 'Location: ' . implode( ', ', $locs );
		}
		if ( $job['experience'] ) {
			$lines[] = 'Experience level: ' . $job['experience'];
		}
		if ( $skills ) {
			$lines[] = 'Skills: ' . implode( ', ', $skills );
		}
		$lines[] = 'Description: ' . mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $job['content'] ) ) ), 0, 5000 );
		return implode( "\n", $lines );
	}
}
