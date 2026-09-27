<?php
/**
 * Grade the anonymous event submission journey from the server side.
 *
 * The browser step records what the visitor SAW; this grade records what
 * the network actually DID with the submission: what the REST route
 * answered, whether the Data Machine workflow was enqueued, and whether the
 * submission was attributed to a real submitter account.
 *
 * The workflow's AI step needs a live model provider, which the
 * egress-blocked sandbox does not have, so this journey stops at "the
 * workflow job exists". That is the boundary extrachill-events#910 broke:
 * the ability permission check rejected every anonymous submission before
 * any job was created.
 *
 * Honest outcomes: a finding is a product defect, a skip is a case an
 * upstream failure left unable to judge; neither becomes a pass.
 *
 * @package ExtraChillNetwork
 */

$fixture = get_site_option( 'ec_rig_journey_fixture_anonymous_event_submission', array() );
if ( empty( $fixture['events_blog_id'] ) ) {
	throw new RuntimeException( 'Journey fixture missing; seed did not run.' );
}

$results = array();

/**
 * Record one graded case.
 *
 * @param array  $results  Result list (by reference).
 * @param string $id       Case id.
 * @param string $task     Plain-language expectation.
 * @param string $outcome  pass|finding|skip.
 * @param array  $evidence Evidence.
 */
function ec_submission_journey_record( array &$results, string $id, string $task, string $outcome, array $evidence ): void {
	$results[] = array(
		'id'       => $id,
		'task'     => $task,
		'outcome'  => $outcome,
		'evidence' => $evidence,
	);
}

$rest_log     = get_site_option( 'ec_submission_journey_rest', array() );
$observations = get_site_option( 'ec_submission_journey_observations', array() );
$rest         = is_array( $rest_log ) && ! empty( $rest_log ) ? end( $rest_log ) : null;

$observed = array();
foreach ( (array) $observations as $observation ) {
	if ( is_array( $observation ) && isset( $observation['label'] ) ) {
		$observed[ $observation['label'] ] = $observation['value'] ?? null;
	}
}

// ---------------------------------------------------------------------------
// The visitor really was anonymous (otherwise the case proves nothing).
// ---------------------------------------------------------------------------
$was_anonymous = is_array( $rest ) ? 0 === (int) $rest['user'] : ( true === ( $observed['logged-out'] ?? null ) );
ec_submission_journey_record(
	$results,
	'visitor-is-anonymous',
	'The submission was made by a logged-out visitor, the audience the public form exists for.',
	null === $rest && ! isset( $observed['logged-out'] ) ? 'skip' : ( $was_anonymous ? 'pass' : 'finding' ),
	array(
		'rest_user'          => is_array( $rest ) ? (int) $rest['user'] : null,
		'browser_logged_out' => $observed['logged-out'] ?? null,
	)
);

// ---------------------------------------------------------------------------
// The REST route admitted and executed the submission.
// ---------------------------------------------------------------------------
ec_submission_journey_record(
	$results,
	'rest-submission-accepted',
	'POST /extrachill/v1/event-submissions answers 200 with a workflow job id, not an ability permission error.',
	null === $rest ? 'finding' : ( ( 200 === (int) $rest['status'] && (int) $rest['job_id'] > 0 ) ? 'pass' : 'finding' ),
	array(
		'reached_route' => null !== $rest,
		'response'      => $rest,
	)
);

// ---------------------------------------------------------------------------
// What the visitor saw.
// ---------------------------------------------------------------------------
$status_seen = $observed['status-after-submit'] ?? null;
ec_submission_journey_record(
	$results,
	'visitor-sees-confirmation',
	'After submitting, the visitor sees a confirmation message, not an error.',
	! is_array( $status_seen ) ? 'finding' : ( ( empty( $status_seen['isError'] ) && '' !== (string) ( $status_seen['text'] ?? '' ) && ! str_contains( (string) $status_seen['text'], 'Sending' ) ) ? 'pass' : 'finding' ),
	array( 'status_line' => $status_seen )
);

ec_submission_journey_record(
	$results,
	'no-internal-error-shown',
	'The visitor is never shown an internal ability or permission error message.',
	! is_array( $status_seen ) ? 'skip' : ( preg_match( '/ability|permission|security check/i', (string) ( $status_seen['text'] ?? '' ) ) ? 'finding' : 'pass' ),
	array( 'status_line' => $status_seen )
);

// ---------------------------------------------------------------------------
// Server-side truth on the events site.
// ---------------------------------------------------------------------------
global $wpdb;
switch_to_blog( (int) $fixture['events_blog_id'] );

$job_row    = null;
$jobs_table = null;
foreach ( array_unique( array( $wpdb->prefix . 'datamachine_jobs', $wpdb->base_prefix . 'datamachine_jobs' ) ) as $candidate ) {
	if ( $candidate !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $candidate ) ) ) { // phpcs:ignore WordPress.DB
		continue;
	}
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT job_id, status, flow_id, user_id FROM {$candidate} WHERE engine_data LIKE %s ORDER BY job_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'%' . $wpdb->esc_like( (string) $fixture['event_title'] ) . '%'
		),
		ARRAY_A
	);
	if ( $row ) {
		$job_row    = $row;
		$jobs_table = $candidate;
		break;
	}
}

restore_current_blog();

$rest_ok = is_array( $rest ) && 200 === (int) $rest['status'];
ec_submission_journey_record(
	$results,
	'workflow-job-enqueued',
	'A Data Machine workflow job carrying the submitted event exists, so the submission will reach the review queue.',
	$job_row ? 'pass' : ( $rest_ok ? 'finding' : 'skip' ),
	array(
		'jobs_table'  => $jobs_table,
		'job'         => $job_row,
		'rest_job_id' => is_array( $rest ) ? (int) $rest['job_id'] : null,
	)
);

$submitter = get_user_by( 'email', (string) $fixture['submitter_email'] );
ec_submission_journey_record(
	$results,
	'submitter-account-attributed',
	'The anonymous submitter gets an unclaimed account flagged as an event submission, so the event is credited to them.',
	! $rest_ok ? 'skip' : ( ( $submitter && 'event_submission' === get_user_meta( $submitter->ID, 'registration_source', true ) && '1' === (string) get_user_meta( $submitter->ID, 'ec_unclaimed', true ) ) ? 'pass' : 'finding' ),
	array(
		'user_id'             => $submitter ? (int) $submitter->ID : null,
		'registration_source' => $submitter ? get_user_meta( $submitter->ID, 'registration_source', true ) : null,
		'ec_unclaimed'        => $submitter ? get_user_meta( $submitter->ID, 'ec_unclaimed', true ) : null,
	)
);

/*
 * Regression contract. Every open finding is pinned to the issue that owns
 * it. The journeys workflow fails when:
 *  - a case produces a finding that is NOT pinned (a new regression), or
 *  - a pinned case now passes (the fix landed: remove the pin so the case
 *    guards against the bug coming back).
 * Skips never fail; they mean an upstream case could not set the stage.
 */
$known_findings = array();
$regressions    = array();
$unpin          = array();
foreach ( $results as &$result ) {
	$result['pinned_issue'] = $known_findings[ $result['id'] ] ?? null;
	if ( 'finding' === $result['outcome'] && null === $result['pinned_issue'] ) {
		$regressions[] = $result['id'];
	}
	if ( 'pass' === $result['outcome'] && null !== $result['pinned_issue'] ) {
		$unpin[] = $result['id'] . ' (' . $result['pinned_issue'] . ')';
	}
}
unset( $result );

$summary = array(
	'schema'       => 'extrachill-network/journey-result/anonymous-event-submission/v1',
	'fixture'      => $fixture,
	'results'      => $results,
	'regressions'  => $regressions,
	'unpin'        => $unpin,
	'passed'       => count( array_filter( $results, static fn( $r ) => 'pass' === $r['outcome'] ) ),
	'findings'     => count( array_filter( $results, static fn( $r ) => 'finding' === $r['outcome'] ) ),
	'skipped'      => count( array_filter( $results, static fn( $r ) => 'skip' === $r['outcome'] ) ),
	'rest_log'     => $rest_log,
	'exceptions'   => get_site_option( 'ec_submission_journey_exceptions', array() ),
	'observations' => $observations,
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );

// Pass/fail is decided by the caller (.github/workflows/journeys.yml) from
// 'regressions' and 'unpin': throwing here would discard this result.
