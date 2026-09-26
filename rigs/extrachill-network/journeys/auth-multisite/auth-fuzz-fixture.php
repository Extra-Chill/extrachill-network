<?php
/**
 * Journey fixture mu-plugin: auth-multisite.
 *
 * Mounted only when the auth-multisite journey is selected (see this rig's
 * journey.json "fixtureMuPlugins" and run.mjs); it can never reach
 * production because it only exists as a mount inside this journey's own
 * disposable boot. Ported from extrachill-users
 * tests/e2e/auth-multisite/fixture/auth-fuzz-fixture.php
 * (extrachill-network#293); Extra Chill Users' OWN rate-limit stores
 * (extrachill_users_admit_registration_attempt, ec_login_rate_limit_cache_operation)
 * are used as-is unmodified -- they are transient-backed and need no
 * override.
 *
 * extrachill-api's public-write admission gate
 * (inc/middleware/public-write-admission.php) is a DIFFERENT store, and it
 * is not optional here: its default implementation
 * (extrachill_api_atomic_rate_limit_cache_increment()) hard-requires a real
 * persistent external object cache (`wp_using_ext_object_cache()`) and
 * fails closed with a 503 otherwise -- this rig's own README already
 * documents `redis-cache` as an excluded component (no Redis server in the
 * disposable sandbox), so EVERY public-write REST call (register, login,
 * refresh, ...) 503s without a substitute store. This is the rig's Redis
 * gap wearing a different name, not a rate-limiting behavior this journey
 * is choosing to fake.
 *
 * @package ExtraChillNetwork
 */

/**
 * Site-option-backed substitute for extrachill-api's atomic public-write
 * admission counter, used only because no external object cache exists in
 * this disposable sandbox. Not a claim of atomicity under real concurrency
 * (a single PHP process handles this journey's requests sequentially) --
 * purely a stand-in for the missing Redis/Memcached dependency.
 *
 * @param string $key Stable opaque counter key.
 * @param int    $ttl Remaining fixed-window lifetime in seconds.
 * @return int
 */
function ec_rig_auth_multisite_rate_limit_store( $key, $ttl ) {
	$state = get_site_option( 'ec_rig_auth_multisite_rate_limits', array() );
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
	update_site_option( 'ec_rig_auth_multisite_rate_limits', $state );
	return (int) $entry['count'];
}
add_filter(
	'extrachill_api_rate_limit_store',
	static function () {
		return 'ec_rig_auth_multisite_rate_limit_store';
	}
);

// Cloudflare Turnstile requires solving a live, real widget challenge that no
// automated browser session can pass (see extrachill-network#295's Gardner
// event-RSVP journey findings: an automated registration attempt against an
// UNCONFIGURED sandbox gets "Security verification required."). Rather than
// faking a widget solve, this uses the SAME dev/test seam the product
// already ships for exactly this purpose
// (inc/core/extrachill-turnstile.php ec_verify_turnstile_response() /
// ec_turnstile_check_request()): 'extrachill_bypass_turnstile_verification'.
// Zero network egress, deterministic, and -- because it is only ever wired
// up from inside this disposable mu-plugin mount -- structurally unable to
// reach production config.
add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );

// No real SMTP exists in the sandbox; never attempt a live send.
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Record every wp_login firing (site + user) so the journey's grade step can
 * verify -- from the SERVER side, not by guessing at browser DOM state --
 * that a browser-handoff redirect actually authenticated the right user on
 * the right site. wp_set_auth_cookie() only sets a cookie in the browser;
 * this is the one place PHP can observe "a real session was established
 * here" without inspecting cookies it cannot read back.
 */
add_action(
	'wp_login',
	static function ( $user_login, $user ) {
		$log   = get_site_option( 'ec_rig_auth_multisite_logins', array() );
		$log[] = array(
			'blog_id' => get_current_blog_id(),
			'domain'  => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'user_id' => (int) $user->ID,
			'time'    => time(),
		);
		update_site_option( 'ec_rig_auth_multisite_logins', $log );
	},
	10,
	2
);
