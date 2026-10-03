<?php
/**
 * Seed a believable Charleston weekend for the calendar-going-share journey.
 *
 * Fixture only: fictional supporting show titles and Channel Bluff at public
 * Charleston venues, dated relative to the rig's clock so "This Weekend"
 * always has content. The featured show carries a demo ticket URL. A disposable
 * fan account (forced ID 230)
 * is created for the authenticated browser step. No production user, email,
 * or personal data is copied, and nothing here can reach production.
 *
 * Follows gardner-event-rsvp/seed.php: resolve sites by domain, bootstrap the
 * events site's per-site plugins explicitly (run-php executes on the primary
 * site), and write through real plugin functions.
 *
 * @package ExtraChillNetwork
 */

const CALENDAR_DEMO_FAN_ID = 230;

$sites = get_sites(
	array(
		'domain' => 'events.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $sites ) ) {
	throw new RuntimeException( esc_html( 'Journey seed could not resolve site by domain: events.extrachill.com' ) );
}
$events_blog_id = (int) $sites[0]->blog_id;

// Force the fan to the ID the journey's auth-user-id expects.
global $wpdb;
if ( ! get_user_by( 'id', CALENDAR_DEMO_FAN_ID ) ) {
	$inserted = $wpdb->insert(
		$wpdb->users,
		array(
			'ID'              => CALENDAR_DEMO_FAN_ID,
			'user_login'      => 'charleston_demo_fan',
			'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
			'user_nicename'   => 'charleston-demo-fan',
			'user_email'      => 'charleston-demo-fan@example.invalid',
			'user_registered' => current_time( 'mysql', true ),
			'display_name'    => 'Charleston Music Fan',
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $inserted ) {
		throw new RuntimeException( esc_html( 'Unable to force-create the demo fan at ID ' . CALENDAR_DEMO_FAN_ID . '.' ) );
	}
	clean_user_cache( CALENDAR_DEMO_FAN_ID );
}
add_user_to_blog( $events_blog_id, CALENDAR_DEMO_FAN_ID, 'subscriber' );

switch_to_blog( $events_blog_id );

require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
if ( ! post_type_exists( 'data_machine_events' ) && class_exists( '\\DataMachineEvents\\Core\\Event_Post_Type' ) ) {
	\DataMachineEvents\Core\Event_Post_Type::register();
}
foreach ( array( 'Venue_Taxonomy', 'Promoter_Taxonomy', 'Event_Type_Taxonomy', 'Artist_Taxonomy' ) as $taxonomy_class ) {
	$fqcn = "\\DataMachineEvents\\Core\\{$taxonomy_class}";
	if ( class_exists( $fqcn ) ) {
		$fqcn::register();
	}
}
require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
if ( function_exists( 'extrachill_events_register_taxonomies' ) ) {
	extrachill_events_register_taxonomies();
}

/**
 * Get or create a term, returning its ID.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy.
 * @param array  $args     wp_insert_term args.
 * @return int
 */
function calendar_demo_term( string $name, string $taxonomy, array $args = array() ): int {
	$slug     = $args['slug'] ?? sanitize_title( $name );
	$existing = get_term_by( 'slug', $slug, $taxonomy );
	if ( $existing ) {
		return (int) $existing->term_id;
	}
	$created = wp_insert_term( $name, $taxonomy, $args );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( esc_html( "Could not create {$taxonomy} term {$name}: " . $created->get_error_message() ) );
	}
	return (int) $created['term_id'];
}

$usa_id        = calendar_demo_term( 'United States', 'location', array( 'slug' => 'usa' ) );
$sc_id         = calendar_demo_term( 'South Carolina', 'location', array(
	'slug'   => 'south-carolina',
	'parent' => $usa_id,
) );
$charleston_id = calendar_demo_term( 'Charleston', 'location', array(
	'slug'   => 'charleston',
	'parent' => $sc_id,
) );

$venues    = array(
	'Charleston Pour House' => '1977 Maybank Hwy',
	'The Royal American'    => '970 Morrison Dr',
	'Music Farm'            => '32 Ann St',
	'Lo-Fi Brewing'         => '2038 Meeting Street Rd',
);
$venue_ids = array();
foreach ( $venues as $venue_name => $address ) {
	$venue_ids[ $venue_name ] = calendar_demo_term( $venue_name, 'venue' );
	update_term_meta( $venue_ids[ $venue_name ], '_venue_address', $address );
	update_term_meta( $venue_ids[ $venue_name ], '_venue_city', 'Charleston' );
	update_term_meta( $venue_ids[ $venue_name ], '_venue_state', 'SC' );
}
$artist_id = calendar_demo_term( 'Channel Bluff', 'artist' );

// Upcoming Friday/Saturday/Sunday relative to the rig clock, so "This Weekend"
// always resolves to these shows.
$tz = wp_timezone();
// Anchor on the weekend the calendar's "This Weekend" scope shows: the current
// one when today is Friday-Sunday, otherwise the coming one. Using "next
// friday" on a Saturday/Sunday jumps a week ahead and empties the scope.
$today    = new DateTimeImmutable( 'today', $tz );
$weekday  = (int) $today->format( 'N' );
$friday   = $weekday >= 5 ? $today->modify( '-' . ( $weekday - 5 ) . ' days' ) : $today->modify( 'next friday' );
$saturday = $friday->modify( '+1 day' );
$sunday   = $friday->modify( '+2 days' );
// The featured show must still be upcoming: on Sunday, Saturday has passed.
$featured_day = 7 === $weekday ? $sunday : $saturday;

$events = array(
	array( 'channel-bluff-charleston-pour-house', 'Channel Bluff', $featured_day, '22:00', 'Charleston Pour House', 'https://tickets.example.invalid/channel-bluff', true ),
	array( 'charleston-weekend-fri-1', 'Marsh Lights', $friday, '20:00', 'The Royal American', '', false ),
	array( 'charleston-weekend-fri-2', 'Low Country Static', $friday, '21:30', 'Music Farm', '', false ),
	array( 'charleston-weekend-sat-1', 'Saltwater Saints', $saturday, '19:00', 'Lo-Fi Brewing', '', false ),
	array( 'charleston-weekend-sat-2', 'The Folly Road Band', $saturday, '20:30', 'The Royal American', '', false ),
	array( 'charleston-weekend-sun-1', 'Sunday Porch Sessions', $sunday, '17:00', 'Charleston Pour House', '', false ),
);

foreach ( $events as $event ) {
	list( $event_slug, $event_title, $event_date, $event_time, $venue_name, $ticket_url, $featured ) = $event;

	$attrs   = wp_json_encode(
		array(
			'startDate' => $event_date->format( 'Y-m-d' ),
			'endDate'   => $event_date->format( 'Y-m-d' ),
			'startTime' => $event_time,
			'endTime'   => '23:30',
			'venue'     => $venue_name,
			'ticketUrl' => $ticket_url,
			'price'     => $featured ? '$15 advance / $20 day of show' : '$10',
		)
	);
	$content = "<!-- wp:data-machine-events/event-details {$attrs} -->\n"
		. '<div class="wp-block-data-machine-events-event-details">'
		. "<!-- wp:paragraph -->\n<p>Live music in Charleston, South Carolina.</p>\n<!-- /wp:paragraph -->"
		. "</div>\n<!-- /wp:data-machine-events/event-details -->";

	$existing   = get_page_by_path( $event_slug, OBJECT, 'data_machine_events' );
	$event_post = array(
		'post_type'    => 'data_machine_events',
		'post_status'  => 'publish',
		'post_author'  => 1,
		'post_title'   => $event_title,
		'post_name'    => $event_slug,
		'post_content' => $content,
	);
	if ( $existing ) {
		$event_post['ID'] = (int) $existing->ID;
	}
	$event_id = wp_insert_post( $event_post, true );
	if ( is_wp_error( $event_id ) ) {
		throw new RuntimeException( esc_html( "Could not seed event {$event_slug}: " . $event_id->get_error_message() ) );
	}

	wp_set_object_terms( $event_id, array( $venue_ids[ $venue_name ] ), 'venue', false );
	wp_set_object_terms( $event_id, array( $charleston_id ), 'location', false );
	if ( $featured ) {
		wp_set_object_terms( $event_id, array( $artist_id ), 'artist', false );
	}
	if ( function_exists( 'data_machine_events_sync_datetime_meta' ) ) {
		data_machine_events_sync_datetime_meta( $event_id, get_post( $event_id ), false );
	}
	if ( $featured ) {
		update_post_meta( $event_id, '_ec_rig_demo_featured', 1 );
	}
}

// Start every run with the fan not yet marked Going on the featured show, so
// the journey's Going tap always marks (never toggles off).
$featured_post = get_page_by_path( 'channel-bluff-charleston-pour-house', OBJECT, 'data_machine_events' );
if ( $featured_post && function_exists( 'ec_users_unmark_event' ) ) {
	ec_users_unmark_event( CALENDAR_DEMO_FAN_ID, (int) $featured_post->ID, $events_blog_id );
}

restore_current_blog();
