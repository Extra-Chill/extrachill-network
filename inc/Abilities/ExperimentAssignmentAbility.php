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
					'required'             => array( 'experiment_key', 'variant', 'surface', 'measurement_eligible' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'       => array( 'type' => 'string' ),
						'variant'              => array( 'type' => 'string' ),
						'surface'              => array( 'type' => 'string' ),
						'measurement_eligible' => array( 'type' => 'boolean' ),
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
}
