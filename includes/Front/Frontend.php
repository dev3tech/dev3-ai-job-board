<?php
/**
 * Front-end wiring: assets, shortcodes, Gutenberg blocks, single templates, archive redirects,
 * alert links and default pages.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Core3\Core\Assets;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact block attribute definitions.

/**
 * Static wiring.
 */
class Frontend {

	const SHORTCODES = array( 'jobs', 'job_search', 'submit_job', 'employer_dashboard', 'candidate_dashboard', 'companies', 'recent_jobs' );

	/**
	 * Enqueued entries.
	 *
	 * @var array
	 */
	private static $enqueued = array();

	/**
	 * Recursion guard for the_content.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_content' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		Files::init();
		Account::init();
		Submission::init();
		Employer_Dashboard::init();
		Candidate_Dashboard::init();
		Schema_Markup::init();
	}

	/**
	 * Shortcodes + blocks + scripts.
	 */
	public static function register() {
		if ( ! wp_script_is( Assets::handle( 'frontend' ), 'registered' ) ) {
			Assets::register( array( 'frontend', 'employer', 'candidate', 'block' ), self::config() );
		}
		foreach ( self::SHORTCODES as $tag ) {
			add_shortcode( 'dev3jb_' . $tag, array( __CLASS__, 'shortcode_' . $tag ) );
		}
		$blocks = array(
			'jobs'                => array(
				'per_page'  => array( 'type' => 'number', 'default' => 10 ),
				'filters'   => array( 'type' => 'string', 'default' => 'yes' ),
				'category'  => array( 'type' => 'string', 'default' => '' ),
				'type'      => array( 'type' => 'string', 'default' => '' ),
				'workplace' => array( 'type' => 'string', 'default' => '' ),
			),
			'job-search'          => array(),
			'recent-jobs'         => array(
				'limit'    => array( 'type' => 'number', 'default' => 5 ),
				'featured' => array( 'type' => 'string', 'default' => 'no' ),
				'category' => array( 'type' => 'string', 'default' => '' ),
			),
			'companies'           => array(
				'limit' => array( 'type' => 'number', 'default' => 24 ),
			),
			'submit-job'          => array(),
			'employer-dashboard'  => array(),
			'candidate-dashboard' => array(),
		);
		if ( function_exists( 'register_block_type' ) ) {
			foreach ( $blocks as $name => $attributes ) {
				$callback = 'shortcode_' . str_replace( '-', '_', $name );
				register_block_type(
					'dev3jb/' . $name,
					array(
						'api_version'     => 2,
						'editor_script'   => Assets::handle( 'block' ),
						'attributes'      => $attributes + array(
							'className' => array( 'type' => 'string' ),
							'align'     => array( 'type' => 'string' ),
						),
						'render_callback' => static function ( $attrs ) use ( $callback ) {
							$html = call_user_func( array( __CLASS__, $callback ), (array) $attrs );
							$cls  = array();
							if ( ! empty( $attrs['align'] ) && in_array( $attrs['align'], array( 'wide', 'full' ), true ) ) {
								$cls[] = 'align' . $attrs['align'];
							}
							if ( ! empty( $attrs['className'] ) ) {
								$cls[] = $attrs['className'];
							}
							return $cls && '' !== $html ? '<div class="' . esc_attr( implode( ' ', $cls ) ) . '">' . $html . '</div>' : $html;
						},
					)
				);
			}
		}
	}

	/**
	 * Front-end boot config (no secrets).
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'loggedIn'  => is_user_logged_in(),
			'loginUrl'  => wp_login_url(),
			'maxUpload' => Settings::max_upload(),
			'pages'     => array(
				'jobs'      => Settings::page_url( 'jobs' ),
				'employer'  => Settings::page_url( 'employer' ),
				'candidate' => Settings::page_url( 'candidate' ),
			),
			'i18n'      => array(
				'error'        => __( 'Something went wrong. Please try again.', 'dev3-ai-job-board' ),
				'loading'      => __( 'Loading…', 'dev3-ai-job-board' ),
				'sending'      => __( 'Sending your application…', 'dev3-ai-job-board' ),
				'sent'         => __( 'Your application was sent. Good luck!', 'dev3-ai-job-board' ),
				'consent'      => __( 'Please confirm that we may share your application with the employer.', 'dev3-ai-job-board' ),
				'required'     => __( 'Please fill in the required fields.', 'dev3-ai-job-board' ),
				'fileTooBig'   => __( 'The file is too large.', 'dev3-ai-job-board' ),
				'saved'        => __( 'Saved', 'dev3-ai-job-board' ),
				'alertTitle'   => __( 'Get these jobs by email', 'dev3-ai-job-board' ),
				'alertText'    => __( 'We will email you new jobs that match this search. You can unsubscribe at any time.', 'dev3-ai-job-board' ),
				'email'        => __( 'Email', 'dev3-ai-job-board' ),
				'frequency'    => __( 'How often', 'dev3-ai-job-board' ),
				'daily'        => __( 'Daily', 'dev3-ai-job-board' ),
				'weekly'       => __( 'Weekly', 'dev3-ai-job-board' ),
				'createAlert'  => __( 'Create alert', 'dev3-ai-job-board' ),
				'cancel'       => __( 'Cancel', 'dev3-ai-job-board' ),
				'close'        => __( 'Close', 'dev3-ai-job-board' ),
				'alertSaved'   => __( 'Alert saved. We will email you when new jobs match.', 'dev3-ai-job-board' ),
				'alertConfirm' => __( 'Check your inbox and confirm the alert.', 'dev3-ai-job-board' ),
				'confirm'      => __( 'Are you sure?', 'dev3-ai-job-board' ),
			),
		);
	}

	/**
	 * Enqueues an entry once (plus the shared stylesheet and accent colour).
	 *
	 * @param string $entry frontend|employer|candidate.
	 */
	public static function enqueue( $entry = 'frontend' ) {
		if ( ! wp_script_is( Assets::handle( 'frontend' ), 'registered' ) ) {
			Assets::register( array( 'frontend', 'employer', 'candidate', 'block' ), self::config() );
		}
		if ( empty( self::$enqueued['frontend'] ) ) {
			self::$enqueued['frontend'] = true;
			Assets::enqueue( 'frontend' );
			$accent = (string) Settings::get( 'accent_color', '#2563eb' );
			wp_add_inline_style( Assets::handle( 'frontend' ), '.dev3jb{--dev3jb-accent:' . esc_attr( $accent ) . ';}' );
		}
		if ( 'frontend' !== $entry && empty( self::$enqueued[ $entry ] ) ) {
			self::$enqueued[ $entry ] = true;
			wp_enqueue_script( Assets::handle( $entry ) );
		}
	}

	/**
	 * Head enqueue for job/company pages and posts that use our shortcodes/blocks.
	 */
	public static function maybe_enqueue() {
		if ( is_singular( array( Post_Types::JOB, Post_Types::COMPANY ) ) ) {
			self::enqueue();
			return;
		}
		$post = get_post();
		if ( ! $post || ! is_singular() ) {
			return;
		}
		$content = (string) $post->post_content;
		if ( false !== strpos( $content, '[dev3jb_' ) || false !== strpos( $content, '<!-- wp:dev3jb/' ) ) {
			self::enqueue();
			if ( false !== strpos( $content, 'employer_dashboard' ) || false !== strpos( $content, 'employer-dashboard' ) || false !== strpos( $content, 'submit_job' ) || false !== strpos( $content, 'submit-job' ) ) {
				self::enqueue( 'employer' );
			}
			if ( false !== strpos( $content, 'candidate_dashboard' ) || false !== strpos( $content, 'candidate-dashboard' ) ) {
				self::enqueue( 'candidate' );
			}
		}
	}

	/**
	 * Body class on our pages.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( is_singular( Post_Types::JOB ) ) {
			$classes[] = 'dev3jb-job-page';
		}
		return $classes;
	}

	/**
	 * Editor: block script data + front-end styles for previews.
	 */
	public static function editor_assets() {
		$opts = static function ( $taxonomy, $all ) {
			$out = array(
				array(
					'value' => '',
					'label' => $all,
				),
			);
			foreach ( Post_Types::terms( $taxonomy ) as $t ) {
				$out[] = array(
					'value' => $t['slug'],
					'label' => $t['name'],
				);
			}
			return $out;
		};
		wp_add_inline_script(
			Assets::handle( 'block' ),
			'window.dev3jbBlock = ' . wp_json_encode(
				array(
					'categories' => $opts( Post_Types::CATEGORY, __( 'All categories', 'dev3-ai-job-board' ) ),
					'types'      => $opts( Post_Types::TYPE, __( 'All job types', 'dev3-ai-job-board' ) ),
				)
			) . ';',
			'before'
		);
		if ( wp_style_is( Assets::handle( 'frontend' ), 'registered' ) ) {
			wp_enqueue_style( Assets::handle( 'frontend' ) );
		}
	}

	/**
	 * Archive redirects, alert links and view counts.
	 */
	public static function template_redirect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token-checked links.
		if ( isset( $_GET['dev3jb_alert'], $_GET['id'], $_GET['token'] ) ) {
			$id    = absint( $_GET['id'] );
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
			$ok    = 'confirm' === $_GET['dev3jb_alert'] ? Alerts::confirm( $id, $token ) : Alerts::delete( $id, 0, $token );
			$key   = 'confirm' === $_GET['dev3jb_alert'] ? 'alert_confirmed' : 'alert_unsubscribed';
			wp_safe_redirect( add_query_arg( $ok ? 'dev3jb_notice' : 'dev3jb_error', $ok ? $key : rawurlencode( __( 'That link is no longer valid.', 'dev3-ai-job-board' ) ), Settings::page_url( 'jobs' ) ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$page = (int) Settings::get( 'page_jobs', 0 );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$url = '';
			if ( is_tax( Post_Types::CATEGORY ) ) {
				$url = add_query_arg( 'category', get_queried_object()->slug, get_permalink( $page ) );
			} elseif ( is_tax( Post_Types::TYPE ) ) {
				$url = add_query_arg( 'type', get_queried_object()->slug, get_permalink( $page ) );
			} elseif ( is_tax( Post_Types::LOCATION ) ) {
				$url = add_query_arg( 'location', get_queried_object()->slug, get_permalink( $page ) );
			} elseif ( is_tax( Post_Types::SKILL ) ) {
				$url = add_query_arg( 'q', rawurlencode( get_queried_object()->name ), get_permalink( $page ) );
			} elseif ( is_post_type_archive( Post_Types::JOB ) && ! is_feed() ) {
				$url = (string) get_permalink( $page );
			}
			if ( $url ) {
				wp_safe_redirect( $url, 302 );
				exit;
			}
		}
		if ( is_singular( Post_Types::JOB ) ) {
			$id   = get_queried_object_id();
			$post = get_post( $id );
			$user = get_current_user_id();
			if ( $post && 'publish' !== $post->post_status && ! Listings::owner_can( $id, $user ) ) {
				// Unpublished jobs (pending, unpaid, draft, expired) are only visible to their owner and managers.
				if ( Post_Types::EXPIRED !== $post->post_status ) {
					wp_safe_redirect( Settings::page_url( 'jobs' ) );
					exit;
				}
			}
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			if ( $post && 'publish' === $post->post_status && (int) $post->post_author !== $user && ! preg_match( '/bot|crawl|spider|slurp|preview/i', $agent ) ) {
				update_post_meta( $id, '_dev3jb_views', (int) get_post_meta( $id, '_dev3jb_views', true ) + 1 );
			}
		}
	}

	/**
	 * Replaces the content of single job and company pages with our templates.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function single_content( $content ) {
		if ( self::$rendering || is_admin() || is_feed() || doing_action( 'wp_head' ) ) {
			return $content;
		}
		$id = get_the_ID();
		if ( ! $id || (int) get_queried_object_id() !== (int) $id ) {
			return $content;
		}
		self::$rendering = true;
		if ( is_singular( Post_Types::JOB ) ) {
			$job = Listings::get( $id );
			if ( $job ) {
				self::enqueue();
				$content = Templates::single_job( $job );
			}
		} elseif ( is_singular( Post_Types::COMPANY ) ) {
			$company = Companies::get( $id );
			if ( $company ) {
				self::enqueue();
				$content = Templates::company_page( $company );
			}
		}
		self::$rendering = false;
		return $content;
	}

	/**
	 * [dev3jb_jobs per_page="10" filters="yes" category="" type="" workplace=""].
	 *
	 * @param array|string $atts Atts.
	 * @return string
	 */
	public static function shortcode_jobs( $atts ) {
		self::enqueue();
		return Templates::board( (array) $atts );
	}

	/**
	 * [dev3jb_job_search].
	 *
	 * @return string
	 */
	public static function shortcode_job_search() {
		self::enqueue();
		return Templates::search_box();
	}

	/**
	 * [dev3jb_recent_jobs limit="5" featured="no" category=""].
	 *
	 * @param array|string $atts Atts.
	 * @return string
	 */
	public static function shortcode_recent_jobs( $atts ) {
		self::enqueue();
		return Templates::recent( (array) $atts );
	}

	/**
	 * [dev3jb_companies limit="24"].
	 *
	 * @param array|string $atts Atts.
	 * @return string
	 */
	public static function shortcode_companies( $atts ) {
		self::enqueue();
		return Templates::companies( (array) $atts );
	}

	/**
	 * [dev3jb_submit_job].
	 *
	 * @return string
	 */
	public static function shortcode_submit_job() {
		self::enqueue( 'employer' );
		return Submission::render();
	}

	/**
	 * [dev3jb_employer_dashboard].
	 *
	 * @return string
	 */
	public static function shortcode_employer_dashboard() {
		self::enqueue( 'employer' );
		return Employer_Dashboard::render();
	}

	/**
	 * [dev3jb_candidate_dashboard].
	 *
	 * @return string
	 */
	public static function shortcode_candidate_dashboard() {
		self::enqueue( 'candidate' );
		return Candidate_Dashboard::render();
	}

	/**
	 * Creates the default pages (once) and stores them in settings.
	 */
	public static function create_pages() {
		$pages = array(
			'jobs'      => array( __( 'Jobs', 'dev3-ai-job-board' ), '<!-- wp:dev3jb/jobs {"align":"wide"} /-->' ),
			'submit'    => array( __( 'Post a Job', 'dev3-ai-job-board' ), '<!-- wp:dev3jb/submit-job {"align":"wide"} /-->' ),
			'employer'  => array( __( 'Employer Dashboard', 'dev3-ai-job-board' ), '<!-- wp:dev3jb/employer-dashboard {"align":"wide"} /-->' ),
			'candidate' => array( __( 'Candidate Dashboard', 'dev3-ai-job-board' ), '<!-- wp:dev3jb/candidate-dashboard {"align":"wide"} /-->' ),
			'companies' => array( __( 'Companies', 'dev3-ai-job-board' ), '<!-- wp:dev3jb/companies {"align":"wide"} /-->' ),
		);
		$patch = array();
		foreach ( $pages as $key => $page ) {
			$current = (int) Settings::get( 'page_' . $key, 0 );
			if ( $current && get_post( $current ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$patch[ 'page_' . $key ] = (int) $id;
			}
		}
		if ( $patch ) {
			\Dev3\AiJobBoard\Core3\Core\Settings_Store::update( $patch );
		}
	}
}
