import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

import guarded_native_release as release
import source_drift_guard as drift
from test_control_plane_release_preflight import PLAN, SITE, SOURCE, GRANT, EXEC, RECEIPT

OLD="a"*40
NEW="b"*40

def bundle(files, source):
    rows=[{"path":name,"bytes":len(data),"sha256":drift.sha(data)} for name,data in sorted(files.items())]
    canon="".join(x["path"]+"\x00"+str(x["bytes"])+"\x00"+x["sha256"]+"\n" for x in rows)
    prov=json.dumps({"source_commit_sha":source,"package_files":rows,
      "package_manifest_digest":drift.sha(canon.encode()),"manifest_file_count":len(rows),
      "archive_file_count":len(rows)+1}).encode()
    return {**files,drift.PROVENANCE:prov}

class Operator:
    def __init__(self,case):
        self.case=case
        self.calls=[]
        self.allowed=True
        self.valid=True
    def admit(self,plan):
        self.calls.append("admit")
        return {"state":"AUTHORIZED" if self.allowed else "DENIED",
                "site_uuid":plan["site_uuid"],"archive_sha256":plan["candidate_archive_sha256"],
                "original_operation_verified":True}
    def apply(self,plan):
        self.calls.append("apply")
        for name,data in self.case.target.items():
            dest=self.case.root/name
            dest.parent.mkdir(exist_ok=True,parents=True)
            dest.write_bytes(data)
        return self.case.receipt
    def verify(self,plan,receipt):
        self.calls.append("verify")
        return self.valid

class GuardedReleaseTests(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        base=Path(temp.name)
        self.lock_dir=base/"locks"
        self.lock_dir.mkdir(mode=0o700)
        self.root=base/"plugin"
        self.root.mkdir()
        self.original=bundle({"plugin.php":b"old"},OLD)
        self.target=bundle({"plugin.php":b"new"},NEW)
        for name,data in self.original.items():
            (self.root/name).write_bytes(data)
        self.archive=base/"package.zip"
        with zipfile.ZipFile(self.archive,"w") as z:
            for name,data in self.target.items():z.writestr(drift.PREFIX+name,data)
        self.review={"contract":"mad4b.staging-artifact-delta.v1",
                     "source_commit_sha":OLD,"target_source_commit_sha":NEW,
                     "changes":[{"path":name,
                                 "previous_sha256":drift.sha(self.original[name]),
                                 "target_sha256":drift.sha(data)}
                                for name,data in self.target.items() if data!=self.original[name]]}
        manifest=json.loads(self.target[drift.PROVENANCE])["package_manifest_digest"]
        self.plan={**PLAN,"source_commit_sha":NEW,"candidate_archive_sha256":drift.sha(self.archive.read_bytes()),
                   "manifest_digest":manifest}
        self.site=copy.deepcopy(SITE)
        self.source={**SOURCE,"source_commit_sha":NEW,"archive_sha256":self.plan["candidate_archive_sha256"],
                     "manifest_digest":manifest}
        self.grant={**GRANT,"archive_sha256":self.plan["candidate_archive_sha256"]}
        self.executor=copy.deepcopy(EXEC)
        self.receipt={**RECEIPT,"archive_sha256":self.plan["candidate_archive_sha256"],
                      "manifest_digest":manifest}
        self.operator=Operator(self)
    def apply(self):
        return release.guarded_native_release(
            site=self.site,plan=self.plan,source=self.source,grant=self.grant,
            executor=self.executor,root=self.root,original_source=OLD,
            archive=self.archive,new_source=NEW,review=self.review,
            lock_directory=self.lock_dir,native_operator=self.operator)
    def test_verified_original_operation(self):
        result=self.apply()
        self.assertEqual(result["state"],"VERIFIED")
        self.assertEqual(self.operator.calls,["admit","apply","verify"])
        self.assertEqual(drift.verify(drift.read_root(self.root),NEW)["source"],NEW)
    def test_unapproved_change_blocks_before_dispatch(self):
        self.review["changes"]=[]
        with self.assertRaisesRegex(drift.DriftError,"UNREVIEWED_SOURCE_DELTA"):
            self.apply()
        self.assertEqual(self.operator.calls,[])
    def test_source_drift_blocks_before_dispatch(self):
        (self.root/"plugin.php").write_bytes(b"unauthorized")
        with self.assertRaises(drift.DriftError):self.apply()
        self.assertEqual(self.operator.calls,[])
    def test_original_grant_denied(self):
        self.operator.allowed=False
        with self.assertRaisesRegex(release.GuardedReleaseError,"ORIGINAL_OPERATION_GRANT_DENIED"):
            self.apply()
        self.assertEqual(self.operator.calls,["admit"])
    def test_failed_receipt_no_replay(self):
        self.receipt["independent_readback"]=False
        with self.assertRaisesRegex(release.GuardedReleaseError,"ATTEMPTED_UNVERIFIED"):
            self.apply()
        self.assertEqual(self.operator.calls,["admit","apply"])
    def test_production_not_reused(self):
        self.plan["environment"]="production"
        with self.assertRaisesRegex(release.GuardedReleaseError,"NATIVE_RELEASE_PREFLIGHT_BLOCKED"):
            self.apply()
        self.assertEqual(self.operator.calls,[])
    def test_site_release_lock_is_exclusive(self):
        fd=release.acquire(self.lock_dir,self.site["site_uuid"])
        try:
            with self.assertRaisesRegex(release.GuardedReleaseError,"SITE_RELEASE_ALREADY_ACTIVE"):
                self.apply()
            self.assertEqual(self.operator.calls,[])
        finally:
            import fcntl
            fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)
    def test_mismatched_native_receipt_no_success(self):
        self.receipt["site_uuid"]="not-the-site"
        with self.assertRaisesRegex(release.GuardedReleaseError,"ATTEMPTED_UNVERIFIED"):
            self.apply()

if __name__=="__main__":
    unittest.main()
