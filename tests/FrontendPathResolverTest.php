<?php
/**
 * Standalone contract checks for frontend-path batch resolution.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

class WP_Post {
	public function __construct( public int $ID, public string $post_status, public string $post_type, public string $url ) {}
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function add_action(): void {}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function ec_get_blog_ids(): array { return array( 'main' => 1, 'events' => 7, 'wire' => 11 ); }
function home_url( string $path ): string { return 'https://example.test' . $path; }
function get_post( int $post_id ): ?WP_Post { return $GLOBALS['ec_test_posts'][ $post_id ] ?? null; }
function get_permalink( WP_Post $post ): string { return $post->url; }
function get_current_blog_id(): int { return 1; }
function ec_cross_site_rest_request_http( string $site_key, string $method, string $path, array $args ) {
	$GLOBALS['ec_test_calls'][] = array( 'site_key' => $site_key, 'method' => $method, 'path' => $path, 'args' => $args );
	return $GLOBALS['ec_test_responses'][ $site_key ];
}

require_once dirname( __DIR__ ) . '/inc/core/frontend-path-resolver.php';

/** Fail the standalone process with a useful message. */
function ec_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Build a valid target response for the requested paths. */
function ec_test_response( int $blog_id, array $paths, array $resolved = array() ): array {
	$results = array();
	foreach ( $paths as $path ) {
		if ( isset( $resolved[ $path ] ) ) {
			$results[ $path ] = array( 'status' => 'resolved', 'candidate' => $resolved[ $path ] );
		} else {
			$results[ $path ] = array( 'status' => 'unresolved' );
		}
	}

	return array( 'status' => 'complete', 'results' => $results );
}

/** Build canonical target evidence. */
function ec_test_candidate( int $blog_id, int $post_id, string $type, string $path ): array {
	return array(
		'blog_id'        => $blog_id,
		'post_id'        => $post_id,
		'post_type'      => $type,
		'canonical_url'  => 'https://example.test' . $path,
		'canonical_path' => $path,
	);
}

$paths = array( '/main/', '/events/sample/' );
$GLOBALS['ec_test_calls'] = array();
$GLOBALS['ec_test_responses'] = array(
	'main'   => ec_test_response( 1, $paths, array( '/main/' => ec_test_candidate( 1, 10, 'post', '/main/' ) ) ),
	'events' => ec_test_response( 7, $paths, array( '/events/sample/' => ec_test_candidate( 7, 20, 'data_machine_events', '/events/sample/' ) ) ),
	'wire'   => ec_test_response( 11, $paths ),
);
$batch = ec_resolve_frontend_paths( array( '/main/?ref=x#one', '/events/sample/', '/main/' ) );
ec_test_assert( 'complete' === $batch['scan']['status'], 'Complete target scan expected.' );
ec_test_assert( 3 === count( $GLOBALS['ec_test_calls'] ), 'One target request per site expected.' );
ec_test_assert( array( '/main/', '/events/sample/' ) === $GLOBALS['ec_test_calls'][0]['args']['query']['paths'], 'Normalized paths must be deduplicated per target request.' );
ec_test_assert( 'resolved' === $batch['results'][0]['status'] && 1 === $batch['results'][0]['candidate']['blog_id'], 'Main path should resolve.' );
ec_test_assert( 'resolved' === $batch['results'][1]['status'] && 7 === $batch['results'][1]['candidate']['blog_id'], 'Events path should resolve.' );

$GLOBALS['ec_test_calls'] = array();
$single = ec_resolve_frontend_path( '/main/' );
ec_test_assert( 'complete' === $single['scan']['status'] && 'resolved' === $single['status'], 'Single wrapper must delegate to the complete batch contract.' );
ec_test_assert( 3 === count( $GLOBALS['ec_test_calls'] ), 'Single wrapper must use one request per site.' );

$GLOBALS['ec_test_responses']['wire'] = new WP_Error( 'target_down', 'Wire unavailable.' );
$partial = ec_resolve_frontend_paths( array( '/main/' ) );
ec_test_assert( 'incomplete' === $partial['scan']['status'] && 'incomplete' === $partial['results'][0]['status'], 'Partial scans must never claim a unique match.' );
ec_test_assert( 'target_down' === $partial['results'][0]['failures'][0]['code'], 'Target failure evidence is required.' );

$GLOBALS['ec_test_responses']['wire'] = array( 'status' => 'complete', 'results' => array() );
$malformed = ec_resolve_frontend_paths( array( '/main/' ) );
ec_test_assert( 'incomplete' === $malformed['results'][0]['status'] && 'malformed_response' === $malformed['scan']['failures'][0]['code'], 'Malformed target responses must be incomplete.' );

$GLOBALS['ec_test_responses']['wire'] = ec_test_response( 11, array( '/shared/' ), array( '/shared/' => ec_test_candidate( 11, 30, 'festival_wire', '/shared/' ) ) );
$GLOBALS['ec_test_responses']['main'] = ec_test_response( 1, array( '/shared/' ), array( '/shared/' => ec_test_candidate( 1, 10, 'post', '/shared/' ) ) );
$GLOBALS['ec_test_responses']['events'] = ec_test_response( 7, array( '/shared/' ) );
$ambiguous = ec_resolve_frontend_paths( array( '/shared/' ) );
ec_test_assert( 'ambiguous' === $ambiguous['results'][0]['status'] && 2 === count( $ambiguous['results'][0]['candidates'] ), 'Exact collisions must be deterministically ambiguous.' );
ec_test_assert( 1 === $ambiguous['results'][0]['candidates'][0]['blog_id'], 'Ambiguity evidence must be ordered.' );

$GLOBALS['ec_test_responses']['main'] = ec_test_response( 1, array( '/missing/' ) );
$GLOBALS['ec_test_responses']['events'] = ec_test_response( 7, array( '/missing/' ) );
$GLOBALS['ec_test_responses']['wire'] = ec_test_response( 11, array( '/missing/' ) );
$unresolved = ec_resolve_frontend_paths( array( '/missing/' ) );
ec_test_assert( 'unresolved' === $unresolved['results'][0]['status'], 'Complete scans with no candidates must be unresolved.' );

$GLOBALS['ec_test_posts'] = array( 1 => new WP_Post( 1, 'draft', 'post', 'https://example.test/draft/' ) );
function url_to_postid( string $url ): int { return 1; }
ec_test_assert( 'unresolved' === ec_frontend_path_resolve_local( '/draft/' )['status'], 'Drafts must not resolve.' );
$GLOBALS['ec_test_posts'][1] = new WP_Post( 1, 'publish', 'post', 'https://example.test/canonical/' );
ec_test_assert( 'unresolved' === ec_frontend_path_resolve_local( '/wrong/' )['status'], 'Canonical mismatches must not resolve.' );

fwrite( STDOUT, "FrontendPathResolverTest passed.\n" );
