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
