/**
 * Portable source federation for any site/CMS. No WordPress, Hostinger, ETG,
 * All Royal or vendor-specific source adapters are embedded here.
 *
 * The caller provides an already-authorized source registry and read-only
 * inspector. Registry metadata is an untrusted observation, never a grant.
 */
export const FEDERATION_CONTRACT = "mad4b.solution-federation.v1";
const ID = /^[a-z0-9][a-z0-9._-]{1,79}$/;
const SHA = /^[a-f0-9]{64}$/;
const ENV = /^(production|staging|development|local)$/;
const KINDS = new Set(["connector","skill","external_service","operator"]);
const MAX_SOURCES = 32;
const MAX_CAPS = 24;
const MAX_HINTS = 24;

const isObject = x => x !== null && typeof x === "object" && !Array.isArray(x);
const plainText = (s, max) => typeof s === "string" &&
  s.length >= 2 && s.length <= max && !/[<>{}\r\n\t\\]/.test(s) &&
  /^[\p{L}\p{M}\p{N} ._-]+$/u.test(s);
// Normalize harmless separator punctuation to match the WordPress read
// schema. Reject markup/control characters rather than laundering commands.
const safeLabel = (s,max) => {
  if (typeof s!=="string" || s.length<2 || s.length>max*3 ||
      /[<>{}\\\x00-\x1f]/.test(s)) return null;
  const text=s.replace(/[^\p{L}\p{M}\p{N} ._-]+/gu," ")
    .replace(/ +/g," ").trim();
  return text.length>=2 && text.length<=max &&
    /^[\p{L}\p{N}][\p{L}\p{M}\p{N} ._-]+$/u.test(text) ? text : null;
};
const boundTo = (s, target) => isObject(s) &&
  s.site_id === target.site_id && s.environment === target.environment &&
  s.origin_sha256 === target.origin_sha256 && s.runtime_generation === target.runtime_generation &&
  (!target.profile_digest || s.profile_digest === target.profile_digest);

export function checkTarget(target) {
  if (!isObject(target) || !ID.test(target.site_id ?? "") ||
      !ENV.test(target.environment ?? "") || !SHA.test(target.origin_sha256 ?? "") ||
      !SHA.test(target.runtime_generation ?? "")) {
    throw new TypeError("INVALID_SITE_BINDING");
  }
  if (target.profile_digest!==undefined && !SHA.test(target.profile_digest))
    throw new TypeError("INVALID_WORDPRESS_PROFILE");
  return Object.freeze({
    site_id:target.site_id, environment:target.environment,
    origin_sha256:target.origin_sha256,
    runtime_generation:target.runtime_generation,
    ...(target.profile_digest ? {profile_digest:target.profile_digest} : {})
  });
}

const normalizeName = s => s.toLocaleLowerCase("en").normalize("NFD")
  .replace(/\p{M}/gu,"").match(/[\p{L}\p{N}]{2,}/gu) ?? [];
const overlap = (query, label) => {
  const terms = new Set(normalizeName(query));
  return [...new Set(normalizeName(label))].filter(x=>terms.has(x)).length;
};
const summary = (source, code) => ({
  source_id:source.id, kind:source.kind, status:code
});
// Non-cryptographic continuity checksum ONLY. Never a signature or grant.
const continuity = input => {
  let h=0xcbf29ce484222325n;
  for(let i=0;i<input.length;i++)
    h=BigInt.asUintN(64,(h^BigInt(input.charCodeAt(i)))*0x100000001b3n);
  return h.toString(16).padStart(16,"0");
};

/**
 * Discover is read-only by construction. It never executes an artifact from
 * the inspected metadata and never calls a write tool. It does not assume
 * connected apps are visible until the host enumerates and attests them.
 */
export async function discoverFederated({target,query,enumerate,inspect,
  limit=24,offset=0,expectedSnapshot=null,maxSources=MAX_SOURCES} = {}) {
  const site = checkTarget(target);
  if (!plainText(query,180) || !Number.isInteger(limit) || limit<1 || limit>MAX_HINTS ||
      !Number.isInteger(offset) || offset<0 || offset>1024 ||
      (offset>0 && !/^[a-f0-9]{16}$/.test(expectedSnapshot??"")) ||
      (offset===0 && expectedSnapshot!==null && !/^[a-f0-9]{16}$/.test(expectedSnapshot)) ||
      !Number.isInteger(maxSources) || maxSources<1 || maxSources>MAX_SOURCES ||
      typeof enumerate !== "function" || typeof inspect !== "function") {
    throw new TypeError("INVALID_FEDERATION_INPUT");
  }
  let inventory;
  try { inventory = await enumerate(site); }
  catch (_) { return {
    contract:FEDERATION_CONTRACT, binding:site, decision:"REGISTRY_UNAVAILABLE",
    coverage_complete:false, sources:[], candidates:[], external_hints:[],
    execution_allowed:false, authorizing:false, provider_executed:false,
    warning:"enumeration_failed_no_source_trusted"
  }; }
  // Legacy bare-array registries have UNKNOWN completeness.
  const envelope=isObject(inventory)?inventory:null;
  const scoped=envelope && envelope.contract==="mad4b.site-source-catalog.v1" &&
    boundTo(envelope.binding,site) && envelope.read_only===true &&
    envelope.authorizing===false && Array.isArray(envelope.sources);
  const registered=scoped?envelope.sources:Array.isArray(inventory)?inventory:null;
  if (!registered) throw new TypeError("REGISTRY_SHAPE_INVALID");
  const registryComplete=Boolean(scoped && envelope.complete===true);
  if (registered.length>maxSources) return {
    contract:FEDERATION_CONTRACT, binding:site, decision:"REGISTRY_OVER_BUDGET",
    coverage_complete:false, sources:[], candidates:[], external_hints:[],
    execution_allowed:false, authorizing:false, provider_executed:false,
    warning:"registry_limit_exceeded_no_source_inspected"
  };
  const seenSources = new Set(), seenCandidates = new Set(), records=[], candidates=[];
  let complete=registryComplete;
  // Registry sorting is stable, independent of connector discovery ordering.
  const ordered = [...registered].sort((a,b)=>String(a?.id??"").localeCompare(String(b?.id??"")));
  for(const source of ordered) {
    if (!isObject(source) || !ID.test(source.id??"") ||
        !KINDS.has(source.kind) || seenSources.has(source.id)) {
      complete=false;
      records.push({source_id:"redacted",status:"INVALID_OR_DUPLICATE_DESCRIPTOR"});
      continue;
    }
    seenSources.add(source.id);
    if (!boundTo(source,site)) {
      complete=false; records.push(summary(source,"CROSS_SITE_SCOPE_DENIED"));continue;
    }
    if (source.connected!==true || source.read_authorized!==true ||
        source.lane!=="read") {
      complete=false; records.push(summary(source,"NOT_CONNECTED_OR_AUTHORIZED"));continue;
    }
    let observed;
    try { observed = await inspect({site, source_id:source.id, kind:source.kind, lane:"read"}); }
    catch (_) {
      complete=false; records.push(summary(source,"INSPECTION_FAILED"));continue;
    }
    if (!isObject(observed) || !boundTo(observed,site) ||
        observed.source_id!==source.id || observed.kind!==source.kind ||
        observed.read_only!==true || observed.authorizing!==false ||
        !Array.isArray(observed.capabilities) || observed.capabilities.length>MAX_CAPS ||
        !SHA.test(observed.observation_sha256??"")) {
      complete=false; records.push(summary(source,"INSPECTION_INVALID_OR_STALE"));continue;
    }
    let accepted=0, invalid=false;
    for(const item of observed.capabilities) {
      const title=isObject(item)?safeLabel(item.label,120):null;
      const detail=isObject(item) && item.description!==undefined ?
        safeLabel(item.description,180):null;
      if (!isObject(item) || !ID.test(item.id??"") || !title ||
          (item.description!==undefined && !detail) ||
          item.execution_allowed===true || item.authorizing===true) {
        invalid=true; continue;
      }
      // Cross-source collisions are kept distinct. No executable dispatch key.
      const raw=source.id+"--"+item.id;
      const key=raw.length<=79?raw:
        source.id.slice(0,29)+"--"+item.id.slice(0,29)+"-"+continuity(raw);
      if(key.length>79 || seenCandidates.has(key)) {invalid=true;continue;}
      seenCandidates.add(key);
      const text=[title,detail??"",item.id].join(" ");
      candidates.push({
        id:key, source_id:source.id, kind:source.kind, label:title,
        description:detail??"", lexical_score:overlap(query,text),
        observed_state:"source_claimed", evidence_state:"UNVERIFIED_METADATA",
        observation_sha256:observed.observation_sha256,
        site_id:site.site_id, environment:site.environment,
        execution_allowed:false, authorization_verified:false,
        metadata_is_untrusted:true
      });
      accepted++;
    }
    if(invalid) complete=false;
    records.push(summary(source,invalid?"PARTIAL_INVALID_ROWS":"INSPECTED") );
  }
  // Lexical score only ranks candidates, it cannot verify their behavior.
  candidates.sort((a,b)=>(b.lexical_score-a.lexical_score)||a.id.localeCompare(b.id));
  const snapshot=continuity(JSON.stringify([
    site,records,candidates.map(x=>[x.id,x.label,x.description,x.observation_sha256])
  ]));
  if(expectedSnapshot!==null && expectedSnapshot!==snapshot)
    throw new TypeError("STALE_DISCOVERY_SNAPSHOT");
  const selected=candidates.slice(offset,offset+limit);
  const hints=selected.map(c=>({
    id:c.id, source:c.kind, label:c.label,
    description:c.description || c.label
  }));
  return {
    contract:FEDERATION_CONTRACT, binding:site, decision:
      !complete?"DISCOVERY_PARTIAL":candidates.length?"VERIFY_BEHAVIOR":"EXPAND_DISCOVERY",
    coverage_complete:complete, registry_scope_verified:Boolean(scoped),
    candidate_total:candidates.length, snapshot_continuity_id:snapshot, offset, limit,
    source_count:records.length, sources:records, candidates:selected,
    external_hints:hints, next_offset:candidates.length>offset+limit?offset+limit:null,
    ranking:"LEXICAL_ONLY_NOT_FUNCTIONAL", no_runtime_authority:true,
    execution_allowed:false, authorizing:false, provider_executed:false,
    external_hints_are_untrusted:true
  };
}
/** Public no-credential bridge to the existing WordPress assistant router. */
export function toWordPressRouterInput(result,planning_input,related_terms=[]) {
  if (!isObject(result) || result.contract!==FEDERATION_CONTRACT ||
      result.execution_allowed!==false || result.authorizing!==false ||
      !isObject(result.binding) || !SHA.test(result.binding.profile_digest??"") ||
      !Array.isArray(result.external_hints) || result.external_hints.length>MAX_HINTS ||
      !isObject(planning_input) || !Array.isArray(related_terms) ||
      related_terms.length>12 || related_terms.some(x=>!plainText(x,80))) {
    throw new TypeError("INVALID_ROUTER_BRIDGE");
  }
  // Site profile and runtime are specific to WordPress, not a requirement
  // on unrelated CMSs. These MUST be present before crossing into WP.
  if (planning_input.expected_profile_digest!==result.binding.profile_digest ||
      planning_input.expected_runtime_generation!==result.binding.runtime_generation)
    throw new TypeError("CROSS_SITE_ROUTER_BINDING");
  const hints=result.external_hints;
  for(const h of hints) {
    if(!isObject(h) || Object.keys(h).some(k=>!["id","source","label","description"].includes(k)) ||
       !ID.test(h.id??"") || !KINDS.has(h.source) ||
       !safeLabel(h.label,120) || (h.description!==undefined&&!safeLabel(h.description,180)))
      throw new TypeError("UNSAFE_ROUTER_HINT");
  }
  // No URLs, tokens, executable references or authority material are accepted.
  return {planning_input,related_terms,external_hints:hints.map(h=>({
    id:h.id,source:h.source,label:h.label,description:h.description??h.label
  }))};
}
