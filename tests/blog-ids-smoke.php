<?php

define( 'ABSPATH', __DIR__ . '/' );

function get_site_option( $key, $default = false ) {
	return $default;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['ec_blog_ids_test_filters'][ $hook ] = $callback;
}

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['ec_blog_ids_test_filters'][ $hook ] ) ) {
		return $GLOBALS['ec_blog_ids_test_filters'][ $hook ]( $value, ...$args );
	}
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require_once dirname( __DIR__ ) . '/inc/core/blog-ids.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
};

$assert( 13 === ec_get_blog_id( 'link_pages' ), 'Link Pages must resolve to its dedicated blog.' );
$assert( 13 === ec_get_domain_map()['extrachill.link'], 'The apex Link Pages domain must resolve to its dedicated blog.' );
$assert( 13 === apply_filters( 'ec_link_page_storage_blog_id', 0 ), 'The standalone runtime must use the dedicated storage blog.' );
$assert( 'https://extrachill.link' === ec_get_site_url( 'link_pages' ), 'The canonical Link Pages site URL must use the apex domain.' );

$assert( array( 'extrachill.link' => 4, 'www.extrachill.link' => 4 ) === ec_get_domain_overrides(), 'Domain overrides must mirror sunrise.php routing until network#174.' );
$assert( 4 === ec_get_shadowing_blog_id( 'link_pages' ), 'The Link Pages host must report the blog that live routing serves.' );
$assert( null === ec_get_shadowing_blog_id( 'artist' ), 'A site routed to its own blog must not report a shadowing blog.' );
$assert( null === ec_get_shadowing_blog_id( 'main' ), 'Unrelated sites must not report a shadowing blog.' );
$assert( null === ec_get_shadowing_blog_id( 'unknown-key' ), 'Unknown site keys must not report a shadowing blog.' );

$GLOBALS['ec_blog_ids_test_filters']['ec_domain_overrides'] = static function ( $overrides ) {
	unset( $overrides );
	return array(
		'extrachill.link'     => 13,
		'wire.extrachill.com' => 4,
	);
};
$assert( null === ec_get_shadowing_blog_id( 'link_pages' ), 'An override back to the registry blog is not shadowing.' );
$assert( 4 === ec_get_shadowing_blog_id( 'wire' ), 'Overrides are filterable for non-production routing.' );
unset( $GLOBALS['ec_blog_ids_test_filters']['ec_domain_overrides'] );
$assert( 4 === ec_get_shadowing_blog_id( 'link_pages' ), 'Overrides return to the declared sunrise mirror after filtering.' );

fwrite( STDOUT, "Blog ID map checks passed.\n" );
