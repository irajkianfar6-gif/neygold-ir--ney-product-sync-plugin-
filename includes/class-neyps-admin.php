<?php
/**
 * Admin: dashboard (full import / update / stop + live progress), browser collector,
 * diagnostics, settings and log.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Admin {

	const SLUG = 'ney-product-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_neyps_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		foreach ( array( 'start', 'step', 'stop', 'test', 'detect', 'collect', 'clear_log' ) as $a ) {
			add_action( 'wp_ajax_neyps_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( NEYPS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">همگام‌سازی</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( 'همگام‌ساز زرپلاس', 'همگام‌ساز زرپلاس', NEYPS_CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-update', 56 );
	}

	public static function notices() {
		if ( ! current_user_can( NEYPS_CAP ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>همگام‌ساز زرپلاس برای انتشار محصولات به افزونه WooCommerce نیاز دارد.</p></div>';
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-product' === $screen->id ) {
			echo '<div class="notice notice-info"><p><strong>زرپلاس:</strong> <a class="button button-primary" href="' . esc_url( self::url( array( 'autorun' => 'new' ) ) ) . '">بروزرسانی — افزودن جدیدترین محصولات</a> <a class="button" href="' . esc_url( self::url() ) . '">پیشخوان همگام‌ساز</a></p></div>';
		}
	}

	/* --------------------------------------------------------------- AJAX */

	private static function guard() {
		check_ajax_referer( 'neyps', 'nonce' );
		if ( ! current_user_can( NEYPS_CAP ) ) {
			wp_send_json_error( array( 'msg' => 'دسترسی مجاز نیست.' ), 403 );
		}
		if ( ! NEYPS_Importer::ready() ) {
			wp_send_json_error( array( 'msg' => 'ابتدا WooCommerce را فعال کنید.' ) );
		}
	}

	private static function long() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public static function summary( $job = null ) {
		$job = $job ? $job : NEYPS_Runner::state();
		$out = array(
			'phase'    => $job ? $job['phase'] : 'idle',
			'mode'     => $job ? $job['mode'] : '',
			'tasks'    => $job ? count( $job['tasks'] ) : 0,
			'ti'       => $job ? (int) $job['ti'] : 0,
			'ids'      => $job ? count( $job['ids'] ) : 0,
			'queue'    => $job ? count( $job['queue'] ) : 0,
			'qi'       => $job ? (int) $job['qi'] : 0,
			'stats'    => $job ? $job['stats'] : array(),
			'imported' => count( NEYPS_Importer::existing_map() ),
			'log'      => array_slice( NEYPS_Log::recent( 14 ), 0, 14 ),
		);
		return $out;
	}

	public static function ajax_start() {
		self::guard();
		self::long();
		$mode = isset( $_POST['mode'] ) && 'all' === $_POST['mode'] ? 'all' : 'new';
		if ( NEYPS_Runner::running() ) {
			wp_send_json_success( self::summary() );
		}
		wp_send_json_success( self::summary( NEYPS_Runner::start( $mode ) ) );
	}

	public static function ajax_step() {
		self::guard();
		self::long();
		$job = NEYPS_Runner::step( 18, (int) NEYPS_Settings::get( 'batch' ) );
		wp_send_json_success( self::summary( $job ) );
	}

	public static function ajax_stop() {
		self::guard();
		self::long();
		NEYPS_Runner::stop();
		wp_send_json_success( self::summary() );
	}

	public static function ajax_clear_log() {
		self::guard();
		self::long();
		NEYPS_Log::clear();
		wp_send_json_success( self::summary() );
	}

	public static function ajax_test() {
		self::guard();
		self::long();
		$id = isset( $_POST['id'] ) && preg_match( '~(\d{2,12})~', sanitize_text_field( wp_unslash( $_POST['id'] ) ), $m ) ? $m[1] : '';
		if ( '' === $id ) {
			$list = NEYPS_Parser::listing( NEYPS_Http::html( NEYPS_Settings::base() . '/products' ) );
			$id   = $list['ids'] ? $list['ids'][0] : '';
		}
		if ( '' === $id ) {
			wp_send_json_error( array( 'msg' => 'هیچ محصولی در فروشگاه منبع پیدا نشد. نشانی منبع و دسترسی سرور به آن را بررسی کنید.' ) );
		}
		$p = NEYPS_Importer::fetch( $id );
		unset( $p['related'] );
		wp_send_json_success( array( 'product' => $p ) );
	}

	public static function ajax_detect() {
		self::guard();
		self::long();
		$rep = NEYPS_Discovery::detect();
		$set = NEYPS_Settings::all();
		if ( $rep['list_api'] && '' === $set['api_list'] ) {
			$set['api_list'] = $rep['list_api'];
		}
		if ( $rep['detail_api'] && '' === $set['api_detail'] ) {
			$set['api_detail'] = $rep['detail_api'];
		}
		update_option( NEYPS_Settings::OPTION, $set, false );
		wp_send_json_success( $rep );
	}

	/**
	 * Data from the browser collector (v2: ids + cards + full API items; v1: ids + cards).
	 * Nothing here contacts the source store, so it works even when the server is blocked.
	 */
	public static function ajax_collect() {
		self::guard();
		self::long();
		$raw  = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, validated below.
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			preg_match_all( '~(?:/product/)?(\d{3,12})~', (string) $raw, $m );
			$data = array( 'ids' => $m[1] );
		}
		$items = ( isset( $data['items'] ) && is_array( $data['items'] ) ) ? $data['items'] : array();
		$cards = ( isset( $data['cards'] ) && is_array( $data['cards'] ) ) ? $data['cards'] : array();
		$ids   = array();
		foreach ( (array) ( isset( $data['ids'] ) ? $data['ids'] : array() ) as $id ) {
			if ( preg_match( '/^\d{2,12}$/', (string) $id ) ) {
				$ids[] = (string) $id;
			}
		}
		$seeds = array();
		$full  = 0;
		foreach ( array_unique( array_map( 'strval', array_merge( array_keys( $items ), array_keys( $cards ) ) ) ) as $id ) {
			if ( ! preg_match( '/^\d{2,12}$/', $id ) ) {
				continue;
			}
			$c  = ( isset( $cards[ $id ] ) && is_array( $cards[ $id ] ) ) ? $cards[ $id ] : array();
			$it = ( isset( $items[ $id ] ) && is_array( $items[ $id ] ) ) ? $items[ $id ] : array();
			$s  = NEYPS_Parser::blank( $id );
			$s['name'] = NEYPS_Parser::text( ! empty( $c['n'] ) ? $c['n'] : ( isset( $it['n'] ) ? $it['n'] : '' ) );
			if ( $it ) {
				$s['description'] = ( isset( $it['d'] ) && is_string( $it['d'] ) ) ? wp_kses_post( $it['d'] ) : '';
				foreach ( (array) ( isset( $it['im'] ) ? $it['im'] : array() ) as $u ) {
					$u = NEYPS_Parser::img( is_string( $u ) ? $u : '' );
					if ( '' !== $u && 0 !== strpos( $u, 'file:' ) ) {
						$s['images'][] = esc_url_raw( $u );
					}
				}
				$s['weight']     = isset( $it['w'] ) ? NEYPS_Parser::num( (string) $it['w'] ) : '';
				$s['specs']      = NEYPS_Parser::specs( isset( $it['sp'] ) ? $it['sp'] : array() );
				$s['categories'] = NEYPS_Parser::labels( isset( $it['c'] ) ? $it['c'] : array() );
				if ( isset( $it['s'] ) ) {
					$s['in_stock'] = (bool) $it['s'];
				}
				$s['price']     = isset( $it['cp'] ) ? NEYPS_Parser::num( (string) $it['cp'] ) : '';
				$s['sources'][] = 'browser-api';
				++$full;
			}
			if ( $c ) {
				$img = NEYPS_Parser::img( isset( $c['i'] ) ? (string) $c['i'] : '' );
				if ( '' !== $img && 0 !== strpos( $img, 'file:' ) ) {
					$s['images'][] = esc_url_raw( $img );
				}
				$t = NEYPS_Parser::en( NEYPS_Parser::text( isset( $c['t'] ) ? $c['t'] : '' ) );
				if ( '' === $s['weight'] && preg_match( '/([\d.]+)\s*گرم/u', $t, $mm ) ) {
					$s['weight'] = $mm[1];
				}
				if ( preg_match( '/([\d,]{4,})\s*تومان/u', $t, $mm ) ) {
					$s['price'] = str_replace( ',', '', $mm[1] ); // cash price shown on the store.
				}
				$s['sources'][] = 'browser';
			}
			unset( $s['specs']['وزن'] );
			$s['images']  = array_values( array_unique( $s['images'] ) );
			$seeds[ $id ] = NEYPS_Parser::finish( $s );
			$ids[]        = $id;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) {
			wp_send_json_error( array( 'msg' => 'داده معتبری پیدا نشد. متن کامل نتیجه بوکمارک یا فایل دانلودشده را وارد کنید.' ) );
		}
		$prev = get_option( 'neyps_collected', array() );
		$all  = array(
			'ids'   => array_values( array_unique( array_merge( isset( $prev['ids'] ) ? (array) $prev['ids'] : array(), $ids ) ) ),
			'seeds' => array_replace( isset( $prev['seeds'] ) ? (array) $prev['seeds'] : array(), $seeds ),
		);
		update_option( 'neyps_collected', $all, false );
		$set = NEYPS_Settings::all();
		foreach ( (array) ( isset( $data['api'] ) ? $data['api'] : array() ) as $u ) {
			$u = (string) $u;
			if ( false !== strpos( $u, '/products' ) && false === strpos( $u, '_rsc' ) && preg_match( '~[?&]page=\d+~', $u ) && preg_match( '~^https://~', $u ) ) {
				$set['api_list'] = preg_replace( '~([?&]page=)\d+~', '${1}{page}', esc_url_raw( $u ), 1 );
				update_option( NEYPS_Settings::OPTION, $set, false );
				break;
			}
		}
		$msg = sprintf( '%d محصول ذخیره شد (%d با جزئیات کامل، مجموع آماده انتشار: %d). اکنون «رفتن به پیشخوان و شروع انتشار» را بزنید.', count( $ids ), $full, count( $all['ids'] ) );
		if ( ! $full ) {
			$msg .= "\n" . 'توجه: این داده فقط نام، تصویر اصلی، وزن و قیمت را دارد. برای توضیحات، مشخصات و همه تصاویر، دکمه بوکمارک جدید این صفحه را دوباره اجرا کنید.';
		}
		NEYPS_Log::info( $msg );
		wp_send_json_success( array( 'msg' => $msg ) );
	}

	public static function save_settings() {
		if ( ! current_user_can( NEYPS_CAP ) ) {
			wp_die( 'دسترسی مجاز نیست.' );
		}
		check_admin_referer( 'neyps_save' );
		$in = isset( $_POST['neyps'] ) ? (array) wp_unslash( $_POST['neyps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in NEYPS_Settings::sanitize().
		NEYPS_Settings::save( $in );
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'saved' => 1 ) ) );
		exit;
	}

	/** Browser collector v2: reads the whole listing, then full product data from the store's own API. */
	public static function bookmarklet() {
		$js = <<<'JS'
(async()=>{const D=location.hostname.split('.')[0],S=new Set(),C={},P={};const box=document.createElement('div');box.style.cssText='position:fixed;z-index:2147483647;top:12px;left:12px;background:#1d2327;color:#fff;padding:14px;border-radius:10px;font:13px/1.9 tahoma;direction:rtl;max-width:480px;box-shadow:0 6px 24px #0008';document.body.appendChild(box);const say=t=>{box.textContent=t};const sleep=ms=>new Promise(r=>setTimeout(r,ms));const grab=()=>{document.querySelectorAll('a[href*="/product/"]').forEach(a=>{const m=a.href.match(/\/product\/(\d+)/);if(!m)return;const id=m[1];S.add(id);if(!C[id]){const im=a.querySelector('img');C[id]={n:im?(im.alt||'').replace(/^تصویر\s+/,''):'',i:im?(im.currentSrc||im.src||''):'',t:(a.innerText||'').replace(/\s+/g,' ').trim()}}})};if(/^\/products/.test(location.pathname)){let last=-1,same=0;while(same<7){grab();say('مرحله ۱ از ۲: خواندن فهرست… '+S.size+' محصول');window.scrollTo(0,document.documentElement.scrollHeight);await sleep(1300);if(S.size===last)same++;else{same=0;last=S.size}}}else{grab()}const pack=g=>{const es=[];Object.keys(g||{}).forEach(k=>{let l=g[k];if(!Array.isArray(l))l=[l];l.forEach(e=>{if(e&&e.name)es.push(e)})});if(!es.length)return null;const e=es[0],v=(e.product_variants||[])[0]||{};let cp=0;es.forEach(x=>{const y=(x.product_variants||[])[0]||{};if(y.price>0){const c=y.price/(1+(y.hnk_gold_gateway_percent||0)/100);if(!cp||c<cp)cp=c}});const im=[],seen={};const add=u=>{if(!u)return;const b=String(u).split('?')[0].split('/').pop();if(seen[b])return;seen[b]=1;im.push(u)};add(e.ai_image);es.forEach(x=>(x.images||[]).slice().sort((a,b)=>(a.order||0)-(b.order||0)).forEach(i=>add(i&&i.url)));const sp={};(v.product_attributes||[]).forEach(a=>{if(a&&a.name&&a.value!=null)sp[a.name]=String(a.value)});[v.additional_fields,e.additional_fields].forEach(f=>{if(f&&typeof f==='object'&&!Array.isArray(f))Object.keys(f).forEach(k=>{if(f[k]!=null&&f[k]!=='')sp[k]=String(f[k])})});const cats=(e.product_categories||[]).map(c=>c&&(c.name||c.title)).filter(Boolean);return{n:e.name,d:e.description||'',im:im,w:v.weight||'',sp:sp,c:cats,s:v.is_stock_managed?(v.stock_number>0?1:0):1,cp:Math.round(cp)}};let apiErr='';for(let pg=1;pg<=60;pg++){say('مرحله ۲ از ۲: دریافت جزئیات کامل… صفحه '+pg+' ('+Object.keys(P).length+' محصول)');let j=null;try{const r=await fetch('https://api.admin.zar.plus/api/mini-app/products?domain='+encodeURIComponent(D)+'&page='+pg+'&per_page=100&in_stock=1');j=await r.json()}catch(err){apiErr=String(err);break}const d=j&&j.data;if(!d||typeof d!=='object'||Array.isArray(d))break;let nw=0;Object.keys(d).forEach(k=>{if(!P[k]){const x=pack(d[k]);if(x){P[k]=x;S.add(k);nw++}}});if(!nw)break}const api=[...new Set(performance.getEntriesByType('resource').filter(e=>e.initiatorType==='fetch'||e.initiatorType==='xmlhttprequest').map(e=>e.name).filter(u=>u.indexOf('_rsc=')<0))];const out=JSON.stringify({v:2,ids:[...S],cards:C,items:P,api:api});let copied=false;try{await navigator.clipboard.writeText(out);copied=true}catch(err){}box.innerHTML='';const p=document.createElement('div');p.textContent=S.size+' محصول پیدا شد؛ '+Object.keys(P).length+' محصول با جزئیات کامل. '+(copied?'متن نتیجه کپی شد. ':'')+'فایل نتیجه را دانلود کنید و در افزونه (تب جمع‌آوری از مرورگر) انتخاب کنید.'+(apiErr?' (خطای API: '+apiErr+')':'');const t=document.createElement('textarea');t.value=out;t.style.cssText='width:100%;height:110px;direction:ltr;margin:8px 0';const dl=document.createElement('a');dl.textContent='دانلود فایل نتیجه';dl.href=URL.createObjectURL(new Blob([out],{type:'text/plain'}));dl.download='zarplus-products.txt';dl.style.cssText='display:inline-block;background:#2271b1;color:#fff;padding:6px 12px;border-radius:4px;margin-left:8px;text-decoration:none';const x=document.createElement('button');x.textContent='بستن';x.onclick=()=>box.remove();box.append(p,t,dl,x);dl.click()})();
JS;
		return 'javascript:' . rawurlencode( $js );
	}

	/* --------------------------------------------------------------- page */

	public static function page() {
		if ( ! current_user_can( NEYPS_CAP ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dash'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'dash'     => 'پیشخوان',
			'collect'  => 'جمع‌آوری از مرورگر',
			'test'     => 'عیب‌یابی',
			'settings' => 'تنظیمات',
		);
		$s    = NEYPS_Settings::all();
		$last = get_option( 'neyps_last_run' );
		echo '<div class="wrap neyps" dir="rtl"><h1>همگام‌ساز محصولات زرپلاس → ووکامرس</h1>';
		echo '<p>منبع: <a dir="ltr" href="' . esc_url( NEYPS_Settings::base() ) . '" target="_blank" rel="noopener">' . esc_html( NEYPS_Settings::base() ) . '</a></p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav><div class="neyps-body">';
		if ( 'settings' === $tab ) {
			self::tab_settings( $s );
		} elseif ( 'collect' === $tab ) {
			echo '<h2>جمع‌آوری کامل محصولات با مرورگر شما</h2>';
			echo '<p>این روش حتی وقتی سرور شما به زرپلاس دسترسی ندارد کار می‌کند: مرورگر شما فهرست را تا انتها می‌خواند و جزئیات کامل هر محصول (توضیحات، مشخصات، دسته و همه تصاویر) را از API خود فروشگاه می‌گیرد. سرور شما فقط تصاویر را دانلود می‌کند.</p><ol>';
			echo '<li>نوار بوکمارک مرورگر را نمایش دهید (Ctrl+Shift+B) و این دکمه را با ماوس روی آن بکشید: <a class="button button-primary" href="' . esc_attr( self::bookmarklet() ) . '" onclick="alert(\'این دکمه را به نوار بوکمارک بکشید و در صفحه محصولات زرپلاس اجرا کنید.\');return false;">جمع‌آوری زرپلاس (نسخه ۲)</a><br>اگر کشیدن نشد: روی دکمه راست‌کلیک ← «کپی آدرس لینک»، سپس روی نوار بوکمارک راست‌کلیک ← «افزودن صفحه» و آدرس را در URL بچسبانید. اگر نسخه قبلی بوکمارک را دارید، آن را حذف و این را جایگزین کنید.</li>';
			echo '<li>صفحه <a dir="ltr" target="_blank" rel="noopener" href="' . esc_url( NEYPS_Settings::base() . '/products' ) . '">' . esc_html( NEYPS_Settings::base() . '/products' ) . '</a> را باز کنید، صبر کنید محصولات نمایش داده شوند و روی بوکمارک کلیک کنید. تا پایان «مرحله ۲ از ۲» به صفحه دست نزنید. در پایان فایل zarplus-products.txt دانلود می‌شود.</li>';
			echo '<li>همین‌جا آن فایل را انتخاب کنید (یا متن نتیجه را در کادر بچسبانید) و «ذخیره داده‌های جمع‌آوری‌شده» را بزنید.</li>';
			echo '<li>سپس «رفتن به پیشخوان و شروع انتشار» را بزنید و تا پایان کار صفحه را باز نگه دارید.</li></ol>';
			echo '<p><label><strong>فایل نتیجه:</strong> <input type="file" id="neyps-file" accept=".txt,.json,text/plain,application/json"></label></p>';
			echo '<textarea id="neyps-collect" dir="ltr" style="width:100%;height:160px" placeholder="{&quot;v&quot;:2,&quot;ids&quot;:[...]}"></textarea>';
			echo '<p><button class="button button-primary" id="neyps-collect-btn">ذخیره داده‌های جمع‌آوری‌شده</button> <a class="button" href="' . esc_url( self::url( array( 'autorun' => 'new' ) ) ) . '">رفتن به پیشخوان و شروع انتشار</a></p><pre id="neyps-out" class="neyps-pre"></pre>';
		} elseif ( 'test' === $tab ) {
			echo '<h2>آزمایش استخراج یک محصول (بدون ذخیره)</h2>';
			echo '<p><input type="text" id="neyps-test-id" dir="ltr" class="regular-text" placeholder="262368 یا https://neygold.zar.plus/product/262368"> <button class="button button-primary" id="neyps-test-btn">آزمایش</button> <button class="button" id="neyps-detect-btn">شناسایی خودکار API فروشگاه</button></p>';
			echo '<pre id="neyps-out" class="neyps-pre"></pre>';
		} else {
			echo '<div class="neyps-cards"><div><b id="neyps-imported">' . esc_html( (string) count( NEYPS_Importer::existing_map() ) ) . '</b><span>محصول استخراج‌شده در این سایت</span></div>';
			echo '<div><b>' . esc_html( is_array( $last ) ? wp_date( 'Y/m/d H:i', $last['time'] ) : '—' ) . '</b><span>آخرین اجرا</span></div>';
			echo '<div><b>' . esc_html( 'off' === $s['auto_sync'] ? 'خاموش' : $s['auto_sync'] ) . '</b><span>بروزرسانی خودکار</span></div></div>';
			echo '<p class="neyps-actions"><button class="button button-primary button-hero" data-mode="new" id="neyps-new">بروزرسانی — افزودن جدیدترین محصولات</button> ';
			echo '<button class="button button-hero" data-mode="all" id="neyps-all">استخراج و انتشار همه محصولات</button> ';
			echo '<button class="button button-link-delete" id="neyps-stop">توقف</button></p>';
			echo '<p class="description">«بروزرسانی» فقط محصولاتی را اضافه می‌کند که هنوز در این سایت نیستند. «استخراج همه» همه را بررسی می‌کند و اگر «به‌روزرسانی موجودها» روشن باشد، قیمت، توضیحات و تصاویر محصولات قبلی را هم به‌روز می‌کند. اگر صفحه را ببندید، با بازگشت ادامه می‌دهد.</p>';
			echo '<div class="neyps-progress"><div id="neyps-bar"></div></div><p id="neyps-status"></p>';
			echo '<h2>گزارش عملیات <button class="button button-small" id="neyps-clear">پاک کردن</button></h2><ul id="neyps-log" class="neyps-log">';
			foreach ( NEYPS_Log::recent( 60 ) as $l ) {
				echo '<li class="l-' . esc_attr( $l['l'] ) . '"><time>' . esc_html( $l['t'] ) . '</time> ' . esc_html( $l['m'] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div></div>';
		self::assets( $tab );
	}

	private static function tab_settings( $s ) {
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
		}
		$row = function ( $label, $html, $help = '' ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$txt = function ( $k, $ltr = true ) use ( $s ) {
			return '<input type="text" class="large-text"' . ( $ltr ? ' dir="ltr"' : '' ) . ' name="neyps[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '">';
		};
		$num = function ( $k ) use ( $s ) {
			return '<input type="number" class="small-text" name="neyps[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '">';
		};
		$chk = function ( $k, $label ) use ( $s ) {
			return '<label><input type="checkbox" name="neyps[' . esc_attr( $k ) . ']" value="1"' . checked( 1, (int) $s[ $k ], false ) . '> ' . esc_html( $label ) . '</label>';
		};
		$sel = function ( $k, $opts ) use ( $s ) {
			$h = '<select name="neyps[' . esc_attr( $k ) . ']">';
			foreach ( $opts as $v => $l ) {
				$h .= '<option value="' . esc_attr( $v ) . '"' . selected( $s[ $k ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			return $h . '</select>';
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="neyps_save">';
		wp_nonce_field( 'neyps_save' );
		echo '<h2>منبع</h2><table class="form-table">';
		$row( 'نشانی فروشگاه منبع', $txt( 'source_url' ), 'پیش‌فرض: https://neygold.zar.plus' );
		$row( 'حذف از انتهای نام محصول', $txt( 'name_suffix', false ), 'زرپلاس نام فروشگاه را به انتهای نام محصولات در API اضافه می‌کند.' );
		$row( 'صفحات فهرست محصولات', '<textarea class="large-text" rows="5" dir="ltr" name="neyps[list_paths]">' . esc_textarea( $s['list_paths'] ) . '</textarea>', 'هر خط یک مسیر؛ صفحه‌بندی (page=2,3,...) خودکار امتحان می‌شود.' );
		$row( 'API فهرست محصولات', $txt( 'api_list' ), 'اختیاری؛ با {page} یا {offset}. با عیب‌یابی یا جمع‌آوری از مرورگر خودکار پر می‌شود.' );
		$row( 'API جزئیات محصول', $txt( 'api_detail' ), 'اختیاری؛ با {id}.' );
		$row( 'سرویس رندر (مرورگر بی‌سر)', $txt( 'render_url' ), 'اختیاری؛ برای صفحاتی که محتوا را با جاوااسکریپت بار می‌کنند. مثال: https://r.jina.ai/{raw} یا آدرس Browserless خودتان با {url}.' );
		$row( 'کشف از محصولات مرتبط', $chk( 'crawl_related', 'محصولات مرتبط هر محصول هم دنبال شوند' ) );
		$row( 'شناسه/لینک محصولات اضافه', '<textarea class="large-text" rows="3" dir="ltr" name="neyps[extra_ids]">' . esc_textarea( $s['extra_ids'] ) . '</textarea>' );
		$row( 'حداکثر صفحات هر فهرست', $num( 'max_pages' ) );
		echo '</table><h2>انتشار</h2><table class="form-table">';
		$row( 'وضعیت محصولات جدید', $sel( 'post_status', array( 'publish' => 'منتشر شده', 'draft' => 'پیش‌نویس', 'pending' => 'در انتظار بررسی' ) ) );
		$row( 'قیمت', $chk( 'import_price', 'قیمت نقدی منبع (تومان) وارد شود' ) . ' &nbsp; گرد کردن به بالا تا: ' . $num( 'price_round' ), 'واحد پول ووکامرس باید تومان باشد. قیمت منبع لحظه‌ای است؛ با «استخراج همه» و گزینه بعدی به‌روز می‌شود.' );
		$row( 'محصولات موجود', $chk( 'update_existing', 'در «استخراج همه» محصولات قبلی هم به‌روز شوند' ) );
		$row( 'تصاویر', 'حداکثر ' . $num( 'max_images' ) . ' تصویر برای هر محصول (اولی = تصویر شاخص، بقیه = گالری)' );
		$row( 'جنسیت', $chk( 'gender_attr', 'به‌صورت ویژگی محصول' ) . '<br>' . $chk( 'gender_parent', 'دسته مادر (زنانه/مردانه/بچگانه) بالای دسته محصول' ) );
		$row( 'پوسته NEY', $chk( 'theme_meta', 'فیلدهای «تنظیمات نمایش NEY» پر شوند (نوع جواهر، نمایش عکس، وزن، جنس، سایز)' ) );
		echo '</table><h2>اجرا</h2><table class="form-table">';
		$row( 'بروزرسانی خودکار', $sel( 'auto_sync', array( 'off' => 'خاموش (فقط دستی)', 'hourly' => 'هر ساعت', 'twicedaily' => 'روزی دو بار', 'daily' => 'روزانه' ) ), 'با WP-Cron فقط محصولات جدید اضافه می‌شوند.' );
		$row( 'محصول در هر گام', $num( 'batch' ) );
		$row( 'مهلت اتصال (ثانیه)', $num( 'timeout' ) );
		$row( 'مکث بین درخواست‌ها (میلی‌ثانیه)', $num( 'delay_ms' ) );
		echo '</table>';
		submit_button( 'ذخیره تنظیمات' );
		echo '</form>';
	}

	private static function assets( $tab ) {
		$cfg = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neyps' ),
			'autorun' => isset( $_GET['autorun'] ) && 'new' === $_GET['autorun'] ? 'new' : '', // phpcs:ignore WordPress.Security.NonceVerification
			'running' => NEYPS_Runner::running(),
		);
		?>
<style>
.neyps .neyps-body{background:#fff;border:1px solid #dcdcde;border-top:0;padding:16px 20px}
.neyps-cards{display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 16px}
.neyps-cards>div{border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:170px;background:#f6f7f7}
.neyps-cards b{display:block;font-size:22px;line-height:1.6}
.neyps-actions .button-hero{margin-left:8px}
.neyps-progress{height:14px;background:#f0f0f1;border-radius:7px;overflow:hidden;margin:14px 0 6px}
#neyps-bar{height:100%;width:0;background:linear-gradient(90deg,#d4a017,#f5c842);transition:width .4s}
.neyps-log{max-height:420px;overflow:auto;background:#1d2327;color:#e0e0e0;padding:10px 14px;border-radius:6px;font-size:12px;line-height:1.9}
.neyps-log time{color:#8c8f94;direction:ltr;display:inline-block;margin-left:6px}
.neyps-log .l-ok{color:#7ad03a}.neyps-log .l-warn{color:#f0b849}.neyps-log .l-error{color:#ff6b6b}
.neyps-pre{direction:ltr;text-align:left;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-height:560px;overflow:auto;white-space:pre-wrap}
</style>
<script>
(function(){
var C=<?php echo wp_json_encode( $cfg ); ?>,busy=false,stopFlag=false,$=function(i){return document.getElementById(i)};
function post(action,data){var f=new FormData();f.append('action','neyps_'+action);f.append('nonce',C.nonce);for(var k in (data||{}))f.append(k,data[k]);return fetch(C.ajax,{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json()});}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function render(s){if(!s)return;var pct=0,txt='';
if(s.phase==='discover'){pct=s.tasks?Math.round(s.ti/s.tasks*15):0;txt='در حال کشف محصولات… '+s.ids+' شناسه پیدا شد';}
else if(s.phase==='import'){pct=15+(s.queue?Math.round(s.qi/s.queue*85):85);txt='در حال استخراج و انتشار: '+s.qi+' از '+s.queue;}
else if(s.phase==='done'){pct=100;txt='پایان عملیات.';}
if(s.stats&&s.stats.found!==undefined){txt+=' | جدید: '+s.stats.created+' | به‌روز: '+s.stats.updated+' | موجود: '+s.stats.skipped+' | ناموفق: '+s.stats.failed;}
if($('neyps-bar'))$('neyps-bar').style.width=pct+'%';if($('neyps-status'))$('neyps-status').textContent=txt;if($('neyps-imported'))$('neyps-imported').textContent=s.imported;
if($('neyps-log')&&s.log){$('neyps-log').innerHTML=s.log.map(function(l){return '<li class="l-'+esc(l.l)+'"><time>'+esc(l.t)+'</time> '+esc(l.m)+'</li>'}).join('');}}
function lock(on){['neyps-new','neyps-all'].forEach(function(i){if($(i))$(i).disabled=on;});}
function loop(){if(stopFlag){busy=false;lock(false);return;}post('step').then(function(r){if(!r.success){alert(r.data&&r.data.msg||'خطا');busy=false;lock(false);return;}render(r.data);if(r.data.phase==='done'||r.data.phase==='idle'){busy=false;lock(false);return;}setTimeout(loop,400);}).catch(function(){setTimeout(loop,4000);});}
function start(mode){if(busy)return;if(mode==='all'&&!confirm('همه محصولات فروشگاه منبع استخراج و منتشر شوند؟'))return;busy=true;stopFlag=false;lock(true);post('start',{mode:mode}).then(function(r){if(!r.success){alert(r.data&&r.data.msg||'خطا');busy=false;lock(false);return;}render(r.data);loop();});}
['neyps-new','neyps-all'].forEach(function(i){if($(i))$(i).addEventListener('click',function(e){e.preventDefault();start(this.getAttribute('data-mode'));});});
if($('neyps-stop'))$('neyps-stop').addEventListener('click',function(e){e.preventDefault();stopFlag=true;post('stop').then(function(r){render(r.data);});});
if($('neyps-clear'))$('neyps-clear').addEventListener('click',function(e){e.preventDefault();post('clear_log').then(function(r){render(r.data);});});
function out(t){if($('neyps-out'))$('neyps-out').textContent=t;}
if($('neyps-test-btn'))$('neyps-test-btn').addEventListener('click',function(e){e.preventDefault();out('در حال دریافت…');post('test',{id:$('neyps-test-id').value}).then(function(r){out(r.success?JSON.stringify(r.data.product,null,2):(r.data&&r.data.msg));});});
if($('neyps-detect-btn'))$('neyps-detect-btn').addEventListener('click',function(e){e.preventDefault();out('در حال بررسی فایل‌های فروشگاه… (ممکن است یک دقیقه طول بکشد)');post('detect').then(function(r){out(JSON.stringify(r.data,null,2));});});
if($('neyps-collect-btn'))$('neyps-collect-btn').addEventListener('click',function(e){e.preventDefault();out('در حال ذخیره و بررسی API…');post('collect',{data:$('neyps-collect').value}).then(function(r){out(r.data&&r.data.msg||'خطا');});});
if($('neyps-file'))$('neyps-file').addEventListener('change',function(){var f=this.files[0];if(!f)return;var rd=new FileReader();rd.onload=function(){$('neyps-collect').value=rd.result;out('فایل خوانده شد ('+Math.round(f.size/1024)+' KB). اکنون «ذخیره داده‌های جمع‌آوری‌شده» را بزنید.');};rd.readAsText(f,'UTF-8');});
if(C.running&&$('neyps-new')){busy=true;lock(true);loop();}else if(C.autorun&&$('neyps-new')){start(C.autorun);}
})();
</script>
		<?php
	}
}
