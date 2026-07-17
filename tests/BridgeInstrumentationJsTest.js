const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const source = fs.readFileSync(
	require.resolve( '../assets/js/bridge-instrumentation.js' ),
	'utf8'
);

let failures = 0;

function check( label, condition ) {
	if ( condition ) {
		console.log( `PASS: ${ label }` );
		return;
	}

	console.error( `FAIL: ${ label }` );
	failures++;
}

function makeLink( destination, label, rect ) {
	return {
		textContent: label,
		rect,
		getAttribute( name ) {
			return name === 'href'
				? `https://${ destination }.extrachill.com/item?utm_campaign=${ destination }`
				: '';
		},
		getBoundingClientRect() {
			return this.rect;
		},
		closest( selector ) {
			return selector === '.ec-cross-site-link' ? this : null;
		},
	};
}

function createHarness( links, supportsObserver ) {
	const events = [];
	const listeners = {};
	const removedListeners = [];
	const timers = [];
	const observers = [];
	let clickHandler;

	class FakeBlob {
		constructor( parts ) {
			this.parts = parts;
		}
	}

	const window = {
		ecBridgeInstrumentation: {
			clickEndpoint: '/click',
			impressionEndpoint: '/impression',
			linkClass: 'ec-cross-site-link',
			sourcePost: 42,
			sourceSite: 'main',
		},
		location: {
			href: 'https://extrachill.com/source',
			origin: 'https://extrachill.com',
		},
		innerWidth: 1000,
		innerHeight: 800,
		addEventListener( type, handler ) {
			listeners[ type ] = handler;
		},
		removeEventListener( type, handler ) {
			if ( listeners[ type ] === handler ) {
				removedListeners.push( type );
				delete listeners[ type ];
			}
		},
		setTimeout( handler, delay ) {
			timers.push( { handler, delay } );
			return timers.length;
		},
		clearTimeout() {},
	};

	if ( supportsObserver ) {
		window.IntersectionObserver = class {
			constructor( callback, options ) {
				this.callback = callback;
				this.options = options;
				this.observed = [];
				this.unobserved = [];
				observers.push( this );
			}
			observe( link ) {
				this.observed.push( link );
			}
			unobserve( link ) {
				this.unobserved.push( link );
			}
		};
	}

	const context = {
		window,
		document: {
			getElementsByClassName() {
				return links;
			},
			addEventListener( type, handler ) {
				if ( type === 'click' ) {
					clickHandler = handler;
				}
			},
		},
		navigator: {
			sendBeacon( endpoint, blob ) {
				events.push( {
					endpoint,
					payload: JSON.parse( blob.parts.join( '' ) ),
				} );
				return true;
			},
		},
		Blob: FakeBlob,
		URL,
		console,
	};

	vm.runInNewContext( source, context );

	return {
		events,
		listeners,
		removedListeners,
		timers,
		observers,
		click( link ) {
			let prevented = false;
			clickHandler( {
				target: link,
				preventDefault() {
					prevented = true;
				},
			} );
			return prevented;
		},
	};
}

const visibleRect = {
	top: 100,
	right: 300,
	bottom: 140,
	left: 100,
	width: 200,
	height: 40,
};
const hiddenRect = {
	top: 900,
	right: 300,
	bottom: 940,
	left: 100,
	width: 200,
	height: 40,
};

const eventsLink = makeLink( 'events', 'Events', visibleRect );
const wireLink = makeLink( 'wire', 'Wire', hiddenRect );
const observerHarness = createHarness( [ eventsLink, wireLink ], true );
const observer = observerHarness.observers[ 0 ];

check(
	'IntersectionObserver uses a 50% threshold',
	observer.options.threshold[ 0 ] === 0.5
);
check( 'all initial bridge elements are observed', observer.observed.length === 2 );
check( 'renders do not count before viewport exposure', observerHarness.events.length === 0 );

observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 0.49 },
] );
check( 'less than 50% visibility does not count', observerHarness.events.length === 0 );

observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 0.5 },
] );
observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'viewport exposure is deduped by element', observerHarness.events.length === 1 );
check( 'exposure retains destination identity', observerHarness.events[ 0 ].payload.dest_site === 'events' );
check( 'exposed elements stop being observed', observer.unobserved[ 0 ] === eventsLink );

check( 'click handling does not prevent navigation', observerHarness.click( eventsLink ) === false );
observerHarness.click( eventsLink );
check(
	'click is deduped by the same element opportunity',
	observerHarness.events.filter( ( event ) => event.endpoint === '/click' ).length === 1
);

observerHarness.click( wireLink );
check(
	'a click emits its missing exposure before the click',
	observerHarness.events
		.slice( -2 )
		.map( ( event ) => event.endpoint )
		.join( ',' ) === '/impression,/click'
);
check(
	'click and exposure totals remain bounded',
	observerHarness.events.filter( ( event ) => event.endpoint === '/click' ).length ===
		observerHarness.events.filter( ( event ) => event.endpoint === '/impression' ).length
);

const duplicateOne = makeLink( 'events', 'Events One', visibleRect );
const duplicateTwo = makeLink( 'events', 'Events Two', visibleRect );
const duplicateHarness = createHarness( [ duplicateOne, duplicateTwo ], true );
const duplicateObserver = duplicateHarness.observers[ 0 ];
duplicateObserver.callback( [
	{ target: duplicateOne, isIntersecting: true, intersectionRatio: 1 },
	{ target: duplicateTwo, isIntersecting: true, intersectionRatio: 1 },
] );
check(
	'separate elements remain separate opportunities',
	duplicateHarness.events.length === 2
);

const fallbackVisible = makeLink( 'community', 'Community', visibleRect );
const fallbackHidden = makeLink( 'artist', 'Artist', hiddenRect );
const fallbackHarness = createHarness( [ fallbackVisible, fallbackHidden ], false );

check(
	'fallback counts only initially visible elements',
	fallbackHarness.events.length === 1
);
check(
	'fallback lifetime is bounded to 30 seconds',
	fallbackHarness.timers[ 0 ].delay === 30000
);

fallbackHidden.rect = visibleRect;
fallbackHarness.listeners.scroll();
check(
	'fallback counts elements when they enter the viewport',
	fallbackHarness.events.length === 2
);
check(
	'fallback removes scroll and resize listeners after all exposures',
	fallbackHarness.removedListeners.includes( 'scroll' ) &&
		fallbackHarness.removedListeners.includes( 'resize' )
);

const timedOutLink = makeLink( 'shop', 'Shop', hiddenRect );
const timedOutHarness = createHarness( [ timedOutLink ], false );
timedOutHarness.timers[ 0 ].handler();
check(
	'fallback removes listeners when its time bound expires',
	timedOutHarness.removedListeners.includes( 'scroll' ) &&
		timedOutHarness.removedListeners.includes( 'resize' )
);
timedOutHarness.click( timedOutLink );
check(
	'click still creates one exposure after fallback expiry',
	timedOutHarness.events.length === 2
);

if ( failures > 0 ) {
	process.exit( 1 );
}

console.log( 'All bridge instrumentation JS tests passed.' );
