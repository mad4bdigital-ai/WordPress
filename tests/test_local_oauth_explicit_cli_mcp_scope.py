"""Source+runtime proof that disposable WP-CLI OAuth tests opt into MCP.

A normal WordPress CLI job must not eagerly materialize all MCP catalogs.
The `MAD4B_SCP_MCP_CLI_REQUEST=1` flag already exists in the current
first-party request-scope contract; it changes no bearer/write permissions.
"""
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
WORKFLOW=ROOT/'.github/workflows/mad4b-local-oauth-standalone.yml'
REQUEST=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-request-scope.php'
SMOKE=ROOT/'wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-chatgpt-http-smoke.php'

class ExplicitCliMcpContext(unittest.TestCase):
    def test_both_disposable_oauth_mcp_processes_opt_in_but_not_background_cli(self):
        workflow=WORKFLOW.read_text()
        name='Prove local OAuth bearer through REST initialize session tools/list and tools/call'
        self.assertEqual(workflow.count('name: '+name),1)
        portion=workflow.split('name: '+name,1)[1].split('      - name:',1)[0]
        self.assertIn('MAD4B_SCP_MCP_CLI_REQUEST=1 php /tmp/wp-cli.phar eval-file',portion)
        self.assertIn('runtime-local-oauth-chatgpt-http-smoke.php',portion)
        self.assertIn('php /tmp/wp-cli.phar eval-file',portion)
        projection='Prove dynamic projection admission through real OAuth and tools/call'
        self.assertEqual(workflow.count('name: '+projection),1)
        projection_step=workflow.split('name: '+projection,1)[1].split('      - name:',1)[0]
        self.assertIn('MAD4B_SCP_MCP_CLI_REQUEST=1 php /tmp/wp-cli.phar eval-file "$root/runtime-chatgpt-projection-http.php"', projection_step)
        self.assertIn('php /tmp/wp-cli.phar eval-file "$root/runtime-chatgpt-projection-http-seed.php"', projection_step)
        independent=workflow.split('name: Prove projection through independent HTTP requests',1)[1].split('      - name:',1)[0]
        self.assertNotIn('MAD4B_SCP_MCP_CLI_REQUEST=1', independent)
        self.assertEqual(workflow.count('MAD4B_SCP_MCP_CLI_REQUEST=1'),2)

    def test_existing_wpcli_scope_is_exact_opt_in_and_has_no_authorization(self):
        source=REQUEST.read_text()
        self.assertIn("'1' === (string) getenv( 'MAD4B_SCP_MCP_CLI_REQUEST' )",source)
        self.assertIn("return self::cli_mcp_opt_in();",source)
        self.assertIn('current_request_requires_mcp_runtime()',source)
        self.assertIn("'production_changed' => false",source)
        self.assertIn('OAuth bearer initialize failed.',SMOKE.read_text())

    def test_site_precedence_does_not_skip_bearer_scope_or_client_fences(self):
        plugin = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
        test = (plugin / 'tests/runtime-local-oauth-chatgpt-http-smoke.php').read_text()
        operator = (plugin / 'includes/class-mad4b-scp-operator-grant-convergence.php').read_text()
        developer = (plugin / 'includes/class-mad4b-scp-developer-authority.php').read_text()
        self.assertIn('$site_denied_first = ! $managed_site_ready && in_array( $code, array(', test)
        for exact in ('mad4b_developer_authority_site_not_ready',
                      'mad4b_operator_grants_site_not_ready'):
            self.assertIn(exact, test)
        self.assertIn("preg_match( '/scope|step_up|client/'", test)
        self.assertIn('Known hidden step-up tool was executable.', test)
        self.assertIn('Every direct step-up permission callback must independently deny this actor.', test)
        self.assertIn("'mad4b_developer_authority_site_not_ready'", developer)
        self.assertIn('MAD4B_SCP_Site_Profile::managed_runtime_enabled()', developer)
        self.assertIn("verified_bearer_has_scope( 'mad4b:authority:step-up' )", operator)
        self.assertIn('verified_bearer_client_is(', operator)
        self.assertIn('MAD4B_SCP_Registered_Write_Admission::preflight( self::ABILITY )', operator)
        self.assertIn('self::can_access( $input )', operator)

    @unittest.skipUnless(shutil.which('php'), 'PHP executable not present')
    def test_hermetic_processes_prove_default_denial_and_explicit_scope(self):
        snippet=(
            'define("ABSPATH","/nonexistent-offline-ci/");'
            'define("WP_CLI",true);'
            'require '+repr(str(REQUEST)).replace("'",'"')+';'
            'echo MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime() ? "1" : "0";'
        )
        for flag,expected in ((None,'0'),('0','0'),('1','1')):
            env=os.environ.copy();env.pop('MAD4B_SCP_MCP_CLI_REQUEST',None)
            if flag is not None:env['MAD4B_SCP_MCP_CLI_REQUEST']=flag
            result=subprocess.run(['php','-r',snippet],env=env,capture_output=True,text=True,check=True)
            self.assertEqual(result.stdout,expected,flag)
            self.assertEqual(result.stderr,'',flag)

if __name__=='__main__': unittest.main()
