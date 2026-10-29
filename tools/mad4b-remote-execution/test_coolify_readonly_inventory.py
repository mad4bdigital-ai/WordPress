import io
import json
import subprocess
import sys
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import coolify_readonly_inventory as ci

class TestCoolifyReadOnly(unittest.TestCase):
    def test_rejects_mutation_command(self):
        with self.assertRaises(ValueError):
            ci.cmd(['coolify','deploy','name','production'])
        with self.assertRaises(ValueError):
            ci.cmd(['coolify','app','env','list'])

    def test_only_passes_list_json_format(self):
        p = subprocess.CompletedProcess([], 0, '[{"uuid":"abc","name":"Staging"}]', '')
        with patch('subprocess.run', return_value=p) as called:
            r=ci.cmd(['coolify','app','list'])
            self.assertTrue(r['ok'])
            self.assertEqual(called.call_args.args[0],
                             ['coolify','app','list','--format','json'])
        with patch('subprocess.run', return_value=p) as called:
            ci.cmd(['coolify','context','verify'])
            self.assertEqual(called.call_args.args[0], ['coolify','context','verify'])

    def test_fail_closed_no_cli(self):
        with patch.object(sys,'argv',['run','--host','tours.dev.mad4b.com']), \
             patch('shutil.which',return_value=None), redirect_stdout(io.StringIO()) as output:
            exit_code=ci.main()
        report=json.loads(output.getvalue())
        self.assertEqual(exit_code,2)
        self.assertFalse(report['shared_media_activation_performed'])
        self.assertIn('COOLIFY_CLI_NOT_INSTALLED_OR_NOT_IN_PATH',report['blockers'])

    def test_registered_domains_exact_not_substring(self):
        rows=ci.resources_from_json(json.dumps({
            'data':[{'uuid':'abc123','name':'Staging',
                     'fqdn':'https://tours.dev.mad4b.com,https://other.company.example',
                     'status':'running'}]}))
        m=ci.matched_hosts(rows,['tours.dev.mad4b.com','dev.mad4b.com','other.company.example'])
        self.assertEqual(m[0]['matching_resource_uuids'], ['abc123'])
        self.assertEqual(m[1]['registration'],'NOT_CONFIRMED')
        self.assertEqual(m[2]['registration'],'FOUND')

    def test_never_reports_runtime_activated(self):
        mockdata=json.dumps({'data':[{'uuid':'test-uuid','name':'STAGING',
                                      'domains':'https://tours.dev.mad4b.com'}]})
        def fake_cmd(argv, *, timeout=ci.CLI_TIMEOUT, context=''):
            return {'ok':True,'exit':0,'raw': mockdata}
        with patch.object(sys,'argv',['run','--host','tours.dev.mad4b.com']), \
             patch('shutil.which', return_value='/usr/bin/coolify'), \
             patch.object(ci,'cmd',side_effect=fake_cmd), \
             redirect_stdout(io.StringIO()) as output:
            code=ci.main()
        data=json.loads(output.getvalue())
        self.assertEqual(code,0)
        self.assertEqual(data['status'],'INVENTORY_ONLY')
        self.assertFalse(data['shared_media_activation_performed'])
        self.assertFalse(data['production_mutation'])

    def test_no_secrets_in_resource_projection(self):
        rows=ci.resources_from_json(json.dumps({'data':[{'uuid':'x','name':'stage',
            'api_token':'SHOULD_NOT_SHOW', 'password':'SHOULD_NOT_SHOW',
            'environment_variables':{'SECRET':'SHOULD_NOT_SHOW'}}]}))
        allowed={k:v for k,v in rows[0].items() if not k.startswith('_')}
        self.assertEqual(allowed,{'uuid':'x','name':'stage'})

    def test_error_does_not_leak_stderr(self):
        p=subprocess.CompletedProcess([],1,'','Bearer SECRET DO NOT PRINT')
        with patch('subprocess.run', return_value=p):
            out=ci.cmd(['coolify','service','list'])
        self.assertFalse(out['ok'])
        self.assertEqual(out['raw'],'')


    def test_explicit_authorized_context_precedes_command(self):
        p = subprocess.CompletedProcess([], 0, '[{"uuid":"abc"}]', '')
        with patch('subprocess.run', return_value=p) as called:
            ci.cmd(['coolify','app','list'], context='mad4b-production')
            self.assertEqual(
                called.call_args.args[0],
                ['coolify','--context','mad4b-production','app','list','--format','json'])
        with patch('subprocess.run', return_value=p) as called:
            ci.cmd(['coolify','context','verify'], context='mad4b-production')
            self.assertEqual(
                called.call_args.args[0],
                ['coolify','--context','mad4b-production','context','verify'])

    def test_rejects_context_flag_injection(self):
        for bad in ('--token', 'two words', 'bad/newline', '-x'):
            with self.assertRaises(ValueError):
                ci.cmd(['coolify','resource','list'], context=bad)

    def test_real_cli_list_without_domains_stays_blocked(self):
        # Coolify 1.10.0 resource/app list may omit domain metadata entirely.
        # Missing host match must never be mistaken for absent Docker workload.
        listing = json.dumps([{'uuid':'etg-staging','name':'ETG Staging'}])
        def fake_cmd(argv, *, timeout=ci.CLI_TIMEOUT, context=''):
            return {'ok':True,'exit':0,'raw':listing}
        with patch.object(sys,'argv',['run','--context','mad4b-production',
                                      '--host','tours.dev.mad4b.com']), \
             patch('shutil.which', return_value='/usr/local/bin/coolify'), \
             patch.object(ci,'cmd',side_effect=fake_cmd), \
             redirect_stdout(io.StringIO()) as output:
            code=ci.main()
        report=json.loads(output.getvalue())
        self.assertEqual(code,2)
        self.assertEqual(report['coolify_context'],'mad4b-production')
        self.assertFalse(report['domain_metadata_available'])
        self.assertIn('COOLIFY_LIST_API_OMITS_DOMAINS',report['blockers'])
        self.assertFalse(report['shared_media_activation_performed'])


    def test_server_hostname_does_not_certify_tenant_domain(self):
        def fake_cmd(argv, *, timeout=ci.CLI_TIMEOUT, context=''):
            if argv[1] == 'server':
                records = [{'uuid':'vps-1','name':'VPS',
                            'fqdn':'https://tours.dev.mad4b.com'}]
            else:
                records = [{'uuid':'app-1','name':'Staging',
                            'type':'application','status':'running'}]
            return {'ok':True,'exit':0,'raw':json.dumps(records)}
        with patch.object(sys,'argv',['run','--context','mad4b-production',
                                      '--host','tours.dev.mad4b.com']), \
             patch('shutil.which', return_value='/usr/local/bin/coolify'), \
             patch.object(ci,'cmd',side_effect=fake_cmd), \
             redirect_stdout(io.StringIO()) as output:
            code=ci.main()
        report=json.loads(output.getvalue())
        self.assertEqual(code,2)
        self.assertFalse(report['domain_metadata_available'])
        self.assertIn('COOLIFY_LIST_API_OMITS_DOMAINS',report['blockers'])
        self.assertEqual(report['matched_hosts'][0]['registration'],'NOT_CONFIRMED')

if __name__ == '__main__':
    unittest.main()
