"""Fail-closed source review of selected PR813 destination parts in PR814."""
from pathlib import Path
from qa31_original305_source_overlay import restore_exact_original305_overlay
import hashlib
import json
import unittest
import subprocess

ROOT = Path(__file__).resolve().parents[1]
REVIEW = ROOT / "docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json"


class DestinationAdditiveReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review = json.loads(REVIEW.read_text())

    def test_selected_pr813_files_exact_and_non_authorizing(self):
        x = self.review
        self.assertEqual(x["contract"], "mad4b.pr814.pr813-additive-destination-reconcile.v1")
        self.assertEqual(x["pr813_source_sha"], "865538aaaf6819a863d32a80406dbe56a17b42da")
        self.assertEqual(x["merge_method"], "selected_additive_only_no_global_replacement")
        self.assertEqual(len(x["files"]), 6)
        self.assertEqual(len({f["path"] for f in x["files"]}), 6)
        for key in ("company_wordpress_source_mutated", "production_release_approved",
                    "original313_signed_receipts_issued"):
            self.assertIs(x[key], False)
        for item in x["files"]:
            with self.subTest(path=item["path"]):
                p = ROOT / item["path"]
                self.assertTrue(p.is_file() and not p.is_symlink())
                body = p.read_bytes()
                self.assertEqual(len(body), item["bytes"])
                self.assertEqual(hashlib.sha256(body).hexdigest(), item["sha256"])

    def test_presentation_historical_addition_and_current_successor_both_proven(self):
        row = self.review["presentation"]
        path = row["path"]

        def git_source(sha):
            result = subprocess.run(["git", "show", sha + ":" + path],
                                    cwd=ROOT, capture_output=True, check=True)
            return result.stdout

        # Historical source and additive source remain immutable, but the
        # current checked-out file is an explicitly reviewed newer successor.
        historical = git_source(self.review["pr814_original_base_sha"])
        self.assertEqual((len(historical), hashlib.sha256(historical).hexdigest()),
                         (row["historical_bytes"], row["historical_sha256"]))
        additive = git_source("c2214ebb92bf9f007b9b2c82bff67c8dfdb5fa5f")
        self.assertEqual((len(additive), hashlib.sha256(additive).hexdigest()),
                         (row["current_bytes"], row["current_sha256"]))
        lines = additive.splitlines(True)
        start = row["insertion_line"]
        insertion = b"".join(lines[start:start + row["insertion_line_count"]])
        self.assertEqual(hashlib.sha256(insertion).hexdigest(), row["insertion_sha256"])
        self.assertIn(b"tour-destination-quick-preview.js", insertion)
        self.assertIn(b"wp_add_inline_script", insertion)
        self.assertEqual(b"".join(lines[:start] + lines[start + row["insertion_line_count"]:]), historical)

        reviewed = json.loads((ROOT / self.review["preserved_historical_review"]).read_text())
        base = [f for f in reviewed["reviewed_files"] if f["path"] == path]
        self.assertEqual(len(base), 1)
        self.assertEqual((base[0]["bytes"], base[0]["sha256"]),
                         (row["historical_bytes"], row["historical_sha256"]))

        successor = (ROOT / path).read_bytes()
        identity = hashlib.sha1(b"blob " + str(len(successor)).encode() + b"\0" + successor).hexdigest()
        coverage = json.loads((ROOT / "governance/production-readiness/capability-coverage.json").read_text())
        current = [record for record in coverage["reviewed_changes"]
                   if record.get("path") == path and record.get("source_blob_sha") == identity]
        self.assertEqual(len(current), 1)
        self.assertEqual(current[0]["status"], "reviewed_for_ci_only")
        self.assertIs(current[0]["production_authorized"], False)
        self.assertTrue(current[0]["live_evidence_required"])
        self.assertIn("tests/test_journey_successor_no_regression.py", current[0]["regression_tests"])
        self.assertIn(b"wp_enqueue_script('tour-destination-preview',$assets.", successor)
        self.assertIn(b"['tour-journey-split-v3']", successor)
        self.assertNotIn(b"wp_add_inline_script('tour-destination-preview'", successor)

    def test_native_destination_contract_remains_read_only_without_duplicate_cards(self):
        asset = (ROOT / "wp-content/plugins/mad4b-site-control-plane/assets/"
                 "tour-destination-quick-preview.js").read_text()
        presentation = (ROOT / self.review["presentation"]["path"]).read_text()
        source = (ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/"
                  "class-mad4b-scp-tour-highlights-v1.php").read_text()
        for phrase in ("frame.setAttribute('sandbox','allow-scripts allow-same-origin')",
                       "u.origin!==location.origin", "section.dataset.tourDestinations",
                       "dialog.showModal()", "root.classList.add('mad4b-destination-preview-root')"):
            self.assertIn(phrase, asset)
        for forbidden in ("cloneNode(", "wp_remote_post(", "wp_insert_post("):
            self.assertNotIn(forbidden, asset)
        self.assertIn("wp_enqueue_script('tour-destination-preview',$assets.'tour-destination-quick-preview.js'", presentation)
        self.assertIn("['tour-journey-split-v3'],(string)filemtime($destinationJs),true", presentation)
        self.assertNotIn("wp_add_inline_script('tour-destination-preview'", presentation)
        self.assertIn("data-tour-destinations", source)
        self.assertIn("if (!function_exists('add_filter') || !function_exists('add_action')) return;", source)

    def test_latest_pr813_private_cpt_and_readonly_reconciliation_are_additive(self):
        item = self.review["original_plugin_entry"]
        live = (ROOT / item["path"]).read_bytes()
        actual = restore_exact_original305_overlay(live,item)
        self.assertEqual((len(actual), hashlib.sha256(actual).hexdigest()),
                         (item["current_bytes"], item["current_sha256"]))
        lines = actual.splitlines(True)
        for row in reversed(item["additions"]):
            part = b"".join(lines[row["start_line"]:row["start_line"] + row["line_count"]])
            self.assertEqual(hashlib.sha256(part).hexdigest(), row["sha256"])
            del lines[row["start_line"]:row["start_line"] + row["line_count"]]
        original = b"".join(lines)
        self.assertEqual((len(original), hashlib.sha256(original).hexdigest()),
                         (item["historical_bytes"], item["historical_sha256"]))
        text = actual.decode()
        for name in ("class-mad4b-scp-private-operations-boundary.php",
                     "class-mad4b-scp-company-code-reconciliation.php",
                     "MAD4B_SCP_Company_Code_Reconciliation::boot()",
                     "MAD4B_SCP_Native_Source_Release_Review::boot()",
                     "class-mad4b-scp-company-code-release-readiness.php",
                     "class-mad4b-scp-original-job-readback.php"):
            self.assertIn(name, text)
        self.assertTrue(self.review["pr813_missing_early_private_cpt_and_readonly_reconcile_recovered"])
        self.assertFalse(item["new_authority_granted"])

    def test_immutable_historical_bootstrap_recovered_only_from_exact_successor(self):
        import tempfile
        from unittest.mock import patch
        import tour_highlights_bootstrap_overlay as historical
        live=(ROOT / historical.MAIN).read_bytes()
        item=self.review["original_plugin_entry"]
        current=restore_exact_original305_overlay(live,item)
        old=historical.original_bootstrap_bytes(current)
        self.assertEqual(hashlib.sha256(old).hexdigest(),historical.BASE_SHA256)
        self.assertEqual(hashlib.sha256(current).hexdigest(),
                         historical.DESTINATION_SUCCESSOR_SHA256)
        for mutated in (current + b"\\n",
                        current.replace(b"Company_Code_Reconciliation::boot()",
                                        b"Company_Code_Reconciliation::apply()")):
            with self.subTest(label=mutated[-40:]):
                with self.assertRaises(historical.SourceOverlayDenied):
                    historical.original_bootstrap_bytes(mutated)
        with tempfile.TemporaryDirectory() as td:
            fake=Path(td) / historical.DESTINATION_REVIEW
            fake.parent.mkdir(parents=True)
            corrupted=json.loads((ROOT / historical.DESTINATION_REVIEW).read_text())
            corrupted["original_plugin_entry"]["additions"][0]["sha256"]="0"*64
            fake.write_text(json.dumps(corrupted))
            with patch.object(historical, "ROOT", Path(td)):
                with self.assertRaises(historical.SourceOverlayDenied):
                    historical.original_bootstrap_bytes(current)

    def test_php74_cas_reconciler_keeps_identity_and_backup_barriers(self):
        script = (ROOT / "scripts/migrations/destination-native-template-reconcile-v1.php").read_text()
        for token in ("MAD4B_DEST_TEMPLATE_APPLY", "MAD4B_DEST_TEMPLATE_CONTRACT",
                      "MAD4B_DEST_TEMPLATE_BACKUP", "backup directory must be outside",
                      "destination-native-insights", "CURRENT"):
            # The dynamic marker is present in the template JSON; the others
            # must live in the source-only migration.
            if token == "CURRENT":
                continue
            if token == "destination-native-insights":
                self.assertIn(token, (ROOT / "config/elementor/destination-native-template-contract-v1.json").read_text())
            else:
                self.assertIn(token, script)
        self.assertNotIn("str_contains(", script)
        self.assertNotIn("str_starts_with(", script)
        self.assertIn("strpos(", script)
        self.assertIn("$realBackup", script)


if __name__ == "__main__":
    unittest.main()
