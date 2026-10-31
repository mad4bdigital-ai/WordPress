/** Pure central rollout planning and independent readback.
 * This is NOT an executor or approval ticket issuer. Never accepts a
 * client-supplied inventory as proof of trust: the governed Control Plane
 * must authenticate and bind inventory, issuer, target and artifact before
 * dispatching anything. No code in this module modifies a site.
 */
const sha=x=>typeof x==="string"&&/^[a-f0-9]{64}$/.test(x);
function ensure(ok,msg){if(!ok)throw Error(msg)}

function validateRelease(r){
 ensure(r?.contract==="mad4b.central-control-plane-release.v1","RELEASE_CONTRACT");
 ensure(r.target_plugin==="mad4b-site-control-plane/mad4b-site-control-plane.php","TARGET_PLUGIN");
 ensure(r.expected_live_base_version==="0.4.0-rc.118","WRONG_BASE");
 ensure(r.site_selection?.canary_site==="https://tours.dev.mad4b.com","CANARY_ORIGIN");
 if(r.release_artifact?.state==="VERIFIED"){
  ensure(sha(r.release_artifact.sha256),"ARTIFACT_SHA_REQUIRED");
  ensure(Object.values(r.release_artifact.critical_file_sha256||{}).length>=2 && Object.values(r.release_artifact.critical_file_sha256||{}).every(sha),"CRITICAL_HASHES");
 }else ensure(["NOT_BUILT","STAGED_UNVERIFIED"].includes(r.release_artifact?.state),"RELEASE_ARTIFACT_STATE");
 return true;
}

function plan(release,inventory){
 validateRelease(release);
 ensure(inventory?.contract==="mad4b.central-site-profile-inventory.v1"&&inventory.trusted_origin==="MAD4B_Site_Profile_Registry"&&sha(inventory.snapshot_sha256),"INVENTORY_NOT_TRUSTED");
 ensure(Array.isArray(inventory.sites),"SITES_MISSING");
 const seen=new Set(),sites=[];
 for(const s of inventory.sites){
  ensure(typeof s.site_uuid==="string"&&/^[a-f0-9-]{36}$/i.test(s.site_uuid)&&/^https:\/\/[a-z0-9][a-z0-9.-]+(?::[0-9]{2,5})?$/i.test(s.origin),"IDENTITY_INVALID");
  const key=s.site_uuid+"|"+s.origin;ensure(!seen.has(key),"DUPLICATE_SITE");seen.add(key);
  ensure(["staging","development","local","production"].includes(s.environment),"ENVIRONMENT_INVALID");
  let state;
  if(s.enrollment_state!=="BOUND_EXACT"||s.origin_bound!==true)state="BLOCKED_IDENTITY";
  else if(s.plugin_version!==release.expected_live_base_version||!sha(s.plugin_inventory_sha256))state="BLOCKED_SOURCE_DRIFT";
  else if(release.release_artifact.state!=="VERIFIED")state="BLOCKED_RELEASE_ARTIFACT";
  else if(s.environment==="production")state="BLOCKED_PRODUCTION_APPROVAL";
  else if(s.environment!=="staging")state="BLOCKED_UNSUPPORTED_ENVIRONMENT";
  else if(s.origin===release.site_selection.canary_site)state="READY_FOR_AUTHENTICATED_CANARY_PLAN";
  else state="WAIT_CANARY_VERIFIED";
  sites.push({site_uuid:s.site_uuid,origin:s.origin,environment:s.environment,source_inventory_sha256:s.plugin_inventory_sha256||null,state,write_authorized:false});
 }
 ensure(sites.filter(x=>x.origin===release.site_selection.canary_site).length===1,"CANARY_MISSING");
 return {contract:"mad4b.central-control-plane-rollout-plan.v1",operation:"control-plane.plugin-policy.rollout",snapshot_sha256:inventory.snapshot_sha256,base_version:release.expected_live_base_version,target_version:release.next_version_intent,artifact_sha256:release.release_artifact.sha256||null,canary:release.site_selection.canary_site,sites,mutation_executed:false,production_mutation:false};
}

function verify(plan,receipt){
 ensure(plan?.contract==="mad4b.central-control-plane-rollout-plan.v1","PLAN_INVALID");
 const s=plan.sites.find(x=>x.site_uuid===receipt?.site_uuid&&x.origin===receipt?.origin);
 if(!s)return {verified:false,reason:"IDENTITY"};
 if(s.environment!=="staging")return {verified:false,reason:"PRODUCTION_OR_NONSTAGING"};
 if(!sha(plan.artifact_sha256)||receipt?.artifact_sha256!==plan.artifact_sha256)return {verified:false,reason:"ARTIFACT"};
 if(receipt?.source_inventory_sha256!==s.source_inventory_sha256||receipt?.plugin_version!==plan.target_version)return {verified:false,reason:"SOURCE_OR_VERSION"};
 if(receipt?.provider_isolation_effective!==true)return {verified:false,reason:"ISOLATION"};
 if(receipt?.mcp_routes_verified!==true||receipt?.oauth_resource_metadata_verified!==true)return {verified:false,reason:"MCP_OAUTH"};
 if(receipt?.new_write_grants!==0||receipt?.customer_data_mutation!==false)return {verified:false,reason:"FORBIDDEN_EFFECTS"};
 if(receipt?.rollback_snapshot_verified!==true)return {verified:false,reason:"ROLLBACK"};
 return {verified:true,reason:"INDEPENDENT_READBACK_PASS"};
}

export {validateRelease,plan,verify};
