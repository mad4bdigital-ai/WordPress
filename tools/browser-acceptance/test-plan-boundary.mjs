import assert from "node:assert/strict";
import { validatePlan } from "./etg-driver.mjs";

const now = Math.floor(Date.now() / 1000);
const base = {
  contract: "mad4b.browser-acceptance-plan.v1",
  state: "ready",
  provider_id: "etg-dfsb",
  plan_digest: "a".repeat(64),
  plan_signature: "b".repeat(64),
  origin: "https://staging.egypttourgates.com/",
  build_identity: { git_sha: "1".repeat(40), tree_sha: "2".repeat(40) },
  challenge: {
    contract: "etg.dfsb.browser-acceptance-challenge.v1",
    nonce: "c".repeat(32),
    issued_at: now,
    expires_at: now + 900,
    signature: "d".repeat(64)
  },
  case_count: 1,
  cases: [{
    case_id: "cairo",
    archive_path: "/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/",
    provider: "jet-engine",
    query_id: "tours_query_archive",
    taxonomy: "location_jet",
    term_id: 1,
    term_slug: "cairo",
    expected: {
      result_total: 2,
      proof_mode: "full_ids",
      proof_item_count: 2,
      ids: [101, 102],
      identity_digest: "e".repeat(64),
      order_digest: "f".repeat(64)
    }
  }]
};

assert.equal(validatePlan(structuredClone(base)).cases.length, 1);

const schemeRelative = structuredClone(base);
schemeRelative.cases[0].archive_path = "//evil.example/path";
assert.throws(() => validatePlan(schemeRelative), /browser_plan_archive_path_invalid/);

const backslash = structuredClone(base);
backslash.cases[0].archive_path = "/\\evil.example/path";
assert.throws(() => validatePlan(backslash), /browser_plan_archive_path_invalid/);

const duplicate = structuredClone(base);
duplicate.case_count = 2;
duplicate.cases.push(structuredClone(duplicate.cases[0]));
assert.throws(() => validatePlan(duplicate), /browser_plan_case_binding_invalid/);

const countMismatch = structuredClone(base);
countMismatch.case_count = 2;
assert.throws(() => validatePlan(countMismatch), /browser_plan_case_count_mismatch/);

const badIds = structuredClone(base);
badIds.cases[0].expected.ids = [101];
assert.throws(() => validatePlan(badIds), /browser_plan_expected_ids_invalid/);

const badDigest = structuredClone(base);
badDigest.cases[0].expected = {
  result_total: 2,
  proof_mode: "full_digest",
  proof_item_count: 2,
  identity_digest: "bad",
  order_digest: "f".repeat(64)
};
assert.throws(() => validatePlan(badDigest), /browser_plan_expected_digest_invalid/);

const badTotal = structuredClone(base);
badTotal.cases[0].expected.result_total = 5001;
badTotal.cases[0].expected.proof_item_count = 5001;
badTotal.cases[0].expected.proof_mode = "full_digest";
badTotal.cases[0].expected.identity_digest = "e".repeat(64);
badTotal.cases[0].expected.order_digest = "f".repeat(64);
assert.throws(() => validatePlan(badTotal), /browser_plan_expected_total_invalid/);

console.log("MAD4B browser plan boundary PASS");
