import assert from "node:assert/strict";
import {createHash,generateKeyPairSync,sign} from "node:crypto";
import {canonicalJson,catalogDigest,verifySourceCatalogReceipt,createTrustedCatalogVerifier,RECEIPT_CONTRACT} from "./source-evidence.mjs";
let n=0;
const yes=(v,m)=>{assert.ok(v,m);n++};
const H=x=>x.repeat(64);
const site={site_id:"any-site",environment:"staging",origin_sha256:H("a"),runtime_generation:H("b")};
const catalog={contract:"mad4b.site-source-catalog.v1",binding:site,complete:true,
 read_only:true,authorizing:false,sources:[{id:"source-aa",kind:"connector",...site,lane:"read",connected:true,read_authorized:true}]};
const now=2000000000;
const keys=generateKeyPairSync("ed25519");
const trusted={"release-key":keys.publicKey.export({type:"spki",format:"pem"})};
const body={contract:RECEIPT_CONTRACT,issuer:"platform-registry",site,
 source_ids:["source-aa"],complete:true,catalog_sha256:catalogDigest(catalog),
 nonce:"opaque-nonce-0123456789",issued_at:now-10,expires_at:now+120,lane:"read"};
function receipt(x=body){const b=canonicalJson(x);return {contract:RECEIPT_CONTRACT,kid:"release-key",
 body:b,signature_b64url:sign(null,Buffer.from(b),keys.privateKey).toString("base64url")}}
const consumed=new Set();
const consumeNonce=async x=>{const k=x.kid+"/"+x.site_id+"/"+x.nonce;if(consumed.has(k))return false;consumed.add(k);return true};
const options={catalog,site,trustedPublicKeys:trusted,consumeNonce,nowEpochSeconds:now};
const valid=await verifySourceCatalogReceipt({...options,receipt:receipt()});
yes(valid.accepted===true&&!valid.authorizing&&!valid.execution_allowed,"valid read receipt nonauthorizing");
const repeat=await verifySourceCatalogReceipt({...options,receipt:receipt()});
yes(repeat.code==="REPLAY_OR_LEDGER_UNAVAILABLE","one-time nonce");
const altered=await verifySourceCatalogReceipt({...options,receipt:receipt({...body,nonce:"another-nonce-01234567"}),catalog:{...catalog,complete:false}});
yes(altered.code==="CATALOG_SCOPE_MISMATCH","modified catalog");
const wrongSite=await verifySourceCatalogReceipt({...options,receipt:receipt({...body,site:{...site,site_id:"another"}})});
yes(wrongSite.code==="INVALID_RECEIPT_SCOPE","wrong site rejected");
const expired=await verifySourceCatalogReceipt({...options,receipt:receipt({...body,nonce:"new-expired-nonce-123",expires_at:now-1})});
yes(expired.code==="INVALID_RECEIPT_SCOPE","expired rejected");
const attacker=generateKeyPairSync("ed25519");
const tampered=receipt({...body,nonce:"new-sig-test-abcdef1234"});
tampered.signature_b64url=sign(null,Buffer.from(tampered.body),attacker.privateKey).toString("base64url");
const forged=await verifySourceCatalogReceipt({...options,receipt:tampered});
yes(forged.code==="INVALID_SIGNATURE","untrusted signer");
const noLedger=await verifySourceCatalogReceipt({...options,receipt:receipt({...body,nonce:"fresh-ledger-fail-1234"}),consumeNonce:async()=>{throw Error("offline")}});
yes(noLedger.code==="INVALID_OR_UNAVAILABLE_PROOF","ledger unavailable");
const envChanged=await verifySourceCatalogReceipt({...options,receipt:receipt({...body,nonce:"environment-nonce-123",site:{...site,environment:"production"}})});
yes(envChanged.code==="INVALID_RECEIPT_SCOPE","environment cannot cross");
const verifier=createTrustedCatalogVerifier({getReceipt:async()=>receipt({...body,nonce:"trusted-bridge-12345"}),trustedPublicKeys:trusted,consumeNonce,clock:()=>now});
yes(await verifier({site,catalog,contract:"mad4b.site-source-catalog.v1",claimed_complete:true,expected_source_ids:["source-aa"]}),"host verifier");
yes(!(await verifier({site,catalog,contract:"mad4b.site-source-catalog.v1",claimed_complete:true,expected_source_ids:["source-aa"]})),"bridge replay denied");
console.log("SOURCE_CATALOG_PROVENANCE_NATIVE: PASS",n,"checks");
