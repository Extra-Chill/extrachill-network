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

import { buildRecipe, domainIdsMuPluginSource, DOMAIN_IDS_MU_PLUGIN_FILENAME, journeySelection, validateJourneyDocument } from '../run.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const topology = JSON.parse(await readFile(path.join(packageRoot, 'network-topology.json'), 'utf8'));
const components = JSON.parse(await readFile(path.join(packageRoot, 'components.json'), 'utf8'));

const temporary = await mkdtemp(path.join(os.tmpdir(), 'extrachill-network-contract-'));
const themeDir = path.join(temporary, 'extrachill-theme');
const artifactsDir = path.join(temporary, 'artifacts');
process.env.HOMEBOY_ARTIFACT_ROOT = artifactsDir;

try {
  await mkdir(themeDir, { recursive: true });
  await writeFile(path.join(themeDir, 'style.css'), '/*\nTheme Name: Extra Chill\n*/\n');
  await writeFile(path.join(themeDir, 'index.php'), '<?php\n');

  // 1. No theme source supplied: recipe still builds (dry-run/check must not
  // require a local checkout of the theme repo), just without a theme mount.
  const withoutTheme = await buildRecipe({}, packageRoot);
  assert.equal(withoutTheme.schema, 'wp-codebox/workspace-recipe/v1');
  assert.equal(withoutTheme.runtime.backend, 'wordpress');
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

  // 1b. The domain-ID mu-plugin is mounted on every boot: file mount exists,
  // the source was generated into the artifacts root, and the generated PHP
  // covers every topology domain with a constant mapping.
  const muMount = withoutTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'extrachill-network-domain-ids');
  assert.ok(muMount, 'the generated EC_BLOG_ID mu-plugin must be mounted on every boot');
  assert.equal(muMount.type, 'file');
  assert.match(muMount.target, /^\/wordpress\/wp-content\/mu-plugins\//);
  const muSource = await readFile(muMount.source, 'utf8');
  assert.match(muSource, /events\.extrachill\.com'\s+=>\s+'EC_BLOG_ID_EVENTS'/);
  for (const site of topology.sites) {
    assert.ok(
      muSource.includes(`'${site.domain}' => 'EC_BLOG_ID_`),
      `generated mu-plugin must map topology domain ${site.domain}`,
    );
  }
  assert.match(muSource, /WP_INSTALLING/, 'mu-plugin must skip while installing');

  // 1c. domainIdsMuPluginSource refuses a topology that dropped a mapped domain.
  assert.throws(
    () => domainIdsMuPluginSource({ sites: topology.sites.filter((site) => site.domain !== 'events.extrachill.com') }),
    /events\.extrachill\.com/,
  );

  // 1d. journeySelection validates its shape.
  assert.deepEqual(journeySelection({}), []);
  assert.deepEqual(journeySelection({ extrachill_journeys: ['gardner-event-rsvp'] }), ['gardner-event-rsvp']);
  assert.throws(() => journeySelection({ extrachill_journeys: 'gardner-event-rsvp' }), /array/);
  assert.throws(() => journeySelection({ extrachill_journeys: [42] }), /non-empty journey ID/);
  assert.throws(() => journeySelection({ extrachill_journeys: ['a', 'a'] }), /more than once/);

  // 2. Local theme checkout path: mounts as a directory, adds a theme
  // activation workflow step (matching wordpress-multisite-e2e's own pattern).
  const withLocalTheme = await buildRecipe({ extrachill_theme_source: themeDir }, packageRoot);
  const themeMount = withLocalTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme');
  assert.ok(themeMount, 'local theme checkout mounts as a directory beside the mu-plugin');
  assert.equal(themeMount.source, themeDir);
  assert.equal(withLocalTheme.inputs.extra_themes, undefined);
  assert.ok(withLocalTheme.workflow.steps.some((step) => step.metadata?.kind === 'wordpress-theme-activation'));

  // 3. Remote https zip theme source: forward-compatible inputs.extra_themes
  // (Automattic/wp-codebox#2519). No local mount, no activation step needed
  // since wp-codebox's own extra_themes activation handles it.
  const withRemoteTheme = await buildRecipe({ extrachill_theme_source: 'https://github.com/Extra-Chill/extrachill/releases/latest/download/extrachill.zip' }, packageRoot);
  assert.equal(withRemoteTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme'), undefined);
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

  // 8. Journey contract: selecting a journey appends seed -> steps -> grade
  // after the baseline, merges its runtimeEnv, and stamps every step.
  const withJourney = await buildRecipe({ extrachill_journeys: ['gardner-event-rsvp'] }, packageRoot);
  const journeyMeta = withJourney.metadata?.extrachillJourneys;
  assert.deepEqual(journeyMeta, [{ id: 'gardner-event-rsvp', persona: 'extra-chill-users/chris-gardner', sites: ['events.extrachill.com', 'extrachill.com'] }]);
  assert.equal(withJourney.inputs.runtimeEnv?.WP_AGENT_RUNTIME, '1', 'journey runtimeEnv merges into the recipe inputs');
  assert.equal(withoutTheme.inputs.runtimeEnv, undefined, 'no runtimeEnv when no journey is selected');

  const kinds = withJourney.workflow.steps.map((step) => step.metadata?.kind ?? 'baseline');
  const firstJourneyIndex = kinds.indexOf('journey-seed');
  assert.ok(firstJourneyIndex > 0, 'journey seed step exists');
  assert.equal(kinds[kinds.indexOf('extrachill-network-assertion')], 'extrachill-network-assertion');
  assert.ok(firstJourneyIndex > kinds.lastIndexOf('extrachill-network-baseline-page-load'), 'journeys run after the full baseline');
  const gradeIndex = kinds.lastIndexOf('journey-grade');
  assert.ok(gradeIndex > firstJourneyIndex, 'grade runs after the journey steps');
  for (let i = firstJourneyIndex + 1; i < gradeIndex; ++i) {
    assert.equal(withJourney.workflow.steps[i].metadata.journey, 'gardner-event-rsvp', `step ${i} belongs to the journey`);
  }

  const seedStep = withJourney.workflow.steps[firstJourneyIndex];
  assert.equal(seedStep.command, 'wordpress.run-php');
  assert.match(seedStep.args[0], /^code-file=/, 'seed runs a code-file from the journey directory');
  const gradeStep = withJourney.workflow.steps[gradeIndex];
  assert.equal(gradeStep.command, 'wordpress.run-php');
  assert.match(gradeStep.args[0], /^code-file=/);

  // 8b. Journey browser steps are domain-scoped: absolute URLs on declared
  // sites, route-host/allow-host within the journey's sites, no egress leaks.
  const journeyBrowserSteps = withJourney.workflow.steps.filter((step) => step.metadata?.kind === 'journey-browser-step');
  assert.ok(journeyBrowserSteps.length >= 5, 'the gardner-event-rsvp journey ships multiple browser steps');
  for (const step of journeyBrowserSteps) {
    const urlArg = step.args.find((arg) => arg.startsWith('url='));
    assert.ok(urlArg, 'journey browser step carries an absolute url');
    const host = new URL(urlArg.slice('url='.length)).host;
    assert.ok(['events.extrachill.com', 'extrachill.com'].includes(host), `journey step targets declared site, got ${host}`);
    assert.ok(step.args.some((arg) => arg === `route-host=${host}`), 'journey step pins route-host to the url host');
    assert.ok(step.args.includes('network-policy=block'), 'journey step blocks other network egress');
  }

  // 8c. Unknown journeys, foreign domains, and missing persona files fail loudly.
  await assert.rejects(
    () => buildRecipe({ extrachill_journeys: ['nope'] }, packageRoot),
    /Unknown extrachill_journeys entry 'nope'/,
  );

  const doc = (overrides = {}) => ({
    schema: 'extrachill-network/journey/v1',
    id: 'gardner-event-rsvp',
    sites: ['events.extrachill.com'],
    persona: { file: 'gardner.v1.json' },
    steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=events.extrachill.com'] }],
    ...overrides,
  });
  await assert.rejects(validateJourneyDocument(doc({ schema: 'other/v1' }), 'gardner-event-rsvp', topology), /must declare schema/);
  await assert.rejects(validateJourneyDocument(doc({ id: 'other' }), 'gardner-event-rsvp', topology), /must match the directory name/);
  await assert.rejects(validateJourneyDocument(doc({ sites: [] }), 'gardner-event-rsvp', topology), /non-empty "sites"/);
  await assert.rejects(validateJourneyDocument(doc({ sites: ['nonexistent.example'] }), 'gardner-event-rsvp', topology), /never by blog ID/);
  await assert.rejects(validateJourneyDocument(doc(), 'gardner-event-rsvp', topology, async () => false), /missing from rigs\/extrachill-network\/personas\//);
  await assert.rejects(validateJourneyDocument(doc({ grade: { codeFile: 'grade.php' } }), 'gardner-event-rsvp', topology, async () => true, async () => false), /grade\.php' does not exist/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=/events/x'] }] }), 'gardner-event-rsvp', topology), /non-absolute url/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=http://artist.extrachill.com/'] }] }), 'gardner-event-rsvp', topology), /outside the journey's declared sites/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=extrachill.com'] }] }), 'gardner-event-rsvp', topology), /route-host/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', metadata: { kind: 'baseline' }, args: [] }] }), 'gardner-event-rsvp', topology), /reserved metadata kind/);

  console.log('extrachill-network rig contract ok');
} finally {
  delete process.env.HOMEBOY_ARTIFACT_ROOT;
  await rm(temporary, { recursive: true, force: true });
}
