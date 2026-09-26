# Rig personas

Canonical, versioned persona contracts consumed by this rig's journeys.
These are **pinned copies**: the owning repo is
[Extra-Chill/extrachill-users](https://github.com/Extra-Chill/extrachill-users)
(`tests/personas/`); the copies here exist so a network boot never breaks
because of an unrelated change in another repo, and so the rig's journey
contract is self-contained (extrachill-network#291, extrachill-network#293
tracks deleting the source copies once consumers stop reading them).

| File | Persona | Pinned from |
| --- | --- | --- |
| `gardner.v1.json` | `extra-chill-users/chris-gardner@1.0.0` | extrachill-users `627533b` ("test: define Gardner persona contract (#368)"), file unchanged through `6c5f307` (origin/main, 2026-09-26) |
| `gardner.schema.json` | JSON Schema for persona contracts | same commit |

Update a pin by copying the newer file from `extrachill-users` and updating
the table above in the same commit. A journey whose `journey.json` names a
persona file that does not exist here fails recipe validation loudly.

Single-scenario fixture users (e.g. the "returning subscriber" or the
live-registering "new creative" in the Gardner event-RSVP journey) are
**not** personas and do not belong here -- see the events journey README for
the reasoning.
