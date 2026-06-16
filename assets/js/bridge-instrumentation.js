/**
 * Cross-Site Bridge Instrumentation
 *
 * Fires two sibling analytics events for the shared cross-site link engine:
 *
 *   - impression: once per pageview, when >=1 bridge link is present in the DOM.
 *                 POST /analytics/impression  (impression_type=bridge)
 *   - click:      when a human clicks a cross-site link button.
 *                 POST /analytics/click       (click_type=bridge)
 *
 * Both ship via navigator.sendBeacon (no AJAX, fire-and-forget, survives the
 * navigation a click triggers), mirroring the theme's share.js click tracking.
 * Because this code only runs in a real, JS-executing browser, prefetch/
 * prerender/crawler hits — the source of the bridge channel's bot inflation —
 * never fire either event. That is the built-in bot filter: clicks and
 * impressions are humans-with-JS by construction.
 *
 * The destination site is read from the link's existing utm_campaign param, so
 * no new markup contract is introduced — the bridge consumers already UTM-tag
 * every outbound URL.
 */
( function () {
	var config = window.ecBridgeInstrumentation;
	if ( ! config || ! config.clickEndpoint || ! config.impressionEndpoint || ! config.linkClass ) {
		return;
	}

	/**
	 * Send a payload to an endpoint via sendBeacon, falling back to keepalive fetch.
	 *
	 * @param {string} endpoint Destination REST endpoint.
	 * @param {Object} payload  JSON payload.
	 */
	function send( endpoint, payload ) {
		var data = JSON.stringify( payload );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				endpoint,
				new Blob( [ data ], { type: 'application/json' } )
			);
		} else {
			fetch( endpoint, {
				method: 'POST',
				body: data,
				headers: { 'Content-Type': 'application/json' },
				keepalive: true,
			} ).catch( function () {} );
		}
	}

	/**
	 * Read a query param from a URL string, returning '' when absent.
	 *
	 * @param {string} url  URL to parse.
	 * @param {string} name Param name.
	 * @return {string} Param value or ''.
	 */
	function param( url, name ) {
		try {
			return new URL( url, window.location.origin ).searchParams.get( name ) || '';
		} catch ( e ) {
			return '';
		}
	}

	var links = document.getElementsByClassName( config.linkClass );

	// --- Impression: one per pageview when the bridge actually rendered cards.
	if ( links && links.length > 0 ) {
		send( config.impressionEndpoint, {
			impression_type: 'bridge',
			source_post: config.sourcePost || 0,
			source_url: window.location.href,
		} );
	}

	// --- Click: delegated so it covers links added after load, and so a single
	// listener instruments every bridge consumer.
	document.addEventListener(
		'click',
		function ( event ) {
			var target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}

			var link = target.closest( '.' + config.linkClass );
			if ( ! link ) {
				return;
			}

			var href = link.getAttribute( 'href' ) || '';

			send( config.clickEndpoint, {
				click_type: 'bridge',
				source_post: config.sourcePost || 0,
				// Destination site key is carried by the link's existing UTM campaign param.
				dest_site: param( href, 'utm_campaign' ),
				source_url: window.location.href,
				destination_url: href,
			} );
		},
		true
	);
} )();
