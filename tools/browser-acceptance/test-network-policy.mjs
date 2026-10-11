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
// A passive canonical/DOM probe must not issue subresource GETs; images,
// stylesheets, fonts, favicons and preloads can have server-side effects.
for (const testCase of [
  {url:origin+"delete-booking?id=42",resourceType:"image",method:"GET"},
  {url:origin+"public/?trigger=1",resourceType:"stylesheet",method:"GET"},
  {url:origin+"public/",resourceType:"font",method:"GET"},
  {url:"https://cdn.example.com/logo.png",resourceType:"image",method:"GET"},
  {url:"data:image/svg+xml,hello",resourceType:"image",method:"GET"},
  {url:"about:blank",resourceType:"document",method:"GET"}
]) {
  const decision=requestBoundaryDecision({...testCase,origin,env,passiveOnly:true,
    allowedDocumentPaths:["/public/"]});
  assert.equal(decision.allow,false,"passive subresource/URL cannot reach network");
}
assert.equal(requestBoundaryDecision({
  url:origin+"public/",resourceType:"image",method:"GET",origin,env,passiveOnly:true
}).reason,"passive_subresource_denied");
assert.equal(requestBoundaryDecision({
  url:"https://staging.egypttourgates.com/public/",method:"GET",
  resourceType:"document",origin,env,passiveOnly:true,
  allowedDocumentPaths:["/public/"]
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
const report=await installContextNetworkBoundary(fakeContext,origin,env,{
  passiveOnly:true,allowedDocumentPaths:["/public/"]
});
assert.equal(report.websocket_network_denied,true);
assert.deepEqual(rules.map(r=>r.type),["ws","http"]); // Must arm socket denial first.
let closed=false;
await rules[0].handler({ close:async()=>{closed=true;} });
assert.equal(closed,true);
await assert.rejects(
  installContextNetworkBoundary({route:async()=>{}},origin,env,{
    passiveOnly:true,allowedDocumentPaths:["/public/"]
  }),
  /browser_websocket_boundary_unavailable/
);
for (const testCase of [
  {url:origin+"wp-json/data",resourceType:"document",method:"GET"},
  {url:origin+"other/",resourceType:"document",method:"GET"},
  {url:origin+"public/?mutate=1",resourceType:"document",method:"GET"},
  {url:origin+"public/",resourceType:"script",method:"GET"},
  {url:origin+"public/",resourceType:"xhr",method:"GET"},
  {url:"https://cdn.example.com/public/",resourceType:"document",method:"GET"}
]) {
  assert.equal(requestBoundaryDecision({...testCase,origin,env,passiveOnly:true,
    allowedDocumentPaths:["/public/"]}).allow,false);
}
assert.equal(requestBoundaryDecision({
  url:origin+"public/",resourceType:"document",method:"GET",origin,env,
  passiveOnly:true,allowedDocumentPaths:["/public/"]
}).allow,true);
// Signed paths cannot be replay-loaded as nested iframe documents.
let blockedSubframe=false;
await rules[1].handler({
  request:()=>({url:()=>origin+"public/",resourceType:()=>"document",
    method:()=>"GET",frame:()=>({parentFrame:()=>({})})}),
  abort:async()=>{blockedSubframe=true;},
  continue:async()=>{throw Error("subframe_was_allowed");}
});
assert.equal(blockedSubframe,true);
let continuedMainframe=false;
await rules[1].handler({
  request:()=>({url:()=>origin+"public/",resourceType:()=>"document",
    method:()=>"GET",frame:()=>({parentFrame:()=>null})}),
  abort:async()=>{throw Error("mainframe_was_blocked");},
  continue:async()=>{continuedMainframe=true;}
});
assert.equal(continuedMainframe,true);
assert.equal(report.exact_signed_document_paths,1);
await assert.rejects(installContextNetworkBoundary(fakeContext,origin,env,{
  passiveOnly:true,allowedDocumentPaths:[]
}),/browser_passive_document_scope_invalid/);
console.log("MAD4B browser network boundary PASS");
