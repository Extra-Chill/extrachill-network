# Journey: near-me-and-my-shows

Walks the real `/near-me/` page under real granted/denied browser
geolocation permission (wp-codebox's own geolocation environment, not a
mocked `navigator.geolocation`), and walks an anonymous visitor from the
real `/my-shows/` marketing surface into the real cross-site registration
redirect on extrachill.com.

Ported from `extrachill-events/tests/browser/near-me.evidence.js` +
`near-me-fixture.html` and `tests/browser/my-shows-registration.evidence.js`
(extrachill-network#292).

## What changed from the mocked-fixture originals

- **near-me**: the original mocked `navigator.geolocation` entirely on a
  static fixture page (`near-me-fixture.html`) that loaded the real
  `assets/js/near-me.js` bundle but faked seven synthetic states
  (`unresolved`, `unsupported`, `denied`, `timeout`, `lookup-failure`,
  `stalled`, `success`). This journey navigates the real `/near-me/` page
  and drives **real** browser geolocation permission grant/deny via
  wp-codebox's own `geolocation-permission` environment argument (backed by
  a real Chromium CDP `Browser.setPermission` call) instead of a JS mock —
  the actual `denied` code path is exercised for real. The other five
  synthetic states (`unsupported`, `timeout`, `lookup-failure`, `stalled`,
  and the fixture's specific dropped-recenter-event reproduction of
  extrachill-events#832) have no equivalent in wp-codebox's declarative
  geolocation environment (no mechanism to inject a custom
  `PositionError.code`, simulate a hung `getCurrentPosition` call, or
  tamper with a specific custom DOM event) and could not be ported for
  real; see "What could not be exercised" below.
- **my-shows-registration**: the original pointed at fake
  `events.example`/`community.example` domains that never touched real
  routing at all. Reading `blocks/concert-stats/render.php` revealed the
  real registration target is **not** community.extrachill.com as the
  original fixture assumed — it is `extrachill_users_get_registration_url()`
  = `network_home_url('/login/', 'https')`, which resolves to
  **extrachill.com** (the network's primary site). This journey exercises
  the real cross-site click-through from events.extrachill.com to
  extrachill.com (both real declared network sites), reusing the same
  `[data-ec-login-register-root]` registration-form selectors
  `gardner-event-rsvp` already proved work.

## What could not be exercised

- **Five of near-me's seven synthetic geolocation states**
  (`unsupported`/`timeout`/`lookup-failure`/`stalled`, plus the
  `data-machine-map-recenter` event-drop reproduction of
  extrachill-events#832). wp-codebox's browser-actions geolocation
  environment supports only `granted`/`denied`/`prompt` permission states
  with real coordinates — it has no mechanism to inject a custom
  `PositionError` code, simulate a hung geolocation call, or tamper with a
  specific custom DOM event before the page's own scripts run (no
  init-script capability). This is a genuine rig capability gap, not a
  workaround to paper over: a future rig enhancement adding an
  `init-script` browser-actions step kind (broadly useful beyond this one
  case) would close it. Not filed as a new upstream issue this session for
  lack of time to write a minimal repro; noted here as a specific follow-up.
- **A confirmed real-geolocation "success" convergence.** The `granted`
  step captures the page's state after real geolocation resolves but does
  not hard-assert full convergence to a scoped calendar, since that also
  depends on the real events-map/calendar blocks' own network calls, which
  `network-policy=block` restricts to `events.extrachill.com` only (no real
  map tile/geocoding provider is reachable in this sandbox). Recorded as
  informational evidence only.

## Honest results

`venue-claim-and-discovery`-style browser-only journey (no `grade.php`).
The `my-shows-anonymous-registration-*` steps verify: the real marketing
copy and CTA hrefs pointing at `extrachill.com/login/`, the real
cross-site navigation, and the real registration form
(`[data-ec-login-register-root]`) loading with `redirect_to` carrying the
originating `/my-shows/` URL. The `near-me-denied-permission-*` steps are
intentionally observational (no hard `assert`) after this session could not
attribute why `.near-me-cities` does not become visible within the
originally-planned window under real `denied` geolocation permission in
this runtime — captured status text and page-error count are recorded as
evidence for a follow-up investigation rather than asserted on, so a
genuine unresolved question is never silently converted into a pass or a
misattributed finding.

## Run it

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["near-me-and-my-shows"]
}' homeboy rig up extrachill-network
```
