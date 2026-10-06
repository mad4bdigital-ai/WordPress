#!/usr/bin/env python3
"""Denial tests for evidence integrity, unsafe archives and false completion."""
from pathlib import Path
import json
import shutil
import stat
import tempfile
import unittest
import zipfile

import validate as subject


class ArchiveDenials(unittest.TestCase):
    def reject(self, name, *, external_attr=0, payload=b"example", duplicate=False):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "reference.zip"
            with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                info = zipfile.ZipInfo(name)
                info.external_attr = external_attr
                archive.writestr(info, payload)
                if duplicate:
                    archive.writestr("provider/" + name.split("/", 1)[1].upper(), payload)
            with self.assertRaises(ValueError):
                subject.inspect_archive(path, "provider")

    def test_traversal(self):
        self.reject("provider/../escape.php")

    def test_absolute(self):
        self.reject("/provider/entry.php")

    def test_backslash(self):
        self.reject("provider\\entry.php")

    def test_symlink(self):
        self.reject("provider/entry.php", external_attr=(stat.S_IFLNK | 0o777) << 16)

    def test_case_alias(self):
        self.reject("provider/entry.php", duplicate=True)

    def test_member_size_bound(self):
        self.reject("provider/huge.txt", payload=b"a" * (12 * 1024 * 1024 + 1))

    def test_special_file(self):
        self.reject("provider/pipe", external_attr=(stat.S_IFIFO | 0o600) << 16)


class EvidenceDenials(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.parent = Path(cls.temp.name) / "specs/007"
        cls.root = cls.parent / "extensions/competitive-experience"
        shutil.copytree(subject.ROOT, cls.root)
        for name in ("feature.json", "task-ledger.generated.json"):
            shutil.copyfile(subject.ROOT.parents[1] / name, cls.parent / name)
        cls.original = {p: p.read_bytes() for p in cls.root.rglob("*") if p.is_file()}

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def tearDown(self):
        for path, raw in self.original.items():
            path.write_bytes(raw)

    def change(self, name, mutate):
        path = self.root / name
        value = json.loads(path.read_text())
        mutate(value)
        path.write_text(json.dumps(value))

    def rejects(self, reason):
        with self.assertRaisesRegex(ValueError, reason):
            subject.validate(self.root, subject.REPO)

    def test_supplied_snapshot_passes(self):
        counts = subject.validate(self.root, subject.REPO)["extension_counts"]
        self.assertEqual(counts["OPEN"] + counts["PARTIAL"], 175)
        self.assertEqual(counts["DONE"], 0)

    def test_changed_original_zip(self):
        path = self.root / "artifacts/royal-mcp-1.5.0.zip"
        path.write_bytes(path.read_bytes() + b"altered archive")
        self.rejects("original_archive_bytes_changed")

    def test_changed_original_report_even_with_updated_manifest(self):
        import hashlib
        path = self.root / "source-adaptive-operations-proposal.ar.md"
        path.write_bytes(path.read_bytes() + b"altered proposal")
        self.change("artifact-manifest.json", lambda d: d["additional_source_reports"][0].update(
            sha256=hashlib.sha256(path.read_bytes()).hexdigest(), bytes=path.stat().st_size))
        self.rejects("source_report_bytes_changed")

    def test_source_range_drift(self):
        self.change("source-index.json", lambda d: d["evidence"][0].update(range_sha256="0" * 64))
        self.rejects("source_line_range_digest_mismatch")

    def test_marketing_cannot_claim_runtime(self):
        self.change("source-index.json", lambda d: d["evidence"][1].update(runtime_verified=True))
        self.rejects("static_evidence_cannot_claim_runtime")

    def test_missing_hard_boundary(self):
        self.change("extension.json", lambda d: d["hard_boundaries"].remove("no_documentation_only_completion"))
        self.rejects("extension_hard_boundary_missing")

    def test_duplicate_task_owner(self):
        self.change("capability-matrix.json", lambda d: d["workstreams"][1]["task_ids"].__setitem__(0, "T3901"))
        self.rejects("task_workstream_ownership_mismatch")

    def test_dependency_cycle(self):
        self.change("capability-matrix.json", lambda d: d["workstreams"][0]["dependencies"].append("operator-journeys"))
        self.rejects("workstream_dependency_cycle")

    def test_release_blocker_widening(self):
        self.change("capability-matrix.json", lambda d: d["workstreams"][0].update(release_blocker=True))
        self.rejects("extension_must_not_widen_release_blockers")

    def test_missing_capability_source(self):
        self.change("capability-matrix.json", lambda d: d["capabilities"][0].update(evidence_ids=["UNKNOWN"]))
        self.rejects("capability_missing_source_evidence")

    def test_checkbox_is_not_completion(self):
        path = self.root / "tasks.md"
        path.write_text(path.read_text().replace("- [ ] T3901", "- [x] T3901"))
        self.rejects("task_status_missing_evidence")

    def test_docs_only_done_even_with_ledger_regenerated(self):
        path = self.root / "tasks.md"
        path.write_text(path.read_text().replace("- [ ] T3901", "- [x] T3901"))
        self.change("task-status.json", lambda d: d["overrides"].update(T3901={"status": "DONE", "reason": "docs", "evidence_refs": ["spec.md"]}))
        (self.root / "task-ledger.generated.json").write_text(json.dumps(subject.build_ledger(self.root)))
        self.rejects("optional_backlog_cannot_claim_runtime_completion")

    def test_ui_cannot_claim_live_acceptance(self):
        self.change("ui-delivery.json", lambda d: d.update(live_browser_acceptance=True))
        self.rejects("ui_delivery_boundary_or_progress_invalid")

    def test_ui_source_hashes_are_required(self):
        self.change("ui-delivery.json", lambda d: d["code_paths"][0].update(sha256="0"*64))
        self.rejects("ui_delivery_source_evidence_drift")

    def test_g1_task_cannot_bind_to_ui_delivery(self):
        self.change("task-status.json", lambda d: d["overrides"].update(T3901={"status":"PARTIAL", "reason":"wrong slice", "evidence_refs":["ui-delivery.json"]}))
        (self.root / "task-ledger.generated.json").write_text(json.dumps(subject.build_ledger(self.root)))
        self.rejects("g1_partial_task_missing_delivery_binding")

    def test_unowned_partial_task_is_rejected(self):
        self.change("task-status.json", lambda d: d["overrides"].update(T3911={"status":"PARTIAL", "reason":"wrong owner", "evidence_refs":["g1-delivery.json"]}))
        (self.root / "task-ledger.generated.json").write_text(json.dumps(subject.build_ledger(self.root)))
        self.rejects("implementation_partial_task_owner_invalid")

    def test_g1_cannot_claim_runtime_parity(self):
        self.change("g1-delivery.json", lambda d: d.update(runtime_parity_claimed=True))
        self.rejects("g1_delivery_boundary_or_progress_invalid")

    def test_g1_cannot_claim_live_provider_acceptance(self):
        self.change("g1-delivery.json", lambda d: d.update(live_provider_acceptance=True))
        self.rejects("g1_delivery_boundary_or_progress_invalid")

    def test_g1_source_hashes_are_required(self):
        self.change("g1-delivery.json", lambda d: d["code_paths"][0].update(sha256="0"*64))
        self.rejects("g1_delivery_source_evidence_drift")

    def test_missing_ledger_regeneration(self):
        path = self.root / "tasks.md"
        path.write_text(path.read_text().replace("reproducible artifact", "reproducible changed artifact", 1))
        self.rejects("generated_task_ledger_drift")

    def test_missing_human_trace(self):
        path = self.root / "traceability.md"
        path.write_text(path.read_text().replace("| CPBEN |", "| REMOVED |"))
        self.rejects("workstream_traceability_or_status")

    def test_classifier_cannot_create_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(classifier_creates_authority=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_reversible_canary_needs_actual_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(canary_requires_existing_staging_authority=False))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_data_pack_cannot_mint_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(signed_pack_can_create_authority=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_preserve_human_edits(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(human_edit_overwrite_automatic=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_governed_level_cannot_be_automatic(self):
        self.change("adaptive-operations.json", lambda d: d["autonomy_levels"][5]["automatic_actions"].append("GRANT_PRODUCTION"))
        self.rejects("adaptive_autonomy_levels_or_actions_invalid")

    def test_aspirational_ratio_not_measurement(self):
        self.change("adaptive-operations.json", lambda d: d["measurement"].update(observed_automation_percent=95))
        self.rejects("adaptive_aspiration_cannot_be_claimed_measurement")


    def test_fleet_cannot_infer_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(cross_site_authority_inference=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_automation_kill_switch_is_required(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(automation_kill_switch_required=False))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_signature_cannot_create_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(supply_chain_signature_creates_authority=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_schema_migration_cannot_lower_risk(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(schema_migration_can_lower_risk=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_fuzzing_must_remain_disposable(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(fuzzing_disposable_fixture_required=False))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

    def test_restore_cannot_replay_authority(self):
        self.change("adaptive-operations.json", lambda d: d["rules"].update(restore_replays_prior_authority=True))
        self.rejects("adaptive_operations_authority_or_safety_boundary_invalid")

if __name__ == "__main__":
    unittest.main()
