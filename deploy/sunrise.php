<?php
/**
 * extrachill.link Domain Mapping (Link Pages site-cutover aware)
 *
 * Canonical source: the Extra-Chill/.github repository. WordPress includes
 * wp-content/sunrise.php when wp-config.php defines SUNRISE; this file is the
 * drop-in template shipped by extrachill-network for the Link Pages site
 * cutover (network issue #174). Deploy by copying it to the canonical source
 * and then to wp-content/sunrise.php on the live install.
 *
 * Behavior is controlled by the network option `ec_link_pages_site_cutover`
 * (see extrachill-network inc/core/blog-ids.php):
 *
 * - Gate off (default): behaviorally identical to the pre-cutover drop-in —
 *   extrachill.link and www.extrachill.link map to artist.extrachill.com
 *   (blog 4) and the artist_link_page rewrite rule is injected.
 * - Gate on: www.extrachill.link 301s to the apex; extrachill.link serves the
 *   dedicated Link Pages site (blog 13) with no artist rewrite injection —
 *   the standalone Link Pages runtime owns routing on that site.
 *
 * The gate is read with a direct wp_sitemeta query because sunrise.php runs
 * before option.php loads, so get_site_option() is not available yet. It is
 * one indexed primary-key lookup per extrachill.link request; the option only
 * changes during the explicit operator cutover/rollback.
 */

// Only run if this is a multisite install
if ( ! defined( 'MULTISITE' ) || ! MULTISITE ) {
	return;
}

if ( ! defined( 'EC_LINK_PAGES_SITE_BLOG_ID' ) ) {
	define( 'EC_LINK_PAGES_SITE_BLOG_ID', 13 );
}
if ( ! defined( 'EC_ARTIST_SITE_BLOG_ID' ) ) {
	define( 'EC_ARTIST_SITE_BLOG_ID', 4 );
}

$ec_cutover_enabled = 0;
if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- sunrise.php runs before option.php and the object-cache warmup; a direct indexed lookup is the only way to read a network option this early.
	$ec_cutover_enabled = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT meta_value FROM ' . $wpdb->sitemeta . ' WHERE site_id = %d AND meta_key = %s',
			defined( 'NETWORK_ID' ) ? NETWORK_ID : 1,
			'ec_link_pages_site_cutover'
		)
	);
}

$ec_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against literal hosts; WordPress sanitization helpers are not loaded at sunrise time.

if ( $ec_cutover_enabled ) {
	// Gate on: canonicalize www to the apex.
	if ( 'www.extrachill.link' === $ec_host ) {
		$ec_request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- forwarded verbatim as a URL path; forced below to start with '/' so no external origin can be injected.
		if ( '' === $ec_request_uri || '/' !== $ec_request_uri[0] ) {
			$ec_request_uri = '/';
		}
		header( 'Location: https://extrachill.link' . $ec_request_uri, true, 301 );
		exit;
	}

	// Gate on: the apex serves the dedicated Link Pages site. No artist
	// rewrite injection — the standalone Link Pages runtime owns routing on
	// blog 13. If the destination site is missing, fall through to the legacy
	// mapping instead of serving a broken site.
	if ( 'extrachill.link' === $ec_host ) {
		$ec_link_pages_site = get_site( EC_LINK_PAGES_SITE_BLOG_ID );
		if ( $ec_link_pages_site && empty( $ec_link_pages_site->deleted ) && empty( $ec_link_pages_site->archived ) && empty( $ec_link_pages_site->spam ) ) {
			// Force WordPress to use the correct blog ID
			global $blog_id;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
			$blog_id = EC_LINK_PAGES_SITE_BLOG_ID;

			// Set the current blog globals
			global $current_site, $current_blog;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current site before WordPress loads.
			$current_site = get_network( 1 );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
			$current_blog = $ec_link_pages_site;
			return;
		}
	}
}

// Legacy mapping: cutover gated off (or gate on with the destination site
// unavailable) — behaviorally identical to the pre-cutover drop-in: both
// extrachill.link hosts map to the artist blog and the artist rewrite rule
// is injected.
$extra_domains = array(
	'extrachill.link'     => EC_ARTIST_SITE_BLOG_ID,
	'www.extrachill.link' => EC_ARTIST_SITE_BLOG_ID,
);

if ( array_key_exists( $ec_host, $extra_domains ) ) {
	$mapped_blog_id = $extra_domains[ $ec_host ];

	// Force WordPress to use the correct blog ID
	global $blog_id;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
	$blog_id = $mapped_blog_id;

	// Set the current blog globals
	global $current_site, $current_blog;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current site before WordPress loads.
	$current_site = get_network( 1 );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
	$current_blog = get_site( $mapped_blog_id );

	// Add the artist link page rewrite rule directly to WordPress
	// This runs at priority 0 to ensure it's added before other plugins
	add_filter( 'rewrite_rules_array', function ( $rules ) {
		// Only add this rule for extrachill.link domain
		if ( isset( $_SERVER['HTTP_HOST'] ) && stripos( $_SERVER['HTTP_HOST'], 'extrachill.link' ) !== false ) {

			// Excluded slugs
			$excluded = 'wp-admin|wp-login|wp-json|artists?|link-page|manage-artist|manage-link-page|join';

			// Add the main rule at the top
			$new_rules = array(
				'^(' . $excluded . ')/?$' => 'index.php?$1',
				'^([^/]+)/?$'             => 'index.php?artist_link_page=$matches[1]',
			);

			return array_merge( $new_rules, $rules );
		}
		return $rules;
	}, 0 );
}
