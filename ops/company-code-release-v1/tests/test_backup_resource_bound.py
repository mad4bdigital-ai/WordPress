"""Backup capacity is policy-owned, plan-pinned and enforced before writing."""
import unittest
from test_release import CodeReleaseTests, release

class BackupBoundTests(unittest.TestCase):
    setUp = CodeReleaseTests.setUp
    tearDown = CodeReleaseTests.tearDown
    grant = CodeReleaseTests.grant

    def test_site_resource_limit_is_pinned_to_immutable_plan(self):
        self.policy["targets"]["demo:staging"]["resource_limits"] = {
            "backup_json_bytes":2_000_000}
        plan=release.make_plan(self.request,self.policy)
        self.assertEqual(plan["backup_json_max_bytes"],2_000_000)
        self.policy["targets"]["demo:staging"]["resource_limits"][
            "backup_json_bytes"]=3_000_000
        with self.assertRaisesRegex(release.Denied,
                                    "BACKUP_RESOURCE_LIMIT_POLICY_DRIFT"):
            release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_oversized_backup_denied_before_mutating_target(self):
        self.target.write_bytes(b"x"*810_000)
        self.request["files"][0]["expected_before_sha256"]=release.digest(
            self.target.read_bytes())
        self.policy["targets"]["demo:staging"]["resource_limits"] = {
            "backup_json_bytes":1_000_000}
        self.plan=release.make_plan(self.request,self.policy)
        with self.assertRaisesRegex(
                release.Denied, "BACKUP_RESOURCE_LIMIT_EXCEEDED_BEFORE_TARGET_WRITE"):
            release.apply_release(self.plan,self.policy,self.grant(),self.receipts,
                                  runtime_check=lambda *_:None,
                                  preflight_code=lambda *_:None,
                                  validate_code=lambda *_:None)
        self.assertEqual(self.target.read_bytes(),b"x"*810_000)
        self.assertFalse(list(self.receipts.glob("*.intent.json")))
        self.assertFalse(list(self.receipts.glob("*.backup.json")))

    def test_reject_boolean_backup_limit(self):
        self.policy["resource_limits"]={"backup_json_bytes":True}
        with self.assertRaisesRegex(release.Denied,"BACKUP_RESOURCE_LIMIT_INVALID"):
            release.make_plan(self.request,self.policy)

if __name__=="__main__":
    unittest.main()
