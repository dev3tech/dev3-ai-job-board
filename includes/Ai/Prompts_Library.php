<?php
/**
 * Versioned prompt templates and output schemas for every DEV3 AI feature of the plugin.
 *
 * Override with the `dev3jb_prompt_<id>` filter (e.g. `dev3jb_prompt_dev3jb_describe`).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Ai;

use Dev3\AiJobBoard\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Registers prompts on init.
 */
class Prompts_Library {

	const DESCRIBE  = 'dev3jb_describe';
	const INCLUSIVE = 'dev3jb_inclusive';
	const PARSE     = 'dev3jb_parse_resume';
	const MATCH     = 'dev3jb_match';

	/**
	 * Fairness instruction shared by the resume and match prompts.
	 */
	const FAIRNESS = 'Fairness rules: never use, mention, guess or infer protected attributes: name, age or date of birth, gender or pronouns, photo or appearance, marital or family status, pregnancy, nationality, ethnicity or race, religion, disability or health, sexual orientation, union membership or political views. Judge only job-relevant skills, experience, education and the stated workplace preference. If the source contains such attributes, ignore them.';

	/**
	 * String schema helper.
	 *
	 * @param int $max Max length.
	 * @return array
	 */
	public static function str( $max ) {
		return array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
	}

	/**
	 * String list helper.
	 *
	 * @param int $items Max items.
	 * @param int $max   Max length per item.
	 * @return array
	 */
	public static function list_of( $items, $max ) {
		return array(
			'type'     => 'ARRAY',
			'maxItems' => $items,
			'items'    => self::str( $max ),
		);
	}

	/**
	 * Integer 0-100 schema.
	 *
	 * @return array
	 */
	public static function pct() {
		return array(
			'type'    => 'INTEGER',
			'minimum' => 0,
			'maximum' => 100,
		);
	}

	/**
	 * Job description schema.
	 *
	 * @return array
	 */
	public static function describe_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'title'            => self::str( 120 ),
				'summary'          => self::str( 600 ),
				'responsibilities' => self::list_of( 8, 220 ),
				'requirements'     => self::list_of( 8, 220 ),
				'nice_to_have'     => self::list_of( 5, 220 ),
				'benefits'         => self::list_of( 6, 220 ),
				'skills'           => self::list_of( 12, 40 ),
				'experience'       => array(
					'type' => 'STRING',
					'enum' => array( '', 'entry', 'mid', 'senior', 'lead', 'executive' ),
				),
				'excerpt'          => self::str( 200 ),
			),
			'required'   => array( 'title', 'summary', 'responsibilities', 'requirements', 'skills', 'excerpt' ),
		);
	}

	/**
	 * Resume schema. It has no field for any protected attribute on purpose.
	 *
	 * @return array
	 */
	public static function resume_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'headline'   => self::str( 140 ),
				'summary'    => self::str( 1200 ),
				'location'   => self::str( 120 ),
				'skills'     => self::list_of( 40, 40 ),
				'experience' => array(
					'type'     => 'ARRAY',
					'maxItems' => 12,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'title'   => self::str( 120 ),
							'company' => self::str( 120 ),
							'start'   => self::str( 7 ),
							'end'     => self::str( 7 ),
							'current' => array( 'type' => 'BOOLEAN' ),
							'summary' => self::str( 600 ),
						),
						'required'   => array( 'title', 'company', 'start', 'end', 'current', 'summary' ),
					),
				),
				'education'  => array(
					'type'     => 'ARRAY',
					'maxItems' => 8,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'degree' => self::str( 120 ),
							'school' => self::str( 120 ),
							'field'  => self::str( 120 ),
							'year'   => self::str( 4 ),
						),
						'required'   => array( 'degree', 'school', 'field', 'year' ),
					),
				),
				'links'      => array(
					'type'     => 'ARRAY',
					'maxItems' => 5,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'label' => self::str( 40 ),
							'url'   => self::str( 200 ),
						),
						'required'   => array( 'label', 'url' ),
					),
				),
			),
			'required'   => array( 'headline', 'summary', 'location', 'skills', 'experience', 'education', 'links' ),
		);
	}

	/**
	 * Match explanation schema.
	 *
	 * @return array
	 */
	public static function match_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'score'     => self::pct(),
				'criteria'  => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'skills'     => self::pct(),
						'experience' => self::pct(),
						'role'       => self::pct(),
						'location'   => self::pct(),
					),
					'required'   => array( 'skills', 'experience', 'role', 'location' ),
				),
				'strengths' => self::list_of( 4, 200 ),
				'gaps'      => self::list_of( 4, 200 ),
				'summary'   => self::str( 400 ),
			),
			'required'   => array( 'score', 'criteria', 'strengths', 'gaps', 'summary' ),
		);
	}

	/**
	 * Registers all templates.
	 */
	public static function register() {
		Prompts::register(
			self::DESCRIBE,
			array(
				'version'     => '1',
				'task'        => 'job_describe',
				'max_tokens'  => 4096,
				'temperature' => 0.6,
				'cache'       => DAY_IN_SECONDS,
				'system'      => 'You are an expert recruiter who writes clear, honest and inclusive job ads. Use plain words, short bullet points and gender-neutral language ("you", "they"). Do not invent salary numbers, perks or facts that the employer did not give; when benefits are unknown, return an empty list. Avoid age-coded or exclusionary words (young, digital native, rockstar, ninja, energetic team, native speaker, culture fit). Write in {{language}} with a {{tone}} tone.',
				'template'    => "Write a job ad.\n\nJob title: {{title}}\nCompany: {{company}}\nAbout the company: {{company_about}}\nJob type: {{types}}\nWorkplace: {{workplace}}\nLocation: {{location}}\nExperience level: {{experience}}\nSalary given: {{salary}}\nSkills mentioned: {{skills}}\n\nEMPLOYER NOTES (main source of facts)\n{{notes}}\n\nReturn: an improved title (keep it searchable, no emojis), a 2-3 sentence summary of the role, 4-8 responsibilities, 4-8 must-have requirements (only what is truly needed), up to 5 nice-to-haves, benefits (only from the notes), up to 12 skills (short names), the experience level, and a one-sentence excerpt for search results.",
			)
		);

		Prompts::register(
			self::INCLUSIVE,
			array(
				'version'     => '1',
				'task'        => 'job_inclusive',
				'max_tokens'  => 2048,
				'temperature' => 0.2,
				'cache'       => DAY_IN_SECONDS,
				'system'      => 'You review job ads for exclusionary or biased language (gender-coded, age-coded, ableist, nationality or culture-coded wording, unnecessary requirements such as degrees or years when skills are what matter). Suggest short, neutral alternatives. Do not flag neutral wording. Answer in {{language}}.',
				'template'    => "Review this job ad. Return each problem phrase exactly as it appears in the text, why it may exclude candidates, and a replacement phrase that fits the same sentence.\n\nRules already found by a word list (confirm or skip them): {{rule_hits}}\n\nJOB AD\n{{text}}",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'issues' => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'phrase'     => self::str( 80 ),
									'reason'     => self::str( 200 ),
									'suggestion' => self::str( 80 ),
								),
								'required'   => array( 'phrase', 'reason', 'suggestion' ),
							),
						),
					),
					'required'   => array( 'issues' ),
				),
			)
		);

		Prompts::register(
			self::PARSE,
			array(
				'version'    => '1',
				'task'       => 'resume_parse',
				'profile'    => 'heavy',
				'max_tokens' => 8192,
				'system'     => 'You turn a resume into a structured candidate profile. Use only facts in the resume; leave strings empty when unknown. Dates: "YYYY-MM" (use "YYYY-01" when only a year is given; end "" and current true for a current job). ' . self::FAIRNESS . ' Do not copy email addresses, phone numbers or street addresses into any field. Keep the language of the resume.',
				'template'   => "Extract the candidate profile.\n- headline: current or target role in a few words (e.g. \"Senior Frontend Developer\").\n- summary: 2-3 sentences about the professional profile (no personal details).\n- location: city and country only, if stated.\n- skills: short skill names (tools, languages, methods), most important first.\n- experience: newest first.\n- education: degrees and certificates.\n- links: portfolio, GitHub, LinkedIn or personal site URLs.\n\nKNOWN SKILL NAMES ON THIS SITE (prefer these spellings): {{vocabulary}}\n\nRESUME ({{source_label}})\n{{text}}",
				'schema'     => self::resume_schema(),
			)
		);

		Prompts::register(
			self::MATCH,
			array(
				'version'     => '1',
				'task'        => 'resume_match',
				'max_tokens'  => 2048,
				'temperature' => 0.1,
				'cache'       => WEEK_IN_SECONDS,
				'system'      => 'You help a hiring team see how well a candidate profile fits a job. You give decision support, not decisions: be balanced, concrete and brief, and cite job requirements in every strength and gap. ' . self::FAIRNESS . ' Score 0-100 where 50 means a partial fit and 80+ means most requirements are clearly met. Criteria: skills (required skills present), experience (relevant years and level), role (similar responsibilities done before), location (workplace and location fit; 100 when the job is remote). Write in {{language}}.',
				'template'    => "JOB\n{{job}}\n\nCANDIDATE (anonymised)\n{{candidate}}\n\nRULE-BASED PRE-CHECK (for reference)\n{{precheck}}\n\nReturn the score, the four criteria scores, up to 4 strengths, up to 4 gaps and a 1-2 sentence summary.",
				'schema'      => self::match_schema(),
			)
		);
	}

	/**
	 * Output language name for the site locale.
	 *
	 * @return string
	 */
	public static function language() {
		$locale = determine_locale();
		$names  = array(
			'en' => 'English',
			'es' => 'Spanish',
			'fr' => 'French',
			'de' => 'German',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'ar' => 'Arabic',
			'hi' => 'Hindi',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'ru' => 'Russian',
			'tr' => 'Turkish',
			'pl' => 'Polish',
			'sv' => 'Swedish',
		);
		$code   = strtolower( substr( (string) $locale, 0, 2 ) );
		return isset( $names[ $code ] ) ? $names[ $code ] : 'the same language as the input';
	}
}
