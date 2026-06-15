<?php
/**
 * Single owner for the `extrachill-multisite` ability category.
 *
 * Every `*Abilities.php` class in this plugin uses
 * `'category' => 'extrachill-multisite'` on its `wp_register_ability()`
 * calls. The category itself must be registered exactly once, otherwise
 * the second registrar trips `_doing_it_wrong`.
 *
 * Historically each class tried to own its own category registration,
 * which started colliding the moment a second class was added. This file
 * is the single point of truth — every class consumes the category by id.
 *
 * @package ExtraChillMultisite\Abilities
 * @since 1.14.1
 */

namespace ExtraChillMultisite\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Register the `extrachill-multisite` ability category.
 *
 * Hooked from the plugin bootstrap.
 */
function register_extrachill_multisite_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-multisite' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-multisite',
		array(
			'label'       => __( 'Extra Chill Multisite', 'extrachill-multisite' ),
			'description' => __( 'Network-wide cross-site operations', 'extrachill-multisite' ),
		)
	);
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_extrachill_multisite_category' );
