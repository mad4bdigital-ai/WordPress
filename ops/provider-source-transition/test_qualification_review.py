import unittest
from bind_qualification_review import bind
class ReviewTest(unittest.TestCase):
    def test_exact_binding_and_negative_cases(self):
        h="a"*40; z="b"*64; d="c"*64
        b=dict(source_sha=h,zip_sha256=z,unsigned=True,certified=False,version="0.4.16")
        p=dict(descriptor_sha256=d,scope="isolated_fixture_only",state="REVIEW_REQUIRED",native_policy_changed=False,runtime_admission_verified=False,authorizing=False,capability_count=13,covered_names=24)
        a=dict(neutral_plugin_version="0.4.16",source_only_audit=True,safe_to_cutover=False)
        self.assertFalse(bind(b,z,p,a,h,d)["authorizing"])
        for changed in ({"source_sha":"x"},{"zip_sha256":"x"},{"certified":True},{"unsigned":False}):
            with self.assertRaises(ValueError):bind(dict(b,**changed),z,p,a,h,d)
        for changed in ({"descriptor_sha256":"x"},{"authorizing":True},{"runtime_admission_verified":True},{"native_policy_changed":True}):
            with self.assertRaises(ValueError):bind(b,z,dict(p,**changed),a,h,d)
