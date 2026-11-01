<?php
/**
 * Employer dashboard: jobs, applications pipeline (JS app), companies, packages & payments.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Billing\Checkout;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\UserPackages;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static dashboard.
 */
class Employer_Dashboard {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_dev3jb_job_action', array( __CLASS__, 'job_action' ) );
		add_action( 'admin_post_dev3jb_save_company', array( __CLASS__, 'save_company' ) );
		add_action( 'admin_post_dev3jb_buy_package', array( __CLASS__, 'buy_package' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'publish'           => array( __( 'Live', 'dev3-ai-job-board' ), 'ok' ),
			'pending'           => array( __( 'In review', 'dev3-ai-job-board' ), 'warn' ),
			'draft'             => array( __( 'Draft', 'dev3-ai-job-board' ), '' ),
			Post_Types::UNPAID  => array( __( 'Awaiting payment', 'dev3-ai-job-board' ), 'info' ),
			Post_Types::EXPIRED => array( __( 'Expired', 'dev3-ai-job-board' ), 'bad' ),
		);
	}

	/**
	 * Tab URL.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra.
	 * @return string
	 */
	public static function tab_url( $tab, array $args = array() ) {
		return add_query_arg( array_merge( array( 'dev3jb_tab' => $tab ), $args ), (string) get_permalink() );
	}

	/**
	 * Renders the dashboard.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return Account::gate( 'employer', __( 'Employer dashboard', 'dev3-ai-job-board' ), __( 'Log in to manage your jobs and review applications.', 'dev3-ai-job-board' ) );
		}
		$tabs = array(
			'jobs'         => __( 'Jobs', 'dev3-ai-job-board' ),
			'applications' => __( 'Applications', 'dev3-ai-job-board' ),
			'companies'    => __( 'Companies', 'dev3-ai-job-board' ),
			'packages'     => __( 'Packages', 'dev3-ai-job-board' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$tab   = isset( $_GET['dev3jb_tab'] ) ? sanitize_key( wp_unslash( $_GET['dev3jb_tab'] ) ) : 'jobs';
		$tab   = isset( $tabs[ $tab ] ) ? $tab : 'jobs';
		$user  = get_current_user_id();
		$html  = '<div class="dev3jb dev3jb-dash">';
		$html .= '<div class="dev3jb-dash__head"><div><h2 class="dev3jb-h">' . esc_html__( 'Employer dashboard', 'dev3-ai-job-board' ) . '</h2><p class="dev3jb-muted">' . esc_html( wp_get_current_user()->display_name ) . '</p></div><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( Settings::page_url( 'submit' ) ) . '">' . Templates::icon( 'plus', 16 ) . esc_html__( 'Post a job', 'dev3-ai-job-board' ) . '</a></div>';
		$html .= '<nav class="dev3jb-tabs" aria-label="' . esc_attr__( 'Dashboard sections', 'dev3-ai-job-board' ) . '"><ul>';
		foreach ( $tabs as $key => $label ) {
			$html .= '<li><a href="' . esc_url( self::tab_url( $key ) ) . '"' . ( $key === $tab ? ' aria-current="page" class="is-active"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></nav>';
		$html .= Forms::notices(
			array(
				'published'         => __( 'Your job is live.', 'dev3-ai-job-board' ),
				'submitted'         => __( 'Thanks! Your job was submitted and will go live after a quick review.', 'dev3-ai-job-board' ),
				'manual'            => __( 'We emailed you payment instructions. Your job goes live once the payment is confirmed.', 'dev3-ai-job-board' ),
				'paid'              => __( 'Payment received. Thank you!', 'dev3-ai-job-board' ),
				'payment_pending'   => __( 'We are still waiting for the payment confirmation. This can take a minute.', 'dev3-ai-job-board' ),
				'payment_cancelled' => __( 'The payment was cancelled. You can try again any time.', 'dev3-ai-job-board' ),
				'package'           => __( 'Package added to your account.', 'dev3-ai-job-board' ),
				'updated'           => __( 'Job updated.', 'dev3-ai-job-board' ),
				'updated_review'    => __( 'Job updated. The changes go live after a quick review.', 'dev3-ai-job-board' ),
				'filled'            => __( 'Marked as filled. The job stays visible but no longer takes applications.', 'dev3-ai-job-board' ),
				'unfilled'          => __( 'The job takes applications again.', 'dev3-ai-job-board' ),
				'deleted'           => __( 'Job deleted.', 'dev3-ai-job-board' ),
				'company_saved'     => __( 'Company saved.', 'dev3-ai-job-board' ),
				'registered'        => __( 'Welcome! Your account is ready.', 'dev3-ai-job-board' ),
			)
		);
		$html .= '<div class="dev3jb-dash__panel">';
		switch ( $tab ) {
			case 'applications':
				$html .= self::applications_tab( $user );
				break;
			case 'companies':
				$html .= self::companies_tab( $user );
				break;
			case 'packages':
				$html .= self::packages_tab( $user );
				break;
			default:
				$html .= self::jobs_tab( $user );
		}
		return $html . '</div></div>';
	}

	/**
	 * Jobs tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function jobs_tab( $user ) {
		$res = Listings::search(
			array(
				'author'   => $user,
				'status'   => array( 'publish', 'pending', 'draft', Post_Types::UNPAID, Post_Types::EXPIRED ),
				'per_page' => 100,
			)
		);
		if ( ! $res['items'] ) {
			return '<div class="dev3jb-empty">' . Templates::icon( 'briefcase', 28 ) . '<h3>' . esc_html__( 'No jobs yet', 'dev3-ai-job-board' ) . '</h3><p>' . esc_html__( 'Post your first job. The writing assistant helps you write it in a few minutes.', 'dev3-ai-job-board' ) . '</p><p><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( Settings::page_url( 'submit' ) ) . '">' . esc_html__( 'Post a job', 'dev3-ai-job-board' ) . '</a></p></div>';
		}
		$labels = self::status_labels();
		$html   = '<div class="dev3jb-table" role="table" aria-label="' . esc_attr__( 'Your jobs', 'dev3-ai-job-board' ) . '"><div class="dev3jb-table__row dev3jb-table__row--head" role="row"><span role="columnheader">' . esc_html__( 'Job', 'dev3-ai-job-board' ) . '</span><span role="columnheader">' . esc_html__( 'Status', 'dev3-ai-job-board' ) . '</span><span role="columnheader">' . esc_html__( 'Applications', 'dev3-ai-job-board' ) . '</span><span role="columnheader">' . esc_html__( 'Views', 'dev3-ai-job-board' ) . '</span><span role="columnheader">' . esc_html__( 'Expires', 'dev3-ai-job-board' ) . '</span><span role="columnheader"><span class="dev3jb-sr">' . esc_html__( 'Actions', 'dev3-ai-job-board' ) . '</span></span></div>';
		foreach ( $res['items'] as $job ) {
			$st    = isset( $labels[ $job['status'] ] ) ? $labels[ $job['status'] ] : array( $job['status'], '' );
			$html .= '<div class="dev3jb-table__row" role="row">';
			$html .= '<span role="cell" class="dev3jb-table__main"><a class="dev3jb-table__title" href="' . esc_url( $job['url'] ) . '">' . esc_html( $job['title'] ) . '</a><span class="dev3jb-muted">' . esc_html( $job['company'] ? $job['company']['name'] : '' ) . ( $job['filled'] ? ' · ' . esc_html__( 'Filled', 'dev3-ai-job-board' ) : '' ) . ( $job['featured'] ? ' · ' . esc_html__( 'Featured', 'dev3-ai-job-board' ) : '' ) . '</span></span>';
			$html .= '<span role="cell" data-label="' . esc_attr__( 'Status', 'dev3-ai-job-board' ) . '"><span class="dev3jb-status dev3jb-status--' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span></span>';
			$html .= '<span role="cell" data-label="' . esc_attr__( 'Applications', 'dev3-ai-job-board' ) . '"><a class="dev3jb-link" href="' . esc_url( self::tab_url( 'applications', array( 'job' => $job['id'] ) ) ) . '">' . (int) $job['applications'] . '</a></span>';
			$html .= '<span role="cell" data-label="' . esc_attr__( 'Views', 'dev3-ai-job-board' ) . '">' . esc_html( number_format_i18n( $job['views'] ) ) . '</span>';
			$html .= '<span role="cell" data-label="' . esc_attr__( 'Expires', 'dev3-ai-job-board' ) . '">' . esc_html( $job['expires'] ? mysql2date( get_option( 'date_format' ), $job['expires'] ) : '—' ) . '</span>';
			$html .= '<span role="cell" class="dev3jb-table__actions">';
			$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( Submission::url( 'form', $job['id'] ) ) . '">' . esc_html__( 'Edit', 'dev3-ai-job-board' ) . '</a>';
			if ( 'publish' === $job['status'] ) {
				$html .= Forms::action_button( 'job_action', array( 'job_id' => $job['id'], 'do' => $job['filled'] ? 'unfill' : 'fill' ), $job['filled'] ? __( 'Reopen', 'dev3-ai-job-board' ) : __( 'Mark filled', 'dev3-ai-job-board' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			} elseif ( Post_Types::EXPIRED === $job['status'] ) {
				$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( Submission::url( 'package', $job['id'], array( 'relist' => 1 ) ) ) . '">' . esc_html__( 'Relist', 'dev3-ai-job-board' ) . '</a>';
			} elseif ( in_array( $job['status'], array( 'draft', Post_Types::UNPAID ), true ) ) {
				$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( Submission::url( 'package', $job['id'] ) ) . '">' . esc_html__( 'Publish', 'dev3-ai-job-board' ) . '</a>';
			}
			$html .= Forms::action_button( 'job_action', array( 'job_id' => $job['id'], 'do' => 'delete' ), __( 'Delete', 'dev3-ai-job-board' ), 'dev3jb-btn dev3jb-btn--danger dev3jb-btn--sm', __( 'Delete this job and its applications?', 'dev3-ai-job-board' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$html .= '</span></div>';
		}
		return $html . '</div>';
	}

	/**
	 * Applications tab: job picker + pipeline mount.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function applications_tab( $user ) {
		$jobs = Listings::search(
			array(
				'author'   => $user,
				'status'   => array( 'publish', Post_Types::EXPIRED, 'pending' ),
				'per_page' => 100,
			)
		);
		if ( ! $jobs['items'] ) {
			return '<div class="dev3jb-empty">' . Templates::icon( 'users', 28 ) . '<h3>' . esc_html__( 'No applications yet', 'dev3-ai-job-board' ) . '</h3><p>' . esc_html__( 'Applications appear here as soon as candidates apply to your jobs.', 'dev3-ai-job-board' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$current = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : $jobs['items'][0]['id'];
		if ( ! Listings::owner_can( $current, $user ) ) {
			$current = $jobs['items'][0]['id'];
		}
		$cfg  = array(
			'job'       => $current,
			'stages'    => Applications::stage_labels(),
			'match'     => (bool) Settings::get( 'match_for_employers', true ),
			'hideNames' => (bool) Settings::get( 'hide_names_in_pipeline', false ),
			'i18n'      => self::pipeline_i18n(),
		);
		$html = '<form class="dev3jb-pipebar" method="get" action="' . esc_url( (string) get_permalink() ) . '"><input type="hidden" name="dev3jb_tab" value="applications"><label for="dev3jb-pipe-job">' . esc_html__( 'Job', 'dev3-ai-job-board' ) . '</label><select id="dev3jb-pipe-job" name="job" data-dev3jb-autosubmit>';
		foreach ( $jobs['items'] as $job ) {
			/* translators: 1: job title, 2: number of applications */
			$html .= '<option value="' . (int) $job['id'] . '"' . selected( $current, $job['id'], false ) . '>' . esc_html( sprintf( __( '%1$s (%2$d)', 'dev3-ai-job-board' ), $job['title'], $job['applications'] ) ) . '</option>';
		}
		$html .= '</select><noscript><button class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" type="submit">' . esc_html__( 'Show', 'dev3-ai-job-board' ) . '</button></noscript></form>';
		$html .= '<div class="dev3jb-pipeline" data-dev3jb-pipeline="' . esc_attr( (string) wp_json_encode( $cfg ) ) . '"><p class="dev3jb-muted">' . esc_html__( 'Loading applications…', 'dev3-ai-job-board' ) . '</p></div>';
		return $html;
	}

	/**
	 * Strings for the pipeline app.
	 *
	 * @return array
	 */
	public static function pipeline_i18n() {
		return array(
			'empty'      => __( 'No applications in this stage.', 'dev3-ai-job-board' ),
			'none'       => __( 'No applications for this job yet.', 'dev3-ai-job-board' ),
			'moveTo'     => __( 'Move to', 'dev3-ai-job-board' ),
			'applied'    => __( 'Applied', 'dev3-ai-job-board' ),
			'open'       => __( 'Open application', 'dev3-ai-job-board' ),
			'match'      => __( 'Match', 'dev3-ai-job-board' ),
			'scoreAll'   => __( 'Score all applications', 'dev3-ai-job-board' ),
			'scoring'    => __( 'Scoring…', 'dev3-ai-job-board' ),
			'sortBoard'  => __( 'Board order', 'dev3-ai-job-board' ),
			'sortScore'  => __( 'Best match first', 'dev3-ai-job-board' ),
			'sortLabel'  => __( 'Sort cards', 'dev3-ai-job-board' ),
			'calc'       => __( 'Calculate match', 'dev3-ai-job-board' ),
			'recalc'     => __( 'Recalculate', 'dev3-ai-job-board' ),
			'decision'   => __( 'Decision support only: review every application yourself. The score never moves or rejects anyone.', 'dev3-ai-job-board' ),
			'fairness'   => __( 'Scored on skills, experience, role and location fit only. Names, contact details and personal characteristics are not used.', 'dev3-ai-job-board' ),
			'strengths'  => __( 'Strengths', 'dev3-ai-job-board' ),
			'gaps'       => __( 'Gaps to explore', 'dev3-ai-job-board' ),
			'criteria'   => array(
				'skills'     => __( 'Skills', 'dev3-ai-job-board' ),
				'experience' => __( 'Experience', 'dev3-ai-job-board' ),
				'role'       => __( 'Role fit', 'dev3-ai-job-board' ),
				'location'   => __( 'Location', 'dev3-ai-job-board' ),
			),
			'resume'     => __( 'Resume', 'dev3-ai-job-board' ),
			'download'   => __( 'Download', 'dev3-ai-job-board' ),
			'cover'      => __( 'Cover letter', 'dev3-ai-job-board' ),
			'profile'    => __( 'Profile', 'dev3-ai-job-board' ),
			'experience' => __( 'Experience', 'dev3-ai-job-board' ),
			'education'  => __( 'Education', 'dev3-ai-job-board' ),
			'skills'     => __( 'Skills', 'dev3-ai-job-board' ),
			'notes'      => __( 'Notes & activity', 'dev3-ai-job-board' ),
			'addNote'    => __( 'Add a private note', 'dev3-ai-job-board' ),
			'save'       => __( 'Save note', 'dev3-ai-job-board' ),
			'rating'     => __( 'Your rating', 'dev3-ai-job-board' ),
			'stage'      => __( 'Stage', 'dev3-ai-job-board' ),
			'notify'     => __( 'Email the candidate about this change', 'dev3-ai-job-board' ),
			'message'    => __( 'Personal message (optional)', 'dev3-ai-job-board' ),
			'update'     => __( 'Update stage', 'dev3-ai-job-board' ),
			'close'      => __( 'Close', 'dev3-ai-job-board' ),
			'present'    => __( 'now', 'dev3-ai-job-board' ),
			'years'      => __( 'years of experience', 'dev3-ai-job-board' ),
			'moved'      => __( 'Moved.', 'dev3-ai-job-board' ),
			'error'      => __( 'Something went wrong. Please try again.', 'dev3-ai-job-board' ),
			'candidate'  => __( 'Candidate', 'dev3-ai-job-board' ),
			'phone'      => __( 'Phone', 'dev3-ai-job-board' ),
			'email'      => __( 'Email', 'dev3-ai-job-board' ),
			'rules'      => __( 'Rule-based score', 'dev3-ai-job-board' ),
			'ai'         => __( 'Score with explanation', 'dev3-ai-job-board' ),
			'starLabel'  => __( '%d of 5 stars', 'dev3-ai-job-board' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		);
	}

	/**
	 * Companies tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function companies_tab( $user ) {
		$companies = Companies::for_user( $user );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$edit = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0;
		$html = '';
		if ( $companies ) {
			$html .= '<ul class="dev3jb-companylist">';
			foreach ( $companies as $c ) {
				/* translators: %d: open jobs */
				$html .= '<li>' . Templates::logo( $c, 'sm' ) . '<div><strong>' . esc_html( $c['name'] ) . '</strong><span class="dev3jb-muted">' . esc_html( sprintf( _n( '%d open job', '%d open jobs', $c['jobs'], 'dev3-ai-job-board' ), $c['jobs'] ) ) . '</span></div><div class="dev3jb-row">' . ( $c['url'] ? '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( $c['url'] ) . '">' . esc_html__( 'View', 'dev3-ai-job-board' ) . '</a>' : '' ) . '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( self::tab_url( 'companies', array( 'company' => $c['id'] ) ) ) . '#dev3jb-company-form">' . esc_html__( 'Edit', 'dev3-ai-job-board' ) . '</a></div></li>';
			}
			$html .= '</ul>';
		}
		$c     = $edit && Companies::owner_can( $edit, $user ) ? Companies::get( $edit ) : null;
		$html .= '<form class="dev3jb-form dev3jb-box" id="dev3jb-company-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Forms::guard_fields( 'save_company' );
		$html .= '<h3 class="dev3jb-h">' . esc_html( $c ? __( 'Edit company', 'dev3-ai-job-board' ) : __( 'Add a company', 'dev3-ai-job-board' ) ) . '</h3>';
		$html .= '<input type="hidden" name="company_id" value="' . (int) ( $c ? $c['id'] : 0 ) . '"><input type="hidden" name="return" value="' . esc_url( self::tab_url( 'companies' ) ) . '">';
		$val   = static function ( $k ) use ( $c ) {
			return $c && isset( $c[ $k ] ) ? (string) $c[ $k ] : '';
		};
		$html .= '<div class="dev3jb-grid2"><div class="dev3jb-field"><label for="dev3jb-co-name">' . esc_html__( 'Company name', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-co-name" name="company[name]" required maxlength="120" value="' . esc_attr( $val( 'name' ) ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-co-web">' . esc_html__( 'Website', 'dev3-ai-job-board' ) . '</label><input type="url" id="dev3jb-co-web" name="company[website]" value="' . esc_attr( $val( 'website' ) ) . '" placeholder="https://"></div></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-co-tag">' . esc_html__( 'Tagline', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-co-tag" name="company[tagline]" maxlength="160" value="' . esc_attr( $val( 'tagline' ) ) . '"></div>';
		$html .= '<div class="dev3jb-grid3"><div class="dev3jb-field"><label for="dev3jb-co-ind">' . esc_html__( 'Industry', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-co-ind" name="company[industry]" value="' . esc_attr( $val( 'industry' ) ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-co-size">' . esc_html__( 'Company size', 'dev3-ai-job-board' ) . '</label><select id="dev3jb-co-size" name="company[size]">';
		foreach ( Companies::SIZES as $size ) {
			$html .= '<option value="' . esc_attr( $size ) . '"' . selected( $val( 'size' ), $size, false ) . '>' . esc_html( '' === $size ? __( 'Not specified', 'dev3-ai-job-board' ) : $size ) . '</option>';
		}
		$html .= '</select></div><div class="dev3jb-field"><label for="dev3jb-co-hq">' . esc_html__( 'Headquarters', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-co-hq" name="company[headquarters]" value="' . esc_attr( $val( 'headquarters' ) ) . '"></div></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-co-desc">' . esc_html__( 'About the company', 'dev3-ai-job-board' ) . '</label><textarea id="dev3jb-co-desc" name="company[description]" rows="4">' . esc_textarea( wp_strip_all_tags( $val( 'description' ) ) ) . '</textarea></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-co-logo">' . esc_html__( 'Logo', 'dev3-ai-job-board' ) . '</label><input class="dev3jb-file" type="file" id="dev3jb-co-logo" name="company_logo" accept="image/png,image/jpeg,image/webp"><span class="dev3jb-help">' . esc_html__( 'Square PNG, JPG or WebP, up to 2 MB.', 'dev3-ai-job-board' ) . '</span></div>';
		$html .= '<div class="dev3jb-form__foot"><button type="submit" class="dev3jb-btn dev3jb-btn--primary">' . esc_html__( 'Save company', 'dev3-ai-job-board' ) . '</button>' . ( $c ? '<a class="dev3jb-btn dev3jb-btn--ghost" href="' . esc_url( self::tab_url( 'companies' ) ) . '">' . esc_html__( 'Cancel', 'dev3-ai-job-board' ) . '</a>' : '' ) . '</div></form>';
		return $html;
	}

	/**
	 * Packages tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function packages_tab( $user ) {
		$mine     = UserPackages::for_user( $user );
		$payments = Payments::recent( 50, $user );
		$html     = '<h3 class="dev3jb-h">' . esc_html__( 'Your packages', 'dev3-ai-job-board' ) . '</h3>';
		if ( $mine ) {
			$status = array(
				'active'  => __( 'Active', 'dev3-ai-job-board' ),
				'pending' => __( 'Awaiting payment', 'dev3-ai-job-board' ),
				'used'    => __( 'Used up', 'dev3-ai-job-board' ),
			);
			$html  .= '<ul class="dev3jb-credits">';
			foreach ( $mine as $p ) {
				/* translators: 1: used, 2: limit */
				$html .= '<li><div><strong>' . esc_html( $p['name'] ) . '</strong><span class="dev3jb-muted">' . esc_html( sprintf( __( '%1$d of %2$d job posts used', 'dev3-ai-job-board' ), $p['jobs_used'], $p['job_limit'] ) ) . '</span></div><span class="dev3jb-status dev3jb-status--' . esc_attr( 'active' === $p['status'] ? 'ok' : ( 'pending' === $p['status'] ? 'info' : '' ) ) . '">' . esc_html( isset( $status[ $p['status'] ] ) ? $status[ $p['status'] ] : $p['status'] ) . '</span></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="dev3jb-muted">' . esc_html__( 'You have no packages yet. Buy one below or when you post a job.', 'dev3-ai-job-board' ) . '</p>';
		}
		$paid = array_filter( Packages::active(), array( Packages::class, 'is_paid' ) );
		if ( $paid ) {
			$html .= '<h3 class="dev3jb-h">' . esc_html__( 'Buy job posts in advance', 'dev3-ai-job-board' ) . '</h3><div class="dev3jb-packages dev3jb-packages--buy">';
			foreach ( $paid as $p ) {
				/* translators: 1: job posts, 2: days */
				$html .= '<div class="dev3jb-package' . ( $p['highlight'] ? ' is-highlight' : '' ) . '"><span class="dev3jb-package__body"><span class="dev3jb-package__name">' . esc_html( $p['name'] ) . '</span><span class="dev3jb-package__price">' . esc_html( html_entity_decode( Listings::money( $p['price'] ), ENT_QUOTES, 'UTF-8' ) ) . '</span><span class="dev3jb-package__meta">' . esc_html( sprintf( _n( '%1$d job post · %2$d days', '%1$d job posts · %2$d days each', $p['job_limit'], 'dev3-ai-job-board' ), $p['job_limit'], $p['duration_days'] ) ) . '</span></span>' . Forms::action_button( 'buy_package', array( 'package' => $p['id'] ), __( 'Buy', 'dev3-ai-job-board' ), 'dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm' ) . '</div>';
			}
			$html .= '</div>';
		}
		if ( $payments ) {
			$html .= '<h3 class="dev3jb-h">' . esc_html__( 'Payment history', 'dev3-ai-job-board' ) . '</h3><ul class="dev3jb-credits">';
			foreach ( $payments as $pay ) {
				$html .= '<li><div><strong>' . esc_html( $pay['package_name'] ) . '</strong><span class="dev3jb-muted">#' . (int) $pay['id'] . ' · ' . esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( $pay['created_at'] ) ) ) . ( $pay['job_title'] ? ' · ' . esc_html( $pay['job_title'] ) : '' ) . '</span></div><span>' . esc_html( html_entity_decode( Listings::money( $pay['amount'], $pay['currency'] ), ENT_QUOTES, 'UTF-8' ) ) . ' · <span class="dev3jb-status dev3jb-status--' . esc_attr( 'paid' === $pay['status'] ? 'ok' : 'info' ) . '">' . esc_html( 'paid' === $pay['status'] ? __( 'Paid', 'dev3-ai-job-board' ) : __( 'Pending', 'dev3-ai-job-board' ) ) . '</span></span></li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * Fill / unfill / delete.
	 */
	public static function job_action() {
		$back = Checkout::dashboard_url();
		if ( ! Forms::check_action( 'job_action' ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_action().
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! Listings::owner_can( $job_id, get_current_user_id() ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_forbidden', __( 'You cannot change this job.', 'dev3-ai-job-board' ) ) );
		}
		if ( 'fill' === $do || 'unfill' === $do ) {
			update_post_meta( $job_id, '_dev3jb_filled', 'fill' === $do ? 1 : 0 );
			Forms::back( $back, 'fill' === $do ? 'filled' : 'unfilled' );
		}
		if ( 'delete' === $do ) {
			foreach ( Applications::query(
				array(
					'job_id'            => $job_id,
					'per_page'          => 500,
					'include_withdrawn' => true,
				)
			)['items'] as $app ) {
				Applications::delete( $app['id'] );
			}
			wp_trash_post( $job_id );
			Forms::back( $back, 'deleted' );
		}
		Forms::back( $back, '' );
	}

	/**
	 * Company form handler.
	 */
	public static function save_company() {
		$back  = Forms::return_url( self::tab_url( 'companies' ) );
		$guard = Forms::check_guard( 'save_company', 20, 0 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		$user = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$id   = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$raw  = isset( $_POST['company'] ) && is_array( $_POST['company'] ) ? wp_unslash( $_POST['company'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Companies::sanitize().
		$logo = isset( $_FILES['company_logo'] ) ? Submission::upload_logo( map_deep( wp_unslash( $_FILES['company_logo'] ), 'sanitize_text_field' ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $user || ( $id && ! Companies::owner_can( $id, $user ) ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_forbidden', __( 'You cannot edit this company.', 'dev3-ai-job-board' ) ) );
		}
		if ( is_wp_error( $logo ) ) {
			Forms::back( $back, $logo );
		}
		$data = Companies::sanitize( (array) $raw );
		unset( $data['logo_id'] );
		if ( $logo ) {
			$data['logo_id'] = $logo;
		}
		$res = Companies::save( $data, $id, $user, 'publish' );
		Forms::back( remove_query_arg( 'company', $back ), is_wp_error( $res ) ? $res : 'company_saved' );
	}

	/**
	 * Buys credits without a job.
	 */
	public static function buy_package() {
		$back = add_query_arg( 'dev3jb_tab', 'packages', Settings::page_url( 'employer' ) );
		if ( ! Forms::check_action( 'buy_package' ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) ) );
		}
		$package = isset( $_POST['package'] ) ? sanitize_key( wp_unslash( $_POST['package'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res     = Checkout::start( get_current_user_id(), $package, 0 );
		if ( is_wp_error( $res ) ) {
			Forms::back( $back, $res );
		}
		wp_safe_redirect( $res['redirect'] );
		exit;
	}
}
