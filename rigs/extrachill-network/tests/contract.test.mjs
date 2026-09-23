/**
 * Env-independent contract test for the extrachill-network rig's recipe
 * builder. No live WP Codebox infrastructure is touched here; this only
 * asserts the shape of the generated wp-codebox/workspace-recipe/v1 document.
 */
import { strict as assert } from 'node:assert';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildRecipe } from '../run.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const topology = JSON.parse(await readFile(path.join(packageRoot, 'network-topology.json'), 'utf8'));
const components = JSON.parse(await readFile(path.join(packageRoot, 'components.json'), 'utf8'));

const temporary = await mkdtemp(path.join(os.tmpdir(), 'extrachill-network-contract-'));
const themeDir = path.join(temporary, 'extrachill-theme');

try {
  await mkdir(themeDir, { recursive: true });
  await writeFile(path.join(themeDir, 'style.css'), '/*\nTheme Name: Extra Chill\n*/\n');
  await writeFile(path.join(themeDir, 'index.php'), '<?php\n');

  // 1. No theme source supplied: recipe still builds (dry-run/check must not
  // require a local checkout of the theme repo), just without a theme mount.
  const withoutTheme = await buildRecipe({}, packageRoot);
  assert.equal(withoutTheme.schema, 'wp-codebox/workspace-recipe/v1');
  assert.equal(withoutTheme.runtime.backend, 'wordpress');
  assert.equal(withoutTheme.inputs.mounts, undefined);
  assert.equal(withoutTheme.inputs.extra_themes, undefined);
  assert.equal(withoutTheme.inputs.siteSeeds.length, 1);
  const seed = withoutTheme.inputs.siteSeeds[0];
  assert.equal(seed.type, 'parent_site');
  assert.equal(seed.bootstrap.multisite.enabled, true);
  assert.equal(seed.bootstrap.multisite.install, 'subdomain');
  assert.equal(seed.bootstrap.multisite.sites.length, topology.sites.length);
  assert.equal(seed.bootstrap.domains.filter((domain) => domain.primary).length, 1);
  assert.equal(withoutTheme.inputs.extra_plugins.length, components.plugins.length);
  for (const plugin of withoutTheme.inputs.extra_plugins) {
    assert.equal(plugin.activate, false, `${plugin.slug} must mount without wp-codebox's own activation; the rig activates plugins itself per-site`);
    assert.match(plugin.source, /^https:\/\//, `${plugin.slug} must default to a remote source`);
  }
  assert.equal(withoutTheme.workflow.steps.length, 2 + topology.sites.length, 'activate + assert + browser-probe-per-site (no theme step when extrachill_theme_source is unset)');

  // 2. Local theme checkout path: mounts as a directory, adds a theme
  // activation workflow step (matching wordpress-multisite-e2e's own pattern).
  const withLocalTheme = await buildRecipe({ extrachill_theme_source: themeDir }, packageRoot);
  assert.equal(withLocalTheme.inputs.mounts.length, 1);
  assert.equal(withLocalTheme.inputs.mounts[0].source, themeDir);
  assert.equal(withLocalTheme.inputs.extra_themes, undefined);
  assert.ok(withLocalTheme.workflow.steps.some((step) => step.metadata?.kind === 'wordpress-theme-activation'));

  // 3. Remote https zip theme source: forward-compatible inputs.extra_themes
  // (Automattic/wp-codebox#2519). No local mount, no activation step needed
  // since wp-codebox's own extra_themes activation handles it.
  const withRemoteTheme = await buildRecipe({ extrachill_theme_source: 'https://github.com/Extra-Chill/extrachill/releases/latest/download/extrachill.zip' }, packageRoot);
  assert.equal(withRemoteTheme.inputs.mounts, undefined);
  assert.equal(withRemoteTheme.inputs.extra_themes.length, 1);
  assert.equal(withRemoteTheme.inputs.extra_themes[0].activate, true);
  assert.ok(!withRemoteTheme.workflow.steps.some((step) => step.metadata?.kind === 'wordpress-theme-activation'));

  // 4. Component source overrides and a release-set pin change the resolved
  // source without touching the checked-in default manifest.
  const overridden = await buildRecipe({
    extrachill_component_source_overrides: { 'extrachill-network': '/abs/local/extrachill-network' },
    extrachill_release_set: { 'extrachill-users': { ref: 'v0.42.8' } },
  }, packageRoot);
  const overriddenNetwork = overridden.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-network');
  assert.equal(overriddenNetwork.source, '/abs/local/extrachill-network');
  const pinnedUsers = overridden.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-users');
  assert.equal(pinnedUsers.source, 'https://github.com/Extra-Chill/extrachill-users/releases/download/v0.42.8/extrachill-users.zip');

  // 5. Excluded components (no public source) are not mounted by default and
  // require both the opt-in flag and an explicit override to appear.
  assert.ok(!withoutTheme.inputs.extra_plugins.some((plugin) => plugin.slug === 'intelligence'));
  const withIntelligence = await buildRecipe({
    extrachill_include_excluded_components: ['intelligence'],
    extrachill_component_source_overrides: { intelligence: '/abs/local/intelligence' },
  }, packageRoot);
  assert.ok(withIntelligence.inputs.extra_plugins.some((plugin) => plugin.slug === 'intelligence' && plugin.source === '/abs/local/intelligence'));

  // 6. wp.org sources hit the always-allowed default download host with no
  // extra WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS configuration required.
  const woocommerce = withoutTheme.inputs.extra_plugins.find((plugin) => plugin.slug === 'woocommerce');
  assert.equal(woocommerce.source, 'https://downloads.wordpress.org/plugin/woocommerce.zip');

  // 7. Every browser-probe step targets a real declared site domain and
  // blocks all other network egress.
  const probes = withoutTheme.workflow.steps.filter((step) => step.metadata?.kind === 'extrachill-network-baseline-page-load');
  assert.equal(probes.length, topology.sites.length);
  for (const site of topology.sites) {
    const probe = probes.find((step) => step.metadata.domain === site.domain);
    assert.ok(probe, `missing baseline browser probe for ${site.domain}`);
    assert.ok(probe.args.includes(`route-host=${site.domain}`));
    assert.ok(probe.args.includes('network-policy=block'));
  }

  console.log('extrachill-network rig contract ok');
} finally {
  await rm(temporary, { recursive: true, force: true });
}
