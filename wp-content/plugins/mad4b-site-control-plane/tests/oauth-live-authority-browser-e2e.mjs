import { chromium } from 'playwright';

const base = (process.env.MAD4B_BROWSER_BASE_URL || '').replace(/\/$/, '');
const username = process.env.MAD4B_BROWSER_USER || '';
const password = process.env.MAD4B_BROWSER_PASSWORD || '';
if (!base || !username || !password) throw new Error('Browser E2E environment is incomplete.');

const assert = (condition, message, data = null) => {
  if (!condition) throw new Error(message + (data === null ? '' : ' ' + JSON.stringify(data)));
};

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();

const ajaxRequests = [];
let firstProjectionResolve;
let firstProjectionReject;
const firstProjection = new Promise((resolve, reject) => {
  firstProjectionResolve = resolve;
  firstProjectionReject = reject;
});

page.on('request', request => {
  if (!request.url().includes('/wp-admin/admin-ajax.php')) return;
  const postData = request.postData() || '';
  if (!postData.includes('action=mad4b_oauth_grant_projection')) return;
  ajaxRequests.push({
    url: request.url(),
    method: request.method(),
    postData,
  });
});

let firstProjectionDone = false;
page.on('response', async response => {
  const request = response.request();
  const postData = request.postData() || '';
  if (!response.url().includes('/wp-admin/admin-ajax.php') || !postData.includes('action=mad4b_oauth_grant_projection')) return;
  if (firstProjectionDone) return;
  firstProjectionDone = true;
  try {
    const json = await response.json();
    firstProjectionResolve({ response, json });
  } catch (error) {
    firstProjectionReject(error);
  }
});

try {
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForURL(url => url.pathname.includes('/wp-admin/'), { timeout: 10000 }),
    page.locator('#wp-submit').click(),
  ]);
  assert(page.url().includes('/wp-admin/'), 'WordPress browser login failed.', { url: page.url() });

  const params = new URLSearchParams({
    response_type: 'code',
    client_id: 'mad4b-browser-e2e',
    redirect_uri: base + '/callback',
    resource: base + '/wp-json/mcp/mad4b-chatgpt',
    code_challenge: 'A'.repeat(43),
    code_challenge_method: 'S256',
    scope: 'mad4b:read offline_access',
    state: 'mad4b-browser-e2e-state',
  });

  const authorizeResponse = await page.goto(base + '/oauth/mcp/authorize?' + params.toString(), { waitUntil: 'domcontentloaded' });
  assert(authorizeResponse && authorizeResponse.status() === 200, 'OAuth authorize page did not return HTTP 200.', { status: authorizeResponse?.status() });

  await page.locator('#mad4b-live-authority').waitFor({ state: 'visible' });
  const heading = (await page.locator('h1').textContent()) || '';
  assert(heading.includes('Authorize read access'), 'OAuth read-consent heading missing.', { heading });

  const bodyText = (await page.locator('body').innerText()) || '';
  assert(!bodyText.includes('Signed in WordPress user:'), 'Raw numeric WordPress user identity leaked into primary UI.');
  const userLabel = ((await page.locator('#mad4b-oauth-user-label').textContent()) || '').trim();
  assert(userLabel && userLabel !== '1', 'Primary OAuth subject label is not human-readable.', { userLabel });

  const csp = authorizeResponse.headers()['content-security-policy'] || '';
  const nonceMatch = csp.match(/script-src 'nonce-([^']+)'/);
  assert(nonceMatch && nonceMatch[1], 'Consent CSP does not contain a per-request script nonce.', { csp });
  const scriptNonce = await page.locator('script[nonce]').evaluate(el => el.nonce);
  assert(scriptNonce === nonceMatch[1], 'Rendered script nonce does not match CSP nonce.', { scriptNonce, csp });
  assert(csp.includes("connect-src 'self'"), 'Consent CSP lost same-origin connect boundary.', { csp });

  const { response: ajaxResponse, json } = await Promise.race([
    firstProjection,
    new Promise((_, reject) => setTimeout(() => reject(new Error('Timed out waiting for live authority AJAX projection.')), 10000)),
  ]);
  assert(ajaxResponse.status() === 200, 'Live authority AJAX did not return HTTP 200.', { status: ajaxResponse.status() });
  assert(json && json.success && json.data && json.data.projection, 'Live authority AJAX returned an invalid payload.', json);
  const projection = json.data.projection;
  assert(projection.contract === 'mad4b.oauth-consent-grant-projection.v3', 'Browser received an unexpected projection contract.', projection);
  assert(projection.read_only === true && projection.mutation_performed === false, 'Browser projection crossed read-only boundary.', projection);
  assert(projection.oauth_scope_changed === false && projection.write_authority_granted_by_consent === false, 'Browser projection widened OAuth/write authority.', projection);
  assert(projection.projection_consistent === true, 'Browser projection is internally inconsistent.', projection);
  assert(typeof projection.projection_fingerprint === 'string' && projection.projection_fingerprint.length === 64, 'Browser projection fingerprint missing.', projection);

  assert(ajaxRequests.length >= 1, 'No live authority AJAX request was observed.');
  const firstRequest = ajaxRequests[0];
  assert(firstRequest.method === 'POST', 'Live authority refresh must use POST.', firstRequest);
  assert(!firstRequest.url.includes('nonce='), 'Live authority nonce leaked into the request URL.', { url: firstRequest.url });
  assert(firstRequest.postData.includes('nonce='), 'Live authority POST body did not carry the nonce.');
  assert(firstRequest.postData.includes('action=mad4b_oauth_grant_projection'), 'Live authority POST body lost its action.');

  // The fingerprint is unchanged. A focus-triggered refresh may happen, but it
  // must not repaint metrics when the projection fingerprint is identical.
  await page.locator('#mad4b-catalog-count').evaluate(el => { el.textContent = 'FINGERPRINT_SENTINEL'; });
  const beforeFocus = ajaxRequests.length;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await page.waitForFunction(
    expected => window.__mad4bNoop !== expected,
    beforeFocus,
    { timeout: 100 }
  ).catch(() => {});
  const started = Date.now();
  while (ajaxRequests.length <= beforeFocus && Date.now() - started < 5000) await new Promise(r => setTimeout(r, 100));
  assert(ajaxRequests.length > beforeFocus, 'Focus did not trigger an immediate live authority refresh.');
  await page.waitForTimeout(300);
  assert((await page.locator('#mad4b-catalog-count').textContent()) === 'FINGERPRINT_SENTINEL', 'Unchanged projection fingerprint caused an unnecessary DOM repaint.');

  // No legacy five-second polling storm.
  const afterFocus = ajaxRequests.length;
  await page.waitForTimeout(6500);
  assert(ajaxRequests.length === afterFocus, 'Authority dashboard is still polling at the legacy five-second cadence.', { before: afterFocus, after: ajaxRequests.length });

  // Simulate a hidden document: focus events must not trigger network refresh.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const hiddenCount = ajaxRequests.length;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await page.waitForTimeout(1000);
  assert(ajaxRequests.length === hiddenCount, 'Hidden authority dashboard still refreshed on focus.', { hiddenCount, now: ajaxRequests.length });

  // Returning visible must refresh immediately.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const resumeStart = Date.now();
  while (ajaxRequests.length <= hiddenCount && Date.now() - resumeStart < 5000) await new Promise(r => setTimeout(r, 100));
  assert(ajaxRequests.length > hiddenCount, 'Authority dashboard did not refresh immediately after visibility returned.');

  console.log(JSON.stringify({
    contract: 'mad4b.oauth-live-authority-browser-e2e.v1',
    status: 'PASS',
    ajax_requests_observed: ajaxRequests.length,
    ajax_method: firstRequest.method,
    nonce_in_url: firstRequest.url.includes('nonce='),
    projection_contract: projection.contract,
    projection_consistent: projection.projection_consistent,
    fingerprint_dedupe_verified: true,
    hidden_pause_verified: true,
    visible_resume_verified: true,
    raw_numeric_user_hidden: true,
  }));
} finally {
  await browser.close();
}
