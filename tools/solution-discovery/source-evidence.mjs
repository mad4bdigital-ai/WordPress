/**
 * Node host-side verification of a site-bound source catalog receipt.
 * This is a READ provenance gate, never an execution/authorization grant.
 *
 * Trusted public keys and consumeNonce must be supplied independently by the
 * host authority. Neither a site, plugin nor its catalog may supply trust roots.
 */
import {createHash, createPublicKey, verify as verifySignature, timingSafeEqual} from "node:crypto";

export const RECEIPT_CONTRACT="mad4b.source-catalog-attestation.v1";
const BASE64URL=/^[A-Za-z0-9_-]+$/;
const HEX=/^[a-f0-9]{64}$/;
const ID=/^[a-z0-9][a-z0-9._-]{1,79}$/;
const isRecord=x=>x!==null&&typeof x==="object"&&!Array.isArray(x);
const fail=code=>Object.freeze({accepted:false,code,authorizing:false,execution_allowed:false});

export function canonicalJson(input) {
  if(input===null||typeof input==="string"||typeof input==="boolean")return JSON.stringify(input);
  if(typeof input==="number") {
    if(!Number.isSafeInteger(input))throw new TypeError("INVALID_CANONICAL_NUMBER");
    return JSON.stringify(input);
  }
  if(Array.isArray(input))return "["+input.map(canonicalJson).join(",")+"]";
  if(!isRecord(input))throw new TypeError("INVALID_CANONICAL_TYPE");
  const fields=Object.keys(input).sort();
  if(fields.some(key=>key==="__proto__"||key==="constructor"||key==="prototype"))
    throw new TypeError("UNSAFE_CANONICAL_KEY");
  return "{"+fields.map(k=>JSON.stringify(k)+":"+canonicalJson(input[k])).join(",")+"}";
}
export function catalogDigest(catalog) {
  return createHash("sha256").update(canonicalJson(catalog),"utf8").digest("hex");
}
const exact=(a,b)=>typeof a==="string"&&typeof b==="string"&&
 HEX.test(a)&&HEX.test(b)&&timingSafeEqual(Buffer.from(a,"hex"),Buffer.from(b,"hex"));
const sameSite=(a,b)=>isRecord(a)&&isRecord(b)&&
 ["site_id","environment","origin_sha256","runtime_generation","profile_digest"].every(k=>
   (a[k]??null)===(b[k]??null));

/**
 * Canonical Ed25519 signed envelope:
 * {contract,kid,body,signature_b64url}, where body is canonical JSON
 * {contract,issuer,site,source_ids,complete,catalog_sha256,
 *  nonce,issued_at,expires_at,lane}.
 *
 * Persistence for one-time replay protection is delegated to an atomic,
 * host-authoritative consumeNonce. A best-effort in-memory Set is NOT enough.
 */
export async function verifySourceCatalogReceipt({
 receipt,catalog,site,trustedPublicKeys,consumeNonce,nowEpochSeconds=Math.floor(Date.now()/1000)
}={}) {
 try {
  if(!isRecord(receipt)||receipt.contract!==RECEIPT_CONTRACT||
     !ID.test(receipt.kid??"")||typeof receipt.body!=="string"||
     receipt.body.length>16384||!BASE64URL.test(receipt.signature_b64url??"")||
     !isRecord(trustedPublicKeys)||!Object.prototype.hasOwnProperty.call(trustedPublicKeys,receipt.kid)||
     typeof consumeNonce!=="function"||!Number.isSafeInteger(nowEpochSeconds))
    return fail("UNTRUSTED_RECEIPT");
  const raw=JSON.parse(receipt.body);
  if(!isRecord(raw)||canonicalJson(raw)!==receipt.body||
     raw.contract!==RECEIPT_CONTRACT||raw.lane!=="read"||
     !ID.test(raw.issuer??"")||!sameSite(raw.site,site)||
     typeof raw.nonce!=="string"||!BASE64URL.test(raw.nonce)||raw.nonce.length<16||raw.nonce.length>96||
     !Number.isSafeInteger(raw.issued_at)||!Number.isSafeInteger(raw.expires_at)||
     raw.issued_at>nowEpochSeconds+30||raw.expires_at<nowEpochSeconds||
     raw.expires_at<=raw.issued_at||raw.expires_at-raw.issued_at>300||
     !Array.isArray(raw.source_ids)||raw.source_ids.length>32||
     raw.source_ids.some(x=>!ID.test(x))||new Set(raw.source_ids).size!==raw.source_ids.length||
     raw.complete!==true||!HEX.test(raw.catalog_sha256??""))
    return fail("INVALID_RECEIPT_SCOPE");
  if(!isRecord(catalog)||catalog.contract!=="mad4b.site-source-catalog.v1"||
     !sameSite(catalog.binding,site)||catalog.complete!==true||
     !Array.isArray(catalog.sources)||catalog.sources.length!==raw.source_ids.length)
    return fail("CATALOG_SCOPE_MISMATCH");
  const ids=catalog.sources.map(x=>x?.id).sort();
  if(ids.some((x,i)=>x!==[...raw.source_ids].sort()[i])||
     !exact(catalogDigest(catalog),raw.catalog_sha256))
    return fail("CATALOG_DIGEST_MISMATCH");
  const b=Buffer.from(receipt.signature_b64url,"base64url");
  if(b.length!==64||b.toString("base64url")!==receipt.signature_b64url)
    return fail("INVALID_SIGNATURE_ENCODING");
  const key=createPublicKey(trustedPublicKeys[receipt.kid]);
  if(key.asymmetricKeyType!=="ed25519"||
     !verifySignature(null,Buffer.from(receipt.body,"utf8"),key,b))
    return fail("INVALID_SIGNATURE");
  // Must be atomic and durable across processes and machines for same
  // issuer/site/key/nonce; the caller guarantees that contract.
  const used=await consumeNonce({
   issuer:raw.issuer,kid:receipt.kid,nonce:raw.nonce,
   site_id:site.site_id,environment:site.environment,
   origin_sha256:site.origin_sha256,runtime_generation:site.runtime_generation,
   expires_at:raw.expires_at
  });
  if(used!==true)return fail("REPLAY_OR_LEDGER_UNAVAILABLE");
  return Object.freeze({accepted:true,code:"ATTESTED_SOURCE_CATALOG",
   issuer:raw.issuer,catalog_sha256:raw.catalog_sha256,
   issued_at:raw.issued_at,expires_at:raw.expires_at,
   authorizing:false,execution_allowed:false});
 } catch (_) { return fail("INVALID_OR_UNAVAILABLE_PROOF"); }
}

/**
 * Produce a verifier for federated read enumeration; receiptGetter and keys
 * must be provided from the host's authenticated control plane.
 */
export function createTrustedCatalogVerifier({getReceipt, trustedPublicKeys,consumeNonce,clock}={}) {
 if(typeof getReceipt!=="function"||typeof consumeNonce!=="function"||
    !isRecord(trustedPublicKeys))throw new TypeError("HOST_PROOF_DEPENDENCY_MISSING");
 return async ({site,expected_source_ids,claimed_complete,contract,catalog})=>{
  if(!claimed_complete||contract!=="mad4b.site-source-catalog.v1"||
     !isRecord(catalog)||!Array.isArray(expected_source_ids)||
     expected_source_ids.join("|")!==catalog.sources.map(x=>x?.id).sort().join("|"))
    return false;
  let receipt;
  try{receipt=await getReceipt({site,catalog_sha256:catalogDigest(catalog)});}
  catch(_){return false}
  const v=await verifySourceCatalogReceipt({receipt,catalog,site,trustedPublicKeys,consumeNonce,
    nowEpochSeconds:typeof clock==="function"?clock():Math.floor(Date.now()/1000)});
  return v.accepted===true;
 };
}
