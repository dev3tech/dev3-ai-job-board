<?php
/**
 * Email notifications: jobs (review, live, expiring, expired), applications, stage changes,
 * payments and job alerts. Every email passes through the `dev3jb_email` filter.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Emails;

use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static mailer.
 */
class Mailer {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'dev3jb_job_pending', array( __CLASS__, 'job_pending' ) );
		add_action( 'dev3jb_job_published', array( __CLASS__, 'job_published' ), 10, 2 );
		add_action( 'dev3jb_job_expiring', array( __CLASS__, 'job_expiring' ) );
		add_action( 'dev3jb_job_expired', array( __CLASS__, 'job_expired' ) );
		add_action( 'dev3jb_application_created', array( __CLASS__, 'application_created' ), 10, 2 );
		add_action( 'dev3jb_payment_completed', array( __CLASS__, 'payment_completed' ) );
		add_action( 'dev3jb_manual_payment_created', array( __CLASS__, 'manual_payment' ) );
		add_action( 'dev3jb_alert_confirm', array( __CLASS__, 'alert_confirm' ) );
	}

	/**
	 * Sends one email (HTML).
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $heading Heading.
	 * @param string $body    Body HTML (escaped by caller).
	 * @param array  $cta     [ label, url ].
	 * @param string $event   Event key (filters).
	 * @param array  $headers Extra headers.
	 * @return bool
	 */
	public static function send( $to, $subject, $heading, $body, array $cta = array(), $event = '', array $headers = array() ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		/**
		 * Filters an outgoing email. Return an empty value to cancel it.
		 *
		 * @param array  $mail  { to, subject, heading, body, cta }.
		 * @param string $event Event key.
		 */
		$mail = apply_filters(
			'dev3jb_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'heading' => $heading,
				'body'    => $body,
				'cta'     => $cta,
			),
			$event
		);
		if ( empty( $mail ) ) {
			return false;
		}
		$color = (string) Settings::get( 'accent_color', '#2563eb' );
		$html  = '<div style="background:#f4f4f7;padding:24px 12px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;overflow:hidden">'
			. '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;font-weight:600">' . esc_html( get_bloginfo( 'name' ) ) . '</div>'
			. '<div style="padding:24px"><h1 style="font-size:20px;margin:0 0 12px">' . esc_html( $mail['heading'] ) . '</h1>'
			. '<div style="font-size:15px;line-height:1.6">' . wp_kses_post( $mail['body'] ) . '</div>';
		if ( ! empty( $mail['cta'][1] ) ) {
			$html .= '<p style="margin:24px 0 0"><a href="' . esc_url( $mail['cta'][1] ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">' . esc_html( $mail['cta'][0] ) . '</a></p>';
		}
		$html .= '</div></div></div>';
		return (bool) wp_mail( $mail['to'], $mail['subject'], $html, array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $headers ) );
	}

	/**
	 * Employer email of a job.
	 *
	 * @param int $job_id Job.
	 * @return string
	 */
	private static function employer_email( $job_id ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $job_id ) );
		return $user ? $user->user_email : '';
	}

	/**
	 * Employer dashboard link.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function dashboard( $tab = 'jobs' ) {
		return add_query_arg( 'dev3jb_tab', $tab, Settings::page_url( 'employer' ) );
	}

	/**
	 * Paragraph helper.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function p( $text ) {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Admin: a job waits for review.
	 *
	 * @param int $job_id Job.
	 */
	public static function job_pending( $job_id ) {
		if ( ! Settings::get( 'email_admin_new_job', true ) ) {
			return;
		}
		$title = get_the_title( $job_id );
		/* translators: %s: job title */
		self::send( (string) get_option( 'admin_email' ), sprintf( __( 'New job to review: %s', 'dev3-ai-job-board' ), $title ), __( 'A new job is waiting for review', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: %s: job */ __( '"%s" was submitted and needs your approval.', 'dev3-ai-job-board' ), $title ) ), array( __( 'Review job', 'dev3-ai-job-board' ), admin_url( 'admin.php?page=' . DEV3JB_SLUG . '#/jobs/' . (int) $job_id ) ), 'job_pending' );
	}

	/**
	 * Employer: job is live.
	 *
	 * @param int    $job_id     Job.
	 * @param string $old_status Previous status.
	 */
	public static function job_published( $job_id, $old_status = '' ) {
		if ( ! Settings::get( 'email_employer_job_status', true ) || ! in_array( $old_status, array( 'pending', 'dev3jb_unpaid', 'dev3jb_expired' ), true ) ) {
			return;
		}
		$title = get_the_title( $job_id );
		/* translators: %s: job title */
		self::send( self::employer_email( $job_id ), sprintf( __( 'Your job is live: %s', 'dev3-ai-job-board' ), $title ), __( 'Your job is live', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: %s: job */ __( '"%s" is now published and candidates can apply.', 'dev3-ai-job-board' ), $title ) ), array( __( 'View job', 'dev3-ai-job-board' ), (string) get_permalink( $job_id ) ), 'job_published' );
	}

	/**
	 * Employer: job expires soon.
	 *
	 * @param int $job_id Job.
	 */
	public static function job_expiring( $job_id ) {
		if ( ! Settings::get( 'email_employer_job_status', true ) ) {
			return;
		}
		$title   = get_the_title( $job_id );
		$expires = (string) get_post_meta( $job_id, '_dev3jb_expires', true );
		/* translators: %s: job title */
		self::send( self::employer_email( $job_id ), sprintf( __( 'Your job expires soon: %s', 'dev3-ai-job-board' ), $title ), __( 'Your job expires soon', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: 1: job, 2: date */ __( '"%1$s" expires on %2$s. Relist it from your dashboard to keep receiving applications.', 'dev3-ai-job-board' ), $title, mysql2date( get_option( 'date_format' ), $expires ) ) ), array( __( 'Open dashboard', 'dev3-ai-job-board' ), self::dashboard() ), 'job_expiring' );
	}

	/**
	 * Employer: job expired.
	 *
	 * @param int $job_id Job.
	 */
	public static function job_expired( $job_id ) {
		if ( ! Settings::get( 'email_employer_job_status', true ) ) {
			return;
		}
		$title = get_the_title( $job_id );
		/* translators: %s: job title */
		self::send( self::employer_email( $job_id ), sprintf( __( 'Your job has expired: %s', 'dev3-ai-job-board' ), $title ), __( 'Your job has expired', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: %s: job */ __( '"%s" is no longer listed. You can relist it from your dashboard.', 'dev3-ai-job-board' ), $title ) ), array( __( 'Relist job', 'dev3-ai-job-board' ), self::dashboard() ), 'job_expired' );
	}

	/**
	 * New application: employer + candidate.
	 *
	 * @param array $app Application.
	 * @param array $job Job.
	 */
	public static function application_created( $app, $job ) {
		if ( Settings::get( 'email_employer_application', true ) ) {
			$to = self::employer_email( $job['id'] );
			if ( ! empty( $job['apply_email'] ) && is_email( $job['apply_email'] ) ) {
				$to = $job['apply_email'];
			}
			$body = self::p( sprintf( /* translators: 1: candidate, 2: job */ __( '%1$s applied for "%2$s".', 'dev3-ai-job-board' ), $app['name'], $job['title'] ) );
			if ( '' !== (string) $app['cover_letter'] ) {
				$body .= '<blockquote style="margin:12px 0;padding:8px 12px;border-left:3px solid #e5e7eb;color:#4b5563">' . nl2br( esc_html( mb_substr( $app['cover_letter'], 0, 600 ) ) ) . '</blockquote>';
			}
			/* translators: %s: job title */
			self::send( $to, sprintf( __( 'New application: %s', 'dev3-ai-job-board' ), $job['title'] ), __( 'You have a new application', 'dev3-ai-job-board' ), $body, array( __( 'Review application', 'dev3-ai-job-board' ), add_query_arg( 'job', (int) $job['id'], self::dashboard( 'applications' ) ) ), 'application_employer', array( 'Reply-To: ' . $app['name'] . ' <' . $app['email'] . '>' ) );
		}
		if ( Settings::get( 'email_candidate_received', true ) ) {
			$company = $job['company'] ? $job['company']['name'] : get_bloginfo( 'name' );
			/* translators: %s: job title */
			self::send( $app['email'], sprintf( __( 'Application received: %s', 'dev3-ai-job-board' ), $job['title'] ), __( 'Thanks for applying', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: 1: job, 2: company */ __( 'We sent your application for "%1$s" to %2$s. You will hear from them if they want to move forward.', 'dev3-ai-job-board' ), $job['title'], $company ) ), $app['candidate_id'] ? array( __( 'Track your applications', 'dev3-ai-job-board' ), add_query_arg( 'dev3jb_tab', 'applications', Settings::page_url( 'candidate' ) ) ) : array(), 'application_candidate' );
		}
	}

	/**
	 * Candidate: stage changed (only when the employer ticks "Email the candidate").
	 *
	 * @param array  $app     Application.
	 * @param string $message Optional personal message.
	 * @return bool
	 */
	public static function stage_changed( array $app, $message = '' ) {
		if ( ! Settings::get( 'email_candidate_stage', true ) ) {
			return false;
		}
		$labels = Applications::candidate_labels();
		$label  = isset( $labels[ $app['stage'] ] ) ? $labels[ $app['stage'] ] : $app['stage'];
		$body   = self::p( sprintf( /* translators: 1: job, 2: status */ __( 'Your application for "%1$s" is now: %2$s.', 'dev3-ai-job-board' ), $app['job_title'], $label ) );
		if ( '' !== trim( $message ) ) {
			$body .= '<p>' . nl2br( esc_html( mb_substr( $message, 0, 2000 ) ) ) . '</p>';
		}
		/* translators: %s: job title */
		$ok = self::send( $app['email'], sprintf( __( 'Update on your application: %s', 'dev3-ai-job-board' ), $app['job_title'] ), __( 'Application update', 'dev3-ai-job-board' ), $body, $app['candidate_id'] ? array( __( 'View your applications', 'dev3-ai-job-board' ), add_query_arg( 'dev3jb_tab', 'applications', Settings::page_url( 'candidate' ) ) ) : array(), 'application_stage' );
		if ( $ok ) {
			Applications::add_event( $app['id'], get_current_user_id(), 'email', sprintf( /* translators: %s: status */ __( 'Emailed the candidate: %s', 'dev3-ai-job-board' ), $label ) );
		}
		return $ok;
	}

	/**
	 * Payment completed: employer receipt + admin note.
	 *
	 * @param array $payment Payment.
	 */
	public static function payment_completed( $payment ) {
		$amount = html_entity_decode( Listings::money( $payment['amount'], $payment['currency'] ), ENT_QUOTES, 'UTF-8' );
		$user   = get_userdata( $payment['user_id'] );
		if ( $user ) {
			/* translators: %s: package */
			self::send( $user->user_email, sprintf( __( 'Payment received: %s', 'dev3-ai-job-board' ), $payment['package_name'] ), __( 'Thanks for your payment', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: 1: amount, 2: package */ __( 'We received %1$s for the %2$s package. Your job posts are ready to use.', 'dev3-ai-job-board' ), $amount, $payment['package_name'] ) ), array( __( 'Open dashboard', 'dev3-ai-job-board' ), self::dashboard( 'packages' ) ), 'payment_employer' );
		}
		if ( Settings::get( 'email_admin_payment', true ) ) {
			/* translators: 1: amount, 2: package */
			self::send( (string) get_option( 'admin_email' ), sprintf( __( 'New payment: %1$s for %2$s', 'dev3-ai-job-board' ), $amount, $payment['package_name'] ), __( 'New package payment', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: 1: user, 2: amount, 3: gateway */ __( '%1$s paid %2$s via %3$s.', 'dev3-ai-job-board' ), $payment['user_name'], $amount, $payment['gateway'] ) ), array( __( 'View payments', 'dev3-ai-job-board' ), admin_url( 'admin.php?page=' . DEV3JB_SLUG . '#/packages' ) ), 'payment_admin' );
		}
	}

	/**
	 * Offline payment instructions.
	 *
	 * @param array $payment Payment.
	 */
	public static function manual_payment( $payment ) {
		$user = get_userdata( $payment['user_id'] );
		if ( ! $user ) {
			return;
		}
		$amount       = html_entity_decode( Listings::money( $payment['amount'], $payment['currency'] ), ENT_QUOTES, 'UTF-8' );
		$instructions = (string) Settings::get( 'manual_instructions', '' );
		$body         = self::p( sprintf( /* translators: 1: package, 2: amount, 3: reference */ __( 'You chose the %1$s package (%2$s). Use reference #%3$s with your payment. Your job goes live once we confirm it.', 'dev3-ai-job-board' ), $payment['package_name'], $amount, $payment['id'] ) );
		if ( '' !== $instructions ) {
			$body .= '<p>' . nl2br( esc_html( $instructions ) ) . '</p>';
		}
		self::send( $user->user_email, __( 'How to pay for your job package', 'dev3-ai-job-board' ), __( 'Payment instructions', 'dev3-ai-job-board' ), $body, array(), 'payment_manual' );
		if ( Settings::get( 'email_admin_payment', true ) ) {
			self::send( (string) get_option( 'admin_email' ), __( 'Offline payment to confirm', 'dev3-ai-job-board' ), __( 'Offline payment to confirm', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: 1: user, 2: amount, 3: reference */ __( '%1$s will pay %2$s offline (reference #%3$s). Mark it paid in Packages & payments when it arrives.', 'dev3-ai-job-board' ), $payment['user_name'], $amount, $payment['id'] ) ), array( __( 'Open payments', 'dev3-ai-job-board' ), admin_url( 'admin.php?page=' . DEV3JB_SLUG . '#/packages' ) ), 'payment_manual_admin' );
		}
	}

	/**
	 * Guest alert: confirm subscription.
	 *
	 * @param array $alert Alert.
	 */
	public static function alert_confirm( $alert ) {
		self::send( $alert['email'], __( 'Confirm your job alert', 'dev3-ai-job-board' ), __( 'Confirm your job alert', 'dev3-ai-job-board' ), self::p( sprintf( /* translators: %s: search */ __( 'Confirm that you want emails about new jobs for: %s. If you did not ask for this, ignore this email.', 'dev3-ai-job-board' ), $alert['label'] ) ), array( __( 'Confirm alert', 'dev3-ai-job-board' ), Alerts::link( $alert, 'confirm' ) ), 'alert_confirm' );
	}

	/**
	 * Alert digest.
	 *
	 * @param array $alert Alert.
	 * @param array $jobs  Jobs.
	 * @return bool
	 */
	public static function alert_digest( array $alert, array $jobs ) {
		$items = '';
		foreach ( $jobs as $job ) {
			$meta   = array_filter(
				array(
					$job['company'] ? $job['company']['name'] : '',
					implode( ', ', wp_list_pluck( $job['locations'], 'name' ) ),
					$job['salary_text'],
				)
			);
			$items .= '<li style="margin:0 0 12px"><a href="' . esc_url( $job['url'] ) . '" style="font-weight:600;color:#1f2937">' . esc_html( $job['title'] ) . '</a><br><span style="color:#6b7280;font-size:14px">' . esc_html( implode( ' · ', $meta ) ) . '</span></li>';
		}
		$body = self::p( sprintf( /* translators: %s: search */ __( 'New jobs for your alert "%s":', 'dev3-ai-job-board' ), $alert['label'] ) ) . '<ul style="padding-left:18px">' . $items . '</ul>'
			. '<p style="font-size:13px;color:#6b7280"><a href="' . esc_url( Alerts::link( $alert, 'unsubscribe' ) ) . '" style="color:#6b7280">' . esc_html__( 'Unsubscribe from this alert', 'dev3-ai-job-board' ) . '</a></p>';
		/* translators: %d: number of jobs */
		return self::send( $alert['email'], sprintf( _n( '%d new job matches your alert', '%d new jobs match your alert', count( $jobs ), 'dev3-ai-job-board' ), count( $jobs ) ), __( 'New jobs for you', 'dev3-ai-job-board' ), $body, array( __( 'See all jobs', 'dev3-ai-job-board' ), add_query_arg( array_map( static fn( $v ) => is_array( $v ) ? implode( ',', $v ) : $v, (array) $alert['query'] ), Settings::page_url( 'jobs' ) ) ), 'alert_digest' );
	}
}
