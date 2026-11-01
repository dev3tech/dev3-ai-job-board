<?php
/**
 * Candidate routes: profile, resume upload + parsing, applications, saved jobs, recommendations.
 * Candidates only ever see their own data.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Rest;

use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Ai\Resume_Parser;
use Dev3\AiJobBoard\Core3\Core\Rate_Limiter;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\Saved;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jb/v1/candidate.
 */
class Candidate_Controller extends Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			'/candidate/profile'                           => array( 'GET,POST', 'profile' ),
			'/candidate/resume-file'                       => array( 'POST', 'resume_file' ),
			'/candidate/parse'                             => array( 'POST', 'parse' ),
			'/candidate/applications'                      => array( 'GET', 'applications' ),
			'/candidate/applications/(?P<id>\d+)/withdraw' => array( 'POST', 'withdraw' ),
			'/candidate/saved'                             => array( 'GET', 'saved' ),
			'/candidate/recommended'                       => array( 'GET', 'recommended' ),
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
	 * GET|POST /candidate/profile.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function profile( \WP_REST_Request $request ) {
		$user = get_current_user_id();
		if ( 'POST' === $request->get_method() ) {
			$res = Resumes::save( $user, Resumes::sanitize( (array) $request->get_json_params() ) );
			if ( is_wp_error( $res ) ) {
				return $this->respond( $res );
			}
		}
		return $this->respond( Resumes::for_user( $user ) );
	}

	/**
	 * POST /candidate/resume-file (multipart "file").
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resume_file( \WP_REST_Request $request ) {
		if ( ! Rate_Limiter::hit( 'candidate_upload', (string) get_current_user_id(), 10 ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_rate_limited', __( 'Too many uploads. Please wait a moment.', 'dev3-ai-job-board' ), array( 'status' => 429 ) ) );
		}
		$file = $this->store_upload( $request, 'file', get_current_user_id(), 'resume' );
		if ( null === $file ) {
			return $this->respond( new \WP_Error( 'dev3jb_upload', __( 'Choose a file to upload.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		if ( is_wp_error( $file ) ) {
			return $this->respond( $file );
		}
		return $this->respond( Files::public_info( $file['id'] ), 201 );
	}

	/**
	 * POST /candidate/parse { file_id }: profile draft (nothing is saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $request ) {
		if ( ! Rate_Limiter::hit( 'candidate_parse', (string) get_current_user_id(), 6 ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-job-board' ), array( 'status' => 429 ) ) );
		}
		$file = Files::get( (int) $request->get_param( 'file_id' ) );
		if ( ! $file || get_current_user_id() !== (int) $file['user_id'] ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Resume file not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( Resume_Parser::parse_file( $file['id'], get_current_user_id(), 'frontend', true ) );
	}

	/**
	 * GET /candidate/applications.
	 *
	 * @return \WP_REST_Response
	 */
	public function applications() {
		$labels = Applications::candidate_labels();
		$out    = array();
		foreach ( Applications::query(
			array(
				'candidate_id'      => get_current_user_id(),
				'per_page'          => 200,
				'include_withdrawn' => true,
			)
		)['items'] as $a ) {
			// Candidates see their status in neutral words, never internal notes, ratings or scores.
			$out[] = array(
				'id'         => $a['id'],
				'job_id'     => $a['job_id'],
				'job_title'  => $a['job_title'],
				'status'     => isset( $labels[ $a['stage'] ] ) ? $labels[ $a['stage'] ] : $a['stage'],
				'stage'      => $a['stage'],
				'created_at' => $a['created_at'],
			);
		}
		return $this->respond( $out );
	}

	/**
	 * POST /candidate/applications/<id>/withdraw.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function withdraw( \WP_REST_Request $request ) {
		$res = Applications::withdraw( (int) $request['id'], get_current_user_id() );
		return $this->respond( is_wp_error( $res ) ? $res : array( 'ok' => true ) );
	}

	/**
	 * GET /candidate/saved.
	 *
	 * @return \WP_REST_Response
	 */
	public function saved() {
		$out = array();
		foreach ( Saved::ids( get_current_user_id() ) as $id ) {
			$job = Listings::get( $id );
			if ( $job && 'publish' === $job['status'] ) {
				$out[] = Public_Controller::public_job( $job );
			}
		}
		return $this->respond( $out );
	}

	/**
	 * GET /candidate/recommended.
	 *
	 * @return \WP_REST_Response
	 */
	public function recommended() {
		$out = array();
		foreach ( Matcher::recommend( get_current_user_id(), 10 ) as $row ) {
			$out[] = array(
				'job'     => Public_Controller::public_job( $row['job'] ),
				'score'   => $row['score'],
				'reasons' => $row['reasons'],
			);
		}
		return $this->respond( $out );
	}
}
