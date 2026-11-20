#!/usr/bin/env python3
import json
import tempfile
import unittest
from pathlib import Path
import source_preflight as m

class SourcePreflightTests(unittest.TestCase):
    def setUp(self):
        self.policy=json.loads((Path(m.__file__).parent/'policy.json').read_text())

    def test_branch_checks(self):
        self.assertEqual(m.branch_name('integration/source-20261119'), 'integration/source-20261119')
        for bad in ('../master','../../etc','feature//x','x@{0}','x;whoami','x.lock','x/','-flag'):
            with self.subTest(bad=bad),self.assertRaises(m.Blocked): m.branch_name(bad)

    def test_path_scope(self):
        self.assertEqual(m.safe_path(m.PREFIX+'includes/test.php'),'plugin/includes/test.php')
        self.assertEqual(m.safe_path(m.MU_PREFIX+'529-mad4b-example.php'),'mu/529-mad4b-example.php')
        self.assertIsNone(m.safe_path(m.MU_PREFIX+'unrelated-optimizer.php'))
        for bad in ('/tmp/file','wp-content/plugins/mad4b-site-control-plane/../../passwd'):
            with self.assertRaises(m.Blocked):m.safe_path(bad)

    def test_dev_tests_excluded_from_release_diff(self):
        self.assertFalse(m.release_path('plugin/tests/runtime.php'))
        self.assertFalse(m.release_path('plugin/docs/CHANGELOG.md'))
        self.assertTrue(m.release_path('plugin/includes/authorization.php'))
        self.assertTrue(m.release_path('mu/529-mad4b-example.php'))

    def test_exact_digest_and_changed_identity(self):
        a={'plugin/includes/class-mad4b-scp-authorization.php':'a'*64,
           'plugin/tests/runtime.php':'c'*64,'mu/529-mad4b-example.php':'d'*64}
        b={'plugin/includes/class-mad4b-scp-authorization.php':'b'*64,
           'plugin/tests/runtime.php':'e'*64,'mu/529-mad4b-example.php':'d'*64,
           'plugin/includes/site-extra.php':'f'*64}
        r=m.reconcile(a,b,self.policy)
        self.assertEqual(r['changed'],1)
        self.assertEqual(r['live_only'],1)
        self.assertEqual(r['source_development_files_excluded'],1)
        self.assertEqual(r['protected_conflicts'],1)
        self.assertEqual(r['reconciliation_delta_count'],2)
        self.assertEqual(r['reconciliation_actions'][0]['default_action'],'PRESERVE_TARGET_AND_REVIEW')
        self.assertFalse(r['preflight_conflict_free'])
        self.assertEqual(r['reconciliation_plan_sha256'],m.digest(m.canonical(r['reconciliation_actions'])))

    def test_site_specific_identity_preserved(self):
        path='plugin/includes/class-mad4b-scp-site-profile.php'
        a={path:'a'*64};b={path:'b'*64}
        r=m.reconcile(a,b,self.policy)
        self.assertEqual(r['site_specific_conflicts'],1)
        self.assertEqual(r['protected_conflicts'],1)
        self.assertEqual(r['reconciliation_actions'][0]['category'],'site_identity_or_media_contract')
        self.assertFalse(r['target_in_place_overwrite_allowed'])

    def test_manifest_self_digest_and_integrity(self):
        name='includes/class-mad4b-scp-authorization.php';content_hash='a'*64
        rows=[{'path':name,'bytes':37,'sha256':content_hash}]
        lines=f'{name}\x0037\x00{content_hash}\n'
        sha='4'*40
        raw=json.dumps({'package_files':rows,'package_manifest_digest':m.digest(lines.encode()),'source_commit_sha':sha}).encode()
        good=m.source_manifest_status(raw,{'plugin/'+name:content_hash},sha)
        self.assertTrue(good['self_digest_valid'])
        self.assertTrue(good['source_commit_matches_pin'])
        self.assertEqual(good['matching_files'],1)
        bad=m.source_manifest_status(raw,{'plugin/'+name:'b'*64},sha)
        self.assertEqual(bad['missing_or_mismatched'],1)
        stale=m.source_manifest_status(raw,{'plugin/'+name:content_hash},'5'*40)
        self.assertFalse(stale['source_commit_matches_pin'])
        self.assertFalse(m.source_manifest_status(b'{bad json',{},sha)['self_digest_valid'])

    def test_write_receipt_never_authorizes(self):
        with tempfile.TemporaryDirectory() as d:
            base=Path(d)
            report_path=base/'candidate.json'
            selection=base/'selected.json'
            record={'contract':'test','preflight':{'status':'NEEDS_RECONCILIATION'},
                    'source':{'type':'github_master','immutable_git_sha':'f'*40}}
            m.write_receipt(record,report_path,True,{'reports_dir':d,'selection_file':str(selection)})
            selection_data=json.loads(selection.read_text())
            self.assertEqual(selection_data['selected_state'],'CANDIDATE_NEEDS_RELEASE_APPROVAL')
            self.assertFalse(selection_data['production_deployment_allowed'])
            self.assertFalse(selection_data['authorizing'])
            self.assertEqual(selection_data['preflight_receipt_sha256'],m.digest(m.canonical(record)))
            self.assertEqual(json.loads(report_path.read_text()),record)
            self.assertEqual(oct(report_path.stat().st_mode & 0o777),'0o600')

    def test_live_symlink_scope(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)
            plugin=p/'wp-content/plugins/mad4b-site-control-plane'
            plugin.mkdir(parents=True)
            (plugin/'main.php').write_bytes(b'<?php // safe')
            mu=p/'wp-content/mu-plugins';mu.mkdir()
            (mu/'thirdparty.php').symlink_to('/etc/passwd')
            result=m.inventory_live(p,self.policy)
            self.assertIn('plugin/main.php',result)
            (mu/'529-mad4b-risk.php').symlink_to('/etc/passwd')
            with self.assertRaises(m.Blocked): m.inventory_live(p,self.policy)

if __name__=='__main__':unittest.main(verbosity=2)
