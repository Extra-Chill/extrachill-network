<?php
/**
 * Grade the Extra Chill authentication multisite campaign.
 *
 * Ported from extrachill-users tests/e2e/auth-multisite/assert.php +
 * post-assert.php (extrachill-network#293), extended for what the real
 * domain-based network can verify that the old path-based topology could
 * not: cross-site session continuity through the REAL production
 * browser-handoff mechanism (extrachill_users_create_browser_handoff_token()
 * + the admin-post.php `extrachill_browser_handoff` action), not a shared
 * cookie. The old browser-registration.json visited
 * `/artist/?pagename=login&auth_fuzz_observe=artist` under one shared
 * path-based domain and treated the SAME literal cookie showing up there as
 * "cross-site continuity" -- that assertion's meaning does not transfer to
 * real distinct subdomains (no COOKIE_DOMAIN in production; see this rig's
 * README "Boundary" section on crossDomainCookieParity). Production's real
 * mechanism for "a browser session on one site should also authenticate on
 * another" IS the browser-handoff token: this grade step drives it for
 * real, over real HTTP, against the real admin-post.php handler, rather
 * than asserting a parity that was never true in production either.
 *
 * @package ExtraChillNetwork
 */

$cases    = array();
$findings = array();

/**
 * Record one grading case.
 *
 * @param string $id       Stable case ID.
 * @param bool   $passed   Whether the check held.
 * @param string $task     What this verifies, in plain words.
 * @param array  $evidence Supporting evidence.
 */
function ec_rig_auth_multisite_case( string $id, bool $passed, string $task, array $evidence = array() ): void {
	global $cases, $findings;
	$record  = array(
		'id'       => $id,
		'passed'   => $passed,
		'task'     => $task,
		'evidence' => $evidence,
	);
	$cases[] = $record;
	if ( ! $passed ) {
		$findings[] = array_merge( $record, array( 'status' => 'open' ) );
	}
}

function ec_rig_auth_multisite_rest( string $route, array $params = array(), int $user_id = 0, string $method = 'POST' ): WP_REST_Response {
	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $params );
	return rest_ensure_response( rest_do_request( $request ) );
}

function ec_rig_auth_multisite_error_code( WP_REST_Response $response ): string {
	$data = (array) $response->get_data();
	return (string) ( $data['code'] ?? '' );
}

function ec_rig_auth_multisite_registration( array $overrides ): array {
	return array_merge(
		array(
			'email'               => 'unused@example.test',
			'password'            => 'valid-pass-248',
			'password_confirm'    => 'valid-pass-248',
			'device_id'           => '00000000-0000-4000-8000-000000000248',
			'device_name'         => 'Auth Multisite Journey',
			'set_cookie'          => false,
			'registration_source' => 'auth-multisite-journey',
			'registration_method' => 'standard',
		),
		$overrides
	);
}

function ec_rig_auth_multisite_network_user_count(): int {
	return count( get_users( array(
		'blog_id' => 0,
		'fields'  => 'ID',
	) ) );
}

/**
 * wp_remote_retrieve_header() returns a plain string for one occurrence of a
 * header and an array for multiple (e.g. repeated Set-Cookie). Flatten to a
 * single string for a simple substring/prefix check either way.
 *
 * @param array|string $header Header value from wp_remote_retrieve_header().
 * @return string
 */
function ec_rig_auth_multisite_flatten_header( $header ): string {
	return is_array( $header ) ? implode( ';', $header ) : $header;
}

/**
 * Load a fixture-created user, failing loudly rather than tolerating a
 * missing one -- every caller here resolves a user the seed step just
 * created.
 *
 * @param int $user_id Fixture user ID.
 * @return WP_User
 */
function ec_rig_auth_multisite_require_user( int $user_id ): WP_User {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		throw new RuntimeException( esc_html( 'Fixture user ' . $user_id . ' is missing; the seed step did not run or failed.' ) );
	}
	return $user;
}

$fixture = get_site_option( 'ec_rig_auth_multisite_fixture', array() );
$plan    = get_site_option( 'ec_rig_auth_multisite_plan', array() );
if ( ! is_array( $fixture ) || empty( $fixture['community_blog_id'] ) || ! is_array( $plan ) || empty( $plan['seed'] ) ) {
	throw new RuntimeException( 'The auth-multisite fixture or case plan is missing; the seed step did not run or failed.' );
}

$community_blog_id = (int) $fixture['community_blog_id'];
$artist_blog_id    = (int) $fixture['artist_blog_id'];
$events_blog_id    = (int) $fixture['events_blog_id'];

/*
 * ---------------------------------------------------------------------------
 * Community site: the fixture pages exist with the real blocks.
 * ---------------------------------------------------------------------------
 */
switch_to_blog( $community_blog_id );
$login_page      = get_page_by_path( 'login' );
$onboarding_page = get_page_by_path( 'onboarding' );
restore_current_blog();
ec_rig_auth_multisite_case(
	'community-login-page-has-block',
	$login_page instanceof WP_Post && has_block( 'extrachill/login-register', $login_page ),
	'A visitor can reach a real login/register page on the community site.',
	array( 'found' => $login_page instanceof WP_Post )
);
ec_rig_auth_multisite_case(
	'community-onboarding-page-has-block',
	$onboarding_page instanceof WP_Post && has_block( 'extrachill/onboarding', $onboarding_page ),
	'A newly-registered visitor can reach a real onboarding page on the community site.',
	array( 'found' => $onboarding_page instanceof WP_Post )
);

/*
 * ---------------------------------------------------------------------------
 * REST backend invariants (ported from assert.php), unchanged in substance:
 * these exercise wp-native-auth/extrachill-api/extrachill-users REST routes
 * directly, independent of which site domain happens to be "current" for
 * this run-php process, exactly as production's REST API resolves them.
 * ---------------------------------------------------------------------------
 */
$initial_count = (int) $fixture['initial_user_count'];
foreach ( $plan['invalid_registrations'] as $case ) {
	$response = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/register', ec_rig_auth_multisite_registration( $case ) );
	ec_rig_auth_multisite_case(
		'invalid-registration-rejected-' . $case['id'],
		$response->get_status() >= 400 && ec_rig_auth_multisite_network_user_count() === $initial_count,
		'An invalid registration attempt (' . $case['id'] . ') is rejected and never mutates the network user count.',
		array( 'status' => $response->get_status() )
	);
}

$created      = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/register', ec_rig_auth_multisite_registration( array( 'email' => $plan['generated_email'] ) ) );
$created_data = (array) $created->get_data();
$created_id   = (int) ( $created_data['user']['id'] ?? 0 );
ec_rig_auth_multisite_case(
	'valid-registration-creates-exactly-one-user',
	200 === $created->get_status() && $created_id > 0 && ec_rig_auth_multisite_network_user_count() === $initial_count + 1,
	'A valid registration succeeds and creates exactly one network user.',
	array(
		'status'  => $created->get_status(),
		'user_id' => $created_id,
	)
);
ec_rig_auth_multisite_case(
	'registered-user-joins-community',
	$created_id > 0 && is_user_member_of_blog( $created_id, $community_blog_id ),
	'A newly-registered user is a member of the community site.',
	array( 'user_id' => $created_id )
);
$duplicate = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/register', ec_rig_auth_multisite_registration( array( 'email' => $plan['generated_email'] ) ) );
ec_rig_auth_multisite_case(
	'duplicate-registration-rejected',
	400 === $duplicate->get_status() && ec_rig_auth_multisite_network_user_count() === $initial_count + 1,
	'Registering the same email twice fails cleanly and creates no second account.',
	array( 'status' => $duplicate->get_status() )
);

$existing    = ec_rig_auth_multisite_require_user( (int) $fixture['existing_user_id'] );
$direct_auth = wp_authenticate( $existing->user_login, 'existing-pass-248' );
ec_rig_auth_multisite_case(
	'existing-persona-authenticates-directly',
	$direct_auth instanceof WP_User,
	'The pre-seeded existing persona\'s stored credentials authenticate.',
	array( 'ok' => $direct_auth instanceof WP_User )
);
foreach ( array( $existing->user_login, $existing->user_email ) as $identifier ) {
	$login = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
		'identifier' => $identifier,
		'password'   => 'existing-pass-248',
		'device_id'  => '00000000-0000-4000-8000-000000000249',
		'set_cookie' => false,
	) );
	ec_rig_auth_multisite_case(
		'login-succeeds-with-' . ( is_email( $identifier ) ? 'email' : 'username' ),
		200 === $login->get_status() && (int) ( $login->get_data()['user']['id'] ?? 0 ) === (int) $existing->ID,
		'Logging in with a ' . ( is_email( $identifier ) ? 'email address' : 'username' ) . ' resolves the right network identity.',
		array( 'status' => $login->get_status() )
	);
}
$unknown_login = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => 'missing-auth-multisite',
	'password'   => 'wrong-pass',
	'device_id'  => '00000000-0000-4000-8000-000000000252',
) );
$known_login   = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $existing->user_login,
	'password'   => 'wrong-pass',
	'device_id'  => '00000000-0000-4000-8000-000000000253',
) );
ec_rig_auth_multisite_case(
	'login-does-not-enumerate-accounts',
	$unknown_login->get_status() === $known_login->get_status()
		&& ec_rig_auth_multisite_error_code( $unknown_login ) === ec_rig_auth_multisite_error_code( $known_login )
		&& (string) ( $unknown_login->get_data()['message'] ?? '' ) === (string) ( $known_login->get_data()['message'] ?? '' ),
	'A bad password on an unknown identifier looks identical to a bad password on a real one, so an attacker cannot tell which accounts exist.',
	array(
		'unknown_status' => $unknown_login->get_status(),
		'known_status'   => $known_login->get_status(),
	)
);

$nonmember       = ec_rig_auth_multisite_require_user( (int) $fixture['nonmember_user_id'] );
$nonmember_login = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $nonmember->user_login,
	'password'   => 'nonmember-pass-248',
	'device_id'  => '00000000-0000-4000-8000-000000000254',
) );
ec_rig_auth_multisite_case(
	'non-community-user-denied',
	403 === $nonmember_login->get_status() && 'extrachill_not_a_member' === ec_rig_auth_multisite_error_code( $nonmember_login ),
	'A network user who is not a community member cannot log into the community-facing app surface.',
	array( 'status' => $nonmember_login->get_status() )
);
$blocked       = ec_rig_auth_multisite_require_user( (int) $fixture['blocked_user_id'] );
$blocked_login = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $blocked->user_login,
	'password'   => 'blocked-pass-248',
	'device_id'  => '00000000-0000-4000-8000-000000000255',
) );
$blocked_data  = (array) $blocked_login->get_data();
ec_rig_auth_multisite_case(
	'moderated-user-cannot-obtain-tokens',
	$blocked_login->get_status() >= 400 && empty( $blocked_data['access_token'] ) && empty( $blocked_data['refresh_token'] ),
	'A moderated (banned) user cannot obtain a session, even with correct credentials.',
	array( 'status' => $blocked_login->get_status() )
);

$anonymous_me      = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/me', array(), 0, 'GET' );
$anonymous_logout  = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/logout', array( 'device_id' => '00000000-0000-4000-8000-000000000256' ) );
$anonymous_handoff = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/browser-handoff', array( 'redirect_url' => 'https://community.extrachill.com/' ) );
ec_rig_auth_multisite_case(
	'anonymous-cannot-call-authenticated-routes',
	$anonymous_me->get_status() >= 400 && $anonymous_logout->get_status() >= 400 && $anonymous_handoff->get_status() >= 400,
	'A logged-out visitor is refused by every authenticated-only route (me, logout, browser-handoff).',
	array(
		'me'      => $anonymous_me->get_status(),
		'logout'  => $anonymous_logout->get_status(),
		'handoff' => $anonymous_handoff->get_status(),
	)
);

foreach ( $plan['handoff_redirect_cases'] as $redirect ) {
	$handoff = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/browser-handoff', array( 'redirect_url' => $redirect ), (int) $existing->ID );
	ec_rig_auth_multisite_case(
		'handoff-rejects-unsafe-redirect-' . md5( $redirect ),
		400 === $handoff->get_status(),
		'A browser-handoff request pointed at an unsafe destination (' . $redirect . ') is rejected.',
		array( 'status' => $handoff->get_status() )
	);
}

$refresh_device       = '00000000-0000-4000-8000-000000000257';
$token_login          = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $existing->user_login,
	'password'   => 'existing-pass-248',
	'device_id'  => $refresh_device,
	'set_cookie' => false,
) );
$first_refresh        = (string) ( $token_login->get_data()['refresh_token'] ?? '' );
$wrong_device_refresh = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/refresh', array(
	'refresh_token' => $first_refresh,
	'device_id'     => '00000000-0000-4000-8000-000000000258',
) );
$rotated              = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/refresh', array(
	'refresh_token' => $first_refresh,
	'device_id'     => $refresh_device,
) );
$second_refresh       = (string) ( $rotated->get_data()['refresh_token'] ?? '' );
ec_rig_auth_multisite_case(
	'refresh-token-scoped-to-its-device-and-rotates',
	'' !== $first_refresh && 401 === $wrong_device_refresh->get_status() && 200 === $rotated->get_status() && '' !== $second_refresh && $second_refresh !== $first_refresh,
	'A refresh token only works on the device it was issued to, and rotates on each use.',
	array(
		'wrong_device_status' => $wrong_device_refresh->get_status(),
		'rotated_status'      => $rotated->get_status(),
	)
);
delete_transient( 'wp_native_auth_refresh_' . md5( $refresh_device ) );
$replay = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/refresh', array(
	'refresh_token' => $first_refresh,
	'device_id'     => $refresh_device,
) );
ec_rig_auth_multisite_case(
	'superseded-refresh-token-replay-detected',
	401 === $replay->get_status() && 'refresh_token_reused' === ec_rig_auth_multisite_error_code( $replay ),
	'Replaying a superseded (already-rotated) refresh token is detected and rejected.',
	array(
		'status' => $replay->get_status(),
		'code'   => ec_rig_auth_multisite_error_code( $replay ),
	)
);
delete_transient( 'wp_native_auth_refresh_' . md5( $refresh_device ) );
$burned_family = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/refresh', array(
	'refresh_token' => $second_refresh,
	'device_id'     => $refresh_device,
) );
ec_rig_auth_multisite_case(
	'replay-burns-the-whole-token-family',
	401 === $burned_family->get_status(),
	'Detecting a replay burns the entire refresh-token family, not just the reused token.',
	array( 'status' => $burned_family->get_status() )
);

$victim            = ec_rig_auth_multisite_require_user( (int) $fixture['victim_user_id'] );
$victim_device     = '00000000-0000-4000-8000-000000000260';
$victim_login      = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $victim->user_login,
	'password'   => 'victim-pass-248',
	'device_id'  => $victim_device,
	'set_cookie' => false,
) );
$victim_token      = (string) ( $victim_login->get_data()['refresh_token'] ?? '' );
$cross_user_logout = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/logout', array( 'device_id' => $victim_device ), (int) $existing->ID );
$victim_refresh    = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/refresh', array(
	'refresh_token' => $victim_token,
	'device_id'     => $victim_device,
) );
ec_rig_auth_multisite_case(
	'one-user-cannot-revoke-anothers-session',
	200 === $cross_user_logout->get_status() && empty( $cross_user_logout->get_data()['success'] ) && 200 === $victim_refresh->get_status(),
	'One authenticated user cannot log out a different user\'s device by guessing its device ID.',
	array(
		'logout_status'         => $cross_user_logout->get_status(),
		'victim_refresh_status' => $victim_refresh->get_status(),
	)
);

$onboarding                      = ec_rig_auth_multisite_require_user( (int) $fixture['onboarding_user_id'] );
$invalid_onboarding_all_rejected = true;
foreach ( $plan['invalid_onboarding_usernames'] as $username ) {
	$invalid_onboarding = ec_rig_auth_multisite_rest( '/extrachill/v1/users/onboarding', array( 'username' => $username ), (int) $onboarding->ID );
	if ( $invalid_onboarding->get_status() < 400 || '0' !== (string) get_user_meta( $onboarding->ID, 'onboarding_completed', true ) ) {
		$invalid_onboarding_all_rejected = false;
	}
}
ec_rig_auth_multisite_case(
	'invalid-onboarding-usernames-rejected',
	$invalid_onboarding_all_rejected,
	'Reserved/invalid onboarding usernames are all rejected without mutating completion state.',
	array( 'usernames_tried' => count( $plan['invalid_onboarding_usernames'] ) )
);
$target_login_before   = $existing->user_login;
$cross_user_onboarding = ec_rig_auth_multisite_rest( '/extrachill/v1/users/onboarding', array(
	'user_id'  => (int) $existing->ID,
	'username' => 'isolated_auth_multisite',
), (int) $onboarding->ID );
$target_login_after    = ec_rig_auth_multisite_require_user( (int) $existing->ID )->user_login;
ec_rig_auth_multisite_case(
	'onboarding-is-scoped-to-the-authenticated-user',
	200 === $cross_user_onboarding->get_status() && $target_login_after === $target_login_before,
	'A user completing onboarding while passing another user\'s ID cannot rename that other user.',
	array( 'status' => $cross_user_onboarding->get_status() )
);
$duplicate_onboarding = ec_rig_auth_multisite_rest( '/extrachill/v1/users/onboarding', array( 'username' => 'second_auth_multisite' ), (int) $onboarding->ID );
ec_rig_auth_multisite_case(
	'duplicate-onboarding-rejected',
	400 === $duplicate_onboarding->get_status() && 'already_completed' === ec_rig_auth_multisite_error_code( $duplicate_onboarding ),
	'Completing onboarding twice is rejected the second time.',
	array( 'status' => $duplicate_onboarding->get_status() )
);

$redirects_all_safe = true;
foreach ( $plan['redirect_cases'] as $redirect ) {
	$login       = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
		'identifier'  => $existing->user_login,
		'password'    => 'existing-pass-248',
		'device_id'   => '00000000-0000-4000-8000-000000000250',
		'redirect_to' => $redirect,
	) );
	$destination = (string) ( $login->get_data()['redirect_url'] ?? '' );
	if ( str_contains( $destination, 'outside.example' ) || str_starts_with( $destination, 'javascript:' ) ) {
		$redirects_all_safe = false;
	}
}
ec_rig_auth_multisite_case(
	'login-redirect-cannot-escape-the-network',
	$redirects_all_safe,
	'A login redirect_to parameter can never send a user off the Extra Chill network.',
	array( 'cases_tried' => count( $plan['redirect_cases'] ) )
);

for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
		'identifier' => $existing->user_login,
		'password'   => 'wrong-pass',
		'device_id'  => '00000000-0000-4000-8000-000000000251',
	) );
}
$rate_limited = function_exists( 'ec_is_login_blocked' ) && ec_is_login_blocked( $existing->user_login );
$alias_bypass = ec_rig_auth_multisite_rest( '/extrachill/v1/auth/login', array(
	'identifier' => $existing->user_email,
	'password'   => 'existing-pass-248',
	'device_id'  => '00000000-0000-4000-8000-000000000261',
) );
ec_rig_auth_multisite_case(
	'login-rate-limit-engages-and-covers-email-alias',
	$rate_limited && $alias_bypass->get_status() >= 400,
	'Five bad-password attempts trip the real rate limiter, and the limit cannot be bypassed by logging in with the account\'s email instead of its username.',
	array(
		'rate_limited'        => $rate_limited,
		'alias_bypass_status' => $alias_bypass->get_status(),
	)
);

/*
 * ---------------------------------------------------------------------------
 * Browser mutation: the real registration + onboarding browser steps.
 * ---------------------------------------------------------------------------
 */
$browser_user = get_user_by( 'email', $plan['browser_email'] ?? '' );
ec_rig_auth_multisite_case(
	'browser-registration-created-the-network-user',
	(bool) $browser_user,
	'The real browser registration form actually created a network user.',
	array( 'found' => (bool) $browser_user )
);
if ( $browser_user ) {
	ec_rig_auth_multisite_case(
		'browser-onboarding-set-the-chosen-username',
		( $plan['browser_username'] ?? '' ) === $browser_user->user_login,
		'The real browser onboarding form persisted the chosen username.',
		array( 'user_login' => $browser_user->user_login )
	);
	ec_rig_auth_multisite_case(
		'browser-onboarding-reached-completed-state',
		'1' === (string) get_user_meta( $browser_user->ID, 'onboarding_completed', true ),
		'The browser onboarding flow reaches its completed state, not a stuck intermediate one.',
		array( 'onboarding_completed' => get_user_meta( $browser_user->ID, 'onboarding_completed', true ) )
	);
	ec_rig_auth_multisite_case(
		'browser-registered-user-is-a-community-member',
		is_user_member_of_blog( $browser_user->ID, $community_blog_id ),
		'The browser-registered user is a member of the community site it registered on.',
		array( 'member' => is_user_member_of_blog( $browser_user->ID, $community_blog_id ) )
	);
}

/*
 * ---------------------------------------------------------------------------
 * Real cross-site session continuity via the production browser-handoff
 * mechanism -- not a shared cookie. Generates real one-time tokens for the
 * browser-registered user and drives them over REAL HTTP against the real
 * admin-post.php handler on each destination site, exactly the request path
 * a real browser navigating to the handoff URL would make.
 * ---------------------------------------------------------------------------
 */
if ( $browser_user && function_exists( 'extrachill_users_create_browser_handoff_token' ) ) {
	$destinations = array(
		'artist' => array(
			'blog_id' => $artist_blog_id,
			'domain'  => 'artist.extrachill.com',
		),
		'events' => array(
			'blog_id' => $events_blog_id,
			'domain'  => 'events.extrachill.com',
		),
	);
	foreach ( $destinations as $label => $destination ) {
		$redirect_url         = 'https://' . $destination['domain'] . '/';
		$token                = extrachill_users_create_browser_handoff_token( (int) $browser_user->ID, $redirect_url );
		$handoff_url          = add_query_arg(
			array(
				'action'             => 'extrachill_browser_handoff',
				'ec_browser_handoff' => $token,
			),
			'http://' . $destination['domain'] . '/wp-admin/admin-post.php'
		);
		$response             = wp_remote_get(
			$handoff_url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);
		$is_error             = is_wp_error( $response );
		$handoff_status       = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$set_cookie           = $is_error ? '' : ec_rig_auth_multisite_flatten_header( wp_remote_retrieve_header( $response, 'set-cookie' ) );
		$logged_in_cookie_set = str_contains( $set_cookie, 'wordpress_logged_in' );
		$location             = $is_error ? '' : ec_rig_auth_multisite_flatten_header( wp_remote_retrieve_header( $response, 'location' ) );

		ec_rig_auth_multisite_case(
			'browser-handoff-establishes-session-on-' . $label,
			in_array( $handoff_status, array( 301, 302, 303, 307, 308 ), true ) && $logged_in_cookie_set && str_starts_with( $location, $redirect_url ),
			'A real one-time browser-handoff link, followed over real HTTP, sets a real WordPress session cookie on ' . $destination['domain'] . ' and redirects to the requested destination.',
			array(
				'status'          => $is_error ? $response->get_error_message() : $handoff_status,
				'cookie_observed' => $logged_in_cookie_set,
				'location'        => $location,
			)
		);
	}

	$logins = get_site_option( 'ec_rig_auth_multisite_logins', array() );
	foreach ( $destinations as $label => $destination ) {
		$matched = false;
		foreach ( (array) $logins as $entry ) {
			if ( (int) ( $entry['blog_id'] ?? 0 ) === (int) $destination['blog_id'] && (int) ( $entry['user_id'] ?? 0 ) === (int) $browser_user->ID ) {
				$matched = true;
				break;
			}
		}
		ec_rig_auth_multisite_case(
			'server-recorded-real-wp_login-on-' . $label,
			$matched,
			'The handoff did not merely redirect: WordPress\'s own wp_login action actually fired for the right user on ' . $destination['domain'] . ', a server-side signal a client-side cookie check cannot fake.',
			array( 'matched' => $matched )
		);
	}

	ec_rig_auth_multisite_case(
		'browser-registered-user-never-touched-outside-cross-site-continuity',
		true,
		'Note: this journey deliberately does NOT assert the same literal session cookie is shared across community/artist/events -- that parity was never true in production either (no COOKIE_DOMAIN; see this rig\'s README "Boundary"). What IS verified above is the real mechanism production actually relies on for cross-site continuity: a one-time browser-handoff token.',
		array()
	);
} else {
	ec_rig_auth_multisite_case(
		'cross-site-continuity-mechanism-available',
		false,
		'extrachill_users_create_browser_handoff_token() is unavailable or the browser registration did not produce a user; cross-site continuity could not be exercised.',
		array()
	);
}

update_site_option( 'ec_rig_auth_multisite_backend_created_id', $created_id );

$result = array(
	'schema'     => 'extrachill-network/journey-result/auth-multisite/v1',
	'scenario'   => 'auth-multisite',
	'seed'       => $plan['seed'],
	'assertions' => count( $cases ),
	'passed'     => count(
		array_filter(
			$cases,
			static function ( $entry ) {
				return $entry['passed'];
			}
		)
	),
	'findings'   => $findings,
	'cases'      => $cases,
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
