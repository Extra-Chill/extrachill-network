<?php
/**
 * Grade the Gardner event-RSVP journey against the persona oracles.
 *
 * Ported from extrachill-events/tests/wp-codebox/gardner-event-rsvp-journey-grade.php
 * (extrachill-events#876) and extended for what the full network can now
 * exercise: the perk pass (issued, emailed, redeemed at the door) and the
 * main-site Local Scene card. Runs after every browser step has executed
 * against the real front end. Makes no product calls of its own beyond
 * read-only ability reads, one door-side redemption through the real
 * registered ability, and direct table reads of the exact tables the
 * product wrote during the browser interactions.
 *
 * Consumed oracles from personas/gardner.v1.json (pinned copy of
 * extra-chill-users/chris-gardner@1.0.0): task-completion, obvious-state,
 * reload-persistence, safe-retry, duplicate-prevention, attribution,
 * actionable-errors, jargon-avoidance, server-authorization.
 *
 * A finding here is a product usability defect, not a harness failure.
 *
 * @package ExtraChillNetwork
 */

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function ec_rig_grade_site_id( string $domain ): int {
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

/**
 * Execute a registered ability without bypassing its contract.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return mixed
 */
function ec_rig_grade_execute( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return new WP_Error( 'ec_rig_grade_ability_missing', $name );
	}
	return $ability->execute( $input );
}

$fixture = get_option( 'ec_rig_journey_fixture_gardner_event_rsvp', array() );
if ( ! is_array( $fixture ) || empty( $fixture['event_id'] ) ) {
	// The seed stores the fixture on the events site.
	$events_blog_for_fixture = ec_rig_grade_site_id( 'events.extrachill.com' );
	switch_to_blog( $events_blog_for_fixture );
	$fixture = get_option( 'ec_rig_journey_fixture_gardner_event_rsvp', array() );
	restore_current_blog();
}
if ( ! is_array( $fixture ) || empty( $fixture['event_id'] ) ) {
	throw new RuntimeException( 'The Gardner event-RSVP fixture is missing; the journey cannot be graded. Seed step did not run or failed.' );
}

$event_id                = (int) $fixture['event_id'];
$gardner_id              = (int) $fixture['gardner_id'];
$returning_subscriber_id = (int) $fixture['returning_subscriber_id'];
$events_blog_id          = (int) ( $fixture['events_blog_id'] ?? ec_rig_grade_site_id( 'events.extrachill.com' ) );
$main_blog_id            = (int) ( $fixture['main_blog_id'] ?? ec_rig_grade_site_id( 'extrachill.com' ) );

$cases    = array();
$findings = array();

/**
 * Record one persona observation.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID from the canonical contract.
 * @param bool   $passed   Whether the persona expectation held.
 * @param string $task     What the persona was trying to do, in plain words.
 * @param array  $evidence Supporting evidence.
 */
function ec_rig_grade_case( string $id, string $oracle, bool $passed, string $task, array $evidence = array() ): void {
	global $cases, $findings;
	$record = array(
		'id'       => $id,
		'oracle'   => $oracle,
		'passed'   => $passed,
		'task'     => $task,
		'evidence' => $evidence,
	);
	$cases[] = $record;
	if ( ! $passed ) {
		$findings[] = array_merge( $record, array( 'status' => 'open' ) );
	}
}

/**
 * Record an observation the runtime could not fairly evaluate.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID.
 * @param string $task     What the persona was trying to do.
 * @param string $reason   Why the runtime could not judge it.
 * @param array  $evidence Supporting evidence.
 */
function ec_rig_grade_skip( string $id, string $oracle, string $task, string $reason, array $evidence = array() ): void {
	global $cases;
	$cases[] = array(
		'id'       => $id,
		'oracle'   => $oracle,
		'passed'   => true,
		'skipped'  => true,
		'task'     => $task,
		'reason'   => $reason,
		'evidence' => $evidence,
	);
}

global $wpdb;

/*
 * ---------------------------------------------------------------------------
 * Gardner: single deliberate "Going" click (browser step gardner-rsvp-with-pass).
 * ---------------------------------------------------------------------------
 */
$gardner_marked = function_exists( 'ec_users_is_event_marked' ) && ec_users_is_event_marked( $gardner_id, $event_id, $events_blog_id );
ec_rig_grade_case(
	'gardner-going-completes',
	'task-completion',
	$gardner_marked,
	'Mark himself as Going and have it actually take.',
	array( 'marked' => $gardner_marked )
);

$concert_table = extrachill_users_concert_tracking_table_name();
$gardner_rows  = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$concert_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted WordPress table identifier.
		$gardner_id,
		$event_id,
		$events_blog_id
	)
);
ec_rig_grade_case(
	'gardner-single-row-no-duplicates',
	'duplicate-prevention',
	1 === $gardner_rows,
	'Not end up marked twice just because the page reloaded or he came back to check.',
	array( 'rows' => $gardner_rows )
);

$notif_table    = extrachill_users_notifications_table_name();
$milestone_row  = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$notif_table} WHERE user_id = %d AND type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gardner_id,
		'milestone'
	),
	ARRAY_A
);
ec_rig_grade_case(
	'gardner-first-show-milestone-fires',
	'obvious-state',
	is_array( $milestone_row ),
	'See some acknowledgment that marking himself Going registered, beyond the button itself.',
	array( 'milestone_row_found' => is_array( $milestone_row ) )
);

/*
 * ---------------------------------------------------------------------------
 * The perk pass (extrachill-events#878): issued, emailed, redeemable at the
 * door. The browser steps captured the pass card on the page; this verifies
 * the server-side truth.
 * ---------------------------------------------------------------------------
 */
$pass          = null;
$pass_row_note = '';
switch_to_blog( $events_blog_id );
if ( class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
	$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $event_id, $gardner_id );
} else {
	$pass_row_note = 'RsvpPassesTable unavailable';
}
$pass_code = is_array( $pass ) ? (string) $pass['code'] : '';
ec_rig_grade_case(
	'perk-pass-issued-with-code',
	'task-completion',
	is_array( $pass ) && '' !== $pass_code,
	'Get something he can actually show at the door to claim the free beer promised in the event description.',
	array(
		'pass_found'     => is_array( $pass ),
		'status'         => is_array( $pass ) ? (string) $pass['status'] : null,
		'code_length'    => strlen( $pass_code ),
		'pass_row_note'  => $pass_row_note,
		'tables_precreated' => $fixture['tables_precreated'] ?? array(),
	)
);

// The pass email: queued through datamachine/send-email-queued (Action
// Scheduler). Whether it actually SENT is not attributable in this runtime
// (no real SMTP); that a queued send for the pass exists is.
$email_action_count = null;
$as_actions_table   = $wpdb->prefix . 'actionscheduler_actions';
$as_exists          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions_table ) );
if ( $as_exists === $as_actions_table ) {
	$email_action_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$as_actions_table} WHERE hook = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table identifier.
			'datamachine_send_email_worker'
		)
	);
}
ec_rig_grade_case(
	'perk-pass-email-queued',
	'obvious-state',
	null !== $email_action_count && $email_action_count > 0,
	'Get the pass in his email too, so it survives losing the page.',
	array(
		'queued_email_actions' => $email_action_count,
		'note'                 => 'Action Scheduler records the queued send; actual SMTP delivery is not attributable in this runtime (no real mail transport), so this verifies queuing only.',
	)
);

// Door redemption through the real ability, as the host (admin seeded the
// event). The browser step already clicked the door-list redeem control;
// this confirms the server state and the safe double-redeem behavior.
wp_set_current_user( 1 );
switch_to_blog( $events_blog_id );
$redeem = ec_rig_grade_execute(
	'extrachill/redeem-event-pass',
	array(
		'event_id' => $event_id,
		'user_id'  => $gardner_id,
	)
);
$redeem_outcome = array(
	'ok'               => ! is_wp_error( $redeem ),
	'already_redeemed' => is_array( $redeem ) ? (bool) ( $redeem['already_redeemed'] ?? false ) : null,
	'error'            => is_wp_error( $redeem ) ? $redeem->get_error_message() : null,
);
restore_current_blog();
$pass_after = null;
switch_to_blog( $events_blog_id );
if ( class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
	$pass_after = \ExtraChillEvents\Core\RsvpPassesTable::find_for_user_event( $event_id, $gardner_id );
}
restore_current_blog();
$redeemed = is_array( $pass_after )
	&& ( ! empty( $pass_after['redeemed_at'] ) || ( is_array( $redeem ) && (bool) ( $redeem['already_redeemed'] ?? false ) ) );
ec_rig_grade_case(
	'perk-pass-redeems-at-door-safely',
	'safe-retry',
	$redeemed,
	'Have the pass scanned at the door, including if the host taps redeem twice.',
	array(
		'redeem_outcome' => $redeem_outcome,
		'final_status'   => is_array( $pass_after ) ? (string) $pass_after['status'] : null,
		'redeemed_at'    => is_array( $pass_after ) ? (string) ( $pass_after['redeemed_at'] ?? '' ) : null,
	)
);

/*
 * ---------------------------------------------------------------------------
 * Returning subscriber: rapid double-click, private-by-default, door list.
 * ---------------------------------------------------------------------------
 */
$subscriber_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$concert_table} WHERE user_id = %d AND event_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$returning_subscriber_id,
		$event_id,
		$events_blog_id
	)
);
ec_rig_grade_case(
	'rapid-double-click-does-not-duplicate',
	'duplicate-prevention',
	$subscriber_rows <= 1,
	'Click Going twice quickly (a real double-submit) without ending up in a broken or duplicated state.',
	array( 'rows' => $subscriber_rows )
);

$subscriber_visibility = get_user_meta( $returning_subscriber_id, '_extrachill_event_attendance_visibility', true );
ec_rig_grade_case(
	'private-by-default-still-holds',
	'server-authorization',
	'' === $subscriber_visibility || 'private' === $subscriber_visibility,
	'Not have his RSVP made publicly visible just because he never opened a settings screen.',
	array(
		'stored_meta' => $subscriber_visibility,
		'note'        => 'Empty string is correct: absent meta is the private-by-default state extrachill-users#415 shipped.',
	)
);

wp_set_current_user( 0 );
switch_to_blog( $events_blog_id );
$attendance = ec_rig_grade_execute(
	'extrachill/get-event-attendance',
	array(
		'event_id'          => $event_id,
		'blog_id'           => $events_blog_id,
		'include_attendees' => true,
		'limit'             => 20,
	)
);
restore_current_blog();
$attendee_names = array();
if ( is_array( $attendance ) && is_array( $attendance['attendees'] ?? null ) ) {
	$attendee_names = wp_list_pluck( $attendance['attendees'], 'display_name' );
}
$count                              = is_array( $attendance ) ? (int) ( $attendance['count'] ?? 0 ) : 0;
$door_list_matches_shipped_decision = ! is_wp_error( $attendance )
	&& in_array( 'Chris Gardner (Test Persona)', $attendee_names, true )
	&& ! in_array( 'Returning Community Member (Test Persona)', $attendee_names, true )
	&& $count >= count( $attendee_names ) + 1;
ec_rig_grade_case(
	'door-list-reality-matches-shipped-privacy-decision',
	'server-authorization',
	$door_list_matches_shipped_decision,
	'Check the public attendee list and understand who is actually coming.',
	array(
		'count'  => $count,
		'listed' => $attendee_names,
		'note'   => 'Known, already-decided tension from extrachill-users#414/#415: a host cannot verify a private attendee from the public list. The door-list surface itself (host view, browser step) now shows the full private-inclusive list; verified here, not re-filed.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * The new creative: registered for real and RSVP'd (browser step
 * new-creative-register-rsvp-loop). This closes the inconclusive case from
 * the original single-site run (extrachill-events#876's "registration submit
 * control never found": the control is input[name=extrachill_register], an
 * <input type=submit>, which :has-text() never matches).
 * ---------------------------------------------------------------------------
 */
$new_creative = get_user_by( 'email', 'new-creative-fixture@example.invalid' );
ec_rig_grade_case(
	'new-creative-registration-completes',
	'task-completion',
	(bool) $new_creative,
	'Create an account from the event page when he decides to join.',
	array(
		'user_found' => (bool) $new_creative,
		'user_id'    => $new_creative ? (int) $new_creative->ID : null,
	)
);

$new_creative_marked = false;
$new_creative_member = false;
if ( $new_creative ) {
	$new_creative_marked = function_exists( 'ec_users_is_event_marked' )
		&& ec_users_is_event_marked( (int) $new_creative->ID, $event_id, $events_blog_id );
	$new_creative_member = is_user_member_of_blog( (int) $new_creative->ID, $events_blog_id );
}
ec_rig_grade_case(
	'new-creative-rsvp-completes-after-register',
	'task-completion',
	$new_creative_marked,
	'Come back to the event after registering and mark himself Going without starting over.',
	array(
		'marked'          => $new_creative_marked,
		'member_of_site'  => $new_creative_member,
	)
);

/*
 * ---------------------------------------------------------------------------
 * Comprehension, verified at the PHP level against the real rendered content.
 * The browser steps assert the same surfaces in the DOM; these confirm them
 * independently of any JS behavior.
 * ---------------------------------------------------------------------------
 */
switch_to_blog( $events_blog_id );
$rendered_html = apply_filters( 'the_content', get_post_field( 'post_content', $event_id ) );
restore_current_blog();

// data-machine-events#860 shipped an end-time render (fixed in v0.64.7);
// this now expects the fix to be live on the booted releases.
$end_time_visible = false !== stripos( $rendered_html, '9:00' ) || false !== stripos( $rendered_html, '9 pm' ) || false !== stripos( $rendered_html, '9pm' );
ec_rig_grade_case(
	'event-end-time-now-visible',
	'obvious-state',
	$end_time_visible,
	'Know what time the event actually ends without reading the whole description.',
	array(
		'end_time_visible' => $end_time_visible,
		'note'             => 'data-machine-events#860 was fixed in v0.64.7; this case flipped from recorded-finding to pass-expectation.',
	)
);

// data-machine-events#861 (free indicator) is still open; expected to fail
// until that ships, and recorded as a finding each run until it does.
$free_indicator_visible = preg_match( '/event-price[^>]*>\s*(free|no cover|\$0)/i', $rendered_html ) === 1
	|| false !== stripos( $rendered_html, 'this event is free' );
ec_rig_grade_case(
	'free-events-show-a-free-indicator',
	'obvious-state',
	$free_indicator_visible,
	'Tell at a glance that the event costs nothing, without reading the description.',
	array(
		'free_indicator_visible' => $free_indicator_visible,
		'note'                   => 'Filed as data-machine-events#861; expected to remain a finding until that ships.',
	)
);

/*
 * ---------------------------------------------------------------------------
 * The Local Scene card on the main site: Gardner's saved scene should put
 * Charleston first in the "Top Event Markets" card (browser step
 * local-scene-card-main-site asserts the DOM; this verifies the cross-site
 * data path the card is built on).
 * ---------------------------------------------------------------------------
 */
switch_to_blog( $main_blog_id );
$counts_ability = function_exists( 'wp_has_ability' ) && wp_has_ability( 'extrachill/events-upcoming-counts' );
$upcoming       = $counts_ability
	? ec_rig_grade_execute(
		'extrachill/events-upcoming-counts',
		array(
			'taxonomy' => 'location',
			'limit'    => 8,
		)
	)
	: new WP_Error( 'ec_rig_grade_ability_missing', 'extrachill/events-upcoming-counts' );
restore_current_blog();
$first_market = is_array( $upcoming ) && ! empty( $upcoming[0] ) ? $upcoming[0] : null;
$charleston_first = is_array( $first_market ) && 'charleston' === ( $first_market['slug'] ?? '' );
ec_rig_grade_case(
	'local-scene-card-prioritizes-saved-market',
	'obvious-state',
	$charleston_first,
	'See the scene he cares about first on the main site, not whichever city has the most shows.',
	array(
		'first_market' => $first_market,
		'markets'      => is_array( $upcoming ) ? count( $upcoming ) : null,
		'note'         => 'Gardner seeded with _extrachill_local_scene=charleston; the card reorders via extrachill/get-user-settings + the events upcoming-counts contract.',
	)
);

$result = array(
	'schema'     => 'extrachill-network/journey-result/gardner-event-rsvp/v1',
	'persona'    => 'extra-chill-users/chris-gardner@1.0.0',
	'scenario'   => 'gardner-event-rsvp',
	'event_id'   => $event_id,
	'event_url'  => $fixture['event_url'] ?? '',
	'events_blog_id' => $events_blog_id,
	'assertions' => count( $cases ),
	'skipped'    => count(
		array_filter(
			$cases,
			static function ( $entry ) {
				return ! empty( $entry['skipped'] );
			}
		)
	),
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
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( wp_json_encode( $result ) ) );
