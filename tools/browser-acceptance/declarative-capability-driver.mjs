// Review-owned, site-neutral browser primitives. A WordPress provider supplies a
// signed *semantic oracle*; this driver ONLY collects observations. Only its
// WordPress reducer can certify them. No site-provided code or CSS selectors.
import crypto from "node:crypto";
import { installContextNetworkBoundary } from "./network-policy.mjs";

export const DECLARATIVE_PROVIDER_CONTRACT = "mad4b.capability-browser-provider.v1";
export const DECLARATIVE_EVIDENCE_CONTRACT = "mad4b.capability-browser-evidence.v1";
const PROBE_TYPES = Object.freeze([
  "public.document_title_digest",
  "public.canonical_path",
  "public.semantic_marker_count"
]);
const SHA = /^[a-f0-9]{64}$/;
const HEX40 = /^[a-f0-9]{40}$/;
const NONCE = /^[a-f0-9]{32}$/;
const IDENT = /^[a-z][a-z0-9._-]{2,79}$/;
function deny(reason) { throw new Error("capability_browser_" + reason); }
function pathValid(s) {
  return typeof s === "string" && s.length > 0 && s.length <= 240 &&
    /^\/(?!\/)[a-zA-Z0-9~._/%-]*$/.test(s) &&
    !/(?:\.\.|%2e|%2f|%5c|%00|\\|#)/i.test(s) &&
    !/(?:^|\/)(?:wp-admin|wp-json|wp-login\.php|xmlrpc\.php|wp-cron\.php)(?:\/|$)/i.test(s);
}
function origin(raw) {
  if (typeof raw !== "string" || !/^https:\/\/[a-z0-9.-]+(?::[0-9]{2,5})?(?:\/[a-zA-Z0-9][a-zA-Z0-9._-]{0,63})*\/$/.test(raw)) deny("origin_invalid");
  const parsed = new URL(raw);
  if (parsed.username || parsed.password || parsed.search || parsed.hash ||
      !/^\/(?:[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\/)*$/.test(parsed.pathname)) deny("origin_invalid");
  return parsed.origin + parsed.pathname.slice(0, -1);
}
export function validateDeclarativePlan(plan) {
  if (!plan || typeof plan !== "object" || Array.isArray(plan) ||
      plan.contract !== "mad4b.browser-acceptance-plan.v1" ||
      plan.provider_contract !== DECLARATIVE_PROVIDER_CONTRACT ||
      !IDENT.test(plan.provider_id || "") ||
      !IDENT.test(plan.profile_id || "") ||
      plan.state !== "ready" || plan.suite !== "browser_runtime" ||
      plan.read_only !== true || plan.authorizing !== false ||
      !SHA.test(plan.plan_digest || "") || !SHA.test(plan.plan_signature || "") ||
      !HEX40.test(plan.build_identity?.git_sha || "") ||
      !SHA.test(plan.build_identity?.build_fingerprint || "")) deny("plan_invalid");
  const canonicalOrigin = origin(plan.origin);
  const challenge = plan.challenge;
  const now = Math.floor(Date.now() / 1000);
  if (!challenge || !NONCE.test(challenge.nonce || "") || !SHA.test(challenge.signature || "") ||
      !Number.isSafeInteger(challenge.issued_at) || !Number.isSafeInteger(challenge.expires_at) ||
      challenge.issued_at > now + 30 || challenge.expires_at <= now ||
      challenge.expires_at - challenge.issued_at > 900 ||
      challenge.expires_at - challenge.issued_at < 1) deny("challenge_invalid");
  if (!Array.isArray(plan.cases) || plan.cases.length < 1 || plan.cases.length > 8 ||
      plan.case_count !== plan.cases.length) deny("case_count_invalid");
  const seen = new Set();
  const permittedCaseKeys = new Set(["case_id", "capability_id", "probe_type", "page_path", "expected"]);
  for (const item of plan.cases) {
    if (!item || typeof item !== "object" || Array.isArray(item) ||
        Object.keys(item).some(key => !permittedCaseKeys.has(key)) ||
        !IDENT.test(item.case_id || "") || seen.has(item.case_id) ||
        !IDENT.test(item.capability_id || "") ||
        !PROBE_TYPES.includes(item.probe_type) || !pathValid(item.page_path) ||
        !item.expected || typeof item.expected !== "object" ||
        Array.isArray(item.expected)) deny("case_invalid");
    seen.add(item.case_id);
    const expected = item.expected;
    if (item.probe_type === "public.document_title_digest") {
      if (Object.keys(expected).length !== 1 || !SHA.test(expected.title_sha256 || "")) deny("oracle_invalid");
    } else if (item.probe_type === "public.canonical_path") {
      if (Object.keys(expected).length !== 1 || !pathValid(expected.path)) deny("oracle_invalid");
    } else if (item.probe_type === "public.semantic_marker_count") {
      if (Object.keys(expected).length !== 2 ||
          !IDENT.test(expected.marker_key || "") ||
          !Number.isSafeInteger(expected.count) || expected.count < 0 || expected.count > 5000) deny("oracle_invalid");
    }
    const u = new URL(item.page_path, canonicalOrigin + "/");
    if (u.origin !== new URL(canonicalOrigin + "/").origin) deny("case_cross_origin");
    const scope = new URL(canonicalOrigin + "/").pathname;
    if (scope !== "/" && !u.pathname.startsWith(scope)) deny("case_outside_site_scope");
  }
  // A signed-shaped plan is still untrusted until the native provider independently
  // verifies its challenge, semantic expected data, and reducer receipt.
  return Object.freeze({ ...plan, origin: canonicalOrigin + "/" });
}
const hash = value => crypto.createHash("sha256").update(value, "utf8").digest("hex");
async function observePage(page, plan, item) {
  const url = new URL(item.page_path, plan.origin).toString();
  const response = await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 });
  if (new URL(page.url()).origin !== new URL(plan.origin).origin) deny("navigation_cross_origin");
  const landed = new URL(page.url());
  const requested = new URL(item.page_path, plan.origin);
  if (landed.pathname !== requested.pathname || landed.search !== requested.search ||
      landed.hash !== "") deny("navigation_path_changed");
  const httpStatus = response?.status() ?? 0;
  if (httpStatus < 200 || httpStatus > 299) deny("public_page_unavailable");
  let observed;
  if (item.probe_type === "public.document_title_digest") {
    observed = { title_sha256: hash(await page.title()) };
  } else if (item.probe_type === "public.canonical_path") {
    const href = await page.locator('link[rel="canonical"]').first().getAttribute("href");
    if (!href) deny("canonical_missing");
    const canonical = new URL(href, page.url());
    if (canonical.origin !== new URL(plan.origin).origin) deny("canonical_cross_origin");
    observed = { path: canonical.pathname + canonical.search };
  } else {
    // The marker key has already passed a strict identifier grammar. Build
    // only one fixed attribute-selector template and use Playwright's native
    // locator.count() (no page.evaluate / site JavaScript required).
    const count = await page.locator(
      `[data-mad4b-capability-key="${item.expected.marker_key}"]`
    ).count();
    if (!Number.isSafeInteger(count) || count > 5000) deny("marker_count_invalid");
    observed = { marker_key: item.expected.marker_key, count };
  }
  const matchesExpected = JSON.stringify(observed) === JSON.stringify(item.expected);
  return {
    case_id: item.case_id, capability_id: item.capability_id,
    probe_type: item.probe_type, challenge_nonce: plan.challenge.nonce,
    http_status: httpStatus, observed, matches_expected: matchesExpected,
    // WordPress must independently reduce this against its own signed oracle.
    certification_issued: false, authorizing: false
  };
}
export async function runDeclarativeBrowserPlan({ browser, providerId, plan }) {
  const validated = validateDeclarativePlan(plan);
  if (!browser || typeof browser.newContext !== "function") deny("browser_unavailable");
  const context = await browser.newContext({
    serviceWorkers: "block",
    javaScriptEnabled: false,
    acceptDownloads: false,
    permissions: []
  });
  try {
    await installContextNetworkBoundary(context, validated.origin, process.env, {
      passiveOnly: true,
      allowedDocumentPaths: [...new Set(validated.cases.map(item =>
        new URL(item.page_path, validated.origin).pathname))]
    });
    const page = await context.newPage();
    const cases = [];
    for (const item of validated.cases) cases.push(await observePage(page, validated, item));
    const engine = typeof browser.version === "function" ? await browser.version() : "Chromium";
    return {
      contract: DECLARATIVE_EVIDENCE_CONTRACT,
      plan_digest: validated.plan_digest, plan_signature: validated.plan_signature,
      origin: validated.origin, build_identity: validated.build_identity,
      observer: {
        contract: "mad4b.capability-browser-observer.v1",
        // Legacy field means the managed runner can execute JS, NOT that the
        // target page executed JS. Passive mode disables page scripts.
        javascript_runtime: true, runner_javascript_runtime: true,
        page_javascript_enabled: false,
        browser_engine: providerId + ":" + String(engine),
        execution_mode: "managed_browser_agent",
        plan_issued_at: validated.challenge.issued_at
      },
      cases
    };
  } finally {
    await context.close().catch(() => {});
  }
}
