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
  "Activation ordering" below).
- **Baseline scenario**: after activation, one assertion step compares actual
  vs. expected active plugins per site and does an internal anonymous
  `wp_remote_get(home_url('/'))` HTTP-status/fatal-marker check; then one
  `wordpress.browser-probe` per site asserts no console/page errors on an
  anonymous page load, with `network-policy=block` scoped to that site's own
  host.

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
| `extrachill_component_source_overrides` | `{ "<slug>": "<local path or URL>" }`. Overrides one component's mount source, e.g. to test an unreleased branch of `extrachill-network` itself. |
| `extrachill_release_set` | `{ "<slug>": { "ref": "<tag>" } }`. Pins a GitHub-hosted component to an explicit release tag instead of `latest`. There is no resolver that turns a `homeboy/release-set/v1` manifest into these entries yet -- a caller wanting deploy-parity pinning composes this map itself (see extrachill-network#223's spike notes on why `release-set/v1` is a gate, not a resolver). |
| `extrachill_include_excluded_components` | Array of slugs from `components.json`'s `excludedComponents` list to force-include; requires a matching `extrachill_component_source_overrides` entry (no default source exists for those). |
| `wordpress_runtime_php_version` | Overrides the default `8.4` (production's actual PHP major.minor, verified read-only via `wp eval 'echo PHP_VERSION;'`). |
| `wordpress_runtime_prepare_steps` / `wordpress_runtime_post_steps` | Consumer-owned recipe steps inserted before/after this rig's own workflow (same contract as `wordpress-multisite-e2e`). |

## Consumer CI: adding a network scenario

A consumer repo (e.g. extrachill-events replacing `booking-network-e2e.yml`)
needs about 10 lines, not 140:

```yaml
- name: Boot the Extra Chill network
  run: |
    homeboy rig install https://github.com/Extra-Chill/.github
    HOMEBOY_SETTINGS_JSON='{
      "extrachill_theme_source": "'"$THEME_CHECKOUT_PATH"'",
      "extrachill_component_source_overrides": { "extrachill-events": "'"$PWD"'" },
      "wordpress_runtime_post_steps": [{"command":"wordpress.browser-scenario","args":["scenario-json=@my-booking-journey.json","route-host=events.extrachill.com","network-policy=block","allow-host=events.extrachill.com"]}]
    }' homeboy rig up extrachill-network
```

`extrachill_component_source_overrides` points the consumer's own component at
`$PWD` (the checked-out worktree under test) instead of its released zip --
this is how a consumer reproduces a cross-plugin fatal against a real network
rather than a synthetic single-plugin fixture. `wordpress_runtime_post_steps`
layers the consumer's own scenario on top of this rig's baseline; it runs
after every site's activation and baseline page-load assertion.

The same recipe works from Homeboy's `rig:` action input / a reusable
workflow wrapper wherever the calling repo's CI already uses `homeboy-action`;
nothing here is CLI-specific.

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

`wp_codebox_extra_themes` / `inputs.extra_themes` only accepts a remote
`https://…zip` source as of
[Automattic/wp-codebox#2519](https://github.com/Automattic/wp-codebox/pull/2519)
(merged into wp-codebox `main` 2026-09-21 as v0.27.0, itself born from
[homeboy-extensions#2857](https://github.com/Extra-Chill/homeboy-extensions/issues/2857)
filed during this rig's own spike). **The wp-codebox CLI installed on this
host as of authoring is still v0.26.12** -- `packages/cli/dist` is 5 commits
behind `origin/main` and only accepts an absolute local directory path for a
theme mount.

`run.mjs` auto-detects which mode to use from `extrachill_theme_source`:

- An `https://…zip` URL is passed straight through to `inputs.extra_themes`.
  This is forward-compatible and will start working the moment the installed
  CLI updates past v0.26.12 -- **no rig code change needed**, only flip the
  setting. Today it fails recipe validation with a clear schema error on the
  currently-installed CLI, which is the correct, honest failure mode for an
  unsupported input.
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
