<?php
/**
 * Journey fixture mu-plugin: auth-multisite.
 *
 * Mounted only when the auth-multisite journey is selected (see this rig's
 * journey.json "fixtureMuPlugins" and run.mjs); it can never reach
 * production because it only exists as a mount inside this journey's own
 * disposable boot. Ported from extrachill-users
 * tests/e2e/auth-multisite/fixture/auth-fuzz-fixture.php
 * (extrachill-network#293); the rate-limit-store overrides that file also
 * carried were dropped -- this journey exercises Extra Chill Users' REAL
 * default rate-limit implementation (extrachill_users_admit_registration_attempt,
 * ec_login_rate_limit_cache_operation) rather than substituting a bespoke
 * store, which is a truer test of production behavior.
 *
 * @package ExtraChillNetwork
 */

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
