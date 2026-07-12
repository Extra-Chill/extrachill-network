<?php
/**
 * QR code generation ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and executes the network-owned QR code primitive.
 */
class QRCodeAbility {

	private const MAX_URL_LENGTH = 2048;

	/**
	 * Stage registration after the retiring Admin Tools owner.
	 */
	public function __construct() {
		// Admin Tools registers at the default priority during the rollout overlap.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register the ability unless the retiring owner already provided it.
	 */
	public function register(): void {
		if ( wp_has_ability( 'extrachill/generate-qr-code' ) ) {
			return;
		}

		wp_register_ability(
			'extrachill/generate-qr-code',
			array(
				'label'               => __( 'Generate QR Code', 'extrachill-network' ),
				'description'         => __( 'Generate a print-ready QR code PNG for a URL.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'  => array(
							'type'        => 'string',
							'format'      => 'uri',
							'maxLength'   => self::MAX_URL_LENGTH,
							'description' => __( 'URL to encode in the QR code.', 'extrachill-network' ),
						),
						'size' => array(
							'type'        => 'integer',
							'description' => __( 'QR code size in pixels (default: 1000, clamped to 100-2000).', 'extrachill-network' ),
						),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'QR code image data (base64 PNG).', 'extrachill-network' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Preserve Admin Tools' automation and network-admin access contract.
	 */
	public function check_permission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
			return true;
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * Generate a base64-encoded PNG using bounded dimensions.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! class_exists( '\\Endroid\\QrCode\\QrCode' ) ) {
			return new \WP_Error( 'dependency_missing', 'Endroid QR Code library not available.' );
		}

		if ( strlen( $input['url'] ) > self::MAX_URL_LENGTH ) {
			return new \WP_Error( 'url_too_long', 'URL cannot exceed 2048 characters.' );
		}

		$size = isset( $input['size'] ) ? absint( $input['size'] ) : 1000;
		$size = max( 100, min( 2000, $size ) );

		$qr_code = new \Endroid\QrCode\QrCode(
			data: $input['url'],
			encoding: new \Endroid\QrCode\Encoding\Encoding( 'UTF-8' ),
			errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
			size: $size,
			margin: 40
		);

		$result = ( new \Endroid\QrCode\Writer\PngWriter() )->write( $qr_code );

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- preserved binary image response contract.
			'image'     => base64_encode( $result->getString() ),
			'mime_type' => $result->getMimeType(),
			'url'       => $input['url'],
			'size'      => $size,
		);
	}
}
