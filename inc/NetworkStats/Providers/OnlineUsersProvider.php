<?php
/**
 * Online-users metric provider.
 *
 * WRAPS the existing ec_get_online_users_count() (extrachill-users), which
 * owns its own `online_users_count` transient (5 min) on the community blog.
 * This provider does NOT recount — it delegates so the NetworkStats value is
 * always identical to the footer widget's "Online Now" number.
 *
 * @package ExtraChillMultisite\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillMultisite\NetworkStats\Providers;

use ExtraChillMultisite\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Network-wide "online now" count.
 */
class OnlineUsersProvider extends AbstractMetricProvider {

	/**
	 * Construct the online-users provider.
	 */
	public function __construct() {
		parent::__construct( 'online_users', __( 'Online Now', 'extrachill-multisite' ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		if ( ! function_exists( 'ec_get_online_users_count' ) ) {
			return null;
		}

		return (int) ec_get_online_users_count();
	}
}
