<?php
/**
 * WP-CLI: wp neyps sync [--mode=all|new]   |   wp neyps test <id>
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_CLI {

	/**
	 * Import products from the source store.
	 *
	 * ## OPTIONS
	 * [--mode=<mode>]
	 * : all = every product, new = only products not imported yet.
	 * ---
	 * default: new
	 * ---
	 */
	public function sync( $args, $assoc ) {
		$mode = isset( $assoc['mode'] ) && 'all' === $assoc['mode'] ? 'all' : 'new';
		if ( ! NEYPS_Runner::running() ) {
			NEYPS_Runner::start( $mode );
		}
		do {
			$job = NEYPS_Runner::step( 60 );
			\WP_CLI::log( sprintf( '%s  ids:%d  %d/%d  created:%d updated:%d skipped:%d failed:%d', $job['phase'], count( $job['ids'] ), $job['qi'], count( $job['queue'] ), $job['stats']['created'], $job['stats']['updated'], $job['stats']['skipped'], $job['stats']['failed'] ) );
		} while ( $job && 'done' !== $job['phase'] );
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Show what would be extracted for one product id (nothing is saved).
	 *
	 * <id>
	 * : Source product id.
	 */
	public function test( $args ) {
		\WP_CLI::log( wp_json_encode( NEYPS_Importer::fetch( $args[0] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
\WP_CLI::add_command( 'neyps', 'NEYPS_CLI' );
