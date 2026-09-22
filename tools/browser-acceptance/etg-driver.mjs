import crypto from "node:crypto";
import { installContextNetworkBoundary } from "./network-policy.mjs";

const OBSERVER_PATH = "/wp-content/plugins/etg-dynamic-filter-seo-bridge/assets/js/browser-acceptance-observer.js";

function ensureHttpsOrigin(origin) {
  const url = new URL(origin);
  if (url.protocol !== "https:") throw new Error("browser_plan_origin_not_https");
  return url;
}

export function validatePlan(plan) {
  if (!plan || typeof plan !== "object") throw new Error("browser_plan_missing");
  if (plan.contract !== "mad4b.browser-acceptance-plan.v1") throw new Error("browser_plan_contract_invalid");
  if (plan.state !== "ready") throw new Error("browser_plan_not_ready");
  if (plan.provider_id !== "etg-dfsb") throw new Error("browser_plan_provider_invalid");
  if (!Array.isArray(plan.cases) || plan.cases.length < 1 || plan.cases.length > 8) throw new Error("browser_plan_case_count_invalid");
  if (!/^[a-f0-9]{64}$/.test(String(plan.plan_digest || ""))) throw new Error("browser_plan_digest_invalid");
  if (!/^[a-f0-9]{64}$/.test(String(plan.plan_signature || ""))) throw new Error("browser_plan_signature_invalid");
  const origin = ensureHttpsOrigin(plan.origin);
  const challenge = plan.challenge || {};
  if (challenge.contract !== "etg.dfsb.browser-acceptance-challenge.v1") throw new Error("browser_plan_challenge_missing");
  if (!/^[a-f0-9]{32}$/.test(String(challenge.nonce || ""))) throw new Error("browser_plan_challenge_nonce_invalid");
  if (!/^[a-f0-9]{64}$/.test(String(challenge.signature || ""))) throw new Error("browser_plan_challenge_signature_invalid");
  const now = Math.floor(Date.now() / 1000);
  if (!(Number(challenge.issued_at) > 0 && Number(challenge.expires_at) > now && Number(challenge.expires_at) - Number(challenge.issued_at) <= 900)) {
    throw new Error("browser_plan_challenge_expired_or_invalid");
  }
  if (Object.prototype.hasOwnProperty.call(plan, "case_count") && Number(plan.case_count) !== plan.cases.length) {
    throw new Error("browser_plan_case_count_mismatch");
  }

  const seenCaseIds = new Set();
  for (const item of plan.cases) {
    const caseId = String(item.case_id || "");
    if (!caseId || seenCaseIds.has(caseId) || item.provider !== "jet-engine" || item.query_id !== "tours_query_archive") {
      throw new Error("browser_plan_case_binding_invalid");
    }
    seenCaseIds.add(caseId);

    if (!item.taxonomy || !item.term_slug || !Number.isInteger(Number(item.term_id)) || Number(item.term_id) <= 0) {
      throw new Error("browser_plan_case_term_invalid");
    }

    const archivePath = String(item.archive_path || "");
    if (
      !archivePath.startsWith("/") ||
      archivePath.startsWith("//") ||
      archivePath.includes("\\") ||
      /[\u0000-\u001f\u007f]/.test(archivePath)
    ) {
      throw new Error("browser_plan_archive_path_invalid");
    }
    const archiveUrl = new URL(archivePath, origin);
    if (archiveUrl.origin !== origin.origin) throw new Error("browser_plan_archive_origin_mismatch");

    const expected = item.expected || {};
    const total = Number(expected.result_total);
    const proofCount = Number(expected.proof_item_count ?? total);
    const proofMode = String(expected.proof_mode || "");
    if (!Number.isInteger(total) || total < 0 || total > 5000 || proofCount !== total) {
      throw new Error("browser_plan_expected_total_invalid");
    }
    if (!["full_ids", "full_digest"].includes(proofMode)) {
      throw new Error("browser_plan_expected_proof_mode_invalid");
    }
    if (proofMode === "full_ids") {
      const ids = Array.isArray(expected.ids) ? expected.ids.map(Number) : [];
      if (total > 100 || ids.length !== total || ids.some((id) => !Number.isInteger(id) || id <= 0)) {
        throw new Error("browser_plan_expected_ids_invalid");
      }
    } else {
      if (!/^[a-f0-9]{64}$/.test(String(expected.identity_digest || "")) ||
          !/^[a-f0-9]{64}$/.test(String(expected.order_digest || ""))) {
        throw new Error("browser_plan_expected_digest_invalid");
      }
    }
  }
  return { ...plan, origin: origin.toString() };
}

function neutralArchivePath(archivePath) {
  const text = String(archivePath || "/");
  const jsf = text.indexOf("/jsf/");
  return jsf >= 0 ? text.slice(0, jsf + 1) : text;
}

async function getPage(browser) {
  const contexts = browser.contexts();
  const context = contexts[0] || await browser.newContext();
  const pages = context.pages();
  const page = pages[0] || await context.newPage();
  return { context, page };
}

async function enforceTopLevelOrigin(page, origin) {
  const allowed = new URL(origin).hostname;
  page.on("framenavigated", (frame) => {
    if (frame !== page.mainFrame()) return;
    const url = frame.url();
    if (!/^https?:/i.test(url)) return;
    const host = new URL(url).hostname;
    if (host !== allowed) {
      void page.close().catch(() => {});
    }
  });
}

async function loadObserver(page, origin) {
  const observerUrl = new URL(OBSERVER_PATH, origin).toString();
  await page.addScriptTag({ url: observerUrl });
  const contract = await page.evaluate(() => window.ETGDFSBBrowserAcceptanceObserver?.contract || "");
  if (contract !== "etg.dfsb.browser-acceptance-observer.v1") throw new Error("browser_observer_unavailable");
}

async function resolveTermIndex(page, planCase) {
  return page.locator(
    ".jet-smart-filters input, .jet-smart-filters button, .jet-smart-filters [data-value], " +
    ".jet-filter input, .jet-filter button, .jet-filter [data-value], " +
    "[class*='jet-smart-filter'] input, [class*='jet-smart-filter'] button, [class*='jet-smart-filter'] [data-value]"
  ).evaluateAll((nodes, input) => {
    const slug = String(input.slug || "").toLowerCase();
    const id = String(input.id || "");
    const tax = String(input.taxonomy || "").toLowerCase();
    let best = { index: -1, score: 0 };
    nodes.forEach((node, index) => {
      if (!(node instanceof HTMLElement)) return;
      const style = window.getComputedStyle(node);
      if (style.display === "none" || style.visibility === "hidden") return;
      const attrs = [
        node.getAttribute("value"), node.getAttribute("data-value"), node.getAttribute("data-term-id"),
        node.getAttribute("data-id"), node.getAttribute("data-term-slug"), node.getAttribute("data-slug")
      ].filter(Boolean).map(String);
      const text = String(node.textContent || "").trim().toLowerCase();
      const name = String(node.getAttribute("name") || "").toLowerCase();
      let score = 0;
      if (attrs.includes(id)) score += 100;
      if (attrs.some((x) => x.toLowerCase() === slug)) score += 90;
      if (text === slug.replace(/-/g, " ")) score += 70;
      if (text.includes(slug.replace(/-/g, " "))) score += 35;
      if (name.includes(tax)) score += 20;
      if (String(node.closest("[data-query-id]")?.getAttribute("data-query-id") || "") === "tours_query_archive") score += 20;
      if (score > best.score) best = { index, score };
    });
    return best.index;
  }, { slug: planCase.term_slug, id: planCase.term_id, taxonomy: planCase.taxonomy });
}

async function clickGovernedTerm(page, planCase) {
  const candidates = page.locator(
    ".jet-smart-filters input, .jet-smart-filters button, .jet-smart-filters [data-value], " +
    ".jet-filter input, .jet-filter button, .jet-filter [data-value], " +
    "[class*='jet-smart-filter'] input, [class*='jet-smart-filter'] button, [class*='jet-smart-filter'] [data-value]"
  );
  const index = await resolveTermIndex(page, planCase);
  if (index < 0) throw new Error(`browser_filter_control_not_found:${planCase.case_id}`);
  await candidates.nth(index).click({ timeout: 10000 });
}

async function waitForPresentation(page, timeout = 15000) {
  await page.waitForFunction(() => {
    const o = window.ETGDFSBBrowserAcceptanceObserver;
    if (!o) return false;
    const s = o.snapshot();
    return !!(s?.events?.ajax_filters_updated && s?.events?.presentation_updated);
  }, null, { timeout });
}

async function currentDomIds(page) {
  return page.evaluate(() => {
    const selectors = [
      ".jet-listing-grid__item[data-post-id]",
      ".jet-listing-grid__item [data-post-id]",
      "[data-etg-dfsb-result-item][data-post-id]"
    ];
    const out = [];
    for (const selector of selectors) {
      for (const node of document.querySelectorAll(selector)) {
        const id = Number.parseInt(node.getAttribute("data-post-id") || "0", 10);
        if (id > 0 && !out.includes(id)) out.push(id);
      }
    }
    return out;
  });
}

async function clickNextPagination(page) {
  const next = page.locator(
    ".jet-filters-pagination__item.next .jet-filters-pagination__link, " +
    ".jet-filters-pagination__item[data-value] .jet-filters-pagination__link, " +
    ".jet-filters-pagination__item[data-value], " +
    "[class*='jet-filters-pagination'] [aria-label*='Next' i], " +
    "[class*='jet-filters-pagination'] .next"
  );
  const count = await next.count();
  if (!count) return false;

  let chosen = -1;
  let maxValue = -1;
  for (let i = 0; i < count; i += 1) {
    const loc = next.nth(i);
    if (!await loc.isVisible().catch(() => false)) continue;
    const cls = String(await loc.getAttribute("class").catch(() => "") || "");
    const aria = String(await loc.getAttribute("aria-label").catch(() => "") || "");
    const value = Number.parseInt(String(await loc.getAttribute("data-value").catch(() => "") || ""), 10);
    if (/\bnext\b/i.test(cls) || /next/i.test(aria)) { chosen = i; break; }
    if (Number.isFinite(value) && value > maxValue) { maxValue = value; chosen = i; }
  }
  if (chosen < 0) return false;
  const before = JSON.stringify(await currentDomIds(page));
  await next.nth(chosen).click({ timeout: 10000 });
  await page.waitForFunction((oldIds) => {
    const nodes = [...document.querySelectorAll(".jet-listing-grid__item[data-post-id], .jet-listing-grid__item [data-post-id], [data-etg-dfsb-result-item][data-post-id]")];
    const ids = [...new Set(nodes.map((n) => Number.parseInt(n.getAttribute("data-post-id") || "0", 10)).filter((x) => x > 0))];
    return JSON.stringify(ids) !== oldIds;
  }, before, { timeout: 10000 }).catch(() => {});
  return true;
}

async function collectCompleteIds(page, expectedTotal) {
  const ordered = [];
  const seen = new Set();
  for (let pageNo = 0; pageNo < 50; pageNo += 1) {
    for (const id of await currentDomIds(page)) {
      if (!seen.has(id)) { seen.add(id); ordered.push(id); }
    }
    if (ordered.length >= expectedTotal) break;
    if (!await clickNextPagination(page)) break;
  }
  return ordered.slice(0, expectedTotal);
}

async function clickReset(page) {
  const selectors = [
    ".jet-smart-filters-reset .jet-reset-all-filter__button",
    ".jet-reset-all-filter__button",
    ".jet-smart-filters-reset button",
    "[class*='jet-smart-filters-reset'] button",
    "[class*='jet-reset'] button"
  ];
  for (const selector of selectors) {
    const loc = page.locator(selector);
    const count = await loc.count().catch(() => 0);
    for (let i = 0; i < count; i += 1) {
      if (!await loc.nth(i).isVisible().catch(() => false)) continue;
      await loc.nth(i).click({ timeout: 10000 });
      return true;
    }
  }
  return false;
}

function sha256Ids(ids) {
  return crypto.createHash("sha256").update(JSON.stringify(ids.map((x) => Number(x)))).digest("hex");
}

async function executeCase(page, plan, planCase) {
  const neutral = new URL(neutralArchivePath(planCase.archive_path), plan.origin).toString();
  await page.goto(neutral, { waitUntil: "domcontentloaded", timeout: 30000 });
  await loadObserver(page, plan.origin);

  const armed = await page.evaluate(({ planCase, challenge }) => {
    return window.ETGDFSBBrowserAcceptanceObserver.arm(planCase, challenge);
  }, { planCase, challenge: plan.challenge });
  if (!armed?.ok) throw new Error(`browser_observer_arm_failed:${planCase.case_id}`);

  await clickGovernedTerm(page, planCase);
  await waitForPresentation(page);

  const expectedTotal = Number(planCase.expected?.result_total || 0);
  const completeIds = await collectCompleteIds(page, expectedTotal);
  let evidence = await page.evaluate(async () => {
    return await window.ETGDFSBBrowserAcceptanceObserver.snapshotAsync();
  });

  if (completeIds.length === expectedTotal && expectedTotal >= 0) {
    evidence.rendered = evidence.rendered || {};
    evidence.rendered.observed_id_count = completeIds.length;
    evidence.rendered.proof_item_count = completeIds.length;
    if (planCase.expected?.proof_mode === "full_ids" && expectedTotal <= 100) {
      evidence.rendered.ids = completeIds;
      evidence.rendered.ids_complete = true;
    } else if (planCase.expected?.proof_mode === "full_digest") {
      evidence.rendered.digest_authoritative = true;
      evidence.rendered.identity_digest = sha256Ids(completeIds.slice().sort((a, b) => a - b));
      evidence.rendered.order_digest = sha256Ids(completeIds);
    }
  }

  const resetClicked = await clickReset(page);
  if (resetClicked) {
    await page.waitForFunction(() => window.ETGDFSBBrowserAcceptanceObserver?.snapshot()?.events?.presentation_reset === true, null, { timeout: 10000 }).catch(() => {});
    evidence = await page.evaluate(async () => await window.ETGDFSBBrowserAcceptanceObserver.snapshotAsync());
    if (completeIds.length === expectedTotal && expectedTotal >= 0) {
      evidence.rendered = evidence.rendered || {};
      evidence.rendered.observed_id_count = completeIds.length;
      evidence.rendered.proof_item_count = completeIds.length;
      if (planCase.expected?.proof_mode === "full_ids" && expectedTotal <= 100) {
        evidence.rendered.ids = completeIds;
        evidence.rendered.ids_complete = true;
      } else if (planCase.expected?.proof_mode === "full_digest") {
        evidence.rendered.digest_authoritative = true;
        evidence.rendered.identity_digest = sha256Ids(completeIds.slice().sort((a, b) => a - b));
        evidence.rendered.order_digest = sha256Ids(completeIds);
      }
    }
  }
  await page.evaluate(() => window.ETGDFSBBrowserAcceptanceObserver?.disarm());
  return evidence;
}

export function buildEvidenceEnvelope({ plan, providerId, browserEngine, cases }) {
  return {
    contract: "etg.dfsb.browser-acceptance-evidence.v1",
    plan_digest: plan.plan_digest,
    plan_signature: plan.plan_signature,
    origin: plan.origin,
    build_identity: plan.build_identity,
    observer: {
      contract: "etg.dfsb.browser-acceptance-observer.v1",
      javascript_runtime: true,
      browser_engine: `${providerId}:${browserEngine}`,
      execution_mode: "managed_browser_agent"
    },
    cases
  };
}

export async function runBrowserPlan({ browser, providerId, plan }) {
  plan = validatePlan(plan);
  const { context, page } = await getPage(browser);
  await installContextNetworkBoundary(context, plan.origin, process.env);
  await enforceTopLevelOrigin(page, plan.origin);
  const browserEngine = await browser.version().catch(() => "Chromium/CDP");
  const cases = [];
  for (const planCase of plan.cases) {
    const evidence = await executeCase(page, plan, planCase);
    evidence.case_id = planCase.case_id;
    evidence.challenge_nonce = plan.challenge.nonce;
    cases.push(evidence);
  }
  return buildEvidenceEnvelope({ plan, providerId, browserEngine, cases });
}
