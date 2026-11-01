<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiJobBoard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3jb_settings = get_option( 'dev3jb_settings', array() );
if ( empty( $dev3jb_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
$dev3jb_tables = array( 'dev3jb_usage', 'dev3jb_core_cache', 'dev3jb_core_jobs', 'dev3jb_core_embeddings', 'dev3jb_applications', 'dev3jb_events', 'dev3jb_user_packages', 'dev3jb_payments', 'dev3jb_alerts', 'dev3jb_files' );
foreach ( $dev3jb_tables as $dev3jb_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3jb_table}" );
}
// Jobs, companies and candidate profiles.
foreach ( array( 'dev3jb_job', 'dev3jb_company', 'dev3jb_resume' ) as $dev3jb_type ) {
	$dev3jb_ids = get_posts(
		array(
			'post_type'      => $dev3jb_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $dev3jb_ids as $dev3jb_id ) {
		wp_delete_post( $dev3jb_id, true );
	}
}
// Private resume files.
$dev3jb_upload = wp_upload_dir( null, false );
$dev3jb_dir    = trailingslashit( $dev3jb_upload['basedir'] ) . 'dev3jb-private';
if ( is_dir( $dev3jb_dir ) ) {
	$dev3jb_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dev3jb_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $dev3jb_items as $dev3jb_item ) {
		if ( $dev3jb_item->isDir() ) {
			rmdir( $dev3jb_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $dev3jb_item->getPathname() );
		}
	}
	rmdir( $dev3jb_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
foreach ( array( 'dev3jb_settings', 'dev3jb_license', 'dev3jb_db_version', 'dev3jb_packages', 'dev3jb_payment_secrets' ) as $dev3jb_option ) {
	delete_option( $dev3jb_option );
}
delete_metadata( 'user', 0, 'dev3jb_saved_jobs', '', true );
remove_role( 'dev3jb_employer' );
remove_role( 'dev3jb_candidate' );
delete_transient( 'dev3jb_models' );
delete_site_transient( 'dev3jb_update_info' );
wp_unschedule_hook( 'dev3jb_run_job' );
wp_unschedule_hook( 'dev3jb_daily' );
