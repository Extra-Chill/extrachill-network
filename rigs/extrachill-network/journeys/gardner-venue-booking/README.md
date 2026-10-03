# Journey: gardner-venue-booking

Walks Chris Gardner through one realistic booking week for Lo-Fi Brewing, a
two-space neighborhood room: reading a Monday inbox, moving a request
forward, holding a date, emailing an offer, double-clicking send, reloading,
and confirming a show. Ported from
`extrachill-events/tests/wp-codebox/gardner-venue-booking.json`,
`-seed.php`, `-journey.php` (extrachill-events#292, originally landed as
extrachill-events#771/c85d235) onto the full 11-site network rig.

## Who walks it

- **Chris Gardner** (`auth-user-id=201` — this journey has no browser steps,
  so the ID is only used inside `wp_set_current_user()` in seed/grade, not
  via `auth-user-id` browser args) — the pinned canonical persona
  `extra-chill-users/chris-gardner@1.0.0` (`../../personas/gardner.v1.json`),
  forced with the contract's real `extra_chill_team` role and baseline
  capabilities.
- **Outsider** (dynamic ID) — a second team member with no membership at
  this venue, proving server-side venue-scoped denial rather than UI hiding.
  A single-scenario fixture, not a persona contract.

## What changed from the single-site fixture

- No `switch_to_blog()`/`EC_BLOG_ID_EVENTS` override shim: this rig IS the
  real 11-site multisite; the events site is resolved by domain.
- Gardner is forced at the canonical rig persona ID (201, matching
  `gardner-event-rsvp`) with the real `extra_chill_team` role instead of a
  bespoke `administrator` account. This turned out to matter for real: the
  `venue_booking` feature sits behind a `team` rollout ceiling
  (`extrachill-events` `LifecycleProvider::register_feature_ceilings()`)
  whose bypass is `user_can( $id, 'manage_options' )` OR
  `ec_is_team_member( $id )` — verified empirically that a per-site
  `administrator` role on this network does **not** carry `manage_options`,
  so only the real team role satisfies the gate. Every persona/fixture user
  across this PR's five journeys uses `extra_chill_team` for exactly this
  reason.
- Tables are verified, not created: the rig's activation step fires
  `extrachill-events`' activation hook (`BookingSchema::install()`).

## Honest results (see `evidence/result.json`)

29 assertions, **29 passed**, 4 skipped, 0 open findings, on a real
`homeboy rig up extrachill-network` run of the full 11-site boot plus every
new journey in this PR.

- `publish-to-calendar` and `republish-does-not-duplicate` are skipped:
  canonical event conversion (`extrachill/convert-booking-to-event`) returns
  `booking_event_ability_unavailable` (HTTP 503, retryable) in this runtime
  — the canonical upsert serializes on the MySQL-only `GET_LOCK` primitive,
  which this SQLite-backed rig does not provide. Same root cause the
  original `gardner-venue-booking-journey.php` already knew about (there it
  showed as `booking_event_upsert_failed`); the error code differs on the
  currently-released `extrachill-events`, verified empirically here.
- `send-offer-email` and `double-click-does-not-double-send` are skipped:
  `extrachill/send-booking-message` returns `booking_message_delivery_uncertain`
  in this runtime (no real mail transport to confirm delivery) rather than a
  clean success object comparable across the double-click retry. A rig
  environment limitation, not a product defect — the ability is correctly
  reporting that it cannot confirm delivery.

Every other persona oracle (task-completion, obvious-state,
reload-persistence, duplicate-prevention, attribution, actionable-errors,
jargon-avoidance, server-authorization) held: illegal shortcuts, stale-tab
retries, and the message-idempotency-conflict all read as actionable,
jargon-free refusals; the hold correctly blocks the competing request in
both the private-availability checker and the console; the boundary (a
teammate without venue membership) is denied server-side.

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["gardner-venue-booking"]
}' homeboy rig up extrachill-network
```
