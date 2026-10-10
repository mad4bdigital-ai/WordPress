#!/usr/bin/env node
/**
 * Make/n8n/Zapier/BitFlows/Node.js alternative to Google Apps Script.
 * Input: a JSON file containing the exact IMP01 "input" object (max 500 rows).
 * Environment: MAD4B_INTAKE_URL (HTTPS exact /wp-json/.../intake),
 * MAD4B_SITE_UUID, MAD4B_INTAKE_SECRET (min 32 chars), MAD4B_INTAKE_KEY_ID.
 * Run: node signed-generic-webhook-push.mjs ./approved-preview.json
 *
 * This submits a REVIEW snapshot only. No WP All Import job or WordPress
 * post will be mutated. Keep credentials in a managed secret store.
 */
import {createHmac,randomBytes} from 'node:crypto';
import {readFile} from 'node:fs/promises';
const [file] = process.argv.slice(2);
const url = process.env.MAD4B_INTAKE_URL || '';
const secret = process.env.MAD4B_INTAKE_SECRET || '';
const site = process.env.MAD4B_SITE_UUID || '';
const keyId = process.env.MAD4B_INTAKE_KEY_ID || '';
if (!file || !/^https:\/\/[^\s]+\/wp-json\/mad4b\/v1\/activity-import\/intake$/.test(url) ||
    secret.length < 32 || !/^[0-9a-f-]{20,64}$/i.test(site) ||
    !/^[a-z][a-z0-9_-]{2,60}$/.test(keyId))
  throw new Error('Configure exact HTTPS intake, site UUID and managed site secret');
const rawInput = await readFile(file, 'utf8');
if (Buffer.byteLength(rawInput) > 900000)
  throw new Error('Review JSON source exceeds bounded payload');
const input = JSON.parse(rawInput);
if (!input || typeof input !== 'object' || !Array.isArray(input.rows) ||
    !Array.isArray(input.headers) || input.rows.length > 500 ||
    input.headers.length > 80 || typeof input.profile_slug !== 'string')
  throw new Error('Profile-bound bounded input is required');
const packet = JSON.stringify({
  site_uuid: site, source_mode: 'signed_generic_webhook',
  issued_at: Math.floor(Date.now()/1000),
  nonce: randomBytes(24).toString('hex'), input
});
if (Buffer.byteLength(packet) > 1048576) throw new Error('Signed envelope exceeds cap');
const signature = createHmac('sha256',secret).update(packet).digest('hex');
const response = await fetch(url, {
  method: 'POST', redirect: 'error',
  headers: {'Content-Type':'application/json', 'x-mad4b-signature':signature,
            'x-mad4b-key-id':keyId},
  body: packet,
  signal: AbortSignal.timeout(30000)
});
if (!response.ok) throw new Error('Review intake refused: HTTP '+response.status);
// Do not log full response or business data. The approved destination remains
// a separate WordPress admin/WP All Import gate.
const receipt = await response.json();
console.log(JSON.stringify({
  staged: Boolean(receipt.staged),
  plan_sha256: receipt.plan_sha256 || null,
  issue_count: receipt.issue_count || 0,
  provider_writes: 0
}));
