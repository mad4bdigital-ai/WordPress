// Durable single-host replay suppression for read-only Browser Acceptance.
// This is NOT a distributed consumption authority or a release certificate.
// Never store OAuth tokens, full browser evidence, HMAC plans or private keys.
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { canonicalSha256 } from "./receipt.mjs";

const HEX64 = /^[a-f0-9]{64}$/;
const NONCE = /^[a-f0-9]{32}$/;
const deny = code => { throw new Error("browser_consumption_" + code); };
const hash = value => crypto.createHash("sha256").update(value).digest("hex");

function validateLedgerDirectory(directory) {
  if (typeof directory !== "string" || !path.isAbsolute(directory) ||
      directory === "/" || directory.length > 512) deny("directory_unconfigured");
  let stat, real;
  try {
    if (fs.lstatSync(directory).isSymbolicLink()) deny("directory_symlink");
    real = fs.realpathSync(directory);
    stat = fs.statSync(directory);
  } catch (error) {
    if (String(error.message || "").startsWith("browser_consumption_")) throw error;
    deny("directory_unavailable");
  }
  if (real !== path.resolve(directory) || !stat.isDirectory() ||
      (stat.mode & 0o077) !== 0 ||
      (typeof process.getuid === "function" && stat.uid !== process.getuid())) {
    deny("directory_untrusted");
  }
  return real;
}

/**
 * Claims one plan digest using an O_EXCL + O_NOFOLLOW durable file.
 * Atomicity scope: processes using ONE explicitly approved POSIX local FS.
 * Does not offer multi-host, remote WordPress server, or distributed anti-replay.
 * Crash after file creation deliberately burns the plan rather than reuses it.
 */
export function consumeLocalBrowserPlanOnce({
  plan, evidence, result, ledgerDir = process.env.MAD4B_BROWSER_CONSUMPTION_LEDGER_DIR,
  authorityMode = process.env.MAD4B_BROWSER_CONSUMPTION_AUTHORITY,
  now = Math.floor(Date.now() / 1000)
} = {}) {
  if (authorityMode !== "single-host-posix-v1") deny("authority_unavailable");
  if (!plan || !evidence || !result ||
      plan.provider_contract !== "mad4b.capability-browser-provider.v1" ||
      plan.state !== "ready" || plan.read_only !== true || plan.authorizing !== false ||
      plan.suite !== "browser_runtime" ||
      !/^https:\/\/[a-z0-9.-]+(?::[0-9]{2,5})?(?:\/[a-zA-Z0-9._-]+)*\/$/.test(plan.origin || "") ||
      result.contract !== "mad4b.browser-acceptance-result.v1" ||
      result.provider_id !== plan.provider_id ||
      result.provider_contract !== plan.provider_contract ||
      result.profile_id !== plan.profile_id ||
      result.suite !== plan.suite ||
      evidence.contract !== "mad4b.capability-browser-evidence.v1" ||
      evidence.origin !== plan.origin ||
      canonicalSha256(evidence.build_identity) !== canonicalSha256(plan.build_identity) ||
      result.verdict !== "PASS" ||
      result.verification?.browser_runtime_parity_verified !== true ||
      result.read_only !== true || result.authorizing !== false ||
      result.receipt_authorizing !== false ||
      !HEX64.test(plan.plan_digest || "") || !HEX64.test(plan.plan_signature || "") ||
      !NONCE.test(plan.challenge?.nonce || "") ||
      result.plan_digest !== plan.plan_digest ||
      evidence.plan_digest !== plan.plan_digest ||
      evidence.plan_signature !== plan.plan_signature ||
      !HEX64.test(result.evidence_digest || "") ||
      result.evidence_digest !== canonicalSha256(evidence) ||
      evidence.cases?.[0]?.challenge_nonce !== plan.challenge.nonce ||
      !HEX64.test(result.receipt_signature || "") ||
      !Number.isSafeInteger(plan.challenge?.issued_at) ||
      !Number.isSafeInteger(plan.challenge?.expires_at) ||
      !Number.isSafeInteger(now) ||
      now < plan.challenge.issued_at - 30 ||
      now >= plan.challenge.expires_at) deny("invalid_or_stale_proof");
  const dir = validateLedgerDirectory(ledgerDir);
  const key = hash(["mad4b.browser-consumption.v1", plan.origin, plan.provider_id,
    plan.plan_digest, plan.challenge.nonce].join("|"));
  const filename = path.join(dir, key + ".json");
  const flags = fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL |
    (fs.constants.O_NOFOLLOW || 0);
  let fd;
  try {
    fd = fs.openSync(filename, flags, 0o600);
  } catch (error) {
    if (error?.code === "EEXIST") deny("replay_detected");
    deny("atomic_claim_failed");
  }
  // Do not unlink the claim on write failure. A partially written claim is
  // burned rather than creating the possibility of accepting it twice.
  try {
    const record = {
      contract: "mad4b.browser-consumption-local.v1",
      claim_key: key,
      plan_digest: plan.plan_digest,
      evidence_digest: result.evidence_digest,
      reducer_receipt_signature_sha256: hash(result.receipt_signature),
      consumed_at: now,
      expires_at: plan.challenge.expires_at,
      scope: "single_host_posix_filesystem",
      globally_unique_consumption_proven: false,
      release_ready: false
    };
    fs.writeSync(fd, JSON.stringify(record) + "\n");
    fs.fsyncSync(fd);
    fs.closeSync(fd);
    fd = undefined;
    // Make directory metadata durable where POSIX directory fsync is supported.
    const dfd = fs.openSync(dir, fs.constants.O_RDONLY);
    try { fs.fsyncSync(dfd); } finally { fs.closeSync(dfd); }
    return {
      contract: record.contract, claim_key: key, consumed_at: now,
      scope: record.scope, globally_unique_consumption_proven: false,
      release_ready: false
    };
  } catch {
    deny("persistence_not_confirmed");
  } finally {
    if (fd !== undefined) {
      try { fs.closeSync(fd); } catch {}
    }
  }
}
