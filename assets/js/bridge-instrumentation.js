/**
 * Cross-Site Bridge Instrumentation
 *
 * Fires two sibling analytics events for the shared cross-site link engine:
 *
 *   - impression: once per pageview, when >=1 bridge link is present in the DOM.
 *   - click:      when a human clicks a cross-site link button.
 *
 * Both ship via navigator.sendBeacon (no AJAX, fire-and-forget, survives the
 * navigation a click triggers). Because this code only runs in a real,
 * JS-executing browser, prefetch/prerender/crawler hits — the source of the
 * bridge channel's bot inflation — never fire either event. That is the
 * built-in bot filter: clicks and impressions are humans-with-JS by
 * construction.
 *
 * Destination context (dest_site, term) is read from the link's existing UTM
 * params, so no new markup contract is introduced — the bridge consumers
 * already UTM-tag every outbound URL.
 */
( function () {
	var config = window.ecBridgeInstrumentation;
	if ( ! config || ! config.endpoint || ! config.linkClass ) {
		return;
	}

	/**
	 * Send an event via sendBeacon, falling back to keepalive fetch.
	 *
	 * @param {Object} payload Event payload.
	 */
	function send( payload ) {
		var data = JSON.stringify( payload );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				config.endpoint,
				new Blob( [ data ], { type: 'application/json' } )
			);
		} else {
			fetch( config.endpoint, {
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

	/**
	 * Base payload shared by both event kinds.
	 *
	 * @param {string} kind Event kind ('click' | 'impression').
	 * @return {Object} Payload.
	 */
	function base( kind ) {
		return {
			kind: kind,
			source_post: config.sourcePost || 0,
			source_site: config.sourceSite || '',
			source_url: window.location.href,
		};
	}

	var links = document.getElementsByClassName( config.linkClass );

	// --- Impression: one per pageview when the bridge actually rendered cards.
	if ( links && links.length > 0 ) {
		send( base( 'impression' ) );
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
			var payload = base( 'click' );

			// Destination context is carried by the link's existing UTM params.
			payload.dest_site = param( href, 'utm_campaign' );
			payload.term = link.textContent ? link.textContent.trim() : '';

			send( payload );
		},
		true
	);
} )();
