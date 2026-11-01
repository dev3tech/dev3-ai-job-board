<?php
/**
 * Saved (bookmarked) jobs, stored in user meta.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
class Saved {

	const META = 'dev3jb_saved_jobs';

	/**
	 * Saved job ids.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function ids( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		$ids = get_user_meta( $user_id, self::META, true );
		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Toggles a job.
	 *
	 * @param int  $user_id User.
	 * @param int  $job_id  Job.
	 * @param bool $save    Save (true) or remove (false); null toggles.
	 * @return bool Saved now.
	 */
	public static function set( $user_id, $job_id, $save = null ) {
		$ids = self::ids( $user_id );
		$has = in_array( (int) $job_id, $ids, true );
		$on  = null === $save ? ! $has : (bool) $save;
		if ( $on && ! $has ) {
			array_unshift( $ids, (int) $job_id );
		} elseif ( ! $on && $has ) {
			$ids = array_values( array_diff( $ids, array( (int) $job_id ) ) );
		}
		update_user_meta( $user_id, self::META, array_slice( $ids, 0, 200 ) );
		return $on;
	}
}
