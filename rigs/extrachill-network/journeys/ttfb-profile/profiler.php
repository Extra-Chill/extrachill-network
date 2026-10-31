<?php
/**
 * Throwaway request profiler for #428 part 3 (branch perf/ttfb-profile, never merged).
 *
 * Records, per HTTP request: wall time from REQUEST_TIME_FLOAT to each core
 * phase, per-plugin load cost (plugin_loaded / network_plugin_loaded), query
 * count, included files, opcache and object-cache state. Query switches:
 *   rig_ttfb_stop=mu|plugins_loaded|init|wp_loaded  exit at that stage
 *   rig_ttfb_hooks=1    attribute time to hook names (adds overhead)
 *   rig_ttfb_queries=1  SAVEQUERIES for per-query timing (adds overhead)
 *   rig_ttfb_client=1   POST: append browser-side timings, then exit
 * Records go to wp-content/rig-ttfb*.jsonl; grade.php prints them.
 */

if ( empty( $_SERVER['REQUEST_URI'] ) ) {
	return;
}

$GLOBALS['rig_ttfb'] = array(
	'uri'      => $_SERVER['REQUEST_URI'],
	'host'     => $_SERVER['HTTP_HOST'] ?? '',
	'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
	'start'    => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null,
	'mu_at'    => microtime( true ),
	'files_mu' => count( get_included_files() ),
	'queries_mu' => isset( $GLOBALS['wpdb'] ) ? (int) $GLOBALS['wpdb']->num_queries : null,
	'marks'    => array(),
	'plugins'  => array(),
	'hooks'    => array(),
	'last'     => microtime( true ),
);

function rig_ttfb_rel( $t ) {
	$p = $GLOBALS['rig_ttfb'];
	return round( ( $t - ( $p['start'] ?? $p['mu_at'] ) ) * 1000, 1 );
}

function rig_ttfb_mark( $name ) {
	$GLOBALS['rig_ttfb']['marks'][ $name ] = rig_ttfb_rel( microtime( true ) );
}

function rig_ttfb_write( $file, $record ) {
	file_put_contents( WP_CONTENT_DIR . '/' . $file, wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );
}

// Browser-side timings, posted by the journey step.
if ( isset( $_GET['rig_ttfb_client'] ) ) {
	$body = file_get_contents( 'php://input' );
	file_put_contents( WP_CONTENT_DIR . '/rig-ttfb-client.jsonl', $body . "\n", FILE_APPEND | LOCK_EX );
	header( 'Content-Type: text/plain' );
	exit( 'ok' );
}

if ( ! empty( $_GET['rig_ttfb_queries'] ) && ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

register_shutdown_function(
	static function () {
		global $wpdb;
		$p   = $GLOBALS['rig_ttfb'];
		$end = microtime( true );
		$opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
		$record = array(
			'uri'          => $p['uri'],
			'host'         => $p['host'],
			'method'       => $p['method'],
			'start'        => $p['start'],
			'pre_mu_ms'    => $p['start'] ? round( ( $p['mu_at'] - $p['start'] ) * 1000, 1 ) : null,
			'timestart_ms' => isset( $GLOBALS['timestart'] ) ? rig_ttfb_rel( (float) $GLOBALS['timestart'] ) : null,
			'db_ready_at_mu' => isset( $GLOBALS['wpdb'] ) && ! empty( $GLOBALS['wpdb']->ready ),
			'dropins'      => array_values( array_filter( array( 'db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php' ), static fn( $d ) => is_file( WP_CONTENT_DIR . '/' . $d ) ) ),
			'mu_plugins'   => count( wp_get_mu_plugins() ),
			'active_plugins' => count( (array) get_option( 'active_plugins', array() ) ) + count( (array) get_site_option( 'active_sitewide_plugins', array() ) ),
			'total_ms'     => rig_ttfb_rel( $end ),
			'marks'        => $p['marks'],
			'plugins'      => $p['plugins'],
			'files_mu'     => $p['files_mu'],
			'files'        => count( get_included_files() ),
			'queries_mu'   => $p['queries_mu'],
			'queries'      => isset( $wpdb ) ? (int) $wpdb->num_queries : null,
			'query_ms'     => ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) ? round( array_sum( array_map( static fn( $q ) => (float) ( $q[1] ?? 0 ), $wpdb->queries ) ) * 1000, 1 ) : null,
			'slow_queries' => ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) ? array_slice( array_map( static fn( $q ) => array( round( (float) $q[1] * 1000, 1 ), substr( (string) $q[0], 0, 160 ) ), ( static function ( $qs ) { usort( $qs, static fn( $a, $b ) => $b[1] <=> $a[1] ); return $qs; } )( $wpdb->queries ) ), 0, 12 ) : null,
			'hooks'        => $p['hooks'] ? array_slice( ( static function ( $h ) { arsort( $h ); return array_map( static fn( $v ) => round( $v * 1000, 1 ), $h ); } )( $p['hooks'] ), 0, 25, true ) : null,
			'db_class'     => isset( $wpdb ) ? get_class( $wpdb ) : null,
			'ext_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : null,
			'opcache'      => $opcache ? array(
				'enabled' => $opcache['opcache_enabled'] ?? null,
				'hits'    => $opcache['opcache_statistics']['hits'] ?? null,
				'misses'  => $opcache['opcache_statistics']['misses'] ?? null,
				'scripts' => $opcache['opcache_statistics']['num_cached_scripts'] ?? null,
				'jit'     => $opcache['jit']['enabled'] ?? null,
			) : array( 'available' => function_exists( 'opcache_get_status' ), 'ini' => ini_get( 'opcache.enable' ) ),
			'php'          => PHP_VERSION,
			'sapi'         => PHP_SAPI,
			'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'logged_in'    => function_exists( 'is_user_logged_in' ) && did_action( 'set_current_user' ) ? is_user_logged_in() : null,
			'blog_id'      => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null,
			'status'       => http_response_code(),
		);
		rig_ttfb_write( 'rig-ttfb.jsonl', $record );
	}
);

if ( 'mu' === ( $_GET['rig_ttfb_stop'] ?? '' ) ) {
	rig_ttfb_mark( 'stop_mu' );
	header( 'Content-Type: text/plain' );
	exit( 'ok' );
}

$rig_ttfb_plugin_loaded = static function ( $file ) {
	$now = microtime( true );
	$GLOBALS['rig_ttfb']['plugins'][ basename( dirname( $file ) ) . '/' . basename( $file ) ] = round( ( $now - $GLOBALS['rig_ttfb']['last'] ) * 1000, 1 );
	$GLOBALS['rig_ttfb']['last'] = $now;
};
add_action( 'network_plugin_loaded', $rig_ttfb_plugin_loaded );
add_action( 'plugin_loaded', $rig_ttfb_plugin_loaded );
add_action( 'muplugins_loaded', static function () {
	rig_ttfb_mark( 'muplugins_loaded' );
	$GLOBALS['rig_ttfb']['last'] = microtime( true );
}, PHP_INT_MAX );

foreach ( array( 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded', 'parse_request', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'rest_api_init' ) as $rig_ttfb_hook ) {
	add_action( $rig_ttfb_hook, static function () use ( $rig_ttfb_hook ) {
		rig_ttfb_mark( $rig_ttfb_hook );
	}, PHP_INT_MIN );
}
foreach ( array( 'plugins_loaded', 'init', 'wp_loaded' ) as $rig_ttfb_hook ) {
	add_action( $rig_ttfb_hook, static function () use ( $rig_ttfb_hook ) {
		rig_ttfb_mark( $rig_ttfb_hook . '_end' );
		if ( ( $_GET['rig_ttfb_stop'] ?? '' ) === $rig_ttfb_hook ) {
			header( 'Content-Type: text/plain' );
			exit( 'ok' );
		}
	}, PHP_INT_MAX );
}

if ( ! empty( $_GET['rig_ttfb_hooks'] ) ) {
	add_action( 'all', static function () {
		$now = microtime( true );
		$p   = &$GLOBALS['rig_ttfb'];
		if ( isset( $p['cur'] ) ) {
			$p['hooks'][ $p['cur'] ] = ( $p['hooks'][ $p['cur'] ] ?? 0 ) + ( $now - $p['cur_at'] );
		}
		$p['cur']    = current_filter();
		$p['cur_at'] = $now;
	} );
}
