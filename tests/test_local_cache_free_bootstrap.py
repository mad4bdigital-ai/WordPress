"""Verify the native no-OPcache Local boot contract using actual PHP flags."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
BOOT = ROOT / 'wp-content/plugins/mad4b-site-control-plane/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'


class LocalCacheFreeBootstrap(unittest.TestCase):
    def test_real_php_opcache_disabled_and_enabled(self):
        source = BOOT.read_text()
        start = source.index('\t$mad4b_mcp_mu_effective_environment =')
        end = source.index('\tif ( $mad4b_mcp_mu_native_opcache ) {', start)
        selection = source[start:end]
        self.assertIn("'production' ===", source)
        self.assertIn("'current' !== $mad4b_cache_result['state']", source)
        self.assertIn("! extension_loaded( 'Zend OPcache' )", selection)
        self.assertIn("function_exists( 'opcache_get_status' ) && false === opcache_get_status( false )", selection)
        self.assertIn("array( 'local', 'development' )", selection)
        self.assertNotIn("array( 'local', 'development', 'production' )", selection)
        php = (
            "$states=array(); foreach(array('local','development','staging','production') as $env) {"
            "$mad4b_mcp_mu_binding=array('environment'=>$env);\n"
            + selection + "\n$states[$env]=(bool)$mad4b_mcp_mu_native_opcache; }"
            "echo json_encode(array('opcache_on'=>is_array(opcache_get_status(false)),'states'=>$states));"
        )
        for flag, disabled_func, expected in (
            ('0', False, {'local': True, 'development': True, 'staging': True, 'production': False}),
            ('1', False, {'local': False, 'development': False, 'staging': True, 'production': False}),
            ('1', True, {'local': False, 'development': False, 'staging': True, 'production': False}),
        ):
            with self.subTest(opcache_enable_cli=flag, status_api_disabled=disabled_func):
                args = ['php', '-d', 'opcache.enable_cli='+flag]
                if disabled_func:
                    args.extend(['-d', 'disable_functions=opcache_get_status'])
                php_code = php.replace(
                    'is_array(opcache_get_status(false))',
                    "(function_exists('opcache_get_status') && is_array(opcache_get_status(false)))"
                )
                result = subprocess.run(args+['-r', php_code],
                                        check=True, text=True, capture_output=True, timeout=15)
                observed = json.loads(result.stdout)
                self.assertEqual(observed['opcache_on'], flag == '1' and not disabled_func)
                self.assertEqual(observed['states'], expected)
                if disabled_func:
                    self.assertIn("! extension_loaded( 'Zend OPcache' )", selection)


if __name__ == '__main__':
    unittest.main()
