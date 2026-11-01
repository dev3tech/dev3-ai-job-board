<?php
/**
 * Candidate dashboard: profile + resume (with parsing), applications, saved jobs, alerts, recommended jobs.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Ai\Resume_Parser;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\Saved;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static dashboard.
 */
class Candidate_Dashboard {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_dev3jb_save_profile', array( __CLASS__, 'save_profile' ) );
		add_action( 'admin_post_dev3jb_withdraw', array( __CLASS__, 'withdraw' ) );
		add_action( 'admin_post_dev3jb_unsave', array( __CLASS__, 'unsave' ) );
		add_action( 'admin_post_dev3jb_alert_delete', array( __CLASS__, 'alert_delete' ) );
		add_action( 'admin_post_dev3jb_alert_create', array( __CLASS__, 'alert_create' ) );
	}

	/**
	 * Tab URL.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return add_query_arg( 'dev3jb_tab', $tab, Settings::page_url( 'candidate' ) );
	}

	/**
	 * Renders the dashboard.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return Account::gate( 'candidate', __( 'Your job search', 'dev3-ai-job-board' ), __( 'Create a free profile to apply in one click, track your applications and get job alerts.', 'dev3-ai-job-board' ) );
		}
		$tabs = array(
			'profile'      => __( 'Profile', 'dev3-ai-job-board' ),
			'applications' => __( 'Applications', 'dev3-ai-job-board' ),
			'saved'        => __( 'Saved jobs', 'dev3-ai-job-board' ),
			'alerts'       => __( 'Job alerts', 'dev3-ai-job-board' ),
			'recommended'  => __( 'Recommended', 'dev3-ai-job-board' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation.
		$tab     = isset( $_GET['dev3jb_tab'] ) ? sanitize_key( wp_unslash( $_GET['dev3jb_tab'] ) ) : 'profile';
		$tab     = isset( $tabs[ $tab ] ) ? $tab : 'profile';
		$user    = get_current_user_id();
		$profile = Resumes::for_user( $user );
		$html    = '<div class="dev3jb dev3jb-dash">';
		$html   .= '<div class="dev3jb-dash__head"><div><h2 class="dev3jb-h">' . esc_html( wp_get_current_user()->display_name ) . '</h2><p class="dev3jb-muted">' . esc_html( $profile['headline'] ? $profile['headline'] : __( 'Complete your profile to apply faster.', 'dev3-ai-job-board' ) ) . '</p></div>';
		/* translators: %d: percent */
		$html .= '<div class="dev3jb-meter" role="img" aria-label="' . esc_attr( sprintf( __( 'Profile %d%% complete', 'dev3-ai-job-board' ), $profile['complete'] ) ) . '"><span class="dev3jb-meter__bar" style="--dev3jb-pct:' . (int) $profile['complete'] . '%"></span><span class="dev3jb-meter__label">' . esc_html( sprintf( __( 'Profile %d%% complete', 'dev3-ai-job-board' ), $profile['complete'] ) ) . '</span></div></div>';
		$html .= '<nav class="dev3jb-tabs" aria-label="' . esc_attr__( 'Dashboard sections', 'dev3-ai-job-board' ) . '"><ul>';
		foreach ( $tabs as $key => $label ) {
			$html .= '<li><a href="' . esc_url( self::tab_url( $key ) ) . '"' . ( $key === $tab ? ' aria-current="page" class="is-active"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></nav>';
		$html .= Forms::notices(
			array(
				'profile_saved' => __( 'Profile saved.', 'dev3-ai-job-board' ),
				'withdrawn'     => __( 'Application withdrawn.', 'dev3-ai-job-board' ),
				'unsaved'       => __( 'Removed from saved jobs.', 'dev3-ai-job-board' ),
				'alert_deleted' => __( 'Alert deleted.', 'dev3-ai-job-board' ),
				'alert_created' => __( 'Alert created.', 'dev3-ai-job-board' ),
				'registered'    => __( 'Welcome! Upload your resume to fill in your profile in seconds.', 'dev3-ai-job-board' ),
			)
		);
		$html .= '<div class="dev3jb-dash__panel">';
		switch ( $tab ) {
			case 'applications':
				$html .= self::applications_tab( $user );
				break;
			case 'saved':
				$html .= self::saved_tab( $user );
				break;
			case 'alerts':
				$html .= self::alerts_tab( $user );
				break;
			case 'recommended':
				$html .= self::recommended_tab( $user );
				break;
			default:
				$html .= self::profile_tab( $profile );
		}
		return $html . '</div></div>';
	}

	/**
	 * Experience row fields.
	 *
	 * @param string $i   Index (or __i__ for the template).
	 * @param array  $row Row.
	 * @return string
	 */
	private static function exp_row( $i, array $row ) {
		$n     = 'profile[experience][' . $i . ']';
		$id    = 'dev3jb-exp-' . $i;
		$html  = '<div class="dev3jb-repeat__row" data-dev3jb-row><div class="dev3jb-grid2">';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-t">' . esc_html__( 'Job title', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-t" name="' . esc_attr( $n ) . '[title]" data-k="title" value="' . esc_attr( isset( $row['title'] ) ? $row['title'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-c">' . esc_html__( 'Company', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-c" name="' . esc_attr( $n ) . '[company]" data-k="company" value="' . esc_attr( isset( $row['company'] ) ? $row['company'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-s">' . esc_html__( 'From', 'dev3-ai-job-board' ) . '</label><input type="month" id="' . esc_attr( $id ) . '-s" name="' . esc_attr( $n ) . '[start]" data-k="start" placeholder="YYYY-MM" value="' . esc_attr( isset( $row['start'] ) ? $row['start'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-e">' . esc_html__( 'To', 'dev3-ai-job-board' ) . '</label><input type="month" id="' . esc_attr( $id ) . '-e" name="' . esc_attr( $n ) . '[end]" data-k="end" placeholder="YYYY-MM" value="' . esc_attr( isset( $row['end'] ) ? $row['end'] : '' ) . '"></div>';
		$html .= '</div><label class="dev3jb-check"><input type="checkbox" name="' . esc_attr( $n ) . '[current]" value="1" data-k="current"' . checked( ! empty( $row['current'] ), true, false ) . '> <span>' . esc_html__( 'I work here now', 'dev3-ai-job-board' ) . '</span></label>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-d">' . esc_html__( 'What you did', 'dev3-ai-job-board' ) . '</label><textarea id="' . esc_attr( $id ) . '-d" name="' . esc_attr( $n ) . '[summary]" data-k="summary" rows="3">' . esc_textarea( isset( $row['summary'] ) ? $row['summary'] : '' ) . '</textarea></div>';
		$html .= '<button type="button" class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-remove>' . Templates::icon( 'trash', 15 ) . esc_html__( 'Remove', 'dev3-ai-job-board' ) . '</button></div>';
		return $html;
	}

	/**
	 * Education row fields.
	 *
	 * @param string $i   Index.
	 * @param array  $row Row.
	 * @return string
	 */
	private static function edu_row( $i, array $row ) {
		$n     = 'profile[education][' . $i . ']';
		$id    = 'dev3jb-edu-' . $i;
		$html  = '<div class="dev3jb-repeat__row" data-dev3jb-row><div class="dev3jb-grid2">';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-d">' . esc_html__( 'Degree or certificate', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-d" name="' . esc_attr( $n ) . '[degree]" data-k="degree" value="' . esc_attr( isset( $row['degree'] ) ? $row['degree'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-f">' . esc_html__( 'Field of study', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-f" name="' . esc_attr( $n ) . '[field]" data-k="field" value="' . esc_attr( isset( $row['field'] ) ? $row['field'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-s">' . esc_html__( 'School', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-s" name="' . esc_attr( $n ) . '[school]" data-k="school" value="' . esc_attr( isset( $row['school'] ) ? $row['school'] : '' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-y">' . esc_html__( 'Year', 'dev3-ai-job-board' ) . '</label><input type="text" inputmode="numeric" maxlength="4" id="' . esc_attr( $id ) . '-y" name="' . esc_attr( $n ) . '[year]" data-k="year" value="' . esc_attr( isset( $row['year'] ) ? $row['year'] : '' ) . '"></div>';
		$html .= '</div><button type="button" class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-remove>' . Templates::icon( 'trash', 15 ) . esc_html__( 'Remove', 'dev3-ai-job-board' ) . '</button></div>';
		return $html;
	}

	/**
	 * Link row.
	 *
	 * @param string $i   Index.
	 * @param array  $row Row.
	 * @return string
	 */
	private static function link_row( $i, array $row ) {
		$n  = 'profile[links][' . $i . ']';
		$id = 'dev3jb-link-' . $i;
		return '<div class="dev3jb-repeat__row dev3jb-repeat__row--inline" data-dev3jb-row><div class="dev3jb-field"><label for="' . esc_attr( $id ) . '-l">' . esc_html__( 'Label', 'dev3-ai-job-board' ) . '</label><input type="text" id="' . esc_attr( $id ) . '-l" name="' . esc_attr( $n ) . '[label]" data-k="label" value="' . esc_attr( isset( $row['label'] ) ? $row['label'] : '' ) . '" placeholder="GitHub"></div><div class="dev3jb-field dev3jb-field--grow"><label for="' . esc_attr( $id ) . '-u">' . esc_html__( 'Link', 'dev3-ai-job-board' ) . '</label><input type="url" id="' . esc_attr( $id ) . '-u" name="' . esc_attr( $n ) . '[url]" data-k="url" value="' . esc_attr( isset( $row['url'] ) ? $row['url'] : '' ) . '" placeholder="https://"></div><button type="button" class="dev3jb-iconbtn" data-dev3jb-remove aria-label="' . esc_attr__( 'Remove link', 'dev3-ai-job-board' ) . '">' . Templates::icon( 'trash', 16 ) . '</button></div>';
	}

	/**
	 * Profile tab.
	 *
	 * @param array $p Profile.
	 * @return string
	 */
	public static function profile_tab( array $p ) {
		$ai    = Resume_Parser::ai_available( 'frontend' );
		$cfg   = array(
			'ai'   => $ai,
			'i18n' => array(
				'uploading' => __( 'Uploading your resume…', 'dev3-ai-job-board' ),
				'reading'   => $ai ? __( 'Reading your resume… this can take up to a minute.', 'dev3-ai-job-board' ) : __( 'Reading your resume…', 'dev3-ai-job-board' ),
				/* translators: %s: list of sections */
				'filled'    => __( 'We filled in: %s. Check every field, then save your profile.', 'dev3-ai-job-board' ),
				'nothing'   => __( 'Your resume is attached, but we could not read details from it. Fill in your profile below.', 'dev3-ai-job-board' ),
				'sections'  => array(
					'headline'   => __( 'headline', 'dev3-ai-job-board' ),
					'summary'    => __( 'summary', 'dev3-ai-job-board' ),
					'location'   => __( 'location', 'dev3-ai-job-board' ),
					'skills'     => __( 'skills', 'dev3-ai-job-board' ),
					'experience' => __( 'experience', 'dev3-ai-job-board' ),
					'education'  => __( 'education', 'dev3-ai-job-board' ),
					'links'      => __( 'links', 'dev3-ai-job-board' ),
				),
				'replace'   => __( 'Replace the details already in your profile with the ones from this resume?', 'dev3-ai-job-board' ),
				'unsaved'   => __( 'Not saved yet', 'dev3-ai-job-board' ),
			),
		);
		$wp    = Listings::workplace_labels();
		$html  = '<form class="dev3jb-form dev3jb-profile" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-dev3jb-profile="' . esc_attr( (string) wp_json_encode( $cfg ) ) . '">' . Forms::guard_fields( 'save_profile' );
		$html .= '<input type="hidden" name="return" value="' . esc_url( self::tab_url( 'profile' ) ) . '"><input type="hidden" name="profile[file_id]" value="' . (int) $p['file_id'] . '" data-dev3jb-file-id><input type="hidden" name="parsed_from" value="0" data-dev3jb-parsed>';
		$html .= '<section class="dev3jb-dropzone" data-dev3jb-resume><div class="dev3jb-dropzone__icon">' . Templates::icon( 'upload', 26 ) . '</div><div class="dev3jb-dropzone__text"><strong>' . esc_html( $p['file'] ? __( 'Your resume', 'dev3-ai-job-board' ) : __( 'Upload your resume', 'dev3-ai-job-board' ) ) . '</strong>';
		$html .= '<span class="dev3jb-muted" data-dev3jb-file-name>' . esc_html( $p['file'] ? $p['file']['name'] : __( 'PDF, Word (.docx) or text. We fill in your profile from it, and you check everything before saving.', 'dev3-ai-job-board' ) ) . '</span>';
		$html .= '</div><div class="dev3jb-row">';
		if ( $p['file'] ) {
			$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" href="' . esc_url( $p['file']['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'dev3-ai-job-board' ) . '</a>';
		}
		$html .= '<label class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm dev3jb-filebtn"><input type="file" accept=".pdf,.docx,.txt,application/pdf" data-dev3jb-resume-input><span>' . esc_html( $p['file'] ? __( 'Upload a new one', 'dev3-ai-job-board' ) : __( 'Choose file', 'dev3-ai-job-board' ) ) . '</span></label>';
		$html .= '</div><p class="dev3jb-form__status" role="status" aria-live="polite" data-dev3jb-resume-status></p></section>';

		$html .= '<fieldset class="dev3jb-section"><legend class="dev3jb-h">' . esc_html__( 'About you', 'dev3-ai-job-board' ) . '</legend>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-p-head">' . esc_html__( 'Headline', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-p-head" name="profile[headline]" maxlength="140" data-dev3jb-p="headline" value="' . esc_attr( $p['headline'] ) . '" placeholder="' . esc_attr__( 'e.g. Senior Frontend Developer', 'dev3-ai-job-board' ) . '"></div>';
		$html .= '<div class="dev3jb-grid2"><div class="dev3jb-field"><label for="dev3jb-p-loc">' . esc_html__( 'City and country', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-p-loc" name="profile[location]" maxlength="120" data-dev3jb-p="location" value="' . esc_attr( $p['location'] ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-p-phone">' . esc_html__( 'Phone (shared only when you apply)', 'dev3-ai-job-board' ) . '</label><input type="tel" id="dev3jb-p-phone" name="profile[phone]" maxlength="40" autocomplete="tel" value="' . esc_attr( $p['phone'] ) . '"></div></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-p-sum">' . esc_html__( 'Summary', 'dev3-ai-job-board' ) . '</label><textarea id="dev3jb-p-sum" name="profile[summary]" rows="4" maxlength="2000" data-dev3jb-p="summary">' . esc_textarea( $p['summary'] ) . '</textarea></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-p-skills">' . esc_html__( 'Skills', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-p-skills" name="profile[skills]" data-dev3jb-p="skills" value="' . esc_attr( implode( ', ', $p['skills'] ) ) . '"><span class="dev3jb-help">' . esc_html__( 'Separate skills with commas. Employers see how your skills match their jobs.', 'dev3-ai-job-board' ) . '</span></div>';
		$html .= '<fieldset class="dev3jb-field"><legend>' . esc_html__( 'Where do you want to work?', 'dev3-ai-job-board' ) . '</legend><div class="dev3jb-pills">';
		foreach ( $wp as $k => $label ) {
			$html .= '<label class="dev3jb-pill"><input type="checkbox" name="profile[workplace][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $p['workplace'], true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		$html .= '</div></fieldset><fieldset class="dev3jb-field"><legend>' . esc_html__( 'Job types you want', 'dev3-ai-job-board' ) . '</legend><div class="dev3jb-pills">';
		foreach ( Post_Types::terms( Post_Types::TYPE ) as $t ) {
			$html .= '<label class="dev3jb-pill"><input type="checkbox" name="profile[job_types][]" value="' . esc_attr( $t['slug'] ) . '"' . checked( in_array( $t['slug'], $p['job_types'], true ), true, false ) . '><span>' . esc_html( $t['name'] ) . '</span></label>';
		}
		$html .= '</div></fieldset></fieldset>';

		$sections = array(
			'experience' => array( __( 'Experience', 'dev3-ai-job-board' ), __( 'Add a job', 'dev3-ai-job-board' ), 'exp_row' ),
			'education'  => array( __( 'Education', 'dev3-ai-job-board' ), __( 'Add education', 'dev3-ai-job-board' ), 'edu_row' ),
			'links'      => array( __( 'Links', 'dev3-ai-job-board' ), __( 'Add a link', 'dev3-ai-job-board' ), 'link_row' ),
		);
		foreach ( $sections as $key => $conf ) {
			$rows  = $p[ $key ];
			$html .= '<fieldset class="dev3jb-section dev3jb-repeat" data-dev3jb-repeat="' . esc_attr( $key ) . '"><legend class="dev3jb-h">' . esc_html( $conf[0] ) . '</legend><div class="dev3jb-repeat__rows" data-dev3jb-rows>';
			foreach ( $rows as $i => $row ) {
				$html .= call_user_func( array( __CLASS__, $conf[2] ), (string) $i, $row );
			}
			$html .= '</div><template data-dev3jb-template>' . call_user_func( array( __CLASS__, $conf[2] ), '__i__', array() ) . '</template>';
			$html .= '<button type="button" class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-add>' . Templates::icon( 'plus', 15 ) . esc_html( $conf[1] ) . '</button></fieldset>';
		}
		$html .= '<p class="dev3jb-muted dev3jb-small">' . esc_html__( 'Do not add your age, date of birth, photo, marital status, nationality or other personal characteristics: employers on this site do not need them, and they are never used for matching.', 'dev3-ai-job-board' ) . '</p>';
		$html .= '<div class="dev3jb-form__foot dev3jb-form__foot--sticky"><span class="dev3jb-unsaved" data-dev3jb-unsaved hidden>' . esc_html__( 'You have unsaved changes', 'dev3-ai-job-board' ) . '</span><button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--lg">' . esc_html__( 'Save profile', 'dev3-ai-job-board' ) . '</button></div></form>';
		return $html;
	}

	/**
	 * Applications tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function applications_tab( $user ) {
		$apps = Applications::query(
			array(
				'candidate_id'      => $user,
				'per_page'          => 100,
				'include_withdrawn' => true,
			)
		)['items'];
		if ( ! $apps ) {
			return '<div class="dev3jb-empty">' . Templates::icon( 'briefcase', 28 ) . '<h3>' . esc_html__( 'No applications yet', 'dev3-ai-job-board' ) . '</h3><p><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( Settings::page_url( 'jobs' ) ) . '">' . esc_html__( 'Find jobs', 'dev3-ai-job-board' ) . '</a></p></div>';
		}
		$labels = Applications::candidate_labels();
		$tones  = array(
			'new'       => 'info',
			'screening' => 'info',
			'interview' => 'warn',
			'offer'     => 'ok',
			'hired'     => 'ok',
			'rejected'  => '',
			'withdrawn' => '',
		);
		$html   = '<ul class="dev3jb-credits">';
		foreach ( $apps as $a ) {
			$job     = Listings::get( $a['job_id'] );
			$company = $job && $job['company'] ? $job['company']['name'] : '';
			$html   .= '<li><div><a class="dev3jb-table__title" href="' . esc_url( $job ? $job['url'] : '#' ) . '">' . esc_html( $a['job_title'] ) . '</a><span class="dev3jb-muted">' . esc_html( trim( $company . ' · ' . sprintf( /* translators: %s: date */ __( 'Applied %s', 'dev3-ai-job-board' ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( $a['created_at'] ) ) ), ' ·' ) ) . '</span></div>';
			$html   .= '<div class="dev3jb-row"><span class="dev3jb-status dev3jb-status--' . esc_attr( $tones[ $a['stage'] ] ?? '' ) . '">' . esc_html( $labels[ $a['stage'] ] ?? $a['stage'] ) . '</span>';
			if ( ! in_array( $a['stage'], array( 'withdrawn', 'hired', 'rejected' ), true ) ) {
				$html .= Forms::action_button( 'withdraw', array( 'application' => $a['id'] ), __( 'Withdraw', 'dev3-ai-job-board' ), 'dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm', __( 'Withdraw this application?', 'dev3-ai-job-board' ) );
			}
			$html .= '</div></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Saved jobs tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function saved_tab( $user ) {
		$ids  = Saved::ids( $user );
		$html = '';
		foreach ( $ids as $id ) {
			$job = Listings::get( $id );
			if ( ! $job || 'publish' !== $job['status'] ) {
				continue;
			}
			$html .= self::card_with_action( $job, Forms::action_button( 'unsave', array( 'job_id' => $id ), __( 'Remove', 'dev3-ai-job-board' ) ) );
		}
		if ( '' === $html ) {
			return '<div class="dev3jb-empty">' . Templates::icon( 'heart', 28 ) . '<h3>' . esc_html__( 'No saved jobs', 'dev3-ai-job-board' ) . '</h3><p>' . esc_html__( 'Tap the heart on any job to keep it here.', 'dev3-ai-job-board' ) . '</p></div>';
		}
		return '<ul class="dev3jb-list">' . $html . '</ul>';
	}

	/**
	 * Card with an extra action.
	 *
	 * @param array  $job    Job.
	 * @param string $action Action HTML.
	 * @return string
	 */
	private static function card_with_action( array $job, $action ) {
		$card = Templates::job_card( $job, array( 'compact' => true ) );
		return str_replace( '</div></li>', $action . '</div></li>', $card );
	}

	/**
	 * Alerts tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function alerts_tab( $user ) {
		$alerts = Alerts::for_user( $user );
		$html   = '';
		if ( $alerts ) {
			$html .= '<ul class="dev3jb-credits">';
			foreach ( $alerts as $a ) {
				$html .= '<li><div><strong>' . esc_html( $a['label'] ) . '</strong><span class="dev3jb-muted">' . esc_html( 'weekly' === $a['frequency'] ? __( 'Weekly email', 'dev3-ai-job-board' ) : __( 'Daily email', 'dev3-ai-job-board' ) ) . '</span></div>' . Forms::action_button( 'alert_delete', array( 'alert' => $a['id'] ), __( 'Delete', 'dev3-ai-job-board' ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<form class="dev3jb-form dev3jb-box" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Forms::guard_fields( 'alert_create' ) . '<input type="hidden" name="return" value="' . esc_url( self::tab_url( 'alerts' ) ) . '">';
		$html .= '<h3 class="dev3jb-h">' . esc_html__( 'New job alert', 'dev3-ai-job-board' ) . '</h3><div class="dev3jb-grid2">';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-al-q">' . esc_html__( 'Keywords', 'dev3-ai-job-board' ) . '</label><input type="text" id="dev3jb-al-q" name="q" placeholder="' . esc_attr__( 'e.g. designer', 'dev3-ai-job-board' ) . '"></div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-al-cat">' . esc_html__( 'Category', 'dev3-ai-job-board' ) . '</label>' . Templates::term_select( 'category', Post_Types::CATEGORY, '', __( 'All categories', 'dev3-ai-job-board' ), 'dev3jb-al-cat' ) . '</div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-al-loc">' . esc_html__( 'Location', 'dev3-ai-job-board' ) . '</label>' . Templates::term_select( 'location', Post_Types::LOCATION, '', __( 'Anywhere', 'dev3-ai-job-board' ), 'dev3jb-al-loc' ) . '</div>';
		$html .= '<div class="dev3jb-field"><label for="dev3jb-al-freq">' . esc_html__( 'How often', 'dev3-ai-job-board' ) . '</label><select id="dev3jb-al-freq" name="frequency"><option value="daily">' . esc_html__( 'Daily', 'dev3-ai-job-board' ) . '</option><option value="weekly">' . esc_html__( 'Weekly', 'dev3-ai-job-board' ) . '</option></select></div>';
		$html .= '</div><label class="dev3jb-check"><input type="checkbox" name="workplace" value="remote"> <span>' . esc_html__( 'Remote jobs only', 'dev3-ai-job-board' ) . '</span></label>';
		$html .= '<div class="dev3jb-form__foot"><button type="submit" class="dev3jb-btn dev3jb-btn--primary">' . esc_html__( 'Create alert', 'dev3-ai-job-board' ) . '</button></div></form>';
		return $html;
	}

	/**
	 * Recommended jobs tab.
	 *
	 * @param int $user User.
	 * @return string
	 */
	public static function recommended_tab( $user ) {
		$rows = Matcher::recommend( $user, 8 );
		if ( ! $rows ) {
			return '<div class="dev3jb-empty">' . Templates::icon( 'sparkles', 28 ) . '<h3>' . esc_html__( 'Add your skills to see recommendations', 'dev3-ai-job-board' ) . '</h3><p>' . esc_html__( 'We suggest jobs based on the skills and experience in your profile.', 'dev3-ai-job-board' ) . '</p><p><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( self::tab_url( 'profile' ) ) . '">' . esc_html__( 'Complete your profile', 'dev3-ai-job-board' ) . '</a></p></div>';
		}
		$html = '<p class="dev3jb-muted">' . esc_html__( 'Jobs that fit the skills and experience in your profile.', 'dev3-ai-job-board' ) . '</p><ul class="dev3jb-list">';
		foreach ( $rows as $r ) {
			$html .= Templates::job_card( $r['job'], array( 'reasons' => $r['reasons'] ) );
		}
		return $html . '</ul>';
	}

	/**
	 * Profile form handler.
	 */
	public static function save_profile() {
		$back  = Forms::return_url( self::tab_url( 'profile' ) );
		$guard = Forms::check_guard( 'save_profile', 20, 0 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$raw         = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Resumes::sanitize().
		$parsed_from = isset( $_POST['parsed_from'] ) ? absint( $_POST['parsed_from'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'workplace', 'job_types', 'experience', 'education', 'links' ) as $k ) {
			if ( ! isset( $raw[ $k ] ) ) {
				$raw[ $k ] = array();
			}
		}
		foreach ( array( 'experience', 'education', 'links' ) as $k ) {
			$raw[ $k ] = array_values( (array) $raw[ $k ] );
		}
		$data = Resumes::sanitize( (array) $raw );
		if ( $parsed_from ) {
			$data['parsed_from'] = $parsed_from;
		}
		$user = get_current_user_id();
		$res  = Resumes::save( $user, $data );
		if ( ! is_wp_error( $res ) ) {
			$wp_user = get_userdata( $user );
			if ( $wp_user && ! in_array( Post_Types::ROLE_CANDIDATE, (array) $wp_user->roles, true ) && ! user_can( $wp_user, 'edit_others_posts' ) && ! in_array( Post_Types::ROLE_EMPLOYER, (array) $wp_user->roles, true ) ) {
				$wp_user->add_role( Post_Types::ROLE_CANDIDATE );
			}
		}
		Forms::back( $back, is_wp_error( $res ) ? $res : 'profile_saved' );
	}

	/**
	 * Withdraw handler.
	 */
	public static function withdraw() {
		$back = self::tab_url( 'applications' );
		if ( ! Forms::check_action( 'withdraw' ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) ) );
		}
		$id  = isset( $_POST['application'] ) ? absint( $_POST['application'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res = Applications::withdraw( $id, get_current_user_id() );
		Forms::back( $back, is_wp_error( $res ) ? $res : 'withdrawn' );
	}

	/**
	 * Unsave handler.
	 */
	public static function unsave() {
		$back = self::tab_url( 'saved' );
		if ( ! Forms::check_action( 'unsave' ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) ) );
		}
		Saved::set( get_current_user_id(), isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0, false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Forms::back( $back, 'unsaved' );
	}

	/**
	 * Alert delete handler.
	 */
	public static function alert_delete() {
		$back = self::tab_url( 'alerts' );
		if ( ! Forms::check_action( 'alert_delete' ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_bad_nonce', __( 'Your session expired. Reload the page and try again.', 'dev3-ai-job-board' ) ) );
		}
		Alerts::delete( isset( $_POST['alert'] ) ? absint( $_POST['alert'] ) : 0, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Forms::back( $back, 'alert_deleted' );
	}

	/**
	 * Alert create handler.
	 */
	public static function alert_create() {
		$back  = Forms::return_url( self::tab_url( 'alerts' ) );
		$guard = Forms::check_guard( 'alert_create', 10, 0 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		$user = wp_get_current_user();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$query = array(
			'q'         => isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '',
			'category'  => isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '',
			'location'  => isset( $_POST['location'] ) ? sanitize_title( wp_unslash( $_POST['location'] ) ) : '',
			'workplace' => isset( $_POST['workplace'] ) && 'remote' === $_POST['workplace'] ? 'remote' : '',
		);
		$freq  = isset( $_POST['frequency'] ) && 'weekly' === $_POST['frequency'] ? 'weekly' : 'daily';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$res = Alerts::create( $query, $user->user_email, $user->ID, $freq );
		Forms::back( $back, is_wp_error( $res ) ? $res : 'alert_created' );
	}
}
