<?php
/**
 * Job packages (definitions): price, job credits, duration, featured.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed list.
 */
class Packages {

	const OPTION = 'dev3jb_packages';

	/**
	 * Built-in starter packages (used until the admin saves their own).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			array(
				'id'            => 'free',
				'name'          => __( 'Basic', 'dev3-ai-job-board' ),
				'description'   => __( 'One job post for 30 days.', 'dev3-ai-job-board' ),
				'price'         => 0,
				'job_limit'     => 1,
				'duration_days' => 30,
				'featured'      => false,
				'highlight'     => false,
				'active'        => true,
				'wc_product_id' => 0,
			),
			array(
				'id'            => 'featured',
				'name'          => __( 'Featured', 'dev3-ai-job-board' ),
				'description'   => __( 'Pinned to the top of search results for 45 days.', 'dev3-ai-job-board' ),
				'price'         => 79,
				'job_limit'     => 1,
				'duration_days' => 45,
				'featured'      => true,
				'highlight'     => true,
				'active'        => true,
				'wc_product_id' => 0,
			),
			array(
				'id'            => 'team',
				'name'          => __( 'Hiring pack', 'dev3-ai-job-board' ),
				'description'   => __( 'Five featured jobs to use within a year.', 'dev3-ai-job-board' ),
				'price'         => 249,
				'job_limit'     => 5,
				'duration_days' => 60,
				'featured'      => true,
				'highlight'     => false,
				'active'        => true,
				'wc_product_id' => 0,
			),
		);
	}

	/**
	 * All packages.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, null );
		$list  = is_array( $saved ) ? $saved : self::defaults();
		return array_values( array_map( array( __CLASS__, 'normalize' ), $list ) );
	}

	/**
	 * Active packages.
	 *
	 * @return array
	 */
	public static function active() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $p ) {
					return $p['active'];
				}
			)
		);
	}

	/**
	 * One package.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Whether a package costs money.
	 *
	 * @param array $p Package.
	 * @return bool
	 */
	public static function is_paid( array $p ) {
		return (float) $p['price'] > 0;
	}

	/**
	 * Typed + sanitized package.
	 *
	 * @param array $p Raw.
	 * @return array
	 */
	public static function normalize( $p ) {
		$p    = is_array( $p ) ? $p : array();
		$name = isset( $p['name'] ) ? mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 80 ) : '';
		$id   = isset( $p['id'] ) ? sanitize_key( (string) $p['id'] ) : '';
		if ( '' === $id ) {
			$id = sanitize_key( $name );
		}
		return array(
			'id'            => '' === $id ? 'pkg' . wp_rand( 100, 999 ) : substr( $id, 0, 32 ),
			'name'          => '' === $name ? __( 'Package', 'dev3-ai-job-board' ) : $name,
			'description'   => isset( $p['description'] ) ? mb_substr( sanitize_text_field( (string) $p['description'] ), 0, 200 ) : '',
			'price'         => isset( $p['price'] ) ? max( 0.0, round( (float) $p['price'], 2 ) ) : 0.0,
			'job_limit'     => isset( $p['job_limit'] ) ? min( 1000, max( 1, (int) $p['job_limit'] ) ) : 1,
			'duration_days' => isset( $p['duration_days'] ) ? min( 365, max( 1, (int) $p['duration_days'] ) ) : 30,
			'featured'      => ! empty( $p['featured'] ),
			'highlight'     => ! empty( $p['highlight'] ),
			'active'        => ! isset( $p['active'] ) || ! empty( $p['active'] ),
			'wc_product_id' => isset( $p['wc_product_id'] ) ? (int) $p['wc_product_id'] : 0,
		);
	}

	/**
	 * Saves the list (unique ids).
	 *
	 * @param array $items Packages.
	 * @return array Saved.
	 */
	public static function save( array $items ) {
		$out  = array();
		$seen = array();
		foreach ( $items as $p ) {
			$p = self::normalize( $p );
			while ( isset( $seen[ $p['id'] ] ) ) {
				$p['id'] = substr( $p['id'], 0, 28 ) . wp_rand( 10, 99 );
			}
			$seen[ $p['id'] ] = true;
			$out[]            = $p;
		}
		update_option( self::OPTION, $out, false );
		return $out;
	}
}
