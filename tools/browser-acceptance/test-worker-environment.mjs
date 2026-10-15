import assert from "node:assert/strict";
import { buildBrowserWorkerEnvironment } from "./worker-environment.mjs";
const env={
 PATH:"/usr/bin",HOME:"/home/runner",BROWSERBASE_API_KEY:"browserkey",
 CLOUDFLARE_BROWSER_RUN_API_TOKEN:"providersecret",
 MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64:"TEST-SIGNER-BASE64",
 MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS:"cdn.example.com",
 MAD4B_MCP_ACCESS_TOKEN:"jwt-secret",GITHUB_TOKEN:"ghp-secret",
 OPENAI_API_KEY:"unrelated",MAD4B_AUTH_STEP_UP:"authority",
 NODE_OPTIONS:"--require /tmp/evil.js",HTTP_PROXY:"http://untrusted.example",
 MAD4B_MCP_RESOURCE:"https://oauth.example",APP_DB_PASSWORD:"db-secret"
};
const isolated=buildBrowserWorkerEnvironment(env,1890000000);
assert.equal(isolated.PATH,"/usr/bin");
assert.equal(isolated.BROWSERBASE_API_KEY,"browserkey");
assert.equal(isolated.CLOUDFLARE_BROWSER_RUN_API_TOKEN,"providersecret");
assert.equal(isolated.MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64,"TEST-SIGNER-BASE64");
assert.equal(isolated.MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS,"cdn.example.com");
assert.equal(isolated.MAD4B_BROWSER_EXECUTION_DEADLINE_EPOCH,"1890000000");
for (const forbidden of ["MAD4B_MCP_ACCESS_TOKEN","GITHUB_TOKEN","OPENAI_API_KEY",
 "MAD4B_AUTH_STEP_UP","NODE_OPTIONS","HTTP_PROXY","MAD4B_MCP_RESOURCE","APP_DB_PASSWORD"]) {
 assert.equal(isolated[forbidden],undefined,forbidden+" must never reach browser worker");
}
assert.throws(()=>buildBrowserWorkerEnvironment(env,NaN),/browser_worker_environment_invalid/);
assert.throws(()=>buildBrowserWorkerEnvironment(env,0),/browser_worker_environment_invalid/);
assert.throws(()=>buildBrowserWorkerEnvironment(null,1),/browser_worker_environment_invalid/);
console.log("MAD4B_BROWSER_WORKER_ENVIRONMENT: PASS");
