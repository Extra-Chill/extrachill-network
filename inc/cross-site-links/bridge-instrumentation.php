<?php
/**
 * Cross-Site Bridge Instrumentation
 *
 * Instruments the shared cross-site link engine ONCE so every consumer — the
 * blog bridge, the events bridge, the news-wire bridge, and the taxonomy /
 * artist-profile cross-site renderers — inherits two sibling measurements
 * without any per-consumer code:
 *
 *   1. CLICK event   — fires when a human clicks a cross-site link button.
 *                      Measures *intent*. Prefetch/prerender can fake a UTM
 *                      arrival at the destination but cannot fake a real
 *                      pointer click in a rendered, JS-executing browser.
 *
 *   2. IMPRESSION event — fires when a bridge section renders WITH cards on a
 *                      real human pageview (the JS only runs in a real browser,
 *                      so prefetch/crawler hits that never execute scripts are
 *                      excluded). Measures *exposure* — the awareness
 *                      denominator that was previously completely unknown.
 *
 * Together these make CTR = clicks / impressions deterministic, and both are
 * gated on the same "JS executed in a real browser" signal, so neither is
 * bot-inflated the way the raw UTM `network_bridge` channel is (see
 * extrachill-multisite#58).
 *
 * NO AJAX (system rule). The browser ships both events with
 * `navigator.sendBeacon()` (fire-and-forget, survives navigation) to the
 * canonical extrachill-api analytics routes — clicks to /analytics/click
 * (click_type=bridge), impressions to /analytics/impression
 * (impression_type=bridge). Those thin wrappers record via the network-wide
 * `extrachill/track-analytics-event` ability owned by extrachill-analytics.
 *
 * This file owns ONLY the client: enqueue, localize, and the sendBeacon JS.
 * The REST receiver lives in extrachill-api (the canonical REST home for
 * ability wrappers) — see extrachill-multisite#62.
 *
 * @package ExtraChillMultisite
 * @since 1.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the bridge instrumentation script on singular front-end views.
 *
 * Loaded broadly on `is_singular()` because every bridge consumer renders on a
 * singular view (blog/events/wire single posts, artist profile hubs). The
 * script self-gates at runtime: it fires an impression only when at least one
 * bridge link is actually present in the DOM, and a click only on a real
 * cross-site link click. No bridge cards on the page == zero beacons.
 */
function extrachill_bridge_enqueue_instrumentation() {
	if ( is_admin() || ! is_singular() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_MULTISITE_PLUGIN_DIR . 'assets/js/bridge-instrumentation.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-bridge-instrumentation',
		EXTRACHILL_MULTISITE_PLUGIN_URL . 'assets/js/bridge-instrumentation.js',
		array(),
		filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'extrachill-bridge-instrumentation',
		'ecBridgeInstrumentation',
		array(
			'clickEndpoint'      => rest_url( 'extrachill/v1/analytics/click' ),
			'impressionEndpoint' => rest_url( 'extrachill/v1/analytics/impression' ),
			'linkClass'          => 'ec-cross-site-link',
			'sourcePost'         => (int) get_the_ID(),
			'sourceSite'         => (string) extrachill_get_current_site_key(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_bridge_enqueue_instrumentation', 20 );
