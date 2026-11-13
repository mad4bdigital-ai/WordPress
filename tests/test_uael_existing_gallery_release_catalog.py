"""Hermetic core CAS plan preview for an existing dynamic MU-plugin component.

No actual company policy, signed grant, WordPress request or production file is
modified. The source Git commit—not working tree content—is authoritative.
"""
from __future__ import annotations
import hashlib,importlib.util,json,subprocess,sys,tempfile,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('mad4b_gallery_release_source_only',
    ROOT/'ops/company-code-release-v1/company_code_release.py')
core=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(core)
CATALOG=ROOT/'ops/company-code-release-v1/catalog/mad4b-release-components.json'
COMPONENT='mad4b-uael-tour-gallery-tabs'


class ExactGalleryReleaseCAS(unittest.TestCase):
    def setUp(self):
        self.files=['wp-content/mu-plugins/mad4b-uael-tour-gallery-addon.php',
                    'wp-content/mu-plugins/mad4b-uael-server-defer-v1.php']
        self.sha=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],
                                         text=True).strip()
        tmp=tempfile.TemporaryDirectory(prefix='mad4b-gallery-release-cas-')
        self.addCleanup(tmp.cleanup)
        self.site=Path(tmp.name)
        self.preimages={}
        for f in self.files:
            dest=self.site/f
            dest.parent.mkdir(parents=True,exist_ok=True)
            # No live customer file is modified. Exact bytes only matter for
            # SHA comparison and deterministic, non-mutating plan admission.
            dest.write_bytes(b'<?php /* previously deployed UAEL gallery */\n')
            self.preimages[f]=hashlib.sha256(dest.read_bytes()).hexdigest()
        self.policy={'contract':core.POLICY_CONTRACT,'source_git_root':str(ROOT),
            'targets':{'example-company:staging':{
                'site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
                'origin':'https://example.invalid',
                'root':str(self.site),'container':'example-wp',
                'allowed_targets':['wp-content/mu-plugins/mad4b-existing-sentinel.php'],
                'release_catalog':'ops/company-code-release-v1/catalog/mad4b-release-components.json'}}}
        self.spec={'contract':core.SPEC_CONTRACT,'company':'example-company',
            'environment':'staging',
            'site_uuid':self.policy['targets']['example-company:staging']['site_uuid'],
            'git_commit':self.sha,'acceptance_checks':['wp_boot'],
            'files':[{'source':f,'target':f,'expected_before_sha256':self.preimages[f]}
                     for f in self.files]}

    def test_component_stays_inside_existing_catalog(self):
        catalog=json.loads(CATALOG.read_text())
        self.assertEqual(catalog['contract'],'mad4b.company-release-components.v1')
        found=[c for c in catalog['components'] if c['id']==COMPONENT]
        self.assertEqual(len(found),1)
        self.assertEqual(found[0]['files'],self.files)
        self.assertTrue(all(core.permitted_target(f) for f in self.files))

    def test_git_bound_release_plan_and_executor_preimage(self):
        plan=core.make_plan(self.spec,self.policy)
        self.assertEqual(len(plan['files']),2)
        self.assertEqual(plan['changed_count'],2)
        self.assertEqual(plan['git_commit'],self.sha)
        self.assertEqual([r['target'] for r in plan['files']],self.files)
        self.assertEqual([r['expected_before_sha256'] for r in plan['files']],
                         [self.preimages[f] for f in self.files])
        core.confirm_plan(plan,self.policy,expect_preimage=True)
        self.assertTrue(all(r['source_sha256']!=r['expected_before_sha256']
                            for r in plan['files']))
        with self.assertRaises(core.Denied):
            core.confirm_plan(plan,self.policy,expect_preimage=False)

    def test_denied_without_registered_release_catalog(self):
        denied=json.loads(json.dumps(self.policy))
        denied['targets']['example-company:staging'].pop('release_catalog')
        with self.assertRaises(core.Denied):core.make_plan(self.spec,denied)

    def test_denied_with_unexpected_preimage(self):
        s=json.loads(json.dumps(self.spec))
        s['files'][0]['expected_before_sha256']='0'*64
        with self.assertRaises(core.Denied):core.make_plan(s,self.policy)

    def test_denied_with_unregistered_different_target(self):
        s=json.loads(json.dumps(self.spec))
        s['files'][0]['target']='wp-content/mu-plugins/random-plugin.php'
        with self.assertRaises(core.Denied):core.make_plan(s,self.policy)

    def test_denied_with_different_source_for_catalog_target(self):
        s=json.loads(json.dumps(self.spec))
        s['files'][0]['source']=self.files[1]
        with self.assertRaises(core.Denied):core.make_plan(s,self.policy)

    def test_denied_if_company_environment_binding_changes(self):
        s=json.loads(json.dumps(self.spec))
        s['environment']='production'
        with self.assertRaises(core.Denied):core.make_plan(s,self.policy)

    def test_denied_if_live_target_changes_after_plan(self):
        plan=core.make_plan(self.spec,self.policy)
        (self.site/self.files[0]).write_bytes(b'<?php /* changed meanwhile */\n')
        with self.assertRaises(core.Denied):
            core.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_no_approval_in_plan(self):
        p=core.make_plan(self.spec,self.policy)
        self.assertNotIn('signature',p)
        self.assertNotIn('grant',p)
        self.assertNotIn('original_313',p)
        self.assertEqual(p['acceptance_checks'],['wp_boot'])


if __name__=='__main__':unittest.main()
