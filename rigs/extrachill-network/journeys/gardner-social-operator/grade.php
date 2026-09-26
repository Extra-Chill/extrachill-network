<?php
/**
 * Grade the Gardner Studio social-operator journey: reload persisted state,
 * retry only the failed platform, and grade against the persona oracles.
 *
 * Ported from extrachill-studio tests/wp-codebox/chris-gardner-social-operator-reload.php
 * (extrachill-network#293) onto the network rig's journey contract. Keeps
 * the deterministic scenario matrix (idempotency, safe retry, partial
 * delivery recovery, final share history, media reuse/duplicate rejection,
 * Instagram comments read/reply). Drops the old file's own typed-artifact
 * ledger files (provider-call-ledger.json / transition-ledger.json / ... in
 * wp_upload_dir()) -- a wp-codebox recipe-level "typed artifacts" feature
 * this rig's journey contract does not (yet) provide generically -- in
 * favor of this rig's own `EXTRACHILL_JOURNEY_RESULT` marker convention,
 * used by every other journey. See evidence/FINDINGS.md for why that is an
 * explicit, named, reasoned boundary rather than a silently dropped
 * capability, and for the carried-over capability-gap findings this
 * consolidation re-verified against a REAL run in this rig (rather than
 * assuming the old single-plugin sandbox's findings still apply unchanged).
 *
 * @package ExtraChillNetwork
 */

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Tracking\SocialShareTracker;

$cases    = array();
$findings = array();

/**
 * Record one grading case.
 *
 * @param string $id       Stable case ID.
 * @param string $oracle   Oracle ID from the pinned persona contract, or a scenario-local label.
 * @param bool   $passed   Whether the check held.
 * @param string $task     What Gardner was trying to do, in plain words.
 * @param array  $evidence Supporting evidence.
 */
function ec_rig_social_operator_case( string $id, string $oracle, bool $passed, string $task, array $evidence = array() ): void {
	global $cases, $findings;
	$record  = array(
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

function ec_rig_social_operator_count_calls( array $ledger, string $provider_call ): int {
	return count( array_filter( $ledger, static fn( $entry ) => ( $entry['provider_call'] ?? '' ) === $provider_call ) );
}

/**
 * Resolve the studio site by domain, never by blog ID -- this run-php
 * process starts on the primary site, so the fixture (written to studio's
 * OWN options table by the seed step, inside its own switch_to_blog()) is
 * unreadable via a plain get_option() until we are on that blog too.
 *
 * @return int Blog ID of studio.extrachill.com.
 */
function ec_rig_social_operator_grade_studio_blog_id(): int {
	$sites = get_sites( array(
		'domain' => 'studio.extrachill.com',
		'number' => 1,
	) );
	if ( empty( $sites ) ) {
		throw new RuntimeException( 'Journey grade step could not resolve studio.extrachill.com by domain.' );
	}
	return (int) $sites[0]->blog_id;
}

switch_to_blog( ec_rig_social_operator_grade_studio_blog_id() );
$fixture = get_option( 'ec_rig_journey_fixture_gardner_social_operator', array() );
if ( ! is_array( $fixture ) || empty( $fixture['studio_blog_id'] ) ) {
	restore_current_blog();
	throw new RuntimeException( 'The gardner-social-operator fixture is missing; the seed step did not run or failed.' );
}
require_once WP_PLUGIN_DIR . '/extrachill-studio/extrachill-studio.php';
if ( function_exists( 'datamachine_register_core_actions' ) ) {
	datamachine_register_core_actions();
}
new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();
if ( function_exists( 'datamachine_socials_bootstrap' ) ) {
	datamachine_socials_bootstrap();
}
\DataMachine\Engine\Tasks\TaskRegistry::reset();

if ( empty( $fixture['delivery_ref'] ) || empty( $fixture['job_id'] ) ) {
	ec_rig_social_operator_case( 'delivered-state-persisted-before-retry', 'task-completion', false, 'Have his approved post actually reach delivery so there is something to retry.', array( 'note' => 'The deliver.php step did not run or did not reach partial delivery; grading cannot proceed past this point.' ) );
	restore_current_blog();
	$result = array(
		'schema'     => 'extrachill-network/journey-result/gardner-social-operator/v1',
		'scenario'   => 'gardner-social-operator',
		'assertions' => count( $cases ),
		'passed'     => 0,
		'findings'   => $findings,
		'cases'      => $cases,
	);
	printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return;
}

$gardner_id  = (int) $fixture['gardner_user_id'];
$ordinary_id = (int) $fixture['ordinary_user_id'];
$article_id  = (int) $fixture['article_id'];
$draft_id    = (int) $fixture['draft_id'];
$job_id      = (int) $fixture['job_id'];
$ref         = (string) $fixture['delivery_ref'];
$jobs        = new Jobs();

wp_set_current_user( $gardner_id );
$before = get_option( 'ec_studio_operator_provider_ledger', array() );
ec_rig_social_operator_case(
	'instagram-delivered-exactly-once-before-retry',
	'duplicate-prevention',
	1 === ec_rig_social_operator_count_calls( $before, 'instagram.publish-effect' ) && 0 === ec_rig_social_operator_count_calls( $before, 'bluesky.publish-effect' ),
	'Not have his Instagram post go out twice while Bluesky is still stuck.',
	array(
		'instagram_calls' => ec_rig_social_operator_count_calls( $before, 'instagram.publish-effect' ),
		'bluesky_calls'   => ec_rig_social_operator_count_calls( $before, 'bluesky.publish-effect' ),
	)
);

$persisted = ExtraChillStudio\get_social_publish_state( $draft_id );
ec_rig_social_operator_case(
	'reload-shows-partial-state-accurately',
	'reload-persistence',
	! empty( $persisted['success'] ) && 'failed' === ( $persisted['delivery']['status'] ?? '' ),
	'Reload the page and still see an accurate picture of what actually happened, not a stale or misleading state.',
	array( 'status' => $persisted['delivery']['status'] ?? null )
);

$instagram_read = wp_get_ability( 'datamachine/instagram-read' );
wp_set_current_user( $ordinary_id );
$ordinary_comments_permission = $instagram_read
	? $instagram_read->check_permissions( array(
		'action'   => 'comments',
		'media_id' => 'ig-media-operator-1',
	) )
	: new WP_Error( 'missing_ability' );
wp_set_current_user( $gardner_id );
$comments_boundary_mismatch = true === $ordinary_comments_permission;
ec_rig_social_operator_case(
	'ordinary-team-member-cannot-read-instagram-comments',
	'server-authorization',
	! $comments_boundary_mismatch,
	'Trust that a teammate without the social-media grant cannot read Instagram comments through the same backend ability Studio uses.',
	array(
		'mismatch'     => $comments_boundary_mismatch,
		'evidence_ref' => 'https://github.com/Extra-Chill/data-machine-socials/issues/247',
	)
);

$retry_ability = wp_get_ability( 'extrachill/retry-social-publish' );
$retry         = $retry_ability ? $retry_ability->execute( array( 'post_id' => $draft_id ) ) : new WP_Error( 'missing_ability' );
$retry_ok      = ! is_wp_error( $retry ) && ! empty( $retry['success'] ) && ( $retry['delivery']['delivery_ref'] ?? '' ) === $ref;
ec_rig_social_operator_case(
	'safe-retry-reaches-delivered-without-reposting-instagram',
	'safe-retry',
	$retry_ok,
	'Retry the stuck Bluesky post without accidentally re-posting to Instagram or losing his place in line.',
	array(
		'retry_error' => is_wp_error( $retry ) ? array(
			'code'    => $retry->get_error_code(),
			'message' => $retry->get_error_message(),
		) : ( empty( $retry['success'] ) ? ( $retry['error'] ?? null ) : null ),
	)
);

if ( $retry_ok ) {
	$reopened         = $jobs->get_job( $job_id );
	$reopened_pending = 'pending' === ( $reopened['status'] ?? '' );
	ec_rig_social_operator_case(
		'retry-reopens-existing-job-not-a-new-one',
		'duplicate-prevention',
		$reopened_pending,
		'Retrying reopens the same job instead of quietly starting a second one behind his back.',
		array( 'status' => $reopened['status'] ?? null )
	);
	if ( $reopened_pending ) {
		$acting_user_id = get_current_user_id();
		wp_set_current_user( (int) $reopened['user_id'] );
		try {
			$execute_result = wp_get_ability( 'datamachine/execute-step' )->execute(
				array(
					'job_id'                => (int) $reopened['job_id'],
					'flow_step_id'          => (string) $reopened['operation_step_id'],
					'operation_generation'  => (int) $reopened['operation_generation'],
					'operation_claim_token' => (string) $reopened['operation_claim_token'],
				)
			);
		} finally {
			wp_set_current_user( $acting_user_id );
		}
		$final = ExtraChillStudio\get_social_publish_state( $draft_id );
		ec_rig_social_operator_case(
			'retry-reaches-fully-delivered-state',
			'task-completion',
			! is_wp_error( $execute_result ) && ! empty( $final['success'] ) && 'delivered' === ( $final['delivery']['status'] ?? '' ) && ( $final['delivery']['delivery_ref'] ?? '' ) === $ref,
			'Get to a final, fully-delivered state after the retry -- not stuck partial forever.',
			array( 'final_status' => $final['delivery']['status'] ?? null )
		);

		$after = get_option( 'ec_studio_operator_provider_ledger', array() );
		ec_rig_social_operator_case(
			'retry-never-reposts-instagram-and-posts-bluesky-once',
			'duplicate-prevention',
			1 === ec_rig_social_operator_count_calls( $after, 'instagram.publish-effect' ) && 1 === ec_rig_social_operator_count_calls( $after, 'bluesky.publish-effect' ) && 0 === ec_rig_social_operator_count_calls( $after, 'blocked-unexpected' ),
			'Not end up with a duplicate Instagram post, and not have the retry silently reach a provider it was never meant to talk to.',
			array(
				'instagram_calls'  => ec_rig_social_operator_count_calls( $after, 'instagram.publish-effect' ),
				'bluesky_calls'    => ec_rig_social_operator_count_calls( $after, 'bluesky.publish-effect' ),
				'unexpected_calls' => ec_rig_social_operator_count_calls( $after, 'blocked-unexpected' ),
			)
		);

		$shares                    = SocialShareTracker::get_shares( $article_id );
		$operation_hash            = hash( 'sha256', $ref );
		$share_identity_consistent = true;
		foreach ( $shares as $share ) {
			if ( ( $share['operation_hash'] ?? '' ) !== $operation_hash ) {
				$share_identity_consistent = false;
			}
		}
		ec_rig_social_operator_case(
			'final-share-history-shows-exactly-two-receipts',
			'attribution',
			2 === SocialShareTracker::count_shares( $article_id )
				&& 1 === SocialShareTracker::count_shares( $article_id, 'instagram' )
				&& 1 === SocialShareTracker::count_shares( $article_id, 'bluesky' )
				&& $share_identity_consistent,
			'See a final, correctly-attributed record of exactly one Instagram and one Bluesky share on his article -- not zero, not four.',
			array(
				'total'               => SocialShareTracker::count_shares( $article_id ),
				'identity_consistent' => $share_identity_consistent,
			)
		);

		// Media reuse across drafts is valid; duplicate media inside ONE operation is rejected.
		$reuse_post                    = wp_insert_post( array(
			'post_title'  => 'Media reuse draft',
			'post_status' => 'publish',
			'post_author' => $gardner_id,
		), true );
		$reuse_caption                 = 'A second draft may reuse the same canonical media.';
		$reuse_input                   = array(
			'post_id'      => (int) $reuse_post,
			'source_url'   => is_wp_error( $reuse_post ) ? '' : get_permalink( $reuse_post ),
			'caption'      => $reuse_caption,
			'content_hash' => hash( 'sha256', $reuse_caption ),
			'channels'     => array( 'instagram' ),
			'media_kind'   => 'image',
			'asset_refs'   => array(
				array(
					'source_id' => $fixture['media'][1]['source_id'],
					'role'      => 'image',
				),
			),
		);
		$reused                        = is_wp_error( $reuse_post ) ? $reuse_post : DelegatedCrossPostAction::normalize_input( $reuse_input, array( 'phase' => 'submit' ) );
		$duplicate_input               = $reuse_input;
		$duplicate_input['media_kind'] = 'carousel';
		$duplicate_input['asset_refs'] = array( $reuse_input['asset_refs'][0], $reuse_input['asset_refs'][0] );
		$duplicate                     = DelegatedCrossPostAction::normalize_input( $duplicate_input, array( 'phase' => 'submit' ) );
		ec_rig_social_operator_case(
			'media-reuse-across-drafts-accepted-duplicate-inside-one-rejected',
			'actionable-errors',
			is_array( $reused ) && is_wp_error( $duplicate ) && 'social_cross_post_invalid_asset_ref' === $duplicate->get_error_code(),
			'Reuse the same photo in a second post later, but never accidentally attach the same photo twice inside one post.',
			array(
				'reuse_ok'             => is_array( $reused ),
				'duplicate_error_code' => is_wp_error( $duplicate ) ? $duplicate->get_error_code() : null,
			)
		);

		// Real Instagram comments read/reply, over the deterministic provider stub.
		$instagram_reply                 = wp_get_ability( 'datamachine/instagram-comment-reply' );
		$provider_state                  = get_option( 'ec_studio_operator_provider_state', array() );
		$provider_state['comments_mode'] = 'page';
		update_option( 'ec_studio_operator_provider_state', $provider_state, false );
		$comments_page = $instagram_read->execute( array(
			'action'   => 'comments',
			'media_id' => 'ig-media-operator-1',
			'limit'    => 1,
		) );

		$provider_state['comments_mode'] = 'partial';
		update_option( 'ec_studio_operator_provider_state', $provider_state, false );
		$partial_comments = $instagram_read->execute( array(
			'action'   => 'comments_all',
			'media_id' => 'ig-media-operator-1',
		) );

		$invalid_reply    = $instagram_reply->execute( array(
			'comment_id' => 'ig-comment-1',
			'message'    => '',
		) );
		$successful_reply = $instagram_reply->execute( array(
			'comment_id' => 'ig-comment-1',
			'message'    => 'Doors are at 7. Thanks for supporting local music.',
		) );
		ec_rig_social_operator_case(
			'gardner-can-read-and-reply-to-instagram-comments',
			'task-completion',
			! is_wp_error( $comments_page ) && 1 === ( $comments_page['data']['count'] ?? 0 )
				&& ! is_wp_error( $partial_comments ) && ! empty( $partial_comments['data']['partial'] )
				&& is_wp_error( $invalid_reply ) && 'missing_param' === $invalid_reply->get_error_code()
				&& ! is_wp_error( $successful_reply ) && 'ig-reply-1' === ( $successful_reply['data']['reply_id'] ?? '' ),
			'See and answer real questions in the Instagram comments on his post, right from the backend Studio already has.',
			array(
				'page_ok'    => ! is_wp_error( $comments_page ),
				'partial_ok' => ! is_wp_error( $partial_comments ),
				'reply_ok'   => ! is_wp_error( $successful_reply ),
			)
		);
	}
}

// Known, carried-over capability gaps from the original single-plugin
// investigation (extrachill-studio#…, data-machine-socials#246/#247). Not
// re-derived from scratch here -- re-verify each against THIS run's own
// evidence before repeating it in the PR body; see evidence/FINDINGS.md.
$capability_gaps = array(
	array(
		'id'          => 'GARDNER-STUDIO-MULTIPLATFORM-COMPOSER',
		'severity'    => 'high',
		'explanation' => 'Gardner cannot compose one clear multi-platform post in Studio; the backend can cross-post, but the current interface is platform-by-platform.',
	),
	array(
		'id'          => 'GARDNER-STUDIO-SCHEDULING-TIMEZONE',
		'severity'    => 'high',
		'explanation' => 'Gardner cannot schedule with an obvious timezone in Studio and must rely on WordPress outside the social composer.',
	),
	array(
		'id'          => 'GARDNER-STUDIO-PERSISTENT-QUEUE',
		'severity'    => 'high',
		'explanation' => 'Gardner has no persistent Studio queue to reopen, edit, reschedule, or cancel a social item.',
	),
	array(
		'id'          => 'GARDNER-STUDIO-PUBLISHED-INVENTORY',
		'severity'    => 'high',
		'explanation' => 'Gardner cannot inspect and reconcile published social inventory or safely edit, delete, and archive it from Studio.',
	),
	array(
		'id'          => 'GARDNER-SOCIALS-PER-MEDIA-HISTORY',
		'severity'    => 'medium',
		'explanation' => 'Gardner cannot tell where an individual media item has already been used; history is attached to the article, not the media.',
	),
	array(
		'id'          => 'GARDNER-INSTAGRAM-DMS',
		'severity'    => 'medium',
		'explanation' => 'Gardner cannot read or answer Instagram direct messages from Studio.',
	),
	array(
		'id'          => 'GARDNER-SOCIAL-ACCOUNT-MANAGEMENT',
		'severity'    => 'high',
		'explanation' => 'Gardner cannot connect, disconnect, switch, or inspect shared account profiles from Studio.',
	),
	array(
		'id'          => 'GARDNER-UNIFIED-SOCIAL-ANALYTICS',
		'severity'    => 'medium',
		'explanation' => 'Gardner has no unified social performance view in Studio.',
	),
	array(
		'id'          => 'GARDNER-SHARE-INITIATOR-ATTRIBUTION',
		'severity'    => 'medium',
		'explanation' => 'Share rows do not carry a first-class human initiator field separate from execution context, so Gardner attribution is unclear during delegated work.',
	),
);
if ( $comments_boundary_mismatch ) {
	$capability_gaps[] = array(
		'id'          => 'GARDNER-IG-COMMENTS-OWNER-BOUNDARY',
		'severity'    => 'critical',
		'explanation' => 'An ordinary team user without the brand-social grant can pass the direct REST-visible Instagram comments ability permission check.',
	);
}

restore_current_blog();

$result = array(
	'schema'          => 'extrachill-network/journey-result/gardner-social-operator/v1',
	'persona'         => 'extra-chill-users/chris-gardner@1.0.0',
	'scenario'        => 'gardner-social-operator',
	'assertions'      => count( $cases ),
	'passed'          => count(
		array_filter(
			$cases,
			static function ( $entry ) {
				return $entry['passed'];
			}
		)
	),
	'findings'        => $findings,
	'cases'           => $cases,
	'capability_gaps' => $capability_gaps,
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable persona evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
