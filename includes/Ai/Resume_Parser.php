<?php
/**
 * Resume parsing: a PDF/DOCX/TXT resume becomes a structured profile draft that the candidate reviews.
 *
 * DEV3 AI reads the document (PDFs go in as files, so scanned PDFs work too). Without AI, the built-in
 * extractor + section heuristics + the site's skill vocabulary fill what they can. The output never
 * contains protected attributes (see Matcher::strip_protected()).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Ai;

use Dev3\AiJobBoard\Core3\Api\Ai;
use Dev3\AiJobBoard\Core3\Api\Prompts;
use Dev3\AiJobBoard\Core3\Core\Privacy;
use Dev3\AiJobBoard\Core3\Core\Settings_Store;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Resume_Parser {

	/**
	 * Common skill names used when the site has few skill terms yet.
	 *
	 * @var string[]
	 */
	const COMMON_SKILLS = array( 'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular', 'Node.js', 'PHP', 'WordPress', 'Laravel', 'Python', 'Django', 'Java', 'Kotlin', 'Swift', 'Go', 'Rust', 'C#', 'C++', '.NET', 'Ruby', 'SQL', 'MySQL', 'PostgreSQL', 'MongoDB', 'GraphQL', 'REST', 'AWS', 'Azure', 'Google Cloud', 'Docker', 'Kubernetes', 'Terraform', 'CI/CD', 'Git', 'Linux', 'HTML', 'CSS', 'Sass', 'Tailwind', 'Figma', 'Sketch', 'Adobe XD', 'Photoshop', 'Illustrator', 'UX research', 'User research', 'Prototyping', 'Wireframing', 'Accessibility', 'SEO', 'Content marketing', 'Copywriting', 'Google Analytics', 'Google Ads', 'Social media', 'Email marketing', 'HubSpot', 'Salesforce', 'Excel', 'Power BI', 'Tableau', 'Data analysis', 'Machine learning', 'Statistics', 'Project management', 'Agile', 'Scrum', 'Jira', 'Product management', 'Stakeholder management', 'Customer service', 'Sales', 'Negotiation', 'Account management', 'Bookkeeping', 'Accounting', 'Payroll', 'Recruiting', 'Leadership', 'Mentoring', 'Public speaking', 'Testing', 'QA', 'Cypress', 'Jest', 'Playwright', 'Security', 'Networking', 'Nursing', 'Teaching', 'Logistics', 'Supply chain' );

	/**
	 * Whether AI parsing can run.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function ai_available( $context = 'frontend' ) {
		return (bool) Settings::get( 'ai_parse', true ) && Ai::enabled( $context ) && '' !== Settings_Store::api_key();
	}

	/**
	 * Skill vocabulary (site terms first, then common names).
	 *
	 * @return string[]
	 */
	public static function vocabulary() {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::SKILL,
				'hide_empty' => false,
				'number'     => 400,
				'fields'     => 'names',
			)
		);
		$names = is_array( $terms ) ? array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES, 'UTF-8' ), $terms ) : array();
		$all   = array();
		foreach ( array_merge( $names, self::COMMON_SKILLS ) as $n ) {
			$all[ self::norm( $n ) ] = $n;
		}
		/**
		 * Filters the skill vocabulary used by parsing and matching.
		 *
		 * @param string[] $skills Skill names.
		 */
		return array_values( (array) apply_filters( 'dev3jb_skill_vocabulary', array_values( $all ) ) );
	}

	/**
	 * Normalised skill key ("Node.js" → "node", "React.js" → "react").
	 *
	 * @param string $skill Skill.
	 * @return string
	 */
	public static function norm( $skill ) {
		$s = mb_strtolower( trim( (string) $skill ) );
		$s = preg_replace( '/\.?js$/', '', $s );
		$s = preg_replace( '/[^a-z0-9#+]+/u', '', (string) $s );
		return (string) $s;
	}

	/**
	 * Parses a stored file of a user.
	 *
	 * @param int    $file_id File.
	 * @param int    $user_id Owner (access check).
	 * @param string $context Context.
	 * @param bool   $use_ai  Try DEV3 AI.
	 * @return array|\WP_Error { profile, source, filled, meta, chars }
	 */
	public static function parse_file( $file_id, $user_id, $context = 'frontend', $use_ai = true ) {
		$file = Files::get( $file_id );
		if ( ! $file || ! Files::can_access( $file, $user_id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Resume file not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$path = Files::path( $file );
		if ( '' === $path ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Resume file not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		return self::parse_path( $path, $file['mime'], $context, $use_ai );
	}

	/**
	 * Parses a resume on disk.
	 *
	 * @param string $path    Path.
	 * @param string $mime    MIME.
	 * @param string $context Context.
	 * @param bool   $use_ai  Try DEV3 AI.
	 * @return array|\WP_Error
	 */
	public static function parse_path( $path, $mime, $context = 'frontend', $use_ai = true ) {
		$text = Text_Extractor::extract( $path, $mime );
		if ( $use_ai && self::ai_available( $context ) ) {
			$vars   = array(
				'vocabulary'   => implode( ', ', array_slice( self::vocabulary(), 0, 250 ) ),
				'text'         => '' !== $text ? Privacy::mask( $text ) : '(see the attached PDF)',
				'source_label' => 'text extracted from the file',
			);
			$extra  = array();
			$images = array();
			/**
			 * Whether to send the PDF itself to DEV3 AI (default: when it has little or no text layer).
			 *
			 * @param bool   $send Send the file.
			 * @param string $text Extracted text.
			 */
			$send_pdf = 'application/pdf' === $mime && (bool) apply_filters( 'dev3jb_parse_send_pdf', mb_strlen( $text ) < 200, $text );
			if ( $send_pdf ) {
				// Little or no text layer (scanned PDF): let the model read the document itself.
				$images[]             = array(
					'mime' => 'application/pdf',
					'data' => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				);
				$vars['text']         = '(see the attached PDF)';
				$vars['source_label'] = 'attached PDF';
			}
			if ( $images ) {
				$extra['images'] = $images;
			}
			$extra['cache'] = DAY_IN_SECONDS;
			$res            = Prompts::run( Prompts_Library::PARSE, $vars, $context, $extra );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$profile = self::clean_profile( is_array( $res['data'] ) ? $res['data'] : array() );
			return self::result(
				$profile,
				'ai',
				array(
					'model'  => $res['model'],
					'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
					'cached' => ! empty( $res['cached'] ),
				),
				mb_strlen( $text )
			);
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'dev3jb_parse_empty', __( 'We could not read text from this file (it may be a scanned image). Fill in your profile by hand, or upload a text-based PDF or Word file.', 'dev3-ai-job-board' ), array( 'status' => 422 ) );
		}
		return self::result( self::clean_profile( self::heuristic( $text ) ), 'rules', array(), mb_strlen( $text ) );
	}

	/**
	 * Sanitised profile draft without protected attributes or contact data.
	 *
	 * @param array $raw Raw parse.
	 * @return array
	 */
	public static function clean_profile( array $raw ) {
		$raw   = Matcher::strip_protected( $raw );
		$clean = Resumes::sanitize( $raw );
		unset( $clean['phone'], $clean['visible'], $clean['file_id'] );
		foreach ( array( 'summary', 'headline' ) as $key ) {
			if ( isset( $clean[ $key ] ) ) {
				$clean[ $key ] = trim( Privacy::mask( $clean[ $key ] ) );
			}
		}
		if ( isset( $clean['experience'] ) ) {
			foreach ( $clean['experience'] as &$row ) {
				$row['summary'] = trim( Privacy::mask( $row['summary'] ) );
			}
			unset( $row );
		}
		return $clean;
	}

	/**
	 * Result envelope.
	 *
	 * @param array  $profile Profile.
	 * @param string $source  ai|rules.
	 * @param array  $meta    Meta.
	 * @param int    $chars   Extracted characters.
	 * @return array
	 */
	private static function result( array $profile, $source, array $meta, $chars ) {
		$filled = array();
		foreach ( array( 'headline', 'summary', 'location', 'skills', 'experience', 'education', 'links' ) as $key ) {
			if ( ! empty( $profile[ $key ] ) ) {
				$filled[] = $key;
			}
		}
		return array(
			'profile' => $profile,
			'source'  => $source,
			'filled'  => $filled,
			'meta'    => $meta,
			'chars'   => (int) $chars,
		);
	}

	/**
	 * Rule-based parse of resume text.
	 *
	 * @param string $text Text.
	 * @return array Raw profile.
	 */
	public static function heuristic( $text ) {
		$lines    = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' ) );
		$sections = array(
			'summary'    => '/^(summary|profile|about( me)?|professional summary|objective)\s*:?$/i',
			'experience' => '/^(experience|work experience|work history|employment( history)?|professional experience|career)\s*:?$/i',
			'education'  => '/^(education|qualifications|certifications?|education & certifications)\s*:?$/i',
			'skills'     => '/^(skills|technical skills|core skills|key skills|competencies|tools)\s*:?/i',
			'other'      => '/^(languages|interests|hobbies|references|projects|awards|volunteering)\s*:?$/i',
		);
		$current  = 'head';
		$buckets  = array( 'head' => array() );
		foreach ( $lines as $line ) {
			$matched = false;
			foreach ( $sections as $key => $re ) {
				if ( mb_strlen( $line ) < 40 && preg_match( $re, $line ) ) {
					$current = $key;
					$matched = true;
					$rest    = trim( (string) preg_replace( $re, '', $line ) );
					if ( '' !== $rest ) {
						$buckets[ $current ][] = $rest;
					}
					break;
				}
			}
			if ( ! $matched ) {
				$buckets[ $current ][] = $line;
			}
		}
		$out = array(
			'headline'   => '',
			'summary'    => '',
			'location'   => '',
			'skills'     => array(),
			'experience' => array(),
			'education'  => array(),
			'links'      => array(),
		);
		// Headline: a role-like line near the top (the first line is usually the name, which we skip).
		$role_re = '/\b(developer|engineer|designer|manager|analyst|specialist|consultant|lead|director|architect|scientist|marketer|writer|editor|accountant|nurse|teacher|assistant|coordinator|officer|administrator|technician|recruiter|representative|executive|intern|strategist|product owner|copywriter)\b/i';
		foreach ( array_slice( isset( $buckets['head'] ) ? $buckets['head'] : array(), 0, 8 ) as $i => $line ) {
			if ( 0 === $i && ! preg_match( $role_re, $line ) ) {
				continue;
			}
			if ( preg_match( $role_re, $line ) && mb_strlen( $line ) <= 80 && ! preg_match( '/@|\d{3}/', $line ) ) {
				$out['headline'] = $line;
				break;
			}
		}
		foreach ( array_slice( isset( $buckets['head'] ) ? $buckets['head'] : array(), 0, 10 ) as $line ) {
			if ( preg_match( '/^(location|based in|address)\s*:\s*(.+)$/i', $line, $m ) ) {
				$out['location'] = trim( $m[2] );
			}
		}
		if ( ! empty( $buckets['summary'] ) ) {
			$out['summary'] = mb_substr( implode( ' ', $buckets['summary'] ), 0, 1000 );
		}
		// Skills: the skills section split on separators + vocabulary hits anywhere in the text.
		$skills = array();
		foreach ( isset( $buckets['skills'] ) ? $buckets['skills'] : array() as $line ) {
			foreach ( preg_split( '/[,;|•·\/]| - /u', $line ) as $s ) {
				$s = trim( preg_replace( '/\s*\(.*?\)\s*/', '', $s ), " \t-*:." );
				if ( '' !== $s && mb_strlen( $s ) <= 40 && str_word_count( $s ) <= 4 ) {
					$skills[ self::norm( $s ) ] = $s;
				}
			}
		}
		foreach ( self::vocabulary() as $skill ) {
			$key = self::norm( $skill );
			if ( '' === $key || isset( $skills[ $key ] ) ) {
				continue;
			}
			if ( preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $skill, '/' ) . '(?![A-Za-z0-9])/i', $text ) ) {
				$skills[ $key ] = $skill;
			}
		}
		$out['skills'] = array_slice( array_values( $skills ), 0, 40 );
		// Experience: lines with a date range; title/company from the line (or the line before).
		$exp_lines = isset( $buckets['experience'] ) ? $buckets['experience'] : array();
		$month     = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
		$date_re   = '/((?:' . $month . '\s+)?\d{4}|\d{1,2}\/\d{4})\s*(?:-|–|—|to)\s*((?:' . $month . '\s+)?\d{4}|\d{1,2}\/\d{4}|present|current|now|today)/i';
		$count     = count( $exp_lines );
		for ( $i = 0; $i < $count; $i++ ) {
			$line = $exp_lines[ $i ];
			if ( ! preg_match( $date_re, $line, $dm ) ) {
				continue;
			}
			$head = trim( str_replace( $dm[0], '', $line ), " \t,|-–—()" );
			if ( '' === $head && $i > 0 ) {
				$head = $exp_lines[ $i - 1 ];
			}
			$title   = $head;
			$company = '';
			if ( preg_match( '/^(.+?)\s+(?:at|@)\s+(.+)$/i', $head, $hm ) || preg_match( '/^(.+?)\s*[,|–—-]\s*(.+)$/u', $head, $hm ) ) {
				$title   = trim( $hm[1] );
				$company = trim( $hm[2], " \t,|-–—" );
			}
			$summary = array();
			for ( $j = $i + 1; $j < $count && $j <= $i + 4; $j++ ) {
				if ( preg_match( $date_re, $exp_lines[ $j ] ) ) {
					break;
				}
				// A line followed by a date-only line is the header of the next job.
				if ( $j + 1 < $count && preg_match( $date_re, $exp_lines[ $j + 1 ], $next ) && '' === trim( str_replace( $next[0], '', $exp_lines[ $j + 1 ] ), " \t,|-–—()" ) ) {
					break;
				}
				$summary[] = ltrim( $exp_lines[ $j ], "-*•· \t" );
			}
			$current             = (bool) preg_match( '/present|current|now|today/i', $dm[2] );
			$out['experience'][] = array(
				'title'   => mb_substr( $title, 0, 120 ),
				'company' => mb_substr( $company, 0, 120 ),
				'start'   => self::to_month( $dm[1] ),
				'end'     => $current ? '' : self::to_month( $dm[2] ),
				'current' => $current,
				'summary' => mb_substr( implode( ' ', $summary ), 0, 600 ),
			);
		}
		foreach ( isset( $buckets['education'] ) ? $buckets['education'] : array() as $line ) {
			if ( ! preg_match( '/\b(bachelor|master|b\.?sc|m\.?sc|b\.?a\.?|m\.?a\.?|mba|ph\.?d|doctor|diploma|degree|certificate|certified|associate|a-levels?|high school)\b/i', $line ) ) {
				continue;
			}
			$year   = preg_match( '/\b(19|20)\d{2}\b/', $line, $ym ) ? $ym[0] : '';
			$clean  = trim( preg_replace( '/\(?\b(19|20)\d{2}\b\)?/', '', $line ), " \t,|-–—" );
			$parts  = preg_split( '/\s*(?:,|\||–|—| - | at )\s*/u', $clean, 2 );
			$degree = isset( $parts[0] ) ? $parts[0] : $clean;
			$school = isset( $parts[1] ) ? $parts[1] : '';
			$field  = '';
			if ( preg_match( '/^(.+?)\s+(?:in|of)\s+(.+)$/i', $degree, $fm ) ) {
				$degree = $fm[1];
				$field  = $fm[2];
			}
			$out['education'][] = array(
				'degree' => $degree,
				'school' => $school,
				'field'  => $field,
				'year'   => $year,
			);
		}
		if ( preg_match_all( '#\b(?:https?://|www\.)[^\s,;|<>()]+|\b(?:linkedin\.com|github\.com|behance\.net|dribbble\.com)/[^\s,;|<>()]+#i', $text, $lm ) ) {
			foreach ( array_slice( array_unique( $lm[0] ), 0, 5 ) as $url ) {
				$url            = preg_match( '#^https?://#i', $url ) ? $url : 'https://' . $url;
				$out['links'][] = array(
					'label' => (string) wp_parse_url( $url, PHP_URL_HOST ),
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * "Mar 2021" / "03/2021" / "2021" → YYYY-MM.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function to_month( $value ) {
		$value  = strtolower( trim( $value ) );
		$months = array(
			'jan' => 1,
			'feb' => 2,
			'mar' => 3,
			'apr' => 4,
			'may' => 5,
			'jun' => 6,
			'jul' => 7,
			'aug' => 8,
			'sep' => 9,
			'oct' => 10,
			'nov' => 11,
			'dec' => 12,
		);
		if ( preg_match( '/^(\d{1,2})\/(\d{4})$/', $value, $m ) ) {
			return $m[2] . '-' . str_pad( (string) min( 12, max( 1, (int) $m[1] ) ), 2, '0', STR_PAD_LEFT );
		}
		if ( preg_match( '/^([a-z]{3})[a-z]*\.?\s+(\d{4})$/', $value, $m ) && isset( $months[ $m[1] ] ) ) {
			return $m[2] . '-' . str_pad( (string) $months[ $m[1] ], 2, '0', STR_PAD_LEFT );
		}
		if ( preg_match( '/(\d{4})/', $value, $m ) ) {
			return $m[1] . '-01';
		}
		return '';
	}
}
