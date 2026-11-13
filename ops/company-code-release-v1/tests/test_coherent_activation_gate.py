"""Do not confuse file-level os.replace with multi-file HTTP cutover."""
import unittest
from test_release import CodeReleaseTests, release


class CoherentActivationTests(unittest.TestCase):
    setUp = CodeReleaseTests.setUp
    tearDown = CodeReleaseTests.tearDown
    grant = CodeReleaseTests.grant
    two_file_transaction = CodeReleaseTests.two_file_transaction
    authorized_apply = CodeReleaseTests.authorized_apply

    def test_multi_file_php_plan_is_held_before_write_when_required(self):
        second=self.two_file_transaction()
        self.policy["targets"]["demo:staging"]["activation_policy"]={
            "require_coherent_cutover":True}
        self.plan=release.make_plan(self.request,self.policy)
        self.assertTrue(self.plan["activation_contract"]["multi_file_php_risk"])
        with self.assertRaisesRegex(release.Denied,
                                    "COHERENT_HTTP_CUTOVER_PROVIDER_NOT_ENROLLED"):
            self.authorized_apply()
        self.assertIn("OLD",self.target.read_text())
        self.assertIn("ORIGINAL SECOND",second.read_text())
        self.assertFalse(list(self.receipts.glob("*.intent.json")))

    def test_contract_prevents_disabling_gate_after_plan(self):
        second=self.two_file_transaction()
        self.policy["targets"]["demo:staging"]["activation_policy"]={
            "require_coherent_cutover":False}
        signed=release.make_plan(self.request,self.policy)
        self.policy["targets"]["demo:staging"]["activation_policy"][
            "require_coherent_cutover"]=True
        with self.assertRaisesRegex(release.Denied,"ACTIVATION_CONTRACT_POLICY_DRIFT"):
            release.confirm_plan(signed,self.policy,expect_preimage=True)

    def test_single_php_file_does_not_need_multi_file_cutover(self):
        self.policy["targets"]["demo:staging"]["activation_policy"]={
            "require_coherent_cutover":True}
        self.plan=release.make_plan(self.request,self.policy)
        result=self.authorized_apply()
        self.assertEqual(result["state"],"APPLIED_VERIFIED")


if __name__=="__main__":
    unittest.main()
