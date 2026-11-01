<?php
/**
 * Plugin tables (added to the core dbDelta run through dev3jb_schema_sql).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + CREATE statements.
 */
class Tables {

	const NAMES = array( 'applications', 'events', 'user_packages', 'payments', 'alerts', 'files' );

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3jb_' . $name;
	}

	/**
	 * Adds our statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function sql( $sql, $charset ) {
		$t = array();
		foreach ( self::NAMES as $n ) {
			$t[ $n ] = self::name( $n );
		}
		$sql[] = "CREATE TABLE {$t['applications']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			employer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			candidate_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resume_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			cover_letter text NULL,
			profile longtext NULL,
			stage varchar(20) NOT NULL DEFAULT 'new',
			position int(10) NOT NULL DEFAULT 0,
			rating tinyint(1) unsigned NOT NULL DEFAULT 0,
			match_score tinyint(3) unsigned NULL DEFAULT NULL,
			match_json text NULL,
			match_at datetime NULL DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'site',
			token char(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_email (job_id,email),
			KEY job_stage (job_id,stage),
			KEY employer (employer_id),
			KEY candidate (candidate_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(20) NOT NULL DEFAULT 'note',
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY application (application_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['user_packages']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			package_id varchar(32) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			job_limit int(10) unsigned NOT NULL DEFAULT 1,
			jobs_used int(10) unsigned NOT NULL DEFAULT 0,
			duration_days int(10) unsigned NOT NULL DEFAULT 30,
			featured tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_status (user_id,status)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['payments']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			package_id varchar(32) NOT NULL DEFAULT '',
			user_package_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(14,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'USD',
			gateway varchar(20) NOT NULL DEFAULT '',
			gateway_ref varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			paid_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user (user_id),
			KEY gateway_ref (gateway_ref),
			KEY status (status)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['alerts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			label varchar(160) NOT NULL DEFAULT '',
			query text NOT NULL,
			frequency varchar(10) NOT NULL DEFAULT 'daily',
			token char(32) NOT NULL DEFAULT '',
			confirmed tinyint(1) NOT NULL DEFAULT 0,
			last_sent datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user (user_id),
			KEY token (token)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['files']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(20) NOT NULL DEFAULT 'resume',
			path varchar(255) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			mime varchar(100) NOT NULL DEFAULT '',
			size int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user (user_id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::NAMES as $n ) {
			$table = self::name( $n );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
