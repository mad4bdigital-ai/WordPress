#!/usr/bin/env python3
"""Source-only regression for the central Staging dynamic WordPress diagnostic actor."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / 'wpcli_dynamic_actor.py'
PATCH = ROOT / 'staging-runtime.patch'
spec = importlib.util.spec_from_file_location('mad4b_wpcli_dynamic_actor_tested', FILE)
actor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(actor)


class DynamicActorTests(unittest.TestCase):
    def runner(self, response, status=0):
        def run(args, **kw):
            self.assertIn('eval', args)
            self.assertNotIn('--user=1', args)
            self.assertNotIn('--user=7', args)
            self.assertIn('--url=https://tours.dev.mad4b.com', args)
            self.assertEqual(kw['timeout'], 40)
            return subprocess.CompletedProcess(args, status, json.dumps(response), '')
        return run

    @staticmethod
    def evidence(actor_id=7, **changes):
        data = {'valid': True, 'actor_user_id': actor_id,
                'owner_stepup': False, 'write_authorized': False,
                'grant_issued': False}
        data.update(changes)
        return data

    def test_authorized_identity_is_dynamic_not_wp_user_one(self):
        base = ['/usr/bin/docker', 'exec', '-u', 'www-data',
                'etg-commerce-staging-php', '/usr/local/bin/wp',
                '--path=/var/www/html', '--skip-themes', actor.MARKER,
                'mad4b-v4-scope', 'verify', 'all-royal-egypt:staging']
        result = actor.with_actor(base, runner=self.runner(self.evidence(7)))
        self.assertIn('--user=7', result)
        self.assertNotIn(actor.MARKER, result)
        self.assertNotIn('--user=1', result)

    def test_bare_read_never_fabricates_wordpress_user(self):
        command = ['docker', 'exec', '-u', 'www-data',
                   'etg-commerce-staging-php', 'wp', 'eval',
                   "echo wp_get_environment_type();"]
        self.assertIs(actor.with_actor(command), command)

    def test_no_identity_from_wrong_host(self):
        with self.assertRaisesRegex(RuntimeError, 'CONTAINER_MISMATCH'):
            actor.with_actor(['ssh', 'external-host', actor.MARKER])

    def test_no_mixed_fixed_or_duplicate_user(self):
        command = ['/usr/bin/docker', 'exec', '-u', 'www-data',
                   'etg-commerce-staging-php', 'wp', actor.MARKER, '--user=99']
        with self.assertRaisesRegex(RuntimeError, 'AMBIGUOUS'):
            actor.with_actor(command)
        command.remove('--user=99')
        command.append(actor.MARKER)
        with self.assertRaisesRegex(RuntimeError, 'AMBIGUOUS'):
            actor.with_actor(command)

    def test_invalid_evidence_fails_closed(self):
        for value in (
            self.evidence(0), self.evidence(True),
            self.evidence(7, valid=False),
            self.evidence(7, owner_stepup=True),
            self.evidence(7, write_authorized=True),
            self.evidence(7, grant_issued=True),
        ):
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                actor.resolve_actor(runner=self.runner(value))

    def test_failed_wordpress_readback_fails_closed(self):
        with self.assertRaises(RuntimeError):
            actor.resolve_actor(runner=self.runner(self.evidence(7), status=1))

    def test_runtime_patch_covers_exact_active_filenames(self):
        patch = PATCH.read_text()
        expected = [
            'broker-v1/remote-exact-canary.py',
            'broker-v1/vps-broker-poller.py',
            'broker-v1/vps-broker-registry-ssh-status-sync.py',
            'broker-v1/vps-broker-journal-sync.py',
            'broker-v1/native-executor-enrollment-preflight.py',
            'broker-v1/broker-native-ssh-reconcile.py',
            'broker-v1/vps-broker-remote-ssh-canary.py',
            'broker-v1/vps-broker-fixed-diagnostic.py',
            'universal-runtime-v1/resolver.py',
            'universal-runtime-v1/central_control_readback.py',
            'universal-runtime-v1/multi_transport_authority.py',
        ]
        self.assertEqual(patch.count('\n--- a/'), len(expected) - 1)
        self.assertEqual(patch.count('--- a/'), len(expected))
        for path in expected:
            self.assertIn('--- a/' + path, patch)
            self.assertIn('+++ b/' + path, patch)
        self.assertEqual(patch.count('+' + 'from wpcli_dynamic_actor import with_actor'), 10)
        self.assertNotIn('+' + '--user=1', patch)
        self.assertIn('ARGS=["--path=/var/www/html","--skip-themes"]', patch)


if __name__ == '__main__':
    unittest.main()
