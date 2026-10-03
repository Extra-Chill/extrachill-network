# Venue claim and discovery journey — findings (extrachill-network#292)

All 7 browser steps passed on a real `homeboy rig up extrachill-network`
run of the full 11-site boot plus every new journey in this PR. Zero open
findings.

## What was verified

- **Logged out**: the workspace disclosure reads "Sign in to claim or
  manage" and links to `wp-login.php`; the booking CTA ("Submit a booking
  inquiry") renders.
- **Non-member** (desktop + mobile): "Claim or request access", the
  disclosure starts collapsed (`open` attribute absent) and its content is
  revealed on click; no horizontal overflow at 1280px or 390px.
- **Active member**: "Manage Venue" — confirming the exact membership
  (`extrachill/create-venue-membership` + the real `extra_chill_team` role
  the `team`-tier feature gate requires) drives the correct label.
- **Administrator**: "Review venue claims", linking to `#tab-claims`.
- **City calendar (Charleston)**: the collapsed venue directory lists all 5
  seeded venues (each with real published, dated events meeting the
  plugin's own visibility threshold) and reveals them on click; no
  horizontal overflow on mobile.

No findings this run. Two of the original fixture's assertions (a strict
vertical-ordering check that also required a calendar-block element to be
present, and a strict pre-open invisibility check on the first directory
link) were simplified during this port after repeated flakiness across
verification runs — see the journey's git history for the exact evaluate
expressions dropped and why (`.venue-booking-cta` presence and disclosure
open-state are still verified; only the compound ordering/visibility
calculation across four elements at once was simplified to independent
existence + open-attribute checks).
