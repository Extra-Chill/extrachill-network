# Extra Chill authentication multisite journey

Ported from `Extra-Chill/extrachill-users` `tests/e2e/auth-multisite/` (its
own bespoke runner composing the generic path-based `wordpress-multisite-e2e`
Homeboy rig) onto this rig's journey contract (extrachill-network#293). That
directory's own `run.mjs`, `settings.mjs`, `topology.php`, `activate.php`,
`components.example.json`, and `validate.mjs` are all dropped: this rig
already boots Extra Chill Network, API, Users, Analytics, and wp-native-auth
as real network-active plugins on the real 11-site domain-based network, so
none of that plumbing is needed to reconstruct a synthetic equivalent.

## What moved, and what changed

| Old file | New home | What changed |
| --- | --- | --- |
| `topology.php` | (deleted) | The old file created synthetic path-based `/community/`, `/artist/`, `/events/` sub-blogs under one shared domain with forced blog IDs (2/3/4/7). This rig already boots the REAL `community.extrachill.com` / `artist.extrachill.com` / `events.extrachill.com` domains, resolved by domain like every other journey. |
| `activate.php` | (deleted) | The four components it activated are already network-active plugins in `components.json`. |
| `seed.php` | `seed.php` | Same fixture personas (existing/nonmember/blocked/onboarding/victim) and fixture pages, seeded onto the real community site instead of a synthetic sub-blog. |
| `cases.mjs` | `seed.php` (`ec_rig_auth_multisite_case_plan()`) | Ported the deterministic, seeded case-plan generation from JS to PHP so it can read the rig's generic `extrachill_journey_seed` setting and run entirely inside the boot. |
| `fixture/auth-fuzz-fixture.php` | `auth-fuzz-fixture.php` (journey `fixtureMuPlugins`) | Kept the Turnstile-bypass and mail-bypass filters (the only seams a disposable sandbox genuinely needs). Dropped the custom rate-limit-store filters: this journey exercises Extra Chill Users' REAL default registration-admitter and login-rate-limit store instead of substituting a bespoke one, which is a truer test of production behavior. Added a `wp_login` recorder the grade step uses to verify cross-site handoff server-side. |
| `assert.php` | `grade.php` | Same REST invariants (registration validation, login enumeration resistance, refresh-token rotation/replay, rate limiting, onboarding scoping, redirect safety), unchanged in substance. |
| `post-assert.php` | `grade.php` | Same browser-mutation checks (the real browser registration created a real user, onboarding persisted, community membership). |
| `browser-anonymous.json` / `browser-registration.json` | `journey.json` `steps` | Same interactions (mismatched-password validation, real register + onboard), targeting the real `community.extrachill.com` domain with real pretty permalinks instead of `?pagename=` query-string routing (the old README's stated reason for that query string -- "the localhost Playground router does not provide production-equivalent pretty-page rewrites" -- does not hold on this rig; see `gardner-event-rsvp`'s own pretty-permalink event pages). |

## Cross-site continuity: a real mechanism, not a faked parity (the point of #293)

The old `post-assert.php` visited `/artist/?pagename=login&auth_fuzz_observe=artist`
under ONE shared path-based domain and treated the SAME literal session
cookie showing up there as proof of "cross-site continuity." That was
already an artifact of the path-based topology, not of production: this
rig's own README says so plainly under "Boundary" --
`crossDomainCookieParity` is `not-claimed`, there is no `COOKIE_DOMAIN` in
production's `wp-config.php`, and production's actual mechanism for "a
session on one site should also authenticate on another" is
`auth.extrachill.com` / wp-native Auth's browser-handoff token, not a shared
cookie.

This journey's `grade.php` does not fake that old parity. It drives the REAL
mechanism: `extrachill_users_create_browser_handoff_token()` mints a real
one-time token for the browser-registered user, and the grade step follows
it over REAL HTTP against the REAL `admin-post.php?action=extrachill_browser_handoff`
handler on `artist.extrachill.com` and `events.extrachill.com` -- the exact
request a real browser makes when it navigates to a handoff URL. It verifies
the real WordPress session cookie is actually set (`Set-Cookie` header),
the redirect lands on the requested destination, and -- the part a
client-side cookie check cannot fake -- that WordPress's own `wp_login`
action genuinely fired for the right user on the right site (recorded by
the `auth-fuzz-fixture.php` mu-plugin, since a cookie value alone can't be
read back to prove *whose* session it is).

## Turnstile

Automated registration is blocked by Cloudflare Turnstile by design (see
`gardner-event-rsvp`'s own findings: "Security verification required." is
the real, correct behavior of an unsolvable challenge, not a defect). This
journey's `fixtureMuPlugins` mounts a small mu-plugin that sets
`extrachill_bypass_turnstile_verification` -- the SAME dev/test seam the
product already ships in `extrachill-network/inc/core/extrachill-turnstile.php`
for exactly this purpose. Zero network egress, deterministic, and
structurally unable to reach production config: it is only ever wired up
from inside this journey's own disposable mu-plugin mount, which does not
exist unless `auth-multisite` is explicitly selected. The
`cross-widget-turnstile` journey continues to exercise the REAL widget
render contract with Cloudflare's documented test keys; this journey is
about the auth flow behind the challenge, not the challenge itself.

## No persona

This is a security/fuzz campaign over adversarial fixture personas
(existing/nonmember/blocked/onboarding/victim/browser-registered), not a
single reference persona -- it carries no `persona` key, matching
`personas/README.md`'s guidance that single-scenario fixture users are not
personas.

## What Google OAuth, real email, and TLS still don't cover

Unchanged from the old README: Google OAuth, real email delivery, and TLS
behavior remain outside this journey and require deployed smoke coverage.
