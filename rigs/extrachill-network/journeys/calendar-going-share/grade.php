<?php
/**
 * Grade calendar-going-share and emit the journey-result contract.
 *
 * The journeys workflow decides pass/fail from the EXTRACHILL_JOURNEY_RESULT
 * line ('regressions' and 'unpin'); a grade that only throws or exits cleanly
 * produces no result and is reported as a failed run.
 *
 * @package ExtraChillNetwork
 */

$cgs_results = array();

$cgs_sites = get_sites(
	array(
		'domain' => 'events.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $cgs_sites ) ) {
	$cgs_results[] = array(
		'id'       => 'events-site-present',
		'task'     => 'The events site exists on the rig.',
		'outcome'  => 'finding',
		'evidence' => array(),
	);
} else {
	$cgs_blog_id = (int) $cgs_sites[0]->blog_id;
	switch_to_blog( $cgs_blog_id );
	$cgs_featured = get_page_by_path( 'channel-bluff-charleston-pour-house', OBJECT, 'data_machine_events' );
	global $wpdb;
	$cgs_table = function_exists( 'extrachill_users_concert_tracking_table_name' ) ? extrachill_users_concert_tracking_table_name() : $wpdb->base_prefix . 'ec_concert_tracking';
	$cgs_rows  = $cgs_featured ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cgs_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", 230, $cgs_featured->ID, $cgs_blog_id ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	restore_current_blog();

	$cgs_results[] = array(
		'id'       => 'featured-show-seeded',
		'task'     => 'The featured Channel Bluff show at Charleston Pour House exists.',
		'outcome'  => $cgs_featured ? 'pass' : 'finding',
		'evidence' => array( 'event_id' => $cgs_featured ? (int) $cgs_featured->ID : null ),
	);
	$cgs_results[] = array(
		'id'       => 'fan-marked-going',
		'task'     => 'Tapping Going records exactly one attendance for the fan on the featured show.',
		'outcome'  => 1 === $cgs_rows ? 'pass' : 'finding',
		'evidence' => array( 'attendance_rows' => $cgs_rows ),
	);
}

$cgs_known_findings = array();
$cgs_regressions    = array();
$cgs_unpin          = array();
foreach ( $cgs_results as &$cgs_result ) {
	$cgs_result['pinned_issue'] = $cgs_known_findings[ $cgs_result['id'] ] ?? null;
	if ( 'finding' === $cgs_result['outcome'] && null === $cgs_result['pinned_issue'] ) {
		$cgs_regressions[] = $cgs_result['id'];
	}
	if ( 'pass' === $cgs_result['outcome'] && null !== $cgs_result['pinned_issue'] ) {
		$cgs_unpin[] = $cgs_result['id'] . ' (' . $cgs_result['pinned_issue'] . ')';
	}
}
unset( $cgs_result );

$cgs_summary = array(
	'schema'      => 'extrachill-network/journey-result/calendar-going-share/v1',
	'results'     => $cgs_results,
	'regressions' => $cgs_regressions,
	'unpin'       => $cgs_unpin,
	'passed'      => count( array_filter( $cgs_results, static fn( $r ) => 'pass' === $r['outcome'] ) ),
	'findings'    => count( array_filter( $cgs_results, static fn( $r ) => 'finding' === $r['outcome'] ) ),
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $cgs_summary ) ) );
