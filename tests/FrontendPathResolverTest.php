<?php
/**
 * Tests for authoritative network frontend-path resolution.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * Verifies exact, target-booted frontend path resolution.
 */
class FrontendPathResolverTest extends WP_UnitTestCase {

	/**
	 * Target-local responses keyed by host.
	 *
	 * @var array<string,array>
	 */
	private array $responses = array();

	/** Set up isolated loopback responses. */
	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'mock_loopback' ), 10, 3 );
	}

	/** Remove isolated loopback responses. */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_loopback' ), 10 );
		parent::tear_down();
	}

	/**
	 * Return target-local probe responses without making network requests.
	 *
	 * @param false|array|WP_Error $preempt Preempted response.
	 * @param array                $args    HTTP arguments.
	 * @return array
	 */
	public function mock_loopback( $preempt, array $args ): array {
		$host = $args['headers']['Host'] ?? '';
		$body = $this->responses[ $host ] ?? array(
			'status' => 'unresolved',
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a target-local resolved candidate response.
	 *
	 * @param int    $blog_id   Owning blog ID.
	 * @param int    $post_id   Published post ID.
	 * @param string $post_type Published post type.
	 * @param string $url       Canonical post URL.
	 * @return array
	 */
	private function candidate( int $blog_id, int $post_id, string $post_type, string $url ): array {
		return array(
			'status'    => 'resolved',
			'candidate' => array(
				'blog_id'        => $blog_id,
				'post_id'        => $post_id,
				'post_type'      => $post_type,
				'canonical_url'  => $url,
				'canonical_path' => wp_parse_url( $url, PHP_URL_PATH ),
			),
		);
	}

	/** Main-site content resolves after query and fragment normalization. */
	public function test_resolves_main_site_content_and_normalizes_query_and_fragment(): void {
		$this->responses['extrachill.com'] = $this->candidate( 1, 101, 'post', 'https://extrachill.com/ordinary-post/' );

		$result = ec_resolve_frontend_path( '/ordinary-post?ref=source#section' );

		$this->assertSame( 'resolved', $result['status'] );
		$this->assertSame( '/ordinary-post/', $result['path'] );
		$this->assertSame( 1, $result['candidate']['blog_id'] );
	}

	/** Events content resolves from its authoritative target site. */
	public function test_resolves_events_path(): void {
		$this->responses['events.extrachill.com'] = $this->candidate( 7, 202, 'data_machine_events', 'https://events.extrachill.com/events/sample-event/' );

		$result = ec_resolve_frontend_path( '/events/sample-event/' );

		$this->assertSame( 'resolved', $result['status'] );
		$this->assertSame( 7, $result['candidate']['blog_id'] );
		$this->assertSame( 'data_machine_events', $result['candidate']['post_type'] );
	}

	/** Festival Wire content resolves from its authoritative target site. */
	public function test_resolves_festival_wire_path(): void {
		$this->responses['wire.extrachill.com'] = $this->candidate( 11, 303, 'festival_wire', 'https://wire.extrachill.com/festival-wire/sample-story/' );

		$result = ec_resolve_frontend_path( '/festival-wire/sample-story' );

		$this->assertSame( 'resolved', $result['status'] );
		$this->assertSame( 11, $result['candidate']['blog_id'] );
		$this->assertSame( 'festival_wire', $result['candidate']['post_type'] );
	}

	/** Missing, absolute, and canonical-mismatched paths remain unresolved. */
	public function test_returns_unresolved_for_missing_or_non_canonical_path(): void {
		$this->responses['events.extrachill.com'] = $this->candidate( 7, 202, 'data_machine_events', 'https://events.extrachill.com/events/canonical/' );

		$this->assertSame( 'unresolved', ec_resolve_frontend_path( '/missing/' )['status'] );
		$this->assertSame( 'unresolved', ec_resolve_frontend_path( '/events/not-canonical/' )['status'] );
		$this->assertSame( 'unresolved', ec_resolve_frontend_path( 'https://events.extrachill.com/events/canonical/' )['status'] );
	}

	/** Collisions return all candidates rather than choosing a first match. */
	public function test_returns_ambiguity_instead_of_first_match(): void {
		$this->responses['extrachill.com']      = $this->candidate( 1, 101, 'post', 'https://extrachill.com/shared/' );
		$this->responses['wire.extrachill.com'] = $this->candidate( 11, 303, 'festival_wire', 'https://wire.extrachill.com/shared/' );

		$result = ec_resolve_frontend_path( '/shared/' );

		$this->assertSame( 'ambiguous', $result['status'] );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertSame( 1, $result['candidates'][0]['blog_id'] );
		$this->assertSame( 11, $result['candidates'][1]['blog_id'] );
	}

	/** The target probe excludes drafts and refuses non-canonical paths. */
	public function test_target_probe_rejects_drafts_and_canonical_mismatches(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$request  = new WP_REST_Request( 'GET', '/extrachill-network/v1/frontend-path-resolution' );
		$request->set_param( 'path', '/draft-path/' );
		$draft_filter = static fn(): string => home_url( '?p=' . $draft_id );
		add_filter( 'url_to_postid', $draft_filter );

		$this->assertSame( 'unresolved', ec_frontend_path_resolver_rest_callback( $request )->get_data()['status'] );
		remove_filter( 'url_to_postid', $draft_filter );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$request->set_param( 'path', '/not-the-canonical-path/' );
		$post_filter = static fn(): string => home_url( '?p=' . $post_id );
		add_filter( 'url_to_postid', $post_filter );
		$this->assertSame( 'unresolved', ec_frontend_path_resolver_rest_callback( $request )->get_data()['status'] );
		remove_filter( 'url_to_postid', $post_filter );
	}
}
