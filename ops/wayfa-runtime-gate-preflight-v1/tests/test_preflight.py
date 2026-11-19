import hashlib
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from preflight import inspect, classify

class ProvenancePreflight(unittest.TestCase):
    def test_match_drift_and_manifest_immutability(self):
        with TemporaryDirectory() as d:
            root = Path(d)
            file = root / "includes" / "example.php"
            file.parent.mkdir()
            file.write_bytes(b"<?php echo 1;")
            raw = file.read_bytes()
            h = hashlib.sha256(raw).hexdigest()
            line = b"includes/example.php\0" + str(len(raw)).encode() + b"\0" + h.encode() + b"\n"
            manifest = {
                "contract":"mad4b.build-provenance.v1",
                "package_files":[{"path":"includes/example.php","bytes":len(raw),"sha256":h}],
                "source_commit_sha":"a" * 40,
                "package_manifest_digest":hashlib.sha256(line).hexdigest(),
            }
            m = root / "MAD4B-BUILD-PROVENANCE.json"
            m.write_text(json.dumps(manifest))
            original=m.read_bytes()
            a=inspect(root)
            self.assertEqual((a["matching_entries"],len(a["changed"])),(1,0))
            self.assertTrue(a["manifest_matches_live"])
            file.write_bytes(b"<?php echo 2;")
            b=inspect(root)
            self.assertEqual(len(b["changed"]),1)
            self.assertIn("runtime_manifest_mismatch",b["hard_blockers"])
            self.assertEqual(m.read_bytes(),original)

    def test_insecure_and_duplicate_paths_fail_closed(self):
        with TemporaryDirectory() as d:
            root=Path(d)
            m=root / "MAD4B-BUILD-PROVENANCE.json"
            for name in ("../bad.php","/etc/passwd","a\\b.php"):
                m.write_text(json.dumps({"contract":"mad4b.build-provenance.v1",
                    "package_files":[{"path":name,"bytes":1,"sha256":"a"*64}]}))
                with self.assertRaises(ValueError):
                    inspect(root)

    def test_unmanifested_php_is_not_auto_approved(self):
        self.assertEqual(classify("includes/worker.php"),"unmanifested_php")
        self.assertEqual(classify("includes/worker.php.before-fix"),"backup")
        self.assertEqual(classify("assets/script.js"),"assets_or_data")

if __name__=="__main__":
    unittest.main()
