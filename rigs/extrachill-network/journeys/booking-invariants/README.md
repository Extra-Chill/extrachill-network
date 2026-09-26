# Journey: booking-invariants

Proves the venue-booking backend invariants a persona journey cannot: exact
and changed-payload idempotency, identity-injection rejection, venue-scoped
idempotency keys, private authorization, stale-version conflicts, valid and
invalid status transitions, message idempotency, performance/deal
selection, holds, confirmation, canonical event conversion and its own
idempotency, reschedule, linked cancellation, timezone alignment,
event-source uniqueness, and configuration-revision conflicts, plus a real
cross-site block-render check (main/studio/events).

Ported from `extrachill-events/tests/NetworkE2E/booking/{topology.php,
assert.php}`, `run.mjs`, `action-model.json`, `docs/booking-network-e2e.md`,
and `.github/workflows/booking-network-e2e.yml` (extrachill-network#292).
This journey and `journeys/gardner-venue-booking` are complementary: this
one proves the invariants hold; that one proves a nontechnical operator can
actually use the surface built on top of them.

## What changed from the hand-rolled network boot

The original `tests/NetworkE2E/booking` built its **own** disposable
multisite topology from scratch (`wpmu_create_blog()` at hardcoded blog
IDs, `.github/workflows/booking-network-e2e.yml`'s 9 SHA-pinned sibling
checkouts, 14 `BOOKING_E2E_*` env vars) because it ran in an isolated
single-purpose WordPress instance with no other consumer. This rig already
boots the real 11-site topology with every network and per-site plugin
activated from the latest release, so this journey's `seed.php` only
resolves the sites it needs **by domain** and stages the two venues, two
users, and one venue membership the invariant assertions in `grade.php`
depend on — the entire hand-rolled boot, the 9-checkout CI matrix, and the
14 env vars are gone.

Both fixture users need the real `extra_chill_team` role (not
`administrator`) for the identical reason `gardner-venue-booking`
documents: the `venue_booking` feature's `team` rollout ceiling bypasses on
`manage_options` or `ec_is_team_member()`, and a per-site `administrator`
role does not carry `manage_options` on this network.

## What could not be ported for real: the two-connection CAS race

The original proved a real two-connection one-winner compare-and-swap race
using two independent `mysqli` connections and `MYSQLI_ASYNC` against the
booking table's `version` column. This rig's default WordPress runtime
database is **SQLite** — wp-codebox's own `adversarial-adapter.ts` already
documents "no supported multi-connection injection primitive" on it. A raw
multi-connection row-level race genuinely cannot be proven against SQLite.

`concurrent-booking-cas-single-winner` is reported as an honest **skip**
(never silently passed or dropped): `grade.php` still attempts a real
`mysqli` connection first (in case a future rig run adds a MySQL-backed
option) and only skips if no real MySQL server is reachable, which is
always true on the current default runtime. This is a genuine, named rig
capability gap, not a per-journey workaround — see "Filed gaps" below.

## Honest results (see `evidence/result.json`)

**32 assertions, 32 passed, 0 open findings, 10 skipped** (all documented
environment limitations) on a real `homeboy rig up extrachill-network` run.

- 1 skip: the CAS race (above).
- 5 skips: canonical event conversion returns `booking_event_ability_unavailable`
  (503, retryable) in this SQLite-backed runtime — the same `GET_LOCK`
  limitation `gardner-venue-booking` documents. Every case downstream of a
  real converted event (idempotent retry, reschedule, linked cancellation,
  timezone alignment, the activity-ledger terminal markers, event-source
  uniqueness) is skipped rather than silently omitted or misreported as a
  failure.
- 4 skips: `send-booking-message`'s message-exact-retry needs a comparable
  success object; this runtime returns `booking_message_delivery_uncertain`
  (no real mail transport) for every send, so the identity check has
  nothing to compare (matching `gardner-venue-booking`'s finding for the
  identical ability). Everything downstream of the message ability that
  depends on that success object inherits the skip.

Every invariant that does not depend on GET_LOCK or a real mail transport
holds: exact/changed idempotency, identity-injection rejection,
venue-scoped keys, private-read authorization (cross-venue denied,
anonymous denied, operator allowed), invalid/stale/valid transitions,
performance and deal selection, hold creation and confirmation, and
configuration-revision conflicts.

## Filed gaps

- **SQLite has no real multi-connection race primitive.** Not filed as a
  new issue this time — it's the same class of documented rig limitation as
  the "no Redis server" exclusion in `components.json`'s
  `excludedComponents`. A future rig extension could add an opt-in
  MySQL-backed database service for journeys that specifically need raw
  concurrency proofs (mirroring wp-codebox's own `inputs.services` mysql
  capability the deleted `run.mjs` used), but that is new rig
  infrastructure, not something this port should improvise per-journey.

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["booking-invariants"]
}' homeboy rig up extrachill-network
```
