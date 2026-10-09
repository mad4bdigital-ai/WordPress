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
const RISKS = new Set(["low","medium","high","exceptional","unknown"]);
const EFFECTS = new Set(["read","write","execute","unknown"]);
const MAX_SOURCES = 32;
const MAX_CAPS = 24;
const MAX_HINTS = 24;
// In-process provenance only. Serializable output is NOT a signed/portable receipt.
const ISSUED_DISCOVERIES = new WeakSet();
const publish = result => {
  for (const name of ["sources","candidates","external_hints"]) {
    if(Array.isArray(result[name])) {
      for(const value of result[name]) Object.freeze(value);
      Object.freeze(result[name]);
    }
  }
  Object.freeze(result);
  ISSUED_DISCOVERIES.add(result);
  return result;
};

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
  limit=24,offset=0,expectedSnapshot=null,maxSources=MAX_SOURCES,
  nowEpochSeconds=Math.floor(Date.now()/1000), verifyCatalog} = {}) {
  const site = checkTarget(target);
  if (!plainText(query,180) || !Number.isInteger(limit) || limit<1 || limit>MAX_HINTS ||
      !Number.isInteger(offset) || offset<0 || offset>1024 ||
      !Number.isSafeInteger(nowEpochSeconds) || nowEpochSeconds<0 ||
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
  // A catalog cannot certify its own completeness. Only the separately
  // supplied, host-controlled verifier may attest inventory coverage.
  let catalogAuthorityVerified=false;
  if(scoped && typeof verifyCatalog==="function") {
    try {
      catalogAuthorityVerified=(await verifyCatalog({
        site, expected_source_ids:envelope.sources
          .map(x=>x?.id).filter(x=>typeof x==="string").sort(),
        claimed_complete:envelope.complete===true, contract:envelope.contract
      }))===true;
    } catch (_) { catalogAuthorityVerified=false; }
  }
  const registryComplete=Boolean(scoped && envelope.complete===true && catalogAuthorityVerified);
  if (registered.length>maxSources) return {
    contract:FEDERATION_CONTRACT, binding:site, decision:"REGISTRY_OVER_BUDGET",
    coverage_complete:false, sources:[], candidates:[], external_hints:[],
    execution_allowed:false, authorizing:false, provider_executed:false,
    warning:"registry_limit_exceeded_no_source_inspected"
  };
  const seenSources = new Set(), seenCandidates = new Set(), records=[], candidates=[];
  let complete=registryComplete;
  let freshnessComplete=true;
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
    const hasObserved=Object.prototype.hasOwnProperty.call(observed,"observed_at");
    const hasExpiry=Object.prototype.hasOwnProperty.call(observed,"valid_until");
    const clocked=Number.isSafeInteger(observed.observed_at) &&
      Number.isSafeInteger(observed.valid_until);
    if(hasObserved!==hasExpiry || ((hasObserved || hasExpiry) && !clocked)) {
      complete=false; freshnessComplete=false;
      records.push(summary(source,"PARTIAL_OR_INVALID_TIMESTAMP"));continue;
    }
    if (clocked && (observed.observed_at>nowEpochSeconds+60 ||
        observed.valid_until<nowEpochSeconds ||
        observed.valid_until<observed.observed_at ||
        observed.valid_until-observed.observed_at>86400)) {
      complete=false;freshnessComplete=false;
      records.push(summary(source,"STALE_OR_INVALID_OBSERVATION"));continue;
    }
    if(!clocked) freshnessComplete=false;
    for(const item of observed.capabilities) {
      const title=isObject(item)?safeLabel(item.label,120):null;
      const detail=isObject(item) && item.description!==undefined ?
        safeLabel(item.description,180):null;
      if (!isObject(item) || !ID.test(item.id??"") || !title ||
          (item.description!==undefined && !detail) ||
          (item.risk!==undefined && !RISKS.has(item.risk)) ||
          (item.effect!==undefined && !EFFECTS.has(item.effect)) ||
          item.execution_allowed===true || item.authorizing===true) {
        invalid=true; continue;
      }
      // Cross-source collisions are kept distinct. No executable dispatch key.
      const raw=source.id+"--"+item.id;
      const key=raw.length<=79?raw:
        source.id.slice(0,29)+"--"+item.id.slice(0,29)+"-"+continuity(raw);
      if(key.length>79 || seenCandidates.has(key)) {invalid=true;continue;}
      seenCandidates.add(key);
      const risk=item.risk??"unknown",effect=item.effect??"unknown";
      const exceptional=risk==="high"||risk==="exceptional" ||
        effect==="execute"||effect==="write";
      const safeReadClaim=(risk==="low"||risk==="medium") && effect==="read";
      // Unclassified metadata stays visible but is never eligible for
      // automatic hints, even if lexical similarity is perfect.
      const safeForHint=safeReadClaim && clocked && catalogAuthorityVerified;
      const text=[title,detail??"",item.id].join(" ");
      candidates.push({
        id:key, source_id:source.id, kind:source.kind, label:title,
        description:detail??"", lexical_score:overlap(query,text),
        observed_state:"source_claimed", evidence_state:"UNVERIFIED_METADATA",
        declared_risk:risk,declared_effect:effect,requires_separate_risk_review:exceptional,
        hint_eligible:safeForHint,qualification_state:safeForHint?
          "READ_METADATA_CANDIDATE":"SEPARATE_QUALIFICATION_REQUIRED",
        observation_sha256:observed.observation_sha256,
        site_id:site.site_id, environment:site.environment,
        execution_allowed:false, authorization_verified:false,
        metadata_is_untrusted:true
      });
      accepted++;
    }
    if(invalid) complete=false;
    records.push(summary(source,invalid?"PARTIAL_INVALID_ROWS":
      clocked?"INSPECTED_FRESH":"INSPECTED_UNDATED") );
  }
  // Lexical score only ranks candidates, it cannot verify their behavior.
  candidates.sort((a,b)=>(b.lexical_score-a.lexical_score)||a.id.localeCompare(b.id));
  const snapshot=continuity(JSON.stringify([
    site,records,candidates.map(x=>[x.id,x.label,x.description,x.observation_sha256])
  ]));
  if(expectedSnapshot!==null && expectedSnapshot!==snapshot)
    throw new TypeError("STALE_DISCOVERY_SNAPSHOT");
  const selected=candidates.slice(offset,offset+limit);
  // High/exceptional risks and declared mutating primitives are *visible*
  // in the review graph, but never auto-handoff as unqualified WP hints.
  const hints=selected.filter(c=>c.hint_eligible).map(c=>({
    id:c.id, source:c.kind, label:c.label,
    description:c.description || c.label
  }));
  return publish({
    contract:FEDERATION_CONTRACT, binding:site, decision:
      !complete?"DISCOVERY_PARTIAL":candidates.length?"VERIFY_BEHAVIOR":"EXPAND_DISCOVERY",
    coverage_complete:complete, freshness_complete:freshnessComplete,
    catalog_authority_verified:catalogAuthorityVerified,
    registry_scope_verified:Boolean(scoped),
    candidate_total:candidates.length, snapshot_continuity_id:snapshot, offset, limit,
    source_count:records.length, sources:records, candidates:selected,
    external_hints:hints,
    restricted_candidate_count:selected.filter(c=>c.requires_separate_risk_review).length,
    next_offset:candidates.length>offset+limit?offset+limit:null,
    ranking:"LEXICAL_ONLY_NOT_FUNCTIONAL", no_runtime_authority:true,
    execution_allowed:false, authorizing:false, provider_executed:false,
    external_hints_are_untrusted:true
  });
}
/** Public no-credential bridge to the existing WordPress assistant router. */
export function toWordPressRouterInput(result,planning_input,related_terms=[]) {
  if (!isObject(result) || !ISSUED_DISCOVERIES.has(result) ||
      result.contract!==FEDERATION_CONTRACT ||
      result.catalog_authority_verified!==true ||
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


/**
 * Build a CMS-independent, non-executable remediation proposal.
 * A plan is NEVER a validated executor, approval, backup or release receipt.
 */
export function planRemediation({target,discovery,operation_id,requested_effect,
  desired_state}={}) {
  const site=checkTarget(target);
  if(!isObject(discovery) || !ISSUED_DISCOVERIES.has(discovery) ||
     discovery.contract!==FEDERATION_CONTRACT ||
     !boundTo(discovery.binding,site) || discovery.execution_allowed!==false ||
     discovery.authorizing!==false || !Array.isArray(discovery.candidates) ||
     !ID.test(operation_id??"") || !EFFECTS.has(requested_effect) ||
     requested_effect==="unknown" || !plainText(desired_state,120)) {
    throw new TypeError("INVALID_REMEDIATION_PLAN_INPUT");
  }
  if(discovery.candidates.length>MAX_HINTS)
    throw new TypeError("REMEDIATION_CANDIDATES_OVER_BUDGET");
  const mutates=requested_effect!=="read";
  const entries=[];
  for(const c of discovery.candidates) {
    if(!isObject(c) || c.site_id!==site.site_id ||
       c.environment!==site.environment || c.execution_allowed!==false ||
       !ID.test(c.id??"")) throw new TypeError("FOREIGN_OR_AUTHORIZED_CANDIDATE");
    const exceptional=Boolean(c.requires_separate_risk_review) ||
      c.declared_risk==="high" || c.declared_risk==="exceptional" ||
      c.declared_effect==="execute";
    entries.push({
      candidate_id:c.id, source_id:c.source_id,
      eligibility:exceptional?"DEDICATED_EXCEPTION_REVIEW":"BEHAVIOR_CERTIFICATION_REQUIRED",
      behavior_verified:false, permission_verified:false,
      execution_allowed:false, automatic_handoff_allowed:false,
      risk_attestation_required:true
    });
  }
  entries.sort((a,b)=>a.candidate_id.localeCompare(b.candidate_id));
  const requirements=[
    "exact_site_and_environment_readback",
    "independent_capability_and_effect_certification",
    "scope_bound_credential_and_governed_authority",
    "explicit_authorization_and_pre_execution_recheck",
    "independent_postcondition_readback"
  ];
  if(mutates) requirements.push(
    "externally_verified_backup","reviewed_reversible_mutation",
    "compensating_rollback_and_failure_readback"
  );
  if(site.environment==="production" && mutates)
    requirements.push("separate_production_promotion_authority");
  return {
    contract:"mad4b.site-remediation-proposal.v1",site,
    requested_operation:operation_id,requested_effect,desired_state,
    status:entries.length?"EXTERNAL_CERTIFICATION_REQUIRED":"EXPAND_DISCOVERY",
    candidates:entries,requirements,metadata_only:true,
    plan_continuity_id:continuity(JSON.stringify([site,operation_id,requested_effect,desired_state,entries])),
    // This return shape cannot be passed as a write or developer grant.
    authorizing:false,mutation_performed:false,execution_allowed:false,
    automatic_install_allowed:false,release_certified:false
  };
}
