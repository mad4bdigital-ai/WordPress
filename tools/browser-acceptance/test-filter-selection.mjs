import assert from "node:assert/strict";
import { chooseGovernedTermIndex } from "./etg-driver.mjs";

const planCase = {
  term_id: 7,
  term_slug: "cairo",
  taxonomy: "location_jet"
};

assert.equal(chooseGovernedTermIndex([
  { visible: true, tagName: "DIV", attrs: [], text: "Cairo tours", name: "", queryId: "" },
  { visible: true, tagName: "INPUT", attrs: ["7"], text: "", name: "location_jet[]", queryId: "tours_query_archive" }
], planCase), 1);

assert.equal(chooseGovernedTermIndex([
  { visible: true, tagName: "BUTTON", attrs: ["cairo"], text: "Cairo", name: "", queryId: "" }
], planCase), 0);

assert.equal(chooseGovernedTermIndex([
  { visible: true, tagName: "BUTTON", attrs: [], text: "Cairo", name: "location_jet_filter", queryId: "" }
], planCase), 0);

assert.equal(chooseGovernedTermIndex([
  { visible: true, tagName: "BUTTON", attrs: [], text: "Cairo tours", name: "location_jet_filter", queryId: "tours_query_archive" }
], planCase), -1);

assert.equal(chooseGovernedTermIndex([
  { visible: false, tagName: "INPUT", attrs: ["7"], text: "", name: "location_jet[]", queryId: "tours_query_archive" }
], planCase), -1);

assert.equal(chooseGovernedTermIndex([
  { visible: true, tagName: "BUTTON", attrs: ["cairo"], text: "Cairo", name: "", queryId: "" },
  { visible: true, tagName: "INPUT", attrs: ["7"], text: "", name: "location_jet[]", queryId: "tours_query_archive" }
], planCase), 1);

console.log("MAD4B governed filter identity selection PASS");
