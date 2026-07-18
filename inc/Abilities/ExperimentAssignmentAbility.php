<?php
/**
 * Experiment assignment ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes cache-safe deterministic assignment through the core Abilities API.
 */
class ExperimentAssignmentAbility {

	/**
	 * Register on Abilities API initialization.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the public read-only assignment ability.
	 */
	public function register(): void {
		wp_register_ability(
			'extrachill/resolve-experiment-assignment',
			array(
				'label'               => __( 'Resolve Experiment Assignment', 'extrachill-network' ),
				'description'         => __( 'Resolve a deterministic variant for an eligible, privacy-permitted experiment subject.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'surface' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key' => array(
							'type'      => 'string',
							'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
							'maxLength' => 64,
						),
						'surface'        => array(
							'type'      => 'string',
							'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
							'maxLength' => 64,
						),
						'context'        => array(
							'type'                 => 'object',
							'maxProperties'        => 10,
							'additionalProperties' => array(
								'type' => array( 'string', 'integer', 'number', 'boolean' ),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'variant', 'surface', 'measurement_eligible', 'exposure_token' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'       => array( 'type' => 'string' ),
						'variant'              => array( 'type' => 'string' ),
						'surface'              => array( 'type' => 'string' ),
						'measurement_eligible' => array( 'type' => 'boolean' ),
						'exposure_token'       => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/record-experiment-exposure',
			array(
				'label'               => __( 'Record Experiment Exposure', 'extrachill-network' ),
				'description'         => __( 'Validate a signed assignment proof and emit a trusted viewport exposure hook.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'variant', 'surface', 'exposure_token' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key' => $this->identifier_schema(),
						'variant'        => $this->identifier_schema(),
						'surface'        => $this->identifier_schema(),
						'context'        => $this->context_schema(),
						'exposure_token' => array(
							'type'      => 'string',
							'pattern'   => '^\\d{10}\\.[a-f0-9]{64}$',
							'maxLength' => 75,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'accepted' ),
					'additionalProperties' => false,
					'properties'           => array(
						'accepted' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_exposure' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'idempotent' => false,
					),
				),
			)
		);
	}

	/**
	 * Return the bounded identifier schema shared by both abilities.
	 *
	 * @return array<string, mixed>
	 */
	private function identifier_schema(): array {
		return array(
			'type'      => 'string',
			'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
			'maxLength' => 64,
		);
	}

	/**
	 * Return the bounded consumer context schema.
	 *
	 * @return array<string, mixed>
	 */
	private function context_schema(): array {
		return array(
			'type'                 => 'object',
			'maxProperties'        => 10,
			'additionalProperties' => array(
				'type' => array( 'string', 'integer', 'number', 'boolean' ),
			),
		);
	}

	/**
	 * Resolve one assignment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		$context = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();

		return extrachill_resolve_experiment_assignment(
			(string) $input['experiment_key'],
			(string) $input['surface'],
			$context
		);
	}

	/**
	 * Validate and emit one trusted viewport exposure.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{accepted: bool}|\WP_Error
	 */
	public function execute_exposure( array $input ) {
		$context  = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();
		$metadata = extrachill_validate_experiment_exposure(
			(string) $input['experiment_key'],
			(string) $input['variant'],
			(string) $input['surface'],
			$context,
			(string) $input['exposure_token']
		);
		if ( null === $metadata ) {
			return new \WP_Error( 'invalid_experiment_exposure', __( 'Experiment exposure proof is invalid or expired.', 'extrachill-network' ), array( 'status' => 400 ) );
		}

		/**
		 * Fires after a signed viewport exposure is validated server-side.
		 *
		 * Analytics may consume this hook for persistence. Browser DOM events do
		 * not reach this hook without a valid subject/context-bound proof.
		 *
		 * @param array{experiment_key: string, variant: string, surface: string} $metadata Bounded exposure metadata.
		 */
		do_action( 'extrachill_experiment_exposure', $metadata );

		return array( 'accepted' => true );
	}
}
