<?php
/**
 * Cross-Site Bridge Instrumentation (enqueue)
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
 *   2. IMPRESSION event — fires when a bridge actually renders WITH cards on a
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
 * `navigator.sendBeacon()` to the canonical extrachill-api analytics routes:
 *   - POST /wp-json/extrachill/v1/analytics/click       (click_type=bridge)
 *   - POST /wp-json/extrachill/v1/analytics/impression  (impression_type=bridge)
 * Those thin route handlers record through the extrachill/track-analytics-event
 * ability — this plugin owns only the render-side instrumentation (enqueue +
 * the marker class every cross-site button already carries), never REST routes
 * or write logic.
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
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_bridge_enqueue_instrumentation', 20 );
