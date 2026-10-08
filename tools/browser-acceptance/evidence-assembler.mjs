// Fail-closed browser evidence from multiple short-lived sessions.
// Never substitute a missing or duplicated case with a "successful" envelope.
const SHA = /^[a-f0-9]{64}$/;
const NONCE = /^[a-f0-9]{32}$/;
function deny(reason) { throw new Error("browser_chunk_" + reason); }
export function createEvidenceAssembler(plan, expectedContract) {
  if (!plan || !Array.isArray(plan.cases) || plan.cases.length < 1 || plan.cases.length > 8 ||
      !SHA.test(plan.plan_digest || "") || !SHA.test(plan.plan_signature || "") ||
      typeof expectedContract !== "string" || !/^[a-z0-9][a-z0-9._-]{0,159}$/.test(expectedContract) ||
      !NONCE.test(plan.challenge?.nonce || "")) deny("plan_invalid");
  const ids = plan.cases.map(c => c?.case_id);
  if (ids.some(x => typeof x !== "string" || !/^[a-zA-Z0-9._:-]{1,120}$/.test(x)) ||
      new Set(ids).size !== ids.length) deny("plan_cases_invalid");
  let envelope = null;
  const cases = [];
  const seen = new Set();
  return Object.freeze({
    add(partial, expectedChunk) {
      if (!Array.isArray(expectedChunk) || !expectedChunk.length ||
          !partial || typeof partial !== "object" || Array.isArray(partial) ||
          partial.contract !== expectedContract || partial.plan_digest !== plan.plan_digest ||
          partial.plan_signature !== plan.plan_signature || partial.origin !== plan.origin ||
          JSON.stringify(partial.build_identity ?? null) !== JSON.stringify(plan.build_identity ?? null) ||
          !partial.observer || partial.observer.javascript_runtime !== true ||
          partial.observer.execution_mode !== "managed_browser_agent" ||
          !Array.isArray(partial.cases) || partial.cases.length !== expectedChunk.length) deny("envelope_mismatch");
      if (envelope && (JSON.stringify(partial.observer) !== JSON.stringify(envelope.observer) ||
          partial.contract !== envelope.contract)) deny("observer_changed");
      const expected = expectedChunk.map(c => c?.case_id);
      for (let i = 0; i < partial.cases.length; i++) {
        const evidence = partial.cases[i];
        if (!evidence || typeof evidence !== "object" || Array.isArray(evidence) ||
            evidence.case_id !== expected[i] || seen.has(evidence.case_id) ||
            evidence.challenge_nonce !== plan.challenge.nonce) deny("case_identity_or_nonce_mismatch");
        if (ids.indexOf(evidence.case_id) !== cases.length + i) deny("case_order_invalid");
      }
      if (!envelope) envelope = { ...partial };
      for (const c of partial.cases) { seen.add(c.case_id); cases.push(c); }
      return cases.length;
    },
    finish() {
      if (!envelope || cases.length !== ids.length || seen.size !== ids.length) deny("incomplete");
      return { ...envelope, cases };
    }
  });
}
