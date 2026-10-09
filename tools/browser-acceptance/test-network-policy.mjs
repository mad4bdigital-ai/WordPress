import assert from "node:assert/strict";
import {
  allowedHostSet,
  configuredAllowedHosts,
  originHostname,
  requestBoundaryDecision,
  installContextNetworkBoundary
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
}).allow, false);
assert.equal(requestBoundaryDecision({
  url: "https://cdn.example.com/logo.webp?track=1",
  resourceType: "image", origin, env
}).allow, true);
assert.equal(requestBoundaryDecision({
  url: "https://other.example/font.woff2",
  resourceType: "font", origin, env
}).reason, "cross_origin_not_allowlisted");

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

assert.throws(() => configuredAllowedHosts({
  MAD4B_BROWSER_ALLOWED_ASSET_DOMAINS: Array.from({length:51}, (_,i)=>"a" + i + ".example.com").join(",")
}), /browser_allowed_asset_domains_overflow/);
for(const address of ["https://127.0.0.1/", "https://10.1.2.3/", "https://192.168.1.5/",
  "https://169.254.169.254/", "https://localhost/", "https://intranet.local/"]) {
  assert.throws(() => originHostname(address), /browser_origin_hostname_invalid/);
}
assert.equal(requestBoundaryDecision({
  url:"https://staging.egypttourgates.com:4443/wp-admin/",
  resourceType:"document", origin, env
}).reason,"site_origin_port_mismatch");
assert.equal(requestBoundaryDecision({
  url:"https://cdn.example.com:4443/file.jpg",
  resourceType:"image", origin, env
}).reason,"asset_host_nonstandard_port");
for (const req of [
 {url:"https://staging.egypttourgates.com/public/",method:"POST",resourceType:"fetch"},
 {url:"https://staging.egypttourgates.com/wp-admin/admin-ajax.php",method:"GET",resourceType:"xhr"},
 {url:"https://staging.egypttourgates.com/wp-json/wp/v2/pages",method:"GET",resourceType:"fetch"},
 {url:"blob:https://staging.egypttourgates.com/code",method:"GET",resourceType:"script"},
 {url:"https://staging.egypttourgates.com/socket",method:"GET",resourceType:"websocket"}
]) assert.equal(requestBoundaryDecision({...req,origin,env,passiveOnly:true}).allow,false);
assert.equal(requestBoundaryDecision({
  url:"https://staging.egypttourgates.com/public/",method:"GET",
  resourceType:"document",origin,env,passiveOnly:true
}).allow,true);
assert.equal(requestBoundaryDecision({
  url:"https://staging.egypttourgates.com/wp-admin/admin-ajax.php",method:"POST",
  resourceType:"xhr",origin,env,passiveOnly:false
}).allow,true); // Legacy ETG AJAX parity retains its own reviewed policy.
const rules=[];
const fakeContext={
  async routeWebSocket(pattern, handler) { rules.push({type:"ws",pattern,handler}); },
  async route(pattern,handler) { rules.push({type:"http",pattern,handler}); }
};
const report=await installContextNetworkBoundary(fakeContext,origin,env,{passiveOnly:true});
assert.equal(report.websocket_network_denied,true);
assert.deepEqual(rules.map(r=>r.type),["ws","http"]); // Must arm socket denial first.
let closed=false;
await rules[0].handler({ close:async()=>{closed=true;} });
assert.equal(closed,true);
await assert.rejects(
  installContextNetworkBoundary({route:async()=>{}},origin,env,{passiveOnly:true}),
  /browser_websocket_boundary_unavailable/
);
console.log("MAD4B browser network boundary PASS");
