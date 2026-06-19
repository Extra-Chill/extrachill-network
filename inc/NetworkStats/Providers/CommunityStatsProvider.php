<?php
/**
 * Community-stats metric providers.
 *
 * DELEGATES to the existing `extrachill/community-get-stats` ability
 * (extrachill-community) — it does NOT re-query bbPress. That ability owns the
 * authoritative forums/topics/replies/users/upvotes counts.
 *
 * Resolution strategy (two paths, in order):
 *  1. Local fast path — if the ability is registered in THIS process (i.e. the
 *     landing page is composed on the community blog, or a warmer runs there),
 *     execute it directly. No HTTP, no switch.
 *  2. Cross-site HTTP loopback — extrachill-community is a PER-SITE plugin
 *     (active only on community.extrachill.com), so its ability callback is not
 *     loaded in the PHP process of any other site, and switch_to_blog() does
 *     NOT load it. The only transport that reaches it from another site is an
 *     HTTP loopback that bootstraps the community site's full plugin stack.
 *     We dispatch to the core Abilities REST run endpoint via
 *     ec_cross_site_rest_request('community', ...).
 *
 * Honesty: if neither path resolves (loopback fails, ability returns an error),
 * value() returns null — the engine marks the metric "not available" rather
 * than fabricating a zero.
 *
 * NOTE (companion follow-up): the cross-site loopback path requires
 * `extrachill/community-get-stats` to be exposed over REST
 * (`show_in_rest => true`); it is currently `false`, so the core run route
 * 404s until that one-line change lands in extrachill-community. Until then the
 * local fast path still works on the community blog, and off-blog callers get
 * an honest null. Tracked separately; not in this PR's repo.
 *
 * The community stats payload is fetched once and shared between the two
 * community providers within a request via a static cache, so requesting both
 * `community_members` and `community_topics` triggers a single delegation.
 *
 * @package ExtraChillMultisite\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillMultisite\NetworkStats\Providers;

use ExtraChillMultisite\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Base for providers that read a field from the community-get-stats payload.
 */
abstract class CommunityStatsProvider extends AbstractMetricProvider {

	/**
	 * Per-request memo of the community stats payload.
	 *
	 * Null means not yet fetched, false means fetched-but-unavailable
	 * (delegation failed), and an array is the resolved stats payload.
	 *
	 * @var array|false|null
	 */
	private static $payload = null;

	/**
	 * Field within the community-get-stats payload this provider exposes.
	 *
	 * @return string
	 */
	abstract protected function field(): string;

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		$stats = self::community_stats();
		if ( ! is_array( $stats ) ) {
			return null;
		}

		$field = $this->field();

		return isset( $stats[ $field ] ) ? (int) $stats[ $field ] : null;
	}

	/**
	 * Resolve the community stats payload (memoized per request).
	 *
	 * @return array|false Stats payload, or false when unavailable.
	 */
	protected static function community_stats() {
		if ( null !== self::$payload ) {
			return self::$payload;
		}

		// Path 1: local ability (community-blog origin / warmer).
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( 'extrachill/community-get-stats' );
			if ( $ability ) {
				$result = $ability->execute( array() );
				if ( is_array( $result ) ) {
					self::$payload = $result;
					return self::$payload;
				}
			}
		}

		// Path 2: cross-site HTTP loopback to the community site, which
		// bootstraps extrachill-community and registers the ability + run route.
		// Force the loopback transport: the ability callback is only defined
		// inside the community site's process, so the default in-process
		// switch_to_blog dispatch cannot satisfy it.
		if ( function_exists( 'ec_cross_site_rest_request' ) ) {
			$force_http = static function ( $use_http, $site_key ) {
				return 'community' === $site_key ? true : $use_http;
			};

			add_filter( 'ec_cross_site_use_http_loopback', $force_http, 10, 2 );
			try {
				$response = ec_cross_site_rest_request(
					'community',
					'POST',
					'/wp-abilities/v1/abilities/extrachill/community-get-stats/run',
					array(
						'body' => array( 'input' => array() ),
					)
				);
			} finally {
				remove_filter( 'ec_cross_site_use_http_loopback', $force_http, 10 );
			}

			if ( ! is_wp_error( $response ) && is_array( $response ) ) {
				self::$payload = $response;
				return self::$payload;
			}
		}

		self::$payload = false;
		return self::$payload;
	}

	/**
	 * Reset the per-request memo (tests).
	 */
	public static function reset_payload(): void {
		self::$payload = null;
	}
}
