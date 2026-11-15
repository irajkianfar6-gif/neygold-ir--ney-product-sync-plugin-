<?php
/**
 * Runner: resumable job (discover -> import), driven by admin AJAX steps, WP-Cron or WP-CLI.
 *
 * @package NEYPS
 */

defined( 'ABSPATH' ) || exit;

class NEYPS_Runner {

	const OPT = 'neyps_job';

	public static function state() {
		$j = get_option( self::OPT );
		return is_array( $j ) ? $j : null;
	}

	public static function running() {
		$j = self::state();
		return $j && 'done' !== $j['phase'];
	}

	/** Start a job. $mode: 'all' = everything, 'new' = only products not imported yet. */
	public static function start( $mode, $extra_ids = array() ) {
		$mode  = 'all' === $mode ? 'all' : 'new';
		$ids   = array_values( array_unique( array_merge( NEYPS_Discovery::manual_ids(), self::pending_ids(), array_map( 'strval', (array) $extra_ids ) ) ) );
		$pending = self::pending_seeds();
		$offline = 0;
		if ( $pending ) {
			$probe   = NEYPS_Http::get( NEYPS_Settings::base() . '/' );
			$offline = 0 === (int) $probe['code'] ? 1 : 0;
			NEYPS_Log::info( $offline ? 'سرور به زرپلاس دسترسی ندارد؛ انتشار فقط از داده‌های جمع‌آوری‌شده با مرورگر انجام می‌شود.' : 'انتشار از داده‌های جمع‌آوری‌شده با مرورگر.' );
		}
		$job   = array(
			'mode'    => $mode,
			'phase'   => 'discover',
			'tasks'   => $pending ? array() : NEYPS_Discovery::tasks(),
			'offline' => $offline,
			'ti'      => 0,
			'ids'     => $ids,
			'seeds'   => $pending,
			'queue'   => array(),
			'qi'      => 0,
			'stats'   => array( 'found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0 ),
			'started' => time(),
			'updated' => time(),
		);
		update_option( self::OPT, $job, false );
		NEYPS_Log::info( 'all' === $mode ? 'شروع استخراج کامل همه محصولات' : 'شروع بروزرسانی: افزودن محصولات جدید' );
		return $job;
	}

	public static function stop() {
		$j = self::state();
		if ( $j && 'done' !== $j['phase'] ) {
			$j['phase'] = 'done';
			$j['stopped'] = 1;
			update_option( self::OPT, $j, false );
			NEYPS_Log::warn( 'عملیات توسط مدیر متوقف شد.' );
		}
		wp_clear_scheduled_hook( NEYPS_CONTINUE );
		delete_transient( 'neyps_lock' );
	}

	/** Ids/cards pasted from the browser collector, kept until the next run. */
	public static function pending_ids() {
		$p = get_option( 'neyps_collected', array() );
		return isset( $p['ids'] ) ? (array) $p['ids'] : array();
	}

	public static function pending_seeds() {
		$p = get_option( 'neyps_collected', array() );
		return isset( $p['seeds'] ) ? (array) $p['seeds'] : array();
	}

	/**
	 * Advance the job.
	 *
	 * @param int $budget  Seconds to work.
	 * @param int $max_imp Max products imported in this call (0 = no limit).
	 */
	public static function step( $budget = 20, $max_imp = 0 ) {
		$job = self::state();
		if ( ! $job || 'done' === $job['phase'] ) {
			return $job;
		}
		if ( get_transient( 'neyps_lock' ) ) {
			return $job;
		}
		set_transient( 'neyps_lock', 1, 5 * MINUTE_IN_SECONDS );
		NEYPS_Importer::$offline = ! empty( $job['offline'] );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, $budget * 3 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );
		$t0   = microtime( true );
		$done = 0;
		try {
			while ( microtime( true ) - $t0 < $budget ) {
				if ( 'discover' === $job['phase'] ) {
					if ( $job['ti'] >= count( $job['tasks'] ) ) {
						self::to_import( $job );
						continue;
					}
					$known = array_flip( $job['ids'] );
					$r     = NEYPS_Discovery::step( $job['tasks'][ $job['ti'] ], $known );
					foreach ( $r['ids'] as $id ) {
						if ( ! isset( $known[ $id ] ) ) {
							$job['ids'][] = (string) $id;
							$known[ $id ] = 1;
						}
					}
					foreach ( $r['seeds'] as $id => $seed ) {
						$job['seeds'][ $id ] = isset( $job['seeds'][ $id ] ) ? NEYPS_Parser::merge( $job['seeds'][ $id ], $seed ) : $seed;
					}
					$type = $job['tasks'][ $job['ti'] ]['t'];
					if ( 'api' === $type && $r['ids'] ) {
						$job['api_ok'] = 1;
					}
					if ( ! empty( $r['failed'] ) ) {
						$job['offline'] = 1;
						$job['ti']      = count( $job['tasks'] );
					} elseif ( $r['done'] ) {
						++$job['ti'];
						if ( 'api' === $type && ! empty( $job['api_ok'] ) ) {
							$job['ti'] = count( $job['tasks'] ); // API is complete; HTML listings are not needed.
						}
					}
					continue;
				}
				// import phase.
				if ( $job['qi'] >= count( $job['queue'] ) ) {
					self::finish( $job );
					break;
				}
				if ( $max_imp && $done >= $max_imp ) {
					break;
				}
				$id = (string) $job['queue'][ $job['qi'] ];
				++$job['qi'];
				$seed = isset( $job['seeds'][ $id ] ) ? $job['seeds'][ $id ] : null;
				unset( $job['seeds'][ $id ] );
				$res = NEYPS_Importer::import_id( $id, $seed, $job['mode'] );
				$st  = $res[0];
				if ( isset( $job['stats'][ $st ] ) ) {
					++$job['stats'][ $st ];
				}
				if ( 'skipped' !== $st ) {
					++$done;
				}
				// Crawl related products to find items the listing did not show.
				if ( NEYPS_Settings::get( 'crawl_related' ) && ! empty( $res[1] ) ) {
					$known    = array_flip( $job['ids'] );
					$existing = NEYPS_Importer::existing_map();
					foreach ( $res[1] as $rid ) {
						$rid = (string) $rid;
						if ( ! isset( $known[ $rid ] ) && ( 'all' === $job['mode'] || ! isset( $existing[ $rid ] ) ) ) {
							$job['ids'][]   = $rid;
							$job['queue'][] = $rid;
							$known[ $rid ]  = 1;
							++$job['stats']['found'];
						}
					}
				}
			}
		} catch ( Throwable $e ) {
			NEYPS_Log::error( 'خطا: ' . $e->getMessage() );
		}
		$job['updated'] = time();
		update_option( self::OPT, $job, false );
		delete_transient( 'neyps_lock' );
		return $job;
	}

	private static function to_import( &$job ) {
		$ids = array_values( array_unique( array_map( 'strval', $job['ids'] ) ) );
		if ( 'new' === $job['mode'] ) {
			$existing = NEYPS_Importer::existing_map();
			$ids      = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $existing ) {
						return ! isset( $existing[ $id ] );
					}
				)
			);
		}
		// Oldest first, so the newest source product ends up newest in WooCommerce too.
		usort(
			$ids,
			function ( $a, $b ) {
				return (int) $a - (int) $b;
			}
		);
		$job['queue']          = $ids;
		$job['qi']             = 0;
		$job['phase']          = 'import';
		$job['stats']['found'] = count( $ids );
		NEYPS_Log::info( sprintf( 'کشف محصولات تمام شد: %d شناسه، %d محصول برای پردازش.', count( $job['ids'] ), count( $ids ) ) );
	}

	private static function finish( &$job ) {
		$job['phase'] = 'done';
		$s            = $job['stats'];
		NEYPS_Log::ok( sprintf( 'پایان: %d محصول جدید، %d به‌روزشده، %d موجود/رد شده، %d ناموفق.', $s['created'], $s['updated'], $s['skipped'], $s['failed'] ) );
		update_option( 'neyps_last_run', array( 'time' => time(), 'mode' => $job['mode'], 'stats' => $s ), false );
		delete_option( 'neyps_collected' );
		if ( function_exists( 'ney_flush_catalog' ) ) {
			ney_flush_catalog();
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}

	/** Cron entry: start (if idle) and work in the background, rescheduling itself until done. */
	public static function run_background( $mode ) {
		if ( ! NEYPS_Importer::ready() ) {
			return;
		}
		if ( ! self::running() ) {
			self::start( $mode );
		}
		self::continue_background();
	}

	public static function continue_background() {
		$job = self::step( 40 );
		if ( $job && 'done' !== $job['phase'] && ! wp_next_scheduled( NEYPS_CONTINUE ) ) {
			wp_schedule_single_event( time() + 30, NEYPS_CONTINUE );
		}
	}
}
