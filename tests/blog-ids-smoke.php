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

fwrite( STDOUT, "Blog ID map checks passed.\n" );
