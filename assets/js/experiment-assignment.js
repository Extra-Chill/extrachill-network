/**
 * Cache-neutral experiment assignment and exposure lifecycle.
 *
 * Cached HTML contains only control metadata. This script asks the core
 * Abilities API for an assignment after page load, then emits separate neutral
 * assignment and viewport-exposure events. Network never persists either.
 */
( function () {
	var config = window.ecExperimentAssignment;
	if ( ! config || ! config.endpoint || ! window.fetch ) {
		return;
	}

	var elements = document.querySelectorAll(
		'[data-ec-experiment-key][data-ec-experiment-surface]'
	);
	if ( ! elements.length ) {
		return;
	}

	function dispatch( name, element, detail ) {
		element.dispatchEvent(
			new CustomEvent( name, {
				bubbles: true,
				detail: {
					experiment_key: detail.experiment_key,
					variant: detail.variant,
					surface: detail.surface,
				},
			} )
		);
	}

	function observeExposure( element, detail ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var observer = new window.IntersectionObserver(
			function ( entries ) {
				if ( entries[ 0 ].isIntersecting && entries[ 0 ].intersectionRatio >= 0.5 ) {
					dispatch( 'extrachill:experiment-exposure', element, detail );
					observer.disconnect();
				}
			},
			{ threshold: [ 0.5 ] }
		);
		observer.observe( element );
	}

	function parseContext( element ) {
		try {
			var context = JSON.parse( element.getAttribute( 'data-ec-experiment-context' ) || '{}' );
			return context && typeof context === 'object' && ! Array.isArray( context )
				? context
				: {};
		} catch ( error ) {
			return {};
		}
	}

	function assign( element ) {
		var experimentKey = element.getAttribute( 'data-ec-experiment-key' ) || '';
		var surface = element.getAttribute( 'data-ec-experiment-surface' ) || '';
		var query = new URLSearchParams();
		query.set( 'input[experiment_key]', experimentKey );
		query.set( 'input[surface]', surface );
		var context = parseContext( element );
		Object.keys( context ).forEach( function ( key ) {
			if ( [ 'string', 'number', 'boolean' ].includes( typeof context[ key ] ) ) {
				query.set( 'input[context][' + key + ']', String( context[ key ] ) );
			}
		} );

		window
			.fetch( config.endpoint + '?' + query.toString(), {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Assignment unavailable' );
				}
				return response.json();
			} )
			.then( function ( result ) {
				if (
					! result ||
					result.measurement_eligible !== true ||
					result.experiment_key !== experimentKey ||
					result.surface !== surface ||
					typeof result.variant !== 'string'
				) {
					return;
				}

				element.setAttribute( 'data-ec-experiment-variant', result.variant );
				dispatch( 'extrachill:experiment-assignment', element, result );
				observeExposure( element, result );
			} )
			.catch( function () {
				// Control markup remains unchanged and unmeasured on every failure.
			} );
	}

	for ( var i = 0; i < elements.length; i++ ) {
		assign( elements[ i ] );
	}
} )();
