# Journey: gardner-event-rsvp

Walks a nontechnical attendee through the real, currently-live "Extra Chill &
WordPress Meetup: Building Your Online Presence in the AI Era"
(events.extrachill.com post 486727, Lo-Fi Brewing, Charleston SC, Wed Oct 21
2026, 6:30-9pm) as three people, then grades the run against the persona
oracles. This is the first port of an events-repo scenario onto the network
rig (extrachill-network#291, ported from extrachill-events#876); the events
repo copy is deleted once this lands (follow-up issue linked from the rig
README).

## Who walks it

- **Chris Gardner** (`auth-user-id=201`) -- the pinned canonical persona
  `extra-chill-users/chris-gardner@1.0.0` (`../../personas/gardner.v1.json`).
  The seed layers scenario-owned state on top of the identity (public event
  attendance visibility, Local Scene = Charleston), exactly as the events
  repo's seed did.
- **Returning subscriber** (`auth-user-id=202`) -- an ordinary fixture user,
  deliberately left at the private-by-default attendance visibility the
  product actually ships (extrachill-users#415). Not a persona contract.
- **New Charleston creative** (no fixture user) -- registers for real through
  the live registration form, then comes back and RSVPs.

## What it covers

1. **Comprehension** (desktop + 390px mobile) -- what/when/where/cost at a
   glance, venue address, "all experience levels" copy, no horizontal
   overflow, no console errors.
2. **Discovery** -- the promoted-event callout on the Charleston location
   archive links to this event.
3. **Local Scene** -- Gardner's saved scene puts Charleston first in the
   main-site "Top Event Markets" homepage card (a genuinely cross-site
   surface: main site reads the events site's upcoming-counts contract).
4. **RSVP** -- Gardner marks Going once; it holds through a reload; the
   returning subscriber rapid-double-clicks (real double-submit) and stays
   private; the door list math matches the shipped #414/#415 decision.
5. **Perk pass** (extrachill-events#878) -- issued with a code when Gardner
   RSVPs, shown on the page, queued as an email, redeemed at the door
   (host view + double-redeem safety).
6. **Account** -- the logged-out Going click gates to the login page; the
   new creative actually SUBMITS registration (the original run never found
   the control: it is `input[name=extrachill_register]`, an `<input
   type=submit>`, which `:has-text()` never matches) and RSVPs afterward.
7. **Grading** -- `grade.php` verifies every browser claim server-side
   through read-only ability reads and direct table reads of the tables the
   product itself wrote.

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["gardner-event-rsvp"]
}' homeboy rig up extrachill-network
```

Add `"extrachill_component_source_overrides"` entries to test unreleased
components; without them the journey runs against the latest GitHub release
zip of every component (deploy parity).

## Seed decisions worth knowing

- The fixture **enables the event's RSVP perk** (`_extrachill_event_perk_*`)
  because the event's real copy promises "your first beer is on Extra Chill"
  -- production has not configured the perk meta on the real post, so the
  perk-pass surface would otherwise be untestable against this event.
- The event is written directly (post + taxonomy) instead of through
  `data-machine-events/upsert-event`: that ability serializes on a
  MySQL-only `GET_LOCK` primitive this runtime's database layer does not
  provide (documented in the events repo's own wp-codebox README first).
  Everything the JOURNEY then does runs through real registered abilities.
- Tables are **verified, not created**: the rig's activation step fires
  every plugin's activation hooks (network-wide, then per site), which is
  what creates them in production. The seed fails loudly if a table it
  depends on is missing -- that is a rig regression, never something the
  journey papers over. (The first real run proved the shape of this: the
  rig passed `activate_plugin(..., $silent = true)`, which skips activation
  hooks entirely, so no plugin table existed and every RSVP silently
  no-opped until the rig owned the fix.)
- Multisite registration must be enabled for the live-registration step; a
  fresh install defaults it off, so the seed sets the network `registration`
  option to `user` and records that it did.

## Evidence

`evidence/` holds the committed run artifacts: `FINDINGS.md` (the UX review
and findings table), the journey result JSON, the fixture JSON, and
screenshots. Everything larger or intermediate lives outside the repo (the
rig's artifacts root).
