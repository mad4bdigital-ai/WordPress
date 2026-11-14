"""Verify the native no-OPcache Local boot contract using actual PHP flags."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
BOOT = ROOT / 'wp-content/plugins/mad4b-site-control-plane/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'


class LocalCacheFreeBootstrap(unittest.TestCase):
    def test_browser_cache_free_proof_is_from_the_server_sapi(self):
        workflow = (ROOT / '.github/workflows/mad4b-local-oauth-standalone.yml').read_text()
        self.assertIn("php -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:8088", workflow)
        self.assertIn("'/__mad4b_ci_cache_free' === $path", workflow)
        self.assertIn("http_response_code( $cache_free ? 204 : 503 )", workflow)
        self.assertIn('curl -fsS --max-time 5 "$MAD4B_BROWSER_BASE_URL/__mad4b_ci_cache_free"', workflow)
        self.assertNotIn("if ( ! $cache_free ) $cache_free = true;", workflow)

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
            "echo json_encode(array('opcache_loaded'=>extension_loaded('Zend OPcache'),"
            "'opcache_on'=>(function_exists('opcache_get_status') && is_array(opcache_get_status(false))),"
            "'states'=>$states));"
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
                loaded = observed['opcache_loaded']
                self.assertEqual(observed['opcache_on'], bool(loaded and flag == '1' and not disabled_func))
                # PHP can be installed with no OPcache extension at all
                # (notably the upstream php:7.4-cli test image). In that
                # case Local is genuinely cache-free even with enable_cli=1.
                if not loaded:
                    expected = {'local': True, 'development': True,
                                'staging': True, 'production': False}
                self.assertEqual(observed['states'], expected)
                if disabled_func:
                    self.assertIn("! extension_loaded( 'Zend OPcache' )", selection)


if __name__ == '__main__':
    unittest.main()
