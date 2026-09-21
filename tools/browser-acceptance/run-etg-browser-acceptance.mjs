#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import { chromium } from "playwright-core";
import {
  configuredProviders,
  classifyProviderError,
  connectBrowserProvider,
  loadProviderContracts
} from "./providers.mjs";
import { runBrowserPlan, validatePlan } from "./etg-driver.mjs";

function arg(name, fallback = "") {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const planPath = arg("plan", process.env.MAD4B_BROWSER_PLAN_FILE || "");
const outPath = arg("out", process.env.MAD4B_BROWSER_EVIDENCE_FILE || "browser-evidence.json");
const attemptsPath = arg("attempts-out", "browser-provider-attempts.json");
const requested = arg("provider", process.env.MAD4B_BROWSER_PROVIDER || "auto");

if (!planPath) {
  console.error("Missing --plan. Browser runner consumes a fresh signed MAD4B Browser Acceptance plan file.");
  process.exit(2);
}

const plan = validatePlan(JSON.parse(fs.readFileSync(planPath, "utf8")));
const contracts = loadProviderContracts();
const candidates = configuredProviders(process.env, requested);
const attempts = [];
let selectedProvider = "";
let finalEvidence = null;

async function executeInProvider(candidate) {
  const sessionSeconds = Number(candidate.definition?.advisory_free_limits?.session_seconds || 900);
  const chunkPerCase = sessionSeconds <= 180 && plan.cases.length > 1;
  if (!chunkPerCase) {
    const connection = await connectBrowserProvider(candidate.id, chromium, process.env, plan.origin);
    try {
      return await runBrowserPlan({ browser: connection.browser, providerId: candidate.id, plan });
    } finally {
      await connection.release();
    }
  }

  const cases = [];
  let envelope = null;
  for (const planCase of plan.cases) {
    const now = Math.floor(Date.now() / 1000);
    if (Number(plan.challenge?.expires_at || 0) <= now + 20) {
      throw new Error("browser_plan_challenge_near_expiry_during_chunked_execution");
    }
    const connection = await connectBrowserProvider(candidate.id, chromium, process.env, plan.origin);
    try {
      const partial = await runBrowserPlan({
        browser: connection.browser,
        providerId: candidate.id,
        plan: { ...plan, cases: [planCase], case_count: 1 }
      });
      if (!envelope) envelope = partial;
      cases.push(...partial.cases);
    } finally {
      await connection.release();
    }
  }
  return { ...envelope, cases };
}

for (const candidate of candidates) {
  if (!candidate.definition) {
    attempts.push({ provider: candidate.id, state: "skipped", reason: "provider_unknown" });
    continue;
  }
  if (!candidate.available) {
    attempts.push({ provider: candidate.id, state: "skipped", reason: "credentials_missing", missing: candidate.missing });
    continue;
  }

  try {
    finalEvidence = await executeInProvider(candidate);
    selectedProvider = candidate.id;
    attempts.push({
      provider: candidate.id,
      state: "selected",
      session_seconds_hint: candidate.definition?.advisory_free_limits?.session_seconds || null
    });
    break;
  } catch (error) {
    const classified = classifyProviderError(error, candidate.definition);
    attempts.push({ provider: candidate.id, state: "failed", ...classified });
    if (requested !== "auto" || !classified.fallback_allowed) break;
  }
}

fs.writeFileSync(attemptsPath, JSON.stringify({
  contract: "mad4b.browser-provider-attempts.v1",
  provider_contract: contracts.contract,
  selected_provider: selectedProvider,
  attempts
}, null, 2));

if (!finalEvidence) {
  console.error(JSON.stringify({ error: "no_browser_provider_completed_plan", attempts }, null, 2));
  process.exit(1);
}

fs.mkdirSync(path.dirname(path.resolve(outPath)), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(finalEvidence, null, 2));
console.log(JSON.stringify({
  contract: "mad4b.browser-execution-run.v1",
  selected_provider: selectedProvider,
  case_count: finalEvidence.cases.length,
  evidence_file: outPath
}));
