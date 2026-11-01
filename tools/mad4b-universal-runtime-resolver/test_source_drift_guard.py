import contextlib
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
import source_drift_guard as g

OLD="a"*40
NEW="b"*40

def pack(files, commit):
    rows=[{"path":k,"bytes":len(v),"sha256":g.sha(v)} for k,v in sorted(files.items())]
    data="".join(r["path"]+"\x00"+str(r["bytes"])+"\x00"+r["sha256"]+"\n" for r in rows)
    manifest={"source_commit_sha":commit,"package_files":rows,"manifest_file_count":len(rows),
              "archive_file_count":len(rows)+1,"package_manifest_digest":g.sha(data.encode())}
    return {**files,g.PROVENANCE:json.dumps(manifest).encode()}

class TestDrift(unittest.TestCase):
    def setUp(self):
        tmp=tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root=Path(tmp.name)/"plugin"
        self.root.mkdir()
        self.old=pack({"plugin.php":b"original","includes/part.php":b"original"},OLD)
        self.new=pack({"plugin.php":b"original","includes/part.php":b"changed"},NEW)
        for name,data in self.old.items():
            dst=self.root/name
            dst.parent.mkdir(parents=True,exist_ok=True)
            dst.write_bytes(data)
        self.archive=Path(tmp.name)/"release.zip"
        with zipfile.ZipFile(self.archive,"w") as z:
            for name,data in self.new.items():z.writestr(g.PREFIX+name,data)
        self.changes=[]
        for name in set(self.old)|set(self.new):
            a=g.sha(self.old[name]) if name in self.old else None
            b=g.sha(self.new[name]) if name in self.new else None
            if a!=b:self.changes.append({"path":name,"previous_sha256":a,"target_sha256":b})
        self.review=Path(tmp.name)/"review.json"
        self.approve(self.changes)
    def approve(self,rows):
        self.review.write_text(json.dumps({"contract":"mad4b.staging-artifact-delta.v1",
            "source_commit_sha":OLD,"target_source_commit_sha":NEW,"changes":rows}))
    def run_guard(self,upgrade=False):
        args=["--plugin-dir",str(self.root),"--expected-source",OLD]
        if upgrade:
            args+=["--candidate-zip",str(self.archive),"--candidate-source",NEW,
                   "--candidate-sha256",g.sha(self.archive.read_bytes()),"--reviewed-changes",str(self.review)]
        with contextlib.redirect_stdout(io.StringIO()):
            return g.main(args)
    def test_current_clean(self):self.assertEqual(self.run_guard(),0)
    def test_approved_update(self):self.assertEqual(self.run_guard(True),0)
    def test_modified_live_byte(self):
        (self.root/"plugin.php").write_bytes(b"tampered")
        self.assertEqual(self.run_guard(),75)
    def test_added_file(self):
        (self.root/"new.php").write_bytes(b"untracked")
        self.assertEqual(self.run_guard(),75)
    def test_missing_file(self):
        (self.root/"includes/part.php").unlink()
        self.assertEqual(self.run_guard(),75)
    def test_symlink(self):
        (self.root/"link").symlink_to(self.root/"plugin.php")
        self.assertEqual(self.run_guard(),75)
    def test_unreviewed_change(self):
        self.approve([])
        self.assertEqual(self.run_guard(True),75)
    def test_bad_digest(self):
        args=["--plugin-dir",str(self.root),"--expected-source",OLD,"--candidate-zip",str(self.archive),
              "--candidate-source",NEW,"--candidate-sha256","0"*64,"--reviewed-changes",str(self.review)]
        with contextlib.redirect_stdout(io.StringIO()):self.assertEqual(g.main(args),75)
    def test_unsafe_path(self):
        with zipfile.ZipFile(self.archive,"w") as z:z.writestr(g.PREFIX+"../escape",b"x")
        self.assertEqual(self.run_guard(True),75)
    def test_duplicate_delta(self):
        self.approve(self.changes+self.changes[:1])
        self.assertEqual(self.run_guard(True),75)
    def test_stale_pin(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(g.main(["--plugin-dir",str(self.root),"--expected-source",NEW]),75)

if __name__=="__main__":
    unittest.main()
