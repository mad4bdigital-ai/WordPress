#!/usr/bin/env python3
"""Adversarial checks for partial G6/G8/G9 progress, never live certification."""
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import shutil
import tempfile
import unittest

import validate as subject


class ProgressDeliveryDenials(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / "repo"
        self.root = self.repo / subject.EXTENSION_PREFIX
        self.root.mkdir(parents=True)
        self.payloads = {}
        self.ledger = {"tasks": []}
        for group, rules in subject.PROGRESS_DELIVERIES.items():
            payload = json.loads((subject.ROOT / (group + "-delivery.json")).read_text())
            self.payloads[group] = payload
            records = payload["evidence_integrity"] if group == "g9" else [
                item for name in ("code_paths", "test_paths", "spec_paths", "workflow_paths")
                for item in payload[name]
            ]
            for record in records:
                path = record["path"]
                target = self.repo / path
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(subject.REPO / path, target)
                raw = target.read_bytes()
                record["sha256"] = hashlib.sha256(raw).hexdigest()
                record["bytes"] = len(raw)
            self.write(group)
            self.ledger["tasks"].extend(
                {"task_id": task, "status": "PARTIAL", "reason": "Repository foundation; acceptance pending.",
                 "evidence_refs": [group + "-delivery.json", group + "-delivery.md"]}
                for task in rules["tasks"]
            )

    def write(self, group):
        (self.root / (group + "-delivery.json")).write_text(json.dumps(self.payloads[group]))

    def check(self, group):
        subject.validate_progress_delivery(group, self.root, self.repo, self.ledger)

    def rejects(self, group, reason):
        self.write(group)
        with self.assertRaisesRegex(ValueError, reason):
            self.check(group)

    def records(self, group):
        payload = self.payloads[group]
        return payload["evidence_integrity"] if group == "g9" else [
            item for name in ("code_paths", "test_paths", "spec_paths", "workflow_paths")
            for item in payload[name]
        ]

    def test_current_partial_foundations_have_exact_owner_bindings(self):
        for group in subject.PROGRESS_DELIVERIES:
            self.check(group)

    def test_duplicate_or_foreign_task_cannot_expand_group_scope(self):
        self.payloads["g6"]["scope_task_ids"][-1] = "T4062"
        self.rejects("g6", "g6_delivery_boundary_or_progress_invalid")

    def test_duplicate_partial_task_is_not_complete_group_coverage(self):
        values = self.payloads["g8"]["partial_task_ids"]
        values[-1] = values[0]
        self.rejects("g8", "g8_delivery_boundary_or_progress_invalid")

    def test_provider_execution_or_live_acceptance_cannot_be_promoted(self):
        for group, field in (("g6", "provider_execution_certified"),
                             ("g8", "live_staging_acceptance"),
                             ("g9", "post_restore_acceptance_receipt_issued")):
            with self.subTest(group=group):
                original = deepcopy(self.payloads[group])
                self.payloads[group][field] = True
                self.rejects(group, group + "_delivery_boundary_or_progress_invalid")
                self.payloads[group] = original
                self.write(group)

    def test_missing_false_boundary_is_not_safe_default(self):
        del self.payloads["g9"]["native_journal_linkage_provider_implemented"]
        self.rejects("g9", "g9_delivery_boundary_or_progress_invalid")

    def test_documentation_cannot_replace_domain_sources(self):
        self.payloads["g8"]["code_paths"] = []
        self.rejects("g8", "g8_delivery_requires_code_tests_and_spec")

    def test_one_valid_slice_cannot_substitute_for_another_task_foundation(self):
        for group in subject.PROGRESS_DELIVERIES:
            with self.subTest(group=group):
                original = deepcopy(self.payloads[group])
                victim = subject.PROGRESS_SOURCES[group]["code_paths"][-1]
                self.payloads[group]["code_paths"] = [
                    item for item in self.payloads[group]["code_paths"]
                    if (item if isinstance(item, str) else item["path"]) != victim
                ]
                self.rejects(group, group + "_delivery_source_slice_missing")
                self.payloads[group] = original
                self.write(group)

    def test_unbound_workflow_cannot_be_substituted(self):
        self.payloads["g6"]["workflow_paths"][0]["path"] = ".github/workflows/feature-007-critical-kernel.yml"
        self.rejects("g6", "g6_delivery_workflow_scope_invalid")

    def test_traversal_or_path_alias_cannot_count_as_another_source(self):
        original = deepcopy(self.payloads["g8"])
        for path in (subject.PLUGIN_PREFIX + "includes/../tests/g8-extended-runtime.php",
                     subject.PLUGIN_PREFIX + "includes//class-mad4b-scp-g8-record.php"):
            with self.subTest(path=path):
                self.payloads["g8"] = deepcopy(original)
                self.payloads["g8"]["code_paths"][0]["path"] = path
                self.rejects("g8", "g8_delivery_path_outside_scope")

    def test_g9_preserves_string_source_groups(self):
        self.payloads["g9"]["code_paths"][0] = {"path": self.payloads["g9"]["code_paths"][0]}
        self.rejects("g9", "g9_delivery_path_outside_scope")

    def test_source_hash_and_byte_length_are_both_required(self):
        original = deepcopy(self.payloads["g6"])
        for field, value in (("sha256", "0" * 64), ("bytes", 1)):
            with self.subTest(field=field):
                self.payloads["g6"] = deepcopy(original)
                self.payloads["g6"]["code_paths"][0][field] = value
                self.rejects("g6", "g6_delivery_source_evidence_drift")

    def test_boolean_bytes_cannot_bypass_integer_shape(self):
        self.payloads["g8"]["code_paths"][0]["bytes"] = True
        self.rejects("g8", "g8_delivery_evidence_record_invalid")

    def test_g9_integrity_cannot_omit_bootstrap_or_add_self_hash(self):
        original = deepcopy(self.payloads["g9"])
        self.payloads["g9"]["evidence_integrity"] = [
            item for item in self.payloads["g9"]["evidence_integrity"]
            if item["path"] != subject.PLUGIN_PREFIX + "mad4b-site-control-plane.php"
        ]
        self.rejects("g9", "g9_delivery_evidence_inventory_invalid")
        self.payloads["g9"] = original
        self.payloads["g9"]["evidence_integrity"].append({
            "path": subject.EXTENSION_PREFIX + "g9-delivery.json", "sha256": "0" * 64, "bytes": 1
        })
        self.rejects("g9", "g9_delivery_evidence_record_invalid")

    def test_symlink_cannot_supply_fingerprinted_source(self):
        path = self.repo / self.payloads["g6"]["code_paths"][0]["path"]
        outside = Path(self.temp.name) / "foreign.php"
        outside.write_bytes(path.read_bytes())
        path.unlink()
        path.symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "missing_or_unsafe_file"):
            self.check("g6")

    def test_declared_partial_requires_same_canonical_state(self):
        row = next(row for row in self.ledger["tasks"] if row["task_id"] == "T4094")
        row["status"] = "OPEN"
        with self.assertRaisesRegex(ValueError, "g9_delivery_canonical_partial_required:T4094"):
            self.check("g9")

    def test_canonical_scope_cannot_omit_task(self):
        self.ledger["tasks"] = [row for row in self.ledger["tasks"] if row["task_id"] != "T3956"]
        with self.assertRaisesRegex(ValueError, "g6_delivery_canonical_task_scope_invalid"):
            self.check("g6")

    def test_canonical_task_requires_json_and_human_handoff(self):
        row = next(row for row in self.ledger["tasks"] if row["task_id"] == "T4087")
        row["evidence_refs"] = ["g8-delivery.json"]
        with self.assertRaisesRegex(ValueError, "g8_partial_task_missing_delivery_binding:T4087"):
            self.check("g8")

    def test_partial_reason_and_references_must_be_typed(self):
        row = next(row for row in self.ledger["tasks"] if row["task_id"] == "T3956")
        row["reason"] = True
        with self.assertRaisesRegex(ValueError, "g6_delivery_canonical_partial_required"):
            self.check("g6")
        row["reason"] = "Repository foundation, acceptance pending."
        row["evidence_refs"] = {"g6-delivery.json": True, "g6-delivery.md": True}
        with self.assertRaisesRegex(ValueError, "g6_partial_task_missing_delivery_binding"):
            self.check("g6")


if __name__ == "__main__":
    unittest.main()
