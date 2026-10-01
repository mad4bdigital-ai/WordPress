import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../../../../tools/connection-edge-acceptance.mjs', import.meta.url));
const origin = 'https://staging.example.com';
const issuer = origin + '/oauth/mcp';
const fingerprint = 'a'.repeat(64);
const protectedMetadata = origin + '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt';
const fixture = [
  { url: protectedMetadata, status: 200, body: { resource: origin + '/wp-json/mcp/mad4b-chatgpt', authorization_servers: [issuer], mad4b_connection_fingerprint: fingerprint } },
  { url: origin + '/.well-known/oauth-authorization-server/oauth/mcp', status: 200, body: { issuer, jwks_uri: issuer + '/jwks', token_endpoint: issuer + '/token', authorization_endpoint: issuer + '/authorize', code_challenge_methods_supported: ['S256'], mad4b_connection_fingerprint: fingerprint } },
  { url: issuer + '/jwks', status: 200, body: { keys: [{ kty: 'RSA', n: 'public-only', e: 'AQAB' }] } },
  { url: origin + '/wp-json/mcp/mad4b-chatgpt', status: 401, body: { error: 'unauthorized' }, headers: { 'www-authenticate': `Bearer resource_metadata="${protectedMetadata}"` } },
];
const preload = `
  const fixture = JSON.parse(process.env.MAD4B_EDGE_FIXTURE);
  let i = 0;
  globalThis.fetch = async (url) => {
    const row = fixture[i++];
    if (!row || row.url !== String(url)) throw new Error('Unexpected outbound request');
    if (row.network_error) throw Object.assign(new Error('fixture network failure'), { cause: { code: row.network_error } });
    const body = row.body === '__oversized' ? 'a'.repeat(270000) : typeof row.body === 'string' ? row.body : JSON.stringify(row.body);
    return new Response(body, { status: row.status, headers: { 'content-type': row.content_type || 'application/json', ...(row.headers || {}) } });
  };
`;
function run(rows, expected = fingerprint) {
  const result = spawnSync(process.execPath, ['--import', 'data:text/javascript,' + encodeURIComponent(preload), script, origin], { encoding: 'utf8', env: { ...process.env, MAD4B_EDGE_FIXTURE: JSON.stringify(rows), MAD4B_EXPECTED_CONNECTION_FINGERPRINT: expected } });
  assert.equal(result.error, undefined);
  assert.ok(result.stdout.trim(), result.stderr);
  return { code: result.status, report: JSON.parse(result.stdout) };
}
const baseline = run(fixture); assert.equal(baseline.report.ready, true, JSON.stringify(baseline));
for (const [label, mutate] of [
  ['wrong resource', f => { f[0].body.resource += '/wrong'; }],
  ['wrong issuer', f => { f[1].body.issuer = 'https://foreign.example.com/oauth/mcp'; }],
  ['wrong token path', f => { f[1].body.token_endpoint = origin + '/foreign-token'; }],
  ['wrong authorize path', f => { f[1].body.authorization_endpoint = origin + '/foreign-authorize'; }],
  ['PKCE downgrade', f => { f[1].body.code_challenge_methods_supported = ['plain']; }],
  ['private JWKS', f => { f[2].body.keys[0].d = 'private'; }],
  ['empty key', f => { f[2].body.keys = [{}]; }],
  ['wrong challenge', f => { f[3].headers['www-authenticate'] = 'Bearer resource_metadata="https://wrong.example.com"'; }],
  ['unrelated 200 route', f => { f[3].status = 200; }],
  ['HTML challenge', f => { f[0].body = '<html>Cloudflare</html>'; f[0].content_type = 'text/html'; }],
  ['wrong content type', f => { f[0].content_type = 'text/plain'; }],
  ['redirect', f => { f[0].status = 302; f[0].headers = { location: origin + '/wp-login.php' }; }],
  ['response budget', f => { f[0].body = '__oversized'; }],
  ['DNS failure', f => { f[0].network_error = 'ENOTFOUND'; }],
]) {
  const changed = structuredClone(fixture);
  mutate(changed);
  const result = run(changed);
  assert.equal(result.code, 1, label);
  assert.equal(result.report.ready, false, label);
}
assert.equal(run(fixture, 'b'.repeat(64)).report.ready, false);
assert.equal(run(fixture, '').report.ready, false);
console.log('external connection edge acceptance runtime: PASS (17 cases)');
