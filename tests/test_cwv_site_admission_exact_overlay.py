"""Negative exact SHA contract for a non-authorizing site-activation overlay."""
import copy
import json
import unittest
from pathlib import Path
from unittest.mock import patch

import cwv_site_admission_exact_overlay as cwv

class ExactOverlay(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.actual=(cwv.ROOT / cwv.MAIN).read_bytes()
        cls.preceding=cwv.ROOT / 'docs/source-reconciliation/PR977-CWV-SITE-ADMISSION-20261120.md'

    def test_reconstructs_previous_exact_git_blob(self):
        self.assertEqual(cwv.blob(cwv.strip_exact_public_kernel(self.actual)), cwv.NEW)
        self.assertEqual(cwv.blob(cwv.strip_exact_site_admission(self.actual)),cwv.OLD)

    def test_unknown_bootstrap_bytes_denied(self):
        for mutation in (self.actual+b'\n',self.actual.replace(b'::admitted()',b'::admitted_without_authority()',1),self.actual.replace(b'admin',b'grant',1)):
            with self.subTest(sha=cwv.blob(mutation)):
                with self.assertRaises(AssertionError):cwv.strip_exact_site_admission(mutation)

    def test_unapproved_source_cannot_claim_authorization(self):
        file=cwv.ROOT/'governance/production-readiness/capability-coverage.json'
        manifest=json.loads(file.read_text());manifest['production_promotion_from_ci']=True
        with patch.object(cwv.Path,'read_text',autospec=True,side_effect=lambda obj,*a,**k: json.dumps(manifest) if str(obj)==str(file) else Path.read_text(obj,*a,**k)):
            with self.assertRaises(AssertionError):cwv.strip_exact_site_admission(self.actual)

    def test_missing_capability_review_is_rejected(self):
        file=cwv.ROOT/'governance/production-readiness/capability-coverage.json'
        registry=json.loads(file.read_text())
        registry['reviewed_capabilities']=[r for r in registry['reviewed_capabilities']
            if r.get('id')!='service:wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cwv-site-admission.php']
        original_read=Path.read_text
        def forged_read(obj,*args,**kwargs):
            return json.dumps(registry) if obj==file else original_read(obj,*args,**kwargs)
        with patch.object(cwv.Path,'read_text',forged_read):
            with self.assertRaises(AssertionError):
                cwv.strip_exact_site_admission(self.actual)

    def test_copied_provider_guard_is_rejected(self):
        # Synthetic checked source; the real repository is never mutated.
        original_read=cwv.Path.read_bytes
        helper=cwv.ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cwv-site-admission.php'
        def fake_read(obj,*a,**k):
            if obj == helper:return b'<?php // fake activated provider\n'
            return original_read(obj,*a,**k)
        with patch.object(cwv.Path,'read_bytes',fake_read):
            with self.assertRaises(AssertionError):cwv.strip_exact_site_admission(self.actual)

if __name__=='__main__':unittest.main()
