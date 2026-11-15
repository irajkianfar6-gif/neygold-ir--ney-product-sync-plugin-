<?php
/**
 * Uninstall: removes plugin options only. Imported products and images are kept.
 *
 * @package NEYPS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'neyps_settings', 'neyps_log', 'neyps_job', 'neyps_last_run', 'neyps_collected' ) as $o ) {
	delete_option( $o );
}
delete_transient( 'neyps_lock' );
wp_clear_scheduled_hook( 'neyps_cron_sync' );
wp_clear_scheduled_hook( 'neyps_cron_continue' );
