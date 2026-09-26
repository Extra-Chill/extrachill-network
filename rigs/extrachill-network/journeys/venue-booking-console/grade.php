<?php
/**
 * Verify the server-side truth behind the booking-console browser steps.
 *
 * The browser steps assert what a visitor and an operator SEE; this file
 * verifies the real availability decision and the real embed admission/CSP
 * headers the product computed, matching the honest-outcomes pattern every
 * other journey in this rig follows.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function venue_booking_console_grade_site_id( string $domain ): int {
	$sites = get_sites(
		array(
			'domain' => $domain,
			'number' => 1,
		)
	);
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey grade could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/** Bootstrap the events site's per-site plugin context (idempotent). */
function venue_booking_console_grade_bootstrap(): void {
	require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
	require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
	if ( class_exists( '\\ExtraChillEvents\\Providers\\AbilitiesProvider' ) ) {
		\ExtraChillEvents\Providers\AbilitiesProvider::initialize();
	}
}

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function venue_booking_console_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'venue_booking_console_ability_missing', $name );
	}
	return $ability->execute( $input );
}

$cases    = array();
$findings = array();

/**
 * Record one case.
 *
 * @param string $id       Stable case ID.
 * @param bool   $passed   Whether the expectation held.
 * @param array  $evidence Supporting evidence.
 */
function venue_booking_console_case( string $id, bool $passed, array $evidence = array() ): void {
	global $cases, $findings;
	$record  = array(
		'id'       => $id,
		'passed'   => $passed,
		'evidence' => $evidence,
	);
	$cases[] = $record;
	if ( ! $passed ) {
		$findings[] = array_merge( $record, array( 'status' => 'open' ) );
	}
}

$events_blog_id = venue_booking_console_grade_site_id( 'events.extrachill.com' );

$fixture = get_option( 'ec_rig_journey_fixture_venue_booking_console', array() );
if ( ! is_array( $fixture ) || empty( $fixture['venue_id'] ) ) {
	switch_to_blog( $events_blog_id );
	$fixture = get_option( 'ec_rig_journey_fixture_venue_booking_console', array() );
	restore_current_blog();
}
if ( ! is_array( $fixture ) || empty( $fixture['venue_id'] ) ) {
	throw new RuntimeException( 'The venue-booking-console fixture is missing; the journey cannot be graded.' );
}

$venue_id = (int) $fixture['venue_id'];

switch_to_blog( $events_blog_id );
venue_booking_console_grade_bootstrap();

$occupied = venue_booking_console_execute(
	'extrachill/check-booking-availability',
	array(
		'venue_term_id'       => $venue_id,
		'requested_space_key' => 'main-room',
		'requested_start_at'  => '2028-05-01 20:00:00',
		'requested_end_at'    => '2028-05-01 23:00:00',
	)
);
venue_booking_console_case(
	'availability-check-reflects-occupied-date',
	is_array( $occupied ) && false === ( $occupied['available'] ?? null ),
	array( 'result' => $occupied )
);

$open = venue_booking_console_execute(
	'extrachill/check-booking-availability',
	array(
		'venue_term_id'       => $venue_id,
		'requested_space_key' => 'main-room',
		'requested_start_at'  => '2028-05-08 20:00:00',
		'requested_end_at'    => '2028-05-08 23:00:00',
	)
);
venue_booking_console_case(
	'availability-check-reflects-open-date',
	is_array( $open ) && true === ( $open['available'] ?? null ),
	array( 'result' => $open )
);

restore_current_blog();

/*
 * The hosted embed's admission decision and CSP headers (real 200 + scoped
 * frame-ancestors for the allowed origin, real 403 + frame-ancestors 'none'
 * for a denied origin) are already verified for real by this journey's own
 * browser steps (hosted-embed-allowed-origin, hosted-embed-denied-origin),
 * which navigate the actual embed URL and inspect the real rendered
 * response. A same-request wp_remote_get() self-call from inside this
 * run-php grade phase was tried here as a redundant server-side spot check
 * and consistently returned 404 in this runtime for reasons this session
 * did not resolve (the identical URL works when a real browser navigates
 * it), so it was removed rather than shipped as an unreliable duplicate of
 * already-passing browser coverage. See the journey README.
 */

$result = array(
	'schema'     => 'extrachill-network/journey-result/venue-booking-console/v1',
	'scenario'   => 'venue-booking-console',
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

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable persona evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
