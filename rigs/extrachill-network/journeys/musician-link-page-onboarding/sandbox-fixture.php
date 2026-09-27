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
 * Journey-only unblock: provision the logged-in musician's Link Page.
 *
 * Self-serve artist creation provisions no Link Page and /manage-link-page/
 * then renders "Link Page management is unavailable." with no way forward
 * (see evidence/FINDINGS.md). That dead end is recorded first; this endpoint
 * then calls ec_create_link_page() -- the same function the claimable
 * external-artist onboarding ability uses -- inside a real front-end request
 * on the artist site (all per-site plugins loaded), so the editor half of
 * the journey can still be judged. Never a pass: grade.php records the
 * before-state as the finding.
 */
add_action(
	'template_redirect',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only fixture endpoint.
		if ( empty( $_GET['musician_journey_provision'] ) || ! is_user_logged_in() || ! function_exists( 'ec_create_link_page' ) || ! function_exists( 'ec_get_artists_for_user' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		$record  = array(
			'user_id' => $user_id,
			'artists' => array(),
		);
		foreach ( (array) ec_get_artists_for_user( $user_id ) as $artist_id ) {
			$before = (int) ec_get_link_page_for_artist( $artist_id );
			$error  = null;
			if ( 0 === $before ) {
				$created = ec_create_link_page( $artist_id );
				$error   = is_wp_error( $created ) ? $created->get_error_code() . ': ' . $created->get_error_message() : null;
			}
			$record['artists'][] = array(
				'artist_id'           => (int) $artist_id,
				'link_page_before'    => $before,
				'link_page_after'     => (int) ec_get_link_page_for_artist( $artist_id ),
				'provision_error'     => $error,
			);
		}
		update_site_option( 'musician_journey_provision', $record );
		wp_send_json( $record );
	}
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
