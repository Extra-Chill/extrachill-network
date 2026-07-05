<?php
/**
 * Plugin Name: Extra Chill Multisite
 * Plugin URI: https://extrachill.com
 * Description: Network administration foundation for the ExtraChill Platform. Provides network-wide Cloudflare Turnstile integration and consolidated network admin menu.
 * Version: 1.22.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: extrachill-multisite
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_MULTISITE_VERSION', '1.22.0' );
define( 'EXTRACHILL_MULTISITE_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_MULTISITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_MULTISITE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( EXTRACHILL_MULTISITE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'vendor/autoload.php';
}

// Breeze role-cookie hardening (extrachill-users#161). Loaded at top level —
// NOT inside extrachill_multisite_init() — because it registers a
// `plugins_loaded` @ 1 self-heal that must be hooked before the
// `do_action( 'plugins_loaded' )` this plugin's own init callback runs on.
require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/cache/breeze-role-cookie.php';

register_activation_hook( __FILE__, 'extrachill_multisite_activate' );

function extrachill_multisite_activate() {
	if ( is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );

	// Only wp_die() in interactive admin contexts. Non-interactive callers
	// (WP-CLI, WordPress Playground bootstrap, automated test runners) need
	// the plugin file to load without terminating the PHP process — the
	// runtime guards inside extrachill_multisite_init() prevent any actual
	// multisite-only behavior from firing on single-site installs.
	$is_interactive_admin = is_admin()
		&& ! ( defined( 'WP_CLI' ) && WP_CLI )
		&& ! wp_doing_ajax();

	if ( $is_interactive_admin ) {
		wp_die( 'Extra Chill Multisite plugin requires a WordPress multisite installation.' );
	}
}

add_action( 'plugins_loaded', 'extrachill_multisite_init' );

function extrachill_multisite_init() {
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/blog-ids.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/mail.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/cross-site-rest.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/cross-site-content-migration.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/extrachill-turnstile.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/oauth-helpers.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/object-cache-config.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/legacy-path-redirects.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/core/new-site-setup.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/cross-site-links/cross-site-links.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/cross-site-links/network-bridge.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/footer-main-menu.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/network-dropdown.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/site-title.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/admin-menu.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/404-content.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/dns-prefetch.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/emoji-deprecation.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/filter-bar.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/footer-links.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/theme/social-links.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/community-activity/community-activity.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/community-activity/sidebar-widget.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/assets.php';

	// NetworkStats — composable cross-site metric-provider registry. The
	// engine, interface, core providers, and the `extrachill_network_stat_providers`
	// filter load here; the get-network-stats ability registers itself on
	// `wp_abilities_api_init` (guarded inside bootstrap). The thin
	// ec_get_network_stats() helper loads unconditionally for template callers.
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/NetworkStats/bootstrap.php';
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/NetworkStats/helpers.php';

	// Abilities API.
	if ( function_exists( 'wp_register_ability' ) ) {
		// Single owner for the `extrachill-multisite` ability category — load
		// before any class that consumes it so the category exists when their
		// `wp_register_ability()` calls reference it.
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/CategoryRegistration.php';

		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/TaxonomyCountAbilities.php';
		new \ExtraChillMultisite\Abilities\TaxonomyCountAbilities();

		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/NetworkMediaAbilities.php';
		new \ExtraChillMultisite\Abilities\NetworkMediaAbilities();

		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/MailAbilities.php';
		new \ExtraChillMultisite\Abilities\MailAbilities();

		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/CrossSiteContentMigrationAbilities.php';
		new \ExtraChillMultisite\Abilities\CrossSiteContentMigrationAbilities();

		// Shared editor utilities — load-envelope shape, permissions block,
		// blog_id resolution. Consumed by every editor ability across the
		// network (this plugin + extrachill-community + future content types).
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Editor/BlogResolver.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Editor/Permissions.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Editor/LoadEnvelope.php';

		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/Abilities/CommentEditorAbilities.php';
		new \ExtraChillMultisite\Abilities\CommentEditorAbilities();
	}

	// Badge count cache warmer.
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/cache/badge-count-warmer.php';

	// OG card generation (Data Machine integration).
	// Loads only when DM is active so the plugin still functions standalone.
	if ( defined( 'DATAMACHINE_VERSION' ) ) {
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/og-cards/og-cards.php';
	}

	// Commerce auth providers (Stripe + Shippo). These MUST live at the network
	// layer so their classes load in network-admin (where the Network Admin >
	// Payments save handler runs) as well as on blog 3 (where the shop reads
	// them). The bootstrap self-guards on Data Machine's BaseAuthProvider. See #92.
	require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'inc/commerce/auth/bootstrap.php';

	if ( is_admin() && is_network_admin() ) {
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'admin/network-menu.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'admin/network-security-settings.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'admin/network-payments-settings.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'admin/network-oauth-settings.php';
		require_once EXTRACHILL_MULTISITE_PLUGIN_DIR . 'admin/network-shipping-settings.php';
	}
}
