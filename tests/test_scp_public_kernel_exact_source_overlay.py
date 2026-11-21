"""Negative and positive source binding for new eight-line non-authorizing kernel."""
import json
import unittest
from pathlib import Path
from unittest.mock import patch
import scp_public_kernel_exact_source_overlay as kernel

class ExactReviewedSourceSuccessor(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.current = (kernel.ROOT/kernel.MAIN).read_bytes()

    def test_bounded_exact_inversion(self):
        self.assertEqual(kernel.blob(self.current),kernel.SUCCESSOR)
        restored=kernel.strip_exact_public_kernel(self.current)
        self.assertEqual(kernel.blob(restored),kernel.PREVIOUS)
        self.assertEqual(self.current.count(kernel.ADDITION),1)
        self.assertEqual(len(kernel.ADDITION.splitlines()),8)

    def test_older_reviewed_source_unchanged(self):
        old=kernel.strip_exact_public_kernel(self.current)
        self.assertEqual(kernel.strip_exact_public_kernel(old),old)

    def test_reject_unauthorized_or_unknown_bytes(self):
        altered=[
            self.current+b"\n",
            self.current.replace(b"Public_Document_Kernel::try_boot()",b"Public_Document_Kernel::always_true()",1),
            self.current.replace(b"read-only, signed-site",b"writable, no-site",1),
            self.current.replace(b"admin-route-registry",b"authorization-bypass",1),
        ]
        for row in altered:
            with self.subTest(sha=kernel.blob(row)):
                with self.assertRaises(AssertionError):
                    kernel.strip_exact_public_kernel(row)

    def test_dependency_checksum_mutation_denied(self):
        target=kernel.ROOT/kernel.KERNEL
        original=kernel.Path.read_bytes
        def corrupt(obj,*args,**kwargs):
            if obj==target:return b"<?php unreviewed override"
            return original(obj,*args,**kwargs)
        with patch.object(kernel.Path,'read_bytes',corrupt):
            with self.assertRaises(AssertionError):
                kernel.strip_exact_public_kernel(self.current)

    def test_unapproved_promotion_is_rejected(self):
        coverage=kernel.ROOT/"governance/production-readiness/capability-coverage.json"
        doc=json.loads(coverage.read_text());doc["production_promotion_from_ci"]=True
        orig=Path.read_text
        def fake(obj,*args,**kwargs):
            return json.dumps(doc) if obj==coverage else orig(obj,*args,**kwargs)
        with patch.object(kernel.Path,'read_text',fake):
            with self.assertRaises(AssertionError):
                kernel.strip_exact_public_kernel(self.current)

    def test_missing_exact_capability_review_denied(self):
        coverage=kernel.ROOT/"governance/production-readiness/capability-coverage.json"
        doc=json.loads(coverage.read_text())
        doc["reviewed_capabilities"]=[
            x for x in doc["reviewed_capabilities"] if x.get("id")!="service:"+kernel.KERNEL]
        orig=Path.read_text
        def fake(obj,*args,**kwargs):
            return json.dumps(doc) if obj==coverage else orig(obj,*args,**kwargs)
        with patch.object(kernel.Path,'read_text',fake):
            with self.assertRaises(AssertionError):
                kernel.strip_exact_public_kernel(self.current)

if __name__=="__main__":
    unittest.main()
