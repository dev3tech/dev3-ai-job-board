<?php
/**
 * Public routes: job search, apply, save, alerts, Stripe webhook.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Rest;

use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Billing\Stripe;
use Dev3\AiJobBoard\Core3\Core\Jobs;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Saved;
use Dev3\AiJobBoard\Front\Templates;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jb/v1.
 */
class Public_Controller extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/listings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'jobs', 60 ),
			)
		);
		register_rest_route(
			self::NS,
			'/listings/(?P<id>\d+)/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_apply' ),
			)
		);
		register_rest_route(
			self::NS,
			'/listings/(?P<id>\d+)/save',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'logged_in' ),
			)
		);
		register_rest_route(
			self::NS,
			'/alerts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'alert' ),
				'permission_callback' => array( $this, 'can_alert' ),
			)
		);
		register_rest_route(
			self::NS,
			'/stripe/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( Stripe::class, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Apply permission: logged in with a nonce, or a guest (setting) through the rate-limited public check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_apply( \WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return $this->logged_in( $request );
		}
		if ( ! Settings::get( 'guest_apply', true ) ) {
			return new \WP_Error( 'dev3jb_login', __( 'Log in to apply for this job.', 'dev3-ai-job-board' ), array( 'status' => 401 ) );
		}
		return $this->public_permission( $request, 'apply', 5 );
	}

	/**
	 * Alert permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_alert( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'alerts_enabled', true ) ) {
			return new \WP_Error( 'dev3jb_alerts_off', __( 'Job alerts are turned off.', 'dev3-ai-job-board' ), array( 'status' => 403 ) );
		}
		return is_user_logged_in() ? $this->logged_in( $request ) : $this->public_permission( $request, 'alert', 5 );
	}

	/**
	 * GET /listings: search (JSON, plus rendered HTML for the board when html=1).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ) {
		$str  = static function ( $key ) use ( $request ) {
			$v = $request->get_param( $key );
			return is_array( $v ) ? implode( ',', array_map( 'sanitize_text_field', $v ) ) : sanitize_text_field( (string) $v );
		};
		$sort = $str( 'sort' );
		$args = array(
			'q'          => mb_substr( $str( 'q' ), 0, 120 ),
			'category'   => $str( 'category' ),
			'type'       => $str( 'type' ),
			'location'   => $str( 'location' ),
			'workplace'  => $str( 'workplace' ),
			'salary_min' => (int) $request->get_param( 'salary_min' ),
			'posted'     => (int) $request->get_param( 'posted' ),
			'orderby'    => in_array( $sort, array( 'date', 'salary' ), true ) ? $sort : 'date',
			'page'       => max( 1, (int) $request->get_param( 'pg' ) ),
			'per_page'   => $request->get_param( 'per_page' ) ? min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) ) : (int) Settings::get( 'per_page', 10 ),
		);
		$res  = Listings::search( $args + array( 'featured_first' => 'date' === $args['orderby'] ) );
		$out  = array(
			'total' => $res['total'],
			'pages' => $res['pages'],
			'page'  => $res['page'],
			'items' => array_map( array( __CLASS__, 'public_job' ), $res['items'] ),
		);
		if ( $request->get_param( 'html' ) ) {
			$base         = esc_url_raw( (string) $request->get_param( 'base' ) );
			$args['base'] = '' !== $base && wp_validate_redirect( $base, '' ) ? $base : Settings::page_url( 'jobs' );
			$out['html']  = Templates::results_html( $res, $args );
		}
		return $this->respond( $out );
	}

	/**
	 * Public job shape (no internal fields).
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function public_job( array $job ) {
		return array(
			'id'         => $job['id'],
			'title'      => $job['title'],
			'url'        => $job['url'],
			'company'    => $job['company'] ? array(
				'name' => $job['company']['name'],
				'logo' => $job['company']['logo'],
				'url'  => $job['company']['url'],
			) : null,
			'excerpt'    => $job['excerpt'],
			'locations'  => wp_list_pluck( $job['locations'], 'name' ),
			'types'      => wp_list_pluck( $job['types'], 'name' ),
			'categories' => wp_list_pluck( $job['categories'], 'name' ),
			'skills'     => wp_list_pluck( $job['skills'], 'name' ),
			'workplace'  => $job['workplace'],
			'salary'     => $job['salary_text'],
			'featured'   => $job['featured'],
			'filled'     => $job['filled'],
			'date'       => $job['date'],
			'expires'    => $job['expires'],
		);
	}

	/**
	 * POST /listings/<id>/apply (multipart: name, email, phone, cover_letter, consent, resume file).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( \WP_REST_Request $request ) {
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_spam', __( 'Your submission looks like spam.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		if ( ! $request->get_param( 'consent' ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_consent', __( 'Please confirm that we may share your application with the employer.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		$job_id = (int) $request['id'];
		$job    = Listings::get( $job_id );
		if ( ! $job ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$open = Applications::can_apply( $job );
		if ( is_wp_error( $open ) ) {
			return $this->respond( $open );
		}
		$user  = get_current_user_id();
		$email = (string) $request->get_param( 'email' );
		$name  = (string) $request->get_param( 'name' );
		if ( $user ) {
			$wp_user = wp_get_current_user();
			$email   = $wp_user->user_email;
			$name    = '' !== trim( $name ) ? $name : $wp_user->display_name;
		}
		$file = $this->store_upload( $request, 'resume', $user, $user ? 'resume' : 'attachment' );
		if ( is_wp_error( $file ) ) {
			return $this->respond( $file );
		}
		$app = Applications::create(
			$job_id,
			array(
				'name'         => $name,
				'email'        => $email,
				'phone'        => (string) $request->get_param( 'phone' ),
				'cover_letter' => (string) $request->get_param( 'cover_letter' ),
				'candidate_id' => $user,
				'file_id'      => $file ? (int) $file['id'] : 0,
				'source'       => 'site',
			)
		);
		if ( is_wp_error( $app ) ) {
			if ( $file ) {
				\Dev3\AiJobBoard\Data\Files::delete( $file['id'] );
			}
			return $this->respond( $app );
		}
		if ( Settings::get( 'ai_match_on_apply', true ) && Settings::get( 'match_for_employers', true ) ) {
			// A transparent rule-based score right away; the DEV3 AI explanation runs in the background.
			Matcher::score_application( $app['id'], false );
			if ( Matcher::ai_available( 'cron' ) ) {
				Jobs::create( Matcher::JOB, array( (int) $app['id'] ), array( 'use_ai' => true ), 1 );
			}
		}
		return $this->respond(
			array(
				'ok'      => true,
				'message' => __( 'Your application was sent. Good luck!', 'dev3-ai-job-board' ),
			),
			201
		);
	}

	/**
	 * POST /listings/<id>/save { saved? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Listings::is_job( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$want = $request->get_param( 'saved' );
		return $this->respond( array( 'saved' => Saved::set( get_current_user_id(), $id, null === $want ? null : (bool) $want ) ) );
	}

	/**
	 * POST /alerts { query, email, frequency }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alert( \WP_REST_Request $request ) {
		$user  = wp_get_current_user();
		$email = $user->exists() ? $user->user_email : (string) $request->get_param( 'email' );
		$query = $request->get_param( 'query' );
		$res   = Alerts::create( is_array( $query ) ? $query : array(), $email, $user->exists() ? $user->ID : 0, (string) $request->get_param( 'frequency' ) );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		return $this->respond(
			array(
				'id'        => $res['id'],
				'label'     => $res['label'],
				'confirmed' => $res['confirmed'],
			),
			201
		);
	}
}
