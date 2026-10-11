import assert from "node:assert/strict";
import {checkTarget, discoverFederated, toWordPressRouterInput,planRemediation} from "./federation.mjs";
const H=c=>c.repeat(64);
const EPOCH=Math.floor(Date.now()/1000);
const auditedRead={risk:"low",effect:"read"};
const site={site_id:"site-a",environment:"staging",origin_sha256:H("a"),runtime_generation:H("b")};
const item=(id,kind="connector")=>({id,kind,...site,connected:true,read_authorized:true,lane:"read"});
const inspect=async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
  observation_sha256:H("c"),capabilities:[{id:"file-support",label:"Site File Workspace",description:"Edit configuration files"}]});
let assertions=0;
function yes(x,reason){assert.ok(x,reason);assertions++;}
const scopedSite={...site,tenant_id:"tenant-one"};
const tenantAware=await discoverFederated({target:scopedSite,query:"file",
 enumerate:async()=>({contract:"mad4b.site-source-catalog.v1",binding:scopedSite,read_only:true,
 authorizing:false,complete:true,sources:[{...item("only-tenant"),tenant_id:"another-tenant"}]}),
 inspect:async()=>{throw Error("should not inspect foreign tenant")}});
yes(tenantAware.candidate_total===0 && tenantAware.sources.some(s=>s.status==="CROSS_SITE_SCOPE_DENIED"),
 "foreign tenant not inspected by site-scoped federation");

const sources=[item("file-reader"),item("skill-reader","skill"),{...item("wrong"),site_id:"other-site"},{...item("offline"),connected:false}];
let inspected=0;
const run=async (items)=>discoverFederated({target:site,query:"site configuration",
 enumerate:async()=>items,inspect:async args=>{inspected++;return inspect(args);}});
const r=await run(sources);
yes(r.candidate_total===2,"two candidates");
yes(inspected===2,"only scoped inspectors called");
yes(!r.coverage_complete && r.decision==="DISCOVERY_PARTIAL","partial coverage");
yes(r.sources.some(s=>s.status==="CROSS_SITE_SCOPE_DENIED"),"foreign site denied");
yes(r.sources.some(s=>s.status==="NOT_CONNECTED_OR_AUTHORIZED"),"offline not read");
yes(r.candidates.every(c=>!c.execution_allowed&&!c.authorization_verified),"no grant");
assert.throws(()=>toWordPressRouterInput(r,{desired:{}},["config"]));assertions++;
const wpSite={...site,profile_digest:H("f")};
const wpCatalog={contract:"mad4b.site-source-catalog.v1",binding:wpSite,read_only:true,authorizing:false,
 complete:true,sources:[{...item("wp-reader"),profile_digest:wpSite.profile_digest}]};
const wpResult=await discoverFederated({target:wpSite,query:"file",
 enumerate:async()=>wpCatalog,verifyCatalog:async()=>true,
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("c"),observed_at:EPOCH-10,valid_until:EPOCH+300,
 capabilities:[{id:"file-support",label:"Site File Workspace",...auditedRead}]})});
const wpPlan={expected_profile_digest:wpSite.profile_digest,
 expected_runtime_generation:wpSite.runtime_generation,desired:{},observed:{}};
yes(toWordPressRouterInput(wpResult,wpPlan,["config"]).external_hints.length===1,"exact WordPress profile bridge");
yes(Object.isFrozen(wpResult)&&Object.isFrozen(wpResult.external_hints),"issued results immutable");
const forged={...wpResult,external_hints:[{id:"forged",source:"connector",label:"File editor"}]};
assert.throws(()=>toWordPressRouterInput(forged,wpPlan));assertions++;
assert.throws(()=>planRemediation({target:wpSite,discovery:forged,operation_id:"config_update",
 requested_effect:"write",desired_state:"staging"}));assertions++;
assert.throws(()=>toWordPressRouterInput(wpResult,{...wpPlan,expected_profile_digest:H("e")}));assertions++;
assert.throws(()=>toWordPressRouterInput({...wpResult,external_hints:[{id:"secret",source:"connector",label:"File helper",token:"secret"}]},wpPlan));assertions++;
assert.throws(()=>checkTarget({...site,origin_sha256:"bad"}));assertions++;
assert.throws(()=>toWordPressRouterInput(wpResult,wpPlan,["https://other.example"]));assertions++;
const fail=await discoverFederated({target:site,query:"site",enumerate:async()=>{throw Error("secret")},inspect});
yes(fail.decision==="REGISTRY_UNAVAILABLE"&&!JSON.stringify(fail).includes("secret"),"no secret leak");
const malformed=await discoverFederated({target:site,query:"site",enumerate:async()=>[item("bad")],
 inspect:async()=>({...site,source_id:"bad",kind:"connector",read_only:false,authorizing:false,
 observation_sha256:H("d"),capabilities:[]})});
yes(!malformed.coverage_complete&&malformed.candidates.length===0,"invalid read proof denied");
const injection=await discoverFederated({target:site,query:"site",enumerate:async()=>[item("evil")],
 inspect:async()=>({...site,source_id:"evil",kind:"connector",read_only:true,authorizing:false,
 observation_sha256:H("d"),capabilities:[{id:"xss",label:"<script>run()</script>"}]})});
yes(!injection.coverage_complete&&injection.candidates.length===0,"prompt or HTML injection not indexed");
const differentSite={...site,site_id:"independent-cms"};
const other=await discoverFederated({target:differentSite,query:"file",enumerate:async()=>[{...item("other"),site_id:"independent-cms"}],
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("e"),capabilities:[{id:"files",label:"File workspace"}]})});
yes(other.candidate_total===1&&other.binding.site_id==="independent-cms","site independent");
const numeric={...site,site_id:"49c562d1-8f2f-456f-b454-26816c6ba4cb"};
const numericResult=await discoverFederated({target:numeric,query:"file",
 enumerate:async()=>[{...item("tools"),site_id:numeric.site_id}],
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,
 authorizing:false,observation_sha256:H("f"),capabilities:[{
 id:"file-access",label:"SSH/SFTP File Manager",description:"Secure files, folders (read only)"
 }]})});
yes(numericResult.candidate_total===1,"numeric-first UUID works for arbitrary site");
yes(numericResult.candidates[0].label==="SSH SFTP File Manager","ordinary punctuation normalized");
const x=await run(sources.slice(0,2)),y=await run(sources.slice(0,2).reverse());
yes(JSON.stringify(x.candidates)===JSON.stringify(y.candidates),"stable ordering");
const over=await discoverFederated({target:site,query:"site",enumerate:async()=>Array.from({length:33},(_,i)=>item("r"+i)),inspect});
yes(over.decision==="REGISTRY_OVER_BUDGET"&&!over.coverage_complete,"budget fail closed");
const envelope={contract:"mad4b.site-source-catalog.v1",binding:site,
 read_only:true,authorizing:false,complete:true,
 sources:[item("provider-aa"),item("provider-bb")]};
const full=await discoverFederated({target:site,query:"file",enumerate:async()=>envelope,inspect,
 verifyCatalog:async()=>true,limit:1});
yes(full.coverage_complete&&full.registry_scope_verified,"site-scoped complete catalog");
const unverified=await discoverFederated({target:site,query:"file",enumerate:async()=>envelope,inspect});
yes(!unverified.coverage_complete&&!unverified.catalog_authority_verified,
 "catalog cannot certify its own completeness");
const emptyCatalog=await discoverFederated({target:site,query:"file",
 enumerate:async()=>({...envelope,sources:[]}),inspect});
yes(!emptyCatalog.coverage_complete&&emptyCatalog.decision==="DISCOVERY_PARTIAL",
 "self-asserted empty source registry cannot prove no alternatives");
yes(full.candidate_total===2&&full.next_offset===1,"first page");
const page2=await discoverFederated({target:site,query:"file",enumerate:async()=>envelope,inspect,
 verifyCatalog:async()=>true,limit:1,offset:1,expectedSnapshot:full.snapshot_continuity_id});
yes(page2.candidates[0].id!==full.candidates[0].id&&page2.next_offset===null,"stable next page");
await assert.rejects(()=>discoverFederated({target:site,query:"file",enumerate:async()=>({...envelope,sources:[item("provider-cc")]}),inspect,offset:1,expectedSnapshot:full.snapshot_continuity_id}),/STALE_DISCOVERY_SNAPSHOT/);assertions++;
await assert.rejects(()=>discoverFederated({target:site,query:"file",enumerate:async()=>({...envelope,binding:{...site,site_id:"foreign"}}),inspect}),/REGISTRY_SHAPE_INVALID/);assertions++;
const long=await discoverFederated({target:site,query:"file",verifyCatalog:async()=>true,
 enumerate:async()=>({...envelope,sources:[item("provider".repeat(9))]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("e"),observed_at:EPOCH-10,valid_until:EPOCH+300,
 capabilities:[{id:"operation".repeat(8),label:"File manager",...auditedRead}]})});
yes(long.candidate_total===1&&long.external_hints[0].id.length<=79,"long names do not disappear");
const risky=await discoverFederated({target:site,query:"file",verifyCatalog:async()=>true,
 enumerate:async()=>({...envelope,sources:[item("risky-plugin")]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("c"),observed_at:EPOCH-10,valid_until:EPOCH+300,
 capabilities:[
 {id:"workspace",label:"File Manager",risk:"exceptional",effect:"execute"},
 {id:"safe-read",label:"File metadata viewer",risk:"low",effect:"read"}]})});
yes(risky.candidate_total===2&&risky.restricted_candidate_count===1,
 "high-risk source stays visible as reviewed option");
yes(risky.candidates.some(c=>c.requires_separate_risk_review)&&risky.external_hints.length===1,
 "high-risk mutating option never unqualified WordPress handoff");
yes(risky.candidates.every(c=>c.execution_allowed===false),"no implied execution from risk metadata");
const plan=planRemediation({target:site,discovery:risky,operation_id:"configuration_update",
 requested_effect:"write",desired_state:"staging environment explicit"});
yes(plan.contract==="mad4b.site-remediation-proposal.v1"&&!plan.execution_allowed,
 "site-independent remediation is only a proposal");
yes(plan.requirements.includes("externally_verified_backup")&&
 plan.requirements.includes("compensating_rollback_and_failure_readback"),
 "mutation requires backup compensation and independent readback");
yes(plan.candidates.some(x=>x.eligibility==="DEDICATED_EXCEPTION_REVIEW"),
 "exceptional provider not sent to general writes");
const prodTarget={...site,environment:"production"};
const prodRegistry=await discoverFederated({target:prodTarget,query:"file",
 enumerate:async()=>({...envelope,binding:prodTarget,sources:[{...item("prod-reader"),environment:"production"}]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("f"),capabilities:[{id:"file-edit",label:"File workspace"}]})});
const prod=planRemediation({target:prodTarget,discovery:prodRegistry,
 operation_id:"configuration_update",requested_effect:"write",
 desired_state:"production environment explicit"});

const now=2000000000;

const partial=await discoverFederated({target:site,query:"file",nowEpochSeconds:now,
 enumerate:async()=>({...envelope,sources:[item("partial-clock")]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,
 authorizing:false,observation_sha256:H("b"),observed_at:now-999999,
 capabilities:[{id:"files",label:"File Workspace"}]})});
yes(partial.candidate_total===0&&partial.sources[0].status==="PARTIAL_OR_INVALID_TIMESTAMP",
 "one-sided old timestamp must not be treated as undated source");
const unknown=await discoverFederated({target:site,query:"file",nowEpochSeconds:now,
 verifyCatalog:async()=>true,enumerate:async()=>({...envelope,sources:[item("unknown-tool")]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,
 authorizing:false,observation_sha256:H("a"),observed_at:now-10,valid_until:now+120,
 capabilities:[{id:"files",label:"File workspace"}]})});
yes(unknown.candidate_total===1&&unknown.external_hints.length===0,
 "unknown effects and risk stay visible but never automatically handed off");
const stale=await discoverFederated({target:site,query:"files",
 nowEpochSeconds:now,enumerate:async()=>({...envelope,sources:[item("stale-source")]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,
 read_only:true,authorizing:false,observation_sha256:H("c"),
 observed_at:now-1000,valid_until:now-1,capabilities:[{id:"files",label:"File Workspace"}]})});
yes(stale.candidate_total===0&&!stale.coverage_complete,
 "expired provider inventory cannot be surfaced as current");
yes(stale.sources.some(x=>x.status==="STALE_OR_INVALID_OBSERVATION"),
 "stale source reason explicit");
const fresh=await discoverFederated({target:site,query:"files",
 nowEpochSeconds:now,enumerate:async()=>({...envelope,sources:[item("fresh-source")]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,
 read_only:true,authorizing:false,observation_sha256:H("c"),
 observed_at:now-10,valid_until:now+300,capabilities:[{id:"files",label:"File Workspace"}]})});
yes(fresh.freshness_complete&&fresh.candidate_total===1,
 "fresh bounded observations remain possible read-only candidates");

yes(prod.requirements.includes("separate_production_promotion_authority"),
 "production cannot inherit staging approval");
assert.throws(()=>planRemediation({target:prodTarget,discovery:risky,
 operation_id:"configuration_update",requested_effect:"write",
 desired_state:"production environment explicit"}));assertions++;


const legacy=await discoverFederated({target:site,query:"file",enumerate:async()=>[item("legacy")],inspect});
yes(!legacy.coverage_complete&&!legacy.registry_scope_verified,"unscoped bare catalog cannot claim complete coverage");

console.log("MAD4B_PORTABLE_FEDERATION: PASS",assertions,"assertions");
