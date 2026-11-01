<?php
/**
 * REST permissions and payloads, private files, billing, schema markup, templates and privacy.
 *
 * @package Dev3\AiJobBoard
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiJobBoard\Billing\Checkout;
use Dev3\AiJobBoard\Billing\Stripe;
use Dev3\AiJobBoard\Billing\Woo;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\UserPackages;
use Dev3\AiJobBoard\Front\Schema_Markup;
use Dev3\AiJobBoard\Front\Templates;
use Dev3\AiJobBoard\Privacy_Data;

class RestTest extends Dev3jb_TestCase {

	/**
	 * Server.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		foreach ( array( 'jobs', 'apply', 'alert' ) as $b ) {
			delete_transient( 'dev3jb_rl_' . $b );
		}
		add_filter( 'dev3jb_public_rate_limit', static fn() => 1000 );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_FILES         = array();
		parent::tear_down();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send nonce.
	 * @param array  $files  File params.
	 * @return WP_REST_Response
	 */
	private function req( $method, $route, $params = array(), $nonce = true, $files = array() ) {
		$r = new WP_REST_Request( $method, '/dev3jb/v1/' . $route );
		if ( 'GET' === $method ) {
			$r->set_query_params( $params );
		} elseif ( $files ) {
			$r->set_body_params( $params );
			$r->set_file_params( $files );
		} else {
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( wp_json_encode( $params ) );
		}
		if ( $nonce ) {
			$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return $this->server->dispatch( $r );
	}

	/**
	 * Fake uploaded PDF.
	 *
	 * @return array
	 */
	private function upload() {
		return array(
			'name'     => 'resume.pdf',
			'type'     => 'application/pdf',
			'tmp_name' => $this->temp_file( dev3jb_test_pdf( $this->resume_lines() ) ),
			'error'    => 0,
			'size'     => 1000,
		);
	}

	public function test_admin_routes_require_capability_and_nonce() {
		wp_set_current_user( $this->candidate() );
		$this->assertSame( 403, $this->req( 'GET', 'admin/jobs' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 403, $this->req( 'GET', 'admin/jobs', array(), false )->get_status(), 'Nonce is required' );
		$this->assertSame( 200, $this->req( 'GET', 'admin/jobs' )->get_status() );
		$this->assertSame( 200, $this->req( 'GET', 'admin/meta' )->get_status() );
		$this->assertSame( 200, $this->req( 'GET', 'admin/stats' )->get_status() );
	}

	public function test_admin_job_crud_bulk_and_terms() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = $this->req( 'POST', 'admin/jobs', array( 'title' => 'QA Engineer', 'content' => '<p>Test</p>', 'skills' => 'Cypress', 'status' => 'pending' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( 'pending', get_post_status( $id ) );
		$this->assertSame( 1, $this->req( 'POST', 'admin/jobs/bulk', array( 'ids' => array( $id ), 'action' => 'approve' ) )->get_data()['updated'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->req( 'POST', 'admin/jobs/bulk', array( 'ids' => array( $id ), 'action' => 'feature' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertTrue( Listings::get( $id )['featured'] );
		$types = $this->req( 'POST', 'admin/terms/types', array( 'name' => 'Seasonal', 'schema' => 'temporary' ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$seasonal = wp_list_filter( $types, array( 'name' => 'Seasonal' ) );
		$this->assertSame( 'TEMPORARY', reset( $seasonal )['schema'] );
		$this->assertSame( 404, $this->req( 'GET', 'admin/terms/nope' )->get_status() );
	}

	public function test_public_search_returns_html_and_hides_internal_fields() {
		$emp = $this->employer();
		$this->job( $emp, array( 'title' => 'Remote React Dev' ) );
		$this->job( $emp, array( 'title' => 'Hidden draft' ), 'draft' );
		wp_set_current_user( 0 );
		$res = $this->req( 'GET', 'listings', array( 'q' => 'React', 'html' => 1 ), false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertArrayNotHasKey( 'author', $data['items'][0] );
		$this->assertArrayNotHasKey( 'apply_email', $data['items'][0] );
		$this->assertStringContainsString( 'Remote React Dev', $data['html'] );
		$this->assertStringNotContainsString( 'Hidden draft', $data['html'] );
	}

	public function test_cross_origin_public_requests_are_refused() {
		$r = new WP_REST_Request( 'GET', '/dev3jb/v1/listings' );
		$r->set_header( 'origin', 'https://evil.example' );
		$this->assertSame( 403, $this->server->dispatch( $r )->get_status() );
	}

	public function test_guest_apply_with_private_resume_and_emails() {
		$emp = $this->employer();
		$job = $this->job( $emp );
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
		$res = $this->req( 'POST', "listings/$job/apply", array( 'name' => 'Guest Person', 'email' => 'guest@example.com', 'consent' => '1' ), false, array( 'resume' => $this->upload() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$app = Applications::query( array( 'job_id' => $job ) )['items'][0];
		$this->assertSame( 'Guest Person', $app['name'] );
		$this->assertNotNull( $app['match_score'], 'A rule-based score is added at once' );
		$file = Files::get( $app['file_id'] );
		$this->assertSame( 'attachment', $file['kind'] );
		$path = Files::path( $file );
		$this->assertStringContainsString( 'dev3jb-private', $path );
		$this->assertFileExists( Files::base_dir() . '/.htaccess' );
		$this->assertStringNotContainsString( 'resume', basename( $path ), 'Stored under a random name' );
		$this->assertGreaterThanOrEqual( 2, count( tests_retrieve_phpmailer_instance()->mock_sent ) );
		$this->assertSame( 400, $this->req( 'POST', "listings/$job/apply", array( 'name' => 'Bot', 'email' => 'b@example.com', 'consent' => '1', 'website' => 'spam' ), false )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 400, $this->req( 'POST', "listings/$job/apply", array( 'name' => 'No', 'email' => 'n@example.com' ), false )->get_status(), 'Consent required' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->settings( array( 'guest_apply' => false ) );
		$this->assertSame( 401, $this->req( 'POST', "listings/$job/apply", array( 'name' => 'G', 'email' => 'g2@example.com', 'consent' => '1' ), false )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
	}

	public function test_uploads_reject_wrong_types() {
		$cand = $this->candidate();
		wp_set_current_user( $cand );
		$bad = array(
			'name'     => 'resume.pdf',
			'tmp_name' => $this->temp_file( '<?php echo 1;', 'pdf' ),
			'error'    => 0,
			'size'     => 10,
		);
		$this->assertSame( 415, $this->req( 'POST', 'candidate/resume-file', array(), true, array( 'file' => $bad ) )->get_status() );
		$exe = array_merge( $bad, array( 'name' => 'cv.exe' ) );
		$this->assertSame( 415, $this->req( 'POST', 'candidate/resume-file', array(), true, array( 'file' => $exe ) )->get_status() );
		remove_filter( 'dev3jb_skip_upload_check', '__return_true' );
		$this->assertSame( 400, $this->req( 'POST', 'candidate/resume-file', array(), true, array( 'file' => $this->upload() ) )->get_status(), 'Only real uploads are accepted' );
	}

	public function test_file_access_is_limited_to_owner_employer_and_managers() {
		$emp   = $this->employer();
		$other = $this->employer();
		$cand  = $this->candidate();
		$cand2 = $this->candidate( 'Other' );
		$file  = Files::store( $this->temp_file( dev3jb_test_pdf( array( 'CV' ) ) ), 'cv.pdf', $cand );
		$this->assertTrue( Files::can_access( $file, $cand ) );
		$this->assertFalse( Files::can_access( $file, $emp ), 'No application yet' );
		$job = $this->job( $emp );
		Applications::create( $job, array( 'name' => 'C', 'email' => 'c@example.com', 'candidate_id' => $cand, 'file_id' => $file['id'] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertTrue( Files::can_access( $file, $emp ) );
		$this->assertFalse( Files::can_access( $file, $other ) );
		$this->assertFalse( Files::can_access( $file, $cand2 ) );
		$this->assertFalse( Files::can_access( $file, 0 ) );
		$this->assertTrue( Files::can_access( $file, self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		$this->assertStringContainsString( '_wpnonce=', Files::download_url( $file['id'] ) );
	}

	public function test_employer_routes_check_ownership() {
		$emp   = $this->employer();
		$other = $this->employer();
		$job   = $this->job( $emp );
		$app   = Applications::create( $job, array( 'name' => 'Ana', 'email' => 'ana@example.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		wp_set_current_user( $other );
		$this->assertSame( 404, $this->req( 'GET', 'employer/applications', array( 'job' => $job ) )->get_status() );
		$this->assertSame( 404, $this->req( 'GET', 'employer/applications/' . $app['id'] )->get_status() );
		$this->assertSame( 404, $this->req( 'POST', 'employer/applications/' . $app['id'], array( 'stage' => 'hired' ) )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		wp_set_current_user( $emp );
		$list = $this->req( 'GET', 'employer/applications', array( 'job' => $job ) )->get_data();
		$this->assertSame( 'Ana', $list['items'][0]['name'] );
		reset_phpmailer_instance();
		$moved = $this->req( 'POST', 'employer/applications/' . $app['id'], array( 'stage' => 'interview', 'notify' => true, 'message' => 'See you Tuesday' ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'interview', $moved['stage'] );
		$body = quoted_printable_decode( tests_retrieve_phpmailer_instance()->get_sent()->body );
		$this->assertStringContainsString( 'Interviewing', $body );
		$this->assertStringContainsString( 'See you Tuesday', $body );
		$notes = $this->req( 'POST', 'employer/applications/' . $app['id'] . '/notes', array( 'note' => 'Great portfolio' ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'Great portfolio', $notes[0]['body'] );
		$scored = $this->req( 'POST', 'employer/applications/' . $app['id'] . '/match', array( 'ai' => false ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'rules', $scored['match']['source'] );
		$this->assertSame( 'interview', $scored['stage'] );
		$this->assertSame( 401, ( function () {
			wp_set_current_user( 0 );
			return $this->req( 'GET', 'employer/jobs' )->get_status();
		} )() );
	}

	public function test_blind_screening_hides_names_until_interview() {
		$this->settings( array( 'hide_names_in_pipeline' => true ) );
		$emp = $this->employer();
		$job = $this->job( $emp );
		$app = Applications::create( $job, array( 'name' => 'Ana Lima', 'email' => 'ana@example.com', 'phone' => '123456789' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		wp_set_current_user( $emp );
		$d = $this->req( 'GET', 'employer/applications/' . $app['id'] )->get_data();
		$this->assertStringNotContainsString( 'Ana', wp_json_encode( $d['card'] ) );
		$this->assertSame( '', $d['email'] );
		Applications::move( $app['id'], 'interview' );
		$this->assertSame( 'Ana Lima', $this->req( 'GET', 'employer/applications/' . $app['id'] )->get_data()['name'] );
	}

	public function test_employer_writer_works_without_ai() {
		wp_set_current_user( $this->employer() );
		$d = $this->req( 'POST', 'employer/ai/describe', array( 'title' => 'Barista', 'notes' => "- Make great coffee\n- 1 year experience" ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'template', $d['source'] );
		$r = $this->req( 'POST', 'employer/ai/inclusive', array( 'content' => 'We need a coffee ninja.' ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'ninja', $r['issues'][0]['phrase'] );
	}

	public function test_candidate_parse_only_own_files_and_applications_view() {
		$cand  = $this->candidate();
		$cand2 = $this->candidate( 'Other' );
		$file  = Files::store( $this->temp_file( dev3jb_test_pdf( $this->resume_lines() ) ), 'cv.pdf', $cand );
		wp_set_current_user( $cand2 );
		$this->assertSame( 404, $this->req( 'POST', 'candidate/parse', array( 'file_id' => $file['id'] ) )->get_status() );
		wp_set_current_user( $cand );
		$res = $this->req( 'POST', 'candidate/parse', array( 'file_id' => $file['id'] ) )->get_data();
		$this->assertSame( 'Senior Frontend Developer', $res['profile']['headline'] );
		$this->assertSame( 0, Resumes::id_for_user( $cand ), 'Parsing never saves the profile' );
		$emp = $this->employer();
		$job = $this->job( $emp );
		$app = Applications::create( $job, array( 'name' => 'C', 'email' => 'c@example.com', 'candidate_id' => $cand ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		Applications::rate( $app['id'], 5 );
		Applications::move( $app['id'], 'rejected' );
		$mine = $this->req( 'GET', 'candidate/applications' )->get_data();
		$this->assertSame( 'Not selected', $mine[0]['status'] );
		$this->assertArrayNotHasKey( 'rating', $mine[0] );
		$this->assertArrayNotHasKey( 'match_score', $mine[0] );
	}

	public function test_stripe_signature_and_session_completion() {
		$secret  = 'whsec_test';
		$payload = '{"x":1}';
		$t       = time();
		$sig     = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		$this->assertTrue( Stripe::verify_signature( $payload, $sig, $secret ) );
		$this->assertFalse( Stripe::verify_signature( $payload, $sig, 'other' ) );
		$this->assertFalse( Stripe::verify_signature( $payload, 't=' . ( $t - 1000 ) . ',v1=' . hash_hmac( 'sha256', ( $t - 1000 ) . '.' . $payload, $secret ), $secret ) );
		Checkout::save_secrets( array( 'stripe_secret' => 'sk_test_123' ) );
		$this->settings( array( 'payment_gateway' => 'stripe' ) );
		Dev3jb_Http::on(
			'api.stripe.com/v1/checkout/sessions',
			array(
				200,
				array(
					'id'  => 'cs_test_abc',
					'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc',
				),
			)
		);
		$emp = $this->employer();
		$job = $this->job( $emp, array(), 'draft' );
		$res = Checkout::start( $emp, 'featured', $job );
		$this->assertStringStartsWith( 'https://checkout.stripe.com/', $res['redirect'] );
		$this->assertSame( 7900, (int) Dev3jb_Http::$requests[0]['args']['body']['line_items[0][price_data][unit_amount]'] );
		$payment = Payments::by_ref( 'cs_test_abc' );
		$this->assertWPError(
			Stripe::complete_from_session(
				array(
					'id'                  => 'cs_test_abc',
					'client_reference_id' => (string) $payment['id'],
					'payment_status'      => 'paid',
					'amount_total'        => 100,
				)
			),
			'Underpaid sessions are refused'
		);
		Stripe::complete_from_session(
			array(
				'id'                  => 'cs_test_abc',
				'client_reference_id' => (string) $payment['id'],
				'payment_status'      => 'paid',
				'amount_total'        => 7900,
			)
		);
		$this->assertSame( 'paid', Payments::get( $payment['id'] )['status'] );
		$this->assertSame( 'pending', get_post_status( $job ) );
		$cfg = Checkout::config();
		$this->assertTrue( $cfg['stripe_test_mode'] );
		$this->assertStringNotContainsString( 'sk_test_123', wp_json_encode( $cfg ) );
	}

	public function test_woocommerce_order_activates_the_package() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		$emp     = $this->employer();
		$job     = $this->job( $emp, array(), 'draft' );
		$pkg     = Packages::get( 'featured' );
		$pending = UserPackages::grant( $emp, $pkg, 'pending' );
		$payment = Payments::create( $emp, $pkg, $pending['id'], $job, 'woocommerce' );
		wp_update_post(
			array(
				'ID'          => $job,
				'post_status' => Post_Types::UNPAID,
			)
		);
		$product = Woo::product_for( $pkg );
		$this->assertIsInt( $product );
		$this->assertSame( 'hidden', wc_get_product( $product )->get_catalog_visibility() );
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $product ) );
		$item->add_meta_data( '_' . Woo::ITEM_KEY, $payment['id'], true );
		$order->add_item( $item );
		$order->calculate_totals();
		$order->save();
		$this->assertSame( 1, Woo::order_paid( $order->get_id() ) );
		$this->assertSame( 'paid', Payments::get( $payment['id'] )['status'] );
		$this->assertSame( 'pending', get_post_status( $job ) );
		$this->assertSame( 0, Woo::order_paid( $order->get_id() ), 'Idempotent' );
		reset_phpmailer_instance();
	}

	public function test_job_posting_schema_has_required_properties() {
		$emp  = $this->employer();
		$cid  = $this->company( $emp );
		$type = $this->term( 'Full-time', Post_Types::TYPE );
		update_term_meta( $type, 'dev3jb_schema', 'FULL_TIME' );
		$de  = $this->term( 'Germany', Post_Types::LOCATION );
		$ber = $this->term( 'Berlin', Post_Types::LOCATION, $de );
		$job = Listings::get( $this->job( $emp, array( 'company_id' => $cid, 'types' => array( $type ), 'locations' => array( $ber ), 'workplace' => 'onsite' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$s   = Schema_Markup::build( $job );
		foreach ( array( 'title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation', 'validThrough', 'employmentType', 'baseSalary' ) as $key ) {
			$this->assertArrayHasKey( $key, $s );
		}
		$this->assertSame( 'Brightwave', $s['hiringOrganization']['name'] );
		$this->assertSame( 'Berlin', $s['jobLocation']['address']['addressLocality'] );
		$this->assertSame( 'Germany', $s['jobLocation']['address']['addressCountry'] );
		$this->assertSame( 'FULL_TIME', $s['employmentType'] );
		$this->assertSame( 70000.0, $s['baseSalary']['value']['minValue'] );
		$remote = Schema_Markup::build( Listings::get( $this->job( $emp, array( 'remote_region' => 'EU' ) ) ) );
		$this->assertSame( 'TELECOMMUTE', $remote['jobLocationType'] );
		$this->assertSame( 'EU', $remote['applicantLocationRequirements']['name'] );
		$this->assertArrayNotHasKey( 'jobLocation', $remote );
	}

	public function test_templates_render_board_single_and_apply_box() {
		$emp = $this->employer();
		$job = Listings::get( $this->job( $emp, array( 'title' => 'Design Lead', 'featured' => 1 ) ) );
		wp_set_current_user( 0 );
		$card = Templates::job_card( $job );
		$this->assertStringContainsString( 'is-featured', $card );
		$this->assertStringContainsString( 'Design Lead', $card );
		$single = Templates::single_job( $job );
		$this->assertStringContainsString( 'data-dev3jb-apply="' . $job['id'] . '"', $single );
		$this->assertStringContainsString( 'name="consent"', $single );
		update_post_meta( $job['id'], '_dev3jb_filled', 1 );
		$this->assertStringNotContainsString( 'data-dev3jb-apply', Templates::apply_box( Listings::get( $job['id'] ) ) );
		wp_set_current_user( $emp );
		$this->assertStringContainsString( 'Review applications', Templates::apply_box( Listings::get( $job['id'] ) ) );
		$board = Templates::board( array() );
		$this->assertStringContainsString( 'data-dev3jb-board', $board );
		$this->assertStringContainsString( 'Design Lead', $board );
	}

	public function test_privacy_export_and_erase() {
		$cand = $this->candidate();
		$user = get_userdata( $cand );
		Resumes::save( $cand, Resumes::sanitize( array( 'headline' => 'Dev', 'skills' => 'PHP' ) ) );
		$job = $this->job( $this->employer() );
		Applications::create( $job, array( 'name' => 'C', 'email' => $user->user_email, 'candidate_id' => $cand ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$export = Privacy_Data::export( $user->user_email );
		$this->assertCount( 2, $export['data'] );
		$erase = Privacy_Data::erase( $user->user_email );
		$this->assertGreaterThanOrEqual( 2, $erase['items_removed'] );
		$this->assertSame( 0, Resumes::id_for_user( $cand ) );
		$this->assertSame( 0, Applications::count_for_job( $job ) );
	}
}
