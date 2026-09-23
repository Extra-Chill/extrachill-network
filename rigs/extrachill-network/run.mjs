#!/usr/bin/env node
/**
 * extrachill-network Homeboy rig.
 *
 * Boots the full 11-site Extra Chill subdomain multisite network inside a
 * disposable WP Codebox WordPress Playground sandbox: real per-domain sites
 * (wp_insert_site(), SUBDOMAIN_INSTALL), every network plugin and per-site
 * plugin mounted from its owning repository's release artifact by default,
 * the shared theme, and a per-site activation matrix that matches production.
 *
 * See README.md for the full design rationale, the settings this rig reads,
 * and the documented gaps (theme remote sourcing, a handful of excluded
 * components with no public source).
 */
import { mkdir, mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const packageRoot = path.dirname(fileURLToPath(import.meta.url));

const WP_CODEBOX_MAX_BUFFER_BYTES = 80 * 1024 * 1024;

// Extra Chill's real product zips (WooCommerce, Gutenberg, data-machine, ...)
// comfortably exceed WP Codebox's 25 MB/100 MB/5000-file defaults, which are
// sized for a single consumer plugin under test, not a ~30-component network.
const DEFAULT_DOWNLOAD_ENV = {
  WP_CODEBOX_ALLOW_NETWORK_DOWNLOADS: '1',
  WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS: 'downloads.wordpress.org,github.com,objects.githubusercontent.com,release-assets.githubusercontent.com',
  WP_CODEBOX_MAX_DOWNLOAD_BYTES: String(100 * 1024 * 1024),
  WP_CODEBOX_MAX_EXTRACTED_BYTES: String(400 * 1024 * 1024),
  WP_CODEBOX_MAX_EXTRACTED_FILES: String(20000),
};

async function loadJson(relativePath) {
  return JSON.parse(await readFile(path.join(packageRoot, relativePath), 'utf8'));
}

export async function buildRecipe(settings = {}, cwd = process.cwd()) {
  const topology = await loadJson('network-topology.json');
  const components = await loadJson('components.json');

  const overrides = isRecord(settings.extrachill_component_source_overrides) ? settings.extrachill_component_source_overrides : {};
  const releaseSet = isRecord(settings.extrachill_release_set) ? settings.extrachill_release_set : {};
  const includeExcluded = new Set(Array.isArray(settings.extrachill_include_excluded_components) ? settings.extrachill_include_excluded_components : []);

  const activePlugins = [...components.plugins];
  for (const excluded of components.excludedComponents) {
    if (includeExcluded.has(excluded.slug) && overrides[excluded.slug]) {
      activePlugins.push({ slug: excluded.slug, source: 'override-only', activations: excluded.forcedActivations ?? [] });
    }
  }

  const extraPlugins = activePlugins.map((component) => ({
    source: resolveComponentSource(component, overrides, releaseSet),
    slug: component.slug,
    // wp-codebox validates a single pluginFile exists in the mounted source
    // regardless of activate:false; components whose main file does not
    // follow the <slug>/<slug>.php convention (e.g. the wp.org AI provider
    // plugins ship plugin.php) need it set explicitly. The rig itself
    // activates every declared activation below, not just this one.
    ...(component.activations?.[0]?.pluginFile ? { pluginFile: component.activations[0].pluginFile } : {}),
    activate: false,
    loadAs: 'plugin',
    metadata: { extrachillActivations: component.activations },
  }));

  const themeSource = settings.extrachill_theme_source;
  const theme = buildThemeInputs(components.theme, themeSource);

  const additionalPrepareSteps = recipeArray(settings.wordpress_runtime_prepare_steps, 'wordpress_runtime_prepare_steps');
  const additionalPostSteps = recipeArray(settings.wordpress_runtime_post_steps, 'wordpress_runtime_post_steps');
  const phpVersion = runtimePhpVersion(settings);

  const activationMatrix = buildActivationMatrix(topology, activePlugins, components.excludedComponents);

  const workflowSteps = [
    activatePluginsStep(activationMatrix),
    ...(theme.activateStep ? [theme.activateStep] : []),
    activationAssertionStep(topology, activationMatrix, components.excludedComponents, includeExcluded),
    ...additionalPrepareSteps,
    ...topology.sites.map((site) => browserProbeStep(site)),
    ...additionalPostSteps,
  ];

  return {
    schema: 'wp-codebox/workspace-recipe/v1',
    runtime: {
      backend: 'wordpress',
      ...(settings.wordpress_runtime_version ? { wp: settings.wordpress_runtime_version } : {}),
      ...(phpVersion ? { phpVersion } : {}),
      blueprint: { steps: [] },
      preview: { siteUrl: `http://${topology.sites.find((site) => site.primary)?.domain ?? topology.sites[0].domain}/` },
    },
    inputs: {
      extra_plugins: extraPlugins,
      ...(theme.extraThemes ? { extra_themes: theme.extraThemes } : {}),
      ...(theme.mounts ? { mounts: theme.mounts } : {}),
      siteSeeds: [{
        type: 'parent_site',
        name: 'extrachill-network-topology',
        // parent_site export is not implemented upstream yet (recipe-site-seeds.ts
        // reports this seed as "skipped"), so no data actually crosses from
        // production; this scope only exists to satisfy schema validation's
        // bounded-scope requirement for a parent_site declaration.
        scopes: { options: { names: ['blogname'], maxRecords: 1 } },
        bootstrap: {
          multisite: {
            enabled: true,
            install: topology.install,
            sites: topology.sites.map((site) => ({ domain: site.domain, path: site.path, title: site.title })),
          },
          domains: topology.sites.map((site) => ({ domain: site.domain, path: site.path, primary: Boolean(site.primary) })),
        },
      }],
    },
    workflow: { steps: workflowSteps },
    artifacts: {
      directory: process.env.HOMEBOY_ARTIFACT_ROOT || path.join(cwd, 'artifacts/extrachill-network'),
    },
  };
}

function resolveComponentSource(component, overrides, releaseSet) {
  if (overrides[component.slug]) {
    return overrides[component.slug];
  }
  const pin = releaseSet[component.slug];
  if (component.source === 'wporg') {
    return `https://downloads.wordpress.org/plugin/${component.wporgSlug}.zip`;
  }
  if (component.source === 'github') {
    if (pin?.ref) {
      return `https://github.com/${component.repo}/releases/download/${pin.ref}/${component.asset}`;
    }
    return `https://github.com/${component.repo}/releases/latest/download/${component.asset}`;
  }
  if (component.source === 'override-only') {
    throw new Error(`Component ${component.slug} has no default source; supply extrachill_component_source_overrides.${component.slug}.`);
  }
  throw new Error(`Unknown component source strategy for ${component.slug}: ${component.source}`);
}

function buildThemeInputs(themeComponent, themeSource) {
  if (!themeSource) {
    return { extraThemes: undefined, mounts: undefined, activateStep: undefined };
  }
  const isRemote = /^https:\/\/.*\.zip$/i.test(themeSource.trim());
  if (isRemote) {
    // Forward-compatible with Automattic/wp-codebox#2519 (merged upstream,
    // not yet in the installed wp-codebox CLI as of this rig's authoring --
    // see components.json theme.note). Recipe validation will reject
    // inputs.extra_themes until the installed CLI updates past v0.26.12.
    return {
      extraThemes: [{ source: themeSource, slug: themeComponent.slug, activate: true }],
      mounts: undefined,
      activateStep: undefined,
    };
  }
  return {
    extraThemes: undefined,
    mounts: [{
      type: 'directory',
      source: themeSource,
      target: `/wordpress/wp-content/themes/${themeComponent.slug}`,
      mode: 'readonly',
      metadata: { kind: 'wordpress-theme', slug: themeComponent.slug },
    }],
    activateStep: activateThemeStep(themeComponent.slug),
  };
}

function activateThemeStep(slug) {
  const code = `$theme_slug = ${JSON.stringify(slug)};
if ( ! is_multisite() ) {
\tthrow new RuntimeException( 'Expected a multisite runtime.' );
}
foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $site_id ) {
\tswitch_to_blog( (int) $site_id );
\ttry {
\t\t$theme = wp_get_theme( $theme_slug );
\t\tif ( ! $theme->exists() ) {
\t\t\tthrow new RuntimeException( 'Mounted theme is unavailable: ' . $theme_slug );
\t\t}
\t\tswitch_theme( $theme_slug );
\t\tif ( get_stylesheet() !== $theme_slug ) {
\t\t\tthrow new RuntimeException( 'Unable to activate mounted theme: ' . $theme_slug );
\t\t}
\t} finally {
\t\trestore_current_blog();
\t}
}`;
  return { command: 'wordpress.run-php', args: [`code=${code}`], metadata: { kind: 'wordpress-theme-activation', slug } };
}

/** @returns {{ network: string[], perDomain: Record<string, string[]> }} */
function buildActivationMatrix(topology, activePlugins, excludedComponents) {
  const network = [];
  const perDomain = Object.fromEntries(topology.sites.map((site) => [site.domain, []]));
  for (const component of activePlugins) {
    for (const activation of component.activations ?? []) {
      if (activation.scope === 'network') {
        network.push(activation.pluginFile);
        continue;
      }
      for (const domain of activation.scope) {
        if (!perDomain[domain]) {
          throw new Error(`components.json activation references unknown site domain: ${domain}`);
        }
        perDomain[domain].push(activation.pluginFile);
      }
    }
  }
  return { network, perDomain, excludedComponents };
}

function activatePluginsStep(matrix) {
  const encoded = Buffer.from(JSON.stringify({ network: matrix.network, perDomain: matrix.perDomain }), 'utf8').toString('base64');
  const code = `require_once ABSPATH . 'wp-admin/includes/plugin.php';
$matrix = json_decode(base64_decode('${encoded}'), true);
if (!is_array($matrix)) {
    throw new RuntimeException('extrachill-network activation matrix did not decode.');
}

/**
 * components.json lists plugins independent of each other's "Requires
 * Plugins" headers or runtime dependency checks (WooCommerce before
 * extrachill-shop, etc.). Rather than hand-order every entry (fragile as
 * soon as a component adds a new dependency), retry the whole pending list
 * across multiple passes: WordPress activates whatever's dependencies are
 * already satisfied each pass, and progress strictly increases until either
 * everything activates or a real, non-ordering failure remains.
 */
function extrachill_network_activate_with_retries($plugin_files, $network_wide) {
    $pending = array_values(array_unique($plugin_files));
    $last_errors = array();
    while (count($pending) > 0) {
        $still_pending = array();
        $activated_this_pass = 0;
        foreach ($pending as $plugin_file) {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                throw new RuntimeException('extrachill-network: plugin file missing after mount: ' . $plugin_file);
            }
            $already_active = $network_wide ? is_plugin_active_for_network($plugin_file) : is_plugin_active($plugin_file);
            if ($already_active) {
                $activated_this_pass++;
                continue;
            }
            $result = activate_plugin($plugin_file, '', $network_wide, true);
            if (is_wp_error($result)) {
                $last_errors[$plugin_file] = $result->get_error_message();
                $still_pending[] = $plugin_file;
                continue;
            }
            $activated_this_pass++;
        }
        if ($activated_this_pass === 0) {
            $details = array();
            foreach ($still_pending as $plugin_file) {
                $details[] = $plugin_file . ': ' . ($last_errors[$plugin_file] ?? 'unknown error');
            }
            throw new RuntimeException('extrachill-network: could not activate (dependency ordering or a real failure): ' . implode('; ', $details));
        }
        $pending = $still_pending;
    }
}

extrachill_network_activate_with_retries($matrix['network'], true);
foreach (get_sites(array('number' => 0)) as $site) {
    switch_to_blog((int) $site->blog_id);
    try {
        $plugin_files = $matrix['perDomain'][$site->domain] ?? array();
        try {
            extrachill_network_activate_with_retries($plugin_files, false);
        } catch (RuntimeException $e) {
            throw new RuntimeException($site->domain . ': ' . $e->getMessage());
        }
    } finally {
        restore_current_blog();
    }
}
echo wp_json_encode(array('activated' => true));`;
  return { command: 'wordpress.run-php', args: [`code=${code}`], metadata: { kind: 'extrachill-network-activation' } };
}

function activationAssertionStep(topology, matrix, excludedComponents, includeExcluded) {
  const excludedSlugs = excludedComponents.filter((excluded) => !includeExcluded.has(excluded.slug)).map((excluded) => ({ slug: excluded.slug, scope: excluded.scope, reason: excluded.reason }));
  const expected = {
    network: matrix.network,
    perDomain: matrix.perDomain,
    excludedComponents: excludedSlugs,
    expectedHomepageStatus: Object.fromEntries(topology.sites.map((site) => [site.domain, site.expectedHomepageStatus ?? [200]])),
  };
  const encoded = Buffer.from(JSON.stringify(expected), 'utf8').toString('base64');
  const code = `require_once ABSPATH . 'wp-admin/includes/plugin.php';
$expected = json_decode(base64_decode('${encoded}'), true);
$report = array('sites' => array(), 'network' => array(), 'excludedComponents' => $expected['excludedComponents']);
$active_network = array_keys(get_site_option('active_sitewide_plugins', array()));
sort($active_network);
$expected_network = $expected['network'];
sort($expected_network);
$report['network'] = array(
    'expected' => $expected_network,
    'actual' => $active_network,
    'missing' => array_values(array_diff($expected_network, $active_network)),
    'unexpected' => array_values(array_diff($active_network, $expected_network)),
);
if (!empty($report['network']['missing'])) {
    throw new RuntimeException('extrachill-network: network plugins not active after setup: ' . implode(', ', $report['network']['missing']));
}
foreach (get_sites(array('number' => 0)) as $site) {
    switch_to_blog((int) $site->blog_id);
    try {
        $active = get_option('active_plugins', array());
        sort($active);
        $expected_site = $expected['perDomain'][$site->domain] ?? array();
        sort($expected_site);
        $missing = array_values(array_diff($expected_site, $active));
        $unexpected = array_values(array_diff($active, $expected_site));
        $response = wp_remote_get(home_url('/'), array('timeout' => 30, 'sslverify' => false));
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
        $fatal = is_string($body) && (str_contains($body, 'Fatal error') || str_contains($body, 'There has been a critical error'));
        $expected_status = $expected['expectedHomepageStatus'][$site->domain] ?? array(200);
        $report['sites'][$site->domain] = array(
            'theme' => get_stylesheet(),
            'httpStatus' => $status,
            'expectedHttpStatus' => $expected_status,
            'fatalMarkerInBody' => $fatal,
            'expectedActivePlugins' => $expected_site,
            'actualActivePlugins' => $active,
            'missingPlugins' => $missing,
            'unexpectedActivePlugins' => $unexpected,
        );
        if (!empty($missing)) {
            throw new RuntimeException('extrachill-network: ' . $site->domain . ' missing expected plugins: ' . implode(', ', $missing));
        }
        if (!in_array($status, $expected_status, true)) {
            throw new RuntimeException('extrachill-network: ' . $site->domain . ' anonymous home request returned HTTP ' . $status . ' (expected one of: ' . implode(',', $expected_status) . ')');
        }
        if ($fatal) {
            throw new RuntimeException('extrachill-network: ' . $site->domain . ' anonymous home request contains a fatal-error marker.');
        }
    } finally {
        restore_current_blog();
    }
}
echo wp_json_encode(array('schema' => 'extrachill-network/activation-report/v1', 'report' => $report));`;
  return { command: 'wordpress.run-php', args: [`code=${code}`], metadata: { kind: 'extrachill-network-assertion' } };
}

function browserProbeStep(site) {
  // A site whose expectedHomepageStatus tolerates non-200 (see
  // network-topology.json for why extrachill.link is one) will legitimately
  // log a "failed to load resource" console entry for the document response
  // itself; only assert no-console-errors where 200 is the sole expectation.
  const expectedStatus = site.expectedHomepageStatus ?? [200];
  const strictStatus = expectedStatus.length === 1 && expectedStatus[0] === 200;
  return {
    command: 'wordpress.browser-probe',
    args: [
      `url=http://${site.domain}/`,
      `route-host=${site.domain}`,
      'network-policy=block',
      `allow-host=${site.domain}`,
      ...(strictStatus ? ['assert=no-console-errors'] : []),
      'assert=no-page-errors',
      'capture=console,errors,html,network,screenshot',
    ],
    metadata: { kind: 'extrachill-network-baseline-page-load', domain: site.domain },
  };
}

// Production runs PHP 8.4.x (verified read-only via `wp eval 'echo PHP_VERSION;'`
// on extrachill.com, 2026-09-23) and several network plugins (e.g.
// extrachill-api) declare "Requires PHP: 8.4" and refuse to activate on
// WP Codebox's own 8.3 default runtime. Default to 8.4; callers can still pin
// a different supported version.
const DEFAULT_PHP_VERSION = '8.4';

function runtimePhpVersion(settings) {
  if (!Object.hasOwn(settings, 'wordpress_runtime_php_version')) {
    return DEFAULT_PHP_VERSION;
  }
  const value = settings.wordpress_runtime_php_version;
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error('wordpress_runtime_php_version must be a non-empty PHP major.minor version.');
  }
  return value.trim();
}

function recipeArray(value, label) {
  if (value === undefined) {
    return [];
  }
  if (!Array.isArray(value)) {
    throw new Error(`${label} must be an array.`);
  }
  return value;
}

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

async function main() {
  const dryRun = process.argv.includes('--dry-run');
  const settings = parseSettings(process.env.HOMEBOY_SETTINGS_JSON);
  const recipe = await buildRecipe(settings);
  const temporary = await mkdtemp(path.join(os.tmpdir(), 'extrachill-network-'));
  const recipePath = path.join(temporary, 'recipe.json');
  const resultPath = process.env.HOMEBOY_NETWORK_RESULT_FILE || path.join(temporary, 'result.json');
  await writeFile(recipePath, `${JSON.stringify(recipe, null, 2)}\n`);

  try {
    runCodebox(['recipe', 'validate', '--recipe', recipePath, '--json']);
    const args = ['recipe-run', '--recipe', recipePath, '--artifacts', recipe.artifacts.directory, '--json'];
    if (dryRun) {
      args.push('--dry-run');
    }
    const result = runCodebox(args, true);
    await mkdir(path.dirname(resultPath), { recursive: true });
    await writeFile(resultPath, result.stdout);
    const envelope = JSON.parse(result.stdout);
    if (!dryRun && envelope.success !== true) {
      throw new Error('WP Codebox extrachill-network recipe did not succeed.');
    }
    process.stdout.write(result.stdout);
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
}

export function runCodebox(args, capture = false) {
  const executable = process.env.HOMEBOY_WP_CODEBOX_BIN || process.env.WP_CODEBOX_BIN || 'wp-codebox';
  const maxBuffer = codeboxMaxBuffer();
  const env = { ...DEFAULT_DOWNLOAD_ENV, ...process.env };
  const result = spawnSync(executable, args, { encoding: 'utf8', stdio: capture ? 'pipe' : 'inherit', maxBuffer, env });
  if (result.error) {
    result.error.stdout = result.stdout || '';
    result.error.stderr = result.stderr || '';
    result.error.maxBuffer = maxBuffer;
    throw result.error;
  }
  if (capture && result.stderr) {
    process.stderr.write(result.stderr);
  }
  if (result.status !== 0) {
    const error = new Error(`WP Codebox exited with status ${result.status}.`);
    error.stdout = result.stdout || '';
    error.stderr = result.stderr || '';
    error.status = result.status;
    throw error;
  }
  return result;
}

function codeboxMaxBuffer() {
  const raw = process.env.HOMEBOY_WP_CODEBOX_MAX_BUFFER_BYTES;
  if (raw === undefined || raw === '') {
    return WP_CODEBOX_MAX_BUFFER_BYTES;
  }
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new Error('HOMEBOY_WP_CODEBOX_MAX_BUFFER_BYTES must be a positive integer.');
  }
  return value;
}

function parseSettings(raw) {
  if (!raw) {
    return {};
  }
  const value = JSON.parse(raw);
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('HOMEBOY_SETTINGS_JSON must contain a JSON object.');
  }
  return value;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
