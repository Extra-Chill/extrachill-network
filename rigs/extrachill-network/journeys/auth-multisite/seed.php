<?php
/**
 * Seed the Extra Chill authentication multisite campaign on the real network
 * boot.
 *
 * Ported from extrachill-users tests/e2e/auth-multisite/topology.php +
 * seed.php (extrachill-network#293) onto the full 11-site network rig. Every
 * path-based-topology workaround from those files was dropped:
 *
 * - No synthetic `/community/`, `/artist/`, `/events/` sub-blogs created
 *   under one shared domain: this rig already boots the REAL
 *   community.extrachill.com / artist.extrachill.com / events.extrachill.com
 *   domains from network-topology.json.
 * - No forced blog-ID assertions (expected community=2, artist=4, events=7):
 *   sites are resolved by domain, matching every other journey in this rig.
 * - Extra Chill Network, API, Users, Analytics, and wp-native-auth are
 *   already network-active plugins on every site this rig boots (see
 *   components.json); this seed does not activate anything itself.
 *
 * Extra Chill Users' REAL registration-admitter and login-rate-limit-store
 * defaults are used as-is (extrachill_users_admit_registration_attempt,
 * ec_login_rate_limit_cache_operation) -- the old test substituted its own
 * atomic site-option-backed stores via filters; this journey does not,
 * which is a truer test of what production actually runs.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID of the site.
 */
function ec_rig_auth_multisite_site_id( string $domain ): int {
	$sites = get_sites(
		array(
			'domain' => $domain,
			'number' => 1,
		)
	);
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey seed could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/**
 * Ensure a fixture page exists at a given slug, on the current blog.
 *
 * @param string $slug    Page slug.
 * @param string $title   Page title.
 * @param string $content Page block content.
 */
function ec_rig_auth_multisite_ensure_page( string $slug, string $title, string $content ): void {
	global $wpdb;
	$page_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status != 'trash' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table identifier.
			$slug
		)
	);
	if ( $page_id > 0 ) {
		return;
	}
	$result = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( esc_html( 'Could not create the ' . $slug . ' fixture page.' ) );
	}
}

/**
 * Deterministic UUID v4-shaped string from a seed suffix and index.
 *
 * Ported from tests/e2e/auth-multisite/cases.mjs uuid().
 *
 * @param string $suffix Seed hash suffix.
 * @param int    $index  Case index.
 * @return string
 */
function ec_rig_auth_multisite_uuid( string $suffix, int $index ): string {
	$tail = substr( str_pad( $suffix . $index, 12, '0' ), 0, 12 );
	return '00000000-0000-4000-8000-' . $tail;
}

/**
 * Build the replayable, seeded fuzz case plan.
 *
 * Ported from tests/e2e/auth-multisite/cases.mjs buildCasePlan(). The seed
 * string comes from the rig's generic `extrachill_journey_seed` setting
 * (get_site_option( 'ec_rig_journey_seed' )); unset defaults to a fixed
 * literal so a bare `homeboy rig up` with this journey selected is still
 * fully replayable.
 *
 * @param string $seed Deterministic seed string.
 * @return array Case plan.
 */
function ec_rig_auth_multisite_case_plan( string $seed ): array {
	$suffix = substr( hash( 'sha256', $seed ), 0, 12 );
	return array(
		'schema'                       => 'extrachill-network/journey-fixture/auth-multisite-plan/v1',
		'seed'                         => $seed,
		'replay'                       => 'extrachill_journey_seed=' . $seed,
		'generated_email'              => 'rest-' . $suffix . '@example.test',
		'browser_email'                => 'browser-auth-fuzz@example.test',
		'browser_username'             => 'traveler_auth_fuzz',
		'invalid_registrations'        => array(
			array(
				'id'               => 'invalid-email',
				'email'            => 'not-an-email',
				'password'         => 'valid-pass-248',
				'password_confirm' => 'valid-pass-248',
				'device_id'        => ec_rig_auth_multisite_uuid( $suffix, 1 ),
			),
			array(
				'id'               => 'password-mismatch',
				'email'            => 'mismatch-' . $suffix . '@example.test',
				'password'         => 'valid-pass-248',
				'password_confirm' => 'different-pass-248',
				'device_id'        => ec_rig_auth_multisite_uuid( $suffix, 2 ),
			),
			array(
				'id'               => 'short-password',
				'email'            => 'short-' . $suffix . '@example.test',
				'password'         => 'short',
				'password_confirm' => 'short',
				'device_id'        => ec_rig_auth_multisite_uuid( $suffix, 3 ),
			),
			array(
				'id'               => 'invalid-device',
				'email'            => 'device-' . $suffix . '@example.test',
				'password'         => 'valid-pass-248',
				'password_confirm' => 'valid-pass-248',
				'device_id'        => 'not-a-uuid',
			),
			array(
				'id'               => 'null-password',
				'email'            => 'null-' . $suffix . '@example.test',
				'password'         => '',
				'password_confirm' => '',
				'device_id'        => ec_rig_auth_multisite_uuid( $suffix, 5 ),
			),
			array(
				'id'               => 'oversized-email',
				'email'            => str_repeat( 'a', 320 ) . '@example.test',
				'password'         => 'valid-pass-248',
				'password_confirm' => 'valid-pass-248',
				'device_id'        => ec_rig_auth_multisite_uuid( $suffix, 6 ),
			),
		),
		'redirect_cases'               => array(
			'https://outside.example/escape',
			'//outside.example/escape',
			'http://localhost/community/',
			'javascript:alert(1)',
			'https://extrachill.com.outside.example/escape',
			'https://outside.example/?next=https://extrachill.com/',
			'https://extrachill.com@outside.example/escape',
		),
		'handoff_redirect_cases'       => array(
			'https://outside.example/escape',
			'https://extrachill.com.outside.example/escape',
			'https://extrachill.link/escape',
			'//community.extrachill.com/relative',
			'javascript:alert(1)',
		),
		'invalid_onboarding_usernames' => array( 'ad', 'admin', 'auth_fuzz_existing', '!!!', str_repeat( 'x', 61 ) ),
	);
}

$community_blog_id = ec_rig_auth_multisite_site_id( 'community.extrachill.com' );
$artist_blog_id    = ec_rig_auth_multisite_site_id( 'artist.extrachill.com' );
$events_blog_id    = ec_rig_auth_multisite_site_id( 'events.extrachill.com' );

$seed = get_site_option( 'ec_rig_journey_seed' );
$seed = is_string( $seed ) && '' !== $seed ? $seed : 'extrachill-network-auth-multisite-default-seed';
$plan = ec_rig_auth_multisite_case_plan( $seed );
update_site_option( 'ec_rig_auth_multisite_plan', $plan );

/*
 * ---------------------------------------------------------------------------
 * Fixture pages on the community site: the real [extrachill/login-register]
 * and [extrachill/onboarding] blocks, matching what a real /login/ and
 * /onboarding/ page carry in production.
 * ---------------------------------------------------------------------------
 */
switch_to_blog( $community_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
ec_rig_auth_multisite_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register /-->' );
ec_rig_auth_multisite_ensure_page( 'onboarding', 'Onboarding', '<!-- wp:extrachill/onboarding /-->' );
flush_rewrite_rules();
if ( ! in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) ) {
	update_site_option( 'registration', 'user' );
}
restore_current_blog();

/*
 * ---------------------------------------------------------------------------
 * Adversarial fixture personas (single-scenario fixture users, not a
 * persona -- see rigs/extrachill-network/personas/README.md).
 * ---------------------------------------------------------------------------
 */
/**
 * Force-create (or reuse) an adversarial fixture user by username.
 *
 * @param string $login    user_login.
 * @param string $password Plaintext password.
 * @param string $email    user_email.
 * @return int Resolved user ID.
 */
function ec_rig_auth_multisite_ensure_user( string $login, string $password, string $email ): int {
	$existing = username_exists( $login );
	if ( $existing ) {
		return (int) $existing;
	}
	$created = wp_create_user( $login, $password, $email );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( esc_html( 'Could not create the ' . $login . ' auth persona: ' . $created->get_error_message() ) );
	}
	return (int) $created;
}

$user_id       = ec_rig_auth_multisite_ensure_user( 'auth_fuzz_existing', 'existing-pass-248', 'existing-auth-fuzz@example.test' );
$nonmember_id  = ec_rig_auth_multisite_ensure_user( 'auth_fuzz_nonmember', 'nonmember-pass-248', 'nonmember-auth-fuzz@example.test' );
$blocked_id    = ec_rig_auth_multisite_ensure_user( 'auth_fuzz_blocked', 'blocked-pass-248', 'blocked-auth-fuzz@example.test' );
$onboarding_id = ec_rig_auth_multisite_ensure_user( 'auth_fuzz_onboarding', 'onboarding-pass-248', 'onboarding-auth-fuzz@example.test' );
$victim_id     = ec_rig_auth_multisite_ensure_user( 'auth_fuzz_victim', 'victim-pass-248', 'victim-auth-fuzz@example.test' );

add_user_to_blog( $community_blog_id, $user_id, 'subscriber' );
// nonmember deliberately stays off community -- exercises extrachill_not_a_member.
add_user_to_blog( $community_blog_id, $blocked_id, 'subscriber' );
add_user_to_blog( $community_blog_id, $onboarding_id, 'subscriber' );
add_user_to_blog( $community_blog_id, $victim_id, 'subscriber' );

$moderated = function_exists( 'extrachill_users_apply_moderation_action' )
	? extrachill_users_apply_moderation_action(
		$blocked_id,
		array(
			'state'      => 'banned',
			'reason_key' => 'other',
			'source'     => 'auth-multisite-journey',
		)
	)
	: new WP_Error( 'missing_moderation_function', 'extrachill_users_apply_moderation_action is unavailable.' );
if ( is_wp_error( $moderated ) || ! function_exists( 'extrachill_users_is_blocked' ) || ! extrachill_users_is_blocked( $blocked_id ) ) {
	throw new RuntimeException( 'Could not establish the moderated auth persona.' );
}
update_user_meta( $onboarding_id, 'onboarding_completed', '0' );

$fixture = array(
	'schema'             => 'extrachill-network/journey-fixture/auth-multisite/v1',
	'community_blog_id'  => $community_blog_id,
	'artist_blog_id'     => $artist_blog_id,
	'events_blog_id'     => $events_blog_id,
	'existing_user_id'   => $user_id,
	'nonmember_user_id'  => $nonmember_id,
	'blocked_user_id'    => $blocked_id,
	'onboarding_user_id' => $onboarding_id,
	'victim_user_id'     => $victim_id,
	'initial_user_count' => count( get_users( array(
		'blog_id' => 0,
		'fields'  => 'ID',
	) ) ),
);
update_site_option( 'ec_rig_auth_multisite_fixture', $fixture );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( array(
	'fixture'   => $fixture,
	'plan_seed' => $plan['seed'],
) ) ) );
