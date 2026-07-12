<?php
/**
 * ExtraChill Network Admin Menu
 *
 * Top-level network admin menu for ExtraChill Platform settings.
 *
 * @package ExtraChill\Network
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ) {
	define( 'EXTRACHILL_NETWORK_MENU_SLUG', 'extrachill-network' );
}

// Back-compat: consumer plugins (extrachill-seo, extrachill-users) register
// their submenu pages against the pre-rename constant. Keep it defined and
// pointing at the SAME slug so their `if ( ! defined( ... ) ) return;` guards
// keep passing and their pages keep landing under this menu. Remove once all
// consumers reference EXTRACHILL_NETWORK_MENU_SLUG.
if ( ! defined( 'EXTRACHILL_MULTISITE_MENU_SLUG' ) ) {
	define( 'EXTRACHILL_MULTISITE_MENU_SLUG', EXTRACHILL_NETWORK_MENU_SLUG );
}

add_action( 'network_admin_menu', 'ec_add_network_menu', 5 );

function ec_add_network_menu() {
	add_menu_page(
		'Extra Chill Network',
		'Extra Chill Network',
		'manage_network_options',
		EXTRACHILL_NETWORK_MENU_SLUG,
		null,
		'dashicons-admin-multisite',
		3
	);
}
