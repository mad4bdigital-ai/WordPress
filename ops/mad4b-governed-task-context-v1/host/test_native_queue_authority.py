#!/usr/bin/env python3
"""No-network host authority admission regression. Does not execute any Queue Job."""
import json
import pathlib
import subprocess
import sys
import unittest
from types import SimpleNamespace

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from native_queue_authority import probe

POLICY = {'revoked': False, 'scopes': {
    'tours-central:staging': {'allow_operator': True, 'allow_mutation': True,
        'canonical_origin': 'https://tours.dev.mad4b.com', 'wordpress_actor_user_id': 7}
}}
ID = {
    'authenticated_via': 'verified_wordpress_wpcli',
    'chatgpt_oauth_verified': False,
    'site_origin': 'https://tours.dev.mad4b.com',
    'site_environment': 'staging',
    'actor_wp_user_id': 7,
    'ability': 'mad4b/hub-bulk-list-queue',
}
QUEUE = {
    'contract': 'mad4b.hub.bulk-vps-runner.v1',
    'registered_executor': 'vps_runner',
    'wordpress_queue_execution_allowed': True,
    'queue_authorization_source': 'verified_wpcli_actor_and_native_queue_policy',
    'claim_scope': 'vps_runner_assigned_tasks_only',
    'mcp_client_task_claim_allowed': False,
}

def fake_runner(identity=None, queue=None, first_exit=0, corrupt=False, expected_actor=7):
    values = [dict(ID if identity is None else identity),
              dict(QUEUE if queue is None else queue)]
    called = []
    def run(argv, **kwargs):
        assert argv[:6] == ['/usr/bin/docker','exec','-u','www-data',
                            'etg-commerce-staging-php','wp']
        assert '--user=' + str(expected_actor) in argv
        assert '--path=/var/www/html' in argv
        assert kwargs.get('check') is False
        assert kwargs.get('timeout') == 15
        called.append(argv[-2:])
        answer = values[len(called)-1]
        return SimpleNamespace(returncode=first_exit if len(called)==1 else 0,
                               stdout='broken' if corrupt else json.dumps(answer))
    return run, called

class NativeWordpressQueueAuthorityTest(unittest.TestCase):
    def test_verified_vps_lane_is_admitted_as_diagnostic_only(self):
        runner, calls = fake_runner()
        state = probe(POLICY, runner=runner)
        self.assertTrue(state['wordpress_queue_execution_allowed'])
        self.assertFalse(state['mcp_client_claim_allowed'])
        self.assertFalse(state['execution_performed'])
        self.assertEqual(state['state'], 'VERIFIED_VPS_ASSIGNED_QUEUE_LANE')
        self.assertEqual(calls, [['fallback','queue-list'],['bulk','status']])

    def test_revoked_host_denied_before_wordpress(self):
        runner, calls = fake_runner()
        state = probe(dict(POLICY, revoked=True), runner=runner)
        self.assertEqual(state['state'], 'HOST_SCOPE_NOT_GRANTED')
        self.assertFalse(calls)

    def test_unenrolled_tenant_denied_before_wordpress(self):
        runner, calls = fake_runner()
        state = probe(POLICY, company='unknown', runner=runner)
        self.assertEqual(state['state'], 'HOST_SCOPE_NOT_GRANTED')
        self.assertFalse(calls)

    def test_unenrolled_environment_fail_closed(self):
        p = {'scopes': {'tours-central:production': {
            'allow_operator': True, 'allow_mutation': True}}}
        state = probe(p, environment='production', runner=fake_runner()[0])
        self.assertEqual(state['state'], 'WORDPRESS_TARGET_PRINCIPAL_NOT_ENROLLED')

    def test_no_host_mutation_scope(self):
        p = {'scopes': {'tours-central:staging': {
            'allow_operator': True, 'allow_mutation': False}}}
        self.assertEqual(probe(p,runner=fake_runner()[0])['state'],
                         'HOST_SCOPE_NOT_GRANTED')

    def test_wrong_origin_denied(self):
        identity = dict(ID, site_origin='https://another.example')
        self.assertEqual(probe(POLICY,runner=fake_runner(identity=identity)[0])['state'],
                         'WORDPRESS_PRINCIPAL_OR_ORIGIN_MISMATCH')

    def test_wrong_user_denied(self):
        identity = dict(ID, actor_wp_user_id=2)
        self.assertEqual(probe(POLICY,runner=fake_runner(identity=identity)[0])['state'],
                         'WORDPRESS_PRINCIPAL_OR_ORIGIN_MISMATCH')

    def test_site_specific_actor_not_one_is_verified(self):
        runner, calls = fake_runner(expected_actor=7)
        state = probe(POLICY, runner=runner)
        self.assertTrue(state['wordpress_queue_execution_allowed'])
        self.assertEqual(calls, [['fallback','queue-list'], ['bulk','status']])

    def test_second_enrolled_actor_uses_distinct_site_id(self):
        local = {'scopes': {'tours-central:staging': dict(
            POLICY['scopes']['tours-central:staging'],
            wordpress_actor_user_id=43)}}
        identity = dict(ID, actor_wp_user_id=43)
        runner, _ = fake_runner(identity=identity, expected_actor=43)
        state = probe(local, runner=runner)
        self.assertTrue(state['wordpress_queue_execution_allowed'])

    def test_actor_missing_from_policy_denied_without_wpcli(self):
        policy = {'scopes': {'tours-central:staging': {
            'allow_operator': True, 'allow_mutation': True,
            'canonical_origin': 'https://tours.dev.mad4b.com'}}}
        runner, calls = fake_runner()
        state = probe(policy, runner=runner)
        self.assertEqual(state['state'], 'WORDPRESS_TARGET_PRINCIPAL_NOT_ENROLLED')
        self.assertEqual(calls, [])

    def test_invalid_actor_ids_denied(self):
        for bad in (0, -1, '1', True, 2147483648):
            policy = {'scopes': {'tours-central:staging': dict(
                POLICY['scopes']['tours-central:staging'],
                wordpress_actor_user_id=bad)}}
            runner, calls = fake_runner()
            state = probe(policy, runner=runner)
            self.assertEqual(state['state'], 'WORDPRESS_TARGET_PRINCIPAL_NOT_ENROLLED')
            self.assertFalse(calls)

    def test_policy_origin_mismatch_denied(self):
        policy = {'scopes': {'tours-central:staging': dict(
            POLICY['scopes']['tours-central:staging'],
            canonical_origin='https://different.example')}}
        runner, calls = fake_runner()
        state = probe(policy, runner=runner)
        self.assertEqual(state['state'], 'WORDPRESS_TARGET_ORIGIN_NOT_ENROLLED')
        self.assertFalse(calls)

    def test_oauth_impersonation_denied(self):
        identity = dict(ID, chatgpt_oauth_verified=True)
        self.assertEqual(probe(POLICY,runner=fake_runner(identity=identity)[0])['state'],
                         'WORDPRESS_PRINCIPAL_OR_ORIGIN_MISMATCH')

    def test_invalid_ability_denied(self):
        identity = dict(ID, ability='mad4b/hub-bulk-list_queue')
        self.assertEqual(probe(POLICY,runner=fake_runner(identity=identity)[0])['state'],
                         'WORDPRESS_PRINCIPAL_OR_ORIGIN_MISMATCH')

    def test_queue_claim_lane_mismatch_denied(self):
        queue = dict(QUEUE, claim_scope='mcp_client')
        self.assertEqual(probe(POLICY,runner=fake_runner(queue=queue)[0])['state'],
                         'WORDPRESS_QUEUE_RUNNER_NOT_AUTHORIZED')

    def test_static_false_diagnostic_denied(self):
        queue = dict(QUEUE, wordpress_queue_execution_allowed=False)
        self.assertEqual(probe(POLICY,runner=fake_runner(queue=queue)[0])['state'],
                         'WORDPRESS_QUEUE_RUNNER_NOT_AUTHORIZED')

    def test_probe_unavailable_on_command_failure(self):
        self.assertEqual(probe(POLICY,runner=fake_runner(first_exit=1)[0])['state'],
                         'WORDPRESS_QUEUE_PROBE_FAILED')

    def test_corrupt_response_fail_closed(self):
        self.assertEqual(probe(POLICY,runner=fake_runner(corrupt=True)[0])['state'],
                         'WORDPRESS_QUEUE_PROBE_UNAVAILABLE')

    def test_timeout_fail_closed(self):
        def raise_timeout(*_args, **_kwargs):
            raise subprocess.TimeoutExpired('wp', 15)
        self.assertEqual(probe(POLICY,runner=raise_timeout)['state'],
                         'WORDPRESS_QUEUE_PROBE_UNAVAILABLE')

if __name__ == '__main__':
    unittest.main()
