import assert from "node:assert/strict";
import {checkTarget, discoverFederated, toWordPressRouterInput} from "./federation.mjs";
const H=c=>c.repeat(64);
const site={site_id:"site-a",environment:"staging",origin_sha256:H("a"),runtime_generation:H("b")};
const item=(id,kind="connector")=>({id,kind,...site,connected:true,read_authorized:true,lane:"read"});
const inspect=async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
  observation_sha256:H("c"),capabilities:[{id:"file-support",label:"Site File Workspace",description:"Edit configuration files"}]});
let assertions=0;
function yes(x,reason){assert.ok(x,reason);assertions++;}
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
yes(toWordPressRouterInput(r,{desired:{}},["config"]).external_hints.length===2,"governed WP transport shape");
assert.throws(()=>checkTarget({...site,origin_sha256:"bad"}));assertions++;
assert.throws(()=>toWordPressRouterInput(r,{},["https://other.example"]));assertions++;
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
const x=await run(sources.slice(0,2)),y=await run(sources.slice(0,2).reverse());
yes(JSON.stringify(x.candidates)===JSON.stringify(y.candidates),"stable ordering");
const over=await discoverFederated({target:site,query:"site",enumerate:async()=>Array.from({length:33},(_,i)=>item("r"+i)),inspect});
yes(over.decision==="REGISTRY_OVER_BUDGET"&&!over.coverage_complete,"budget fail closed");
const envelope={contract:"mad4b.site-source-catalog.v1",binding:site,
 read_only:true,authorizing:false,complete:true,
 sources:[item("provider-aa"),item("provider-bb")]};
const full=await discoverFederated({target:site,query:"file",enumerate:async()=>envelope,inspect,limit:1});
yes(full.coverage_complete&&full.registry_scope_verified,"site-scoped complete catalog");
yes(full.candidate_total===2&&full.next_offset===1,"first page");
const page2=await discoverFederated({target:site,query:"file",enumerate:async()=>envelope,inspect,limit:1,offset:1,expectedSnapshot:full.snapshot_continuity_id});
yes(page2.candidates[0].id!==full.candidates[0].id&&page2.next_offset===null,"stable next page");
await assert.rejects(()=>discoverFederated({target:site,query:"file",enumerate:async()=>({...envelope,sources:[item("provider-cc")]}),inspect,offset:1,expectedSnapshot:full.snapshot_continuity_id}),/STALE_DISCOVERY_SNAPSHOT/);assertions++;
await assert.rejects(()=>discoverFederated({target:site,query:"file",enumerate:async()=>({...envelope,binding:{...site,site_id:"foreign"}}),inspect}),/REGISTRY_SHAPE_INVALID/);assertions++;
const long=await discoverFederated({target:site,query:"file",
 enumerate:async()=>({...envelope,sources:[item("provider".repeat(9))]}),
 inspect:async ({site,source_id,kind})=>({...site,source_id,kind,read_only:true,authorizing:false,
 observation_sha256:H("e"),capabilities:[{id:"operation".repeat(8),label:"File manager"}]})});
yes(long.candidate_total===1&&long.external_hints[0].id.length<=79,"long names do not disappear");
const legacy=await discoverFederated({target:site,query:"file",enumerate:async()=>[item("legacy")],inspect});
yes(!legacy.coverage_complete&&!legacy.registry_scope_verified,"unscoped bare catalog cannot claim complete coverage");

console.log("MAD4B_PORTABLE_FEDERATION: PASS",assertions,"assertions");
