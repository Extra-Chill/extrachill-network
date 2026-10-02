<?php
/**
 * Grade calendar-going-share: the demo fan is marked Going on the featured show exactly once.
 *
 * @package ExtraChillNetwork
 */

$sites = get_sites(
	array(
		'domain' => 'events.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $sites ) ) {
	throw new RuntimeException( 'Events site is missing.' );
}
$events_blog_id = (int) $sites[0]->blog_id;
switch_to_blog( $events_blog_id );
$featured = get_page_by_path( 'charleston-weekend-feature', OBJECT, 'data_machine_events' );
if ( ! $featured ) {
	restore_current_blog();
	throw new RuntimeException( 'Featured Charleston event was not seeded.' );
}
global $wpdb;
$concert_table = function_exists( 'extrachill_users_concert_tracking_table_name' ) ? extrachill_users_concert_tracking_table_name() : $wpdb->base_prefix . 'ec_concert_tracking';
$going_rows    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$concert_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", 230, $featured->ID, $events_blog_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
restore_current_blog();
if ( 1 !== $going_rows ) {
	throw new RuntimeException( esc_html( 'Demo fan attendance row missing or duplicated for featured show: ' . $going_rows ) );
}
