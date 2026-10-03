# Gardner event-RSVP journey — findings (extrachill-network#291)

Run against a real, disposable 11-site network boot (`homeboy rig up
extrachill-network`, `extrachill_journeys: ["gardner-event-rsvp"]`), every
component from its owning repo's latest release except `extrachill-network`
itself (this branch, via `extrachill_component_source_overrides`). 15
persona-oracle assertions, 9 passed, 6 recorded as findings/skips below.
Full machine-readable result: `result.json`. Screenshots: desktop (1280px)
and mobile (390px) event-page views, the Going-button before/after state,
the perk pass on page, the logged-out login redirect, and the registration
block.

## Findings table

| Finding | Non-technical severity | Repo | Issue |
| --- | --- | --- | --- |
| Main-site "Top Event Markets" card doesn't reorder to the visitor's saved city on a fresh/non-production boot (route-affinity forward fails with "Invalid or expired route-affinity token") | High — breaks a whole class of cross-site homepage/API composition outside production | extrachill-api | [#189](https://github.com/Extra-Chill/extrachill-api/issues/189) (already filed from this same investigation; not re-filed) |
| The RSVP perk-pass email doesn't appear to queue, even though the pass is correctly issued and correctly shown on the page | Medium — "your beer receipt survives losing the page" only holds via the on-page display, not email, until confirmed | extrachill-events | [#897](https://github.com/Extra-Chill/extrachill-events/issues/897) |
| Free events don't show an at-a-glance "free" indicator near the price | Low-medium — a visitor has to read the description to learn there's no cost | data-machine-events | #861 (already filed; unchanged, still open) |
| A host viewing the public attendee list can't tell a *private* RSVP is still coming (by design, per extrachill-users#414/#415) | Low — known, already-decided product tension | extrachill-users | Not re-filed; #414/#415 already track it |
| New-visitor registration from the event page could not be exercised end-to-end | N/A — harness limitation, not a confirmed defect | — | See "What couldn't be exercised" below |

## What couldn't be exercised

**New-account registration from the event page.** The journey fixed a real
harness bug along the way (the register form's email field, `#extrachill_email`,
collided with an unrelated newsletter-signup email field also on the page —
`input[type="email"]` matched both, so Playwright refused to fill either).
After that fix, the automated persona reaches the registration form, fills
it, and submits — and gets **"Security verification required."** This is
Cloudflare Turnstile (network-wide via `extrachill-network`'s own Turnstile
integration) correctly doing its job: an automated browser session has no
way to solve it. This is **not** a confirmed product defect — a real human
visitor's browser would complete Turnstile normally — it is a genuine limit
of what a scripted persona can verify in this environment. The
`extrachill-events#876` "registration submit control never found" question
this journey set out to close is now answered precisely (it was a selector
collision, now fixed in `journey.json`), but the actual register → RSVP loop
itself remains unverified end-to-end by this rig.

## UX review (desktop + 390px mobile)

**What/when/where/cost at a glance.** Date, time, and full venue address
with a map and a "Venue Website" link are all present above the fold on
both breakpoints — good. **Cost is not shown anywhere near the primary
event card** (see the "free indicator" finding above); a first-time visitor
has to read into the paragraph copy to learn the event is free.

**Going button clarity.** The button's *state change* is clear and good:
clicking "Going" turns it green with a checkmark and "N going" count, plus
an explicit "Attendance saved." confirmation
(`gardner-marked-going-with-pass.png`). Its *default* (not-yet-clicked)
state is weaker: on both desktop and mobile
(`desktop-event-page-logged-out.png`, `mobile-event-page-390px.png`) the
un-clicked "Going" button renders in a muted white/gray, visually
*less* prominent than the neighboring "Event Link" (cyan) and "Add to
Calendar" (cyan) and "Share" (green) buttons, even though it is the single
most important action on the page for a visitor deciding whether to attend.
Worth a design pass to make the primary RSVP action look primary before a
visitor commits, not just after.

**Privacy wording.** The privacy disclosure ("Your name appears in the
public attendee list when you mark attendance. Manage visibility.") is
accurate and well-placed — but it only appears *after* a visitor has
already clicked Going, not before. Someone who cares about that would
benefit from seeing it as part of the decision, not as an after-the-fact
notice.

**Logged-out → register → back to event.** The redirect to `/login/` for a
logged-out "Going" click works (200, Login/Register tabs, "Register here"
link) — see `logged-out-going-click-redirects-to-login.png`. There's no
"you'll come back to this event" messaging on the login page itself, so a
visitor bounced there has no cue their place will be preserved.

**Perk pass on page/email/door.** On page: excellent —
`gardner-reload-persistence-perk-pass.png` shows "Your first beer is on
Extra Chill – show your pass code at the door" with the actual pass code,
surviving a reload. At the door: verified server-side (redeem ability call
succeeds, double-redeem is safe). By email: unverified, see the findings
table.

**Design-system classes.** Every button observed in these screenshots
matches classes that exist in the theme's `style.css`
(`button-1`/`button-2`/`button-3` + size modifiers); no invented classes
found in this surface.
