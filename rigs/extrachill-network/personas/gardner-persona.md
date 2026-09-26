# Chris Gardner Persona Contract

Extra Chill Users originally defined the canonical network identity and access contract for the Chris Gardner reference persona; the machine-readable fixture and this doc are now pinned and canonical in this rig's `personas/` directory (extrachill-network#291, extrachill-network#293), the one place a network boot / E2E user-journey harness lives. Gardner represents a nontechnical power user who knows the operation well, notices small inconsistencies, expects direct outcomes, and may reload, backtrack, double-submit, or change his mind when state is unclear.

The machine-readable contract is `gardner.v1.json` (this directory). Its stable persona ID is `extra-chill-users/chris-gardner`, and its current contract version is `1.0.0`. The fixture uses an `example.invalid` email and a visibly test-only display name; it is not Chris Gardner's production account or contact record. See `README.md` in this directory for the pinning provenance (which commit of Extra Chill Users this copy was pinned from).

## What the contract records

The contract records only Users-owned identity/access concerns:

- Canonical identity and the `extra_chill_team` team role.
- Membership with that role on every active network site, matching the network-wide team grant behavior.
- Baseline WordPress and Extra Chill capabilities supplied by the team role.
- The explicit per-user `manage_brand_socials` grant on every active network site.
- Reusable behavioral traits and stable oracle vocabulary.
- Safety constraints for test recipes.

The contract test (`../tests/gardner-persona-contract.test.mjs`) validates the fixture against `gardner.schema.json` and its stable identity/oracle shape. There is no runtime persona endpoint, runner, or orchestration layer.

## Consumer Boundary

Product repositories own their actions, setup, assertions, and capability-gap expectations. Broad examples include Studio social operations; Artist Platform profile, roster, ownership, link, and commerce journeys; Events booking, promoter, My Shows, event, venue, location, and artist-archive journeys; Community journeys; and future product scenarios.

Those scenarios may use the stable oracle IDs from this contract, but product actions do not belong in Extra Chill Users. Extra Chill Network remains product-agnostic and does not need to know which products consume the persona.

## Pinning A Recipe

A journey should pin both identity and version in its `journey.json` `persona` key, then load the matching fixture from this directory:

```json
{
	"persona_contract": {
		"id": "extra-chill-users/chris-gardner",
		"version": "1.0.0"
	}
}
```

Consumers must reject an unavailable or incompatible version rather than silently using the latest contract. They should reference oracle IDs such as `safe-retry` and `reload-persistence`; they should not copy the oracle definitions or add product behavior to this fixture.

Contract changes that alter required identity, access, traits, or oracle semantics require a new semantic contract version and a correspondingly named fixture. Compatible documentation clarifications do not require a version change.

## Safety

Persona recipes must never contain or request production credentials, tokens, personal contact data, or live external writes. Product scenarios must use isolated test identities, fake destinations, and stubbed or sandboxed integrations.
