<?php
/**
 * Employer routes (front-end dashboard): pipeline, application detail, notes, match scores, writing assistant.
 * Every route checks that the employer owns the job behind the request.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Rest;

use Dev3\AiJobBoard\Ai\Describer;
use Dev3\AiJobBoard\Ai\Inclusive;
use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Core3\Core\Jobs;
use Dev3\AiJobBoard\Core3\Core\Rate_Limiter;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Emails\Mailer;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jb/v1/employer.
 */
class Employer_Controller extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			'/employer/jobs'                           => array( 'GET', 'jobs' ),
			'/employer/applications'                   => array( 'GET', 'applications' ),
			'/employer/applications/(?P<id>\d+)'       => array( 'GET,POST', 'application' ),
			'/employer/applications/(?P<id>\d+)/notes' => array( 'POST', 'note' ),
			'/employer/applications/(?P<id>\d+)/match' => array( 'POST', 'match' ),
			'/employer/jobs/(?P<id>\d+)/score'         => array( 'POST', 'score_all' ),
			'/employer/queue/(?P<id>\d+)'              => array( 'GET', 'queue' ),
			'/employer/companies'                      => array( 'GET', 'companies' ),
			'/employer/ai/describe'                    => array( 'POST', 'describe' ),
			'/employer/ai/inclusive'                   => array( 'POST', 'inclusive' ),
		);
		foreach ( $routes as $path => $conf ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $conf[0],
					'callback'            => array( $this, $conf[1] ),
					'permission_callback' => array( $this, 'logged_in' ),
				)
			);
		}
	}

	/**
	 * Application the current user may manage, or an error.
	 *
	 * @param int $id Application.
	 * @return array|\WP_Error
	 */
	private function owned_app( $id ) {
		$app = Applications::get( $id );
		if ( ! $app || ! Applications::employer_can( $app, get_current_user_id() ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Application not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		return $app;
	}

	/**
	 * Light card for the pipeline.
	 *
	 * @param array $app  Application.
	 * @param bool  $hide Hide names.
	 * @return array
	 */
	public static function card( array $app, $hide = false ) {
		$profile = $app['profile'];
		// Blind screening: names stay hidden until the application reaches the interview stage.
		$hide = $hide && in_array( $app['stage'], array( 'new', 'screening' ), true );
		return array(
			'id'          => $app['id'],
			'name'        => $hide ? sprintf( /* translators: %d: application id */ __( 'Candidate #%d', 'dev3-ai-job-board' ), $app['id'] ) : $app['name'],
			'headline'    => $app['headline'],
			'stage'       => $app['stage'],
			'position'    => $app['position'],
			'rating'      => $app['rating'],
			'match_score' => Settings::get( 'match_for_employers', true ) ? $app['match_score'] : null,
			'created_at'  => $app['created_at'],
			'has_file'    => (bool) $app['file_id'],
			'years'       => $profile ? (float) $profile['years'] : null,
			'skills'      => $profile ? array_slice( (array) $profile['skills'], 0, 4 ) : array(),
		);
	}

	/**
	 * GET /employer/jobs: the employer's jobs (for pickers).
	 *
	 * @return \WP_REST_Response
	 */
	public function jobs() {
		$res = Listings::search(
			array(
				'author'   => get_current_user_id(),
				'status'   => array( 'publish', 'pending', 'draft', 'dev3jb_unpaid', 'dev3jb_expired' ),
				'per_page' => 100,
			)
		);
		$out = array();
		foreach ( $res['items'] as $job ) {
			$out[] = array(
				'id'           => $job['id'],
				'title'        => $job['title'],
				'status'       => $job['status'],
				'applications' => $job['applications'],
				'url'          => $job['url'],
			);
		}
		return $this->respond( $out );
	}

	/**
	 * GET /employer/applications?job=: pipeline cards + counts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function applications( \WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'job' );
		if ( ! Listings::owner_can( $job_id, get_current_user_id() ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$list = Applications::query(
			array(
				'job_id'   => $job_id,
				'per_page' => 500,
				'orderby'  => 'position',
			)
		);
		$hide = (bool) Settings::get( 'hide_names_in_pipeline', false );
		return $this->respond(
			array(
				'items'  => array_map( static fn( $a ) => self::card( $a, $hide ), $list['items'] ),
				'counts' => Applications::stage_counts( array( 'job_id' => $job_id ) ),
				'job'    => array(
					'id'    => $job_id,
					'title' => html_entity_decode( get_the_title( $job_id ), ENT_QUOTES, 'UTF-8' ),
				),
			)
		);
	}

	/**
	 * GET|POST /employer/applications/<id> (POST: stage, index, rating, notify, message).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function application( \WP_REST_Request $request ) {
		$app = $this->owned_app( (int) $request['id'] );
		if ( is_wp_error( $app ) ) {
			return $this->respond( $app );
		}
		if ( 'POST' === $request->get_method() ) {
			$res = self::update_application( $app, $request );
			if ( is_wp_error( $res ) ) {
				return $this->respond( $res );
			}
			$app = $res;
		}
		return $this->respond( self::detail( $app ) );
	}

	/**
	 * Applies stage/rating changes (shared with the admin controller).
	 *
	 * @param array            $app     Application.
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function update_application( array $app, \WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'rating' ) ) {
			$app = Applications::rate( $app['id'], (int) $request->get_param( 'rating' ) );
		}
		$stage = $request->get_param( 'stage' );
		if ( null !== $stage && '' !== $stage ) {
			$index = null === $request->get_param( 'index' ) ? -1 : (int) $request->get_param( 'index' );
			$from  = $app['stage'];
			$app   = Applications::move( $app['id'], sanitize_key( (string) $stage ), $index, get_current_user_id() );
			if ( is_wp_error( $app ) ) {
				return $app;
			}
			if ( $from !== $app['stage'] && $request->get_param( 'notify' ) ) {
				Mailer::stage_changed( $app, sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
				$app = Applications::get( $app['id'] );
			}
		}
		return $app;
	}

	/**
	 * Full detail for the drawer.
	 *
	 * @param array $app Application.
	 * @return array
	 */
	public static function detail( array $app ) {
		$hide         = (bool) Settings::get( 'hide_names_in_pipeline', false ) && ! current_user_can( 'manage_options' );
		$out          = $app;
		$out['card']  = self::card( $app, $hide );
		$out['match'] = Settings::get( 'match_for_employers', true ) || current_user_can( 'manage_options' ) ? $app['match'] : null;
		$hide         = $hide && in_array( $app['stage'], array( 'new', 'screening' ), true );
		if ( $hide ) {
			$out['name']  = $out['card']['name'];
			$out['email'] = '';
			$out['phone'] = '';
			$out['blind'] = true;
		}
		if ( $out['profile'] ) {
			unset( $out['profile']['email'], $out['profile']['user_id'], $out['profile']['file_id'], $out['profile']['visible'], $out['profile']['name'], $out['profile']['phone'] );
			if ( $hide ) {
				$out['profile']['links'] = array();
			}
		}
		$out['job_url'] = (string) get_permalink( $app['job_id'] );
		$out['ai']      = Matcher::ai_available( 'frontend' );
		return $out;
	}

	/**
	 * POST /employer/applications/<id>/notes { note }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function note( \WP_REST_Request $request ) {
		$app = $this->owned_app( (int) $request['id'] );
		if ( is_wp_error( $app ) ) {
			return $this->respond( $app );
		}
		$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		if ( '' === $note ) {
			return $this->respond( new \WP_Error( 'dev3jb_note', __( 'Write a note first.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		Applications::add_event( $app['id'], get_current_user_id(), 'note', $note );
		return $this->respond( Applications::events( $app['id'] ) );
	}

	/**
	 * POST /employer/applications/<id>/match { ai }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function match( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'match_for_employers', true ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_match_off', __( 'Match scores are turned off on this site.', 'dev3-ai-job-board' ), array( 'status' => 403 ) ) );
		}
		$app = $this->owned_app( (int) $request['id'] );
		if ( is_wp_error( $app ) ) {
			return $this->respond( $app );
		}
		if ( ! Rate_Limiter::hit( 'employer_match', (string) get_current_user_id(), 30 ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-job-board' ), array( 'status' => 429 ) ) );
		}
		$res = Matcher::score_application( $app['id'], (bool) $request->get_param( 'ai' ), 'frontend' );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		return $this->respond( self::detail( Applications::get( $app['id'] ) ) );
	}

	/**
	 * POST /employer/jobs/<id>/score { ai }: queue scoring of all applications.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function score_all( \WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		if ( ! Settings::get( 'match_for_employers', true ) || ! Listings::owner_can( $job_id, get_current_user_id() ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$use_ai = (bool) $request->get_param( 'ai' ) && Matcher::ai_available( 'cron' );
		if ( ! $use_ai ) {
			// Rule-based scores are instant: run them inline.
			$list = Applications::query(
				array(
					'job_id'   => $job_id,
					'per_page' => 500,
				)
			);
			foreach ( $list['items'] as $app ) {
				Matcher::score_application( $app['id'], false );
			}
			return $this->respond(
				array(
					'queue' => 0,
					'done'  => count( $list['items'] ),
				)
			);
		}
		$id = Matcher::start_bulk( $job_id, true );
		return $this->respond( is_wp_error( $id ) ? $id : array( 'queue' => (int) $id ) );
	}

	/**
	 * GET /employer/queue/<id>: status of a scoring queue the user started.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function queue( \WP_REST_Request $request ) {
		$job = Jobs::get( (int) $request['id'] );
		if ( ! $job || get_current_user_id() !== (int) $job['user_id'] ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		Jobs::kick( $job['id'] );
		$job = Jobs::get( $job['id'] );
		return $this->respond(
			array(
				'status'  => $job['status'],
				'percent' => $job['percent'],
				'done'    => $job['done'],
				'total'   => $job['total'],
			)
		);
	}

	/**
	 * GET /employer/companies.
	 *
	 * @return \WP_REST_Response
	 */
	public function companies() {
		return $this->respond( Companies::for_user( get_current_user_id() ) );
	}

	/**
	 * POST /employer/ai/describe: job description draft (template without AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function describe( \WP_REST_Request $request ) {
		if ( ! Rate_Limiter::hit( 'employer_describe', (string) get_current_user_id(), 10 ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-job-board' ), array( 'status' => 429 ) ) );
		}
		$input = (array) $request->get_json_params();
		if ( ! empty( $input['company_id'] ) && ! Companies::owner_can( (int) $input['company_id'], get_current_user_id() ) ) {
			unset( $input['company_id'] );
		}
		return $this->respond( Describer::draft( $input, 'frontend' ) );
	}

	/**
	 * POST /employer/ai/inclusive { content }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function inclusive( \WP_REST_Request $request ) {
		if ( ! Rate_Limiter::hit( 'employer_inclusive', (string) get_current_user_id(), 10 ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-job-board' ), array( 'status' => 429 ) ) );
		}
		return $this->respond( Inclusive::check( (string) $request->get_param( 'content' ), 'frontend', true ) );
	}
}
