# Venue booking console journey — findings (extrachill-network#292)

## Findings table

| Finding | Non-technical severity | Repo | Issue |
| --- | --- | --- | --- |
| `/venue-settings/` 500s with a class-not-found fatal (`ExtraChillEvents\Core\VenueLinkPages`) for any real team member with venue access | High — the entire operator console is unreachable, not a partial degradation | extrachill-events | [#899](https://github.com/Extra-Chill/extrachill-events/issues/899) |
| A real hold on a date does not make `check-booking-availability` report that date as unavailable | Medium — the public booking form can still offer a night that is actually held | — | Not filed as a numbered issue this session; root cause not fully attributed. See "What couldn't be attributed" below. |

## What couldn't be attributed: the availability/hold discrepancy

`seed.php` creates a real hold through the exact same ability sequence
`gardner-venue-booking` already proves works end-to-end
(`transition-venue-booking` → `under_review` → `negotiating`,
`select-venue-booking-performance`, `create-booking-hold`), and the seed's
own fixture evidence confirms it (`occupied_date_hold: {"ok": true}`). Yet
both this journey's `grade.php` (`extrachill/check-booking-availability`)
and the real public booking-inquiry form (`public-inquiry-form-desktop`
browser step, checking for "That date is unavailable") see the date as
still available.

Two candidate explanations were identified but not distinguished within
this session:

1. A timezone or interval-representation mismatch between what
   `select-venue-booking-performance` stores as
   `performance_start_at`/`performance_end_at` and what
   `check-booking-availability` (`VenueBookingHoldRepository::public_interval_availability()`)
   compares against. `gardner-venue-booking`'s own seed/grade pass the
   *venue-local-converted-to-UTC* value as `start_at`/`end_at` (its own
   README documents this); this journey's seed passed the same
   `2028-05-01 20:00:00` string used everywhere else in its own fixture,
   which may not be the convention the ability actually expects.
2. `hold_ttl_minutes` (the venue config default) expiring the hold before
   the browser steps that check it run — a real network rig boot takes
   several minutes end to end, several journeys deep by the time this one's
   browser steps execute.

This is recorded as an open, unattributed finding rather than filed as a
numbered GitHub issue against a specific root cause, since filing a
specific fix recommendation without confirming which of the two (or
another) explanation is correct would misdirect the eventual investigator.
A follow-up session should add direct diagnostic logging of the hold's
`expires_at` and the exact stored performance interval before filing.
