#!/usr/bin/env node
// Credential-only Cloudflare API check. Never starts a browser or prints tokens,
// account IDs, API response text, headers, token IDs, or sensitive error details.
const VERIFY = "https://api.cloudflare.com/client/v4/user/tokens/verify";
const ACCOUNT = /^[a-fA-F0-9]{32}$/;

export async function verifyCloudflareToken(env = process.env, request = fetch) {
  const account = typeof env.CLOUDFLARE_ACCOUNT_ID === "string"
    ? env.CLOUDFLARE_ACCOUNT_ID.trim() : "";
  const token = typeof env.CLOUDFLARE_BROWSER_RUN_API_TOKEN === "string"
    ? env.CLOUDFLARE_BROWSER_RUN_API_TOKEN.trim() : "";
  const required = /^(true|1|yes)$/i.test(String(env.MAD4B_BROWSER_VERIFY_REQUIRED || ""));
  const result = {
    contract: "mad4b.cloudflare-credential-verification.v1",
    provider: "cloudflare",
    state: "blocked",
    token_active: false,
    account_id_format_valid: ACCOUNT.test(account),
    browser_rendering_permission_verified: false,
    account_token_binding_verified: false,
    browser_session_started: false,
    provider_quota_spent: false,
    secret_values_included: false,
    network_request_performed: false,
    required
  };
  if (!result.account_id_format_valid || !token || token.length > 2048 || /[\r\n]/.test(token)) {
    result.state = "credential_missing_or_invalid_format";
    return result;
  }
  try {
    const response = await request(VERIFY, {
      method: "GET",
      redirect: "error",
      headers: { Authorization: "Bearer " + token, Accept: "application/json" },
      signal: AbortSignal.timeout(8000)
    });
    result.network_request_performed = true;
    if (response.status !== 200) {
      result.state = response.status === 401 || response.status === 403
        ? "cloudflare_token_rejected"
        : "cloudflare_auth_check_unavailable";
      return result;
    }
    // Do not log raw payload, even if Cloudflare echoes sensitive information.
    const data = await response.json();
    result.token_active = data?.success === true && data?.result?.status === "active";
    result.state = result.token_active ? "token_active" : "cloudflare_token_not_active";
    return result;
  } catch {
    result.state = "cloudflare_auth_check_unavailable";
    return result;
  }
}

if (process.argv[1] && import.meta.url === new URL("file://" + process.argv[1]).href) {
  const result = await verifyCloudflareToken();
  console.log(JSON.stringify(result));
  if (result.required && !result.token_active) process.exitCode = 2;
}
