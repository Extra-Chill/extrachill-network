<?php
/**
 * Seed a page that renders TWO Cloudflare Turnstile widgets via the plugin's
 * own ec_render_turnstile_widget(), driven by the plugin's REAL explicit-render
 * boot script (assets/js/turnstile-boot.js), and prove cross-widget isolation.
 *
 * Ported from tests/browser/seed-two-widgets.php (this same repo,
 * extrachill-network#293) onto the network rig's journey contract. Runs on
 * the primary site (extrachill.com), which is what WordPress.run-php already
 * bootstraps by default, so no per-site plugin bootstrap is needed here --
 * extrachill-network is network-active everywhere.
 *
 * Background -- the bug class this guards (newsletter #17 / multisite #48):
 * the primitive used to rely on Cloudflare api.js IMPLICIT auto-render -- a
 * single batch pass over every .cf-turnstile element. One widget carrying a
 * data-callback naming an undefined JS function threw during that pass and
 * aborted rendering for EVERY widget on the page, so an unrelated sibling
 * (e.g. the event-submission captcha) silently never rendered.
 *
 * The fix (multisite #48): ec_enqueue_turnstile_script() now loads api.js in
 * EXPLICIT mode and ships window.ecTurnstileBoot (turnstile-boot.js), which
 * renders EACH widget in its own turnstile.render() wrapped in try/catch. A
 * bad widget can then only break itself.
 *
 * This seed proves that contract end to end. The disposable sandbox cannot
 * reach the live Cloudflare api.js, so it configures Cloudflare's own
 * documented "always passes" Turnstile TEST keys
 * (https://developers.cloudflare.com/turnstile/troubleshooting/testing/) --
 * `1x00000000000000000000AA` / `1x0000000000000000000000000000000AA` --
 * purely so ec_render_turnstile_widget() emits real widget markup (it
 * returns '' when unconfigured); these keys never validate a live token and
 * are the same values this repo's PHPUnit-adjacent smoke already used before
 * this port. It then provides a faithful stub of window.turnstile.render()
 * that mirrors the real API's failure semantics:
 *   - render() looks up any `callback` option; if it was handed a function it
 *     marks the widget rendered.
 *   - to simulate a dangling/undefined callback, the stub THROWS when a
 *     widget declares a data-callback whose named global does not exist.
 * It then loads the plugin's ACTUAL boot script and lets it drive rendering.
 *
 * The decisive scenario: TWO widgets where the FIRST declares a broken
 * data-callback (undefined global) and the SECOND is well-formed. Under the
 * old implicit batch this aborted BOTH. Under explicit per-widget render the
 * boot's try/catch isolates the failure: the bad widget is skipped, the GOOD
 * widget still renders. The journey's browser step asserts zero uncaught
 * page errors (`assert=no-page-errors`); the emitted
 * `EC_TURNSTILE_SMOKE rendered=<n> total=<n>` console marker and the
 * screenshot are captured as evidence for the "at least the good widget
 * rendered" half of the contract (see journey README).
 *
 * @package ExtraChillNetwork
 */

if ( ! function_exists( 'ec_render_turnstile_widget' ) ) {
	throw new RuntimeException( 'ec_render_turnstile_widget() is unavailable -- extrachill-network is not loaded on the primary site.' );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Cloudflare's documented "always passes" test keys. Never real credentials;
// never reach the network -- the widget markup they configure only matters
// for the sandbox's stubbed turnstile.render(), not a live verification call.
update_site_option( 'ec_turnstile_site_key', '1x00000000000000000000AA' );
update_site_option( 'ec_turnstile_secret_key', '1x0000000000000000000000000000000AA' );

$boot_path = WP_PLUGIN_DIR . '/extrachill-network/assets/js/turnstile-boot.js';
if ( ! file_exists( $boot_path ) ) {
	throw new RuntimeException( esc_html( 'turnstile-boot.js not found at ' . $boot_path . '; the mounted extrachill-network component is missing its shipped boot script.' ) );
}
$boot_js = file_get_contents( $boot_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset in a disposable sandbox.

// Widget ONE: deliberately broken -- declares a data-callback naming a global
// that is never defined. Under the OLD implicit batch this aborted ALL widgets.
$widget_one = ec_render_turnstile_widget(
	array(
		'data-size'     => 'invisible',
		'data-callback' => 'ecSmokeUndefinedCallbackDoesNotExist',
		'id'            => 'ec-smoke-widget-broken',
	)
);

// Widget TWO: well-formed, the way a real consumer renders. This is the
// sibling the lackey bug killed. It MUST still render.
$widget_two = ec_render_turnstile_widget(
	array(
		'id' => 'ec-smoke-widget-good',
	)
);

if ( '' === $widget_one || '' === $widget_two ) {
	throw new RuntimeException( 'ec_render_turnstile_widget() returned empty markup; ec_is_turnstile_configured() is false even after seeding the test keys.' );
}

// Faithful stub of window.turnstile, mirroring the real api.js render
// contract closely enough to prove isolation.
$stub_turnstile_js = <<<'JS'
window.turnstile = {
	render: function (el, opts) {
		var cbName = el.getAttribute('data-callback');
		if (cbName && typeof window[cbName] !== 'function') {
			// Mirror Cloudflare rejecting an invalid widget config.
			throw new Error('turnstile: invalid callback "' + cbName + '"');
		}
		el.setAttribute('data-rendered', '1');
		return 'widget-' + (el.id || Math.random().toString(36).slice(2));
	},
	getResponse: function () { return ''; },
	reset: function () {},
	execute: function () {}
};
function ecSmokeEmitMarker() {
	var done = document.querySelectorAll('.cf-turnstile[data-rendered="1"]').length;
	var all = document.querySelectorAll('.cf-turnstile').length;
	console.log('EC_TURNSTILE_SMOKE rendered=' + done + ' total=' + all);
}
JS;

$boot_runner_js = <<<'JS'
(function () {
	if (typeof window.ecTurnstileBoot === 'function') {
		// Explicit mode: api.js would call this onload. We invoke it directly
		// since the real api.js cannot load offline.
		window.ecTurnstileBoot();
	}
	ecSmokeEmitMarker();
	setInterval(ecSmokeEmitMarker, 250);
})();
JS;

$body = $widget_one . "\n" . $widget_two
	. "\n<script>\n" . $stub_turnstile_js . "\n</script>"
	. "\n<script>\n" . $boot_js . "\n</script>"
	. "\n<script>\n" . $boot_runner_js . "\n</script>";

$existing = get_page_by_path( 'ec-turnstile-cross-widget-smoke' );
$postarr  = array(
	'post_title'   => 'EC Turnstile Cross-Widget Smoke',
	'post_name'    => 'ec-turnstile-cross-widget-smoke',
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_content' => $body,
);
if ( $existing ) {
	$postarr['ID'] = $existing->ID;
}
$page_id = wp_insert_post( $postarr, true );
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( esc_html( 'Failed to seed smoke page: ' . $page_id->get_error_message() ) );
}

update_option(
	'ec_rig_journey_fixture_cross_widget_turnstile',
	array(
		'schema'  => 'extrachill-network/journey-fixture/cross-widget-turnstile/v1',
		'page_id' => (int) $page_id,
		'url'     => get_permalink( $page_id ),
		'widgets' => 2,
	),
	false
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( array(
	'seeded'           => true,
	'page_id'          => (int) $page_id,
	'widgets'          => 2,
	'broken_widget'    => true,
	'has_callback'     => ( false !== strpos( $body, 'data-callback' ) ),
	'uses_boot_script' => true,
) ) ) );
