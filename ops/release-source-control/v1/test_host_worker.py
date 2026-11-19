#!/usr/bin/env python3
"""Hermetic read-only issuer admission tests. Never creates live host evidence."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import host_worker as w
import source_preflight as pre

class HostWorkerTests(unittest.TestCase):
    def setUp(self):
        self.site='toursdev'
        self.source_sha='a'*40
        self.inventory={'plugin/mad4b.php':'b'*64}
        self.profile={'site_uuid':'uuid-test','profile_digest':'f'*64,'origin':'https://source.test'}
        self.req={'type':'github_candidate','reference':'feature/test','requested_at':100,'site_uuid':'uuid-test','profile_digest':'f'*64,'authorizing':False}
        self.target_hash=pre.inventory_digest(self.inventory)
        self.report={'preflight':{'status':'READY_FOR_CERTIFICATION_REVIEW','reconciliation_complete':True},
             'source':{'immutable_snapshot':True,'immutable_git_sha':self.source_sha,'inventory_sha256':self.target_hash,
                       'package_manifest':{'self_digest_valid':True,'missing_or_mismatched':0,'source_commit_matches_pin':True}},
             'targets':{'toursdev':{'site_inventory_sha256':self.target_hash,
                       'reconciliation':{'preflight_conflict_free':True,'reconciliation_plan_sha256':'c'*64}}}}
        self.cfg={'repo':'/tmp/does-not-matter','toursdev':'/tmp/does-not-matter'}

    def test_never_sign_conflicts(self):
        with patch.object(w,'PRIVATE',Path('/tmp/no-private-key')):
            for field,val in [('status','NEEDS_RECONCILIATION')]:
                self.report['preflight'][field]=val
                self.assertIsNone(w.attest(self.report,'toursdev',self.req,self.profile,self.cfg,Path('/tmp/missing')))

    def test_manifest_stale_denied(self):
        self.report['source']['package_manifest']['missing_or_mismatched']=7
        self.assertIsNone(w.attest(self.report,'toursdev',self.req,self.profile,self.cfg,Path('/tmp/missing')))

    def test_nonimmutable_live_denied(self):
        self.report['source']['immutable_snapshot']=False
        self.assertIsNone(w.attest(self.report,'toursdev',self.req,self.profile,self.cfg,Path('/tmp/missing')))

    def test_target_conflict_denied(self):
        self.report['targets']['toursdev']['reconciliation']['preflight_conflict_free']=False
        self.assertIsNone(w.attest(self.report,'toursdev',self.req,self.profile,self.cfg,Path('/tmp/missing')))

    def test_source_moved_denied(self):
        with (patch.object(pre,'source_identity',return_value=(
                {'immutable_git_sha':'e'*40},self.inventory,None)),
              patch.object(pre,'inventory_live',return_value=self.inventory)):
            with self.assertRaises(w.WorkerBlocked):
                w.attest(self.report,'toursdev',self.req,self.profile,self.cfg,Path('/tmp/missing'))

if __name__=='__main__':unittest.main(verbosity=2)
