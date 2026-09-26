<?php
/**
 * Seed the real /near-me/ and /my-shows/ pages on the events site.
 *
 * Ported from extrachill-events/tests/browser/near-me.evidence.js +
 * near-me-fixture.html and tests/browser/my-shows-registration.evidence.js
 * (extrachill-events#292). Both pages are ordinary WordPress pages the
 * plugin's own `the_content`/block-render hooks key off of
 * (`is_page( 'near-me' )`, the `extrachill/concert-stats` block); this seed
 * only needs to publish them with the right slugs -- no plugin-context
 * bootstrap is required because the browser steps make real front-end HTTP
 * requests, which load every plugin normally.
 *
 * @package ExtraChillNetwork
 */

const NEAR_ME_MY_SHOWS_OWNER_USER_ID = 501;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function near_me_my_shows_site_id( string $domain ): int {
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
 * Force-create (or reuse) a fixture user at an exact, deterministic ID.
 *
 * @param int    $user_id Forced user ID.
 * @param string $login   user_login.
 * @param string $email   user_email.
 * @return WP_User
 */
function near_me_my_shows_force_user( int $user_id, string $login, string $email ): WP_User {
	global $wpdb;
	$existing = get_user_by( 'id', $user_id );
	if ( ! $existing ) {
		$inserted = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $login,
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $login,
				'user_email'      => $email,
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $login,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( esc_html( 'Unable to force-create fixture user ' . $login . ' at ID ' . $user_id . '.' ) );
		}
		clean_user_cache( $user_id );
	}
	return new WP_User( $user_id );
}

$events_blog_id = near_me_my_shows_site_id( 'events.extrachill.com' );

$evidence = array(
	'schema' => 'extrachill-network/journey-fixture/near-me-and-my-shows/v1',
);

wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );

$near_me_page = get_page_by_path( 'near-me' );
if ( ! ( $near_me_page instanceof WP_Post ) ) {
	$near_me_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Near Me',
			'post_name'    => 'near-me',
			'post_content' => '',
		),
		true
	);
	if ( is_wp_error( $near_me_id ) ) {
		throw new RuntimeException( esc_html( 'Could not publish the near-me route: ' . $near_me_id->get_error_message() ) );
	}
}

$my_shows_page = get_page_by_path( 'my-shows' );
if ( ! ( $my_shows_page instanceof WP_Post ) ) {
	$my_shows_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'My Shows',
			'post_name'    => 'my-shows',
			'post_content' => '<!-- wp:extrachill/concert-stats /-->',
		),
		true
	);
	if ( is_wp_error( $my_shows_id ) ) {
		throw new RuntimeException( esc_html( 'Could not publish the my-shows route: ' . $my_shows_id->get_error_message() ) );
	}
}

$owner = near_me_my_shows_force_user( NEAR_ME_MY_SHOWS_OWNER_USER_ID, 'near_me_my_shows_owner', 'near-me-my-shows-owner@example.invalid' );
if ( ! is_user_member_of_blog( NEAR_ME_MY_SHOWS_OWNER_USER_ID, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, NEAR_ME_MY_SHOWS_OWNER_USER_ID, 'subscriber' );
}

update_option( 'ec_rig_journey_fixture_near_me_and_my_shows', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
