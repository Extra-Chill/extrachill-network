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
 * `navigator.sendBeacon()` (fire-and-forget, survives navigation) to a single
 * REST receiver registered here. The receiver records via the network-wide
 * `extrachill_track_analytics_event()` recording path owned by
 * extrachill-analytics; if that plugin is inactive the receiver degrades to a
 * no-op rather than fataling.
 *
 * @package ExtraChillMultisite
 * @since 1.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics event_type for a human click on a cross-site bridge link.
 */
const EC_BRIDGE_CLICK_EVENT = 'bridge_click';

/**
 * Analytics event_type for a real-browser bridge impression (render with cards).
 */
const EC_BRIDGE_IMPRESSION_EVENT = 'bridge_impression';

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
			'endpoint'   => rest_url( 'extrachill/v1/analytics/bridge-event' ),
			'linkClass'  => 'ec-cross-site-link',
			'sourcePost' => (int) get_the_ID(),
			'sourceSite' => (string) extrachill_get_current_site_key(),
			'clickEvent' => EC_BRIDGE_CLICK_EVENT,
			'viewEvent'  => EC_BRIDGE_IMPRESSION_EVENT,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_bridge_enqueue_instrumentation', 20 );

/**
 * Register the bridge-event REST receiver.
 *
 * Single endpoint for BOTH sibling events (click + impression); the event kind
 * arrives in the body. Public (`__return_true`) because it is fired from
 * anonymous front-end pageviews exactly like the existing analytics view
 * endpoint — the recording layer sanitizes and bounds everything.
 */
function extrachill_bridge_register_rest_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/bridge-event',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_bridge_handle_event',
			'permission_callback' => '__return_true',
			'args'                => array(
				'kind'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'source_post' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				),
				'source_site' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => '',
				),
				'dest_site'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => '',
				),
				'term'        => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
				'source_url'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'default'           => '',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'extrachill_bridge_register_rest_route' );

/**
 * Handle an inbound bridge click/impression beacon.
 *
 * Records to the network-wide analytics events table via the recording path
 * owned by extrachill-analytics. Degrades to a recorded:false no-op when that
 * plugin is inactive so the bridge keeps working standalone.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_bridge_handle_event( WP_REST_Request $request ) {
	$kind = $request->get_param( 'kind' );

	$event_type_map = array(
		'click'      => EC_BRIDGE_CLICK_EVENT,
		'impression' => EC_BRIDGE_IMPRESSION_EVENT,
	);

	if ( ! isset( $event_type_map[ $kind ] ) ) {
		return rest_ensure_response( array( 'recorded' => false ) );
	}

	if ( ! function_exists( 'extrachill_track_analytics_event' ) ) {
		// extrachill-analytics inactive — nothing to record into.
		return rest_ensure_response( array( 'recorded' => false ) );
	}

	$event_data = array(
		'source_post' => (int) $request->get_param( 'source_post' ),
		'source_site' => (string) $request->get_param( 'source_site' ),
		'dest_site'   => (string) $request->get_param( 'dest_site' ),
		'term'        => (string) $request->get_param( 'term' ),
	);

	$source_url = (string) $request->get_param( 'source_url' );

	$id = extrachill_track_analytics_event( $event_type_map[ $kind ], $event_data, $source_url );

	return rest_ensure_response( array( 'recorded' => (bool) $id ) );
}
