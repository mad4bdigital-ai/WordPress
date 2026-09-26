import assert from "node:assert/strict";
import {
  allowedHostSet,
  configuredAllowedHosts,
  originHostname,
  requestBoundaryDecision
} from "./network-policy.mjs";

const origin = "https://staging.egypttourgates.com/";
const env = {
  MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS: "cdn.example.com, static.example.net"
};

assert.equal(originHostname(origin), "staging.egypttourgates.com");
assert.deepEqual([...allowedHostSet(origin, env)].sort(), [
  "cdn.example.com",
  "staging.egypttourgates.com",
  "static.example.net"
].sort());
assert.deepEqual(configuredAllowedHosts(env), ["cdn.example.com", "static.example.net"]);

assert.equal(requestBoundaryDecision({
  url: "https://staging.egypttourgates.com/wp-admin/admin-ajax.php",
  resourceType: "xhr",
  origin,
  env
}).allow, true);

assert.equal(requestBoundaryDecision({
  url: "https://cdn.example.com/app.js",
  resourceType: "script",
  origin,
  env
}).allow, true);

assert.equal(requestBoundaryDecision({
  url: "https://evil.example/collect",
  resourceType: "fetch",
  origin,
  env
}).allow, false);

assert.equal(requestBoundaryDecision({
  url: "https://images.example/photo.webp",
  resourceType: "image",
  origin,
  env
}).allow, true);

assert.equal(requestBoundaryDecision({
  url: "http://staging.egypttourgates.com/insecure.js",
  resourceType: "script",
  origin,
  env
}).allow, false);

assert.throws(
  () => configuredAllowedHosts({ MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS: "*.example.com" }),
  /browser_allowed_asset_domain_invalid/
);
assert.throws(
  () => configuredAllowedHosts({ MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS: "https://cdn.example.com" }),
  /browser_allowed_asset_domain_invalid/
);

console.log("MAD4B browser network boundary PASS");
