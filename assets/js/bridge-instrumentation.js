/**
 * Cross-Site Bridge Instrumentation
 *
 * Fires two sibling analytics events for the shared cross-site link engine:
 *
 *   - impression: one per rendered bridge card present in the DOM, each
 *                 carrying that card's dest_site, deduped per pageview.
 *   - click:      when a human clicks a cross-site link button.
 *
 * Both ship via navigator.sendBeacon (no AJAX, fire-and-forget, survives the
 * navigation a click triggers) to the canonical extrachill-api analytics
 * routes — clicks to /analytics/click (click_type=bridge), impressions to
 * /analytics/impression (impression_type=bridge). Because this code only runs
 * in a real, JS-executing browser, prefetch/prerender/crawler hits — the
 * source of the bridge channel's bot inflation — never fire either event. That
 * is the built-in bot filter: clicks and impressions are humans-with-JS by
 * construction.
 *
 * Destination context (dest_site, term) is read from the link's existing UTM
 * params, so no new markup contract is introduced — the bridge consumers
 * already UTM-tag every outbound URL. Emitting one impression PER CARD (rather
 * than one page-level impression) gives impressions the same per-destination
 * grain the click beacon already has, so CTR = clicks / impressions becomes
 * computable per destination. See extrachill-analytics#75.
 */
( function () {
	var config = window.ecBridgeInstrumentation;
	if (
		! config ||
		! config.clickEndpoint ||
		! config.impressionEndpoint ||
		! config.linkClass
	) {
		return;
	}

	/**
	 * Send an event via sendBeacon, falling back to keepalive fetch.
	 *
	 * @param {string} endpoint Target REST endpoint.
	 * @param {Object} payload  Event payload.
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
			} );
		}
	}

	/**
	 * Read a query param from a URL string, returning '' when absent.
	 *
	 * @param {string} url   URL to parse.
	 * @param {string} param Param name.
	 * @return {string} Param value or ''.
	 */
	function param( url, param ) {
		try {
			return new URL( url, window.location.origin ).searchParams.get( param ) || '';
		} catch ( e ) {
			return '';
		}
	}

	var links = document.getElementsByClassName( config.linkClass );

	// --- Impression: one per rendered bridge card, each carrying that card's
	// dest_site (read from the link's existing utm_campaign, exactly like the
	// click beacon below). Posts to the canonical extrachill-api impression
	// route (impression_type=bridge).
	//
	// Deduped per pageview by dest_site: if a page renders the same destination
	// more than once, only the first card emits an impression for that dest.
	// This keeps the impression denominator at one-per-destination-per-pageview,
	// the simplest grain that pairs cleanly with the per-destination click
	// numerator for CTR. See extrachill-analytics#75.
	if ( links && links.length > 0 ) {
		var seenDests = {};
		for ( var i = 0; i < links.length; i++ ) {
			var card = links[ i ];
			var cardHref = card.getAttribute( 'href' ) || '';
			var destSite = param( cardHref, 'utm_campaign' );

			// Dedupe on dest_site within this pageview. An empty dest_site can
			// only be counted once so an untagged card can't inflate the
			// denominator.
			if ( Object.prototype.hasOwnProperty.call( seenDests, destSite ) ) {
				continue;
			}
			seenDests[ destSite ] = true;

			send( config.impressionEndpoint, {
				impression_type: 'bridge',
				source_url: window.location.href,
				source_post: config.sourcePost || 0,
				source_site: config.sourceSite || '',
				// Destination context is carried by the card link's existing UTM
				// params, mirroring the click beacon below.
				dest_site: destSite,
				term: card.textContent ? card.textContent.trim() : '',
			} );
		}
	}

	// --- Click: delegated so it covers links added after load, and so a single
	// listener instruments every bridge consumer. Posts to the canonical
	// extrachill-api click route (click_type=bridge).
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
				source_url: window.location.href,
				source_post: config.sourcePost || 0,
				source_site: config.sourceSite || '',
				// Destination context is carried by the link's existing UTM params.
				dest_site: param( href, 'utm_campaign' ),
				term: link.textContent ? link.textContent.trim() : '',
			} );
		},
		true
	);
} )();
