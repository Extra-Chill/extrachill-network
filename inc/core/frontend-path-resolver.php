<?php
/**
 * Authoritative frontend-path resolution across the Extra Chill network.
 *
 * A frontend path must be resolved by each target site's own WordPress boot:
 * switch_to_blog() changes database context but does not load that site's
 * post-type rewrite registrations. The existing HTTP loopback primitive gives
 * each candidate site its real bootstrap, then this file compares the target's
 * canonical permalink path exactly before returning any match.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a host-relative frontend path for canonical comparisons.
 *
 * Query strings and fragments do not identify content, so they are removed.
 * Absolute and protocol-relative URLs are rejected: callers with a hostname
 * must use the site-specific URL rather than asking this path-only contract to
 * infer its owner.
 *
 * @param string $path Host-relative frontend path.
 * @return string|null Normalized path, or null when it is not host-relative.
 */
function ec_normalize_frontend_path( string $path ): ?string {
	$path = trim( $path );
	if ( '' === $path || str_starts_with( $path, '//' ) || ! str_starts_with( $path, '/' ) ) {
		return null;
	}

	$parts = wp_parse_url( $path );
	if ( false === $parts || ! empty( $parts['host'] ) || ! empty( $parts['scheme'] ) || ! isset( $parts['path'] ) ) {
		return null;
	}

	$path = '/' . ltrim( (string) $parts['path'], '/' );

	return '/' === $path ? '/' : trailingslashit( $path );
}

/**
 * Resolve a host-relative frontend path to one exact, published network post.
 *
 * @param string $path Host-relative frontend path. Query strings and fragments
 *                     are ignored; absolute URLs are unresolved.
 * @return array{status:string,path:?string,candidate?:array,candidates?:array}
 */
function ec_resolve_frontend_path( string $path ): array {
	$path = ec_normalize_frontend_path( $path );
	if ( null === $path ) {
		return array(
			'status'     => 'unresolved',
			'path'       => null,
			'candidates' => array(),
		);
	}

	$candidates = array();
	foreach ( ec_get_blog_ids() as $site_key => $blog_id ) {
		$response = ec_cross_site_rest_request_http(
			$site_key,
			'GET',
			'/extrachill-network/v1/frontend-path-resolution',
			array(
				'query' => array( 'path' => $path ),
			)
		);

		if ( is_wp_error( $response ) || ! is_array( $response ) || 'resolved' !== ( $response['status'] ?? null ) ) {
			continue;
		}

		$candidate = $response['candidate'] ?? null;
		if ( ! is_array( $candidate ) || ec_normalize_frontend_path( (string) ( $candidate['canonical_path'] ?? '' ) ) !== $path ) {
			continue;
		}

		if ( (int) ( $candidate['blog_id'] ?? 0 ) !== (int) $blog_id || empty( $candidate['post_id'] ) || empty( $candidate['post_type'] ) || empty( $candidate['canonical_url'] ) ) {
			continue;
		}

		$candidates[ (int) $candidate['blog_id'] . ':' . (int) $candidate['post_id'] ] = $candidate;
	}

	$candidates = array_values( $candidates );
	usort(
		$candidates,
		static fn( array $left, array $right ): int => array( (int) $left['blog_id'], (int) $left['post_id'] ) <=> array( (int) $right['blog_id'], (int) $right['post_id'] )
	);

	if ( 1 === count( $candidates ) ) {
		return array(
			'status'    => 'resolved',
			'path'      => $path,
			'candidate' => $candidates[0],
		);
	}

	return array(
		'status'     => empty( $candidates ) ? 'unresolved' : 'ambiguous',
		'path'       => $path,
		'candidates' => $candidates,
	);
}

/**
 * Register the target-local probe used by ec_resolve_frontend_path().
 *
 * The response is public because it exposes only already-public, published
 * content. Network callers consume it through a localhost loopback, ensuring
 * the target site's own plugin and rewrite registrations are initialized.
 */
function ec_register_frontend_path_resolver_route(): void {
	register_rest_route(
		'extrachill-network/v1',
		'/frontend-path-resolution',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ec_frontend_path_resolver_rest_callback',
			'permission_callback' => '__return_true',
			'args'                => array(
				'path' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ec_register_frontend_path_resolver_route' );

/**
 * Resolve an exact canonical path inside the currently booted site.
 *
 * @param WP_REST_Request $request Request containing a host-relative path.
 * @return WP_REST_Response
 */
function ec_frontend_path_resolver_rest_callback( WP_REST_Request $request ): WP_REST_Response {
	$path = ec_normalize_frontend_path( (string) $request->get_param( 'path' ) );
	if ( null === $path ) {
		return new WP_REST_Response( array( 'status' => 'unresolved' ) );
	}

	$post_id = url_to_postid( home_url( $path ) );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return new WP_REST_Response( array( 'status' => 'unresolved' ) );
	}

	$canonical_url  = get_permalink( $post );
	$canonical_path = $canonical_url ? ec_normalize_frontend_path( (string) wp_parse_url( $canonical_url, PHP_URL_PATH ) ) : null;
	if ( $path !== $canonical_path ) {
		return new WP_REST_Response( array( 'status' => 'unresolved' ) );
	}

	return new WP_REST_Response(
		array(
			'status'    => 'resolved',
			'candidate' => array(
				'blog_id'        => get_current_blog_id(),
				'post_id'        => (int) $post->ID,
				'post_type'      => $post->post_type,
				'canonical_url'  => $canonical_url,
				'canonical_path' => $canonical_path,
			),
		)
	);
}
