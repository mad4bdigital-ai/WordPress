import importlib.util
import unittest
from unittest.mock import patch

PATH='/opt/mad4b-company-transport/registry-remote-v1/mcp_stdio_client.py'
spec=importlib.util.spec_from_file_location('vps_mcp_optional_user',PATH)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class OptionalWordPressIdentityTests(unittest.TestCase):
    def test_rpc_error_cannot_count_as_success(self):
        self.assertFalse(m._rpc_ok({'id':1,'error':{'code':-32000,'message':'denied'}}))
        self.assertFalse(m._rpc_ok({'id':2,'result':[]}))
        self.assertFalse(m._rpc_ok(None))
        self.assertTrue(m._rpc_ok({'id':1,'result':{'protocolVersion':'2025-11-25'}}))

    def test_initialize_error_never_lists_tools_or_calls_ability(self):
        from unittest.mock import MagicMock,call
        proc=MagicMock()
        selector=MagicMock()
        events=[]
        def send(_,message):events.append(('send',message.get('method')))
        def read(_,__,___,request_id,____):
            events.append(('read',request_id))
            return {'id':request_id,'error':{'code':-32603,'message':'Rejected'}}
        with patch.object(m,'command_for',return_value=['dummy']), \
             patch.object(m.subprocess,'Popen',return_value=proc), \
             patch.object(m.selectors,'DefaultSelector',return_value=selector), \
             patch.object(m.os,'set_blocking'), \
             patch.object(m,'_send_rpc',side_effect=send), \
             patch.object(m,'_read_rpc',side_effect=read):
            result=m.inspect('hosting-2:staging','auto',None,read_tool=True)
        self.assertEqual(result['state'],'MCP_INITIALIZATION_FAILED')
        self.assertFalse(result['initialize_pass'])
        self.assertFalse(result['tools_list_pass'])
        self.assertFalse(result['read_tool_pass'])
        self.assertEqual(events,[('send','initialize'),('read',1)])

    def test_central_route_does_not_depend_on_company_registry(self):
        from unittest.mock import MagicMock
        wp=['/usr/bin/docker','exec','-u','www-data','php-test','wp']
        with patch.object(m,'central_wp',return_value=wp), \
             patch.object(m.resolver,'registry',
                side_effect=AssertionError('Central must not depend on company registry')), \
             patch.object(m.subprocess,'run',return_value=MagicMock(stdout='https://tours.dev.mad4b.com'+chr(10))):
            actual=m.bound_route(m.CENTRAL)
        self.assertEqual(actual,(wp,None,None))

    def test_mcp_handshake_stages_run_in_order(self):
        from unittest.mock import MagicMock
        proc=MagicMock()
        selector=MagicMock()
        events=[]
        def send(_,message):events.append(('send',message.get('method')))
        def read(_,__,___,request_id,____):
            events.append(('read',request_id))
            if request_id==1:
                return {'id':1,'result':{'protocolVersion':'2025-11-25'}}
            if request_id==2:
                return {'id':2,'result':{'tools':[{'name':'mad4b-site-info'}]}}
            return {'id':3,'result':{'isError':False,'content':[{'type':'text','text':'ok'}]}}
        with patch.object(m,'command_for',return_value=['dummy']), \
             patch.object(m.subprocess,'Popen',return_value=proc), \
             patch.object(m.selectors,'DefaultSelector',return_value=selector), \
             patch.object(m.os,'set_blocking'), \
             patch.object(m,'_send_rpc',side_effect=send), \
             patch.object(m,'_read_rpc',side_effect=read):
            result=m.inspect('hosting-2:staging','auto',None,read_tool=True)
        self.assertTrue(result['read_tool_pass'])
        self.assertEqual(events,[('send','initialize'),('read',1),
             ('send','notifications/initialized'),('send','tools/list'),('read',2),
             ('send','tools/call'),('read',3)])

    def test_auto_requires_no_user(self):
        self.assertEqual(m.wp_args('auto',None),[])
    def test_host_operator_does_not_impersonate_wp_user(self):
        self.assertEqual(m.wp_args('host_operator',None),[])
    def test_wp_user_requires_explicit_mode(self):
        with self.assertRaisesRegex(ValueError,'EXPLICIT_WORDPRESS_USER_REQUIRED'):
            m.wp_args('wordpress_user',None)
    def test_auto_rejects_accidental_user_injection(self):
        with self.assertRaisesRegex(ValueError,'WORDPRESS_USER_REQUIRES_EXPLICIT_MODE'):
            m.wp_args('auto','1')
    def test_explicit_wp_user(self):
        self.assertEqual(m.wp_args('wordpress_user','1'),['--user=1'])
    def test_invalid_user_prevents_shell_injection(self):
        with self.assertRaises(ValueError):
            m.wp_args('wordpress_user','admin; touch /tmp/forbidden')
    def test_docker_mcp_stdin_is_attached(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/bin/docker','exec','-u','www-data','php-test','wp'],None,None)):
            result=m.command_for(m.CENTRAL,'auto',None)
        self.assertEqual(result[:4],['/usr/bin/docker','exec','-i','-u'])
        self.assertNotIn('--user=1',result)
        self.assertIn('--server=mad4b-content',result)
    def test_auto_does_not_require_host_operator_grant_for_anonymous_probe(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/local/bin/wp','--path=/wp'],['ssh','-T'],'site@host')), \
             patch.object(m,'original_host_authority',side_effect=AssertionError('operator not requested')):
            result=m.command_for('hosting-2:staging','auto',None)
        self.assertNotIn('--user=',result[-1])
    def test_rejects_invalid_server_id(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/local/bin/wp','--path=/wp'],['ssh','-T'],'site@host')):
            with self.assertRaisesRegex(ValueError,'INVALID_MCP_SERVER_ID'):
                m.command_for('hosting-2:staging','auto',None,'bad server;invalid')
    def test_explicit_server_is_not_second_default(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/bin/docker','exec','-u','www-data','php-test','wp'],None,None)):
            result=m.command_for(m.CENTRAL,'auto',None,'custom-read')
        self.assertIn('--server=custom-read',result)
        self.assertNotIn('--server=mad4b-content',result)

    def test_ssh_command_preserves_pinned_base(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/local/bin/wp','--path=/wp','--skip-themes'],
            ['ssh','-T','-o','StrictHostKeyChecking=yes'],'site@host')):
            result=m.command_for('hosting-2:staging','auto',None)
        self.assertEqual(result[:4],['ssh','-T','-o','StrictHostKeyChecking=yes'])
        self.assertEqual(result[-2],'site@host')
        self.assertIn('mcp-adapter serve',result[-1])
        self.assertNotIn('--user=',result[-1])
    def test_operator_mode_requires_original_authority(self):
        with patch.object(m,'original_host_authority',side_effect=ValueError('ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED')):
            with self.assertRaisesRegex(ValueError,'ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED'):
                m.command_for('hosting-2:staging','host_operator',None)

    def test_ssh_explicit_wordpress_user(self):
        with patch.object(m,'bound_route',return_value=(
            ['/usr/local/bin/wp','--path=/wp'],['ssh','-T'],'site@host')), \
             patch.object(m,'original_host_authority',return_value=True) as verify:
            result=m.command_for('hosting-3:staging','wordpress_user','editor')
            verify.assert_called_once_with('hosting-3:staging')
        self.assertIn('--user=editor',result[-1])

if __name__=='__main__':
    unittest.main()
