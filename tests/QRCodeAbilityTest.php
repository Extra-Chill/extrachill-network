<?php
/**
 * Tests for the network-owned QR code ability.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

use ExtraChillNetwork\Abilities\QRCodeAbility;

/**
 * Validate QR generation compatibility and resource bounds.
 *
 * @group qr-code
 */
class QRCodeAbilityTest extends WP_UnitTestCase {

	/**
	 * Ability under test.
	 *
	 * @var QRCodeAbility
	 */
	private QRCodeAbility $ability;

	/**
	 * Create a fresh ability handler for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->ability = new QRCodeAbility();
	}

	/**
	 * The API and CLI consumers receive the unchanged response keys.
	 */
	public function test_generate_preserves_response_contract(): void {
		$result = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com/test',
				'size' => 300,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'https://extrachill.com/test', $result['url'] );
		$this->assertSame( 300, $result['size'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- verifies the binary response contract.
		$this->assertNotFalse( base64_decode( $result['image'], true ) );
	}

	/**
	 * Pixel dimensions remain bounded without response drift.
	 */
	public function test_generate_clamps_requested_dimensions(): void {
		$small = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com',
				'size' => 1,
			)
		);
		$large = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com',
				'size' => 5000,
			)
		);

		$this->assertSame( 100, $small['size'] );
		$this->assertSame( 2000, $large['size'] );
	}

	/**
	 * Oversized input is rejected before QR matrix allocation.
	 */
	public function test_generate_rejects_oversized_url_payload(): void {
		$result = $this->ability->execute(
			array( 'url' => 'https://extrachill.com/?value=' . str_repeat( 'a', 2048 ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'url_too_long', $result->get_error_code() );
	}

	/**
	 * REST exposure remains restricted to network managers.
	 */
	public function test_permission_requires_network_management_capability(): void {
		$this->assertFalse( $this->ability->check_permission() );

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $administrator );
		wp_set_current_user( $administrator );

		$this->assertTrue( $this->ability->check_permission() );
	}
}
