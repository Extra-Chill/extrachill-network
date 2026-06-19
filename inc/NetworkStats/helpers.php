<?php
/**
 * NetworkStats global helper.
 *
 * Thin procedural wrapper around the NetworkStats engine for template/theme
 * callers that prefer a function to a class call.
 *
 * @package ExtraChillMultisite\NetworkStats
 * @since   1.19.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ec_get_network_stats' ) ) {
	/**
	 * Get cross-site network statistics.
	 *
	 * Each metric is individually cached. Unavailable sources are reported as
	 * `available => false` with `value => null` — never a fabricated zero.
	 *
	 * @param string[] $keys Metric keys to resolve (e.g.
	 *                       ['events_count','artist_profiles']). Empty = all.
	 * @return array<string,array{key:string,label:string,value:int|array|null,available:bool}>
	 */
	function ec_get_network_stats( array $keys = array() ): array {
		return \ExtraChillMultisite\NetworkStats\NetworkStats::get( $keys );
	}
}
