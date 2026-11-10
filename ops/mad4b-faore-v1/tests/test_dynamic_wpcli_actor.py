"""Host WP-CLI actor: enrolled scope, runtime identity, and input preservation."""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
sys.path.insert(0,str(Path(__file__).resolve().parents[1] / "integrations"))
import wpcli_dynamic_actor as actor

SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
ORIGIN="https://tours.dev.mad4b.com"
TARGET={"container":"php-fresh-discovery","site_uuid":SITE,
        "canonical_origin":ORIGIN,"environment":"staging"}
BASE=["/usr/bin/docker","exec","-u","www-data",actor.TARGET_MARKER,
      "/usr/local/bin/wp","--path=/var/www/html","--skip-themes",
      actor.MARKER,"mad4b","broker","pending","--limit=10"]


def result(valid=True,**kwargs):
    data=dict(valid=valid,actor_user_id=17,site_uuid=SITE,
              canonical_origin=ORIGIN,environment="staging",
              owner_stepup=False,write_authorized=False,grant_issued=False)
    data.update(kwargs)
    return SimpleNamespace(returncode=0,stdout=json.dumps(data),stderr="")


class ActorTests(unittest.TestCase):
    def fake(self,**changes):
        calls=[]
        def runner(argv,**kwargs):
            calls.append(argv)
            return result(**changes)
        return runner,calls

    def test_enrolled_target_matches_root_policy(self):
        v=actor.enrolled_target(discover=lambda env:TARGET,
                                scope_loader=lambda:dict(TARGET))
        self.assertEqual(v,TARGET)

    def test_mismatched_scope_refused(self):
        for name in ('site_uuid','canonical_origin','environment'):
            with self.subTest(name=name):
                v=dict(TARGET)
                v[name]='mismatch'
                with self.assertRaisesRegex(RuntimeError,'HOST_SCOPE_SITE_PROFILE'):
                    actor.enrolled_target(
                        discover=lambda env:v,
                        scope_loader=lambda:dict(TARGET))

    def test_actor_resolves_on_new_container_name(self):
        runner,calls=self.fake()
        value=actor.resolve_actor(runner=runner,target_resolver=lambda:TARGET)
        self.assertEqual(value,17)
        self.assertEqual(calls[0][4],TARGET['container'])
        self.assertNotIn('etg-commerce-staging-php',repr(calls[0]))

    def test_actor_refuses_false_claimed_write(self):
        for key,value in [('write_authorized',True),('grant_issued',True),
                          ('owner_stepup',True),('valid',False),
                          ('canonical_origin','https://foreign.example'),
                          ('site_uuid','foreign')]:
            with self.subTest(key=key):
                runner,_=self.fake(**{key:value})
                with self.assertRaisesRegex(RuntimeError,'UNVERIFIED'):
                    actor.resolve_actor(runner=runner,target_resolver=lambda:TARGET)

    def test_marker_rebinds_only_to_enrolled_runtime(self):
        runner,_=self.fake()
        output=actor.with_actor(BASE,runner,target_resolver=lambda:TARGET)
        self.assertEqual(output[4],TARGET['container'])
        self.assertIn('--user=17',output)
        self.assertNotIn(actor.MARKER,output)
        self.assertEqual(output[-3:],['broker','pending','--limit=10'])
        self.assertEqual(BASE[4],actor.TARGET_MARKER)

    def test_wrong_container_does_not_impersonate(self):
        runner,calls=self.fake()
        with self.assertRaisesRegex(RuntimeError,'CONTAINER_MISMATCH'):
            actor.with_actor(BASE[:4]+['external-container']+BASE[5:],
                             runner,target_resolver=lambda:TARGET)
        self.assertEqual(calls,[])

    def test_duplicate_markers_denied(self):
        runner,calls=self.fake()
        with self.assertRaisesRegex(RuntimeError,'AMBIGUOUS'):
            actor.with_actor(BASE+[actor.MARKER],runner,
                             target_resolver=lambda:TARGET)
        self.assertEqual(calls,[])

    def test_explicit_wordpress_user_denied(self):
        runner,calls=self.fake()
        with self.assertRaisesRegex(RuntimeError,'AMBIGUOUS'):
            actor.with_actor(BASE+['--user=1'],runner,
                             target_resolver=lambda:TARGET)
        self.assertEqual(calls,[])

    def test_malformed_wordpress_invocation_denied(self):
        runner,calls=self.fake()
        v=list(BASE);v[5]='/bin/sh'
        with self.assertRaisesRegex(RuntimeError,'COMMAND_INVALID'):
            actor.with_actor(v,runner,target_resolver=lambda:TARGET)
        self.assertEqual(calls,[])

    def test_root_policy_file_security(self):
        with tempfile.TemporaryDirectory() as temp:
            policy=Path(temp)/'policy.json'
            policy.write_text('{}')
            policy.chmod(0o644)
            with self.assertRaisesRegex(RuntimeError,'ROOT_OWNERSHIP'):
                actor.root_scope(policy)

    def test_no_marker_keeps_other_argv_unchanged(self):
        runner,calls=self.fake()
        cmd=['docker','ps']
        self.assertIs(actor.with_actor(cmd,runner),cmd)
        self.assertEqual(calls,[])


if __name__=="__main__":
    unittest.main()
