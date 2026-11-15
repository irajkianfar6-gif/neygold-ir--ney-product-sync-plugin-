<?php
/**
 * HTTP layer: browser-like requests, retries, Next.js RSC requests, optional renderer.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Http {

	private static $cache = array();

	public static function get( $url, $headers = array(), $method = 'GET' ) {
		$url = self::enc( $url );
		$key = md5( $method . $url . wp_json_encode( $headers ) );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$headers = array_merge(
			array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
				'Accept'          => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.7',
				'Referer'         => NEYPS_Settings::base() . '/',
			),
			$headers
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => (int) NEYPS_Settings::get( 'timeout' ),
			'redirection' => 5,
			'headers'     => $headers,
			'sslverify'   => (bool) apply_filters( 'neyps_sslverify', true ),
		);
		$res = null;
		$err = '';
		for ( $try = 1; $try <= 2; $try++ ) {
			$resp = wp_remote_request( $url, $args );
			if ( is_wp_error( $resp ) ) {
				$err = $resp->get_error_message();
			} else {
				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( $code >= 200 && $code < 300 ) {
					$res = array(
						'code' => $code,
						'body' => (string) wp_remote_retrieve_body( $resp ),
						'type' => (string) wp_remote_retrieve_header( $resp, 'content-type' ),
					);
					break;
				}
				$err = 'HTTP ' . $code;
				if ( in_array( $code, array( 400, 401, 403, 404, 405, 410 ), true ) ) {
					break;
				}
			}
			if ( $try < 2 ) {
				usleep( 600000 * $try );
			}
		}
		if ( null === $res ) {
			$res = array(
				'code'  => 0,
				'body'  => '',
				'type'  => '',
				'error' => $err,
			);
		}
		$delay = (int) NEYPS_Settings::get( 'delay_ms' );
		if ( $delay > 0 && 'HEAD' !== $method ) {
			usleep( $delay * 1000 );
		}
		if ( count( self::$cache ) > 60 ) {
			self::$cache = array();
		}
		self::$cache[ $key ] = $res;
		return $res;
	}

	public static function html( $url ) {
		$r = self::get( $url );
		return $r['body'];
	}

	/** Next.js App Router flight payload (server component data) of a page. */
	public static function rsc( $url ) {
		$r = self::get(
			$url,
			array(
				'RSC'    => '1',
				'Accept' => 'text/x-component',
			)
		);
		if ( false !== stripos( $r['type'], 'text/html' ) ) {
			return '';
		}
		return $r['body'];
	}

	public static function json( $url ) {
		$r = self::get( $url, array( 'Accept' => 'application/json, text/plain, */*' ) );
		if ( '' === $r['body'] ) {
			return null;
		}
		$j = json_decode( $r['body'], true );
		return is_array( $j ) ? $j : null;
	}

	/** HTML rendered by an optional headless renderer (e.g. https://r.jina.ai/{raw} or your own Browserless). */
	public static function rendered( $url ) {
		$tpl = trim( (string) NEYPS_Settings::get( 'render_url' ) );
		if ( '' === $tpl ) {
			return '';
		}
		if ( false !== strpos( $tpl, '{url}' ) || false !== strpos( $tpl, '{raw}' ) ) {
			$u = str_replace( array( '{url}', '{raw}' ), array( rawurlencode( $url ), $url ), $tpl );
		} else {
			$u = $tpl . $url;
		}
		$r = self::get(
			$u,
			array(
				'X-Return-Format' => 'html',
				'X-Timeout'       => '25',
				'X-No-Cache'      => 'true',
			)
		);
		return $r['body'];
	}

	public static function exists( $url ) {
		$r = self::get( $url, array(), 'HEAD' );
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			return true;
		}
		$r = self::get( $url, array( 'Range' => 'bytes=0-64' ) );
		return $r['code'] >= 200 && $r['code'] < 300;
	}

	/** Percent-encode non-ASCII characters (Persian query values). */
	public static function enc( $u ) {
		return preg_replace_callback(
			'/[^\x00-\x7F]+/',
			function ( $m ) {
				return rawurlencode( $m[0] );
			},
			(string) $u
		);
	}

	/** Absolute URL relative to the source store. */
	public static function abs( $u ) {
		$u = trim( html_entity_decode( (string) $u, ENT_QUOTES ) );
		if ( '' === $u ) {
			return '';
		}
		if ( 0 === strpos( $u, '//' ) ) {
			return 'https:' . $u;
		}
		if ( preg_match( '~^https?://~i', $u ) ) {
			return $u;
		}
		return NEYPS_Settings::base() . '/' . ltrim( $u, '/' );
	}
}
