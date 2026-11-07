from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from kernel import Kernel, FCPError
from release import Generation, preview, verify_apply


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.k=Kernel(Path(self.tmp.name)/"release.db")
        self.k.add_dependency("shared:pricing","core:rates","Runtime")
        self.k.add_dependency("core:rates","company:wayfatours","Schema")
        self.k.add_dependency("core:rates","company:are","Schema")
        self.k.add_dependency("unrelated:template","company:etg","Presentation")
        self.g=Generation("gen-2","a"*64,"b"*40,1,2)

    def tearDown(self):
        self.k.close()
        self.tmp.cleanup()

    def test_selective_compatibility(self):
        def observer(company):
            if company=="wayfatours":
                return {"protocol_version":1,"provider":"coolify",
                        "local_release_admission":True,"generation":"gen-1",
                        "overrides_digest":"company-owner-overrides"}
            return {"protocol_version":3,"provider":"coolify",
                    "local_release_admission":True,"generation":"gen-1"}
        plan=preview(self.k,"shared:pricing",self.g,observer)
        self.assertEqual(len(plan["targets"]),2)
        result={t["company_id"]:t for t in plan["targets"]}
        self.assertTrue(result["wayfatours"]["eligible"])
        self.assertFalse(result["are"]["eligible"])
        self.assertEqual(result["are"]["reason"],"PROTOCOL_INCOMPATIBLE")
        self.assertEqual(result["wayfatours"]["overrides_digest"],"company-owner-overrides")
        self.assertFalse(plan["deployed"])
        self.assertFalse(plan["company_data_copied"])
        good={"current_generation":"gen-2","source_digest":"a"*64}
        self.assertEqual(verify_apply(plan,"wayfatours",good,lambda *_:True)["state"],"VERIFIED")
        self.assertEqual(verify_apply(plan,"wayfatours",good,lambda *_:False)["state"],"UNVERIFIED")
        self.assertEqual(verify_apply(plan,"wayfatours",{},lambda *_:True)["state"],"UNVERIFIED")
        with self.assertRaises(FCPError):
            verify_apply(plan,"are",good,lambda *_:True)
        with self.assertRaises(FCPError):
            verify_apply(plan,"etg",good,lambda *_:True)

    def test_invalid_release_and_missing_provider(self):
        with self.assertRaises(FCPError):
            Generation("gen-2","not-a-digest","b"*40,1,2)
        plan=preview(self.k,"unrelated:template",self.g,
                     lambda _:{"protocol_version":1,"provider":None,"local_release_admission":True})
        self.assertEqual(plan["targets"][0]["company_id"],"etg")
        self.assertEqual(plan["targets"][0]["reason"],"COOLIFY_PROVIDER_UNAVAILABLE")


if __name__=="__main__":
    unittest.main()
