<?php
/**
 * Settings storage.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Settings {

	const OPTION = 'neyps_settings';

	public static function defaults() {
		return array(
			'source_url'      => 'https://neygold.zar.plus',
			'list_paths'      => "/products\n/products?gender=%D8%B2%D9%86%D8%A7%D9%86%D9%87\n/products?gender=%D9%85%D8%B1%D8%AF%D8%A7%D9%86%D9%87\n/products?gender=%D8%A8%DA%86%DA%AF%D8%A7%D9%86%D9%87\n/",
			'api_list'        => 'https://api.admin.zar.plus/api/mini-app/products?domain={slug}&page={page}&per_page=100&in_stock=1',
			'name_suffix'     => 'نی گلد',
			'api_detail'      => '',
			'render_url'      => '',
			'crawl_related'   => 1,
			'max_pages'       => 60,
			'batch'           => 2,
			'auto_sync'       => 'off',
			'post_status'     => 'publish',
			'import_price'    => 1,
			'price_round'     => 1000,
			'update_existing' => 0,
			'max_images'      => 15,
			'gender_parent'   => 0,
			'gender_attr'     => 1,
			'theme_meta'      => 1,
			'timeout'         => 20,
			'delay_ms'        => 300,
			'extra_ids'       => '',
		);
	}

	public static function install() {
		$cur = get_option( self::OPTION );
		if ( ! is_array( $cur ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		} else {
			update_option( self::OPTION, array_merge( self::defaults(), $cur ), false );
		}
	}

	/** Upgrade older installs: real zar.plus API + faster network defaults. */
	public static function upgrade() {
		$cur = get_option( self::OPTION );
		$cur = is_array( $cur ) ? $cur : array();
		$d   = self::defaults();
		if ( empty( $cur['api_list'] ) ) {
			$cur['api_list'] = $d['api_list'];
		}
		if ( isset( $cur['timeout'] ) && 30 === (int) $cur['timeout'] ) {
			$cur['timeout'] = $d['timeout'];
		}
		if ( isset( $cur['batch'] ) && 3 === (int) $cur['batch'] ) {
			$cur['batch'] = $d['batch'];
		}
		update_option( self::OPTION, array_merge( $d, $cur ), false );
	}

	public static function all() {
		$v = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $v ) ? $v : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Base URL of the source store without trailing slash. */
	public static function base() {
		$u = trim( (string) self::get( 'source_url' ) );
		if ( '' === $u ) {
			$u = 'https://neygold.zar.plus';
		}
		if ( ! preg_match( '~^https?://~i', $u ) ) {
			$u = 'https://' . $u;
		}
		$p = wp_parse_url( $u );
		if ( empty( $p['host'] ) ) {
			return 'https://neygold.zar.plus';
		}
		$p['scheme'] = empty( $p['scheme'] ) ? 'https' : $p['scheme'];
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	public static function host() {
		return (string) wp_parse_url( self::base(), PHP_URL_HOST );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$out = self::all();
		$in  = is_array( $in ) ? $in : array();
		foreach ( array( 'source_url', 'api_list', 'api_detail', 'render_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$raw       = trim( sanitize_text_field( wp_unslash( $in[ $k ] ) ) );
				$out[ $k ] = ( 'source_url' === $k ) ? esc_url_raw( $raw ) : preg_replace( '~[\s<>"\']~', '', $raw );
			}
		}
		foreach ( array( 'list_paths', 'extra_ids' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( wp_unslash( $in[ $k ] ) );
			}
		}
		if ( isset( $in['name_suffix'] ) ) {
			$out['name_suffix'] = sanitize_text_field( wp_unslash( $in['name_suffix'] ) );
		}
		$ints = array(
			'max_pages'   => array( 1, 500 ),
			'batch'       => array( 1, 20 ),
			'price_round' => array( 0, 1000000 ),
			'max_images'  => array( 1, 50 ),
			'timeout'     => array( 5, 120 ),
			'delay_ms'    => array( 0, 5000 ),
		);
		foreach ( $ints as $k => $r ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( $r[0], min( $r[1], absint( $in[ $k ] ) ) );
			}
		}
		foreach ( array( 'crawl_related', 'import_price', 'update_existing', 'gender_parent', 'gender_attr', 'theme_meta' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		if ( isset( $in['auto_sync'] ) ) {
			$v                = sanitize_key( $in['auto_sync'] );
			$out['auto_sync'] = in_array( $v, array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $v : 'off';
		}
		if ( isset( $in['post_status'] ) ) {
			$v                  = sanitize_key( $in['post_status'] );
			$out['post_status'] = in_array( $v, array( 'publish', 'draft', 'pending' ), true ) ? $v : 'publish';
		}
		return array_merge( $d, $out );
	}

	public static function save( $in ) {
		$clean = self::sanitize( $in );
		update_option( self::OPTION, $clean, false );
		neyps_schedule();
		return $clean;
	}
}
