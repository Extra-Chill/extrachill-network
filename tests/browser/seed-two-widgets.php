<?php
/**
 * Seed a page that renders TWO Cloudflare Turnstile widgets via the plugin's
 * own ec_render_turnstile_widget(), driven by the plugin's REAL explicit-render
 * boot script (assets/js/turnstile-boot.js), and prove cross-widget isolation.
 *
 * Background — the bug class this guards (newsletter #17 / multisite #48): the
 * primitive used to rely on Cloudflare api.js IMPLICIT auto-render — a single
 * batch pass over every .cf-turnstile element. One widget carrying a
 * data-callback naming an undefined JS function threw during that pass and
 * aborted rendering for EVERY widget on the page, so an unrelated sibling (e.g.
 * the event-submission captcha) silently never rendered.
 *
 * The fix (multisite #48): ec_enqueue_turnstile_script() now loads api.js in
 * EXPLICIT mode and ships window.ecTurnstileBoot (turnstile-boot.js), which
 * renders EACH widget in its own turnstile.render() wrapped in try/catch. A bad
 * widget can then only break itself.
 *
 * This seed proves that contract end to end. It cannot reach the live Cloudflare
 * api.js inside the offline Playground sandbox, so it provides a faithful stub
 * of window.turnstile.render() that mirrors the real API's failure semantics:
 *   - render() looks up any `callback` option; if it was handed a function it
 *     marks the widget rendered.
 *   - to simulate a dangling/undefined callback, the stub THROWS when a widget
 *     declares a data-callback whose named global does not exist.
 * It then loads the plugin's ACTUAL boot script and lets it drive rendering.
 *
 * The decisive scenario: TWO widgets where the FIRST declares a broken
 * data-callback (undefined global) and the SECOND is well-formed. Under the old
 * implicit batch this aborted BOTH. Under explicit per-widget render the boot's
 * try/catch isolates the failure: the bad widget is skipped, the GOOD widget
 * still renders. The smoke asserts rendered >= 1 (the good widget) with total=2.
 *
 * Run inside the wp-codebox sandbox via wordpress.run-php.
 *
 * @package ExtraChill\Network
 */

if ( ! function_exists( 'ec_render_turnstile_widget' ) ) {
	echo wp_json_encode(
		array(
			'seeded' => false,
			'error'  => 'ec_render_turnstile_widget() not available — plugin not loaded',
		)
	);
	return;
}

// Configure Turnstile so the renderer emits real widgets (it returns '' when
// unconfigured). Test keys only; never hits the network in this smoke.
update_site_option( 'ec_turnstile_site_key', '1x00000000000000000000AA' );
update_site_option( 'ec_turnstile_secret_key', '1x0000000000000000000000000000000AA' );

// Locate the plugin's REAL boot script so the smoke exercises shipped code, not
// a reimplementation. If it is missing the seed fails loudly.
$boot_path = WP_PLUGIN_DIR . '/extrachill-network/assets/js/turnstile-boot.js';
if ( ! file_exists( $boot_path ) ) {
	echo wp_json_encode(
		array(
			'seeded' => false,
			'error'  => 'turnstile-boot.js not found at ' . $boot_path,
		)
	);
	return;
}
$boot_js = file_get_contents( $boot_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset in a test sandbox.

// Widget ONE: deliberately broken — declares a data-callback naming a global
// that is never defined. Under the OLD implicit batch this aborted ALL widgets.
$widget_one = ec_render_turnstile_widget(
	array(
		'data-size'     => 'invisible',
		'data-callback' => 'ecSmokeUndefinedCallbackDoesNotExist',
		'id'            => 'ec-smoke-widget-broken',
	)
);

// Widget TWO: well-formed, the way a real consumer renders. This is the sibling
// that the lackey bug killed. It MUST still render.
$widget_two = ec_render_turnstile_widget(
	array(
		'id' => 'ec-smoke-widget-good',
	)
);

// Faithful stub of window.turnstile, mirroring the real api.js render contract
// closely enough to prove isolation:
//   - render(el, opts) throws if opts.callback resolves to a *named-but-missing*
//     global. The boot script only passes a `callback` option when the named
//     global is a real function, so a dangling data-callback never reaches here
//     as a callback — but we ALSO throw if the element still carries a
//     data-callback attribute pointing at an undefined global, simulating
//     Cloudflare rejecting the widget config. Either way: bad widget throws,
//     boot's try/catch must contain it.
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
// Emit the machine-readable marker the runner asserts on. Recompute from the
// DOM so it reflects the true end state after the boot's per-widget loop.
function ecSmokeEmitMarker() {
	var done = document.querySelectorAll('.cf-turnstile[data-rendered="1"]').length;
	var all = document.querySelectorAll('.cf-turnstile').length;
	console.log('EC_TURNSTILE_SMOKE rendered=' + done + ' total=' + all);
}
JS;

// Order matters: stub turnstile first, then the real boot script (which defines
// window.ecTurnstileBoot and, because window.turnstile already exists, renders
// immediately), then emit the marker on a short interval so the browser-probe
// console listener reliably catches it after navigation.
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

// Create (or update) a published page the browser-probe can visit at a stable
// slug.
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
	echo wp_json_encode(
		array(
			'seeded' => false,
			'error'  => 'Failed to seed smoke page: ' . $page_id->get_error_message(),
		)
	);
	return;
}

echo wp_json_encode(
	array(
		'seeded'           => true,
		'page_id'          => (int) $page_id,
		'url'              => get_permalink( $page_id ),
		'widgets'          => 2,
		'broken_widget'    => true,
		'has_callback'     => ( false !== strpos( $body, 'data-callback' ) ),
		'uses_boot_script' => true,
	)
);
