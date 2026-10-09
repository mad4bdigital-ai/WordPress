'use strict';
// Non-authorizing: read-only authenticated wp-admin navigation probe.
// No saves, screenshots, form submits, provider execution or release certificates.
const fs = require('node:fs');
const path = require('node:path');
function required(key) {
  const value = process.env[key];
  if (typeof value !== 'string' || !value.trim()) throw new Error('MISSING_CONFIGURATION');
  return value.trim();
}
const requested = new URL(required('MAD4B_UI_STAGING_ORIGIN'));
if (requested.protocol !== 'https:' || requested.username || requested.password ||
    requested.pathname !== '/' || requested.search || requested.hash) {
  throw new Error('UNSAFE_SITE_ORIGIN');
}
const origin = requested.origin;
const adminPath = process.env.MAD4B_UI_ADMIN_PATH || '/wp-admin/admin.php';
// Explicitly support WordPress installed in a bounded subdirectory.
if (!/^\/(?:[a-zA-Z0-9._-]+\/)*wp-admin\/admin\.php$/.test(adminPath)) {
  throw new Error('UNSAFE_ADMIN_PATH');
}
const stateFile = required('MAD4B_UI_AUTH_STATE_FILE');
if (!fs.existsSync(stateFile) || !fs.statSync(stateFile).isFile()) {
  throw new Error('AUTH_STATE_MISSING');
}
const root = required('MAD4B_UI_NODE_MODULES');
const { chromium } = require(path.join(root, 'playwright'));
function link(href) {
  let u;
  try { u = new URL(href, origin); } catch (_) { return null; }
  const slug = u.searchParams.get('page') || '';
  const permittedKeys = new Set(['page','tab','section','view']);
  if (u.origin !== origin || u.pathname !== adminPath ||
      !/^mad4b-[a-z0-9-]{1,100}$/.test(slug) ||
      [...u.searchParams.keys()].some(key => !permittedKeys.has(key))) return null;
  return { slug, url: u.href };
}
(async () => {
  const browser = await chromium.launch({ headless: true,
    ...(process.env.MAD4B_UI_CHROME ? { executablePath: process.env.MAD4B_UI_CHROME } : {})
  });
  const context = await browser.newContext({
    storageState: stateFile, serviceWorkers: 'block', acceptDownloads: false
  });
  const page = await context.newPage();
  const blocked = { external: 0, write: 0 };
  let pageErrors = 0;
  page.on('pageerror', () => { pageErrors++; });
  await page.route('**/*', route => {
    const req = route.request();
    let trusted = false;
    try { trusted = new URL(req.url()).origin === origin; } catch (_) {}
    if (!trusted) { blocked.external++; return route.abort(); }
    if (!['GET','HEAD'].includes(req.method())) {
      blocked.write++;
      return route.abort();
    }
    return route.continue();
  });
  const entry = origin + adminPath + '?page=mad4b-operator-control-center';
  const first = await page.goto(entry, { waitUntil: 'domcontentloaded', timeout: 25000 });
  if (!first || first.status() !== 200 || !link(page.url()) ||
      await page.locator('.mad4b-workspace').count() !== 1) {
    throw new Error('ADMIN_LOGIN_OR_DIRECTORY_NOT_VERIFIED');
  }
  const effective = (await page.locator('.mad4b-workspace-environment bdi')
    .first().textContent() || '').trim();
  if (effective !== 'staging') throw new Error('SITE_PROFILE_NOT_STAGING');
  const rawLinks = await page.locator('[data-mad4b-workspace-item] > a')
    .evaluateAll(items => items.map(a => a.getAttribute('href')).filter(Boolean));
  const sectionHrefs = await page.locator('.mad4b-workspace-section-links a')
    .evaluateAll(items => items.map(a => a.getAttribute('href')).filter(Boolean));
  const routes = new Map();
  for (const raw of rawLinks) {
    const item = link(raw);
    if (item && !routes.has(item.slug)) routes.set(item.slug, item.url);
  }
  if (routes.size < 2 || !routes.has('mad4b-operator-control-center')) {
    throw new Error('DIRECTORY_INCOMPLETE');
  }
  const expected = Number.parseInt(process.env.MAD4B_UI_EXPECTED_COUNT || '', 10);
  const countMatches = Number.isInteger(expected) && expected > 0 && expected === routes.size;
  // Explore every declared, capability-checked tab/section/view link in the
  // current WP directory. Do not fabricate arbitrary ids or execute actions.
  const cases = new Map(routes);
  for (const raw of sectionHrefs) {
    const destination = link(raw);
    if (!destination || !routes.has(destination.slug)) continue;
    const parsed = new URL(destination.url);
    const variants = ['tab','section','view'].filter(key => parsed.searchParams.has(key));
    if (variants.length !== 1) continue;
    const key = variants[0], value = parsed.searchParams.get(key) || '';
    if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(value)) continue;
    cases.set(destination.slug + ':' + key + ':' + value, destination.url);
  }
  if (cases.size > 180) throw new Error('UI_CASE_BUDGET_EXCEEDED');
  const results = [];
  for (const [slug, url] of cases) {
    const issues = [];
    let code = 0, unlabeled = 0, duplicateIds = 0;
    const overflowWidths = [];
    const priorErrors = pageErrors;
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });
      code = response ? response.status() : 0;
      if (code !== 200) issues.push('HTTP_ERROR');
      const current = link(page.url());
      if (!current || current.slug !== slug.split(':')[0]) issues.push('CROSS_ROUTE_REDIRECT');
      if (await page.locator('.mad4b-workspace').count() !== 1) issues.push('WORKSPACE_MISSING');
      if (await page.locator('.mad4b-workspace [aria-current="page"]').count() !== 1) {
        issues.push('CURRENT_PAGE_AMBIGUOUS');
      }
      if (await page.locator('#wpbody-content .wrap h1').count() < 1) issues.push('H1_MISSING');
      if (await page.locator('.mad4b-environment-context.notice-warning').count() > 0) {
        issues.push('RAW_WORDPRESS_ENVIRONMENT_MISMATCH');
      }
      const form = await page.locator('#wpbody-content .wrap').evaluate(root => {
        const fields = [...root.querySelectorAll('input,select,textarea')].filter(el =>
          el.type !== 'hidden' && !el.disabled
        );
        const unlabeled = fields.filter(el => {
          const id = el.id;
          return !el.closest('label') && !el.hasAttribute('aria-label') &&
            !el.hasAttribute('aria-labelledby') &&
            (!id || !root.querySelector('label[for="' + CSS.escape(id) + '"]'));
        }).length;
        const ids = [...root.querySelectorAll('[id]')].map(el => el.id).filter(Boolean);
        return { unlabeled, duplicate: ids.length - new Set(ids).size };
      });
      unlabeled = form.unlabeled; duplicateIds = form.duplicate;
      if (unlabeled) issues.push('UNLABELED_CONTROLS');
      if (duplicateIds) issues.push('DUPLICATE_IDS');
      for (const width of [375,768,1024,1440]) {
        await page.setViewportSize({ width, height: 900 });
        if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) {
          overflowWidths.push(width);
        }
      }
      if (overflowWidths.length) issues.push('VIEWPORT_OVERFLOW');
      if (pageErrors > priorErrors) issues.push('JAVASCRIPT_ERRORS');
    } catch (_) {
      issues.push('NAVIGATION_EXCEPTION');
    }
    results.push({
      slug, status: issues.length ? 'BLOCKED' : 'OBSERVED_UNCERTIFIED',
      http_code: code, issues, unlabeled, duplicate_ids: duplicateIds,
      viewport_overflow: overflowWidths
    });
  }
  await context.close(); await browser.close();
  const failures = results.filter(item => item.status === 'BLOCKED').length;
  const report = {
    contract: 'mad4b.admin-readonly-browser-survey.v1',
    state: failures || !countMatches ? 'BLOCKED' : 'OBSERVED_UNCERTIFIED',
    discovered_routes: routes.size, discovered_route_and_section_cases: cases.size,
    expected_route_count_match: countMatches,
    blocked_external_requests: blocked.external, blocked_write_requests: blocked.write,
    failures, results, source_manifest_verified: false,
    browser_attestation_verified: false, provider_mutations_verified: false,
    release_certified: false
  };
  process.stdout.write(JSON.stringify(report, null, 2) + '\n');
  if (report.state === 'BLOCKED') process.exitCode = 2;
})().catch(() => {
  // Error details, cookies, URLs, response contents and auth-state paths are private.
  process.stderr.write(JSON.stringify({
    contract: 'mad4b.admin-readonly-browser-survey.v1',
    state: 'BLOCKED', error_code: 'PREFLIGHT_OR_RUNTIME_FAILURE',
    release_certified: false
  }) + '\n');
  process.exitCode = 2;
});
