/**
 * Contract validation for the canonical Gardner test persona, now pinned
 * (and canonical) in this rig's own personas/ directory
 * (extrachill-network#293). Ported from extrachill-users
 * tests/js/gardner-persona.test.js, which used Jest + Ajv; neither is a
 * dependency of this repo, so this is a plain node:test file with a small
 * self-contained validator for the subset of JSON Schema draft-07
 * gardner.schema.json actually uses (type, const, required, properties,
 * additionalProperties, pattern, minItems, uniqueItems, items, enum,
 * minLength) -- adding Ajv as a new dependency of a repo with no existing
 * package.json/node_modules would be a bigger footprint than this schema
 * warrants.
 */
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const personaPath = path.join(root, '../personas/gardner.v1.json');
const schemaPath = path.join(root, '../personas/gardner.schema.json');
const personaSource = readFileSync(personaPath, 'utf8');
const persona = JSON.parse(personaSource);
const schema = JSON.parse(readFileSync(schemaPath, 'utf8'));

/**
 * Validate a value against the small subset of JSON Schema draft-07 this
 * repo's persona schema actually uses. Throws with a descriptive message
 * (not a boolean) on the first violation, which is enough for one fixed,
 * hand-authored contract file.
 */
function validateAgainstSchema(value, node, at = '$') {
  if (node.const !== undefined) {
    assert.deepEqual(value, node.const, `${at} must equal ${JSON.stringify(node.const)}, got ${JSON.stringify(value)}`);
    return;
  }
  if (node.enum !== undefined) {
    assert.ok(node.enum.includes(value), `${at} must be one of ${JSON.stringify(node.enum)}, got ${JSON.stringify(value)}`);
    return;
  }
  if (node.type === 'object') {
    assert.equal(typeof value, 'object');
    assert.ok(value !== null && !Array.isArray(value), `${at} must be an object`);
    for (const key of node.required ?? []) {
      assert.ok(Object.hasOwn(value, key), `${at} is missing required property "${key}"`);
    }
    if (node.additionalProperties === false) {
      const allowed = new Set(Object.keys(node.properties ?? {}));
      for (const key of Object.keys(value)) {
        assert.ok(allowed.has(key), `${at} has unexpected property "${key}"`);
      }
    }
    for (const [key, propertySchema] of Object.entries(node.properties ?? {})) {
      if (Object.hasOwn(value, key)) {
        validateAgainstSchema(value[key], propertySchema, `${at}.${key}`);
      }
    }
    return;
  }
  if (node.type === 'array') {
    assert.ok(Array.isArray(value), `${at} must be an array`);
    if (node.minItems !== undefined) {
      assert.ok(value.length >= node.minItems, `${at} must have at least ${node.minItems} items, got ${value.length}`);
    }
    if (node.uniqueItems) {
      const seen = new Set(value.map((entry) => JSON.stringify(entry)));
      assert.equal(seen.size, value.length, `${at} must not contain duplicate items`);
    }
    if (node.items) {
      value.forEach((entry, index) => validateAgainstSchema(entry, node.items, `${at}[${index}]`));
    }
    return;
  }
  if (node.type === 'string') {
    assert.equal(typeof value, 'string', `${at} must be a string`);
    if (node.pattern) {
      assert.ok(new RegExp(node.pattern).test(value), `${at} must match /${node.pattern}/, got ${JSON.stringify(value)}`);
    }
    if (node.minLength !== undefined) {
      assert.ok(value.length >= node.minLength, `${at} must be at least ${node.minLength} characters`);
    }
  }
}

validateAgainstSchema(persona, schema);
console.log('gardner persona matches its versioned schema');

assert.equal(persona.persona_id, 'extra-chill-users/chris-gardner');
assert.equal(persona.contract_version, '1.0.0');
assert.equal(persona.reference_persona.name, 'Chris Gardner');
assert.deepEqual(persona.fixture_identity, {
  username: 'gardner_persona_fixture',
  display_name: 'Chris Gardner (Test Persona)',
  email: 'gardner-persona@example.invalid',
  non_production: true,
});
assert.equal(persona.network_access.team_role, 'extra_chill_team');
assert.deepEqual(persona.network_access.site_membership, {
  scope: 'every-active-network-site',
  role: 'extra_chill_team',
});
for (const capability of ['read', 'upload_files', 'edit_posts', 'access_studio', 'access_events_admin', 'submit_for_review']) {
  assert.ok(persona.network_access.baseline_capabilities.includes(capability), `baseline_capabilities must include "${capability}"`);
}
assert.ok(
  persona.network_access.explicit_user_grants.some((grant) => grant.capability === 'manage_brand_socials' && grant.scope === 'every-active-network-site'),
  'explicit_user_grants must include manage_brand_socials scoped to every-active-network-site',
);
console.log('gardner persona keeps its stable identity and Users-owned access contract');

const oracleIds = persona.oracles.map((oracle) => oracle.id);
assert.equal(new Set(oracleIds).size, oracleIds.length, 'oracle IDs must be unique');
assert.deepEqual(oracleIds, [
  'task-completion',
  'obvious-state',
  'reload-persistence',
  'safe-retry',
  'duplicate-prevention',
  'attribution',
  'actionable-errors',
  'jargon-avoidance',
  'server-authorization',
]);
console.log('gardner persona publishes each stable cross-product oracle exactly once');

assert.ok(persona.fixture_identity.email.endsWith('@example.invalid'));
assert.deepEqual(persona.safety, {
  production_credentials: false,
  tokens: false,
  personal_contact_data: false,
  live_external_writes: false,
});
assert.ok(!/(?:api[_-]?key|password|secret)/i.test(personaSource), 'persona fixture must not mention api keys, passwords, or secrets');
assert.ok(
  !/(?:https?:\/\/|\+?1?[ .-]?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4})/.test(personaSource),
  'persona fixture must not contain a URL or a phone-number-shaped string',
);
console.log('gardner persona contains no live identity material or permitted external side effects');

console.log('gardner persona contract ok');
