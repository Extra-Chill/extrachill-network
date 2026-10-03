<?php
/**
 * Seed a real public booking-inquiry venue, a real operator console fixture,
 * and a real hosted-embed admission scenario on the events site.
 *
 * Ported from extrachill-events/tests/browser/{booking-inquiry,booking-
 * correspondence,booking-form-preview,booking-setup-copy,booking-embed}
 * .evidence.js (extrachill-events#292). See journey.json's description for
 * what changed from the mocked-fixture originals.
 *
 * @package ExtraChillNetwork
 */

const VENUE_BOOKING_CONSOLE_OWNER_USER_ID = 401;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function venue_booking_console_site_id( string $domain ): int {
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

/** Bootstrap the events site's per-site plugin context (idempotent). */
function venue_booking_console_bootstrap(): void {
	require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
	require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
	if ( ! post_type_exists( 'data_machine_events' ) && class_exists( '\\DataMachineEvents\\Core\\Event_Post_Type' ) ) {
		\DataMachineEvents\Core\Event_Post_Type::register();
	}
	if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
		extrachill_events_register_taxonomies();
	}
	if ( class_exists( '\\ExtraChillEvents\\Providers\\AbilitiesProvider' ) ) {
		\ExtraChillEvents\Providers\AbilitiesProvider::initialize();
	}
}

/**
 * Force-create (or reuse) a fixture user at an exact, deterministic ID.
 *
 * @param int    $user_id Forced user ID.
 * @param string $login   user_login.
 * @param string $email   user_email.
 * @return WP_User
 */
function venue_booking_console_force_user( int $user_id, string $login, string $email ): WP_User {
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

$events_blog_id = venue_booking_console_site_id( 'events.extrachill.com' );

$evidence = array(
	'schema' => 'extrachill-network/journey-fixture/venue-booking-console/v1',
	'steps'  => array(),
);

wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );
venue_booking_console_bootstrap();

if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) ) {
	throw new RuntimeException( 'BookingSchema is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueBookingConfig' ) ) {
	throw new RuntimeException( 'VenueBookingConfig is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\VenueMembershipRepository' ) ) {
	throw new RuntimeException( 'VenueMembershipRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! class_exists( '\\ExtraChillEvents\\Core\\BookingRepository' ) ) {
	throw new RuntimeException( 'BookingRepository is unavailable after bootstrapping extrachill-events.' );
}
if ( ! \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
	throw new RuntimeException( 'The booking schema tables are not ready; the rig activation step failed to fire extrachill-events\' activation hook (BookingSchema::install()).' );
}

$venue = wp_insert_term( 'Console Fixture Room', 'venue', array( 'slug' => 'console-fixture-room' ) );
if ( is_wp_error( $venue ) ) {
	$existing_venue = get_term_by( 'slug', 'console-fixture-room', 'venue' );
	if ( ! $existing_venue ) {
		throw new RuntimeException( esc_html( 'Could not create the console fixture venue: ' . $venue->get_error_message() ) );
	}
	$venue_id = (int) $existing_venue->term_id;
} else {
	$venue_id = (int) $venue['term_id'];
}
update_term_meta( $venue_id, '_venue_address', '77 Console Row' );
update_term_meta( $venue_id, '_venue_city', 'Charleston' );
update_term_meta( $venue_id, '_venue_state', 'SC' );
update_term_meta( $venue_id, '_venue_zip', '29403' );
update_term_meta( $venue_id, '_venue_country', 'US' );
update_term_meta( $venue_id, '_venue_timezone', 'America/New_York' );

$config               = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->defaults();
$config['enabled']    = true;
$config['revision']   = 1;
$config['spaces']     = array(
	array(
		'key'        => 'main-room',
		'name'       => 'Main Room',
		'is_default' => true,
	),
);
$config['intake']     = array(
	'version' => 1,
	'fields'  => array(
		array(
			'key'      => 'website',
			'type'     => 'url',
			'label'    => 'Artist website',
			'required' => false,
		),
		array(
			'key'      => 'event_type',
			'type'     => 'select',
			'label'    => 'Event type',
			'required' => true,
			'options'  => array( 'Concert', 'Market', 'Other' ),
		),
		array(
			'key'          => 'other_event',
			'type'         => 'text',
			'label'        => 'Other event details',
			'required'     => true,
			'visible_when' => array(
				'field' => 'event_type',
				'value' => 'Other',
			),
		),
		array(
			'key'      => 'press_links',
			'type'     => 'url',
			'label'    => 'Press links',
			'required' => false,
		),
	),
);
$config['embed']      = array( 'allowed_parent_origins' => array( 'https://events.extrachill.com' ) );
$config['updated_at'] = gmdate( 'Y-m-d H:i:s' );
update_term_meta( $venue_id, \ExtraChillEvents\Core\VenueBookingConfig::META_KEY, $config );

$stored_config = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->get( $venue_id );
if ( is_wp_error( $stored_config ) ) {
	throw new RuntimeException( esc_html( 'The seeded console-fixture venue configuration is invalid: ' . $stored_config->get_error_message() ) );
}

/*
 * The `venue_booking` feature sits behind a `team` rollout ceiling
 * (extrachill-events LifecycleProvider::register_feature_ceilings()). That
 * tier's bypass is `user_can( $id, 'manage_options' )` OR
 * `ec_is_team_member( $id )` -- a plain `subscriber` satisfies neither, so
 * the owner fixture needs the real `extra_chill_team` role, matching
 * journeys/gardner-venue-booking's persona.
 */
if ( function_exists( 'ec_users_register_team_role' ) ) {
	ec_users_register_team_role();
}
$owner = venue_booking_console_force_user( VENUE_BOOKING_CONSOLE_OWNER_USER_ID, 'venue_booking_console_owner', 'venue-booking-console-owner@example.invalid' );
if ( ! is_user_member_of_blog( VENUE_BOOKING_CONSOLE_OWNER_USER_ID, $events_blog_id ) ) {
	add_user_to_blog( $events_blog_id, VENUE_BOOKING_CONSOLE_OWNER_USER_ID, get_role( 'extra_chill_team' ) ? 'extra_chill_team' : 'subscriber' );
}
if ( get_role( 'extra_chill_team' ) ) {
	$owner->set_role( 'extra_chill_team' );
}
$owner->add_cap( 'access_events_admin' );

$ability    = wp_get_ability( 'extrachill/create-venue-membership' );
$membership = $ability ? $ability->execute(
	array(
		'venue_term_id' => $venue_id,
		'user_id'       => VENUE_BOOKING_CONSOLE_OWNER_USER_ID,
		'is_owner'      => true,
	)
) : new WP_Error( 'venue_booking_console_ability_missing', 'extrachill/create-venue-membership' );
if ( is_wp_error( $membership ) ) {
	( new \ExtraChillEvents\Core\VenueMembershipRepository() )->create(
		array(
			'venue_term_id'      => $venue_id,
			'user_id'            => VENUE_BOOKING_CONSOLE_OWNER_USER_ID,
			'is_owner'           => true,
			'status'             => 'active',
			'created_by_user_id' => 1,
		)
	);
}

/*
 * One occupied date (2028-05-01, main-room, 20:00-23:00) so the public
 * availability check has a real unavailable date to find; 2028-05-08 stays
 * open for the "available" case. `check-booking-availability` reads real
 * HOLDS (VenueBookingHoldRepository::public_interval_availability()), not
 * merely submitted inquiries -- verified empirically: a plain submitted
 * inquiry on this date did NOT make the date read as unavailable. A real
 * hold requires the negotiate -> select-performance -> create-hold sequence,
 * the same one journeys/gardner-venue-booking exercises, run here as the
 * owner. Written through BookingRepository (the public
 * create-booking-inquiry ability's admission saga serializes on the
 * MySQL-only GET_LOCK primitive, which this runtime does not provide --
 * covered instead by journeys/booking-invariants).
 */
$repository = new \ExtraChillEvents\Core\BookingRepository();
$occupied   = $repository->find_inquiry( $venue_id, 'console-fixture-occupied-date' );
if ( ! is_array( $occupied ) ) {
	$occupied = $repository->create(
		array(
			'venue_term_id'           => $venue_id,
			'inquiry_idempotency_key' => 'console-fixture-occupied-date',
			'artist_name'             => 'Console Fixture Occupant',
			'contact_name'            => 'Console Fixture Contact',
			'contact_email'           => 'console-fixture-contact@example.invalid',
			'requested_space_key'     => 'main-room',
			'requested_start_at'      => '2028-05-01 20:00:00',
			'requested_end_at'        => '2028-05-01 23:00:00',
			'intake'                  => array(
				'config_revision' => 1,
				'message'         => 'Occupies the date the availability-check browser step expects to be unavailable.',
				'fields'          => array(),
				'consent'         => array(
					'id'       => 'booking-privacy',
					'version'  => 1,
					'accepted' => true,
				),
			),
		)
	);
}
if ( is_array( $occupied ) && 'submitted' === ( $occupied['status'] ?? '' ) ) {
	wp_set_current_user( VENUE_BOOKING_CONSOLE_OWNER_USER_ID );
	$under_review                            = wp_get_ability( 'extrachill/transition-venue-booking' )->execute(
		array(
			'booking_id'       => (int) $occupied['id'],
			'to_status'        => 'under_review',
			'expected_version' => (int) $occupied['version'],
		)
	);
	$negotiating                             = is_array( $under_review ) ? wp_get_ability( 'extrachill/transition-venue-booking' )->execute(
		array(
			'booking_id'       => (int) $occupied['id'],
			'to_status'        => 'negotiating',
			'expected_version' => (int) $under_review['version'],
		)
	) : $under_review;
	$performance                             = is_array( $negotiating ) ? wp_get_ability( 'extrachill/select-venue-booking-performance' )->execute(
		array(
			'booking_id'       => (int) $occupied['id'],
			'expected_version' => (int) $negotiating['version'],
			'space_key'        => 'main-room',
			'start_at'         => '2028-05-01 20:00:00',
			'end_at'           => '2028-05-01 23:00:00',
		)
	) : $negotiating;
	$hold                                    = is_array( $performance ) ? wp_get_ability( 'extrachill/create-booking-hold' )->execute(
		array(
			'booking_id'               => (int) $occupied['id'],
			'expected_booking_version' => (int) $performance['version'],
		)
	) : $performance;
	$evidence['steps']['occupied_date_hold'] = array( 'ok' => is_array( $hold ) );
	if ( ! is_array( $hold ) ) {
		throw new RuntimeException(
			esc_html(
				'Could not create the occupied-date hold the availability-check browser step depends on: ' .
				wp_json_encode(
					array(
						'under_review' => is_wp_error( $under_review ) ? $under_review->get_error_message() : null,
						'negotiating'  => is_wp_error( $negotiating ) ? $negotiating->get_error_message() : null,
						'performance'  => is_wp_error( $performance ) ? $performance->get_error_message() : null,
						'hold'         => is_wp_error( $hold ) ? $hold->get_error_message() : null,
					)
				)
			)
		);
	}
	wp_set_current_user( 1 );
}

/* The console is a front-end route, not wp-admin (reused across journeys). */
$venue_settings_page = get_page_by_path( 'venue-settings' );
if ( ! ( $venue_settings_page instanceof WP_Post ) ) {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Venue Settings',
			'post_name'    => 'venue-settings',
			'post_content' => '<!-- wp:extrachill/venue-settings /-->',
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( 'Could not publish the venue settings route.' );
	}
}

$evidence['venue_id'] = $venue_id;
$evidence['owner_id'] = VENUE_BOOKING_CONSOLE_OWNER_USER_ID;

update_option( 'ec_rig_journey_fixture_venue_booking_console', $evidence, false );

restore_current_blog();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
