<?php
/**
 * Discovery: finds every product id of the source store (listing pages + pagination,
 * gender filters, optional JSON API, related-product crawl, manual ids) and detects APIs.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Discovery {

	public static function tasks() {
		$tasks = array();
		$api   = str_replace( '{slug}', (string) strtok( NEYPS_Settings::host(), '.' ), trim( (string) NEYPS_Settings::get( 'api_list' ) ) );
		if ( '' !== $api ) {
			$tasks[] = array( 't' => 'api', 'u' => $api, 'page' => 1, 'seen' => 0, 'idle' => 0 );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) NEYPS_Settings::get( 'list_paths' ) ) as $path ) {
			$path = trim( $path );
			if ( '' !== $path ) {
				$tasks[] = array( 't' => 'list', 'u' => NEYPS_Http::abs( $path ), 'page' => 1, 'seen' => 0, 'idle' => 0 );
			}
		}
		return $tasks;
	}

	/** Manual ids / product URLs from the settings. */
	public static function manual_ids() {
		$out = array();
		if ( preg_match_all( '~(?:/product/)?(\d{2,12})~', (string) NEYPS_Settings::get( 'extra_ids' ), $m ) ) {
			$out = $m[1];
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * One discovery step (one page). Advances $task.
	 *
	 * @param array $task  Task (by reference).
	 * @param array $known Ids already known (flipped).
	 * @return array ids, seeds, done
	 */
	public static function step( &$task, $known ) {
		$max  = (int) NEYPS_Settings::get( 'max_pages' );
		$page = (int) $task['page'];
		if ( 'api' === $task['t'] ) {
			if ( $page > 1 && false === strpos( $task['u'], '{page}' ) && false === strpos( $task['u'], '{offset}' ) ) {
				return array( 'ids' => array(), 'seeds' => array(), 'done' => true );
			}
			$url   = str_replace( array( '{page}', '{offset}' ), array( $page, ( $page - 1 ) * 100 ), $task['u'] );
			$json  = NEYPS_Http::json( $url );
			$items = $json ? NEYPS_Parser::zarplus_api( $json ) : array();
			if ( $items ) {
				$res = array(
					'ids'   => array_map( 'strval', array_keys( $items ) ),
					'seeds' => $items,
				);
			} else {
				$res = $json ? NEYPS_Parser::listing( '', array( $json ) ) : array( 'ids' => array(), 'seeds' => array() );
			}
			if ( ! $json && 1 === $page ) {
				NEYPS_Log::warn( 'API فروشگاه از سرور شما در دسترس نیست: ' . $url );
			}
		} else {
			$url = $page > 1 ? add_query_arg( 'page', $page, $task['u'] ) : $task['u'];
			$r0  = NEYPS_Http::get( $url );
			if ( 0 === (int) $r0['code'] ) {
				NEYPS_Log::error( sprintf( 'سرور شما به %s دسترسی ندارد (%s). از تب «جمع‌آوری از مرورگر» استفاده کنید؛ محصولات بدون تماس سرور با زرپلاس منتشر می‌شوند.', NEYPS_Settings::host(), isset( $r0['error'] ) ? $r0['error'] : '' ) );
				return array(
					'ids'    => array(),
					'seeds'  => array(),
					'done'   => true,
					'failed' => true,
				);
			}
			$html = $r0['body'];
			$rsc  = NEYPS_Http::rsc( $url );
			$res  = NEYPS_Parser::listing( $html, array( $rsc ) );
		}
		$new = 0;
		foreach ( $res['ids'] as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				++$new;
			}
		}
		NEYPS_Log::info( sprintf( 'صفحه %s : %d محصول، %d جدید', rawurldecode( $url ), count( $res['ids'] ), $new ) );
		$task['idle'] = $new ? 0 : $task['idle'] + 1;
		$task['page'] = $page + 1;
		$done         = ! $res['ids'] || $task['idle'] >= 2 || $task['page'] > $max;
		return array(
			'ids'   => $res['ids'],
			'seeds' => $res['seeds'],
			'done'  => $done,
		);
	}

	/**
	 * Detect the JSON API the storefront uses: scans Next.js bundles for endpoints and probes them.
	 *
	 * @return array report
	 */
	public static function detect() {
		$base = NEYPS_Settings::base();
		$host = NEYPS_Settings::host();
		$slug = strtok( $host, '.' );
		$html = NEYPS_Http::html( $base . '/products' );
		$list = NEYPS_Parser::listing( $html, array( NEYPS_Http::rsc( $base . '/products' ) ) );
		$ids  = array_slice( $list['ids'], 0, 5 );
		$rep  = array(
			'listing_ids' => count( $list['ids'] ),
			'scripts'     => 0,
			'candidates'  => array(),
			'list_api'    => '',
			'detail_api'  => '',
		);
		$srcs = array();
		if ( preg_match_all( '~<script[^>]+src=["\']([^"\']+\.js[^"\']*)["\']~i', $html, $m ) ) {
			$srcs = array_slice( array_unique( $m[1] ), 0, 45 );
		}
		$text = $html;
		foreach ( $srcs as $s ) {
			$text .= "\n" . NEYPS_Http::html( NEYPS_Http::abs( $s ) );
		}
		$rep['scripts'] = count( $srcs );
		$cands          = array();
		if ( preg_match_all( '~https?://[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}(?:/[A-Za-z0-9_\-./]*)?~i', $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				$h = (string) wp_parse_url( $u, PHP_URL_HOST );
				if ( preg_match( '~arvanstorage|w3\.org|reactjs|nextjs|vercel|google|github|schema\.org|instagram|wa\.me|fonts|cdn|sentry|mozilla|npms|unpkg|jsdelivr|snapp|digipay~i', $h ) ) {
					continue;
				}
				if ( preg_match( '~api|backend|service|gateway|graphql~i', $u ) || ( $h !== $host && false !== strpos( $h, 'zar' ) ) ) {
					$cands[] = rtrim( $u, '/.' );
				}
			}
		}
		if ( preg_match_all( '~["\'`](/api/[A-Za-z0-9_\-./]*)~', $text, $m ) ) {
			foreach ( $m[1] as $u ) {
				$cands[] = $base . rtrim( $u, '/.' );
			}
		}
		$cands             = array_slice( array_values( array_unique( $cands ) ), 0, 25 );
		$rep['candidates'] = $cands;
		if ( ! $ids ) {
			return $rep;
		}
		$want = array_flip( $list['ids'] );
		$lpat = array( '', '/products', '/product', '/store/' . $slug . '/products', '/shop/' . $slug . '/products', '/stores/' . $slug . '/products', '/' . $slug . '/products', '/products?store=' . $slug, '/products?slug=' . $slug );
		$dpat = array( '/products/{id}', '/product/{id}', '/store/' . $slug . '/products/{id}', '/shop/' . $slug . '/products/{id}', '/products/{id}?store=' . $slug );
		$bases = array();
		foreach ( $cands as $c ) {
			$bases[] = $c;
			$p       = wp_parse_url( $c );
			if ( ! empty( $p['host'] ) ) {
				$bases[] = $p['scheme'] . '://' . $p['host'] . ( preg_match( '~^/api(/v\d+)?~', isset( $p['path'] ) ? $p['path'] : '', $vm ) ? $vm[0] : '' );
			}
		}
		$bases = array_slice( array_values( array_unique( $bases ) ), 0, 12 );
		foreach ( $bases as $b ) {
			if ( '' === $rep['list_api'] ) {
				foreach ( $lpat as $lp ) {
					$u = $b . $lp;
					$j = NEYPS_Http::json( $u );
					if ( $j ) {
						$r   = NEYPS_Parser::listing( '', array( $j ) );
						$hit = count( array_intersect_key( array_flip( $r['ids'] ), $want ) );
						if ( $hit >= 2 ) {
							$rep['list_api'] = add_query_arg( 'page', '{page}', $u );
							$rep['list_api'] = str_replace( '%7Bpage%7D', '{page}', $rep['list_api'] );
							break;
						}
					}
				}
			}
			if ( '' === $rep['detail_api'] ) {
				foreach ( $dpat as $dp ) {
					$u = $b . str_replace( '{id}', $ids[0], $dp );
					$j = NEYPS_Http::json( $u );
					if ( $j ) {
						$found = NEYPS_Parser::nodes( array( $j ), 3 );
						if ( isset( $found[ $ids[0] ] ) ) {
							$rep['detail_api'] = $b . $dp;
							break;
						}
					}
				}
			}
			if ( '' !== $rep['list_api'] && '' !== $rep['detail_api'] ) {
				break;
			}
		}
		return $rep;
	}
}
