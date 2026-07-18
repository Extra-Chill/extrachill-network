# Experiment Lifecycle

Extra Chill Network owns experiment definition validation, lifecycle state, allocation, and trusted assignment/exposure hooks. Consumers own rendering and eligibility. Identity and privacy remain provider filters, Analytics owns measurement persistence, and Extra Chill Users remains the authorization and rollout owner. An experiment assignment can never grant access denied by a consumer.

## Definition Contract

Consumers register code-owned definitions with `extrachill_experiment_definitions`:

```php
add_filter(
	'extrachill_experiment_definitions',
	static function ( array $definitions ): array {
		$definitions['example-experiment'] = array(
			'key'                  => 'example-experiment',
			'definition_version'   => 1,
			'assignment_policy'    => 'weighted_random',
			'default_state'        => 'inactive',
			'default_variant'      => 'control',
			'control_variant'      => 'control',
			'variants'             => array( 'control' => 50, 'treatment' => 50 ),
			'surfaces'             => array( 'example-surface' ),
			'eligibility_callback' => 'example_consumer_is_eligible',
		);

		return $definitions;
	}
);
```

The stable key must match the filter array key. Versions are positive integers. `weighted_random` is the only policy. The default and control must be the same declared variant. Consumers must explicitly review `default_state`; use `inactive` for new experiments. The first `geo-bridge-holdout` definition is inactive by default and requires an explicit operator transition before allocation starts.

The pre-lifecycle keyed shape is normalized as version `1`, policy `weighted_random`, and default state `active` to preserve the merged assignment behavior during migration. Consumers should move to the explicit contract when next edited.

## Live State

One network option, `extrachill_experiment_lifecycle`, stores only current overrides:

```php
array(
	'example-experiment' => array(
		'definition_version' => 1,
		'state'              => 'paused',
	),
)
```

Valid transitions are `inactive -> active`, `active -> paused`, `paused -> active`, `active -> completed`, and `paused -> completed`. Repeating the effective state is idempotent. A completed definition version is terminal. A higher code definition version resets to its reviewed default and may be activated as a new run.

Missing state uses the code default. Corrupt, unknown, unregistered, or future-version state fails closed. Option keys cannot be supplied by callers; lifecycle writes resolve only registered code definitions.

## Public Helpers

- `extrachill_experiment_is_active( $key, $surface, $context )` checks current lifecycle, registered surface, and consumer eligibility before rendering or enqueueing.
- `extrachill_experiment_attributes( $key, $surface, $context )` returns cache-neutral control attributes and enqueues the assignment client only for an active, consumer-eligible experiment.
- `extrachill_resolve_experiment_assignment( $key, $surface, $context )` returns an empty variant for lifecycle no-ops. Once active, consumer eligibility, subject, or privacy failure returns cache-neutral control unmeasured.

Inactive, paused, completed, unregistered, and invalid experiments are true no-ops: no assigned variant, experiment attributes, client enqueue, assignment request, token, assignment/exposure hook, DOM event, or downstream Analytics event. The consumer's normal feature behavior continues unchanged; Network does not force control or treatment while lifecycle is non-active. Active but consumer-ineligible or privacy-excluded requests retain cache-neutral control delivery without measurement.

## Abilities And Hooks

Public browser abilities:

- `extrachill/resolve-experiment-assignment`
- `extrachill/record-experiment-exposure`

Private lifecycle abilities require `manage_network_options`. Cookie-authenticated REST requests also pass WordPress REST nonce validation before the Ability permission callback:

- `extrachill/list-experiments`
- `extrachill/transition-experiment-state`

Assignment responses, exposure requests, signed proofs, DOM events, and trusted `extrachill_experiment_assignment` / `extrachill_experiment_exposure` metadata include `definition_version` and `assignment_policy`. State changes emit `extrachill_experiment_state_changed` with only the experiment key, definition version, previous state, and new state for optional audit integration; Network adds no audit storage.
