#!/usr/bin/env node
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import process from "node:process";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import {
  createMad4bMcpSession,
  requestBrowserPlan,
  submitBrowserEvidence
} from "./mcp-bridge.mjs";
import { buildBrowserExecutionReceipt } from "./receipt.mjs";

function arg(name, fallback = "") {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const HERE = path.dirname(fileURLToPath(import.meta.url));
const resource = String(process.env.MAD4B_MCP_RESOURCE || "").trim();
const accessToken = String(process.env.MAD4B_MCP_ACCESS_TOKEN || "").trim();
const profileId = arg("profile", process.env.MAD4B_BROWSER_PROFILE_ID || "tours");
const browserProvider = arg("browser-provider", process.env.MAD4B_BROWSER_PROVIDER || "auto");
const evidencePath = path.resolve(arg("out", "browser-evidence.json"));
const attemptsPath = path.resolve(arg("attempts-out", "browser-provider-attempts.json"));
const resultPath = path.resolve(arg("result-out", "browser-acceptance-result.json"));
const receiptPath = path.resolve(arg("receipt-out", "browser-execution-receipt.json"));

if (!resource || !accessToken) {
  console.error("MAD4B_MCP_RESOURCE and a short-lived MAD4B_MCP_ACCESS_TOKEN are required.");
  process.exit(2);
}
if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(profileId)) {
  console.error("Browser acceptance profile ID is invalid.");
  process.exit(2);
}

const session = await createMad4bMcpSession({ resource, accessToken });
const plan = await requestBrowserPlan(session, { providerId: "etg-dfsb", profileId });

const now = Math.floor(Date.now() / 1000);
if (Number(plan.challenge?.expires_at || 0) <= now + 60) {
  throw new Error("mcp_browser_plan_freshness_window_insufficient");
}

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), "mad4b-browser-plan-"));
const planPath = path.join(tempDir, "signed-plan.json");
fs.writeFileSync(planPath, JSON.stringify(plan), { mode: 0o600 });

try {
  const childEnv = { ...process.env };
  delete childEnv.MAD4B_MCP_ACCESS_TOKEN;

  const runnerPath = path.join(HERE, "run-etg-browser-acceptance.mjs");
  const child = spawnSync(process.execPath, [
    runnerPath,
    "--plan", planPath,
    "--provider", browserProvider,
    "--out", evidencePath,
    "--attempts-out", attemptsPath
  ], {
    stdio: "inherit",
    env: childEnv
  });
  if (child.error) throw child.error;
  if (child.status !== 0) {
    throw new Error(`managed_browser_runner_failed:${child.status}`);
  }

  const evidence = JSON.parse(fs.readFileSync(evidencePath, "utf8"));
  const result = await submitBrowserEvidence(session, plan, evidence);
  fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));

  const attempts = JSON.parse(fs.readFileSync(attemptsPath, "utf8"));
  const receipt = buildBrowserExecutionReceipt({
    plan,
    attempts,
    evidence,
    result,
    sourceHead: process.env.GITHUB_SHA || ""
  });
  fs.writeFileSync(receiptPath, JSON.stringify(receipt, null, 2));

  const verified = result?.verification?.browser_runtime_parity_verified === true;
  const verdict = String(result?.verdict || "");
  console.log(JSON.stringify({
    contract: "mad4b.browser-live-execution.v1",
    profile_id: profileId,
    browser_provider: browserProvider,
    plan_digest: plan.plan_digest,
    result_contract: result.contract,
    verdict,
    browser_runtime_parity_verified: verified,
    result_file: resultPath,
    receipt_file: receiptPath,
    receipt_sha256: receipt.receipt_sha256
  }));

  if (verdict !== "PASS" || !verified) process.exitCode = 1;
} finally {
  try { fs.rmSync(tempDir, { recursive: true, force: true }); } catch {}
}
