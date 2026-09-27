<?php
/**
 * Journey fixture mu-plugin: musician-link-page-onboarding.
 *
 * Mounted only when this journey is selected (journey.json
 * "fixtureMuPlugins"); it exists solely inside the disposable boot and can
 * never reach production. Each filter substitutes a dependency the sandbox
 * does not have, never product behavior:
 *
 * - Cloudflare Turnstile cannot be solved by an automated browser. Uses the
 *   product's own dev/test seam (`extrachill_bypass_turnstile_verification`),
 *   same as auth-multisite.
 * - No SMTP: never attempt a live send.
 * - extrachill-api's public-write admission counter hard-requires a
 *   persistent external object cache (Redis is an excluded component on this
 *   rig). A site-option-backed counter stands in via the existing
 *   `extrachill_api_rate_limit_store` filter.
 *
 * @package ExtraChillNetwork
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );

/*
 * ec_get_site_url() builds production https:// URLs; the sandbox serves the
 * network over plain http, so every cross-site hop (the /join redirect, the
 * /power bridge cards, the editor handoff endpoints) would dead-end on a TLS
 * connection nothing answers. extrachill-network ships `ec_site_url_override`
 * for exactly this ("for dev environments"): answer with each site's real
 * home URL.
 */
add_filter(
	'ec_site_url_override',
	static function ( $url, $key, $blog_id ) {
		unset( $key );
		return $blog_id ? untrailingslashit( get_home_url( (int) $blog_id ) ) : $url;
	},
	10,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Site-option-backed substitute for the Redis-backed admission counter.
 *
 * @param string $key Counter key.
 * @param int    $ttl Window lifetime in seconds.
 * @return int
 */
function musician_journey_rate_limit_store( $key, $ttl ) {
	$state = get_site_option( 'musician_journey_rate_limits', array() );
	$now   = time();
	$entry = $state[ $key ] ?? array(
		'count'   => 0,
		'expires' => $now + max( 1, (int) $ttl ),
	);
	if ( $entry['expires'] <= $now ) {
		$entry = array(
			'count'   => 0,
			'expires' => $now + max( 1, (int) $ttl ),
		);
	}
	++$entry['count'];
	$state[ $key ] = $entry;
	update_site_option( 'musician_journey_rate_limits', $state );
	return (int) $entry['count'];
}
add_filter(
	'extrachill_api_rate_limit_store',
	static function () {
		return 'musician_journey_rate_limit_store';
	}
);

/*
 * WordPress Playground's platform mu-plugin REPLACES allowed_redirect_hosts
 * with three wordpress.org hosts (playground_allowed_redirect_hosts() ignores
 * its input), which drops the network domains extrachill-network merges in.
 * Every cross-site wp_safe_redirect -- including the extrachill.link editor
 * token handoff -- then falls back to wp-admin. Re-merge the network's own
 * list after Playground's filter; production is unaffected (verified with
 * wp_validate_redirect on extrachill.com).
 */
add_filter(
	'allowed_redirect_hosts',
	static function ( $hosts ) {
		return function_exists( 'ec_get_allowed_redirect_hosts' ) ? array_values( array_unique( array_merge( (array) $hosts, ec_get_allowed_redirect_hosts() ) ) ) : $hosts;
	},
	99
);

/*
 * link-pages builds public URLs as https:// (ec_link_page_public_base_url),
 * so the canonical redirect off a Link Page slug lands on a TLS port nothing
 * answers in this http-only sandbox. Downgrade network-host redirects to
 * http; production is https end to end.
 */
add_filter(
	'wp_redirect',
	static function ( $location ) {
		return is_string( $location ) ? preg_replace( '#^https://((?:[a-z0-9-]+\.)?extrachill\.(?:com|link))(?=[/?\#]|$)#i', 'http://$1', $location ) : $location;
	},
	PHP_INT_MAX - 1
);

/*
 * Evidence for the /edit bearer handshake: what the REST layer saw.
 */
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		if ( false !== strpos( (string) $request->get_route(), 'link-pages/editor-configuration' ) ) {
			$log   = get_site_option( 'musician_journey_rest_diag', array() );
			$log[] = array(
				'route'           => $request->get_route(),
				'has_auth_header' => '' !== (string) $request->get_header( 'authorization' ),
				'server_auth'     => isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
				'user'            => get_current_user_id(),
			);
			update_site_option( 'musician_journey_rest_diag', array_slice( $log, -10 ) );
		}
		return $result;
	},
	10,
	3
);

/*
 * Record fatal errors so an empty response (a blank page in the browser)
 * carries its cause into the journey result.
 */
register_shutdown_function(
	static function () {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) || ! function_exists( 'get_site_option' ) ) {
			return;
		}
		$log   = get_site_option( 'musician_journey_fatals', array() );
		$log[] = array(
			'url'     => ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- evidence only.
			'message' => substr( (string) $error['message'], 0, 1500 ),
			'file'    => $error['file'] . ':' . $error['line'],
		);
		update_site_option( 'musician_journey_fatals', array_slice( $log, -20 ) );
	}
);

/*
 * Observation sink. wordpress.browser-actions only records an `evaluate`
 * step's value when the step asserts; this journey is exploratory (what
 * does the musician SEE, where do the links go), so each observation posts
 * itself here and grade.php prints the collected list.
 */
add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox evidence sink.
		if ( empty( $_GET['musician_journey_observe'] ) ) {
			return;
		}
		$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
		$log   = get_site_option( 'musician_journey_observations', array() );
		$log[] = is_array( $body ) ? $body : array( 'raw' => 'unparseable' );
		update_site_option( 'musician_journey_observations', $log );
		status_header( 204 );
		exit;
	},
	0
);

/*
 * MySQL advisory locks (GET_LOCK / RELEASE_LOCK) do not exist on the SQLite
 * driver this sandbox runs, so every Link Page create and save fails closed
 * with link_page_owner_lock_failed / link_page_id_lock_failed. The sandbox
 * serves requests sequentially through one PHP runtime, so the lock has
 * nothing to serialize; answer "acquired"/"released" the way MySQL would.
 * Same class of substitute as the rate-limit store above (a missing
 * infrastructure primitive), and never a claim about concurrency safety.
 */
add_filter(
	'query',
	static function ( $query ) {
		if ( is_string( $query ) && preg_match( '/^\s*SELECT\s+(GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK)\s*\(/i', $query ) ) {
			return 'SELECT 1';
		}
		return $query;
	}
);

/**
 * Diagnose the extrachill.link editor token handoff in the sandbox.
 */
add_action(
	'admin_post_ec_link_token_handoff',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- sandbox evidence only.
		$return = isset( $_GET['return'] ) ? (string) wp_unslash( $_GET['return'] ) : '';
		$log    = get_site_option( 'musician_journey_handoff_diag', array() );
		$log[]  = array(
			'user'           => get_current_user_id(),
			'return'         => $return,
			'esc_url_raw'    => esc_url_raw( $return ),
			'allowed_hosts'  => apply_filters( 'allowed_redirect_hosts', array(), 'extrachill.link' ),
			'validated'      => wp_validate_redirect( $return . '#ec_link_token_none=1', 'FALLBACK' ),
			'token_callable' => is_callable( 'wp_native_auth_generate_access_token' ),
			'home'           => home_url( '/' ),
		);
		update_site_option( 'musician_journey_handoff_diag', array_slice( $log, -10 ) );
	},
	1
);

/**
 * Record every redirect a front-end request issues, so the grade can show
 * the real hop sequence of the join funnel from the server side.
 */
add_filter(
	'wp_redirect',
	static function ( $location, $status ) {
		$log   = get_site_option( 'musician_journey_redirects', array() );
		$log[] = array(
			'from'   => ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- evidence only, sandbox.
			'to'     => (string) $location,
			'status' => (int) $status,
			'user'   => get_current_user_id(),
		);
		update_site_option( 'musician_journey_redirects', array_slice( $log, -80 ) );
		return $location;
	},
	PHP_INT_MAX,
	2
);

/*
 * Render trace for public Link Page requests: how far does a request get
 * before an empty response? (Playground drops the body on a WASM-level
 * crash, where no shutdown handler runs.)
 */
if ( isset( $_SERVER['HTTP_HOST'] ) && false !== strpos( (string) $_SERVER['HTTP_HOST'], 'extrachill.link' ) && false === strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'wp-json' ) ) {
	$GLOBALS['musician_journey_trace_key'] = substr( md5( (string) microtime( true ) ), 0, 8 );
	$musician_journey_mark                 = static function ( $label ) {
		return static function ( $value = null ) use ( $label ) {
			$log   = get_site_option( 'musician_journey_render_trace', array() );
			$log[] = $GLOBALS['musician_journey_trace_key'] . ' ' . ( $_SERVER['REQUEST_URI'] ?? '' ) . ' ' . $label . ( is_string( $value ) && str_ends_with( $value, '.php' ) ? ' ' . basename( $value ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			update_site_option( 'musician_journey_render_trace', array_slice( $log, -60 ) );
			return $value;
		};
	};
	add_action( 'wp', $musician_journey_mark( 'wp' ), PHP_INT_MAX );
	add_action( 'template_redirect', $musician_journey_mark( 'template_redirect:end' ), PHP_INT_MAX );
	add_filter( 'template_include', $musician_journey_mark( 'template_include' ), PHP_INT_MAX );
	add_action( 'ec_link_page_public_head', $musician_journey_mark( 'public_head' ), 0 );
	add_action( 'ec_link_page_public_body_open', $musician_journey_mark( 'public_body_open' ), 0 );
	add_action( 'wp_head', $musician_journey_mark( 'wp_head' ), 0 );
	add_action( 'wp_footer', $musician_journey_mark( 'wp_footer' ), PHP_INT_MAX );
	add_action( 'shutdown', $musician_journey_mark( 'shutdown' ), 0 );
}

/*
 * #299 evidence: status and error code of every auth/registration REST call.
 */
add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		$route = (string) $request->get_route();
		if ( false !== strpos( $route, '/auth/' ) || false !== strpos( $route, 'register' ) || false !== strpos( $route, 'onboarding' ) || false !== strpos( $route, 'subscribe' ) ) {
			$data  = $response instanceof WP_REST_Response ? $response->get_data() : null;
			$log   = get_site_option( 'musician_journey_auth_rest', array() );
			$log[] = array(
				'route'       => $route,
				'status'      => $response instanceof WP_REST_Response ? $response->get_status() : null,
				'code'        => is_array( $data ) ? ( $data['code'] ?? null ) : null,
				'message'     => is_array( $data ) ? substr( (string) ( $data['message'] ?? '' ), 0, 200 ) : null,
				'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'ext_cache'   => wp_using_ext_object_cache(),
			);
			update_site_option( 'musician_journey_auth_rest', array_slice( $log, -20 ) );
		}
		return $response;
	},
	PHP_INT_MAX,
	3
);
