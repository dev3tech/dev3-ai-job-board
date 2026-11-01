<?php
/**
 * Match scores with explanations (decision support for employers, never an automatic decision).
 *
 * 1. A transparent rule-based score from four criteria: skills (40%), experience (25%), role (20%) and
 *    location/workplace fit (15%). It runs without AI and is fully reproducible.
 * 2. With DEV3 AI on, the model reviews the anonymised profile against the job and writes strengths,
 *    gaps and a summary; the final score is the average of both, so the rules keep it grounded.
 *
 * Fairness: only job-relevant data is used. Names, contact details and every protected attribute are
 * removed before matching (strip_protected + anonymise), and nothing here changes a pipeline stage.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Ai;

use Dev3\AiJobBoard\Core3\Api\Ai;
use Dev3\AiJobBoard\Core3\Api\Prompts;
use Dev3\AiJobBoard\Core3\Core\Jobs;
use Dev3\AiJobBoard\Core3\Core\Privacy;
use Dev3\AiJobBoard\Core3\Core\Settings_Store;
use Dev3\AiJobBoard\Core3\Data\Embeddings_Store;
use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Files;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Matcher {

	const JOB       = 'dev3jb_score';
	const INDEX_JOB = 'dev3jb_index';

	const WEIGHTS = array(
		'skills'     => 40,
		'experience' => 25,
		'role'       => 20,
		'location'   => 15,
	);

	/**
	 * Keys that are never sent to AI or used for scoring (normalised: lowercase, letters only).
	 *
	 * @var string[]
	 */
	const PROTECTED_KEYS = array( 'name', 'firstname', 'lastname', 'fullname', 'email', 'phone', 'mobile', 'photo', 'picture', 'avatar', 'image', 'age', 'dob', 'dateofbirth', 'birthdate', 'birthday', 'birthplace', 'gender', 'sex', 'pronouns', 'title', 'maritalstatus', 'married', 'spouse', 'children', 'dependents', 'family', 'pregnancy', 'nationality', 'citizenship', 'ethnicity', 'race', 'religion', 'disability', 'health', 'medical', 'sexualorientation', 'orientation', 'union', 'political', 'politics', 'address', 'street', 'postcode', 'zip', 'socialsecurity', 'ssn', 'passport', 'visa' );

	/**
	 * Keys that are allowed inside nested entries even though they collide with protected words.
	 *
	 * @var string[]
	 */
	const NESTED_ALLOWED = array( 'title' );

	/**
	 * Experience years expected per level.
	 *
	 * @var array
	 */
	const LEVEL_YEARS = array(
		''          => 0,
		'entry'     => 0,
		'mid'       => 2,
		'senior'    => 5,
		'lead'      => 8,
		'executive' => 10,
	);

	/**
	 * Protected attribute keys (filterable for audits).
	 *
	 * @return string[]
	 */
	public static function protected_keys() {
		/**
		 * Filters the keys removed before matching and from parsed resumes.
		 *
		 * @param string[] $keys Normalised keys (lowercase letters only).
		 */
		return (array) apply_filters( 'dev3jb_protected_attributes', self::PROTECTED_KEYS );
	}

	/**
	 * Removes protected keys recursively. Top-level "title" is dropped (honorifics); job titles in
	 * nested experience entries are kept.
	 *
	 * @param array $data  Data.
	 * @param int   $depth Depth.
	 * @return array
	 */
	public static function strip_protected( array $data, $depth = 0 ) {
		$keys = self::protected_keys();
		$out  = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$norm = preg_replace( '/[^a-z]/', '', strtolower( $key ) );
				if ( in_array( $norm, $keys, true ) && ! ( $depth > 0 && in_array( $norm, self::NESTED_ALLOWED, true ) ) ) {
					continue;
				}
			}
			$out[ $key ] = is_array( $value ) ? self::strip_protected( $value, $depth + 1 ) : $value;
		}
		return $out;
	}

	/**
	 * Anonymised profile used for matching (no name, contact data, links or file).
	 *
	 * @param array  $profile Profile.
	 * @param string $name    Candidate name to scrub from free text.
	 * @return array
	 */
	public static function anonymise( array $profile, $name = '' ) {
		$p     = array_merge( Resumes::blank(), $profile );
		$names = array_filter( array_unique( array_merge( array( $name, $p['name'] ), preg_split( '/\s+/', trim( $name . ' ' . $p['name'] ) ) ) ), static fn( $n ) => mb_strlen( (string) $n ) >= 3 );
		$scrub = static function ( $text ) use ( $names ) {
			$text = Privacy::mask( (string) $text );
			foreach ( $names as $n ) {
				$text = str_ireplace( $n, '[candidate]', $text );
			}
			return $text;
		};
		$out   = array(
			'headline'   => $scrub( $p['headline'] ),
			'summary'    => $scrub( $p['summary'] ),
			'location'   => mb_substr( (string) $p['location'], 0, 120 ),
			'workplace'  => (array) $p['workplace'],
			'job_types'  => (array) $p['job_types'],
			'skills'     => array_values( (array) $p['skills'] ),
			'experience' => array(),
			'education'  => array(),
			'years'      => (float) ( isset( $p['years'] ) ? $p['years'] : Resumes::years( (array) $p['experience'] ) ),
		);
		foreach ( (array) $p['experience'] as $e ) {
			$out['experience'][] = array(
				'title'   => (string) ( isset( $e['title'] ) ? $e['title'] : '' ),
				'company' => (string) ( isset( $e['company'] ) ? $e['company'] : '' ),
				'start'   => (string) ( isset( $e['start'] ) ? $e['start'] : '' ),
				'end'     => (string) ( isset( $e['end'] ) ? $e['end'] : '' ),
				'current' => ! empty( $e['current'] ),
				'summary' => $scrub( isset( $e['summary'] ) ? $e['summary'] : '' ),
			);
		}
		foreach ( (array) $p['education'] as $e ) {
			$out['education'][] = array(
				'degree' => (string) ( isset( $e['degree'] ) ? $e['degree'] : '' ),
				'school' => (string) ( isset( $e['school'] ) ? $e['school'] : '' ),
				'field'  => (string) ( isset( $e['field'] ) ? $e['field'] : '' ),
				'year'   => (string) ( isset( $e['year'] ) ? $e['year'] : '' ),
			);
		}
		/**
		 * Filters the anonymised candidate payload used for matching (audit hook).
		 *
		 * @param array $out     Payload.
		 * @param array $profile Original profile.
		 */
		return self::strip_protected( (array) apply_filters( 'dev3jb_match_payload', $out, $profile ) );
	}

	/**
	 * Skill keys of a job (terms, or vocabulary hits in the text when it has no skill terms).
	 *
	 * @param array $job Job.
	 * @return array norm => label
	 */
	public static function job_skills( array $job ) {
		$out = array();
		foreach ( $job['skills'] as $s ) {
			$out[ Resume_Parser::norm( $s['name'] ) ] = $s['name'];
		}
		if ( ! $out ) {
			$text = wp_strip_all_tags( $job['title'] . ' ' . $job['content'] );
			foreach ( Resume_Parser::vocabulary() as $skill ) {
				if ( preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $skill, '/' ) . '(?![A-Za-z0-9])/i', $text ) ) {
					$out[ Resume_Parser::norm( $skill ) ] = $skill;
				}
			}
		}
		unset( $out[''] );
		return $out;
	}

	/**
	 * Years the job asks for.
	 *
	 * @param array $job Job.
	 * @return int
	 */
	public static function required_years( array $job ) {
		$years = isset( self::LEVEL_YEARS[ $job['experience'] ] ) ? self::LEVEL_YEARS[ $job['experience'] ] : 0;
		if ( preg_match_all( '/(\d{1,2})\s*\+?\s*(?:years?|yrs?)/i', wp_strip_all_tags( $job['content'] ), $m ) ) {
			$years = max( $years, min( 15, max( array_map( 'intval', $m[1] ) ) ) );
		}
		return (int) $years;
	}

	/**
	 * Words of a title for overlap.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		$stop  = array( 'and', 'the', 'for', 'with', 'senior', 'junior', 'lead', 'mid', 'level', 'remote', 'hybrid', 'of', 'in', 'at', 'a', 'an', 'to', 'm', 'f', 'd', 'x' );
		$words = preg_split( '/[^a-z0-9#+]+/', mb_strtolower( (string) $text ) );
		return array_values( array_diff( array_filter( (array) $words, static fn( $w ) => strlen( $w ) > 1 ), $stop ) );
	}

	/**
	 * Rule-based score.
	 *
	 * @param array $job     Job.
	 * @param array $profile Anonymised profile.
	 * @return array { score, criteria, strengths, gaps, summary, matched_skills, missing_skills, required_years, source }
	 */
	public static function rule_score( array $job, array $profile ) {
		$need = self::job_skills( $job );
		$have = array();
		foreach ( $profile['skills'] as $s ) {
			$have[ Resume_Parser::norm( $s ) ] = $s;
		}
		// Skills named in the experience text count too.
		$exp_text = mb_strtolower( implode( ' ', wp_list_pluck( $profile['experience'], 'summary' ) ) . ' ' . $profile['summary'] );
		$matched  = array();
		$missing  = array();
		foreach ( $need as $key => $label ) {
			if ( isset( $have[ $key ] ) || ( mb_strlen( $label ) > 2 && false !== mb_strpos( $exp_text, mb_strtolower( $label ) ) ) ) {
				$matched[] = $label;
			} else {
				$missing[] = $label;
			}
		}
		$skills = $need ? (int) round( 100 * count( $matched ) / count( $need ) ) : 50;

		$req        = self::required_years( $job );
		$years      = (float) $profile['years'];
		$experience = $req <= 0 ? ( $years > 0 ? 100 : 70 ) : (int) round( 100 * min( 1, $years / $req ) );

		$jw     = self::words( $job['title'] );
		$titles = array_merge( array( $profile['headline'] ), wp_list_pluck( $profile['experience'], 'title' ) );
		$cw     = self::words( implode( ' ', $titles ) );
		$role   = $jw ? (int) round( 100 * count( array_intersect( $jw, $cw ) ) / count( $jw ) ) : 50;
		$cos    = self::embedding_similarity( $job['id'], isset( $profile['resume_id'] ) ? (int) $profile['resume_id'] : 0 );
		if ( null !== $cos ) {
			// Cosine of related texts sits around 0.55-0.9 for these models: map that range to 0-100.
			$role = (int) round( ( $role + 100 * min( 1, max( 0, ( $cos - 0.55 ) / 0.35 ) ) ) / 2 );
		}

		$location = self::location_fit( $job, $profile );
		$criteria = array(
			'skills'     => min( 100, max( 0, $skills ) ),
			'experience' => min( 100, max( 0, $experience ) ),
			'role'       => min( 100, max( 0, $role ) ),
			'location'   => $location,
		);
		$score    = 0;
		foreach ( self::WEIGHTS as $k => $w ) {
			$score += $criteria[ $k ] * $w;
		}
		$score = (int) round( $score / 100 );

		$strengths = array();
		$gaps      = array();
		if ( $matched ) {
			/* translators: %s: skill list */
			$strengths[] = sprintf( __( 'Has %s, which the job asks for.', 'dev3-ai-job-board' ), implode( ', ', array_slice( $matched, 0, 6 ) ) );
		}
		if ( $missing ) {
			/* translators: %s: skill list */
			$gaps[] = sprintf( __( 'Profile does not show: %s.', 'dev3-ai-job-board' ), implode( ', ', array_slice( $missing, 0, 6 ) ) );
		}
		if ( $req > 0 ) {
			if ( $years >= $req ) {
				/* translators: 1: candidate years, 2: required years */
				$strengths[] = sprintf( __( '%1$s years of experience (the job asks for about %2$d).', 'dev3-ai-job-board' ), number_format_i18n( $years, 1 ), $req );
			} else {
				/* translators: 1: candidate years, 2: required years */
				$gaps[] = sprintf( __( '%1$s years of experience; the job asks for about %2$d.', 'dev3-ai-job-board' ), number_format_i18n( $years, 1 ), $req );
			}
		}
		if ( $role >= 60 ) {
			$strengths[] = __( 'Has held a similar role before.', 'dev3-ai-job-board' );
		} elseif ( $role < 30 && $jw ) {
			$gaps[] = __( 'Previous job titles differ from this role; check transferable experience.', 'dev3-ai-job-board' );
		}
		if ( $location >= 90 ) {
			$strengths[] = 'remote' === $job['workplace'] ? __( 'Remote role: location is not a barrier.', 'dev3-ai-job-board' ) : __( 'Location and workplace preference fit.', 'dev3-ai-job-board' );
		} elseif ( $location < 50 ) {
			$gaps[] = __( 'Location or workplace preference may not fit; ask the candidate.', 'dev3-ai-job-board' );
		}
		$summary = $score >= 75 ? __( 'Strong fit on the stated requirements.', 'dev3-ai-job-board' ) : ( $score >= 50 ? __( 'Partial fit: review the gaps.', 'dev3-ai-job-board' ) : __( 'Few stated requirements are visible in the profile.', 'dev3-ai-job-board' ) );
		return array(
			'score'          => $score,
			'criteria'       => $criteria,
			'strengths'      => $strengths,
			'gaps'           => $gaps,
			'summary'        => $summary,
			'matched_skills' => array_values( $matched ),
			'missing_skills' => array_values( $missing ),
			'required_years' => $req,
			'source'         => 'rules',
		);
	}

	/**
	 * Location/workplace fit 0-100.
	 *
	 * @param array $job     Job.
	 * @param array $profile Profile.
	 * @return int
	 */
	public static function location_fit( array $job, array $profile ) {
		if ( 'remote' === $job['workplace'] ) {
			return 100;
		}
		$prefs = (array) $profile['workplace'];
		$loc   = mb_strtolower( (string) $profile['location'] );
		$hit   = false;
		foreach ( $job['locations'] as $l ) {
			if ( '' !== $loc && false !== mb_strpos( $loc, mb_strtolower( $l['name'] ) ) ) {
				$hit = true;
			}
		}
		if ( $prefs && ! in_array( $job['workplace'], $prefs, true ) ) {
			return $hit ? 60 : 30;
		}
		if ( $hit ) {
			return 100;
		}
		return '' === $loc || ! $job['locations'] ? 70 : 40;
	}

	/**
	 * Cosine similarity of stored job/resume vectors (no API call), or null.
	 *
	 * @param int $job_id    Job.
	 * @param int $resume_id Resume.
	 * @return float|null
	 */
	public static function embedding_similarity( $job_id, $resume_id ) {
		if ( ! $job_id || ! $resume_id ) {
			return null;
		}
		$store = Embeddings_Store::instance();
		$a     = $store->get( 'job', $job_id );
		$b     = $store->get( 'resume', $resume_id );
		if ( ! $a || ! $b || empty( $a['vector'] ) || empty( $b['vector'] ) || count( $a['vector'] ) !== count( $b['vector'] ) ) {
			return null;
		}
		return Embeddings_Store::cosine( $a['vector'], $b['vector'] );
	}

	/**
	 * Whether AI explanations can run.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function ai_available( $context = 'admin' ) {
		return (bool) Settings::get( 'ai_match', true ) && Ai::enabled( $context ) && '' !== Settings_Store::api_key();
	}

	/**
	 * Profile of an application (snapshot/live profile, or the attached file read without AI).
	 *
	 * @param array $app Application.
	 * @return array
	 */
	public static function application_profile( array $app ) {
		if ( $app['profile'] ) {
			$p = $app['profile'];
		} else {
			$p = Resumes::blank();
			if ( $app['file_id'] ) {
				$file = Files::get( $app['file_id'] );
				$path = $file ? Files::path( $file ) : '';
				if ( $path ) {
					$text = Text_Extractor::extract( $path, $file['mime'] );
					if ( '' !== $text ) {
						$p = array_merge( $p, Resume_Parser::clean_profile( Resume_Parser::heuristic( $text ) ) );
					}
				}
			}
			if ( '' === $p['summary'] && '' !== (string) $app['cover_letter'] ) {
				$p['summary'] = mb_substr( (string) $app['cover_letter'], 0, 1500 );
			}
		}
		$p['years']        = Resumes::years( (array) $p['experience'] );
		$anon              = self::anonymise( $p, (string) $app['name'] );
		$anon['resume_id'] = (int) $app['resume_id'];
		return $anon;
	}

	/**
	 * Scores one application and stores the result.
	 *
	 * @param int    $app_id  Application.
	 * @param bool   $use_ai  Use DEV3 AI for the explanation.
	 * @param string $context Context.
	 * @return array|\WP_Error Match result.
	 */
	public static function score_application( $app_id, $use_ai = true, $context = 'admin' ) {
		$app = Applications::get( $app_id );
		if ( ! $app ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Application not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$job = Listings::get( $app['job_id'] );
		if ( ! $job ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Job not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		$result = self::match( $job, self::application_profile( $app ), $use_ai, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Applications::set_match( $app_id, $result['score'], $result );
		/* translators: %d: score */
		Applications::add_event( $app_id, get_current_user_id(), 'match', sprintf( __( 'Match score %d/100 calculated', 'dev3-ai-job-board' ), $result['score'] ) . ( 'ai' === $result['source'] ? ' (DEV3 AI)' : '' ) );
		return $result;
	}

	/**
	 * Match a job and an anonymised profile.
	 *
	 * @param array  $job     Job.
	 * @param array  $profile Anonymised profile.
	 * @param bool   $use_ai  Use AI.
	 * @param string $context Context.
	 * @return array|\WP_Error
	 */
	public static function match( array $job, array $profile, $use_ai = true, $context = 'admin' ) {
		$rules = self::rule_score( $job, $profile );
		if ( ! $use_ai || ! self::ai_available( $context ) ) {
			return $rules;
		}
		$candidate = $profile;
		unset( $candidate['resume_id'] );
		$res = Prompts::run(
			Prompts_Library::MATCH,
			array(
				'job'       => Listings::to_text( $job ),
				'candidate' => Resumes::to_text( array_merge( Resumes::blank(), $candidate ) ),
				'precheck'  => wp_json_encode(
					array(
						'criteria'       => $rules['criteria'],
						'matched_skills' => $rules['matched_skills'],
						'missing_skills' => $rules['missing_skills'],
						'required_years' => $rules['required_years'],
					)
				),
				'language'  => Prompts_Library::language(),
			),
			$context
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ai       = is_array( $res['data'] ) ? $res['data'] : array();
		$clamp    = static fn( $v ) => min( 100, max( 0, (int) $v ) );
		$criteria = array();
		foreach ( array_keys( self::WEIGHTS ) as $k ) {
			$criteria[ $k ] = isset( $ai['criteria'][ $k ] ) ? (int) round( ( $rules['criteria'][ $k ] + $clamp( $ai['criteria'][ $k ] ) ) / 2 ) : $rules['criteria'][ $k ];
		}
		$list     = static function ( $key ) use ( $ai ) {
			return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', isset( $ai[ $key ] ) && is_array( $ai[ $key ] ) ? array_map( 'strval', $ai[ $key ] ) : array() ) ) ), 0, 4 );
		};
		$ai_score = isset( $ai['score'] ) ? $clamp( $ai['score'] ) : $rules['score'];
		return array_merge(
			$rules,
			array(
				'score'      => (int) round( ( $rules['score'] + $ai_score ) / 2 ),
				'rule_score' => $rules['score'],
				'ai_score'   => $ai_score,
				'criteria'   => $criteria,
				'strengths'  => $list( 'strengths' ) ? $list( 'strengths' ) : $rules['strengths'],
				'gaps'       => $list( 'gaps' ) ? $list( 'gaps' ) : $rules['gaps'],
				'summary'    => isset( $ai['summary'] ) && '' !== trim( (string) $ai['summary'] ) ? sanitize_text_field( (string) $ai['summary'] ) : $rules['summary'],
				'source'     => 'ai',
				'model'      => $res['model'],
				'cached'     => ! empty( $res['cached'] ),
			)
		);
	}

	/**
	 * Queues scoring for every application of a job.
	 *
	 * @param int  $job_id Job.
	 * @param bool $use_ai Use AI.
	 * @param bool $only_unscored Skip scored ones.
	 * @return int|\WP_Error Job queue id.
	 */
	public static function start_bulk( $job_id, $use_ai = true, $only_unscored = false ) {
		$list = Applications::query(
			array(
				'job_id'   => $job_id,
				'per_page' => 500,
			)
		);
		$ids  = array();
		foreach ( $list['items'] as $app ) {
			if ( ! $only_unscored || null === $app['match_score'] ) {
				$ids[] = (int) $app['id'];
			}
		}
		if ( ! $ids ) {
			return new \WP_Error( 'dev3jb_nothing', __( 'There are no applications to score.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return Jobs::create( self::JOB, $ids, array( 'use_ai' => (bool) $use_ai ), 5 );
	}

	/**
	 * Job queue handler.
	 *
	 * @param array $items Application ids.
	 * @param array $job   Queue job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$use_ai = ! empty( $job['args']['use_ai'] );
		$out    = array();
		foreach ( $items as $id ) {
			$res        = self::score_application( (int) $id, $use_ai, 'cron' );
			$out[ $id ] = is_wp_error( $res ) ? $res : true;
		}
		return $out;
	}

	/**
	 * Queues embedding of jobs and resumes that changed (for recommendations and the role criterion).
	 *
	 * @return int|\WP_Error Queue id.
	 */
	public static function start_index() {
		if ( ! Ai::enabled( 'admin' ) || '' === Settings_Store::api_key() ) {
			return new \WP_Error( 'dev3jb_ai_required', __( 'Indexing needs DEV3 AI. Add a Google Gemini API key in API Config.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$store = Embeddings_Store::instance();
		$items = array();
		foreach ( array( 'job', 'resume' ) as $type ) {
			$hashes = array();
			$ids    = get_posts(
				array(
					'post_type'      => 'job' === $type ? Post_Types::JOB : Post_Types::RESUME,
					'post_status'    => 'job' === $type ? 'publish' : array( 'private', 'publish' ),
					'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'fields'         => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$hashes[ $id ] = Embeddings_Store::hash( self::embed_text( $type, $id ) );
			}
			foreach ( $store->stale( $type, $hashes ) as $id ) {
				$items[] = $type . ':' . $id;
			}
		}
		if ( ! $items ) {
			return new \WP_Error( 'dev3jb_nothing', __( 'Everything is already indexed.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		return Jobs::create( self::INDEX_JOB, $items, array(), 20 );
	}

	/**
	 * Text that is embedded for a job or resume.
	 *
	 * @param string $type job|resume.
	 * @param int    $id   Post.
	 * @return string
	 */
	public static function embed_text( $type, $id ) {
		if ( 'job' === $type ) {
			$job = Listings::get( $id );
			return $job ? Listings::to_text( $job ) : '';
		}
		$p = Resumes::get( $id );
		return $p ? Resumes::to_text( array_merge( Resumes::blank(), self::anonymise( $p ) ) ) : '';
	}

	/**
	 * Index queue handler.
	 *
	 * @param array $items "job:ID" / "resume:ID".
	 * @param array $job   Queue job.
	 * @return array
	 */
	public static function index_chunk( array $items, array $job ) {
		unset( $job );
		$texts = array();
		$keys  = array();
		foreach ( $items as $item ) {
			list( $type, $id ) = array_pad( explode( ':', (string) $item ), 2, 0 );
			$text              = self::embed_text( $type, (int) $id );
			if ( '' !== $text ) {
				$texts[] = mb_substr( $text, 0, 6000 );
				$keys[]  = array( $type, (int) $id, Embeddings_Store::hash( $text ) );
			}
		}
		$out = array();
		if ( ! $texts ) {
			return $out;
		}
		$res = Ai::embed( 'match_index', $texts, 'SEMANTIC_SIMILARITY', 'cron' );
		if ( is_wp_error( $res ) ) {
			foreach ( $items as $item ) {
				$out[ $item ] = $res;
			}
			return $out;
		}
		foreach ( $keys as $i => $k ) {
			if ( isset( $res['vectors'][ $i ] ) ) {
				Embeddings_Store::instance()->upsert( $k[0], $k[1], $res['vectors'][ $i ], $k[2] );
				$out[ $k[0] . ':' . $k[1] ] = true;
			}
		}
		return $out;
	}

	/**
	 * Jobs recommended for a candidate: rule score over recent jobs, boosted by embeddings.
	 *
	 * @param int $user_id Candidate.
	 * @param int $limit   Max.
	 * @return array [ { job, score, reasons } ]
	 */
	public static function recommend( $user_id, $limit = 6 ) {
		$profile = Resumes::for_user( $user_id );
		if ( ! $profile['id'] ) {
			return array();
		}
		$anon              = self::anonymise( $profile );
		$anon['resume_id'] = (int) $profile['id'];
		$recent            = Listings::search(
			array(
				'per_page' => 100,
				'orderby'  => 'date',
			)
		);
		$applied           = wp_list_pluck(
			Applications::query(
				array(
					'candidate_id'      => $user_id,
					'per_page'          => 500,
					'include_withdrawn' => true,
				)
			)['items'],
			'job_id'
		);
		$use_vec           = (bool) Settings::get( 'ai_recommend', true );
		$rows              = array();
		foreach ( $recent['items'] as $job ) {
			if ( $job['filled'] || in_array( $job['id'], $applied, true ) ) {
				continue;
			}
			if ( ! $use_vec ) {
				$anon['resume_id'] = 0;
			}
			$r      = self::rule_score( $job, $anon );
			$rows[] = array(
				'job'     => $job,
				'score'   => $r['score'],
				'reasons' => $r['matched_skills'] ? array_slice( $r['matched_skills'], 0, 4 ) : array(),
			);
		}
		usort( $rows, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $rows, 0, max( 1, (int) $limit ) );
	}
}
