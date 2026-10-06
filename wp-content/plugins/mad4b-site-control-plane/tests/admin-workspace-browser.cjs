/* Browser QA of the actual PHP presentation fixtures, with all outbound requests denied. */
const assert = require('node:assert/strict');
const { readFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const modules = process.env.MAD4B_UI_NODE_MODULES || process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const { chromium } = require(path.join(modules, 'playwright'));
const fixtureDir = process.argv[2];
assert(fixtureDir, 'A disposable fixture directory is required.');

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.MAD4B_UI_CHROME ? { executablePath: process.env.MAD4B_UI_CHROME } : {}) });
  let assertions = 0;
  const check = (value, message) => { assertions += 1; assert(value, message); };
  let outbound = 0;
  const errors = [];
  for (const locale of ['en', 'ar']) {
    for (const width of [375, 768, 1024, 1440]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => { outbound += 1; return route.abort(); });
      await page.setContent(readFileSync(path.join(fixtureDir, `workspace-${locale}.html`), 'utf8'));
      check(await page.locator('html').getAttribute('dir') === (locale === 'ar' ? 'rtl' : 'ltr'), 'Direction follows the locale.');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${locale}/${width}: no document overflow.`);
      const summary = page.locator('.mad4b-workspace-directory > summary');
      await summary.focus();
      await page.keyboard.press('Enter');
      check(await page.locator('.mad4b-workspace-directory').getAttribute('open') !== null, 'Directory opens from the keyboard.');
      const input = page.locator('#mad4b-workspace-filter');
      await input.fill(locale === 'ar' ? 'بيانات' : 'credentials');
      check(await page.locator('[data-mad4b-workspace-item]:visible').count() > 0, 'Credential setup is discoverable in the current language.');
      await input.fill('unmatched-workspace-xyz');
      check(await page.locator('[data-mad4b-workspace-item]:visible').count() === 0, 'Unmatched results are hidden.');
      check(await page.locator('[data-mad4b-directory-empty]').isVisible(), 'No results provide a recovery instruction.');
      check(await page.locator('[data-mad4b-directory-status]').textContent() !== '', 'Search announces a contextual result count.');
      await input.fill('');
      check(await page.locator('[data-mad4b-workspace-item]:visible').count() === 14, 'Clearing restores all fourteen pages.');
      await input.focus();
      check(await input.evaluate(node => getComputedStyle(node).outlineStyle !== 'none'), 'Keyboard focus has a visible outline.');
      check(await page.locator('.mad4b-action-card').count() === 4, 'Action cards preserve distinct handoffs.');
      check(await page.locator('.mad4b-scp-card-value').filter({ hasText: locale === 'ar' ? 'لم يتم الفحص' : 'Not checked' }).count() === 1, 'Unknown data is never presented as a failed or passed check.');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${locale}/${width}: expanded navigation fits the viewport.`);
      if (width === 375 || width === 1440) {
        mkdirSync(path.join(fixtureDir, 'screenshots'), { recursive: true });
        await page.screenshot({ path: path.join(fixtureDir, 'screenshots', `${locale}-${width}.png`), fullPage: false });
      }
      await context.close();
    }
    const context = await browser.newContext({ viewport: { width: 375, height: 1000 }, javaScriptEnabled: false });
    const page = await context.newPage();
    await page.route('**/*', route => { outbound += 1; return route.abort(); });
    await page.setContent(readFileSync(path.join(fixtureDir, `workspace-${locale}.html`), 'utf8'));
    await page.locator('.mad4b-workspace-directory > summary').click();
    check(await page.locator('[data-mad4b-workspace-item]:visible').count() === 14, 'Native directory works without JavaScript.');
    check(!await page.locator('[data-mad4b-directory-controls]').isVisible(), 'Unavailable enhancement controls stay hidden.');
    await context.close();
  }
  check(errors.length === 0, `No browser exceptions: ${errors.join(', ')}`);
  check(outbound === 0, 'Local presentation makes zero outbound requests.');
  await browser.close();
  console.log(JSON.stringify({ contract: 'mad4b.admin-workspace-browser-fixture.v1', status: 'PASS', assertions, locales: ['en', 'ar'], viewports: [375, 768, 1024, 1440], outbound_requests: outbound, evidence_class: 'disposable_presentation_fixture', live_browser_acceptance: false, authorizing: false }));
})().catch(error => { console.error(error); process.exitCode = 1; });
