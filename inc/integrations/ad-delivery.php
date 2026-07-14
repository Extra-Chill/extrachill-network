<?php
/**
 * Production ad-delivery integration health evidence.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report whether the configured delivery plugin is active for a site.
 *
 * This adapter is intentionally separate from the vendor-neutral policy.
 * Plugin state diagnoses operational drift; it never determines site intent.
 *
 * @param array<string, bool> $health  Existing health evidence.
 * @param int                 $blog_id Site ID.
 * @return array<string, bool>
 */
function extrachill_mediavine_ad_integration_health( array $health, int $blog_id ): array {
	$plugin_file     = 'mediavine-control-panel/mediavine-control-panel.php';
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	$site_plugins    = (array) get_blog_option( $blog_id, 'active_plugins', array() );
	$is_active       = isset( $network_plugins[ $plugin_file ] ) || in_array( $plugin_file, $site_plugins, true );

	$health['available']         = $is_active;
	$health['delivery_detected'] = $is_active;

	return $health;
}
add_filter( 'extrachill_ad_integration_health', 'extrachill_mediavine_ad_integration_health', 10, 2 );
