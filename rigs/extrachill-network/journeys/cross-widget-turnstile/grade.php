<?php
/**
 * Grade the cross-widget Turnstile isolation smoke.
 *
 * Runs after the browser step has navigated the seeded page. The browser
 * step's own `assert=no-page-errors` argument already enforces the decisive
 * safety property in-recipe (the boot's per-widget try/catch must contain
 * the broken widget's throw so it never escapes as an uncaught page error).
 * This grade step verifies the server-side truth the browser exercised
 * against: the seeded page still carries exactly two Turnstile widgets, one
 * deliberately broken, both driven by the plugin's REAL shipped boot script
 * (never a reimplementation).
 *
 * The "at least the good widget rendered" half of the old bash runner's
 * contract (`rendered>=1` of `total=2` from the `EC_TURNSTILE_SMOKE` console
 * marker, parsed from `console.jsonl` after the recipe run) is a host-side,
 * post-run artifact check -- outside what a WordPress.run-php step running
 * INSIDE the sandbox can read (the recipe's own captured console/artifact
 * files live on the host, written after this boot finishes). It is verified
 * as part of this journey's own evidence-gathering pass against a real
 * `homeboy rig up` run (see evidence/FINDINGS.md), not as an in-sandbox gate.
 *
 * @package ExtraChillNetwork
 */

$cases    = array();
$findings = array();

/**
 * Record one grading case.
 *
 * @param string $id       Stable case ID.
 * @param bool   $passed   Whether the check held.
 * @param string $task     What this verifies, in plain words.
 * @param array  $evidence Supporting evidence.
 */
function ec_rig_cross_widget_case( string $id, bool $passed, string $task, array $evidence = array() ): void {
	global $cases, $findings;
	$record  = array(
		'id'       => $id,
		'passed'   => $passed,
		'task'     => $task,
		'evidence' => $evidence,
	);
	$cases[] = $record;
	if ( ! $passed ) {
		$findings[] = array_merge( $record, array( 'status' => 'open' ) );
	}
}

$fixture = get_option( 'ec_rig_journey_fixture_cross_widget_turnstile', array() );
if ( ! is_array( $fixture ) || empty( $fixture['page_id'] ) ) {
	throw new RuntimeException( 'The cross-widget-turnstile fixture is missing; the seed step did not run or failed.' );
}

$smoke_page = get_post( (int) $fixture['page_id'] );
ec_rig_cross_widget_case(
	'seeded-page-exists',
	$smoke_page instanceof WP_Post && 'publish' === $smoke_page->post_status,
	'The smoke page the browser step navigates to actually exists and is published.',
	array(
		'page_id' => $fixture['page_id'],
		'status'  => $smoke_page instanceof WP_Post ? $smoke_page->post_status : null,
	)
);

$content      = $smoke_page instanceof WP_Post ? (string) $smoke_page->post_content : '';
$widget_count = substr_count( $content, 'cf-turnstile' );
ec_rig_cross_widget_case(
	'two-widgets-present',
	2 === $widget_count,
	'Both Turnstile widgets (the broken one and its good sibling) are present on the page.',
	array( 'cf_turnstile_occurrences' => $widget_count )
);

ec_rig_cross_widget_case(
	'broken-widget-declares-dangling-callback',
	str_contains( $content, 'data-callback' ) && str_contains( $content, 'ecSmokeUndefinedCallbackDoesNotExist' ),
	'The first widget still declares the dangling data-callback that reproduces the lackey bug class.',
	array( 'has_callback_attribute' => str_contains( $content, 'data-callback' ) )
);

$boot_path        = WP_PLUGIN_DIR . '/extrachill-network/assets/js/turnstile-boot.js';
$real_boot_source = file_exists( $boot_path ) ? (string) file_get_contents( $boot_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset in a disposable sandbox.
ec_rig_cross_widget_case(
	'uses-the-real-shipped-boot-script',
	'' !== $real_boot_source && str_contains( $content, $real_boot_source ),
	'The page runs the plugin\'s ACTUAL assets/js/turnstile-boot.js, never a reimplementation of it.',
	array( 'boot_script_bytes' => strlen( $real_boot_source ) )
);

ec_rig_cross_widget_case(
	'turnstile-configured-with-documented-test-keys-only',
	'1x00000000000000000000AA' === get_site_option( 'ec_turnstile_site_key', '' ),
	'Only Cloudflare\'s documented always-pass TEST site key is configured in this disposable sandbox, never a real key.',
	array( 'site_key' => get_site_option( 'ec_turnstile_site_key', '' ) )
);

$result = array(
	'schema'     => 'extrachill-network/journey-result/cross-widget-turnstile/v1',
	'scenario'   => 'cross-widget-turnstile',
	'page_id'    => (int) $fixture['page_id'],
	'url'        => (string) $fixture['url'],
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

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $result ) ) );
