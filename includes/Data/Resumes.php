<?php
/**
 * Candidate profiles (private CPT dev3jb_resume, one per candidate).
 *
 * The profile deliberately has no fields for protected attributes (age, date of birth, gender, photo,
 * marital status, nationality, religion, disability…). See Ai\Matcher::PROTECTED_KEYS.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository.
 */
class Resumes {

	const MAX_ENTRIES = 15;

	/**
	 * Resume post id of a user (0 if none).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function id_for_user( $user_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::RESUME,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'author'         => (int) $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Empty profile.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'id'          => 0,
			'user_id'     => 0,
			'name'        => '',
			'email'       => '',
			'phone'       => '',
			'headline'    => '',
			'summary'     => '',
			'location'    => '',
			'workplace'   => array(),
			'job_types'   => array(),
			'skills'      => array(),
			'experience'  => array(),
			'education'   => array(),
			'links'       => array(),
			'visible'     => false,
			'file_id'     => 0,
			'file'        => null,
			'years'       => 0,
			'updated'     => '',
			'complete'    => 0,
			'parsed_from' => 0,
		);
	}

	/**
	 * Profile of a resume post.
	 *
	 * @param int $id Resume.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Post_Types::RESUME !== $post->post_type ) {
			return null;
		}
		$user    = get_userdata( (int) $post->post_author );
		$json    = static function ( $key ) use ( $id ) {
			$v = json_decode( (string) get_post_meta( $id, '_dev3jb_' . $key, true ), true );
			return is_array( $v ) ? $v : array();
		};
		$file_id = (int) get_post_meta( $id, '_dev3jb_file', true );
		$profile = array_merge(
			self::blank(),
			array(
				'id'          => (int) $post->ID,
				'user_id'     => (int) $post->post_author,
				'name'        => $user ? $user->display_name : '',
				'email'       => $user ? $user->user_email : '',
				'phone'       => (string) get_post_meta( $id, '_dev3jb_phone', true ),
				'headline'    => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'summary'     => (string) get_post_meta( $id, '_dev3jb_summary', true ),
				'location'    => (string) get_post_meta( $id, '_dev3jb_location', true ),
				'workplace'   => $json( 'workplace' ),
				'job_types'   => $json( 'job_types' ),
				'skills'      => wp_list_pluck( Listings::post_terms( $id, Post_Types::SKILL ), 'name' ),
				'experience'  => $json( 'experience' ),
				'education'   => $json( 'education' ),
				'links'       => $json( 'links' ),
				'visible'     => (bool) get_post_meta( $id, '_dev3jb_visible', true ),
				'file_id'     => $file_id,
				'file'        => $file_id ? Files::public_info( $file_id ) : null,
				'updated'     => get_post_modified_time( 'Y-m-d H:i:s', true, $post ),
				'parsed_from' => (int) get_post_meta( $id, '_dev3jb_parsed_from', true ),
			)
		);
		if ( 'Untitled profile' === $profile['headline'] ) {
			$profile['headline'] = '';
		}
		$profile['years']    = self::years( $profile['experience'] );
		$profile['complete'] = self::completeness( $profile );
		return $profile;
	}

	/**
	 * Profile for a user (blank with user data when none exists yet).
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		$id = self::id_for_user( $user_id );
		if ( $id ) {
			return self::get( $id );
		}
		$user  = get_userdata( $user_id );
		$blank = self::blank();
		if ( $user ) {
			$blank['user_id'] = (int) $user_id;
			$blank['name']    = $user->display_name;
			$blank['email']   = $user->user_email;
		}
		return $blank;
	}

	/**
	 * Sanitizes profile input. Unknown keys (including any protected attribute) are dropped.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$out  = array();
		$text = static function ( $v, $len ) {
			return mb_substr( sanitize_text_field( (string) $v ), 0, $len );
		};
		foreach ( array(
			'headline' => 140,
			'location' => 120,
			'phone'    => 40,
		) as $key => $len ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = $text( $in[ $key ], $len );
			}
		}
		if ( isset( $in['summary'] ) ) {
			$out['summary'] = mb_substr( sanitize_textarea_field( (string) $in['summary'] ), 0, 2000 );
		}
		if ( isset( $in['workplace'] ) ) {
			$out['workplace'] = array_values( array_intersect( Listings::WORKPLACES, (array) $in['workplace'] ) );
		}
		if ( isset( $in['job_types'] ) ) {
			$out['job_types'] = array_slice( array_values( array_filter( array_map( 'sanitize_title', (array) $in['job_types'] ) ) ), 0, 10 );
		}
		if ( isset( $in['skills'] ) ) {
			$skills = is_array( $in['skills'] ) ? $in['skills'] : explode( ',', (string) $in['skills'] );
			$clean  = array();
			foreach ( $skills as $s ) {
				$s = trim( $text( $s, 40 ) );
				if ( '' !== $s && ! isset( $clean[ mb_strtolower( $s ) ] ) ) {
					$clean[ mb_strtolower( $s ) ] = $s;
				}
			}
			$out['skills'] = array_slice( array_values( $clean ), 0, 40 );
		}
		if ( isset( $in['experience'] ) && is_array( $in['experience'] ) ) {
			$rows = array();
			foreach ( array_slice( $in['experience'], 0, self::MAX_ENTRIES ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$r = array(
					'title'   => $text( isset( $row['title'] ) ? $row['title'] : '', 120 ),
					'company' => $text( isset( $row['company'] ) ? $row['company'] : '', 120 ),
					'start'   => self::month( isset( $row['start'] ) ? $row['start'] : '' ),
					'end'     => self::month( isset( $row['end'] ) ? $row['end'] : '' ),
					'current' => ! empty( $row['current'] ) && 'false' !== $row['current'],
					'summary' => mb_substr( sanitize_textarea_field( isset( $row['summary'] ) ? (string) $row['summary'] : '' ), 0, 1200 ),
				);
				if ( '' !== $r['title'] || '' !== $r['company'] ) {
					$rows[] = $r;
				}
			}
			$out['experience'] = $rows;
		}
		if ( isset( $in['education'] ) && is_array( $in['education'] ) ) {
			$rows = array();
			foreach ( array_slice( $in['education'], 0, self::MAX_ENTRIES ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$r = array(
					'degree' => $text( isset( $row['degree'] ) ? $row['degree'] : '', 120 ),
					'school' => $text( isset( $row['school'] ) ? $row['school'] : '', 120 ),
					'field'  => $text( isset( $row['field'] ) ? $row['field'] : '', 120 ),
					'year'   => preg_match( '/^\d{4}$/', isset( $row['year'] ) ? (string) $row['year'] : '' ) ? (string) $row['year'] : '',
				);
				if ( '' !== $r['degree'] || '' !== $r['school'] ) {
					$rows[] = $r;
				}
			}
			$out['education'] = $rows;
		}
		if ( isset( $in['links'] ) && is_array( $in['links'] ) ) {
			$rows = array();
			foreach ( array_slice( $in['links'], 0, 6 ) as $row ) {
				$url = esc_url_raw( is_array( $row ) && isset( $row['url'] ) ? (string) $row['url'] : (string) $row, array( 'http', 'https' ) );
				if ( $url ) {
					$rows[] = array(
						'label' => $text( is_array( $row ) && isset( $row['label'] ) ? $row['label'] : wp_parse_url( $url, PHP_URL_HOST ), 60 ),
						'url'   => $url,
					);
				}
			}
			$out['links'] = $rows;
		}
		if ( isset( $in['visible'] ) ) {
			$out['visible'] = (bool) $in['visible'] && 'false' !== $in['visible'] && '0' !== $in['visible'];
		}
		if ( isset( $in['file_id'] ) ) {
			$out['file_id'] = (int) $in['file_id'];
		}
		return $out;
	}

	/**
	 * YYYY-MM or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function month( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-(\d{1,2})/', $value, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ) {
			return $m[1] . '-' . str_pad( $m[2], 2, '0', STR_PAD_LEFT );
		}
		if ( preg_match( '/^(\d{4})$/', $value, $m ) ) {
			return $m[1] . '-01';
		}
		return '';
	}

	/**
	 * Saves a candidate's profile (creates it on first save).
	 *
	 * @param int   $user_id User.
	 * @param array $data    Clean data.
	 * @return int|\WP_Error Resume id.
	 */
	public static function save( $user_id, array $data ) {
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'dev3jb_user', __( 'Log in to save your profile.', 'dev3-ai-job-board' ), array( 'status' => 401 ) );
		}
		$id   = self::id_for_user( $user_id );
		$post = array(
			'post_type'   => Post_Types::RESUME,
			'post_status' => 'private',
		);
		if ( isset( $data['headline'] ) ) {
			$post['post_title'] = '' === $data['headline'] ? 'Untitled profile' : $data['headline'];
		}
		if ( $id ) {
			$post['ID'] = $id;
			$res        = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_author'] = (int) $user_id;
			if ( ! isset( $post['post_title'] ) ) {
				$post['post_title'] = 'Untitled profile';
			}
			$res = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (int) $res;
		foreach ( array( 'summary', 'location', 'phone' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, '_dev3jb_' . $key, $data[ $key ] );
			}
		}
		foreach ( array( 'workplace', 'job_types', 'experience', 'education', 'links' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, '_dev3jb_' . $key, wp_slash( (string) wp_json_encode( $data[ $key ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
			}
		}
		if ( isset( $data['skills'] ) ) {
			wp_set_object_terms( $id, $data['skills'], Post_Types::SKILL );
		}
		if ( isset( $data['visible'] ) ) {
			update_post_meta( $id, '_dev3jb_visible', $data['visible'] ? 1 : 0 );
		}
		if ( isset( $data['file_id'] ) ) {
			$file = $data['file_id'] ? Files::get( $data['file_id'] ) : null;
			if ( ! $data['file_id'] || ( $file && (int) $file['user_id'] === (int) $user_id ) ) {
				update_post_meta( $id, '_dev3jb_file', (int) $data['file_id'] );
			}
		}
		if ( isset( $data['parsed_from'] ) ) {
			update_post_meta( $id, '_dev3jb_parsed_from', (int) $data['parsed_from'] );
		}
		do_action( 'dev3jb_resume_saved', $id, (int) $user_id );
		return $id;
	}

	/**
	 * Total years of experience (overlaps merged by month).
	 *
	 * @param array $experience Entries.
	 * @return float
	 */
	public static function years( array $experience ) {
		$months = array();
		$now    = gmdate( 'Y-m' );
		foreach ( $experience as $row ) {
			$start = isset( $row['start'] ) ? (string) $row['start'] : '';
			if ( '' === $start ) {
				continue;
			}
			$end = ! empty( $row['current'] ) || empty( $row['end'] ) ? $now : (string) $row['end'];
			$s   = (int) substr( $start, 0, 4 ) * 12 + (int) substr( $start, 5, 2 );
			$e   = (int) substr( $end, 0, 4 ) * 12 + (int) substr( $end, 5, 2 );
			for ( $m = $s; $m <= $e && $m - $s < 600; $m++ ) {
				$months[ $m ] = true;
			}
		}
		return round( count( $months ) / 12, 1 );
	}

	/**
	 * Profile completeness 0-100.
	 *
	 * @param array $p Profile.
	 * @return int
	 */
	public static function completeness( array $p ) {
		$checks = array(
			'' !== $p['headline'],
			'' !== $p['summary'],
			'' !== $p['location'],
			count( $p['skills'] ) >= 3,
			count( $p['experience'] ) > 0,
			count( $p['education'] ) > 0,
			! empty( $p['file_id'] ),
		);
		return (int) round( 100 * count( array_filter( $checks ) ) / count( $checks ) );
	}

	/**
	 * Matching text of a profile: skills, experience and education only (no contact details, no name).
	 *
	 * @param array $p Profile.
	 * @return string
	 */
	public static function to_text( array $p ) {
		$lines = array();
		if ( $p['headline'] ) {
			$lines[] = 'Headline: ' . $p['headline'];
		}
		if ( $p['skills'] ) {
			$lines[] = 'Skills: ' . implode( ', ', $p['skills'] );
		}
		if ( $p['summary'] ) {
			$lines[] = 'Summary: ' . $p['summary'];
		}
		$lines[] = 'Total experience: ' . $p['years'] . ' years';
		foreach ( $p['experience'] as $e ) {
			$lines[] = '- ' . trim( $e['title'] . ' at ' . $e['company'] ) . ' (' . $e['start'] . ' to ' . ( $e['current'] ? 'now' : $e['end'] ) . '): ' . $e['summary'];
		}
		foreach ( $p['education'] as $e ) {
			$lines[] = '- ' . trim( $e['degree'] . ' ' . $e['field'] ) . ', ' . $e['school'] . ( $e['year'] ? ' (' . $e['year'] . ')' : '' );
		}
		if ( $p['location'] ) {
			$lines[] = 'Based in: ' . $p['location'];
		}
		if ( $p['workplace'] ) {
			$lines[] = 'Workplace preference: ' . implode( ', ', $p['workplace'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Admin/employer list of profiles.
	 *
	 * @param array $args { search, page, per_page, visible_only }.
	 * @return array { items, total }
	 */
	public static function search( array $args ) {
		$q     = new \WP_Query(
			array(
				'post_type'      => Post_Types::RESUME,
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20,
				'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
				's'              => isset( $args['search'] ) ? (string) $args['search'] : '',
				'meta_query'     => ! empty( $args['visible_only'] ) ? array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_dev3jb_visible',
						'value' => 1,
					),
				) : array(),
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = self::get( $post->ID );
		}
		return array(
			'items' => $items,
			'total' => (int) $q->found_posts,
		);
	}
}
