"""Hermetic tests for immutable dynamic MAD4B release catalog."""
import json
import unittest
from pathlib import Path
from test_release import CodeReleaseTests, release, shell

class CatalogTests(unittest.TestCase):
    def setUp(self):
        CodeReleaseTests.setUp(self)
        self.dynamic='wp-content/mu-plugins/mad4b-composite-new.php'
        self.catalog='ops/company-code-release-v1/catalog/mad4b-release-components.json'
        (self.repo/'ops/company-code-release-v1/catalog').mkdir(parents=True)
        (self.repo/self.dynamic).parent.mkdir(parents=True,exist_ok=True)
        (self.repo/self.dynamic).write_text('<?php echo "catalogued";\n')
        self.manifest={'contract':'mad4b.company-release-components.v1','components':[{'id':'mad4b-composite-media','files':[self.dynamic]}]}
        self.save_commit()
        self.policy['targets']['demo:staging']['release_catalog']=self.catalog
        self.request['git_commit']=self.commit
        self.request['files']=[{'source':self.dynamic,'target':self.dynamic,'expected_before_sha256':None}]

    def tearDown(self):
        CodeReleaseTests.tearDown(self)

    def save_commit(self):
        (self.repo/self.catalog).write_text(json.dumps(self.manifest))
        shell('git','-C',str(self.repo),'add','.')
        shell('git','-C',str(self.repo),'-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm','dynamic catalog')
        self.commit=shell('git','-C',str(self.repo),'rev-parse','HEAD')

    def test_legacy_static_plan_survives_catalog_enrollment(self):
        static=self.policy['targets']['demo:staging']
        static.pop('release_catalog')
        older=json.loads(json.dumps(self.request))
        older['files']=[{'source':'ops/artifacts/approved.php',
                         'target':'wp-content/mu-plugins/mad4b-approved.php',
                         'expected_before_sha256':release.digest(self.target.read_bytes())}]
        previous=release.make_plan(older,self.policy)
        # Simulate a plan issued by an older release executor with no catalog key.
        previous.pop('release_catalog',None)
        previous.pop('plan_sha256',None)
        previous['plan_sha256']=release.digest(release.canonical({
            k:v for k,v in previous.items() if k not in ('changed_count','plan_sha256')}))
        static['release_catalog']=self.catalog
        release.confirm_plan(previous,self.policy,expect_preimage=True)
        self.assertEqual(previous['files'][0]['target'],older['files'][0]['target'])

    def test_catalogued_new_component_plan(self):
        plan=release.make_plan(self.request,self.policy)
        self.assertEqual(plan['files'][0]['target'],self.dynamic)
        self.assertEqual(plan['release_catalog'],self.catalog)
        release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_no_catalog_opt_in_rejected(self):
        self.policy['targets']['demo:staging'].pop('release_catalog')
        with self.assertRaisesRegex(release.Denied,'TARGET_FILE_NOT_ALLOWED'):
            release.make_plan(self.request,self.policy)

    def test_unregistered_target_rejected(self):
        self.request['files'][0]['target']='wp-content/mu-plugins/mad4b-surprise.php'
        with self.assertRaisesRegex(release.Denied,'TARGET_FILE_NOT_ALLOWED'):
            release.make_plan(self.request,self.policy)

    def test_catalog_cannot_register_arbitrary_plugin(self):
        self.manifest['components'][0]['files']=['wp-content/plugins/evil/file.php']
        self.save_commit();self.request['git_commit']=self.commit
        with self.assertRaisesRegex(release.Denied,'RELEASE_COMPONENT_SCOPE_INVALID'):
            release.make_plan(self.request,self.policy)

    def test_policy_catalog_binding_drift_rejected(self):
        plan=release.make_plan(self.request,self.policy)
        self.policy['targets']['demo:staging'].pop('release_catalog')
        with self.assertRaisesRegex(release.Denied,'RELEASE_CATALOG_BINDING_DRIFT'):
            release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_newer_commit_does_not_modify_existing_plan(self):
        plan=release.make_plan(self.request,self.policy)
        self.manifest['components'][0]['files']=[]
        self.save_commit()
        release.confirm_plan(plan,self.policy,expect_preimage=True)

if __name__=='__main__': unittest.main()
