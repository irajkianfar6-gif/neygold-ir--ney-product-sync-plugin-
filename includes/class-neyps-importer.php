<?php
/**
 * Importer: fetches one source product and publishes it as a WooCommerce product
 * (images, gallery, categories, attributes, price, weight, NEY theme fields).
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Importer {

	public static function ready() {
		return class_exists( 'WooCommerce' ) && class_exists( 'WC_Product_Simple' );
	}

	/** Product post id for a source id (any status, trash included so deleted items are not re-added). */
	public static function find( $sid ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_neyps_id' AND pm.meta_value = %s AND p.post_type = 'product' LIMIT 1",
				(string) $sid
			)
		);
	}

	/** source id => post id, for every imported product. */
	public static function existing_map() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.meta_value AS sid, pm.post_id AS pid FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_neyps_id' AND p.post_type = 'product'",
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['sid'] ] = (int) $r['pid'];
		}
		return $out;
	}

	/** True when the server cannot reach the source store (set by the runner). */
	public static $offline = false;

	/**
	 * Fetch + parse one product. Products that already carry full data (from the zar.plus API
	 * or the browser collector) need no request to the store; only their images are downloaded.
	 */
	public static function fetch( $sid, $seed = null ) {
		$sid  = (string) $sid;
		$url  = NEYPS_Settings::base() . '/product/' . rawurlencode( $sid );
		$rich = is_array( $seed ) && ! empty( $seed['name'] ) && ! empty( $seed['images'] ) && array_intersect( array( 'api', 'browser-api' ), (array) $seed['sources'] );
		if ( $rich || self::$offline ) {
			$p = NEYPS_Parser::blank( $sid );
		} else {
			$html  = NEYPS_Http::html( $url );
			$extra = array();
			if ( '' !== $html ) {
				$rsc = NEYPS_Http::rsc( $url );
				if ( '' !== $rsc ) {
					$extra[] = $rsc;
				}
			}
			$tpl = trim( (string) NEYPS_Settings::get( 'api_detail' ) );
			if ( '' !== $tpl ) {
				$j = NEYPS_Http::json( str_replace( '{id}', rawurlencode( $sid ), $tpl ) );
				if ( $j ) {
					$extra[] = $j;
				}
			}
			$p = NEYPS_Parser::product( $sid, $html, $extra );
			if ( ( '' === $p['name'] || ! $p['images'] || ( ! $p['specs'] && '' === $p['description'] ) ) && '' !== trim( (string) NEYPS_Settings::get( 'render_url' ) ) ) {
				$r = NEYPS_Http::rendered( $url );
				if ( '' !== $r ) {
					$p              = NEYPS_Parser::merge( $p, NEYPS_Parser::product( $sid, $r ) );
					$p['sources'][] = 'render';
				}
			}
		}
		if ( is_array( $seed ) ) {
			$p = NEYPS_Parser::merge( $p, $seed );
		}
		$p['id']  = $sid;
		$p['url'] = $url;
		return NEYPS_Parser::finish( $p );
	}

	/** @return array( status, related ids ) status: created|updated|skipped|failed */
	public static function import_id( $sid, $seed = null, $mode = 'new' ) {
		if ( ! self::ready() ) {
			return array( 'failed', array() );
		}
		$existing = self::find( $sid );
		if ( $existing && ( 'new' === $mode || ! NEYPS_Settings::get( 'update_existing' ) ) ) {
			return array( 'skipped', array() );
		}
		$p = self::fetch( $sid, $seed );
		if ( '' === $p['name'] ) {
			NEYPS_Log::error( sprintf( 'محصول %s: اطلاعات قابل استخراج نبود (بخش عیب‌یابی را ببینید).', $sid ) );
			return array( 'failed', $p['related'] );
		}
		try {
			$st = self::save( $p, $existing );
		} catch ( Throwable $e ) {
			NEYPS_Log::error( sprintf( 'محصول %s: %s', $sid, $e->getMessage() ) );
			return array( 'failed', $p['related'] );
		}
		return array( $st, $p['related'] );
	}

	private static function rnd( $n ) {
		$n = (float) $n;
		$r = (int) NEYPS_Settings::get( 'price_round' );
		return $r > 1 ? (string) ( ceil( $n / $r ) * $r ) : (string) round( $n );
	}

	/** Create or update the WooCommerce product. */
	public static function save( $p, $post_id = 0 ) {
		$product = $post_id ? wc_get_product( $post_id ) : null;
		$is_new  = ! $product;
		if ( $is_new ) {
			$product = new WC_Product_Simple();
			$product->set_status( (string) NEYPS_Settings::get( 'post_status' ) );
			$product->set_catalog_visibility( 'visible' );
		}
		$product->set_name( $p['name'] );
		$product->set_description( self::description( $p ) );
		$product->set_short_description( self::short_description( $p ) );

		if ( NEYPS_Settings::get( 'import_price' ) && '' !== $p['price'] ) {
			$price = self::rnd( $p['price'] );
			if ( '' !== $p['regular'] && (float) $p['regular'] > (float) $p['price'] ) {
				$product->set_regular_price( self::rnd( $p['regular'] ) );
				$product->set_sale_price( $price );
			} else {
				$product->set_regular_price( $price );
				$product->set_sale_price( '' );
			}
		}
		$sku = '' !== $p['sku'] ? $p['sku'] : 'ZP-' . $p['id'];
		if ( $is_new || '' === $product->get_sku() ) {
			try {
				$product->set_sku( $sku );
			} catch ( Exception $e ) {
				$product->set_sku( 'ZP-' . $p['id'] );
			}
		}
		if ( '' !== $p['weight'] ) {
			$unit = get_option( 'woocommerce_weight_unit', 'kg' );
			$w    = (float) $p['weight'];
			$map  = array( 'g' => $w, 'kg' => $w / 1000, 'lbs' => $w / 453.592, 'oz' => $w / 28.3495 );
			$product->set_weight( (string) round( isset( $map[ $unit ] ) ? $map[ $unit ] : $w, 4 ) );
		}
		$product->set_manage_stock( false );
		$product->set_stock_status( false === $p['in_stock'] ? 'outofstock' : 'instock' );
		$product->set_category_ids( self::category_ids( $p ) );
		$product->set_attributes( self::attributes( $p ) );

		$product->update_meta_data( '_neyps_id', (string) $p['id'] );
		$product->update_meta_data( '_neyps_url', $p['url'] );
		$product->update_meta_data( '_neyps_synced_at', current_time( 'mysql' ) );
		$product->update_meta_data( '_neyps_sources', implode( ',', array_unique( (array) $p['sources'] ) ) );
		if ( NEYPS_Settings::get( 'theme_meta' ) ) {
			self::theme_meta( $product, $p );
		}
		$id = $product->save();

		$img_key = md5( wp_json_encode( array( $p['images'], $p['files'] ) ) );
		if ( $is_new || $img_key !== (string) $product->get_meta( '_neyps_img_key' ) || ! $product->get_image_id() ) {
			$ids = self::images( $p, $id );
			if ( $ids ) {
				$product->set_image_id( $ids[0] );
				$product->set_gallery_image_ids( array_slice( $ids, 1 ) );
				$product->update_meta_data( '_neyps_img_key', $img_key );
				$product->save();
			}
		}
		NEYPS_Log::ok(
			sprintf(
				'%s: «%s» (منبع #%s) — %d تصویر، دسته: %s',
				$is_new ? 'افزوده شد' : 'به‌روز شد',
				$p['name'],
				$p['id'],
				count( $product->get_gallery_image_ids() ) + ( $product->get_image_id() ? 1 : 0 ),
				implode( '، ', $p['categories'] )
			)
		);
		return $is_new ? 'created' : 'updated';
	}

	public static function description( $p ) {
		$d = trim( (string) $p['description'] );
		if ( '' !== $d && false === strpos( $d, '<' ) ) {
			$d = wpautop( esc_html( $d ) );
		}
		return wp_kses_post( $d );
	}

	/** Specs list (weight + every "مشخصات محصول" row) as the short description. */
	public static function short_description( $p ) {
		$rows = array();
		if ( '' !== $p['weight'] ) {
			$rows['وزن'] = $p['weight'] . ' گرم';
		}
		if ( $p['gender'] ) {
			$rows['جنسیت'] = implode( '، ', $p['gender'] );
		}
		foreach ( (array) $p['specs'] as $k => $v ) {
			if ( ! isset( $rows[ $k ] ) && 'وزن' !== $k ) {
				$rows[ $k ] = $v;
			}
		}
		if ( ! $rows ) {
			return '';
		}
		$lines = array();
		foreach ( $rows as $k => $v ) {
			$lines[] = '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v );
		}
		return implode( '<br>', $lines );
	}

	private static function term( $name, $parent = 0 ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$t = term_exists( $name, 'product_cat', (int) $parent );
		if ( ! $t ) {
			$t = wp_insert_term( $name, 'product_cat', array( 'parent' => (int) $parent ) );
			if ( is_wp_error( $t ) ) {
				$tid = (int) $t->get_error_data( 'term_exists' );
				return $tid;
			}
			if ( function_exists( 'ney_guess_type' ) ) {
				update_term_meta( (int) $t['term_id'], 'ney_type', ney_guess_type( $name ) );
			}
			update_term_meta( (int) $t['term_id'], '_neyps_cat', 1 );
		}
		return (int) ( is_array( $t ) ? $t['term_id'] : $t );
	}

	public static function category_ids( $p ) {
		$ids    = array();
		$parent = 0;
		if ( NEYPS_Settings::get( 'gender_parent' ) && $p['gender'] ) {
			$parent = self::term( $p['gender'][0] );
		}
		foreach ( (array) $p['categories'] as $c ) {
			$tid = self::term( $c, $parent );
			if ( $tid ) {
				$ids[] = $tid;
			}
		}
		if ( ! $ids && $parent ) {
			$ids[] = $parent;
		}
		if ( ! $ids ) {
			$ids[] = (int) get_option( 'default_product_cat', 0 );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** Visible custom attributes: جنسیت + every spec row. */
	public static function attributes( $p ) {
		$rows = array();
		if ( NEYPS_Settings::get( 'gender_attr' ) && $p['gender'] ) {
			$rows['جنسیت'] = $p['gender'];
		}
		if ( '' !== $p['weight'] ) {
			$rows['وزن (گرم)'] = array( $p['weight'] );
		}
		foreach ( (array) $p['specs'] as $k => $v ) {
			if ( mb_strlen( $k ) <= 60 && '' !== trim( (string) $v ) ) {
				$rows[ $k ] = array( (string) $v );
			}
		}
		$out = array();
		$pos = 0;
		foreach ( $rows as $name => $vals ) {
			$a = new WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( wc_clean( $name ) );
			$a->set_options( array_map( 'wc_clean', $vals ) );
			$a->set_position( $pos++ );
			$a->set_visible( true );
			$a->set_variation( false );
			$out[ sanitize_title( $name ) ? sanitize_title( $name ) : 'attr-' . $pos ] = $a;
		}
		return $out;
	}

	/** Fields of the NEY theme (inc/woo.php) so products render with photos, weight and sizes. */
	private static function theme_meta( $product, $p ) {
		$hay  = implode( ' ', $p['categories'] ) . ' ' . $p['name'];
		$type = function_exists( 'ney_guess_type' ) ? ney_guess_type( $hay ) : '';
		if ( '' !== $type ) {
			$product->update_meta_data( '_ney_type', $type );
		}
		$product->update_meta_data( '_ney_img', 'photo' );
		if ( '' !== $p['weight'] ) {
			$product->update_meta_data( '_ney_weight', $p['weight'] );
		}
		if ( preg_match( '/(۱۸|18)\s*عیار/u', $p['name'], $m ) ) {
			$product->update_meta_data( '_ney_material', 'طلای ' . $m[1] . ' عیار' );
		}
		$gold = 'y';
		if ( preg_match( '/رز\s*گلد|صورتی/u', $hay ) ) {
			$gold = 'r';
		} elseif ( preg_match( '/سفید|وایت/u', $hay ) ) {
			$gold = 'w';
		}
		$product->update_meta_data( '_ney_gold', $gold );
		$product->update_meta_data( '_ney_golds', $gold );
		foreach ( (array) $p['specs'] as $k => $v ) {
			if ( false !== mb_strpos( $k, 'سایز' ) ) {
				$product->update_meta_data( '_ney_optn', $k );
				$product->update_meta_data( '_ney_options', str_replace( array( '،', '/' ), ',', $v ) );
				break;
			}
		}
	}

	/* --------------------------------------------------------------- images */

	/** Download every image (featured first) into the media library; returns attachment ids. */
	public static function images( $p, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$urls = (array) $p['images'];
		foreach ( (array) $p['files'] as $f ) {
			$u = self::resolve_file( $f, $p );
			if ( '' !== $u ) {
				$urls[] = $u;
			}
		}
		$urls = array_slice( array_values( array_unique( $urls ) ), 0, (int) NEYPS_Settings::get( 'max_images' ) );
		$ids  = array();
		foreach ( $urls as $i => $u ) {
			$aid = self::attachment( $u, $post_id, $p['name'] . ( $i ? ' - ' . ( $i + 1 ) : '' ) );
			if ( $aid ) {
				$ids[] = $aid;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** A bare file name (from JSON) -> full URL, by trying the product's storage folders. */
	private static function resolve_file( $file, $p ) {
		$dirs = array();
		foreach ( (array) $p['images'] as $u ) {
			$dirs[] = dirname( preg_replace( '~\?.*$~', '', $u ) );
			if ( preg_match( '~/talasis/(\d+)/~', $u, $m ) ) {
				$dirs[] = NEYPS_Parser::ARVAN . 'talasis/' . $m[1] . '/' . $p['id'];
			}
		}
		foreach ( array_unique( $dirs ) as $d ) {
			$u = rtrim( $d, '/' ) . '/' . rawurlencode( $file );
			if ( NEYPS_Http::exists( $u ) ) {
				return $u;
			}
		}
		return '';
	}

	private static function attachment( $url, $post_id, $title ) {
		global $wpdb;
		$key = preg_replace( '~\?.*$~', '', $url );
		$aid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_neyps_src' AND meta_value = %s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $aid && get_post( $aid ) ) {
			return $aid;
		}
		$timeout = (int) NEYPS_Settings::get( 'timeout' ) + 30;
		$tmp     = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			// Fallback through the store's own image proxy.
			$proxy = NEYPS_Settings::base() . '/_next/image?url=' . rawurlencode( $url ) . '&w=3840&q=100';
			$tmp   = download_url( $proxy, $timeout );
		}
		if ( is_wp_error( $tmp ) ) {
			NEYPS_Log::warn( 'دانلود تصویر ناموفق: ' . $url . ' (' . $tmp->get_error_message() . ')' );
			return 0;
		}
		$name = basename( (string) wp_parse_url( $key, PHP_URL_PATH ) );
		if ( ! preg_match( '~\.(jpe?g|png|gif|webp|avif)$~i', $name ) ) {
			$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $tmp ) : '';
			$ext  = array( 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' );
			$name = ( $name ? $name : 'zp-' . md5( $url ) ) . '.' . ( isset( $ext[ $mime ] ) ? $ext[ $mime ] : 'jpg' );
		}
		$file = array(
			'name'     => sanitize_file_name( 'zp-' . substr( md5( $key ), 0, 8 ) . '-' . $name ),
			'tmp_name' => $tmp,
		);
		$aid  = media_handle_sideload( $file, $post_id, $title );
		if ( is_wp_error( $aid ) ) {
			wp_delete_file( $tmp );
			NEYPS_Log::warn( 'ذخیره تصویر ناموفق: ' . $url . ' (' . $aid->get_error_message() . ')' );
			return 0;
		}
		update_post_meta( $aid, '_neyps_src', $key );
		update_post_meta( $aid, '_wp_attachment_image_alt', $title );
		return (int) $aid;
	}
}
