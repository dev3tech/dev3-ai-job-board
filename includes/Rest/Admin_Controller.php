<?php
/**
 * Admin app routes (manage capability; AI drafts need the generate capability).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Rest;

use Dev3\AiJobBoard\Ai\Describer;
use Dev3\AiJobBoard\Ai\Inclusive;
use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Ai\Resume_Parser;
use Dev3\AiJobBoard\Billing\Checkout;
use Dev3\AiJobBoard\Core3\Api\Ai;
use Dev3\AiJobBoard\Core3\Core\Settings_Store;
use Dev3\AiJobBoard\Core3\Data\Embeddings_Store;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\UserPackages;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jb/v1/admin.
 */
class Admin_Controller extends Controller {

	const TAXONOMIES = array(
		'categories' => Post_Types::CATEGORY,
		'types'      => Post_Types::TYPE,
		'locations'  => Post_Types::LOCATION,
		'skills'     => Post_Types::SKILL,
	);

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage   = array( $this, 'can_manage' );
		$generate = array( $this, 'can_generate' );
		$routes   = array(
			'/admin/meta'                              => array( 'GET', 'meta', $manage ),
			'/admin/stats'                             => array( 'GET', 'stats', $manage ),
			'/admin/jobs'                              => array( 'GET,POST', 'jobs', $manage ),
			'/admin/jobs/bulk'                         => array( 'POST', 'jobs_bulk', $manage ),
			'/admin/jobs/(?P<id>\d+)'                  => array( 'GET,POST,DELETE', 'job', $manage ),
			'/admin/jobs/(?P<id>\d+)/score'            => array( 'POST', 'score_job', $manage ),
			'/admin/applications'                      => array( 'GET', 'applications', $manage ),
			'/admin/applications/(?P<id>\d+)'          => array( 'GET,POST,DELETE', 'application', $manage ),
			'/admin/applications/(?P<id>\d+)/notes'    => array( 'POST', 'note', $manage ),
			'/admin/applications/(?P<id>\d+)/match'    => array( 'POST', 'match', $manage ),
			'/admin/index'                             => array( 'POST', 'index', $manage ),
			'/admin/candidates'                        => array( 'GET', 'candidates', $manage ),
			'/admin/candidates/(?P<id>\d+)'            => array( 'GET', 'candidate', $manage ),
			'/admin/companies'                         => array( 'GET,POST', 'companies', $manage ),
			'/admin/companies/(?P<id>\d+)'             => array( 'GET,POST,DELETE', 'company', $manage ),
			'/admin/terms/(?P<tax>[a-z]+)'             => array( 'GET,POST', 'terms', $manage ),
			'/admin/terms/(?P<tax>[a-z]+)/(?P<id>\d+)' => array( 'POST,DELETE', 'term', $manage ),
			'/admin/packages'                          => array( 'GET,POST', 'packages', $manage ),
			'/admin/packages/grant'                    => array( 'POST', 'grant', $manage ),
			'/admin/payments'                          => array( 'GET', 'payments', $manage ),
			'/admin/payments/(?P<id>\d+)/paid'         => array( 'POST', 'payment_paid', $manage ),
			'/admin/payments/(?P<id>\d+)/status'       => array( 'POST', 'payment_status', $manage ),
			'/admin/payment-config'                    => array( 'GET,POST', 'payment_config', $manage ),
			'/admin/alerts'                            => array( 'GET', 'alerts', $manage ),
			'/admin/alerts/send'                       => array( 'POST', 'alerts_send', $manage ),
			'/admin/alerts/(?P<id>\d+)'                => array( 'DELETE', 'alert_delete', $manage ),
			'/admin/ai/describe'                       => array( 'POST', 'describe', $generate ),
			'/admin/ai/inclusive'                      => array( 'POST', 'inclusive', $generate ),
			'/admin/ai/parse'                          => array( 'POST', 'parse', $generate ),
		);
		foreach ( $routes as $path => $conf ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $conf[0],
					'callback'            => array( $this, $conf[1] ),
					'permission_callback' => $conf[2],
				)
			);
		}
	}

	/**
	 * GET /admin/meta: vocabularies for the admin app.
	 *
	 * @return \WP_REST_Response
	 */
	public function meta() {
		$terms = array();
		foreach ( self::TAXONOMIES as $key => $tax ) {
			$terms[ $key ] = Post_Types::terms( $tax );
		}
		$pages = array();
		foreach ( get_pages( array( 'number' => 300 ) ) as $p ) {
			$pages[] = array(
				'value' => (int) $p->ID,
				'label' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		$companies = array();
		foreach ( get_posts(
			array(
				'post_type'      => Post_Types::COMPANY,
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $c ) {
			$companies[] = array(
				'value' => (int) $c->ID,
				'label' => html_entity_decode( get_the_title( $c ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		$employers = array();
		foreach ( get_users(
			array(
				'role__in' => array( Post_Types::ROLE_EMPLOYER, 'administrator', 'editor' ),
				'number'   => 300,
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			)
		) as $u ) {
			$employers[] = array(
				'value' => (int) $u->ID,
				'label' => $u->display_name . ' (' . $u->user_email . ')',
			);
		}
		return $this->respond(
			array(
				'terms'      => $terms,
				'pages'      => $pages,
				'companies'  => $companies,
				'employers'  => $employers,
				'stages'     => Applications::stage_labels(),
				'workplaces' => Listings::workplace_labels(),
				'levels'     => Listings::experience_labels(),
				'periods'    => Listings::period_labels(),
				'sizes'      => Companies::SIZES,
				'packages'   => Packages::all(),
				'currency'   => (string) Settings::get( 'currency', 'USD' ),
				'ai'         => array(
					'enabled' => Ai::enabled( 'admin' ),
					'key_set' => '' !== Settings_Store::api_key(),
					'writer'  => Describer::ai_available( 'admin' ),
					'match'   => Matcher::ai_available( 'admin' ),
					'parse'   => Resume_Parser::ai_available( 'admin' ),
					'indexed' => array(
						'job'    => Embeddings_Store::instance()->count( 'job' ),
						'resume' => Embeddings_Store::instance()->count( 'resume' ),
					),
				),
				'urls'       => array(
					'jobs'      => Settings::page_url( 'jobs' ),
					'submit'    => Settings::page_url( 'submit' ),
					'employer'  => Settings::page_url( 'employer' ),
					'candidate' => Settings::page_url( 'candidate' ),
				),
			)
		);
	}

	/**
	 * GET /admin/stats: dashboard KPIs.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		$counts = Listings::counts();
		$recent = Applications::query( array( 'per_page' => 6 ) )['items'];
		$top    = array();
		foreach ( Listings::search(
			array(
				'per_page' => 50,
				'orderby'  => 'date',
			)
		)['items'] as $job ) {
			$top[] = array(
				'id'           => $job['id'],
				'title'        => $job['title'],
				'company'      => $job['company'] ? $job['company']['name'] : '',
				'applications' => $job['applications'],
				'views'        => $job['views'],
			);
		}
		usort( $top, static fn( $a, $b ) => $b['applications'] <=> $a['applications'] );
		$resumes = new \WP_Query(
			array(
				'post_type'      => Post_Types::RESUME,
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $this->respond(
			array(
				'jobs'            => $counts,
				'applications_30' => Applications::recent_count( 30 ),
				'applications_7'  => Applications::recent_count( 7 ),
				'revenue_30'      => Payments::revenue( 30 ),
				'candidates'      => (int) $resumes->found_posts,
				'daily'           => Applications::daily( 14 ),
				'stages'          => Applications::stage_counts(),
				'recent'          => array_map(
					static fn( $a ) => Employer_Controller::card( $a ) + array(
						'job_title' => $a['job_title'],
						'job_id'    => $a['job_id'],
					),
					$recent
				),
				'top'             => array_slice( $top, 0, 5 ),
				'pending'         => array_map(
					static fn( $j ) => array(
						'id'    => $j['id'],
						'title' => $j['title'],
						'date'  => $j['date'],
					),
					Listings::search(
						array(
							'status'   => 'pending',
							'per_page' => 5,
						)
					)['items']
				),
			)
		);
	}

	/**
	 * GET|POST /admin/jobs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function jobs( \WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method() ) {
			return $this->save_job( 0, $request );
		}
		$status        = sanitize_key( (string) $request->get_param( 'status' ) );
		$all           = array( 'publish', 'pending', 'draft', Post_Types::UNPAID, Post_Types::EXPIRED );
		$res           = Listings::search(
			array(
				'status'   => in_array( $status, $all, true ) ? $status : $all,
				'q'        => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'company'  => (int) $request->get_param( 'company' ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
				'per_page' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) ),
				'orderby'  => 'title' === $request->get_param( 'orderby' ) ? 'title' : 'date',
				'order'    => 'asc' === $request->get_param( 'order' ) ? 'asc' : 'desc',
			)
		);
		$res['counts'] = Listings::counts();
		return $this->respond( $res );
	}

	/**
	 * Saves a job from the admin editor.
	 *
	 * @param int              $id      Job (0 = new).
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	private function save_job( $id, \WP_REST_Request $request ) {
		$in     = (array) $request->get_json_params();
		$data   = Listings::sanitize( $in );
		$status = isset( $in['status'] ) && in_array( $in['status'], array( 'publish', 'pending', 'draft', Post_Types::UNPAID, Post_Types::EXPIRED ), true ) ? $in['status'] : '';
		$author = isset( $in['author'] ) ? (int) $in['author'] : 0;
		$res    = Listings::save( $data, $id, $author, $status ? $status : ( $id ? '' : 'draft' ) );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		if ( $id && $author && get_userdata( $author ) ) {
			wp_update_post(
				array(
					'ID'          => $res,
					'post_author' => $author,
				)
			);
		}
		return $this->respond( Listings::get( $res ), $id ? 200 : 201 );
	}

	/**
	 * GET|POST|DELETE /admin/jobs/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function job( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Listings::is_job( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		if ( 'POST' === $request->get_method() ) {
			return $this->save_job( $id, $request );
		}
		if ( 'DELETE' === $request->get_method() ) {
			wp_trash_post( $id );
			return $this->respond( array( 'deleted' => true ) );
		}
		$job                = Listings::get( $id );
		$job['author_name'] = get_the_author_meta( 'display_name', $job['author'] );
		$job['counts']      = Applications::stage_counts( array( 'job_id' => $id ) );
		return $this->respond( $job );
	}

	/**
	 * POST /admin/jobs/bulk { ids, action }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function jobs_bulk( \WP_REST_Request $request ) {
		$ids    = array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$done   = 0;
		foreach ( $ids as $id ) {
			if ( ! Listings::is_job( $id ) ) {
				continue;
			}
			switch ( $action ) {
				case 'approve':
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'publish',
						)
					);
					break;
				case 'expire':
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => Post_Types::EXPIRED,
						)
					);
					break;
				case 'draft':
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'draft',
						)
					);
					break;
				case 'feature':
				case 'unfeature':
					update_post_meta( $id, '_dev3jb_featured', 'feature' === $action ? 1 : 0 );
					break;
				case 'delete':
					wp_trash_post( $id );
					break;
				default:
					continue 2;
			}
			++$done;
		}
		return $this->respond( array( 'updated' => $done ) );
	}

	/**
	 * POST /admin/jobs/<id>/score { ai }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function score_job( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Listings::is_job( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$use_ai = (bool) $request->get_param( 'ai' ) && Matcher::ai_available( 'cron' );
		$queue  = Matcher::start_bulk( $id, $use_ai, (bool) $request->get_param( 'only_unscored' ) );
		return $this->respond( is_wp_error( $queue ) ? $queue : array( 'job' => (int) $queue ) );
	}

	/**
	 * GET /admin/applications.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function applications( \WP_REST_Request $request ) {
		$orderby       = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$res           = Applications::query(
			array(
				'job_id'   => (int) $request->get_param( 'job' ),
				'stage'    => sanitize_key( (string) $request->get_param( 'stage' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
				'per_page' => min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 50 ) ) ),
				'orderby'  => in_array( $orderby, array( 'date', 'score', 'position' ), true ) ? $orderby : 'date',
			)
		);
		$res['items']  = array_map(
			static fn( $a ) => Employer_Controller::card( $a ) + array(
				'job_title' => $a['job_title'],
				'job_id'    => $a['job_id'],
				'email'     => $a['email'],
			),
			$res['items']
		);
		$res['counts'] = Applications::stage_counts( array( 'job_id' => (int) $request->get_param( 'job' ) ) );
		return $this->respond( $res );
	}

	/**
	 * GET|POST|DELETE /admin/applications/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function application( \WP_REST_Request $request ) {
		$app = Applications::get( (int) $request['id'] );
		if ( ! $app ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Application not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		if ( 'DELETE' === $request->get_method() ) {
			Applications::delete( $app['id'] );
			return $this->respond( array( 'deleted' => true ) );
		}
		if ( 'POST' === $request->get_method() ) {
			$app = Employer_Controller::update_application( $app, $request );
			if ( is_wp_error( $app ) ) {
				return $this->respond( $app );
			}
		}
		$out          = Employer_Controller::detail( $app );
		$out['name']  = $app['name'];
		$out['email'] = $app['email'];
		return $this->respond( $out );
	}

	/**
	 * POST /admin/applications/<id>/notes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function note( \WP_REST_Request $request ) {
		$app  = Applications::get( (int) $request['id'] );
		$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		if ( ! $app || '' === $note ) {
			return $this->respond( new \WP_Error( 'dev3jb_note', __( 'Write a note first.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		Applications::add_event( $app['id'], get_current_user_id(), 'note', $note );
		return $this->respond( Applications::events( $app['id'] ) );
	}

	/**
	 * POST /admin/applications/<id>/match { ai }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function match( \WP_REST_Request $request ) {
		$res = Matcher::score_application( (int) $request['id'], (bool) $request->get_param( 'ai' ), 'admin' );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		$app          = Applications::get( (int) $request['id'] );
		$out          = Employer_Controller::detail( $app );
		$out['name']  = $app['name'];
		$out['email'] = $app['email'];
		return $this->respond( $out );
	}

	/**
	 * POST /admin/index: embed jobs and resumes for recommendations.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$id = Matcher::start_index();
		return $this->respond( is_wp_error( $id ) ? $id : array( 'job' => (int) $id ) );
	}

	/**
	 * GET /admin/candidates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function candidates( \WP_REST_Request $request ) {
		$res   = Resumes::search(
			array(
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
				'per_page' => 20,
			)
		);
		$items = array();
		foreach ( $res['items'] as $p ) {
			$items[] = array(
				'id'           => $p['id'],
				'user_id'      => $p['user_id'],
				'name'         => $p['name'],
				'email'        => $p['email'],
				'headline'     => $p['headline'],
				'location'     => $p['location'],
				'skills'       => array_slice( $p['skills'], 0, 6 ),
				'years'        => $p['years'],
				'complete'     => $p['complete'],
				'has_file'     => (bool) $p['file_id'],
				'updated'      => $p['updated'],
				'applications' => Applications::query(
					array(
						'candidate_id' => $p['user_id'],
						'per_page'     => 1,
					)
				)['total'],
			);
		}
		return $this->respond(
			array(
				'items' => $items,
				'total' => $res['total'],
			)
		);
	}

	/**
	 * GET /admin/candidates/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function candidate( \WP_REST_Request $request ) {
		$p = Resumes::get( (int) $request['id'] );
		if ( ! $p ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Candidate not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		$p['applications'] = Applications::query(
			array(
				'candidate_id'      => $p['user_id'],
				'per_page'          => 50,
				'include_withdrawn' => true,
			)
		)['items'];
		return $this->respond( $p );
	}

	/**
	 * GET|POST /admin/companies.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function companies( \WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method() ) {
			$in  = (array) $request->get_json_params();
			$res = Companies::save( Companies::sanitize( $in ), 0, isset( $in['author'] ) ? (int) $in['author'] : get_current_user_id(), 'publish' );
			return $this->respond( is_wp_error( $res ) ? $res : Companies::get( $res ), 201 );
		}
		return $this->respond( Companies::for_user( 0 ) );
	}

	/**
	 * GET|POST|DELETE /admin/companies/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function company( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Companies::get( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_not_found', __( 'Company not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		if ( 'DELETE' === $request->get_method() ) {
			wp_trash_post( $id );
			return $this->respond( array( 'deleted' => true ) );
		}
		if ( 'POST' === $request->get_method() ) {
			$in  = (array) $request->get_json_params();
			$res = Companies::save( Companies::sanitize( $in ), $id );
			if ( is_wp_error( $res ) ) {
				return $this->respond( $res );
			}
			if ( ! empty( $in['author'] ) && get_userdata( (int) $in['author'] ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_author' => (int) $in['author'],
					)
				);
			}
		}
		return $this->respond( Companies::get( $id ) );
	}

	/**
	 * Taxonomy from the route key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function tax( $key ) {
		return isset( self::TAXONOMIES[ $key ] ) ? self::TAXONOMIES[ $key ] : '';
	}

	/**
	 * GET|POST /admin/terms/<tax> (POST: { name, parent, schema }).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function terms( \WP_REST_Request $request ) {
		$tax = $this->tax( (string) $request['tax'] );
		if ( ! $tax ) {
			return $this->respond( new \WP_Error( 'dev3jb_tax', __( 'Unknown list.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		if ( 'POST' === $request->get_method() ) {
			$name = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 );
			if ( '' === $name ) {
				return $this->respond( new \WP_Error( 'dev3jb_term', __( 'Enter a name.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
			}
			$res = wp_insert_term( $name, $tax, array( 'parent' => is_taxonomy_hierarchical( $tax ) ? (int) $request->get_param( 'parent' ) : 0 ) );
			if ( is_wp_error( $res ) ) {
				return $this->respond( new \WP_Error( 'dev3jb_term', $res->get_error_message(), array( 'status' => 400 ) ) );
			}
			if ( Post_Types::TYPE === $tax ) {
				update_term_meta( $res['term_id'], 'dev3jb_schema', Post_Types::sanitize_employment_type( (string) $request->get_param( 'schema' ) ) );
			}
		}
		return $this->respond( Post_Types::terms( $tax ) );
	}

	/**
	 * POST|DELETE /admin/terms/<tax>/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function term( \WP_REST_Request $request ) {
		$tax = $this->tax( (string) $request['tax'] );
		$id  = (int) $request['id'];
		if ( ! $tax || ! term_exists( $id, $tax ) ) {
			return $this->respond( new \WP_Error( 'dev3jb_tax', __( 'Not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
		}
		if ( 'DELETE' === $request->get_method() ) {
			wp_delete_term( $id, $tax );
		} else {
			$args = array();
			if ( null !== $request->get_param( 'name' ) ) {
				$args['name'] = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 );
			}
			if ( null !== $request->get_param( 'parent' ) && is_taxonomy_hierarchical( $tax ) && (int) $request->get_param( 'parent' ) !== $id ) {
				$args['parent'] = (int) $request->get_param( 'parent' );
			}
			$res = wp_update_term( $id, $tax, $args );
			if ( is_wp_error( $res ) ) {
				return $this->respond( new \WP_Error( 'dev3jb_term', $res->get_error_message(), array( 'status' => 400 ) ) );
			}
			if ( Post_Types::TYPE === $tax && null !== $request->get_param( 'schema' ) ) {
				update_term_meta( $id, 'dev3jb_schema', Post_Types::sanitize_employment_type( (string) $request->get_param( 'schema' ) ) );
			}
		}
		return $this->respond( Post_Types::terms( $tax ) );
	}

	/**
	 * GET|POST /admin/packages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function packages( \WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method() ) {
			$list = $request->get_param( 'packages' );
			return $this->respond( Packages::save( is_array( $list ) ? $list : array() ) );
		}
		return $this->respond( Packages::all() );
	}

	/**
	 * POST /admin/packages/grant { user_id, package_id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function grant( \WP_REST_Request $request ) {
		$user    = (int) $request->get_param( 'user_id' );
		$package = Packages::get( sanitize_key( (string) $request->get_param( 'package_id' ) ) );
		if ( ! $user || ! get_userdata( $user ) || ! $package ) {
			return $this->respond( new \WP_Error( 'dev3jb_grant', __( 'Choose an employer and a package.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( UserPackages::grant( $user, $package, 'active' ), 201 );
	}

	/**
	 * GET /admin/payments.
	 *
	 * @return \WP_REST_Response
	 */
	public function payments() {
		return $this->respond(
			array(
				'items'      => Payments::recent( 200 ),
				'revenue_30' => Payments::revenue( 30 ),
				'gateway'    => Checkout::gateway(),
			)
		);
	}

	/**
	 * POST /admin/payments/<id>/paid.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function payment_paid( \WP_REST_Request $request ) {
		$p = Payments::complete( (int) $request['id'] );
		return $this->respond( $p ? $p : new \WP_Error( 'dev3jb_not_found', __( 'Payment not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) ) );
	}

	/**
	 * POST /admin/payments/<id>/status { status }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function payment_status( \WP_REST_Request $request ) {
		Payments::set_status( (int) $request['id'], sanitize_key( (string) $request->get_param( 'status' ) ) );
		return $this->respond( Payments::get( (int) $request['id'] ) );
	}

	/**
	 * GET|POST /admin/payment-config (Stripe keys, encrypted; never returned in full).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function payment_config( \WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method() ) {
			Checkout::save_secrets( (array) $request->get_json_params() );
		}
		return $this->respond( Checkout::config() );
	}

	/**
	 * GET /admin/alerts.
	 *
	 * @return \WP_REST_Response
	 */
	public function alerts() {
		return $this->respond( Alerts::all( 300 ) );
	}

	/**
	 * POST /admin/alerts/send: run the digest now.
	 *
	 * @return \WP_REST_Response
	 */
	public function alerts_send() {
		return $this->respond( array( 'sent' => Alerts::send_digests() ) );
	}

	/**
	 * DELETE /admin/alerts/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alert_delete( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Alerts::delete( (int) $request['id'], get_current_user_id() ) ) );
	}

	/**
	 * POST /admin/ai/describe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function describe( \WP_REST_Request $request ) {
		$in = (array) $request->get_json_params();
		return $this->respond( Describer::draft( $in, 'admin', ! empty( $in['template'] ) ) );
	}

	/**
	 * POST /admin/ai/inclusive { content, ai }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function inclusive( \WP_REST_Request $request ) {
		return $this->respond( Inclusive::check( (string) $request->get_param( 'content' ), 'admin', false !== $request->get_param( 'ai' ) ) );
	}

	/**
	 * POST /admin/ai/parse (multipart "file"): try the resume parser on a file (nothing is stored).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $request ) {
		$file = $this->store_upload( $request, 'file', get_current_user_id(), 'attachment' );
		if ( null === $file ) {
			return $this->respond( new \WP_Error( 'dev3jb_upload', __( 'Choose a file to upload.', 'dev3-ai-job-board' ), array( 'status' => 400 ) ) );
		}
		if ( is_wp_error( $file ) ) {
			return $this->respond( $file );
		}
		$res = Resume_Parser::parse_file( $file['id'], get_current_user_id(), 'admin', false !== $request->get_param( 'ai' ) && 'false' !== $request->get_param( 'ai' ) );
		\Dev3\AiJobBoard\Data\Files::delete( $file['id'] );
		return $this->respond( $res );
	}
}
