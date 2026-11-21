"""Separate exact Control Plane source delta audit, not a legacy gate bypass."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "ops/native-source-publisher-v1/control_plane_readback_provenance.py"
spec = importlib.util.spec_from_file_location("_mad4b_cp_readback_provenance_fixture", FILE)
source = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source)


class ReadbackProvenance(unittest.TestCase):
    def test_exact_existing_source_delta_never_authorizes_release(self):
        # The historical BASE-anchored auditor MUST reject a newly reconciled
        # Original313 PR tree; it is not a signed source-approval shortcut.
        with self.assertRaisesRegex(source.ProvenanceDenied,
            "CONTROL_PLANE_READBACK_BOOT_DELTA_UNREVIEWED"):
            source.audit()

    def test_only_expected_bootstrap_insertion_is_accepted(self):
        import subprocess
        old = subprocess.check_output(["git", "-C", str(ROOT), "show",
                   source.BASE + ":" + source.MAIN])
        original_delta = old.replace(source.ANCHOR, source.ANCHOR + source.INSERT, 1)
        self.assertEqual(source.sha256(original_delta), source.CANDIDATE_SHA256)
        candidate = original_delta
        for anchor, insert in (
            (source.BRIDGE_REQUIRE_ANCHOR, source.BRIDGE_REQUIRE_INSERT),
            (source.BRIDGE_BOOT_ANCHOR, source.BRIDGE_BOOT_INSERT),
        ):
            self.assertEqual(candidate.count(anchor), 1)
            candidate = candidate.replace(anchor, anchor + insert, 1)
        review = source.compare_bytes(old, candidate)
        self.assertEqual(review["candidate_sha256"], source.V21_CANDIDATE_SHA256)
        self.assertEqual(review["prior_readback_candidate_sha256"], source.CANDIDATE_SHA256)
        self.assertEqual(review["additional_readonly_bridge_lines"],3)
        for mutated in (candidate.replace(b"bounded admin Ability", b"unbounded admin Ability"),
                        candidate + b"\n", candidate.replace(b"Original305", b"Original306"),
                        old, original_delta,
                        candidate.replace(source.BRIDGE_BOOT_INSERT,b''),
                        candidate.replace(source.BRIDGE_REQUIRE_INSERT,b'')):
            with self.assertRaises(source.ProvenanceDenied):
                source.compare_bytes(old, mutated)
        with self.assertRaises(source.ProvenanceDenied):
            source.compare_bytes(old + b"unexpected", candidate)

    def test_exact_readback_module_pinned_but_not_installed(self):
        # Verify historical module SHA without pretending the new bootstrap
        # matches the old V21 exact-source fingerprint.
        with self.assertRaises(source.ProvenanceDenied):
            source.audit()
        import hashlib
        import json
        import subprocess
        # The archived V21 historical source object remains immutable: do not
        # rewrite its original hash when a reviewed successor is integrated.
        archived="05b6a111090a3ce473b876e7e0c155155da31cb3"
        old=subprocess.check_output(["git", "-C", str(ROOT), "show",
                                     archived + ":" + source.MODULE])
        self.assertEqual(source.checked_readback_module(old), source.MODULE_SHA256)
        for candidate in (old + b"\n", old.replace(b"manage_options",b"edit_posts"),
                          old.replace(b"readonly",b"readwrite")):
            with self.assertRaises(source.ProvenanceDenied):
                source.checked_readback_module(candidate)
        # The merged successor must have its *own* reviewed source provenance.
        # An older pin must reject it; that denial does not mean the reviewed
        # successor should be overwritten by the archived implementation.
        current=(ROOT / source.MODULE).read_bytes()
        with self.assertRaises(source.ProvenanceDenied):
            source.checked_readback_module(current)
        ledger=json.loads((ROOT / 'governance/production-readiness/capability-coverage.json').read_text())
        self.assertFalse(ledger['authorizing'])
        self.assertFalse(ledger['production_promotion_from_ci'])
        blob=hashlib.sha1(b'blob ' + str(len(current)).encode() + b'\0' + current).hexdigest()
        reviewed=[r for r in ledger['reviewed_changes']
                  if r.get('path')==source.MODULE and r.get('source_blob_sha')==blob]
        self.assertEqual(len(reviewed),1,'current readback source has no exact CI-only review')
        self.assertIn('Central controller',reviewed[0].get('review_rationale',''))
        bootstrap=(ROOT/source.MAIN).read_text()
        self.assertIn("MAD4B_SCP_Original_Job_Readback::boot();",bootstrap)
        self.assertIn('MAD4B_SCP_Native_Source_Release_Review::boot();',bootstrap)

    def test_old_provenance_guard_is_not_rewritten(self):
        old_gate = (ROOT / "tests/test_additive_mad4b_plugin_sources.py").read_text()
        self.assertIn("self.assertEqual(before.stdout,current,path)", old_gate)
        self.assertIn("self.assertEqual(len(reviewed),22)", old_gate)
        self.assertNotIn("control_plane_readback_provenance", old_gate)

if __name__ == "__main__":
    unittest.main()
