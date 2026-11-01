<?php
/**
 * Data layer: jobs, search, expiry, companies, profiles, packages, payments, alerts, applications.
 *
 * @package Dev3\AiJobBoard
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiJobBoard\Billing\Checkout;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\Saved;
use Dev3\AiJobBoard\Data\UserPackages;

class ModelTest extends Dev3jb_TestCase {

	public function test_job_save_sanitizes_and_reads_back() {
		$emp = $this->employer();
		$cid = $this->company( $emp );
		$cat = $this->term( 'Engineering', Post_Types::CATEGORY );
		$id  = $this->job(
			$emp,
			array(
				'title'           => '<b>Senior</b> Engineer',
				'company_id'      => $cid,
				'categories'      => array( $cat ),
				'salary_min'      => '120,000',
				'salary_max'      => 90000,
				'salary_currency' => 'eur',
				'workplace'       => 'moon',
				'apply_url'       => 'javascript:alert(1)',
				'deadline'        => 'tomorrow',
			)
		);
		$job = Listings::get( $id );
		$this->assertSame( 'Senior Engineer', $job['title'] );
		$this->assertSame( $cid, $job['company_id'] );
		$this->assertSame( 'Brightwave', $job['company']['name'] );
		$this->assertSame( 90000.0, $job['salary_min'], 'Min and max are swapped when reversed' );
		$this->assertSame( 120000.0, $job['salary_max'] );
		$this->assertSame( 'EUR', $job['salary_currency'] );
		$this->assertSame( 'onsite', $job['workplace'] );
		$this->assertSame( '', $job['apply_url'] );
		$this->assertSame( '', $job['deadline'] );
		$this->assertEqualsCanonicalizing( array( 'React', 'TypeScript', 'CSS' ), wp_list_pluck( $job['skills'], 'name' ) );
		$this->assertSame( '€90,000 – €120,000 per year', $job['salary_text'] );
		$this->assertNotEmpty( $job['expires'], 'Publishing sets a default expiry' );
	}

	public function test_validation_requires_title_and_apply_target() {
		$this->assertWPError( Listings::save( Listings::sanitize( array( 'content' => 'x' ) ), 0, 1 ) );
		$err = Listings::save( Listings::sanitize( array( 'title' => 'X', 'apply_method' => 'url' ) ), 0, 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'dev3jb_apply_url', $err->get_error_code() );
	}

	public function test_search_filters_and_featured_first() {
		$emp = $this->employer();
		$cat = $this->term( 'Design', Post_Types::CATEGORY );
		$a   = $this->job( $emp, array( 'title' => 'Designer', 'categories' => array( $cat ), 'workplace' => 'onsite', 'salary_min' => 40000, 'salary_max' => 50000 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$b   = $this->job( $emp, array( 'title' => 'Engineer', 'workplace' => 'remote', 'salary_min' => 100000, 'salary_max' => 140000 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$c   = $this->job( $emp, array( 'title' => 'Writer', 'workplace' => 'hybrid', 'featured' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( array( $a ), wp_list_pluck( Listings::search( array( 'category' => 'design' ) )['items'], 'id' ) );
		$this->assertSame( array( $b ), wp_list_pluck( Listings::search( array( 'workplace' => 'remote' ) )['items'], 'id' ) );
		$this->assertSame( array( $b ), wp_list_pluck( Listings::search( array( 'salary_min' => 95000 ) )['items'], 'id' ) );
		$this->assertSame( array( $a ), wp_list_pluck( Listings::search( array( 'q' => 'Designer' ) )['items'], 'id' ) );
		$first = Listings::search( array( 'featured_first' => true ) )['items'];
		$this->assertSame( $c, $first[0]['id'], 'Featured jobs come first' );
		$this->assertCount( 3, $first );
		$by_salary = wp_list_pluck( Listings::search( array( 'orderby' => 'salary' ) )['items'], 'id' );
		$this->assertSame( $b, $by_salary[0] );
	}

	public function test_expiry_and_expiring_notice() {
		$emp = $this->employer();
		$old = $this->job( $emp );
		update_post_meta( $old, '_dev3jb_expires', gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) );
		$soon = $this->job( $emp );
		update_post_meta( $soon, '_dev3jb_expires', gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) );
		$res = Listings::check_expiry();
		$this->assertSame( 1, $res['expired'] );
		$this->assertSame( 1, $res['notified'] );
		$this->assertSame( Post_Types::EXPIRED, get_post_status( $old ) );
		$this->assertSame( 0, Listings::check_expiry()['notified'], 'The notice goes out once' );
	}

	public function test_resume_sanitize_drops_unknown_and_protected_keys() {
		$clean = Resumes::sanitize(
			array(
				'headline'      => 'Dev',
				'gender'        => 'female',
				'date_of_birth' => '1990-01-02',
				'nationality'   => 'X',
				'skills'        => 'React, react , PHP',
				'experience'    => array(
					array(
						'title'   => 'Lead',
						'company' => 'A',
						'start'   => '2020-1',
						'current' => '1',
					),
					array( 'title' => '' ),
				),
				'workplace'     => array( 'remote', 'space' ),
			)
		);
		$this->assertArrayNotHasKey( 'gender', $clean );
		$this->assertArrayNotHasKey( 'date_of_birth', $clean );
		$this->assertArrayNotHasKey( 'nationality', $clean );
		$this->assertSame( array( 'React', 'PHP' ), $clean['skills'] );
		$this->assertCount( 1, $clean['experience'] );
		$this->assertSame( '2020-01', $clean['experience'][0]['start'] );
		$this->assertSame( array( 'remote' ), $clean['workplace'] );
	}

	public function test_resume_save_years_and_completeness() {
		$user = $this->candidate();
		$id   = Resumes::save(
			$user,
			Resumes::sanitize(
				array(
					'headline'   => 'Frontend Lead',
					'summary'    => 'Builds apps.',
					'location'   => 'Berlin',
					'skills'     => 'React, CSS, PHP',
					'experience' => array(
						array(
							'title' => 'A',
							'start' => '2018-01',
							'end'   => '2019-12',
						),
						array(
							'title' => 'B',
							'start' => '2019-06',
							'end'   => '2020-12',
						),
					),
				)
			)
		);
		$p = Resumes::get( $id );
		$this->assertSame( $id, Resumes::id_for_user( $user ) );
		$this->assertSame( 3.0, $p['years'], 'Overlapping months count once' );
		$this->assertSame( 71, $p['complete'] );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertSame( $id, Resumes::save( $user, array( 'summary' => 'x' ) ), 'One profile per candidate' );
	}

	public function test_packages_defaults_normalize_and_unique_ids() {
		$this->assertCount( 3, Packages::all() );
		$saved = Packages::save(
			array(
				array(
					'name'      => 'Pro',
					'price'     => -5,
					'job_limit' => 0,
				),
				array( 'name' => 'Pro' ),
			)
		);
		$this->assertSame( 0.0, $saved[0]['price'] );
		$this->assertSame( 1, $saved[0]['job_limit'] );
		$this->assertNotSame( $saved[0]['id'], $saved[1]['id'] );
	}

	public function test_credits_are_consumed_once_each() {
		$emp = $this->employer();
		$up  = UserPackages::grant( $emp, Packages::get( 'team' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertIsArray( UserPackages::consume( $up['id'] ) );
		}
		$this->assertWPError( UserPackages::consume( $up['id'] ) );
		$this->assertSame( 'used', UserPackages::get( $up['id'] )['status'] );
		$this->assertSame( array(), UserPackages::available( $emp ) );
	}

	public function test_free_package_publishes_or_queues_for_review() {
		$emp = $this->employer();
		$job = $this->job( $emp, array(), 'draft' );
		$res = Checkout::start( $emp, 'free', $job );
		$this->assertSame( 'pending', $res['status'] );
		$this->assertSame( 'pending', get_post_status( $job ) );
		$this->settings( array( 'submission_moderation' => 'publish' ) );
		$job2 = $this->job( $emp, array(), 'draft' );
		$this->assertSame( 'publish', Checkout::start( $emp, 'free', $job2 )['status'] );
	}

	public function test_manual_payment_marks_job_unpaid_until_paid() {
		$emp = $this->employer();
		$job = $this->job( $emp, array(), 'draft' );
		$res = Checkout::start( $emp, 'featured', $job );
		$this->assertSame( Post_Types::UNPAID, get_post_status( $job ) );
		$this->assertSame( 'manual', $res['payment']['gateway'] );
		$this->assertSame( 79.0, $res['payment']['amount'] );
		$this->assertSame( array(), UserPackages::available( $emp ), 'Pending packages have no credits yet' );
		$paid = Payments::complete( $res['payment']['id'] );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 'pending', get_post_status( $job ) );
		$this->assertSame( '1', get_post_meta( $job, '_dev3jb_featured', true ) );
		$this->assertSame( 1, UserPackages::get( $paid['user_package_id'] )['jobs_used'] );
		Payments::complete( $res['payment']['id'] );
		$this->assertSame( 1, UserPackages::get( $paid['user_package_id'] )['jobs_used'], 'Completing twice is a no-op' );
	}

	public function test_relist_uses_a_credit_and_resets_expiry() {
		$emp = $this->employer();
		$job = $this->job( $emp );
		wp_update_post(
			array(
				'ID'          => $job,
				'post_status' => Post_Types::EXPIRED,
			)
		);
		update_post_meta( $job, '_dev3jb_filled', 1 );
		$this->settings( array( 'submission_moderation' => 'publish' ) );
		$up = UserPackages::grant( $emp, Packages::get( 'team' ) );
		$this->assertSame( 'publish', Listings::relist( $job, $up ) );
		$this->assertSame( gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ), get_post_meta( $job, '_dev3jb_expires', true ) );
		$this->assertSame( '0', get_post_meta( $job, '_dev3jb_filled', true ) );
	}

	public function test_applications_rules_duplicates_and_closed_jobs() {
		$emp = $this->employer();
		$job = $this->job( $emp );
		$app = Applications::create(
			$job,
			array(
				'name'  => 'Sam',
				'email' => 'sam@example.com',
			)
		);
		$this->assertSame( 'new', $app['stage'] );
		$this->assertSame( $emp, $app['employer_id'] );
		$dup = Applications::create(
			$job,
			array(
				'name'  => 'Sam',
				'email' => 'sam@example.com',
			)
		);
		$this->assertSame( 'dev3jb_duplicate', $dup->get_error_code() );
		$this->assertSame( 'dev3jb_email', Applications::create( $job, array( 'name' => 'X' ) )->get_error_code() );
		update_post_meta( $job, '_dev3jb_filled', 1 );
		$this->assertSame( 'dev3jb_filled', Applications::create( $job, array( 'name' => 'B', 'email' => 'b@example.com' ) )->get_error_code() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$ext = $this->job( $emp, array( 'apply_method' => 'url', 'apply_url' => 'https://x.example/apply' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'dev3jb_external', Applications::create( $ext, array( 'name' => 'B', 'email' => 'b@example.com' ) )->get_error_code() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$late = $this->job( $emp, array( 'deadline' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 'dev3jb_deadline', Applications::create( $late, array( 'name' => 'B', 'email' => 'b@example.com' ) )->get_error_code() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
	}

	public function test_pipeline_moves_reorder_and_log_events() {
		$emp = $this->employer();
		$job = $this->job( $emp );
		$ids = array();
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			$ids[] = Applications::create( $job, array( 'name' => $n, 'email' => "$n@example.com" ) )['id']; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		}
		Applications::move( $ids[2], 'interview', 0, $emp );
		Applications::move( $ids[0], 'interview', 0, $emp );
		$order = wp_list_pluck(
			Applications::query(
				array(
					'job_id'  => $job,
					'stage'   => 'interview',
					'orderby' => 'position',
				)
			)['items'],
			'id'
		);
		$this->assertSame( array( $ids[0], $ids[2] ), $order );
		$this->assertSame( 'stage', Applications::get( $ids[0] )['events'][0]['kind'] );
		$this->assertWPError( Applications::move( $ids[1], 'teleported' ) );
		$counts = Applications::stage_counts( array( 'job_id' => $job ) );
		$this->assertSame( 2, $counts['interview'] );
		$this->assertSame( 1, $counts['new'] );
		$this->assertSame( 4, Applications::rate( $ids[1], 4 )['rating'] );
		$this->assertSame( 5, Applications::rate( $ids[1], 9 )['rating'] );
	}

	public function test_withdraw_only_by_the_candidate() {
		$emp  = $this->employer();
		$cand = $this->candidate();
		$job  = $this->job( $emp );
		$app  = Applications::create( $job, array( 'name' => 'A', 'email' => 'a@example.com', 'candidate_id' => $cand ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertWPError( Applications::withdraw( $app['id'], $emp ) );
		$this->assertSame( 'withdrawn', Applications::withdraw( $app['id'], $cand )['stage'] );
		$this->assertWPError( Applications::move( $app['id'], 'offer' ) );
		$this->assertSame( 0, Applications::count_for_job( $job ) );
	}

	public function test_alerts_double_opt_in_matching_and_digest() {
		$guest = Alerts::create( array( 'q' => 'React', 'bogus' => 'x' ), 'guest@example.com' );
		$this->assertFalse( $guest['confirmed'] );
		$this->assertArrayNotHasKey( 'bogus', $guest['query'] );
		$this->assertTrue( Alerts::confirm( $guest['id'], $guest['token'] ) );
		$this->assertFalse( Alerts::confirm( $guest['id'], 'wrong' ) );
		global $wpdb;
		$wpdb->update( \Dev3\AiJobBoard\Data\Tables::name( 'alerts' ), array( 'last_sent' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'id' => $guest['id'] ) ); // phpcs:ignore
		$emp = $this->employer();
		$this->job( $emp, array( 'title' => 'React Developer' ) );
		$this->job( $emp, array( 'title' => 'Accountant', 'content' => 'Numbers.', 'skills' => 'Excel' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		reset_phpmailer_instance();
		$this->assertSame( 1, Alerts::send_digests() );
		$body = quoted_printable_decode( tests_retrieve_phpmailer_instance()->get_sent()->body );
		$this->assertStringContainsString( 'React Developer', $body );
		$this->assertStringNotContainsString( 'Accountant', $body );
		$this->assertStringContainsString( 'dev3jb_alert=unsubscribe', $body );
		$this->assertSame( 0, Alerts::send_digests(), 'Daily alerts wait a day' );
		$this->assertTrue( Alerts::delete( $guest['id'], 0, $guest['token'] ) );
	}

	public function test_saved_jobs_toggle() {
		$u = $this->candidate();
		$this->assertTrue( Saved::set( $u, 10 ) );
		$this->assertSame( array( 10 ), Saved::ids( $u ) );
		$this->assertFalse( Saved::set( $u, 10 ) );
		$this->assertSame( array(), Saved::ids( $u ) );
	}
}
