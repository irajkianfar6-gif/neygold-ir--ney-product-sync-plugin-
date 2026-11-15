<?php
/**
 * Lightweight run log (ring buffer stored in an option).
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Log {

	const OPTION = 'neyps_log';
	const MAX    = 400;

	public static function add( $msg, $level = 'info' ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			't' => current_time( 'mysql' ),
			'l' => $level,
			'm' => wp_strip_all_tags( (string) $msg ),
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	public static function info( $m ) {
		self::add( $m, 'info' );
	}

	public static function ok( $m ) {
		self::add( $m, 'ok' );
	}

	public static function warn( $m ) {
		self::add( $m, 'warn' );
	}

	public static function error( $m ) {
		self::add( $m, 'error' );
	}

	public static function recent( $n = 150 ) {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_reverse( array_slice( $log, -$n ) ) : array();
	}

	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}
