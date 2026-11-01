<?php
/**
 * DEV3 AI features with Gemini mocked: writer, inclusive check, resume parsing, matching, fairness.
 *
 * @package Dev3\AiJobBoard
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiJobBoard\Ai\Describer;
use Dev3\AiJobBoard\Ai\Inclusive;
use Dev3\AiJobBoard\Ai\Matcher;
use Dev3\AiJobBoard\Ai\Resume_Parser;
use Dev3\AiJobBoard\Ai\Text_Extractor;
use Dev3\AiJobBoard\Core3\Core\Jobs;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Resumes;

class AiTest extends Dev3jb_TestCase {

	public function test_writer_template_without_ai() {
		$d = Describer::draft(
			array(
				'title'  => 'Product Designer',
				'notes'  => "We design calm software.\n- Design onboarding flows\n- 3+ years of product design experience\n- Remote budget and 30 days holiday",
				'skills' => 'Figma, Prototyping',
			)
		);
		$this->assertSame( 'template', $d['source'] );
		$this->assertSame( array( 'Design onboarding flows' ), $d['sections']['responsibilities'] );
		$this->assertSame( array( '3+ years of product design experience' ), $d['sections']['requirements'] );
		$this->assertSame( array( 'Remote budget and 30 days holiday' ), $d['sections']['benefits'] );
		$this->assertStringContainsString( '<h3>What you will do</h3>', $d['content'] );
		$this->assertWPError( Describer::draft( array() ) );
		$this->assertCount( 0, Dev3jb_Http::$requests, 'No AI call without a key' );
	}

	public function test_writer_with_ai_returns_a_draft_with_safe_html() {
		$this->with_key();
		Dev3jb_Http::on(
			'generativelanguage',
			Dev3jb_Http::gemini(
				array(
					'title'            => 'Senior Product Designer',
					'summary'          => 'Shape <script>x</script> our product.',
					'responsibilities' => array( 'Own the design system' ),
					'requirements'     => array( '5 years in product design' ),
					'nice_to_have'     => array(),
					'benefits'         => array(),
					'skills'           => array( 'Figma', 'Research' ),
					'experience'       => 'senior',
					'excerpt'          => 'Lead design.',
				)
			)
		);
		$d = Describer::draft( array( 'title' => 'Designer', 'notes' => 'design lead', 'tone' => 'friendly' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'ai', $d['source'] );
		$this->assertSame( 'Senior Product Designer', $d['title'] );
		$this->assertSame( 'senior', $d['experience'] );
		$this->assertStringNotContainsString( '<script>', $d['content'] );
		$prompt = wp_json_encode( Dev3jb_Http::$requests[0]['json'] );
		$this->assertStringContainsString( 'friendly tone', $prompt );
		$this->assertStringContainsString( 'inclusive', $prompt );
	}

	public function test_inclusive_rules_and_ai_phrases_must_exist_in_text() {
		$html = '<p>We want a young rockstar. He/she must be a native English speaker.</p>';
		$res  = Inclusive::check( $html, 'admin', false );
		$this->assertSame( 'rules', $res['source'] );
		$phrases = array_map( 'strtolower', wp_list_pluck( $res['issues'], 'phrase' ) );
		$this->assertContains( 'young', $phrases );
		$this->assertContains( 'rockstar', $phrases );
		$this->assertContains( 'he/she', $phrases );
		$this->assertContains( 'native english speaker', $phrases );
		$this->with_key();
		Dev3jb_Http::on(
			'generativelanguage',
			Dev3jb_Http::gemini(
				array(
					'issues' => array(
						array(
							'phrase'     => 'must be',
							'reason'     => 'Strong wording.',
							'suggestion' => 'should be',
						),
						array(
							'phrase'     => 'not in the ad',
							'reason'     => 'x',
							'suggestion' => 'y',
						),
					),
				)
			)
		);
		$ai = Inclusive::check( $html, 'admin', true );
		$this->assertSame( 'ai', $ai['source'] );
		$all = array_map( 'strtolower', wp_list_pluck( $ai['issues'], 'phrase' ) );
		$this->assertContains( 'must be', $all );
		$this->assertNotContains( 'not in the ad', $all );
		$fixed = Inclusive::apply( $html, array( array( 'phrase' => 'rockstar', 'suggestion' => 'expert' ), array( 'phrase' => 'young', 'suggestion' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( '<p>We want a expert. He/she must be a native English speaker.</p>', $fixed );
	}

	public function test_text_extraction_from_pdf_and_docx() {
		$text = Text_Extractor::pdf( dev3jb_test_pdf( array( 'Hello (World)', 'Second line' ) ) );
		$this->assertSame( "Hello (World)\nSecond line", $text );
		$this->assertSame( 'Plain', Text_Extractor::pdf( dev3jb_test_pdf( array( 'Plain' ), false ) ) );
		if ( class_exists( 'ZipArchive' ) ) {
			$path = $this->temp_file( '', 'docx' );
			$zip  = new ZipArchive();
			$zip->open( $path, ZipArchive::OVERWRITE );
			$zip->addFromString( 'word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Jane Doe</w:t></w:r></w:p><w:p><w:r><w:t>Designer &amp; Researcher</w:t></w:r></w:p></w:body></w:document>' );
			$zip->close();
			$this->assertSame( "Jane Doe\nDesigner & Researcher", Text_Extractor::docx( $path ) );
		}
	}

	public function test_rule_parser_reads_a_resume_pdf_without_protected_data() {
		$path = $this->temp_file( dev3jb_test_pdf( $this->resume_lines() ) );
		$res  = Resume_Parser::parse_path( $path, 'application/pdf', 'frontend', true );
		$this->assertSame( 'rules', $res['source'] );
		$p = $res['profile'];
		$this->assertSame( 'Senior Frontend Developer', $p['headline'] );
		$this->assertSame( 'Berlin, Germany', $p['location'] );
		$this->assertContains( 'React', $p['skills'] );
		$this->assertContains( 'GraphQL', $p['skills'] );
		$this->assertCount( 2, $p['experience'] );
		$this->assertSame( 'Brightwave', $p['experience'][0]['company'] );
		$this->assertTrue( $p['experience'][0]['current'] );
		$this->assertSame( '2016-03', $p['experience'][1]['start'] );
		$this->assertSame( 'TU Berlin', $p['education'][0]['school'] );
		$this->assertSame( 'https://github.com/alexm', $p['links'][0]['url'] );
		$json = wp_json_encode( $p );
		foreach ( array( 'alex.morgan@example.com', '555 123', 'gender', 'nationality', 'birth', 'Alex Morgan' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $json );
		}
		$this->assertEqualsCanonicalizing( array( 'headline', 'summary', 'location', 'skills', 'experience', 'education', 'links' ), $res['filled'] );
	}

	public function test_ai_parser_strips_protected_attributes_and_contact_data() {
		$this->with_key();
		Dev3jb_Http::on(
			'generativelanguage',
			Dev3jb_Http::gemini(
				array(
					'headline'    => 'Senior Frontend Developer',
					'summary'     => 'Reach me at alex.morgan@example.com.',
					'location'    => 'Berlin, Germany',
					'skills'      => array( 'React', 'TypeScript' ),
					'experience'  => array(
						array(
							'title'   => 'Frontend Lead',
							'company' => 'Brightwave',
							'start'   => '2020-01',
							'end'     => '',
							'current' => true,
							'summary' => 'Led a team.',
						),
					),
					'education'   => array(),
					'links'       => array(),
					'gender'      => 'female',
					'age'         => 34,
					'nationality' => 'Canadian',
					'photo'       => 'data:image/png;base64,xx',
				)
			)
		);
		$path = $this->temp_file( dev3jb_test_pdf( $this->resume_lines() ) );
		$res  = Resume_Parser::parse_path( $path, 'application/pdf', 'frontend', true );
		$this->assertSame( 'ai', $res['source'] );
		foreach ( array( 'gender', 'age', 'nationality', 'photo' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $res['profile'] );
		}
		$this->assertStringNotContainsString( 'alex.morgan@example.com', $res['profile']['summary'] );
		$this->assertSame( 'Frontend Lead', $res['profile']['experience'][0]['title'], 'Job titles inside entries are kept' );
		$sent = wp_json_encode( Dev3jb_Http::$requests[0]['json'] );
		$this->assertStringNotContainsString( 'alex.morgan@example.com', $sent, 'Contact data is masked before sending' );
		$this->assertStringContainsString( 'Fairness rules', $sent );
	}

	public function test_scanned_pdf_is_sent_as_a_file_to_ai() {
		$this->with_key();
		Dev3jb_Http::on( 'generativelanguage', Dev3jb_Http::gemini( array( 'headline' => 'Nurse', 'summary' => '', 'location' => '', 'skills' => array(), 'experience' => array(), 'education' => array(), 'links' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$path = $this->temp_file( "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF" );
		$res  = Resume_Parser::parse_path( $path, 'application/pdf', 'frontend', true );
		$this->assertSame( 'Nurse', $res['profile']['headline'] );
		$this->assertSame( 'application/pdf', Dev3jb_Http::$requests[0]['json']['contents'][0]['parts'][0]['inline_data']['mime_type'] );
		$this->assertWPError( Resume_Parser::parse_path( $path, 'application/pdf', 'frontend', false ), 'Without AI a scanned PDF cannot be read' );
	}

	public function test_strip_protected_and_anonymise() {
		$clean = Matcher::strip_protected(
			array(
				'name'       => 'X',
				'Gender'     => 'f',
				'date-of-birth' => '1990',
				'skills'     => array( 'PHP' ),
				'experience' => array( array( 'title' => 'Dev', 'religion' => 'x' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
			)
		);
		$this->assertSame(
			array(
				'skills'     => array( 'PHP' ),
				'experience' => array( array( 'title' => 'Dev' ) ),
			),
			$clean
		);
		$anon = Matcher::anonymise(
			array(
				'name'     => 'Alex Morgan',
				'email'    => 'a@example.com',
				'phone'    => '+1 555 123 4567',
				'summary'  => 'Alex builds apps. Call +1 555 123 4567.',
				'headline' => 'Dev',
				'links'    => array( array( 'url' => 'https://alex.example' ) ),
			)
		);
		$json = wp_json_encode( $anon );
		foreach ( array( 'Alex', 'a@example.com', '555', 'alex.example' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_rule_score_is_transparent_and_reproducible() {
		$emp     = $this->employer();
		$job     = Listings::get( $this->job( $emp, array( 'title' => 'Senior Frontend Developer', 'experience' => 'senior' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$profile = Matcher::anonymise(
			array(
				'headline'   => 'Frontend Developer',
				'skills'     => array( 'React', 'CSS' ),
				'experience' => array(
					array(
						'title'   => 'Frontend Developer',
						'start'   => gmdate( 'Y', time() - 3 * YEAR_IN_SECONDS ) . '-01',
						'current' => true,
						'summary' => 'Wrote TypeScript every day.',
					),
				),
				'workplace'  => array( 'remote' ),
			)
		);
		$a = Matcher::rule_score( $job, $profile );
		$b = Matcher::rule_score( $job, $profile );
		$this->assertSame( $a, $b );
		$this->assertSame( 100, $a['criteria']['skills'], 'TypeScript found in the experience text' );
		$this->assertSame( 100, $a['criteria']['location'], 'Remote job' );
		$this->assertLessThan( 100, $a['criteria']['experience'] );
		$this->assertSame( 5, $a['required_years'] );
		$this->assertGreaterThanOrEqual( 70, $a['score'] );
		$this->assertNotEmpty( $a['strengths'] );
		$this->assertNotEmpty( $a['gaps'] );
	}

	public function test_score_application_with_ai_sends_no_personal_data_and_keeps_stage() {
		$this->with_key();
		$emp  = $this->employer();
		$cand = $this->candidate( 'Priya Raman' );
		Resumes::save( $cand, Resumes::sanitize( array( 'headline' => 'Frontend Developer', 'summary' => 'Priya loves React. Email priya@example.com', 'skills' => 'React, TypeScript', 'phone' => '+44 7700 900123' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$job = $this->job( $emp );
		$app = Applications::create( $job, array( 'name' => 'Priya Raman', 'email' => 'priya@example.com', 'candidate_id' => $cand, 'phone' => '+44 7700 900123' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		Dev3jb_Http::on(
			'generativelanguage',
			Dev3jb_Http::gemini(
				array(
					'score'     => 90,
					'criteria'  => array(
						'skills'     => 95,
						'experience' => 80,
						'role'       => 90,
						'location'   => 100,
					),
					'strengths' => array( 'Has React and TypeScript, both required.' ),
					'gaps'      => array( 'Years of experience not stated.' ),
					'summary'   => 'Strong match on required skills.',
				)
			)
		);
		$res = Matcher::score_application( $app['id'], true, 'admin' );
		$this->assertSame( 'ai', $res['source'] );
		$this->assertSame( (int) round( ( $res['rule_score'] + 90 ) / 2 ), $res['score'] );
		$sent = wp_json_encode( Dev3jb_Http::$requests[0]['json'] );
		foreach ( array( 'Priya', 'priya@example.com', '7700', 'Raman' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $sent );
		}
		$stored = Applications::get( $app['id'] );
		$this->assertSame( $res['score'], $stored['match_score'] );
		$this->assertSame( 'new', $stored['stage'], 'A score never moves a candidate' );
		$this->assertSame( 'match', $stored['events'][0]['kind'] );
	}

	public function test_guest_application_is_scored_from_its_file() {
		$emp  = $this->employer();
		$job  = $this->job( $emp );
		$file = Files::store( $this->temp_file( dev3jb_test_pdf( $this->resume_lines() ) ), 'cv.pdf', 0, 'attachment' );
		$app  = Applications::create( $job, array( 'name' => 'Alex', 'email' => 'alex@example.com', 'file_id' => $file['id'] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$res  = Matcher::score_application( $app['id'], false );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertContains( 'React', $res['matched_skills'] );
	}

	public function test_bulk_scoring_job_and_recommendations() {
		$emp  = $this->employer();
		$job  = $this->job( $emp );
		$cand = $this->candidate();
		Resumes::save( $cand, Resumes::sanitize( array( 'headline' => 'Frontend Developer', 'skills' => 'React, TypeScript, CSS', 'workplace' => array( 'remote' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		foreach ( array( 'a', 'b' ) as $n ) {
			Applications::create( $job, array( 'name' => $n, 'email' => "$n@example.com" ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		}
		add_filter( 'dev3jb_jobs_use_action_scheduler', '__return_false' );
		$queue = Matcher::start_bulk( $job, false );
		Jobs::run( $queue, 30 );
		$this->assertSame( 'done', Jobs::get( $queue )['status'] );
		foreach ( Applications::query( array( 'job_id' => $job ) )['items'] as $a ) {
			$this->assertNotNull( $a['match_score'] );
		}
		$this->job( $emp, array( 'title' => 'Accountant', 'content' => 'Numbers', 'skills' => 'Excel', 'workplace' => 'onsite' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$rec = Matcher::recommend( $cand, 2 );
		$this->assertSame( $job, $rec[0]['job']['id'] );
		$this->assertContains( 'React', $rec[0]['reasons'] );
	}

	public function test_prompts_are_registered_with_bounded_schemas() {
		foreach ( array( \Dev3\AiJobBoard\Ai\Prompts_Library::resume_schema(), \Dev3\AiJobBoard\Ai\Prompts_Library::describe_schema(), \Dev3\AiJobBoard\Ai\Prompts_Library::match_schema() ) as $schema ) {
			array_walk_recursive(
				$schema,
				function ( $v, $k ) {
					if ( 'type' === $k && 'STRING' === $v ) {
						$this->addToAssertionCount( 1 );
					}
				}
			);
			$json = wp_json_encode( $schema );
			$this->assertSame( substr_count( $json, '"type":"STRING"' ) - substr_count( $json, '"enum"' ), substr_count( $json, 'maxLength' ), 'Every free STRING has maxLength' );
		}
		$resume = wp_json_encode( \Dev3\AiJobBoard\Ai\Prompts_Library::resume_schema() );
		foreach ( array( 'gender', 'age', 'birth', 'nationality', 'photo', 'marital', 'religion', '"name"', 'email', 'phone' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $resume );
		}
	}
}
