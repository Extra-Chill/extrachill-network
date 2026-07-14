<?php
/**
 * Exercises one real large JSON POST through the cross-site HTTP helper.
 *
 * @package ExtraChillNetwork
 */

require_once dirname( __DIR__ ) . '/inc/core/frontend-path-resolver.php';

$paths = array_fill( 0, EC_FRONTEND_PATH_RESOLVER_MAX_PATHS, '/' . str_repeat( 'a', 100 ) );
$result = ec_cross_site_rest_request_http(
	'events',
	'POST',
	'/wp/v2/posts',
	array(
		'body'    => array( 'paths' => $paths ),
		'timeout' => 5,
	)
);

if ( ! is_wp_error( $result ) ) {
	throw new RuntimeException( 'Expected the unauthenticated core POST route to reject the transport probe.' );
}

$error_data = $result->get_error_data();
if ( is_array( $error_data ) && 414 === (int) ( $error_data['status'] ?? 0 ) ) {
	throw new RuntimeException( 'Large JSON POST transport probe received HTTP 414.' );
}

fwrite( STDOUT, "FrontendPathResolverTransportProbe passed.\n" );
