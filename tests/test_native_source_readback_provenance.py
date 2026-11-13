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
        module=(ROOT / source.MODULE).read_bytes()
        self.assertEqual(source.checked_readback_module(module), source.MODULE_SHA256)
        for candidate in (module + b"\n", module.replace(b"manage_options",b"edit_posts"),
                          module.replace(b"readonly",b"readwrite")):
            with self.assertRaises(source.ProvenanceDenied):
                source.checked_readback_module(candidate)

    def test_old_provenance_guard_is_not_rewritten(self):
        old_gate = (ROOT / "tests/test_additive_mad4b_plugin_sources.py").read_text()
        self.assertIn("self.assertEqual(before.stdout,current,path)", old_gate)
        self.assertIn("self.assertEqual(len(reviewed),22)", old_gate)
        self.assertNotIn("control_plane_readback_provenance", old_gate)

if __name__ == "__main__":
    unittest.main()
