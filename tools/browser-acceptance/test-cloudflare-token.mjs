import assert from "node:assert/strict";
import { verifyCloudflareToken } from "./verify-cloudflare-token.mjs";
const account = "a".repeat(32);
const token = "TOKEN-DO-NOT-PRINT";
const good = { CLOUDFLARE_ACCOUNT_ID: account, CLOUDFLARE_BROWSER_RUN_API_TOKEN: token, MAD4B_BROWSER_VERIFY_REQUIRED:"true" };
let requests = 0;
const fetchActive = async (url, options) => {
  requests++;
  assert.equal(url, "https://api.cloudflare.com/client/v4/user/tokens/verify");
  assert.equal(options.method, "GET");
  assert.equal(options.headers.Authorization, "Bearer " + token);
  return { status:200, json:async()=>({success:true,result:{status:"active",id:"SECRET-ID"}}) };
};
const active = await verifyCloudflareToken(good, fetchActive);
assert.equal(requests, 1);
assert.equal(active.state, "token_active");
assert.equal(active.token_active, true);
assert.equal(active.browser_session_started, false);
assert.equal(active.account_token_binding_verified, false);
assert.equal(active.browser_rendering_permission_verified, false);
assert.equal(active.secret_values_included, false);
assert.ok(!JSON.stringify(active).includes(token));
assert.ok(!JSON.stringify(active).includes(account));
assert.ok(!JSON.stringify(active).includes("SECRET-ID"));

for (const [status, state] of [[401,"cloudflare_token_rejected"],[403,"cloudflare_token_rejected"],[500,"cloudflare_auth_check_unavailable"]]) {
  const observed = await verifyCloudflareToken(good, async()=>({status,json:async()=>({})}));
  assert.equal(observed.state, state);
  assert.equal(observed.token_active, false);
}
const notActive = await verifyCloudflareToken(good, async()=>({status:200,json:async()=>({success:true,result:{status:"expired"}})}));
assert.equal(notActive.state, "cloudflare_token_not_active");
let called = false;
const empty = await verifyCloudflareToken({CLOUDFLARE_ACCOUNT_ID:account},async()=>{called=true;throw Error("must not call")});
assert.equal(empty.state,"credential_missing_or_invalid_format");
assert.equal(called,false);
const bad = await verifyCloudflareToken({...good,CLOUDFLARE_ACCOUNT_ID:"evil-account"},async()=>{called=true;throw Error("must not call")});
assert.equal(bad.account_id_format_valid,false);
assert.equal(bad.network_request_performed,false);
const outage = await verifyCloudflareToken(good,async()=>{throw Error("SECRET " + token)});
assert.equal(outage.state,"cloudflare_auth_check_unavailable");
assert.ok(!JSON.stringify(outage).includes(token));
console.log("MAD4B_CLOUDFLARE_TOKEN_VERIFICATION_CONTRACT: PASS");
