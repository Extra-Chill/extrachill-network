# Journey: venue-booking-console

Walks the real public venue-booking-inquiry form (dynamic fields,
availability check, session-draft persistence, Turnstile gate), the real
operator Venue Settings console's Booking Form tab (website-embed copy
widget, preview-toggle responsiveness), and the real hosted booking-embed
document's admission/CSP authorization.

Ported from `extrachill-events/tests/browser/{booking-inquiry,
booking-correspondence, booking-form-preview, booking-setup-copy,
booking-embed}.evidence.js` (extrachill-events#292).

## What changed from the mocked-fixture originals

The originals mounted the built React bundles against hand-authored static
HTML fixtures with a fully mocked `fetch`/DOM (`booking-inquiry`,
`booking-correspondence`, `booking-form-preview`, `booking-setup-copy`) or
fake cross-origin domains intercepted by Playwright routes
(`booking-embed`). None of those five files ever exercised a real WordPress
request. This journey exercises the **real** rendered pages, **real** REST
endpoints, and **real** embed admission/CSP logic
(`inc/Core/VenueBookingEmbed.php`) on the network rig instead:

- **booking-inquiry**: a real seeded venue with the exact field shape the
  original fixture's `config` JSON modeled (`website` url, `event_type`
  select with a conditional `other_event` text field, `press_links` url),
  reached through the real `#booking-inquiry` anchor on a real venue
  archive page, hitting the real `/extrachill/v1/venues/{id}/booking-*` REST
  endpoints instead of a mocked `window.fetch`.
- **booking-embed**: this is a genuine improvement on the original, not
  just a port. The original used three synthetic domains
  (`events.example`/`allowed.example`/`denied.example`) that never touched
  the real product's authorization code at all — it tested the embed
  script's `postMessage` logic in total isolation. Reading
  `VenueBookingEmbed::authorize_request()` revealed the real product does
  **not** decide allow/deny by detecting the actual framing origin; it
  decides by matching the `parent-origin` query parameter against the
  venue's own `embed.allowed_parent_origins` config, then sets a scoped
  `Content-Security-Policy: frame-ancestors` header as defense-in-depth. A
  *denied* origin gets an outright `wp_die()` 403, not a normal page framed
  with a stricter CSP. This journey tests the **real** decision by
  navigating directly to the real embed URL with an allowed vs. a
  disallowed `parent-origin` — no second real host needed, and no fake
  domains either.
- **booking-correspondence** / **booking-form-preview** / **booking-setup-copy**:
  these all live inside the real Venue Settings console's "Booking Form"
  tab (`blocks/venue-settings/src/booking-form-tab.js`,
  `src/booking-console.js`'s `Correspondence` component), reached by
  navigating to `/venue-settings/` as a real seeded owner and clicking the
  real "Booking Form" tab button — the exact click-through pattern the
  component's own unit tests use (`buttonByText(container, 'Booking Form')`).

## What could not be exercised

- **A real submission through Cloudflare Turnstile.** Same finding as
  `gardner-event-rsvp` (extrachill-network#291): this rig does not
  configure a Turnstile site key by default
  (`ec_get_turnstile_site_key()` returns empty), so
  `ec_render_turnstile_widget()` itself returns an empty string rather than
  rendering the real Cloudflare widget. The journey verifies the
  `[data-booking-turnstile]` wrapper renders (proving the block wires up
  the security-challenge slot) without requiring widget children, since
  those depend on a site key this sandbox does not have configured.
- **The Venue Settings console's Booking Form tab, for real.** Filed as
  [extrachill-events#899](https://github.com/Extra-Chill/extrachill-events/issues/899):
  `/venue-settings/` 500s with `Uncaught Error: Class
  "ExtraChillEvents\Core\VenueLinkPages" not found` for any real,
  authenticated team member with venue access, on the latest release of
  every component. See "Findings" below.
- **The occupied-date availability check.** A real hold was created
  (negotiate → select-performance → create-booking-hold, the same sequence
  `gardner-venue-booking` proves works) for 2028-05-01, but
  `check-booking-availability` still reports that interval as available —
  both in this journey's own server-side check and in the real public
  booking-inquiry form. Root cause not fully attributed this session; see
  `evidence/FINDINGS.md`.

## Honest results (see `evidence/result.json`)

The server-side `grade.php` checks: **2 assertions, 1 passed, 1 open
finding** (the occupied-date availability discrepancy above). The hosted
embed's CSP/admission decision is verified by the journey's own browser
steps (`hosted-embed-allowed-origin`, `hosted-embed-denied-origin`), which
passed for real; a redundant server-side `wp_remote_get()` spot check was
tried and removed after it returned an unattributed 404 in this runtime
that contradicted the passing browser evidence (see the code comment in
`grade.php`).

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["venue-booking-console"]
}' homeboy rig up extrachill-network
```
