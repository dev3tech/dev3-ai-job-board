<?php
/**
 * Company profiles (CPT dev3jb_company, owned by an employer).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository.
 */
class Companies {

	const SIZES = array( '', '1-10', '11-50', '51-200', '201-500', '501-1000', '1000+' );

	/**
	 * Short shape for job cards.
	 *
	 * @param int $id Company.
	 * @return array|null
	 */
	public static function summary( $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Post_Types::COMPANY !== $post->post_type ) {
			return null;
		}
		$logo_id = (int) get_post_thumbnail_id( $post );
		return array(
			'id'      => (int) $post->ID,
			'name'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'tagline' => (string) get_post_meta( $post->ID, '_dev3jb_tagline', true ),
			'website' => (string) get_post_meta( $post->ID, '_dev3jb_website', true ),
			'logo'    => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
			'logo_id' => $logo_id,
			'url'     => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'status'  => $post->post_status,
		);
	}

	/**
	 * Full shape.
	 *
	 * @param int $id Company.
	 * @return array|null
	 */
	public static function get( $id ) {
		$base = self::summary( $id );
		if ( ! $base ) {
			return null;
		}
		$post = get_post( $id );
		$m    = static function ( $key ) use ( $id ) {
			return (string) get_post_meta( $id, '_dev3jb_' . $key, true );
		};
		return array_merge(
			$base,
			array(
				'description'  => (string) $post->post_content,
				'industry'     => $m( 'industry' ),
				'size'         => $m( 'size' ),
				'founded'      => $m( 'founded' ),
				'headquarters' => $m( 'headquarters' ),
				'email'        => $m( 'email' ),
				'linkedin'     => $m( 'linkedin' ),
				'twitter'      => $m( 'twitter' ),
				'author'       => (int) $post->post_author,
				'jobs'         => self::job_count( $id ),
			)
		);
	}

	/**
	 * Open job count.
	 *
	 * @param int $id Company.
	 * @return int
	 */
	public static function job_count( $id ) {
		$q = new \WP_Query(
			array(
				'post_type'      => Post_Types::JOB,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_dev3jb_company', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Companies of a user (or all for managers when $user_id = 0).
	 *
	 * @param int $user_id Owner (0 = all).
	 * @return array
	 */
	public static function for_user( $user_id = 0 ) {
		$args = array(
			'post_type'      => Post_Types::COMPANY,
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $user_id ) {
			$args['author'] = (int) $user_id;
		}
		$out = array();
		foreach ( get_posts( $args ) as $post ) {
			$out[] = self::get( $post->ID );
		}
		return $out;
	}

	/**
	 * Sanitizes company input.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$out = array();
		if ( isset( $in['name'] ) ) {
			$out['name'] = mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 120 );
		}
		if ( isset( $in['description'] ) ) {
			$out['description'] = wp_kses_post( (string) $in['description'] );
		}
		foreach ( array( 'tagline', 'industry', 'headquarters' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, 160 );
			}
		}
		foreach ( array( 'website', 'linkedin', 'twitter' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = esc_url_raw( (string) $in[ $key ], array( 'http', 'https' ) );
			}
		}
		if ( isset( $in['email'] ) ) {
			$out['email'] = sanitize_email( (string) $in['email'] );
		}
		if ( isset( $in['size'] ) ) {
			$out['size'] = in_array( $in['size'], self::SIZES, true ) ? $in['size'] : '';
		}
		if ( isset( $in['founded'] ) ) {
			$year           = (int) $in['founded'];
			$out['founded'] = $year >= 1800 && $year <= (int) gmdate( 'Y' ) ? (string) $year : '';
		}
		if ( isset( $in['logo_id'] ) ) {
			$logo           = (int) $in['logo_id'];
			$out['logo_id'] = $logo && wp_attachment_is_image( $logo ) ? $logo : 0;
		}
		return $out;
	}

	/**
	 * Creates or updates a company.
	 *
	 * @param array  $data   Clean data.
	 * @param int    $id     Company (0 = new).
	 * @param int    $author Owner.
	 * @param string $status Status for new companies.
	 * @return int|\WP_Error
	 */
	public static function save( array $data, $id = 0, $author = 0, $status = 'publish' ) {
		if ( $id && Post_Types::COMPANY !== get_post_type( $id ) ) {
			return new \WP_Error( 'dev3jb_not_found', __( 'Company not found.', 'dev3-ai-job-board' ), array( 'status' => 404 ) );
		}
		if ( ( ! $id && empty( $data['name'] ) ) || ( isset( $data['name'] ) && '' === $data['name'] ) ) {
			return new \WP_Error( 'dev3jb_company_name', __( 'Add the company name.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		$post = array( 'post_type' => Post_Types::COMPANY );
		if ( isset( $data['name'] ) ) {
			$post['post_title'] = $data['name'];
		}
		if ( isset( $data['description'] ) ) {
			$post['post_content'] = $data['description'];
		}
		if ( $id ) {
			$post['ID'] = (int) $id;
			$id         = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_status'] = $status;
			$post['post_author'] = $author ? (int) $author : get_current_user_id();
			$id                  = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( array( 'tagline', 'industry', 'headquarters', 'website', 'linkedin', 'twitter', 'email', 'size', 'founded' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, '_dev3jb_' . $key, $data[ $key ] );
			}
		}
		if ( isset( $data['logo_id'] ) ) {
			if ( $data['logo_id'] ) {
				set_post_thumbnail( $id, $data['logo_id'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}
		return (int) $id;
	}

	/**
	 * Whether a user may use/edit a company.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    User.
	 * @return bool
	 */
	public static function owner_can( $company_id, $user_id ) {
		$post = get_post( $company_id );
		return $post && Post_Types::COMPANY === $post->post_type && $user_id && ( (int) $post->post_author === (int) $user_id || user_can( $user_id, 'edit_others_posts' ) );
	}

	/**
	 * Initials for logo placeholders.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function initials( $name ) {
		$words = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( (array) $words, 0, 2 ) as $w ) {
			$out .= mb_strtoupper( mb_substr( $w, 0, 1 ) );
		}
		return '' === $out ? '?' : $out;
	}
}
