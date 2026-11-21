"""Fail closed on tampered current source, historical SHA, or release claims."""
import json
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

import control_plane_pr995_selective_historical_source as checker


class ReviewedSuccessorHistoricalIntegrity(TestCase):
    def test_exact_old_and_current_are_both_immutable(self):
        path=checker.ROOT/checker.PREFIX/checker.OPERATOR
        self.assertTrue(checker.assert_operator_historical_and_exact_successor(
            checker.ORIGINAL_SHA,path.read_bytes()))

    def test_wrong_old_source_or_current_source_denied(self):
        current=(checker.ROOT/checker.PREFIX/checker.OPERATOR).read_bytes()
        with self.assertRaises(AssertionError):
            checker.assert_operator_historical_and_exact_successor("0"*64,current)
        with self.assertRaises(AssertionError):
            checker.assert_operator_historical_and_exact_successor(
                checker.ORIGINAL_SHA,current+b"\n// unsafe-source")

    def test_release_claim_and_digest_tamper_denied(self):
        original_read=Path.read_text
        pristine=(checker.ROOT/checker.PREFIX/checker.OPERATOR).read_bytes()
        manifest=json.loads(checker.MANIFEST.read_text())
        cases=[
            ("production_authorized",True),
            ("release_approved",True),
            ("host_grant_issued",True),
            ("operator_grants_written",True),
            ("source_only",False),
            ("release_gate","APPROVED"),
        ]
        for name,wrong in cases:
            with self.subTest(claim=name):
                modified=dict(manifest)
                modified[name]=wrong
                def forged(self,*a,**kw):
                    if self==checker.MANIFEST:
                        return json.dumps(modified)
                    return original_read(self,*a,**kw)
                with patch.object(Path,"read_text",forged):
                    with self.assertRaises(AssertionError):
                        checker.assert_operator_historical_and_exact_successor(
                            checker.ORIGINAL_SHA,pristine)
        modified=dict(manifest)
        paths={k:dict(v) for k,v in modified["source_paths"].items()}
        paths[checker.OPERATOR]["reviewed_sha256"]="0"*64
        modified["source_paths"]=paths
        def forged(self,*a,**kw):
            return json.dumps(modified) if self==checker.MANIFEST else original_read(self,*a,**kw)
        with patch.object(Path,"read_text",forged):
            with self.assertRaises(AssertionError):
                checker.assert_operator_historical_and_exact_successor(
                    checker.ORIGINAL_SHA,pristine)
