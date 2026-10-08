#!/usr/bin/env python3
from copy import deepcopy
from pathlib import Path
import importlib.util
import json
import tempfile

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("competitive_evidence", HERE / "competitive-evidence.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

s = m.build_snapshot()
assert s["authorizing"] is False
assert s["runtime_certification_inferred"] is False
assert s["marketing_claims_promoted"] is False
assert len(s["packages"]) > 0 and len(s["capabilities"]) > 0
assert all(len(p["sha256"]) == 64 for p in s["packages"])
assert all(c["task_ids"] and c["evidence_ids"] and c["evidence_sources"] for c in s["capabilities"])
assert all(c["mad4b_foundation_paths"] and c["acceptance_requirements"] for c in s["capabilities"])
assert all(src["runtime_verified"] is False for c in s["capabilities"] for src in c["evidence_sources"])

same = m.semantic_diff(s, deepcopy(s))
assert same["added"] == [] and same["removed"] == [] and same["changed"] == []
assert same["package_changed"] == [] and same["source_drift"] is False and same["task_drift"] is False

changed = deepcopy(s)
changed["capabilities"][0]["implementation_status"] = "DONE"
changed["generation_sha256"] = "1" * 64
delta = m.semantic_diff(s, changed)
assert delta["changed"][0]["id"] == s["capabilities"][0]["id"]
assert "implementation_status" in delta["changed"][0]["fields"]

drift = deepcopy(s)
drift["source_generation"] = "0" * 64
drift["generation_sha256"] = "2" * 64
assert m.semantic_diff(s, drift)["source_drift"] is True

package_drift = deepcopy(s)
package_drift["packages"][0]["version"] = "99.0"
package_drift["generation_sha256"] = "3" * 64
assert m.semantic_diff(s, package_drift)["package_changed"][0]["id"] == s["packages"][0]["id"]

task_drift = deepcopy(s)
task_drift["task_generation"] = "0" * 64
task_drift["generation_sha256"] = "4" * 64
assert m.semantic_diff(s, task_drift)["task_drift"] is True

summary = m.operator_summary(s)
assert summary["contract"] == "mad4b.competitive-evidence-summary.v2"
assert summary["authorizing"] is False
assert summary["package_count"] == len(s["packages"])
assert summary["capability_count"] == len(s["capabilities"])
assert all("runtime_parity_claimed" in r and "mad4b_foundation_paths" in r and "evidence_sources" in r for r in summary["capabilities"])
assert all("workstream_id" in r and "family" in r and "open_task_ids" in r and "partial_task_ids" in r for r in summary["capabilities"])

rendered = m.render_php_summary(summary)
assert rendered.startswith("<?php\nif ( ! defined( 'ABSPATH' ) ) {\n")
assert "http_response_code( 404 )" in rendered
assert "Direct web execution returns HTTP 404 with no evidence." in rendered
assert "competitive-evidence-summary.json" not in rendered
assert "MAD4B_JSON" in rendered

history = m.verify_history(s, summary)
assert history["authorizing"] is False
assert history["contract"] == m.HISTORY_CONTRACT
assert history["retention"]["max_entries"] == m.MAX_HISTORY_ENTRIES
assert len(history["entries"]) >= 2, "append-only history was reset"
assert history["entries"][-1]["revision"] == len(history["entries"])
prior_known = [r["generation_sha256"] for r in history["entries"][:-1] if r["state"] == "known_good"]
assert history["previous_known_good_generation_sha256"] == (prior_known[-1] if prior_known else "")
assert history["alerts"] and history["alerts"][-1]["generation_sha256"] == s["generation_sha256"]
assert history["alerts"][-1]["state"] in {"open", "acknowledged"}
if history["alerts"][-1]["state"] == "acknowledged":
    assert history["acknowledgements"] and history["acknowledgements"][-1]["alert_id"] == history["alerts"][-1]["alert_id"]
assert history["current_generation_sha256"] == s["generation_sha256"]
assert history["entries"][-1]["summary_sha256"] == summary["summary_sha256"]
assert history["current_entry_sha256"] == history["entries"][-1]["entry_sha256"]
assert m.next_history(history, s, summary) == history, "same generation appended or cleared its drift alert"

with tempfile.TemporaryDirectory() as temp:
    tampered = deepcopy(history)
    tampered["entries"][0]["source_generation"] = "0" * 64
    tampered_path = Path(temp) / "competitive-evidence-history.json"
    tampered_path.write_text(json.dumps(tampered), encoding="utf-8")
    try:
        m.verify_history(s, summary, tampered_path)
        raise AssertionError("tampered competitive evidence history was accepted")
    except SystemExit as error:
        assert str(error) == "COMPETITIVE_EVIDENCE_HISTORY_ENTRY_DIGEST_INVALID"

future = deepcopy(s)
future["source_generation"] = "1" * 64
future["generation_sha256"] = "2" * 64
future_summary = m.operator_summary(future)
next_history = m.next_history(history, future, future_summary)
assert next_history["entries"][-1]["state"] == "drifted"
assert next_history["alerts"][-1]["state"] == "open"
assert next_history["previous_known_good_generation_sha256"] == history["previous_known_good_generation_sha256"]

metadata_reconciliation = deepcopy(future)
metadata_reconciliation["generation_sha256"] = "3" * 64
metadata_summary = m.operator_summary(metadata_reconciliation)
carried = m.next_history(next_history, metadata_reconciliation, metadata_summary)
assert carried["entries"][-1]["state"] == "drifted"
assert carried["entries"][-1]["drift_flags"]["source"] is True
assert carried["alerts"][-1]["generation_sha256"] == metadata_reconciliation["generation_sha256"]
assert carried["alerts"][-1]["state"] == "open"

try:
    m.next_history(next_history, s, summary)
    raise AssertionError("old generation replay was appended")
except ValueError as error:
    assert str(error) == "history_generation_replay"
prior_known = [r["generation_sha256"] for r in next_history["entries"][:-1] if r.get("state") == "known_good"]
expected_previous_known_good = prior_known[-1] if prior_known else ""
acknowledged = m.acknowledge_history_alert(next_history, next_history["alerts"][-1]["alert_id"], "owner-test")
assert acknowledged["alerts"][-1]["state"] == "acknowledged"
assert acknowledged["acknowledgements"][-1]["authorizing"] is False
assert acknowledged["previous_known_good_generation_sha256"] == expected_previous_known_good

m.verify()

with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    for name in ("artifact-manifest.json", "capability-matrix.json", "source-index.json", "task-ledger.generated.json"):
        (root / name).write_text((HERE / name).read_text(encoding="utf-8"), encoding="utf-8")
    prior = deepcopy(history)
    history_path = root / "competitive-evidence-history.json"
    history_path.write_text(json.dumps(prior, indent=2) + "\n", encoding="utf-8")
    summary_path, resource_path = root / "summary.php", root / "history.php"
    ledger = json.loads((root / "task-ledger.generated.json").read_text(encoding="utf-8"))
    ledger["tasks"][0]["reason"] += " Repository-only fixture; no runtime acceptance."
    (root / "task-ledger.generated.json").write_text(json.dumps(ledger), encoding="utf-8")
    m.refresh_generated(root, summary_path, history_path, resource_path)
    refreshed = json.loads(history_path.read_text(encoding="utf-8"))
    assert refreshed["entries"][:len(prior["entries"])] == prior["entries"]
    assert refreshed["alerts"][:len(prior["alerts"])] == prior["alerts"]
    assert refreshed["acknowledgements"] == prior["acknowledgements"]
    assert refreshed["entries"][-1]["state"] == "drifted"
    assert refreshed["authorizing"] is False
    exact = [path.read_bytes() for path in (summary_path, history_path, resource_path)]
    m.refresh_generated(root, summary_path, history_path, resource_path)
    assert [path.read_bytes() for path in (summary_path, history_path, resource_path)] == exact
    damaged = deepcopy(refreshed)
    damaged["entries"][0]["source_generation"] = "0" * 64
    history_path.write_text(json.dumps(damaged), encoding="utf-8")
    before = [path.read_bytes() for path in (summary_path, history_path, resource_path)]
    try:
        m.refresh_generated(root, summary_path, history_path, resource_path)
        raise AssertionError("refresh reset a damaged history")
    except SystemExit as error:
        assert str(error) == "COMPETITIVE_EVIDENCE_HISTORY_ENTRY_DIGEST_INVALID"
    assert [path.read_bytes() for path in (summary_path, history_path, resource_path)] == before


# Native relation auditing is evidence-only: no implied WPML mapper ownership,
# locale inference or writes, even when WordPress post IDs are known.
relation_pack = {
    "contract": "mad4b.native-relation-audit-input.v1",
    "fields": [
        {"key": "related_tour_id", "kind": "post", "post_type": "tours-and-activities",
         "cardinality": "one", "locale_policy": "same_locale"},
        {"key": "related_properties_id", "kind": "post", "post_type": "properties",
         "cardinality": "many", "locale_policy": "review"},
    ],
    "records": [
        {"id": 45005, "translation_group": "638158", "locale": "en",
         "meta": {"related_tour_id": "25675", "related_properties_id": ["36475", "36005"]}},
        {"id": 45007, "translation_group": "638158", "locale": "fr",
         "meta": {"related_tour_id": "27819", "related_properties_id": ["36475", "36005"]}},
    ],
    "identities": [
        {"id": 25675, "exists": True, "post_type": "tours-and-activities"},
        {"id": 27819, "exists": True, "post_type": "tours-and-activities"},
        {"id": 36475, "exists": True, "post_type": "properties"},
        {"id": 36005, "exists": True, "post_type": "properties"},
    ],
}
partial_relations = m.audit_native_postmeta_relations(relation_pack)
assert partial_relations["summary"] == {"PASS": 0, "REVIEW": 6, "ISSUE": 0}
assert partial_relations["complete"] is False
assert partial_relations["read_only"] and not partial_relations["authorizing"]
assert partial_relations["mutation_performed"] is False
assert any(x["reason"] == "target_locale_not_verified" for x in partial_relations["findings"])
assert any(x["reason"] == "locale_policy_requires_review" for x in partial_relations["findings"])
resolved = deepcopy(relation_pack)
resolved["identities"][0]["locale"] = "en"
resolved["identities"][1]["locale"] = "fr"
assert m.audit_native_postmeta_relations(resolved)["summary"] == {"PASS": 2, "REVIEW": 4, "ISSUE": 0}
cross = deepcopy(resolved)
cross["identities"][1]["locale"] = "en"
assert any(x["reason"] == "cross_locale_reference" for x in m.audit_native_postmeta_relations(cross)["findings"])
bad_type = deepcopy(resolved)
bad_type["identities"][0]["post_type"] = "properties"
assert any(x["reason"] == "target_type_mismatch" for x in m.audit_native_postmeta_relations(bad_type)["findings"])
missing = deepcopy(resolved)
missing["identities"] = missing["identities"][1:]
assert any(x["reason"] == "target_identity_not_observed" for x in m.audit_native_postmeta_relations(missing)["findings"])
duplicate_locale = deepcopy(resolved)
duplicate_locale["records"][1]["locale"] = "en"
assert any(x["reason"] == "duplicate_group_locale" for x in m.audit_native_postmeta_relations(duplicate_locale)["findings"])
cardinality = deepcopy(resolved)
cardinality["records"][0]["meta"]["related_properties_id"] = "36475"
assert any(x["reason"] == "cardinality_mismatch" for x in m.audit_native_postmeta_relations(cardinality)["findings"])

print("mad4b.competitive-evidence-tests.v3: PASS")
