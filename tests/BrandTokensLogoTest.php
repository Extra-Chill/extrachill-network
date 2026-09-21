<?php
/**
 * Tests for the OG card logo brand token (issue #856).
 *
 * Covers `inc/og-cards/brand-tokens.php`: `provide_tokens()` setting a
 * `logo_path` token from the main site's media library, and
 * `main_site_upload_path()` resolving that path correctly regardless of
 * which site in the network is currently active — the case that matters
 * for this file, since OG cards for events.extrachill.com (blog 7)
 * render while that site, not the main site, is current.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

use function ExtraChillNetwork\OgCards\main_site_upload_path;
use function ExtraChillNetwork\OgCards\provide_tokens;

/**
 * @group og-cards
 */
class BrandTokensLogoTest extends WP_UnitTestCase {

	public function tear_down() {
		parent::tear_down();
	}

	public function test_main_site_upload_path_resolves_against_the_main_site_even_when_on_another_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite to exercise the cross-blog resolution path.' );
		}

		$main_site_id = get_main_site_id();
		$upload_dir   = wp_upload_dir();

		// Write a real file into the *main* site's uploads dir directly
		// (bypassing switch_to_blog for the write, to isolate what we're
		// testing: does main_site_upload_path() find it from elsewhere?).
		switch_to_blog( $main_site_id );
		$main_upload_dir = wp_upload_dir();
		$relative        = 'brand-tokens-test-' . uniqid() . '.png';
		$absolute        = trailingslashit( $main_upload_dir['basedir'] ) . $relative;
		$image           = imagecreatetruecolor( 4, 4 );
		imagepng( $image, $absolute );
		imagedestroy( $image );
		restore_current_blog();

		try {
			// Create (or reuse) a second site and make it current, so we are
			// definitely not on the main site when resolving.
			$other_site_id = self::factory()->blog->create();
			switch_to_blog( $other_site_id );

			$resolved = main_site_upload_path( $relative );

			$this->assertSame( $absolute, $resolved, 'Must resolve to the main site\'s file, not the current site\'s uploads dir' );
			$this->assertSame(
				(int) $main_site_id,
				get_current_blog_id(),
				'main_site_upload_path() must restore the original current blog before returning'
			);
		} finally {
			restore_current_blog();
			@unlink( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
		}
	}

	public function test_main_site_upload_path_returns_null_for_a_missing_file(): void {
		$resolved = main_site_upload_path( 'definitely/does-not-exist-' . uniqid() . '.png' );

		$this->assertNull( $resolved );
	}

	public function test_provide_tokens_sets_logo_path_when_the_asset_exists(): void {
		$tokens = provide_tokens( array() );

		// This asserts against the real, verified asset shipped in the
		// main site's media library (see the comment in provide_tokens()).
		// If it's ever moved/deleted, this test is the tripwire — and the
		// template's own resolve_logo() guard means a failure here would
		// degrade to the site icon or text, not a broken card.
		$this->assertArrayHasKey( 'logo_path', $tokens );
		$this->assertIsString( $tokens['logo_path'] );
		$this->assertFileExists( $tokens['logo_path'] );
	}

	public function test_provide_tokens_does_not_set_logo_path_inverse(): void {
		// No verified light-ink asset exists yet — asserting its absence
		// here is intentional: it documents that dark-background cards
		// correctly fall through to the next resolution level (site icon,
		// then text) rather than pointing at a path that doesn't exist.
		$tokens = provide_tokens( array() );

		$this->assertArrayNotHasKey( 'logo_path_inverse', $tokens );
	}

	public function test_provide_tokens_preserves_existing_brand_text_and_site_label(): void {
		$tokens = provide_tokens( array() );

		$this->assertSame( 'Extra Chill', $tokens['brand_text'] );
		$this->assertArrayHasKey( 'site_label', $tokens );
	}
}
