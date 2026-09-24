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
import {
  BrowserRunBudget,
  rankProviderCandidates
} from "./scheduler.mjs";
import { runBrowserPlan, validatePlan } from "./etg-driver.mjs";

function arg(name, fallback = "") {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const planPath = arg("plan", process.env.MAD4B_BROWSER_PLAN_FILE || "");
const outPath = arg("out", process.env.MAD4B_BROWSER_EVIDENCE_FILE || "browser-evidence.json");
const attemptsPath = arg("attempts-out", "browser-provider-attempts.json");
const requested = arg("provider", process.env.MAD4B_BROWSER_PROVIDER || "auto");
const executionDeadline = Number(process.env.MAD4B_BROWSER_EXECUTION_DEADLINE_EPOCH || 0);
const allowCreditProviders = requested !== "auto" || /^(1|true|yes|on)$/i.test(String(process.env.MAD4B_BROWSER_ALLOW_CREDIT_FALLBACK || ""));

if (!planPath) {
  console.error("Missing --plan. Browser runner consumes a fresh signed MAD4B Browser Acceptance plan file.");
  process.exit(2);
}

const plan = validatePlan(JSON.parse(fs.readFileSync(planPath, "utf8")));
const contracts = loadProviderContracts();
const budget = new BrowserRunBudget(contracts);
const configured = configuredProviders(process.env, requested);
const candidates = requested === "auto"
  ? rankProviderCandidates(configured, plan, contracts, { allowCreditProviders })
  : rankProviderCandidates(configured, plan, contracts, { allowCreditProviders: true })
      .sort((a, b) => Number(a.definition?.priority || 9999) - Number(b.definition?.priority || 9999));

const attempts = [];
let selectedProvider = "";
let finalEvidence = null;

function chunkCases(candidate) {
  const execution = candidate.execution || {};
  const size = Math.max(1, Number(execution.cases_per_session || plan.cases.length || 1));
  const chunks = [];
  for (let i = 0; i < plan.cases.length; i += size) chunks.push(plan.cases.slice(i, i + size));
  return chunks;
}

async function executeInProvider(candidate) {
  const chunks = chunkCases(candidate);
  const cases = [];
  let envelope = null;

  for (const caseChunk of chunks) {
    const now = Math.floor(Date.now() / 1000);
    const reserve = Number(contracts.run_budget?.challenge_expiry_reserve_seconds || 30);
    if (Number(plan.challenge?.expires_at || 0) <= now + reserve) {
      throw new Error("browser_plan_challenge_near_expiry_during_execution");
    }
    const estimatedCaseSeconds = Number(candidate.execution?.estimated_case_seconds || contracts.run_budget?.estimated_case_seconds || 45);
    const startOverhead = Number(contracts.run_budget?.session_start_overhead_seconds || 10);
    const estimatedChunkSeconds = caseChunk.length * estimatedCaseSeconds + startOverhead;
    if (executionDeadline > 0 && now + estimatedChunkSeconds > executionDeadline) {
      throw new Error("browser_execution_deadline_insufficient_for_chunk");
    }

    budget.reserveSession();
    const connection = await connectBrowserProvider(candidate.id, chromium, process.env, plan.origin);
    try {
      const partial = await runBrowserPlan({
        browser: connection.browser,
        providerId: candidate.id,
        plan: { ...plan, cases: caseChunk, case_count: caseChunk.length }
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
  if (candidate.selection_blocker) {
    attempts.push({
      provider: candidate.id,
      state: "skipped",
      reason: candidate.selection_blocker,
      missing: candidate.missing || [],
      execution: candidate.execution || null
    });
    continue;
  }

  if (!budget.canAttemptProvider(candidate.id)) {
    attempts.push({ provider: candidate.id, state: "skipped", reason: "provider_circuit_or_attempt_budget_open" });
    continue;
  }

  const remainingSeconds = executionDeadline > 0 ? executionDeadline - Math.floor(Date.now() / 1000) : null;
  if (executionDeadline > 0 && remainingSeconds < Number(candidate.execution?.estimated_total_seconds || 0)) {
    attempts.push({
      provider: candidate.id,
      state: "skipped",
      reason: "execution_deadline_insufficient",
      remaining_seconds: remainingSeconds,
      execution: candidate.execution
    });
    if (requested !== "auto") break;
    continue;
  }

  budget.reserveProvider(candidate.id);
  const startedAt = new Date().toISOString();
  try {
    finalEvidence = await executeInProvider(candidate);
    selectedProvider = candidate.id;
    attempts.push({
      provider: candidate.id,
      state: "selected",
      started_at: startedAt,
      completed_at: new Date().toISOString(),
      execution: candidate.execution,
      budget: budget.snapshot()
    });
    break;
  } catch (error) {
    budget.openCircuit(candidate.id);
    const classified = classifyProviderError(error, candidate.definition);
    attempts.push({
      provider: candidate.id,
      state: "failed",
      started_at: startedAt,
      completed_at: new Date().toISOString(),
      execution: candidate.execution,
      budget: budget.snapshot(),
      ...classified
    });
    if (requested !== "auto" || !classified.fallback_allowed) break;
  }
}

fs.mkdirSync(path.dirname(path.resolve(attemptsPath)), { recursive: true });
fs.writeFileSync(attemptsPath, JSON.stringify({
  contract: "mad4b.browser-provider-attempts.v2",
  provider_contract: contracts.contract,
  selection_policy: contracts.selection_policy,
  allow_credit_fallback: allowCreditProviders,
  plan_digest: plan.plan_digest,
  selected_provider: selectedProvider,
  run_budget: { ...budget.snapshot(), execution_deadline_epoch: executionDeadline || null },
  attempts
}, null, 2));

if (!finalEvidence) {
  console.error(JSON.stringify({ error: "no_browser_provider_completed_plan", attempts, run_budget: budget.snapshot() }, null, 2));
  process.exit(1);
}

fs.mkdirSync(path.dirname(path.resolve(outPath)), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(finalEvidence, null, 2));
console.log(JSON.stringify({
  contract: "mad4b.browser-execution-run.v2",
  selected_provider: selectedProvider,
  case_count: finalEvidence.cases.length,
  browser_sessions_started: budget.snapshot().browser_sessions_started,
  execution_deadline_epoch: executionDeadline || null,
  evidence_file: outPath,
  attempts_file: attemptsPath
}));
