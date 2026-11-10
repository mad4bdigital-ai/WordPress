"""FAORE dynamic central selection, observer and broker regression contracts."""
import json
import sys
import unittest
from unittest.mock import patch
from types import SimpleNamespace
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import stdio_scope
import stdio_routing
import stdio_probe_auto
import broker_313_readiness

SITE='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
ORIGIN='https://tours.dev.mad4b.com'


def profile(environment='staging', origin=ORIGIN, valid=True):
    return dict(contract='mad4b.site-profile.v2', company_manager_loaded=True, site_uuid=SITE,
                canonical_origin=origin, siteurl=origin, environment=environment,
                configured=valid, origin_match=True,
                environment_match=True, deployment_binding_match=True)


def fake_inventory(names, rows, inactive=()):
    def runner(args, **kwargs):
        if args[:2] == ['docker', 'ps']:
            return '\n'.join(names)
        container=args[4]
        if args[-2] == 'eval':
            return json.dumps(dict(rows[container],
                                   company_manager_loaded=container not in inactive))
        raise AssertionError(args)
    return runner


class ScopeTests(unittest.TestCase):
    def test_unique_staging_runtime(self):
        runner=fake_inventory(['php-prod','php-stage'],{
            'php-prod':profile('production'),'php-stage':profile()})
        self.assertEqual(stdio_scope.central_target(runner=runner)['container'],'php-stage')

    def test_duplicate_is_refused(self):
        runner=fake_inventory(['php-first','php-second'],{
            'php-first':profile(),'php-second':profile()})
        with self.assertRaisesRegex(RuntimeError,'AMBIGUOUS'):
            stdio_scope.central_target(runner=runner)

    def test_inactive_manager_is_refused(self):
        runner=fake_inventory(['php-foreign'],{'php-foreign':profile()},
                              inactive={'php-foreign'})
        with self.assertRaisesRegex(RuntimeError,'NOT_VERIFIED'):
            stdio_scope.central_target(runner=runner)

    def test_invalid_site_profile_is_refused(self):
        for value in [profile('production'),profile(valid=False),
                      profile(origin='http://example.com'),
                      dict(profile(),site_uuid='not-a-uuid'),
                      dict(profile(),deployment_binding_match=False)]:
            with self.subTest(value=value):
                runner=fake_inventory(['php-test'],{'php-test':value})
                with self.assertRaisesRegex(RuntimeError,'NOT_VERIFIED'):
                    stdio_scope.central_target(runner=runner)

    def test_unknown_environment_is_refused(self):
        with self.assertRaises(ValueError):
            stdio_scope.central_target('other')

    def test_probe_only_calls_existing_canary_and_does_not_grant_write(self):
        calls=[]
        def execute(uuid,origin,environment,*,target):
            calls.append((uuid,origin,environment,target(uuid,origin,environment)))
            return {'state':'READ_PROBE_RECORDED','authorization_granted':False}
        result=stdio_probe_auto.run_dynamic(
            discover=lambda env: {'site_uuid':SITE,'canonical_origin':ORIGIN,
                                  'environment':env,'container':'php-stage'},
            execute=execute)
        self.assertEqual(calls,[(SITE,ORIGIN,'staging','php-stage')])
        self.assertFalse(result['authorization_granted'])
        self.assertFalse(result['original_313_verified'])

    def test_probe_uses_exact_scope(self):
        captured=[]
        def execute(uuid,origin,environment,*,target):
            with self.assertRaisesRegex(RuntimeError,'SCOPE_CHANGED'):
                target('foreign-site',origin,environment)
            captured.append(True)
            return {'state':'READ_PROBE_RECORDED'}
        stdio_probe_auto.run_dynamic(
            discover=lambda env: {'site_uuid':SITE,'canonical_origin':ORIGIN,
                                  'environment':env,'container':'php-stage'},
            execute=execute)
        self.assertTrue(captured)


    def test_central_busy_is_not_missing_identity(self):
        def runner(args, **kwargs):
            if args[:2] == ['docker', 'ps']:
                return 'php-live'
            raise RuntimeError('CENTRAL_WORDPRESS_BUSY')
        with self.assertRaisesRegex(RuntimeError,'CENTRAL_WORDPRESS_BUSY'):
            stdio_scope.central_target(runner=runner)

    def test_probe_retries_one_recognized_read_slot_busy(self):
        attempts=[]
        def discover(environment):
            attempts.append(environment)
            if len(attempts)==1:
                raise RuntimeError('CENTRAL_WORDPRESS_BUSY')
            return {'site_uuid':SITE,'canonical_origin':ORIGIN,
                    'environment':'staging','container':'php-live'}
        def execute(uuid,origin,environment,*,target):
            return {'state':'READ_PROBE_RECORDED','authorization_granted':False}
        sleeps=[]
        result=stdio_probe_auto.run_dynamic(discover=discover,execute=execute,
                                             sleeper=sleeps.append)
        self.assertEqual(sleeps,[3])
        self.assertEqual(attempts,['staging','staging'])
        self.assertEqual(result['state'],'READ_PROBE_RECORDED')

    def test_probe_nonbusy_error_not_retried(self):
        calls=[]
        def discover(environment):
            calls.append(environment)
            raise RuntimeError('CENTRAL_RUNTIME_AMBIGUOUS')
        with self.assertRaisesRegex(RuntimeError,'AMBIGUOUS'):
            stdio_probe_auto.run_dynamic(discover=discover,sleeper=lambda n:None)
        self.assertEqual(len(calls),1)

    def test_probe_busy_max_two_attempts(self):
        calls=[]
        def discover(environment):
            calls.append(environment)
            raise RuntimeError('MCP_STDIO_READ_SLOT_BUSY')
        with self.assertRaisesRegex(RuntimeError,'MCP_STDIO_READ_SLOT_BUSY'):
            stdio_probe_auto.run_dynamic(discover=discover,sleeper=lambda n:None)
        self.assertEqual(len(calls),2)


    def test_transport_busy_distinguished_from_other_errors(self):
        failure=SimpleNamespace(returncode=1,stdout='',stderr='STAGING_WPCLI_BUSY: slots full')
        with patch.object(stdio_routing.subprocess,'run',return_value=failure):
            with self.assertRaisesRegex(RuntimeError,'MCP_STDIO_READ_SLOT_BUSY'):
                stdio_routing.run(['wp','mcp-adapter','list'])

    def test_host_scope_busy_distinguished_from_missing_site(self):
        failure=SimpleNamespace(returncode=1,stdout='',stderr='STAGING_WPCLI_BUSY: slots full')
        with patch.object(stdio_scope.subprocess,'run',return_value=failure):
            with self.assertRaisesRegex(RuntimeError,'CENTRAL_WORDPRESS_BUSY'):
                stdio_scope.run(['docker','ps'])


class BrokerTests(unittest.TestCase):
    def fake(self, count=0, claimed=0, correct=True):
        calls=[]
        def runner(args,**kwargs):
            calls.append(args)
            if args[-3:]==['broker','pending','--limit=10']:
                return json.dumps({'contract':'mad4b.central-vps-broker.v1'
                                   if correct else 'wrong','pending':[{}]*count})
            if args[-3:]==['broker-authority','claimed','--limit=10']:
                return json.dumps({'contract':'mad4b.broker-exact-ssh-authority.v1',
                                   'claimed':[{}]*claimed})
            raise AssertionError(args)
        return runner,calls

    def test_pending_and_claimed_not_313(self):
        runner,calls=self.fake(2,1)
        output=broker_313_readiness.inspect(
            discover=lambda env:{'site_uuid':SITE,'container':'php-stage'},
            actor_resolver=lambda:7,runner=runner)
        self.assertEqual(output['pending_original_306'],2)
        self.assertEqual(output['claimed_original_312'],1)
        self.assertFalse(output['original_313_verified'])
        self.assertFalse(output['mutation_performed'])
        self.assertFalse(output['new_jobs_created'])
        self.assertEqual(len(calls),2)

    def test_invalid_broker_contract_is_refused(self):
        runner,_=self.fake(correct=False)
        with self.assertRaisesRegex(RuntimeError,'CONTRACT_UNVERIFIED'):
            broker_313_readiness.inspect(
                discover=lambda env:{'site_uuid':SITE,'container':'php-stage'},
                actor_resolver=lambda:7,runner=runner)

    def test_invalid_actor_prevents_queue_access(self):
        runner,calls=self.fake()
        with self.assertRaises(ValueError):
            broker_313_readiness.inspect(
                discover=lambda env:{'site_uuid':SITE,'container':'php-stage'},
                actor_resolver=lambda:0,runner=runner)
        self.assertEqual(calls,[])
