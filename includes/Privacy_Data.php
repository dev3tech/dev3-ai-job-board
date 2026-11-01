<?php
/**
 * GDPR exporter/eraser for applications, candidate profiles, resume files and job alerts.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard;

use Dev3\AiJobBoard\Core3\Core\Privacy;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Static privacy hooks.
 */
class Privacy_Data {

	/**
	 * Registers the exporter/eraser and the policy text addition.
	 */
	public static function init() {
		Privacy::add_exporter( __( 'Job applications and profile', 'dev3-ai-job-board' ), array( __CLASS__, 'export' ) );
		Privacy::add_eraser( __( 'Job applications and profile', 'dev3-ai-job-board' ), array( __CLASS__, 'erase' ) );
		add_filter( 'dev3jb_privacy_policy_text', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Adds job-board specifics to the suggested privacy policy text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function policy( $text ) {
		return $text . '<p>' . esc_html__( 'When you apply for a job, we share your application (name, email, phone, cover letter, profile and resume file) with the employer who posted it. Resume files are stored privately and are only available to you, that employer and site administrators. If DEV3 AI features are enabled, your resume text may be sent to Google Gemini to fill in your profile, and an anonymised version of your profile (skills, experience and education, without your name, contact details or any personal characteristics) may be compared with the job to give the employer a match score. Match scores are decision support only; people make every hiring decision.', 'dev3-ai-job-board' ) . '</p>';
	}

	/**
	 * Applications of an email.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	private static function app_ids( $email ) {
		global $wpdb;
		$table = Tables::name( 'applications' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 500", $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$items = array();
		foreach ( self::app_ids( $email ) as $id ) {
			$app = Applications::get( $id );
			if ( ! $app ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'dev3jb-applications',
				'group_label' => __( 'Job applications', 'dev3-ai-job-board' ),
				'item_id'     => 'dev3jb-application-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Job', 'dev3-ai-job-board' ),
						'value' => $app['job_title'],
					),
					array(
						'name'  => __( 'Name', 'dev3-ai-job-board' ),
						'value' => $app['name'],
					),
					array(
						'name'  => __( 'Phone', 'dev3-ai-job-board' ),
						'value' => $app['phone'],
					),
					array(
						'name'  => __( 'Cover letter', 'dev3-ai-job-board' ),
						'value' => (string) $app['cover_letter'],
					),
					array(
						'name'  => __( 'Status', 'dev3-ai-job-board' ),
						'value' => $app['stage'],
					),
					array(
						'name'  => __( 'Applied', 'dev3-ai-job-board' ),
						'value' => $app['created_at'],
					),
				),
			);
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$p = Resumes::for_user( $user->ID );
			if ( $p['id'] ) {
				$items[] = array(
					'group_id'    => 'dev3jb-profile',
					'group_label' => __( 'Candidate profile', 'dev3-ai-job-board' ),
					'item_id'     => 'dev3jb-profile-' . $p['id'],
					'data'        => array(
						array(
							'name'  => __( 'Headline', 'dev3-ai-job-board' ),
							'value' => $p['headline'],
						),
						array(
							'name'  => __( 'Summary', 'dev3-ai-job-board' ),
							'value' => $p['summary'],
						),
						array(
							'name'  => __( 'Location', 'dev3-ai-job-board' ),
							'value' => $p['location'],
						),
						array(
							'name'  => __( 'Skills', 'dev3-ai-job-board' ),
							'value' => implode( ', ', $p['skills'] ),
						),
						array(
							'name'  => __( 'Experience', 'dev3-ai-job-board' ),
							'value' => wp_json_encode( $p['experience'] ),
						),
						array(
							'name'  => __( 'Education', 'dev3-ai-job-board' ),
							'value' => wp_json_encode( $p['education'] ),
						),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser: deletes applications, alerts, the profile and private files of the email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		global $wpdb;
		$removed = 0;
		foreach ( self::app_ids( $email ) as $id ) {
			Applications::delete( $id );
			++$removed;
		}
		$alerts   = Tables::name( 'alerts' );
		$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$alerts} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user     = get_user_by( 'email', $email );
		if ( $user ) {
			$rid = Resumes::id_for_user( $user->ID );
			if ( $rid ) {
				wp_delete_post( $rid, true );
				++$removed;
			}
			foreach ( Files::for_user( $user->ID ) as $file ) {
				Files::delete( (int) $file['id'] );
				++$removed;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
