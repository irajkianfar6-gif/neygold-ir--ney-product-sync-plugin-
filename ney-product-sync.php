<?php
/**
 * Plugin Name:       NEY Product Sync - همگام‌ساز محصولات زرپلاس
 * Plugin URI:        https://github.com/irajkianfar6-gif/neygold.ir
 * Description:       استخراج کامل محصولات فروشگاه زرپلاس (پیش‌فرض: https://neygold.zar.plus) با همه تصاویر، توضیحات، مشخصات، قیمت و دسته‌بندی و انتشار آن‌ها به‌صورت محصولات ووکامرس سازگار با پوسته NEY. دکمه «بروزرسانی» فقط محصولات تازه را اضافه می‌کند.
 * Version:           2.2.0
 * Author:            NEY
 * Text Domain:       ney-product-sync
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.8
 * License:           GPL-2.0-or-later
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

define( 'NEYPS_VERSION', '2.2.0' );
define( 'NEYPS_FILE', __FILE__ );
define( 'NEYPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEYPS_URL', plugin_dir_url( __FILE__ ) );
define( 'NEYPS_CRON', 'neyps_cron_sync' );
define( 'NEYPS_CONTINUE', 'neyps_cron_continue' );
define( 'NEYPS_CAP', 'manage_woocommerce' );

foreach ( array( 'settings', 'log', 'http', 'parser', 'discovery', 'importer', 'runner' ) as $neyps_part ) {
	require_once NEYPS_DIR . 'includes/class-neyps-' . $neyps_part . '.php';
}
unset( $neyps_part );

if ( is_admin() ) {
	require_once NEYPS_DIR . 'includes/class-neyps-admin.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NEYPS_DIR . 'includes/class-neyps-cli.php';
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEYPS_FILE, true );
		}
	}
);

function neyps_activate() {
	NEYPS_Settings::install();
	neyps_schedule();
}
register_activation_hook( __FILE__, 'neyps_activate' );

function neyps_deactivate() {
	wp_clear_scheduled_hook( NEYPS_CRON );
	wp_clear_scheduled_hook( NEYPS_CONTINUE );
	delete_transient( 'neyps_lock' );
}
register_deactivation_hook( __FILE__, 'neyps_deactivate' );

/** (Re)schedule the automatic "new products" sync. */
function neyps_schedule() {
	wp_clear_scheduled_hook( NEYPS_CRON );
	$recur = (string) NEYPS_Settings::get( 'auto_sync' );
	if ( in_array( $recur, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
		wp_schedule_event( time() + 300, $recur, NEYPS_CRON );
	}
}

add_action(
	NEYPS_CRON,
	function () {
		NEYPS_Runner::run_background( 'new' );
	}
);
add_action(
	NEYPS_CONTINUE,
	function () {
		NEYPS_Runner::continue_background();
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'neyps_version' ) !== NEYPS_VERSION ) {
			NEYPS_Settings::upgrade();
			update_option( 'neyps_version', NEYPS_VERSION, false );
		}
		if ( is_admin() ) {
			NEYPS_Admin::init();
		}
		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'manage_edit-product_columns', 'neyps_product_column', 20 );
			add_action( 'manage_product_posts_custom_column', 'neyps_product_column_value', 10, 2 );
			add_action( 'woocommerce_product_options_general_product_data', 'neyps_product_source_box', 99 );
		}
	}
);

function neyps_product_column( $cols ) {
	$out = array();
	foreach ( $cols as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'name' === $k ) {
			$out['neyps'] = 'منبع';
		}
	}
	return $out;
}

function neyps_product_column_value( $col, $post_id ) {
	if ( 'neyps' !== $col ) {
		return;
	}
	$url = (string) get_post_meta( $post_id, '_neyps_url', true );
	if ( '' === $url ) {
		echo '&mdash;';
		return;
	}
	echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $url ) . '">زرپلاس #' . esc_html( (string) get_post_meta( $post_id, '_neyps_id', true ) ) . '</a>';
}

function neyps_product_source_box() {
	global $post;
	if ( ! $post ) {
		return;
	}
	$url = (string) get_post_meta( $post->ID, '_neyps_url', true );
	if ( '' === $url ) {
		return;
	}
	echo '<div class="options_group"><p class="form-field"><label>منبع زرپلاس</label><a dir="ltr" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p>';
	echo '<p class="form-field"><label>آخرین همگام‌سازی</label><span>' . esc_html( (string) get_post_meta( $post->ID, '_neyps_synced_at', true ) ) . '</span></p></div>';
}
