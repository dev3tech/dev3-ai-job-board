<?php
/**
 * Job description writer: DEV3 AI drafts a structured job ad from the employer's notes.
 * Without AI it builds a clean outline from the same notes. Either way the result is a draft.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Ai;

use Dev3\AiJobBoard\Core3\Api\Ai;
use Dev3\AiJobBoard\Core3\Api\Prompts;
use Dev3\AiJobBoard\Core3\Core\Settings_Store;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Describer {

	/**
	 * Whether AI writing can run for a context.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function ai_available( $context = 'admin' ) {
		return (bool) Settings::get( 'ai_writer', true ) && Ai::enabled( $context ) && '' !== Settings_Store::api_key();
	}

	/**
	 * Cleans the writer input.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function clean_input( array $in ) {
		$text    = static function ( $key, $len ) use ( $in ) {
			return isset( $in[ $key ] ) ? mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, $len ) : '';
		};
		$company = isset( $in['company_id'] ) ? Companies::get( (int) $in['company_id'] ) : null;
		$skills  = isset( $in['skills'] ) ? ( is_array( $in['skills'] ) ? $in['skills'] : explode( ',', (string) $in['skills'] ) ) : array();
		$types   = array();
		foreach ( isset( $in['types'] ) ? (array) $in['types'] : array() as $t ) {
			$term = get_term( (int) $t );
			if ( $term && ! is_wp_error( $term ) ) {
				$types[] = $term->name;
			}
		}
		$tones = array( 'professional', 'friendly', 'startup', 'formal' );
		$tone  = isset( $in['tone'] ) && in_array( $in['tone'], $tones, true ) ? $in['tone'] : (string) Settings::get( 'writer_tone', 'professional' );
		return array(
			'title'         => $text( 'title', 160 ),
			'company'       => $company ? $company['name'] : $text( 'company', 120 ),
			'company_about' => $company ? mb_substr( wp_strip_all_tags( $company['description'] ), 0, 600 ) : '',
			'notes'         => isset( $in['notes'] ) ? mb_substr( sanitize_textarea_field( (string) $in['notes'] ), 0, 4000 ) : '',
			'workplace'     => isset( $in['workplace'] ) && in_array( $in['workplace'], Listings::WORKPLACES, true ) ? $in['workplace'] : 'onsite',
			'location'      => $text( 'location', 120 ),
			'experience'    => isset( $in['experience'] ) && in_array( $in['experience'], Listings::EXPERIENCE, true ) ? $in['experience'] : '',
			'types'         => implode( ', ', $types ),
			'salary'        => $text( 'salary', 120 ),
			'skills'        => implode( ', ', array_slice( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $skills ) ) ), 0, 20 ) ),
			'tone'          => $tone,
		);
	}

	/**
	 * Draft a job ad.
	 *
	 * @param array  $in      Writer input (title, notes, company_id, workplace, location, experience, types, skills, salary, tone).
	 * @param string $context admin|frontend.
	 * @param bool   $force_template Skip AI.
	 * @return array|\WP_Error { title, excerpt, content, skills, experience, sections, source, meta }
	 */
	public static function draft( array $in, $context = 'admin', $force_template = false ) {
		$input = self::clean_input( $in );
		if ( '' === $input['title'] && '' === $input['notes'] ) {
			return new \WP_Error( 'dev3jb_writer_empty', __( 'Add a job title or a few notes about the role first.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( $force_template || ! self::ai_available( $context ) ) {
			return self::template( $input );
		}
		$vars = $input;
		foreach ( $vars as $k => $v ) {
			if ( '' === $v ) {
				$vars[ $k ] = '(not given)';
			}
		}
		$vars['language'] = Prompts_Library::language();
		$res              = Prompts::run( Prompts_Library::DESCRIBE, $vars, $context, array( 'schema' => Prompts_Library::describe_schema() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data          = is_array( $res['data'] ) ? $res['data'] : array();
		$out           = self::from_sections( $data, $input );
		$out['source'] = 'ai';
		$out['meta']   = array(
			'model'  => $res['model'],
			'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
			'cached' => ! empty( $res['cached'] ),
		);
		return $out;
	}

	/**
	 * Draft from AI sections.
	 *
	 * @param array $data  Sections.
	 * @param array $input Input.
	 * @return array
	 */
	public static function from_sections( array $data, array $input ) {
		$list     = static function ( $key, $max ) use ( $data ) {
			$items = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
			return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $items ) ) ) ), 0, $max );
		};
		$sections = array(
			'summary'          => sanitize_textarea_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ),
			'responsibilities' => $list( 'responsibilities', 10 ),
			'requirements'     => $list( 'requirements', 10 ),
			'nice_to_have'     => $list( 'nice_to_have', 6 ),
			'benefits'         => $list( 'benefits', 8 ),
		);
		$title    = isset( $data['title'] ) && '' !== trim( (string) $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $input['title'];
		$exp      = isset( $data['experience'] ) && in_array( $data['experience'], Listings::EXPERIENCE, true ) ? $data['experience'] : $input['experience'];
		$excerpt  = isset( $data['excerpt'] ) ? mb_substr( sanitize_text_field( (string) $data['excerpt'] ), 0, 200 ) : '';
		return array(
			'title'      => mb_substr( $title, 0, 160 ),
			'excerpt'    => '' === $excerpt ? mb_substr( $sections['summary'], 0, 200 ) : $excerpt,
			'content'    => self::to_html( $sections ),
			'skills'     => $list( 'skills', 15 ),
			'experience' => $exp,
			'sections'   => $sections,
		);
	}

	/**
	 * Non-AI outline from the notes: sentences become the summary, bullet lines become lists.
	 *
	 * @param array $input Clean input.
	 * @return array
	 */
	public static function template( array $input ) {
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $input['notes'] );
		$bullets = array();
		$prose   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^([-*•·]|\d+[.)])\s*(.+)$/u', $line, $m ) ) {
				$bullets[] = trim( $m[2] );
			} else {
				$prose[] = $line;
			}
		}
		$req  = array();
		$resp = array();
		$ben  = array();
		foreach ( $bullets as $b ) {
			if ( preg_match( '/\b(years?|experience|degree|knowledge|skills?|proficien|familiar|fluent|must|required)\b/i', $b ) ) {
				$req[] = $b;
			} elseif ( preg_match( '/\b(salary|bonus|holiday|vacation|pto|insurance|health|pension|equity|remote|flexible|budget|leave)\b/i', $b ) ) {
				$ben[] = $b;
			} else {
				$resp[] = $b;
			}
		}
		$company = '' !== $input['company'] ? $input['company'] : get_bloginfo( 'name' );
		$summary = $prose ? implode( ' ', $prose ) : sprintf(
			/* translators: 1: company, 2: job title */
			__( '%1$s is hiring a %2$s. In this role you will work with a friendly team on meaningful work.', 'dev3-ai-job-board' ),
			$company,
			'' !== $input['title'] ? $input['title'] : __( 'new team member', 'dev3-ai-job-board' )
		);
		if ( ! $resp ) {
			$resp = array( __( 'Own your area of work from planning to delivery.', 'dev3-ai-job-board' ), __( 'Work closely with your team and share what you learn.', 'dev3-ai-job-board' ) );
		}
		$skills = array_filter( array_map( 'trim', explode( ',', (string) $input['skills'] ) ) );
		if ( ! $req && $skills ) {
			/* translators: %s: skills list */
			$req[] = sprintf( __( 'Hands-on experience with %s.', 'dev3-ai-job-board' ), implode( ', ', array_slice( $skills, 0, 5 ) ) );
		}
		$sections = array(
			'summary'          => $summary,
			'responsibilities' => array_slice( $resp, 0, 10 ),
			'requirements'     => array_slice( $req, 0, 10 ),
			'nice_to_have'     => array(),
			'benefits'         => array_slice( $ben, 0, 8 ),
		);
		return array(
			'title'      => '' !== $input['title'] ? $input['title'] : __( 'New role', 'dev3-ai-job-board' ),
			'excerpt'    => mb_substr( $summary, 0, 200 ),
			'content'    => self::to_html( $sections ),
			'skills'     => array_values( array_slice( $skills, 0, 15 ) ),
			'experience' => $input['experience'],
			'sections'   => $sections,
			'source'     => 'template',
			'meta'       => array(),
		);
	}

	/**
	 * Sections → job HTML.
	 *
	 * @param array $s Sections.
	 * @return string
	 */
	public static function to_html( array $s ) {
		$html = '';
		if ( '' !== $s['summary'] ) {
			foreach ( preg_split( '/\n\s*\n/', $s['summary'] ) as $para ) {
				$html .= '<p>' . esc_html( trim( $para ) ) . "</p>\n";
			}
		}
		$heads = array(
			'responsibilities' => __( 'What you will do', 'dev3-ai-job-board' ),
			'requirements'     => __( 'What you bring', 'dev3-ai-job-board' ),
			'nice_to_have'     => __( 'Nice to have', 'dev3-ai-job-board' ),
			'benefits'         => __( 'What we offer', 'dev3-ai-job-board' ),
		);
		foreach ( $heads as $key => $label ) {
			if ( empty( $s[ $key ] ) ) {
				continue;
			}
			$html .= '<h3>' . esc_html( $label ) . "</h3>\n<ul>\n";
			foreach ( $s[ $key ] as $item ) {
				$html .= '<li>' . esc_html( $item ) . "</li>\n";
			}
			$html .= "</ul>\n";
		}
		return $html;
	}
}
