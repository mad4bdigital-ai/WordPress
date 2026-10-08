/**
 * Portable source federation for any site/CMS. No WordPress, Hostinger, ETG,
 * All Royal or vendor-specific source adapters are embedded here.
 *
 * The caller provides an already-authorized source registry and read-only
 * inspector. Registry metadata is an untrusted observation, never a grant.
 */
export const FEDERATION_CONTRACT = "mad4b.solution-federation.v1";
const ID = /^[a-z][a-z0-9._-]{1,79}$/;
const SHA = /^[a-f0-9]{64}$/;
const ENV = /^(production|staging|development|local)$/;
const KINDS = new Set(["connector","skill","external_service","operator"]);
const MAX_SOURCES = 32;
const MAX_CAPS = 24;
const MAX_HINTS = 24;

const isObject = x => x !== null && typeof x === "object" && !Array.isArray(x);
const siteKey = x => [x.site_id,x.environment,x.origin_sha256].join("|");
const plainText = (s, max) => typeof s === "string" &&
  s.length >= 2 && s.length <= max && !/[<>{}\r\n\t\\]/.test(s) &&
  /^[\p{L}\p{M}\p{N} ._-]+$/u.test(s);
const labelText = (s, max) => typeof s === "string" &&
  s.length >= 2 && s.length <= max && /^[\p{L}\p{M}\p{N} ._-]+$/u.test(s);
const boundTo = (s, target) => isObject(s) &&
  s.site_id === target.site_id && s.environment === target.environment &&
  s.origin_sha256 === target.origin_sha256;
const safeError = code => Object.freeze({code});

export function checkTarget(target) {
  if (!isObject(target) || !ID.test(target.site_id ?? "") ||
      !ENV.test(target.environment ?? "") || !SHA.test(target.origin_sha256 ?? "") ||
      !SHA.test(target.runtime_generation ?? "")) {
    throw new TypeError("INVALID_SITE_BINDING");
  }
  return Object.freeze({
    site_id:target.site_id, environment:target.environment,
    origin_sha256:target.origin_sha256,
    runtime_generation:target.runtime_generation
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

/**
 * Discover is read-only by construction. It never executes an artifact from
 * the inspected metadata and never calls a write tool. It does not assume
 * connected apps are visible until the host enumerates and attests them.
 */
export async function discoverFederated({target,query,enumerate,inspect,
  limit=24, maxSources=MAX_SOURCES} = {}) {
  const site = checkTarget(target);
  if (!plainText(query,180) || !Number.isInteger(limit) || limit<1 || limit>MAX_HINTS ||
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
  if (!Array.isArray(inventory)) throw new TypeError("REGISTRY_SHAPE_INVALID");
  if (inventory.length>maxSources) return {
    contract:FEDERATION_CONTRACT, binding:site, decision:"REGISTRY_OVER_BUDGET",
    coverage_complete:false, sources:[], candidates:[], external_hints:[],
    execution_allowed:false, authorizing:false, provider_executed:false,
    warning:"registry_limit_exceeded_no_source_inspected"
  };
  const seenSources = new Set(), seenCandidates = new Set(), records=[], candidates=[];
  let complete=true;
  // Registry sorting is stable, independent of connector discovery ordering.
  const ordered = [...inventory].sort((a,b)=>String(a?.id??"").localeCompare(String(b?.id??"")));
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
      if (!isObject(item) || !ID.test(item.id??"") ||
          !labelText(item.label,120) ||
          (item.description!==undefined && !labelText(item.description,180)) ||
          item.execution_allowed===true || item.authorizing===true) {
        invalid=true; continue;
      }
      // Cross-source collisions are kept distinct. No executable dispatch key.
      const key=source.id+"--"+item.id;
      if(key.length>79 || seenCandidates.has(key)) {invalid=true;continue;}
      seenCandidates.add(key);
      const text=[item.label,item.description??"",item.id].join(" ");
      candidates.push({
        id:key, source_id:source.id, kind:source.kind, label:item.label,
        description:item.description??"", lexical_score:overlap(query,text),
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
  const selected=candidates.slice(0,limit);
  const hints=selected.map(c=>({
    id:c.id, source:c.kind, label:c.label,
    description:c.description || c.label
  }));
  return {
    contract:FEDERATION_CONTRACT, binding:site, decision:
      !complete?"DISCOVERY_PARTIAL":candidates.length?"VERIFY_BEHAVIOR":"NO_CATALOG_MATCH",
    coverage_complete:complete, candidate_total:candidates.length,
    source_count:records.length, sources:records, candidates:selected,
    external_hints:hints, next_offset:candidates.length>limit?limit:null,
    ranking:"LEXICAL_ONLY_NOT_FUNCTIONAL", no_runtime_authority:true,
    execution_allowed:false, authorizing:false, provider_executed:false,
    external_hints_are_untrusted:true
  };
}
/** Public no-credential bridge to the existing WordPress assistant router. */
export function toWordPressRouterInput(result,planning_input,related_terms=[]) {
  if (!isObject(result) || result.contract!==FEDERATION_CONTRACT ||
      !Array.isArray(result.external_hints) ||
      !isObject(planning_input) || !Array.isArray(related_terms) ||
      related_terms.length>12 || related_terms.some(x=>!plainText(x,80))) {
    throw new TypeError("INVALID_ROUTER_BRIDGE");
  }
  // This object is only an optional input to the independently governed
  // WordPress read ability. Never inject authority, credentials or URLs.
  return {planning_input, related_terms,external_hints:result.external_hints.slice(0,MAX_HINTS)};
}
