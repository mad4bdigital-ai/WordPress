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
assert history["previous_known_good_generation_sha256"] == history["entries"][-2]["generation_sha256"]
assert history["alerts"] and history["alerts"][-1]["generation_sha256"] == s["generation_sha256"]
assert history["alerts"][-1]["state"] in {"open", "acknowledged"}
if history["alerts"][-1]["state"] == "acknowledged":
    assert history["acknowledgements"] and history["acknowledgements"][-1]["alert_id"] == history["alerts"][-1]["alert_id"]
assert history["current_generation_sha256"] == s["generation_sha256"]
assert history["entries"][-1]["summary_sha256"] == summary["summary_sha256"]
assert history["current_entry_sha256"] == history["entries"][-1]["entry_sha256"]

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
prior_known = [r["generation_sha256"] for r in next_history["entries"][:-1] if r.get("state") == "known_good"]
expected_previous_known_good = prior_known[-1] if prior_known else ""
acknowledged = m.acknowledge_history_alert(next_history, next_history["alerts"][-1]["alert_id"], "owner-test")
assert acknowledged["alerts"][-1]["state"] == "acknowledged"
assert acknowledged["acknowledgements"][-1]["authorizing"] is False
assert acknowledged["previous_known_good_generation_sha256"] == expected_previous_known_good

m.verify()
print("mad4b.competitive-evidence-tests.v3: PASS")
