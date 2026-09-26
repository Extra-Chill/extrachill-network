# Near-me and My Shows journey — findings (extrachill-network#292)

No confirmed product defects this run. One genuinely unresolved harness
question, recorded honestly rather than guessed at.

## What was verified

- **My Shows, anonymous, desktop + mobile**: the marketing headline ("Every
  show has a story. Keep yours."), both CTAs' hrefs correctly point at
  `extrachill.com/login/` (not the events site or a fake domain), clicking
  either CTA performs a real cross-site navigation to extrachill.com, and
  the real registration form (`[data-ec-login-register-root]`) loads there.
  No horizontal overflow on mobile.
- **My Shows, logged in**: the marketing surface does not render for an
  authenticated visitor (the real app-shell branch takes over instead).

## Unresolved: near-me under real denied geolocation permission

Under wp-codebox's real `geolocation-permission=denied` (a genuine Chromium
CDP permission denial, not a mock), `.near-me-cities` — the fallback city
grid `near-me.js` shows on any geolocation failure — did not become visible
within this session's testing window (tried up to 45s). No JavaScript
console errors were observed during any attempt. Two explanations were
considered but not distinguished:

1. `near-me.js`'s error-handling path for a `PERMISSION_DENIED` code
   specifically (rather than another `PositionError` code) may take a
   different, slower path than assumed.
2. A real CDP-level permission denial may resolve on a different timeline
   than the fixture's synthetic 30ms mock did.

Recorded as an explicit unresolved harness question (not asserted on, not
counted as a finding) rather than guessed at. A follow-up session should
capture the DOM snapshot mid-wait to see the actual in-progress state.
