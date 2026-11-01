<?php
/**
 * Base test case (loaded by each test file after the WordPress test suite).
 *
 * @package Dev3\AiJobBoard
 */

use Dev3\AiJobBoard\Core3\Api\Ai;
use Dev3\AiJobBoard\Core3\Core\Cache;
use Dev3\AiJobBoard\Core3\Core\Schema;
use Dev3\AiJobBoard\Core3\Core\Settings_Store;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Tables;

require_once __DIR__ . '/fixtures/pdf.php';

/**
 * Base case: fresh tables, settings and mocks for every test.
 */
abstract class Dev3jb_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3JB_DB_VERSION );
		global $wpdb;
		foreach ( Tables::NAMES as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore
		}
		update_option( Settings_Store::OPTION, array() );
		delete_option( Packages::OPTION );
		Settings_Store::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Dev3jb_Http::reset();
		add_filter( 'dev3jb_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3jb_skip_upload_check', '__return_true' );
		\Dev3\AiJobBoard\Ai\Prompts_Library::register();
		\Dev3\AiJobBoard\Core3\Data\Usage_Repository::flush();
		Post_Types::add_roles();
		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_filter( 'dev3jb_skip_upload_check', '__return_true' );
		Dev3jb_Http::reset();
		parent::tear_down();
	}

	/**
	 * Sets a fake key so AI features run (against the mocked endpoint).
	 */
	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Settings patch.
	 *
	 * @param array $patch Patch.
	 */
	protected function settings( array $patch ) {
		Settings_Store::update( $patch );
		Settings_Store::flush();
	}

	/**
	 * Term id (created on demand).
	 *
	 * @param string $name     Name.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $parent_id Parent.
	 * @return int
	 */
	protected function term( $name, $taxonomy, $parent_id = 0 ) {
		$t = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent_id ) );
		return is_wp_error( $t ) ? (int) $t->get_error_data() : (int) $t['term_id'];
	}

	/**
	 * Employer user.
	 *
	 * @return int
	 */
	protected function employer() {
		return self::factory()->user->create( array( 'role' => Post_Types::ROLE_EMPLOYER ) );
	}

	/**
	 * Candidate user.
	 *
	 * @param string $name Display name.
	 * @return int
	 */
	protected function candidate( $name = 'Alex Morgan' ) {
		return self::factory()->user->create(
			array(
				'role'         => Post_Types::ROLE_CANDIDATE,
				'display_name' => $name,
			)
		);
	}

	/**
	 * Company owned by a user.
	 *
	 * @param int    $owner Owner.
	 * @param string $name  Name.
	 * @return int
	 */
	protected function company( $owner, $name = 'Brightwave' ) {
		$id = Companies::save(
			array(
				'name'    => $name,
				'website' => 'https://brightwave.example',
			),
			0,
			$owner
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Published job.
	 *
	 * @param int   $author Author.
	 * @param array $data   Overrides (raw input for Listings::sanitize()).
	 * @param string $status Status.
	 * @return int
	 */
	protected function job( $author, array $data = array(), $status = 'publish' ) {
		$id = Listings::save(
			Listings::sanitize(
				array_merge(
					array(
						'title'        => 'Frontend Developer',
						'content'      => '<p>Build our web app with React and TypeScript. 3+ years of experience.</p>',
						'workplace'    => 'remote',
						'skills'       => 'React, TypeScript, CSS',
						'experience'   => 'mid',
						'apply_method' => 'internal',
						'salary_min'   => 70000,
						'salary_max'   => 90000,
					),
					$data
				)
			),
			0,
			$author,
			$status
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Writes a temp file and returns its path.
	 *
	 * @param string $bytes Content.
	 * @param string $ext   Extension.
	 * @return string
	 */
	protected function temp_file( $bytes, $ext = 'pdf' ) {
		$path = get_temp_dir() . 'dev3jb-test-' . wp_generate_password( 8, false ) . '.' . $ext;
		file_put_contents( $path, $bytes ); // phpcs:ignore
		return $path;
	}

	/**
	 * Fixture resume text (includes protected attributes on purpose).
	 *
	 * @return string[]
	 */
	protected function resume_lines() {
		return array(
			'Alex Morgan',
			'Senior Frontend Developer',
			'alex.morgan@example.com | +1 555 123 4567',
			'Date of birth: 01/02/1990 | Gender: female | Nationality: Canadian | Married',
			'Location: Berlin, Germany',
			'Summary',
			'Frontend engineer who builds fast, accessible web apps.',
			'Skills',
			'React, TypeScript, Node.js, CSS, GraphQL',
			'Experience',
			'Frontend Lead at Brightwave, Jan 2020 - Present',
			'- Led a team of 5 building a design system',
			'Developer, Acme Corp',
			'03/2016 - 12/2019',
			'Built e-commerce features in Vue',
			'Education',
			'BSc in Computer Science, TU Berlin, 2015',
			'https://github.com/alexm',
		);
	}
}
