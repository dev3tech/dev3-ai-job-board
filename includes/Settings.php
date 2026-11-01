<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * Payment secrets are NOT stored here (settings are sent to the admin app); see Billing\Checkout.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard;

use Dev3\AiJobBoard\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3jb_settings_defaults / dev3jb_settings_sanitize.
 */
class Settings {

	/**
	 * Boolean keys.
	 *
	 * @var string[]
	 */
	const FLAGS = array(
		'allow_registration',
		'edit_moderation',
		'guest_apply',
		'resume_required',
		'alerts_enabled',
		'schema_enabled',
		'email_admin_new_job',
		'email_admin_payment',
		'email_employer_application',
		'email_employer_job_status',
		'email_candidate_received',
		'email_candidate_stage',
		'ai_writer',
		'ai_inclusive',
		'ai_parse',
		'ai_match',
		'ai_match_on_apply',
		'ai_recommend',
		'match_for_employers',
		'hide_names_in_pipeline',
	);

	/**
	 * Page keys.
	 *
	 * @var string[]
	 */
	const PAGES = array( 'jobs', 'submit', 'employer', 'candidate', 'companies' );

	/**
	 * Adds plugin defaults. No __() here: defaults can be read before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'page_jobs'                  => 0,
				'page_submit'                => 0,
				'page_employer'              => 0,
				'page_candidate'             => 0,
				'page_companies'             => 0,
				'per_page'                   => 10,
				'currency'                   => 'USD',
				'accent_color'               => '#2563eb',
				'job_duration'               => 30,
				'expiry_notice_days'         => 5,
				'submission_moderation'      => 'pending',
				'edit_moderation'            => false,
				'allow_registration'         => true,
				'guest_apply'                => true,
				'resume_required'            => false,
				'max_upload_mb'              => 5,
				'alerts_enabled'             => true,
				'schema_enabled'             => true,
				'email_admin_new_job'        => true,
				'email_admin_payment'        => true,
				'email_employer_application' => true,
				'email_employer_job_status'  => true,
				'email_candidate_received'   => true,
				'email_candidate_stage'      => true,
				'payment_gateway'            => 'manual',
				'manual_instructions'        => '',
				'ai_writer'                  => true,
				'ai_inclusive'               => true,
				'ai_parse'                   => true,
				'ai_match'                   => true,
				'ai_match_on_apply'          => true,
				'ai_recommend'               => true,
				'match_for_employers'        => true,
				'hide_names_in_pipeline'     => false,
				'writer_tone'                => 'professional',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		$enums = array(
			'submission_moderation' => array( 'pending', 'publish' ),
			'payment_gateway'       => array( 'manual', 'stripe', 'woocommerce' ),
			'writer_tone'           => array( 'professional', 'friendly', 'startup', 'formal' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		$ints = array(
			'per_page'           => array( 1, 50 ),
			'job_duration'       => array( 1, 365 ),
			'expiry_notice_days' => array( 0, 60 ),
			'max_upload_mb'      => array( 1, 20 ),
		);
		foreach ( self::PAGES as $page ) {
			$ints[ 'page_' . $page ] = array( 0, PHP_INT_MAX );
		}
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['currency'] ) ) {
			$cur = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $input['currency'] ) );
			if ( 3 === strlen( $cur ) ) {
				$out['currency'] = $cur;
			}
		}
		if ( isset( $input['manual_instructions'] ) ) {
			$out['manual_instructions'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['manual_instructions'] ) ), 0, 1000 );
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		return $out;
	}

	/**
	 * Shortcut.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Page URL for a configured page (jobs|submit|employer|candidate|companies).
	 *
	 * @param string $which Page.
	 * @return string
	 */
	public static function page_url( $which ) {
		$id = (int) self::get( 'page_' . $which, 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return home_url( '/' );
	}

	/**
	 * Upload limit in bytes.
	 *
	 * @return int
	 */
	public static function max_upload() {
		return (int) self::get( 'max_upload_mb', 5 ) * MB_IN_BYTES;
	}
}
