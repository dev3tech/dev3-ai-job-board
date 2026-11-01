<?php
/**
 * Job, company and resume post types, taxonomies, statuses and roles.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Registration.
 */
class Post_Types {

	const JOB      = 'dev3jb_job';
	const COMPANY  = 'dev3jb_company';
	const RESUME   = 'dev3jb_resume';
	const CATEGORY = 'dev3jb_category';
	const TYPE     = 'dev3jb_job_type';
	const LOCATION = 'dev3jb_location';
	const SKILL    = 'dev3jb_skill';
	const EXPIRED  = 'dev3jb_expired';
	const UNPAID   = 'dev3jb_unpaid';

	const ROLE_EMPLOYER  = 'dev3jb_employer';
	const ROLE_CANDIDATE = 'dev3jb_candidate';

	/**
	 * Schema.org employmentType values.
	 *
	 * @var string[]
	 */
	const EMPLOYMENT_TYPES = array( 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 10, 2 );
	}

	/**
	 * Registers everything.
	 */
	public static function register() {
		register_post_type(
			self::JOB,
			array(
				'labels'          => array(
					'name'          => __( 'Jobs', 'dev3-ai-job-board' ),
					'singular_name' => __( 'Job', 'dev3-ai-job-board' ),
					'add_new_item'  => __( 'Add job', 'dev3-ai-job-board' ),
					'edit_item'     => __( 'Edit job', 'dev3-ai-job-board' ),
					'search_items'  => __( 'Search jobs', 'dev3-ai-job-board' ),
					'not_found'     => __( 'No jobs found', 'dev3-ai-job-board' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'rest_base'       => 'dev3jb-jobs',
				'has_archive'     => false, // The Jobs page (shortcode/block) is the archive; RSS: /feed/?post_type=dev3jb_job.
				'rewrite'         => array(
					'slug'       => apply_filters( 'dev3jb_job_slug', 'job' ),
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'excerpt', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		register_post_type(
			self::COMPANY,
			array(
				'labels'          => array(
					'name'          => __( 'Companies', 'dev3-ai-job-board' ),
					'singular_name' => __( 'Company', 'dev3-ai-job-board' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => apply_filters( 'dev3jb_company_slug', 'company' ),
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		// Resumes are private: never queryable on the front end and not in REST.
		register_post_type(
			self::RESUME,
			array(
				'labels'              => array(
					'name'          => __( 'Candidate profiles', 'dev3-ai-job-board' ),
					'singular_name' => __( 'Candidate profile', 'dev3-ai-job-board' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
		$tax = array(
			self::CATEGORY => array( __( 'Job categories', 'dev3-ai-job-board' ), __( 'Category', 'dev3-ai-job-board' ), 'job-category', true, array( self::JOB ) ),
			self::TYPE     => array( __( 'Job types', 'dev3-ai-job-board' ), __( 'Job type', 'dev3-ai-job-board' ), 'job-type', false, array( self::JOB ) ),
			self::LOCATION => array( __( 'Job locations', 'dev3-ai-job-board' ), __( 'Location', 'dev3-ai-job-board' ), 'job-location', true, array( self::JOB ) ),
			self::SKILL    => array( __( 'Skills', 'dev3-ai-job-board' ), __( 'Skill', 'dev3-ai-job-board' ), 'job-skill', false, array( self::JOB, self::RESUME ) ),
		);
		foreach ( $tax as $name => $conf ) {
			register_taxonomy(
				$name,
				$conf[4],
				array(
					'labels'            => array(
						'name'          => $conf[0],
						'singular_name' => $conf[1],
					),
					'hierarchical'      => $conf[3],
					'public'            => true,
					'show_ui'           => true,
					'show_in_menu'      => false,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'rewrite'           => array(
						'slug'       => $conf[2],
						'with_front' => false,
					),
				)
			);
		}
		register_term_meta(
			self::TYPE,
			'dev3jb_schema',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_employment_type' ),
			)
		);
		register_post_status(
			self::EXPIRED,
			array(
				'label'                     => _x( 'Expired', 'job status', 'dev3-ai-job-board' ),
				'public'                    => false,
				'protected'                 => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'dev3-ai-job-board' ),
			)
		);
		register_post_status(
			self::UNPAID,
			array(
				'label'                     => _x( 'Awaiting payment', 'job status', 'dev3-ai-job-board' ),
				'public'                    => false,
				'protected'                 => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop( 'Awaiting payment <span class="count">(%s)</span>', 'Awaiting payment <span class="count">(%s)</span>', 'dev3-ai-job-board' ),
			)
		);
	}

	/**
	 * Allowed schema.org employment type.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sanitize_employment_type( $value ) {
		$value = strtoupper( (string) $value );
		return in_array( $value, self::EMPLOYMENT_TYPES, true ) ? $value : 'OTHER';
	}

	/**
	 * Employer + candidate roles and default job types (activation).
	 */
	public static function add_roles() {
		add_role(
			self::ROLE_EMPLOYER,
			__( 'Employer', 'dev3-ai-job-board' ),
			array( 'read' => true )
		);
		add_role(
			self::ROLE_CANDIDATE,
			__( 'Candidate', 'dev3-ai-job-board' ),
			array( 'read' => true )
		);
		$defaults = array(
			'full-time'  => array( __( 'Full-time', 'dev3-ai-job-board' ), 'FULL_TIME' ),
			'part-time'  => array( __( 'Part-time', 'dev3-ai-job-board' ), 'PART_TIME' ),
			'contract'   => array( __( 'Contract', 'dev3-ai-job-board' ), 'CONTRACTOR' ),
			'temporary'  => array( __( 'Temporary', 'dev3-ai-job-board' ), 'TEMPORARY' ),
			'internship' => array( __( 'Internship', 'dev3-ai-job-board' ), 'INTERN' ),
			'freelance'  => array( __( 'Freelance', 'dev3-ai-job-board' ), 'CONTRACTOR' ),
		);
		if ( ! get_terms(
			array(
				'taxonomy'   => self::TYPE,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		) ) {
			foreach ( $defaults as $slug => $conf ) {
				$term = wp_insert_term( $conf[0], self::TYPE, array( 'slug' => $slug ) );
				if ( ! is_wp_error( $term ) ) {
					update_term_meta( $term['term_id'], 'dev3jb_schema', $conf[1] );
				}
			}
		}
	}

	/**
	 * Whether a user is an employer (role, or can manage).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_employer( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( self::ROLE_EMPLOYER, (array) $user->roles, true ) || user_can( $user, 'edit_others_posts' );
	}

	/**
	 * Whether a user is a candidate (any logged-in user who is not only an employer).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_candidate( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		$roles = (array) $user->roles;
		return in_array( self::ROLE_CANDIDATE, $roles, true ) || ! in_array( self::ROLE_EMPLOYER, $roles, true );
	}

	/**
	 * Admin edit links open the DEV3 editor.
	 *
	 * @param string $link    Link.
	 * @param int    $post_id Post.
	 * @return string
	 */
	public static function edit_link( $link, $post_id ) {
		$type = get_post_type( $post_id );
		if ( current_user_can( 'edit_others_posts' ) ) {
			if ( self::JOB === $type ) {
				return admin_url( 'admin.php?page=' . DEV3JB_SLUG . '#/jobs/' . (int) $post_id );
			}
			if ( self::COMPANY === $type ) {
				return admin_url( 'admin.php?page=' . DEV3JB_SLUG . '#/companies/' . (int) $post_id );
			}
		}
		return $link;
	}

	/**
	 * Terms of a taxonomy as [ {id, name, slug, parent, count} ].
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	public static function terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$row = array(
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'slug'   => $t->slug,
				'parent' => (int) $t->parent,
				'count'  => (int) $t->count,
			);
			if ( self::TYPE === $taxonomy ) {
				$row['schema'] = (string) get_term_meta( $t->term_id, 'dev3jb_schema', true );
			}
			$out[] = $row;
		}
		return $out;
	}
}
