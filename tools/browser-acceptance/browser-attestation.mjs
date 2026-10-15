// Browser-worker-owned RSA signature over the exact canonical evidence
// BEFORE the attestation field is attached. Not a WordPress / MCP secret.
import crypto from "node:crypto";

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (!value || typeof value !== "object") return value;
  const result = {};
  for (const key of Object.keys(value).sort()) result[key] = canonicalize(value[key]);
  return result;
}
export function canonicalEvidenceBytes(evidence) {
  if (!evidence || typeof evidence !== "object" || Array.isArray(evidence) ||
      Object.prototype.hasOwnProperty.call(evidence, "attestation")) {
    throw new Error("browser_attestation_payload_invalid");
  }
  return Buffer.from(JSON.stringify(canonicalize(evidence)), "utf8");
}
export function assertSigningConfigured(env = process.env) {
  const material = env.MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64;
  if (typeof material !== "string" || material.length > 12000 ||
      !/^[a-zA-Z0-9+/=]+$/.test(material)) {
    throw new Error("browser_attestation_signer_unconfigured");
  }
  let key;
  try {
    key = crypto.createPrivateKey(Buffer.from(material, "base64").toString("utf8"));
  } catch {
    throw new Error("browser_attestation_private_key_invalid");
  }
  if (key.asymmetricKeyType !== "rsa" || (key.asymmetricKeyDetails?.modulusLength || 0) < 2048) {
    throw new Error("browser_attestation_key_strength_invalid");
  }
  return key;
}
export function signerKeyId(env = process.env) {
  const key = assertSigningConfigured(env);
  return "rsa-spki-sha256:" + crypto.createHash("sha256")
    .update(crypto.createPublicKey(key).export({ format: "der", type: "spki" }))
    .digest("hex");
}
export function signDeclarativeEvidence(evidence, env = process.env) {
  if (evidence?.contract !== "mad4b.capability-browser-evidence.v1") return evidence;
  const key = assertSigningConfigured(env);
  const signature = crypto.sign("sha256", canonicalEvidenceBytes(evidence), key).toString("base64");
  return {
    ...evidence,
    attestation: {
      algorithm: "rsa-sha256",
      key_id: signerKeyId(env),
      signature
    }
  };
}
