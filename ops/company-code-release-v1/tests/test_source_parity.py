import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / "source_parity.py"
spec = importlib.util.spec_from_file_location("mad4b_source_parity_test", SOURCE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class GitPreservationTest(unittest.TestCase):
    def test_branch_removal_and_modification_are_explicit(self):
        with tempfile.TemporaryDirectory() as tmp:
            def git(*args):
                return subprocess.check_output(["git", "-C", tmp, *args],
                                               stderr=subprocess.DEVNULL).decode().strip()
            git("init", "-q")
            git("config", "user.email", "source@example.invalid")
            git("config", "user.name", "Source QA")
            a=Path(tmp) / "ops/release"
            a.mkdir(parents=True)
            (a/"a.py").write_text("VPS only\n")
            (a/"b.py").write_text("Original guard\n")
            git("add", ".")
            git("commit", "-qm", "protected source")
            old=git("rev-parse","HEAD")
            (a/"a.py").unlink()
            (a/"b.py").write_text("Weaker guard\n")
            git("add", "-A")
            git("commit", "-qm", "candidate altered")
            later=git("rev-parse","HEAD")
            result=m.compare(tmp,old,later,["ops/release"])
            self.assertEqual(len(result["lost_files"]),1)
            self.assertEqual(len(result["modified_files"]),1)
            self.assertEqual(result["lost_files"][0]["path"],"ops/release/a.py")
            self.assertEqual(result["modified_files"][0]["path"],"ops/release/b.py")
            self.assertEqual(m.compare(tmp,old,old,["ops/release"])["lost_files"],[])
            with self.assertRaisesRegex(ValueError,"SCOPE_INVALID"):
                m.entries(tmp,old,["../etc"])
            with self.assertRaisesRegex(ValueError,"REVISION_INVALID"):
                m.entries(tmp,"--output=/tmp/hijack",["ops/release"])


if __name__ == "__main__":
    unittest.main()
