<?php
/**
 * Extra Chill answers for the owner-neutral Link Pages runtime.
 *
 * extrachill-link-pages ships no Extra Chill specifics: the title suffix,
 * footer credit, click-tracking endpoint and network tag manager come from
 * the host site through filters and actions. This file is that host
 * integration for extrachill.link, so pages render the same with or
 * without an owner plugin loaded (extrachill-link-pages#36).
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

defined( 'EC_LINK_PAGES_GTM_CONTAINER_ID' ) || define( 'EC_LINK_PAGES_GTM_CONTAINER_ID', 'GTM-NXKDLFD' );

/**
 * Suffix every Link Page title with the public host.
 *
 * @return string
 */
function ec_network_link_page_title_suffix() {
	return ' | extrachill.link';
}
add_filter( 'ec_link_page_title_suffix', 'ec_network_link_page_title_suffix' );

/**
 * Footer credit shown on every Link Page.
 *
 * @return string
 */
function ec_network_link_page_footer_html() {
	return '<a href="https://extrachill.com/power/?utm_source=linkpage&amp;utm_medium=footer&amp;utm_campaign=power" rel="noopener">Powered by Extra Chill</a>';
}
add_filter( 'ec_link_page_footer_html', 'ec_network_link_page_footer_html' );

/**
 * Network click-tracking endpoint (extrachill-api analytics).
 *
 * Same URL the artist owner projection supplied, so behaviour is unchanged
 * for artist pages and every other owner gets tracking for free.
 *
 * @param string $url Existing endpoint.
 * @return string
 */
function ec_network_link_page_click_tracking_url( $url ) {
	if ( '' !== (string) $url ) {
		return $url;
	}
	// Post the beacon to the site that serves the page. Telemetry
	// validation only accepts a source host belonging to the site that
	// handles the request, so after the cutover extrachill.link pages must
	// post to extrachill.link itself (its own REST API), not the artist site.
	if ( function_exists( 'ec_link_pages_site_cutover_enabled' ) && ec_link_pages_site_cutover_enabled() ) {
		return 'https://extrachill.link/wp-json/extrachill/v1/analytics/click';
	}
	$base = ec_get_site_url( 'artist' );
	return $base ? $base . '/wp-json/extrachill/v1/analytics/click' : '';
}
add_filter( 'ec_link_page_click_tracking_url', 'ec_network_link_page_click_tracking_url' );

/** Network Google Tag Manager container, head snippet. */
function ec_network_link_page_gtm_head() {
	$id = (string) EC_LINK_PAGES_GTM_CONTAINER_ID;
	if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $id ) ) {
		return;
	}
	// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Link Pages render an isolated minimal head.
	echo "<!-- Google Tag Manager --><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $id ) . "');</script><!-- End Google Tag Manager -->";
}
add_action( 'ec_link_page_public_head', 'ec_network_link_page_gtm_head' );

/** Network Google Tag Manager container, no-script fallback. */
function ec_network_link_page_gtm_body() {
	$id = (string) EC_LINK_PAGES_GTM_CONTAINER_ID;
	if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $id ) ) {
		return;
	}
	echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
}
add_action( 'ec_link_page_public_body_open', 'ec_network_link_page_gtm_body' );

/**
 * Resolve the artist profile ID behind a Link Page owner reference.
 *
 * @param string $owner_reference Normalized owner reference.
 * @return int Artist profile ID, or 0 when the owner is not an artist.
 */
function ec_network_link_page_artist_owner_id( $owner_reference ) {
	if ( ! function_exists( 'ec_parse_link_page_owner_reference' ) ) {
		return 0;
	}
	$owner = ec_parse_link_page_owner_reference( (string) $owner_reference );
	if ( is_wp_error( $owner ) || 'post' !== $owner['kind'] || 'artist_profile' !== $owner['subtype'] || (int) ec_get_blog_id( 'artist' ) !== (int) $owner['blog_id'] ) {
		return 0;
	}
	return (int) $owner['object_id'];
}

/** Base URL of the network REST API on the artist site. */
function ec_network_link_page_artist_api_base() {
	$base = ec_get_site_url( 'artist' );
	return $base ? $base . '/wp-json/extrachill/v1' : '';
}

/**
 * Artist direct-subscriber endpoint for artist-owned Link Pages.
 *
 * @param string $url             Existing endpoint.
 * @param int    $link_page_id    Link Page ID.
 * @param string $owner_reference Owner reference.
 * @return string
 */
function ec_network_link_page_subscribe_url( $url, $link_page_id, $owner_reference ) {
	unset( $link_page_id );
	if ( '' !== (string) $url ) {
		return $url;
	}
	$artist_id = ec_network_link_page_artist_owner_id( $owner_reference );
	$base      = ec_network_link_page_artist_api_base();
	return $artist_id && $base ? $base . '/artists/' . $artist_id . '/subscribe' : '';
}
add_filter( 'ec_link_page_subscribe_url', 'ec_network_link_page_subscribe_url', 20, 3 );

/**
 * Edit-button endpoints for artist-owned Link Pages.
 *
 * @param array  $endpoints       Existing endpoints.
 * @param int    $link_page_id    Link Page ID.
 * @param string $owner_reference Owner reference.
 * @return array
 */
function ec_network_link_page_management_endpoints( $endpoints, $link_page_id, $owner_reference ) {
	unset( $link_page_id );
	if ( is_array( $endpoints ) && ! empty( $endpoints['permissions_url'] ) ) {
		return $endpoints;
	}
	$artist_id = ec_network_link_page_artist_owner_id( $owner_reference );
	$base      = ec_network_link_page_artist_api_base();
	$site      = ec_get_site_url( 'artist' );
	if ( ! $artist_id || ! $base || ! $site ) {
		return is_array( $endpoints ) ? $endpoints : array();
	}
	return array(
		'permissions_url' => $base . '/artists/' . $artist_id . '/permissions',
		'handoff_url'     => $site . '/wp-admin/admin-post.php?action=ec_link_token_handoff',
	);
}
add_filter( 'ec_link_page_management_endpoints', 'ec_network_link_page_management_endpoints', 20, 3 );

/**
 * Send extrachill.link/join to artist signup.
 *
 * The public Link Pages host serves no signup of its own yet
 * (extrachill-link-pages#27), so /join forwards to the artist site's login
 * with the join context. Lives here rather than in an owner plugin so it
 * works on the dedicated Link Pages site, where owner plugins are not active.
 *
 * @param mixed  $route Existing special route.
 * @param string $path  Requested public path.
 * @return mixed
 */
function ec_network_link_page_join_route( $route, $path ) {
	if ( null !== $route || 'join' !== $path ) {
		return $route;
	}
	$artist = ec_get_site_url( 'artist' );
	if ( ! $artist ) {
		return $route;
	}
	return array(
		'url'    => $artist . '/login/?from_join=true',
		'status' => 301,
		'safe'   => false,
	);
}
add_filter( 'ec_link_page_public_special_route', 'ec_network_link_page_join_route', 20, 2 );

/**
 * Public host for Link Pages: extrachill.link.
 *
 * @return string
 */
function ec_network_link_page_public_host() {
	return 'extrachill.link';
}
add_filter( 'ec_link_page_public_host', 'ec_network_link_page_public_host' );

/**
 * The Extra Chill Link Page is served at the public root.
 *
 * @return string
 */
function ec_network_link_page_root_slug() {
	return 'extra-chill';
}
add_filter( 'ec_link_page_root_slug', 'ec_network_link_page_root_slug' );
