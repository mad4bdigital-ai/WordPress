// Dedicated least-privilege environment for the external browser worker.
// A WordPress MCP token, GitHub token, service credential or other unrelated
// process environment must never cross the runner->browser-worker boundary.
const ALLOWED = Object.freeze([
  "PATH", "Path", "HOME", "USERPROFILE", "SYSTEMROOT", "SystemRoot",
  "WINDIR", "TMP", "TEMP", "TMPDIR", "TZ",
  "MAD4B_BROWSER_ALLOW_CREDIT_FALLBACK",
  "MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS",
  "MAD4B_CLOUDFLARE_ALLOWED_DOMAINS",
  "CLOUDFLARE_ACCOUNT_ID", "CLOUDFLARE_BROWSER_RUN_API_TOKEN",
  "BROWSERBASE_API_KEY", "BROWSERLESS_TOKEN", "BROWSERLESS_REGION",
  "STEEL_API_KEY"
]);
export function buildBrowserWorkerEnvironment(env, deadline) {
  if (!env || typeof env !== "object" || Array.isArray(env) ||
      !Number.isSafeInteger(deadline) || deadline <= 0) {
    throw new Error("browser_worker_environment_invalid");
  }
  const result = Object.create(null);
  for (const key of ALLOWED) {
    if (typeof env[key] === "string") result[key] = env[key];
  }
  result.MAD4B_BROWSER_EXECUTION_DEADLINE_EPOCH = String(deadline);
  return result;
}
