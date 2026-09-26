# extrachill-network Homeboy rig

Boots the full 11-site Extra Chill subdomain multisite network -- real
per-domain sites, every network and per-site plugin, and the shared theme --
inside a disposable WP Codebox WordPress Playground sandbox. It exists to gate
network-wide deploys (extrachill-network#223): verify the *combination* of
~30 independently-released components boots cleanly before treating a deploy
as safe, and to give any repo's CI or an agent session a way to reproduce a
cross-plugin fatal without touching production.

See [Extra-Chill/extrachill-network#223](https://github.com/Extra-Chill/extrachill-network/issues/223)
for the full spike history this rig's design is built on (domain-based
multisite topology, release-zip mounting, and the theme-remote-sourcing gap).

## What it boots

- **Topology** (`network-topology.json`): the 11 production sites as real
  `wp_insert_site()` records under `SUBDOMAIN_INSTALL`, sourced read-only from
  `wp site list` on extrachill.com. Sites are matched by **domain**, never by
  blog ID -- a fresh install assigns new sequential blog IDs, so nothing in
  this rig or a consumer's own scenarios should assume blog ID 4 is the artist
  platform, etc.
- **Components** (`components.json`): every network-active and per-site plugin
  plus the shared theme, each defaulting to its owning repository's **latest
  GitHub Release zip** (`.../releases/latest/download/<asset>.zip`) or, for
  WordPress.org-hosted third-party plugins, `downloads.wordpress.org`. The
  per-site/network activation matrix is data read read-only from production
  (`wp plugin list --fields=name,file`), not shell.
- **Activation**: wp-codebox's own `extra_plugins[].activate` can only pick
  "network-wide" or "whatever blog is current," which cannot express a real
  11-site matrix. Every plugin mounts with `activate: false`; a single
  `wordpress.run-php` workflow step applies the exact per-site/network
  activation matrix itself, with dependency-tolerant multi-pass retries (see
  "Activation ordering" below). Activation runs with hooks **enabled**
  (`activate_plugin(..., $silent = false)`): core skips
  `register_activation_hook` callbacks entirely in silent mode, which left
  the 2026-09-23 baseline looking green while no plugin table existed
  anywhere. The rig also loads `wp-admin/includes/upgrade.php` (so `dbDelta`
  is available to callbacks the way a real admin activation provides it) and
  re-fires each network-activated plugin's activation hook per site, because
  network activation fires it only once from the current site -- per-site
  setup on the other 10 sites otherwise never runs.
- **Baseline scenario**: after activation, one assertion step compares actual
  vs. expected active plugins per site and does an internal anonymous
  `wp_remote_get(home_url('/'))` HTTP-status/fatal-marker check; then one
  `wordpress.browser-probe` per site asserts no console/page errors on an
  anonymous page load, with `network-policy=block` scoped to that site's own
  host.
- **Domain-ID alignment** (generated mu-plugin): a fresh install assigns new
  sequential blog IDs, but the network plugin set routes through
  `EC_BLOG_ID_*` constants (production's IDs: events = 7, newsletter = 9,
  ...). On every boot the rig generates `ec-network-domain-ids.php` from
  `network-topology.json` and mounts it into `mu-plugins/`; it resolves each
  `EC_BLOG_ID_*` constant **by domain** against the actual sites table on
  every request, so `ec_is_events_site()` and every cross-site route built on
  `ec_get_blog_id()` target the right site. Without it, the events-site
  identity silently lands on whichever site draws blog 7 (docs, on this
  topology). This is rig infrastructure, not a product workaround; the
  upstream fix is for `ec_get_blog_id()` to resolve by domain itself.
- **Journeys** (optional): full user journeys -- seeded personas, real
  browser interactions, persona-oracle grading -- that run after the baseline
  when selected via `extrachill_journeys`. See "The journey contract" below.

## Running it

```bash
homeboy rig install <this repo checkout or its git URL>
homeboy rig lint extrachill-network
homeboy rig package lint <this repo checkout>
homeboy rig check extrachill-network
HOMEBOY_SETTINGS_JSON='{"extrachill_theme_source":"/absolute/path/to/extrachill-theme-checkout"}' \
  homeboy rig up extrachill-network
```

`extrachill_theme_source` is required for a real `up` (omit it for `check`'s
dry-run, which never mounts the theme). See "Theme sourcing" below for why
this is a local checkout path today, not a release zip.

### Settings this rig reads (`HOMEBOY_SETTINGS_JSON`)

| Key | Purpose |
| --- | --- |
| `extrachill_theme_source` | Absolute local theme checkout path, or (forward-compatible only, see below) an `https://…zip` URL. Required for a real `up`. |
| `extrachill_journeys` | Array of journey IDs from `journeys/` to run after the baseline, e.g. `["gardner-event-rsvp"]`. Unknown IDs, wrong schemas, undeclared site domains, or missing persona files fail recipe validation loudly. |
| `extrachill_component_source_overrides` | `{ "<slug>": "<local path or URL>" }`. Overrides one component's mount source, e.g. to test an unreleased branch of `extrachill-network` itself. |
| `extrachill_release_set` | `{ "<slug>": { "ref": "<tag>" } }`. Pins a GitHub-hosted component to an explicit release tag instead of `latest`. There is no resolver that turns a `homeboy/release-set/v1` manifest into these entries yet -- a caller wanting deploy-parity pinning composes this map itself (see extrachill-network#223's spike notes on why `release-set/v1` is a gate, not a resolver). |
| `extrachill_include_excluded_components` | Array of slugs from `components.json`'s `excludedComponents` list to force-include; requires a matching `extrachill_component_source_overrides` entry (no default source exists for those). |
| `wordpress_runtime_php_version` | Overrides the default `8.4` (production's actual PHP major.minor, verified read-only via `wp eval 'echo PHP_VERSION;'`). |
| `wordpress_runtime_prepare_steps` / `wordpress_runtime_post_steps` | Consumer-owned recipe steps inserted before/after this rig's own workflow (same contract as `wordpress-multisite-e2e`). |

## The journey contract

**Journeys live here. This rig is the only place a network boot / E2E
user-journey harness may live** (extrachill-network#291). Consumer repos do
not keep their own runners, recipes, persona fixtures, or journey workflows;
they are components of this boot and, when a journey must exercise
unreleased consumer code, callers pass that code through
`extrachill_component_source_overrides` -- never by adding scenario files to
the consumer repo.

A journey is a directory under `journeys/<journey-id>/`:

```
journeys/gardner-event-rsvp/
  journey.json   # the contract document (schema extrachill-network/journey/v1)
  seed.php       # optional: run-php step executed before the browser steps
  grade.php      # optional: run-php step executed after the browser steps
  README.md      # what the journey walks, seed decisions, evidence layout
  evidence/      # committed run artifacts: FINDINGS.md, screenshots, result JSON
```

Contract rules enforced by `run.mjs` and `tests/contract.test.mjs`:

- `schema` must be `extrachill-network/journey/v1`; `id` must match the
  directory name.
- `sites` is a non-empty array of **domains** from `network-topology.json`.
  Sites are resolved by domain, never by blog ID -- seed and grade scripts
  resolve their target site with `get_sites(['domain' => ...])`.
- Every browser step carries an absolute `http://<domain>/...` URL, and its
  `url`, `route-host`, and `allow-host` hosts must be inside the journey's
  declared `sites` (with `network-policy=block`, journey steps block all
  other egress).
- `persona.file` must exist in `personas/` (pinned canonical copies; see
  `personas/README.md`). Single-scenario fixture users are not personas and
  stay inside the journey's seed.
- `seed`/`grade` are `wordpress.run-php` `code-file` steps read from the
  journey directory at boot time; no mount of the journey directory is
  needed.
- `runtimeEnv` entries (e.g. `WP_AGENT_RUNTIME=1`, which forces Data
  Machine's full runtime -- and abilities registration -- on front-end
  requests) merge into the recipe inputs when the journey is selected.
- Steps run **after the full baseline** (activation, per-site assertion, and
  every site's anonymous browser probe), in the order the setting lists
  them; consumer `wordpress_runtime_post_steps` still run last.

Selecting journeys:

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["gardner-event-rsvp"]
}' homeboy rig up extrachill-network
```

Testing unreleased consumer code remains a pure settings concern:

```bash
HOMEBOY_SETTINGS_JSON='{
  "extrachill_theme_source": "/path/to/extrachill-theme-checkout",
  "extrachill_journeys": ["gardner-event-rsvp"],
  "extrachill_component_source_overrides": { "extrachill-events": "/abs/worktree" }
}' homeboy rig up extrachill-network
```

Writing a new journey: copy the shape of `journeys/gardner-event-rsvp/`,
grade against the oracles of a pinned persona (or document why there is no
persona), commit curated evidence under `journeys/<id>/evidence/` (FINDINGS.md
plus screenshots plus the result JSON), and file every product finding in
the repo that owns it after a duplicate search. Keep the honest-outcomes
rule from the events repo journeys: a **finding** is a real product defect,
a **skip** is a runtime that could not fairly judge the case, and neither is
ever silently converted into a pass.

The old "consumer-owned scenario" pattern (a consumer repo layering its own
`wordpress_runtime_post_steps` browser scenario onto this baseline) is
superseded: `wordpress_runtime_prepare_steps` / `wordpress_runtime_post_steps`
remain for arbitrary workflow-level composition, but user journeys belong in
this directory.

## Activation ordering

`components.json` does not hand-order plugins by dependency (WooCommerce
before extrachill-shop, etc.) -- that's fragile the moment any component adds
a new "Requires Plugins" header or runtime check. Instead, `run.mjs`'s
activation step retries the pending list across multiple passes per site and
network-wide: each pass activates whatever's dependencies are already
satisfied, and the loop only throws once a full pass makes zero progress
(i.e., a real, non-ordering failure remains). This was hit and fixed live
during this rig's own verification run (`extrachill-shop` requires
WooCommerce to already be active).

## Theme sourcing (documented gap)

`wp_codebox_extra_themes` / `inputs.extra_themes` accepts a remote
`https://…zip` source as of
[Automattic/wp-codebox#2519](https://github.com/Automattic/wp-codebox/pull/2519)
(merged into wp-codebox `main` 2026-09-21 as v0.27.0, itself born from
[homeboy-extensions#2857](https://github.com/Extra-Chill/homeboy-extensions/issues/2857)
filed during this rig's own spike). The wp-codebox CLI installed on this host
is v0.27.1, so both sourcing modes below are available. One caveat drove the
rig's default: wp-codebox's own `extra_themes` activation is not
multisite-aware (it does not `switch_theme()` on every created site), while
the local-mount path carries a rig-owned activation step that switches the
theme on all 11 sites. A network boot therefore uses the local checkout path;
the remote zip path stays supported for single-site consumers of the rig.

`run.mjs` auto-detects which mode to use from `extrachill_theme_source`:

- An `https://…zip` URL is passed straight through to `inputs.extra_themes`.
  Works on wp-codebox v0.27.0+; note the multisite activation caveat above.
- Anything else is treated as a local checkout path and mounted as a readonly
  directory the same way `wordpress-multisite-e2e`'s own `run.mjs` already
  does, with a workflow step that `switch_theme()`s every created site.

There is deliberately **no default** for `extrachill_theme_source` on a real
`up` -- callers must supply a checkout of `Extra-Chill/extrachill` (or, once
the CLI updates, its release zip). `homeboy rig check`'s dry-run validates
cleanly without it (no theme mount at all in that mode).

## Excluded components (documented, not silent)

`components.json`'s `excludedComponents` list is the honest inventory of
production plugins this rig does **not** mount by default, and why:

| Slug | Site(s) in production | Reason |
| --- | --- | --- |
| `wp-codebox` | network | This rig runs the whole network inside a WP Codebox sandbox; mounting the WP Codebox WordPress companion plugin inside its own disposable sandbox is self-referential and out of scope. |
| `chubes-gallery-lightbox` | network | No GitHub Release and not on WordPress.org -- no zip to source. |
| `wp-coding-agents-integration` | extrachill.com | Agent-runtime development tooling, not network product code. |
| `intelligence` | studio.extrachill.com | Private repository with no public source (NETWORK-ARCHITECTURE.MD: "the installed repository is not publicly linkable"). |
| `mediavine-control-panel` | extrachill.com, events.extrachill.com, wire.extrachill.com | Proprietary vendor plugin with no public zip. |
| `redis-cache` | network | Requires a Redis server the disposable runtime does not run. Its activation hook (which the rig now fires for real) installs the `object-cache.php` drop-in; with no Redis reachable, every persistent option read fails through to defaults and the boot breaks. Production runs Redis; the sandbox documents the exclusion instead of pretending. |

Force-include one with `extrachill_include_excluded_components` +
`extrachill_component_source_overrides` if you have a private/local source for
it.

## Boundary

Like `wordpress-multisite-e2e`, this rig does not claim cross-domain cookie
parity -- `crossDomainCookieParity` is `"not-claimed"` in wp-codebox's own
site-seed topology evidence, and production doesn't rely on it either (cross-site
auth goes through auth.extrachill.com / wp-native Auth, not a shared session
cookie; there is no `COOKIE_DOMAIN` constant in production's `wp-config.php`).
`extrachill.link`'s homepage is expected to return either 200 or 404 (see
`network-topology.json`'s `expectedHomepageStatusNote`): `extrachill-artist-platform`
renders it through link-page routing that requires a provisioned link page, so
a brand-new disposable site with no seeded content 404s exactly like an
unclaimed slug would in production.
