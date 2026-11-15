<?php
/**
 * Parser: turns zar.plus (Next.js) pages / JSON into normalised product arrays.
 * Sources, strongest first: JSON-LD, __NEXT_DATA__, RSC flight data, JSON API, DOM.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Parser {

	const ARVAN = 'https://s3.ir-thr-at1.arvanstorage.ir/';

	public static $k_id     = array( 'id', 'product_id', 'productId', 'pk', '_id', 'uuid' );
	public static $k_name   = array( 'title', 'name', 'product_name', 'productName', 'fa_name', 'name_fa', 'persian_name' );
	public static $k_price  = array( 'cash_price', 'cashPrice', 'final_price', 'finalPrice', 'price', 'sell_price', 'sellPrice', 'total_price', 'totalPrice', 'amount' );
	public static $k_reg    = array( 'old_price', 'oldPrice', 'price_before_discount', 'regular_price', 'regularPrice', 'compare_price' );
	public static $k_weight = array( 'weight', 'gold_weight', 'goldWeight', 'net_weight', 'netWeight', 'wt' );
	public static $k_images = array( 'images', 'gallery', 'pictures', 'photos', 'media', 'files', 'product_images', 'productImages', 'image_list', 'imageList', 'attachments' );
	public static $k_image  = array( 'image', 'main_image', 'mainImage', 'image_url', 'imageUrl', 'thumbnail', 'thumb', 'cover', 'picture', 'photo' );
	public static $k_desc   = array( 'description', 'long_description', 'longDescription', 'desc', 'body', 'content', 'details', 'about', 'caption' );
	public static $k_cat    = array( 'category', 'categories', 'category_name', 'categoryName', 'category_title', 'product_category', 'productCategory', 'cat', 'collection' );
	public static $k_gender = array( 'gender', 'genders', 'sex' );
	public static $k_specs  = array( 'specs', 'specifications', 'attributes', 'properties', 'features', 'extra_fields', 'extraFields', 'fields', 'attrs', 'details_list' );
	public static $k_sku    = array( 'sku', 'code', 'product_code', 'productCode', 'barcode' );
	public static $k_wage   = array( 'wage', 'ojrat', 'making_charge', 'makingCharge', 'wage_percent', 'wagePercent' );
	public static $k_stock  = array( 'in_stock', 'inStock', 'is_available', 'isAvailable', 'available', 'stock', 'quantity', 'count' );

	public static function blank( $id = '' ) {
		return array(
			'id'          => (string) $id,
			'url'         => '',
			'name'        => '',
			'description' => '',
			'price'       => '',
			'regular'     => '',
			'weight'      => '',
			'wage'        => '',
			'sku'         => '',
			'images'      => array(),
			'files'       => array(),
			'categories'  => array(),
			'gender'      => array(),
			'specs'       => array(),
			'in_stock'    => null,
			'related'     => array(),
			'sources'     => array(),
		);
	}

	/* ------------------------------------------------------------------ helpers */

	public static function en( $s ) {
		return strtr(
			(string) $s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٫' => '.', '٬' => ',', '،' => ',',
			)
		);
	}

	/** Number from "۳۶,۴۴۱,۰۸۰ تومان" / "۱.۲۱ گرم" / 36441080. */
	public static function num( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		if ( ! is_string( $v ) ) {
			return '';
		}
		$s = str_replace( ',', '', self::en( $v ) );
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $s, $m ) ) {
			return '';
		}
		return $m[0];
	}

	public static function text( $v ) {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = html_entity_decode( wp_strip_all_tags( (string) $v ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/[ \t\x{00A0}\x{200B}]+/u', ' ', $v ) );
	}

	public static function is_assoc( $a ) {
		return is_array( $a ) && array() !== $a && array_keys( $a ) !== range( 0, count( $a ) - 1 );
	}

	public static function pick( $n, $keys ) {
		if ( ! is_array( $n ) ) {
			return null;
		}
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $n ) && null !== $n[ $k ] && '' !== $n[ $k ] && array() !== $n[ $k ] && '$undefined' !== $n[ $k ] ) {
				return $n[ $k ];
			}
		}
		return null;
	}

	public static function walk( $node, $cb, $depth = 0 ) {
		if ( ! is_array( $node ) || $depth > 60 ) {
			return;
		}
		call_user_func( $cb, $node );
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				self::walk( $v, $cb, $depth + 1 );
			}
		}
	}

	/** Text labels from a string / {name|title} / list of them. */
	public static function labels( $v ) {
		$out = array();
		if ( is_string( $v ) ) {
			foreach ( preg_split( '/\s*[,\x{060C}|]\s*/u', $v ) as $x ) {
				$x = self::text( $x );
				if ( '' !== $x && ! is_numeric( self::en( $x ) ) && mb_strlen( $x ) < 80 ) {
					$out[] = $x;
				}
			}
		} elseif ( self::is_assoc( $v ) ) {
			$parent = isset( $v['parent'] ) ? self::labels( $v['parent'] ) : array();
			$name   = self::pick( $v, array( 'title', 'name', 'label', 'fa_name', 'value', 'text' ) );
			$out    = array_merge( $parent, is_string( $name ) ? self::labels( $name ) : array() );
		} elseif ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				$out = array_merge( $out, self::labels( $x ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Specs as label => value, from list of pairs or assoc arrays. */
	public static function specs( $v ) {
		$out = array();
		if ( self::is_assoc( $v ) ) {
			$k = self::pick( $v, array( 'title', 'name', 'label', 'key', 'attribute', 'field' ) );
			$x = self::pick( $v, array( 'value', 'values', 'val', 'option', 'options', 'text' ) );
			if ( is_string( $k ) && null !== $x ) {
				$val = is_array( $x ) ? implode( '، ', self::labels( $x ) ) : self::text( $x );
				if ( '' !== $val ) {
					$out[ self::text( $k ) ] = $val;
				}
				return $out;
			}
			foreach ( $v as $key => $val ) {
				if ( is_string( $key ) && is_scalar( $val ) && '' !== self::text( $val ) && ! preg_match( '/^(id|_id|created|updated|pk|image|url|slug)/i', $key ) ) {
					$out[ self::text( $key ) ] = self::text( $val );
				} elseif ( is_array( $val ) ) {
					$out = array_merge( $out, self::specs( $val ) );
				}
			}
		} elseif ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( is_array( $x ) ) {
					$out = array_merge( $out, self::specs( $x ) );
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ images */

	/** Normalise one image reference to a full-size URL (or a bare file name to resolve later). */
	public static function img( $u ) {
		if ( self::is_assoc( $u ) ) {
			$u = self::pick( $u, array( 'original', 'full', 'large', 'url', 'src', 'path', 'image', 'file', 'image_url', 'imageUrl', 'link', 'address', 'name' ) );
		}
		if ( ! is_string( $u ) ) {
			return '';
		}
		$u = trim( html_entity_decode( $u, ENT_QUOTES ) );
		if ( '' === $u || 0 === strpos( $u, 'data:' ) ) {
			return '';
		}
		// Next.js image proxy: /_next/image?url=<encoded original>&w=..
		if ( false !== strpos( $u, '/_next/image' ) && preg_match( '~[?&]url=([^&\s"]+)~', $u, $m ) ) {
			$u = rawurldecode( $m[1] );
		}
		$u = preg_replace( '~\\\\/~', '/', $u );
		if ( preg_match( '~^/?talasis/~', $u ) ) {
			$u = self::ARVAN . ltrim( $u, '/' );
		}
		if ( ! preg_match( '~\.(jpe?g|png|webp|gif|avif)(\?.*)?$~i', $u ) && false === strpos( $u, 'arvanstorage' ) ) {
			return '';
		}
		if ( 0 === strpos( $u, '//' ) ) {
			$u = 'https:' . $u;
		}
		// store chrome: logo, banners, payment badges.
		if ( preg_match( '~/users/\d+/logo|^/images/|/_next/static/|/icons?/|favicon~i', $u ) ) {
			return '';
		}
		if ( ! preg_match( '~^https?://~i', $u ) ) {
			if ( false === strpos( $u, '/' ) ) {
				return 'file:' . $u; // bare file name, resolved by the importer.
			}
			$u = NEYPS_Http::abs( $u );
		}
		return $u;
	}

	public static function images( $v ) {
		$out = array();
		if ( is_string( $v ) || self::is_assoc( $v ) ) {
			$v = array( $v );
		}
		if ( ! is_array( $v ) ) {
			return $out;
		}
		foreach ( $v as $x ) {
			$u = self::img( $x );
			if ( '' !== $u ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------- embedded JSON */

	/** Concatenated Next.js App Router flight payload from self.__next_f.push([1,"..."]). */
	public static function flight( $html ) {
		$out = '';
		if ( preg_match_all( '/self\.__next_f\.push\(\[1,\s*"((?:[^"\\\\]|\\\\.)*)"\]\)/s', $html, $m ) ) {
			foreach ( $m[1] as $chunk ) {
				$dec = json_decode( '"' . $chunk . '"' );
				if ( is_string( $dec ) ) {
					$out .= $dec . "\n";
				}
			}
		}
		return $out;
	}

	/** Every decodable JSON object/array inside a text blob. */
	public static function blobs( $text, $limit = 600 ) {
		$out  = array();
		$text = substr( (string) $text, 0, 6000000 );
		$len  = strlen( $text );
		$i    = 0;
		while ( $i < $len && count( $out ) < $limit ) {
			$next = strcspn( $text, '{[', $i );
			$i   += $next;
			if ( $i >= $len ) {
				break;
			}
			$end = self::match_end( $text, $i, $len );
			if ( $end > $i + 10 ) {
				$j = json_decode( substr( $text, $i, $end - $i + 1 ), true );
				if ( is_array( $j ) ) {
					$out[] = $j;
					$i     = $end + 1;
					continue;
				}
			}
			++$i;
		}
		return $out;
	}

	private static function match_end( $s, $start, $len ) {
		$depth = 0;
		$in    = false;
		$esc   = false;
		$stop  = min( $len, $start + 3000000 );
		for ( $k = $start; $k < $stop; $k++ ) {
			$ch = $s[ $k ];
			if ( "\n" === $ch ) {
				return -1;
			}
			if ( $in ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( '\\' === $ch ) {
					$esc = true;
				} elseif ( '"' === $ch ) {
					$in = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in = true;
			} elseif ( '{' === $ch || '[' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch || ']' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return $k;
				}
			} elseif ( "\n" === $ch && 0 === $depth ) {
				return -1;
			}
		}
		return -1;
	}

	/** All JSON structures embedded in a page (JSON-LD, __NEXT_DATA__, flight data) plus raw JSON bodies. */
	public static function page_json( $html, $extra = array() ) {
		$all = array();
		if ( preg_match_all( '~<script[^>]*type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>~is', $html, $m ) ) {
			foreach ( $m[1] as $s ) {
				$j = json_decode( html_entity_decode( trim( $s ) ), true );
				if ( is_array( $j ) ) {
					$all[] = $j;
				}
			}
		}
		if ( preg_match( '~<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>~is', $html, $m ) ) {
			$j = json_decode( $m[1], true );
			if ( is_array( $j ) ) {
				$all[] = $j;
			}
		}
		$texts = array( self::flight( $html ) );
		foreach ( (array) $extra as $x ) {
			if ( is_array( $x ) ) {
				$all[] = $x;
			} elseif ( is_string( $x ) && '' !== $x ) {
				$j = json_decode( $x, true );
				if ( is_array( $j ) ) {
					$all[] = $j;
				} else {
					$texts[] = false !== strpos( $x, 'self.__next_f' ) ? self::flight( $x ) : $x;
				}
			}
		}
		foreach ( $texts as $t ) {
			if ( '' !== trim( $t ) ) {
				$all = array_merge( $all, self::blobs( $t ) );
			}
		}
		return $all;
	}

	/* --------------------------------------------------------- product nodes */

	/** How "product-like" an array is (0 = not a product). */
	public static function score( $n, $want = '' ) {
		if ( ! self::is_assoc( $n ) ) {
			return 0;
		}
		$name = self::pick( $n, self::$k_name );
		if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > 300 ) {
			return 0;
		}
		$s  = 1;
		$s += null !== self::pick( $n, self::$k_weight ) ? 3 : 0;
		$s += null !== self::pick( $n, self::$k_price ) ? 1 : 0;
		$s += ( null !== self::pick( $n, self::$k_images ) || null !== self::pick( $n, self::$k_image ) ) ? 2 : 0;
		$s += null !== self::pick( $n, self::$k_desc ) ? 1 : 0;
		$s += null !== self::pick( $n, self::$k_cat ) ? 1 : 0;
		$s += null !== self::pick( $n, self::$k_specs ) ? 1 : 0;
		$id = self::pick( $n, self::$k_id );
		if ( '' !== (string) $want && is_scalar( $id ) && (string) $id === (string) $want ) {
			$s += 20;
		}
		return $s;
	}

	public static function node_id( $n ) {
		$id = self::pick( $n, self::$k_id );
		return is_scalar( $id ) ? (string) $id : '';
	}

	/** Normalise a product-like JSON node. */
	public static function from_node( $n ) {
		$p         = self::blank( self::node_id( $n ) );
		$p['name'] = self::text( self::pick( $n, self::$k_name ) );
		$d         = self::pick( $n, self::$k_desc );
		if ( is_string( $d ) && '' !== trim( $d ) ) {
			$p['description'] = $d;
		}
		$pr = self::pick( $n, self::$k_price );
		if ( is_array( $pr ) ) {
			$pr = self::pick( $pr, array( 'cash', 'final', 'amount', 'value', 'price', 'total' ) );
		}
		$p['price']   = is_scalar( $pr ) ? self::num( $pr ) : '';
		$rg           = self::pick( $n, self::$k_reg );
		$p['regular'] = is_scalar( $rg ) ? self::num( $rg ) : '';
		$w            = self::pick( $n, self::$k_weight );
		$p['weight']  = is_scalar( $w ) ? self::num( $w ) : '';
		$wg           = self::pick( $n, self::$k_wage );
		$p['wage']    = is_scalar( $wg ) ? self::num( $wg ) : '';
		$sku          = self::pick( $n, self::$k_sku );
		$p['sku']     = is_scalar( $sku ) ? self::text( $sku ) : '';
		$p['images']  = array_merge( self::images( self::pick( $n, self::$k_image ) ), self::images( self::pick( $n, self::$k_images ) ) );
		$p['categories'] = self::labels( self::pick( $n, self::$k_cat ) );
		$p['gender']     = self::labels( self::pick( $n, self::$k_gender ) );
		$p['specs']      = self::specs( self::pick( $n, self::$k_specs ) );
		$st              = self::pick( $n, self::$k_stock );
		if ( is_bool( $st ) ) {
			$p['in_stock'] = $st;
		} elseif ( is_numeric( $st ) ) {
			$p['in_stock'] = (float) $st > 0;
		}
		$p['sources'][] = 'json';
		return $p;
	}

	/** Every product-like node in a list of JSON structures, keyed by source id. */
	public static function nodes( $structures, $min = 4 ) {
		$found = array();
		$cb    = function ( $n ) use ( &$found, $min ) {
			$id = self::node_id( $n );
			if ( '' === $id || ! preg_match( '/^\d{2,12}$/', $id ) ) {
				return;
			}
			$s = self::score( $n );
			if ( $s >= $min && ( ! isset( $found[ $id ] ) || $s > $found[ $id ][0] ) ) {
				$found[ $id ] = array( $s, $n );
			}
		};
		foreach ( $structures as $st ) {
			self::walk( $st, $cb );
		}
		return $found;
	}

	/* ---------------------------------------------------------------- merge */

	public static function merge( $a, $b ) {
		foreach ( $b as $k => $v ) {
			if ( in_array( $k, array( 'images', 'files', 'categories', 'gender', 'related', 'sources' ), true ) ) {
				$a[ $k ] = array_values( array_unique( array_merge( (array) $a[ $k ], (array) $v ) ) );
			} elseif ( 'specs' === $k ) {
				$a[ $k ] = array_merge( (array) $v, (array) $a[ $k ] );
			} elseif ( ( '' === $a[ $k ] || null === $a[ $k ] ) && '' !== $v && null !== $v ) {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}

	/* ---------------------------------------------------------------- pages */

	/** Product ids + card data (name, image, weight, price) found on a listing page / JSON. */
	public static function listing( $html, $extra = array() ) {
		$ids   = array();
		$seeds = array();
		if ( preg_match_all( '~/product/(\d{2,12})(?![\d])~', $html . ' ' . implode( ' ', array_filter( (array) $extra, 'is_string' ) ), $m ) ) {
			$ids = $m[1];
		}
		foreach ( self::nodes( self::page_json( $html, $extra ), 3 ) as $id => $hit ) {
			$ids[]        = (string) $id;
			$seeds[ $id ] = self::from_node( $hit[1] );
		}
		if ( preg_match_all( '~<a\b[^>]*href=["\'][^"\']*/product/(\d{2,12})["\'][^>]*>(.*?)</a>~is', $html, $cards, PREG_SET_ORDER ) ) {
			foreach ( $cards as $c ) {
				$seed = self::card( $c[1], $c[2] );
				$seeds[ $c[1] ] = isset( $seeds[ $c[1] ] ) ? self::merge( $seeds[ $c[1] ], $seed ) : $seed;
			}
		}
		return array(
			'ids'   => array_values( array_unique( array_map( 'strval', $ids ) ) ),
			'seeds' => $seeds,
		);
	}

	/** A product card: <a href="/product/ID"><img alt="تصویر NAME" src=..> ۱.۲۱ گرم NAME ۳۶,۴۴۱,۰۸۰ تومان</a>. */
	public static function card( $id, $inner ) {
		$p = self::blank( $id );
		if ( preg_match( '~<img\b[^>]*\balt=["\']([^"\']*)["\']~i', $inner, $m ) ) {
			$p['name'] = self::text( preg_replace( '/^\s*تصویر\s+/u', '', html_entity_decode( $m[1], ENT_QUOTES ) ) );
		}
		if ( preg_match_all( '~(?:src|srcset|data-src)=["\']([^"\']+)["\']~i', $inner, $m ) ) {
			foreach ( $m[1] as $src ) {
				$first = trim( explode( ' ', trim( explode( ',', $src )[0] ) )[0] );
				$u     = self::img( $first );
				if ( '' !== $u && 0 !== strpos( $u, 'file:' ) ) {
					$p['images'][] = $u;
				}
			}
			$p['images'] = array_values( array_unique( $p['images'] ) );
		}
		$t = self::en( self::text( preg_replace( '~<[^>]+>~', ' ', $inner ) ) );
		if ( preg_match( '/([\d.]+)\s*گرم/u', $t, $m ) ) {
			$p['weight'] = $m[1];
		}
		if ( preg_match( '/([\d,]{4,})\s*تومان/u', $t, $m ) ) {
			$p['price'] = str_replace( ',', '', $m[1] );
		}
		$p['sources'][] = 'card';
		return $p;
	}

	/**
	 * Full product from its page HTML (+ RSC payload / rendered HTML / API JSON).
	 *
	 * @param string $id    Source product id.
	 * @param string $html  Product page HTML.
	 * @param array  $extra Extra payloads (RSC text, rendered HTML, decoded API JSON).
	 */
	public static function product( $id, $html, $extra = array() ) {
		$id   = (string) $id;
		$p    = self::blank( $id );
		$json = self::page_json( $html, $extra );
		$best = null;
		$bs   = 0;
		$cb   = function ( $n ) use ( $id, &$best, &$bs ) {
			$s = self::score( $n, $id );
			if ( $s >= 21 && $s > $bs ) {
				$best = $n;
				$bs   = $s;
			}
		};
		foreach ( $json as $st ) {
			self::walk( $st, $cb );
		}
		if ( $best ) {
			$p = self::merge( $p, self::from_node( $best ) );
			// Second pass: other nodes with the same id (e.g. images or specs delivered separately).
			$cb2 = function ( $n ) use ( $id, &$p, $best ) {
				if ( $n !== $best && self::score( $n, $id ) >= 21 ) {
					$p = self::merge( $p, self::from_node( $n ) );
				}
			};
			foreach ( $json as $st ) {
				self::walk( $st, $cb2 );
			}
		}
		// JSON-LD Product (no id): use when present.
		foreach ( $json as $st ) {
			self::walk(
				$st,
				function ( $n ) use ( &$p ) {
					if ( isset( $n['@type'] ) && ( 'Product' === $n['@type'] || ( is_array( $n['@type'] ) && in_array( 'Product', $n['@type'], true ) ) ) ) {
						$q          = self::blank( '' );
						$q['name']  = self::text( isset( $n['name'] ) ? $n['name'] : '' );
						$q['description'] = isset( $n['description'] ) && is_string( $n['description'] ) ? $n['description'] : '';
						$q['images']      = self::images( isset( $n['image'] ) ? $n['image'] : array() );
						$q['sku']         = isset( $n['sku'] ) && is_scalar( $n['sku'] ) ? (string) $n['sku'] : '';
						$q['categories']  = self::labels( isset( $n['category'] ) ? $n['category'] : array() );
						if ( isset( $n['offers'] ) && is_array( $n['offers'] ) ) {
							$o          = isset( $n['offers']['price'] ) ? $n['offers'] : ( isset( $n['offers'][0] ) ? $n['offers'][0] : array() );
							$q['price'] = isset( $o['price'] ) ? self::num( $o['price'] ) : '';
						}
						$q['sources'][] = 'jsonld';
						$id_keep        = $p['id'];
						$p              = self::merge( $p, $q );
						$p['id']        = $id_keep;
					}
				}
			);
		}
		// DOM of the (rendered) page.
		foreach ( array_merge( array( $html ), array_filter( (array) $extra, 'is_string' ) ) as $doc ) {
			if ( false !== stripos( $doc, '<html' ) || false !== stripos( $doc, '<body' ) || false !== stripos( $doc, '<h1' ) ) {
				$p = self::merge( $p, self::dom( $id, $doc ) );
			}
		}
		// Related products (for discovery).
		$all = $html . ' ' . implode( ' ', array_filter( (array) $extra, 'is_string' ) );
		if ( preg_match_all( '~/product/(\d{2,12})(?![\d])~', $all, $m ) ) {
			$p['related'] = array_values( array_diff( array_unique( $m[1] ), array( $id ) ) );
		}
		$p['id']  = $id;
		$p['url'] = NEYPS_Settings::base() . '/product/' . rawurlencode( $id );
		return self::finish( $p );
	}

	/** DOM fallback for rendered pages: title, gallery, specs table, price, weight, description. */
	public static function dom( $id, $html ) {
		$p = self::blank( $id );
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
			return $p;
		}
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$xp = new DOMXPath( $doc );

		$h1 = $xp->query( '//main//h1 | //h1' );
		foreach ( $h1 as $h ) {
			$t = self::text( $h->textContent );
			if ( '' !== $t && mb_strlen( $t ) < 250 ) {
				$p['name'] = $t;
				break;
			}
		}
		if ( '' === $p['name'] ) {
			$og = $xp->query( '//meta[@property="og:title"]/@content' );
			if ( $og->length ) {
				$p['name'] = self::text( $og->item( 0 )->nodeValue );
			}
		}

		// Images: everything in the page that lives under the product folder, then og:image.
		$cand = array();
		foreach ( $xp->query( '//img/@src | //img/@srcset | //img/@data-src | //source/@srcset | //a/@href | //meta[@property="og:image"]/@content' ) as $a ) {
			foreach ( explode( ',', (string) $a->nodeValue ) as $piece ) {
				$u = self::img( trim( explode( ' ', trim( $piece ) )[0] ) );
				if ( '' !== $u && 0 !== strpos( $u, 'file:' ) ) {
					$cand[] = $u;
				}
			}
		}
		if ( preg_match_all( '~https?:(?:\\\\?/){2}s3\.[a-z0-9.\-]*arvanstorage\.ir(?:\\\\?/)[^"\'\s)<>]+?\.(?:jpe?g|png|webp|gif)~i', $html, $mm ) ) {
			foreach ( $mm[0] as $u ) {
				$cand[] = str_replace( '\\/', '/', $u );
			}
		}
		foreach ( array_unique( $cand ) as $u ) {
			if ( false !== strpos( $u, '/' . $id . '/' ) ) {
				$p['images'][] = $u;
			}
		}

		// Specs block: "مشخصات محصول" heading followed by label/value pairs.
		foreach ( $xp->query( '//*[self::h2 or self::h3 or self::h4 or self::div or self::span or self::p][normalize-space(text())="مشخصات محصول" or normalize-space(text())="مشخصات"]' ) as $head ) {
			$box = $head->nextSibling;
			while ( $box && XML_ELEMENT_NODE !== $box->nodeType ) {
				$box = $box->nextSibling;
			}
			if ( ! $box ) {
				$box = $head->parentNode;
			}
			$p['specs'] = array_merge( $p['specs'], self::dom_pairs( $xp, $box, $head ) );
			break;
		}
		foreach ( $xp->query( '//table//tr' ) as $tr ) {
			$cells = $xp->query( './th|./td', $tr );
			if ( 2 === $cells->length ) {
				$k = self::text( $cells->item( 0 )->textContent );
				$v = self::text( $cells->item( 1 )->textContent );
				if ( '' !== $k && '' !== $v && mb_strlen( $k ) < 60 ) {
					$p['specs'][ $k ] = $v;
				}
			}
		}

		// Description block.
		foreach ( $xp->query( '//*[self::h2 or self::h3 or self::h4 or self::div or self::span][normalize-space(text())="توضیحات" or normalize-space(text())="توضیحات محصول" or normalize-space(text())="معرفی محصول"]' ) as $head ) {
			$box = $head->nextSibling;
			while ( $box && XML_ELEMENT_NODE !== $box->nodeType ) {
				$box = $box->nextSibling;
			}
			if ( $box ) {
				$p['description'] = trim( $doc->saveHTML( $box ) );
				break;
			}
		}
		if ( '' === $p['description'] ) {
			$md = $xp->query( '//meta[@property="og:description"]/@content | //meta[@name="description"]/@content' );
			if ( $md->length ) {
				$d = self::text( $md->item( 0 )->nodeValue );
				if ( mb_strlen( $d ) > 25 && $d !== $p['name'] ) {
					$p['description'] = $d;
				}
			}
		}

		// Price + weight from the text that follows the title.
		$main = $xp->query( '//main' );
		$txt  = self::en( self::text( $main->length ? $main->item( 0 )->textContent : $doc->textContent ) );
		if ( '' !== $p['name'] ) {
			$pos = mb_strpos( $txt, self::en( $p['name'] ) );
			if ( false !== $pos ) {
				$txt = mb_substr( $txt, $pos, 1500 );
			}
		}
		$txt = preg_split( '/کالا[\x{200C} ]*های مرتبط|محصولات مرتبط/u', $txt )[0];
		if ( preg_match( '/([\d.]+)\s*گرم/u', $txt, $m ) ) {
			$p['weight'] = $m[1];
		}
		if ( preg_match( '/([\d,]{5,})\s*تومان/u', $txt, $m ) ) {
			$p['price'] = str_replace( ',', '', $m[1] );
		}
		if ( preg_match( '/ناموجود|اتمام موجودی/u', $txt ) ) {
			$p['in_stock'] = false;
		}
		$p['sources'][] = 'dom';
		return $p;
	}

	/** Label/value pairs inside a specs container. */
	private static function dom_pairs( $xp, $box, $skip ) {
		$out  = array();
		$rows = $xp->query( './/*[count(./*)>=2 and count(./*)<=3]', $box );
		foreach ( $rows as $row ) {
			$kids = array();
			foreach ( $row->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType ) {
					$kids[] = self::text( $c->textContent );
				}
			}
			$kids = array_values( array_filter( $kids, 'strlen' ) );
			if ( 2 === count( $kids ) && mb_strlen( $kids[0] ) < 50 && mb_strlen( $kids[1] ) < 200 && ! $row->isSameNode( $skip ) ) {
				if ( ! isset( $out[ $kids[0] ] ) ) {
					$out[ $kids[0] ] = $kids[1];
				}
			}
		}
		return $out;
	}

	/**
	 * zar.plus mini-app API (api.admin.zar.plus/api/mini-app/products):
	 * {"data":{"<product id>":{"<gateway>":[entry, ...], ...}}}
	 *
	 * @return array source id => normalised product
	 */
	public static function zarplus_api( $json ) {
		$out = array();
		if ( ! is_array( $json ) || ! isset( $json['data'] ) || ! self::is_assoc( $json['data'] ) ) {
			return $out;
		}
		foreach ( $json['data'] as $pid => $gws ) {
			$pid = (string) $pid;
			if ( ! preg_match( '/^\d{2,12}$/', $pid ) || ! is_array( $gws ) ) {
				continue;
			}
			$p = self::zarplus_entry( $pid, $gws );
			if ( '' !== $p['name'] ) {
				$out[ $pid ] = $p;
			}
		}
		return $out;
	}

	/** One product of the zar.plus API (all payment-gateway copies merged). */
	public static function zarplus_entry( $pid, $gws ) {
		$p       = self::blank( $pid );
		$entries = array();
		$cash    = 0;
		foreach ( $gws as $list ) {
			if ( self::is_assoc( $list ) ) {
				$list = array( $list );
			}
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $e ) {
				if ( ! is_array( $e ) || empty( $e['name'] ) ) {
					continue;
				}
				$entries[] = $e;
				$v         = ( isset( $e['product_variants'][0] ) && is_array( $e['product_variants'][0] ) ) ? $e['product_variants'][0] : array();
				if ( isset( $v['price'] ) && is_numeric( $v['price'] ) && $v['price'] > 0 ) {
					$pct = isset( $v['hnk_gold_gateway_percent'] ) ? (float) $v['hnk_gold_gateway_percent'] : 0;
					$c   = (float) $v['price'] / ( 1 + $pct / 100 );
					if ( ! $cash || $c < $cash ) {
						$cash = $c;
					}
				}
			}
		}
		if ( ! $entries ) {
			return $p;
		}
		$e         = $entries[0];
		$v         = ( isset( $e['product_variants'][0] ) && is_array( $e['product_variants'][0] ) ) ? $e['product_variants'][0] : array();
		$p['name'] = self::text( $e['name'] );
		if ( isset( $e['description'] ) && is_string( $e['description'] ) ) {
			$p['description'] = $e['description'];
		}
		$seen = array();
		$add  = function ( $u ) use ( &$p, &$seen ) {
			$u = self::img( $u );
			if ( '' === $u || 0 === strpos( $u, 'file:' ) ) {
				return;
			}
			$b = basename( (string) wp_parse_url( $u, PHP_URL_PATH ) );
			if ( isset( $seen[ $b ] ) ) {
				return;
			}
			$seen[ $b ]    = 1;
			$p['images'][] = $u;
		};
		if ( ! empty( $e['ai_image'] ) ) {
			$add( $e['ai_image'] );
		}
		foreach ( $entries as $x ) {
			$imgs = ( isset( $x['images'] ) && is_array( $x['images'] ) ) ? array_values( array_filter( $x['images'], 'is_array' ) ) : array();
			usort(
				$imgs,
				function ( $a, $b ) {
					return ( isset( $a['order'] ) ? (int) $a['order'] : 0 ) - ( isset( $b['order'] ) ? (int) $b['order'] : 0 );
				}
			);
			foreach ( $imgs as $im ) {
				$add( $im );
			}
		}
		if ( isset( $v['weight'] ) && is_numeric( $v['weight'] ) ) {
			$p['weight'] = (string) $v['weight'];
		}
		if ( isset( $v['product_attributes'] ) ) {
			$p['specs'] = self::specs( $v['product_attributes'] );
		}
		foreach ( array( isset( $v['additional_fields'] ) ? $v['additional_fields'] : null, isset( $e['additional_fields'] ) ? $e['additional_fields'] : null ) as $af ) {
			if ( self::is_assoc( $af ) ) {
				$p['specs'] = array_merge( $p['specs'], self::specs( $af ) );
			}
		}
		unset( $p['specs']['وزن'] );
		$p['categories'] = self::labels( isset( $e['product_categories'] ) ? $e['product_categories'] : array() );
		if ( ! empty( $v['is_stock_managed'] ) && isset( $v['stock_number'] ) ) {
			$p['in_stock'] = (float) $v['stock_number'] > 0;
		}
		if ( $cash > 0 ) {
			$p['price'] = (string) round( $cash );
		}
		$p['url']       = NEYPS_Settings::base() . '/product/' . $pid;
		$p['sources'][] = 'api';
		return self::finish( $p );
	}

	/** Final clean-up: categories from the title when missing, gender, unique images. */
	public static function finish( $p ) {
		$p['name'] = self::text( $p['name'] );
		$sfx       = trim( (string) NEYPS_Settings::get( 'name_suffix' ) );
		if ( '' !== $sfx && mb_strlen( $p['name'] ) > mb_strlen( $sfx ) && mb_substr( $p['name'], -mb_strlen( $sfx ) ) === $sfx ) {
			$p['name'] = trim( mb_substr( $p['name'], 0, -mb_strlen( $sfx ) ) );
		}
		if ( in_array( $p['name'], array( 'نی گلد', '' ), true ) ) {
			$p['name'] = '';
		}
		$imgs = array();
		foreach ( (array) $p['images'] as $u ) {
			$u = (string) $u;
			if ( '' === $u ) {
				continue;
			}
			if ( 0 === strpos( $u, 'file:' ) ) {
				$p['files'][] = substr( $u, 5 );
				continue;
			}
			$key = preg_replace( '~\?.*$~', '', $u );
			if ( ! isset( $imgs[ $key ] ) ) {
				$imgs[ $key ] = $u;
			}
		}
		// Do not keep bare files that are already part of a full URL.
		$files = array();
		foreach ( array_unique( (array) $p['files'] ) as $f ) {
			$dup = false;
			foreach ( $imgs as $u ) {
				if ( basename( (string) wp_parse_url( $u, PHP_URL_PATH ) ) === $f ) {
					$dup = true;
					break;
				}
			}
			if ( ! $dup ) {
				$files[] = $f;
			}
		}
		$p['images'] = array_values( $imgs );
		$p['files']  = $files;

		// Gender from specs / labels.
		$g = array();
		foreach ( array_merge( (array) $p['gender'], array_values( (array) $p['specs'] ), array( $p['name'] ) ) as $x ) {
			foreach ( array( 'زنانه', 'مردانه', 'بچگانه', 'اسپرت', 'یونیسکس' ) as $word ) {
				if ( false !== mb_strpos( (string) $x, $word ) ) {
					$g[] = $word;
				}
			}
		}
		foreach ( (array) $p['specs'] as $k => $v ) {
			if ( false !== mb_strpos( $k, 'جنسیت' ) ) {
				unset( $p['specs'][ $k ] );
			}
		}
		$p['gender'] = array_values( array_unique( $g ) );

		// Categories: source category, else the jewel type from the title ("انگشتر طلا ۱۸ عیار ..." => "انگشتر").
		$cats = array();
		foreach ( (array) $p['categories'] as $c ) {
			$c = self::text( $c );
			if ( '' !== $c && ! in_array( $c, array( 'زنانه', 'مردانه', 'بچگانه', 'محصولات' ), true ) ) {
				$cats[] = $c;
			}
		}
		if ( ! $cats && '' !== $p['name'] ) {
			$cats[] = self::type_from_title( $p['name'] );
		}
		$p['categories'] = array_values( array_unique( array_filter( $cats ) ) );

		if ( '' !== $p['weight'] ) {
			$p['weight'] = rtrim( rtrim( number_format( (float) $p['weight'], 3, '.', '' ), '0' ), '.' );
		}
		foreach ( array( 'price', 'regular' ) as $k ) {
			if ( '' !== $p[ $k ] && (float) $p[ $k ] <= 0 ) {
				$p[ $k ] = '';
			}
		}
		return $p;
	}

	/** "گوشواره طلا ۱۸ عیار گوشواره حلقه ای" => "گوشواره". */
	public static function type_from_title( $name ) {
		$name = self::text( $name );
		if ( preg_match( '/^(.+?)\s+(?:طلا|نقره|پلاتین|طلای)(?=\s|$)/u', $name, $m ) && mb_strlen( $m[1] ) <= 30 ) {
			return trim( preg_replace( '/\s*(?:زنانه|مردانه|بچگانه|دخترانه|پسرانه|یونیسکس|اسپرت)\s*/u', ' ', $m[1] ) );
		}
		$known = array( 'انگشتر', 'حلقه', 'گوشواره', 'گردنبند', 'آویز', 'دستبند', 'النگو', 'زنجیر', 'پابند', 'سرویس', 'نیم ست', 'سکه', 'پیرسینگ', 'گوشواره فلشی' );
		foreach ( $known as $k ) {
			if ( 0 === mb_strpos( $name, $k ) ) {
				return $k;
			}
		}
		$first = preg_split( '/\s+/u', $name );
		return $first ? $first[0] : '';
	}
}
