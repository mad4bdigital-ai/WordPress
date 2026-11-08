import importlib.util
import hashlib
from pathlib import Path
import tempfile
import unittest

SCRIPT=Path(__file__).resolve().parent.parent/'bin/company-cache-control-readiness-v1.py'
if not SCRIPT.is_file():
    SCRIPT=Path('/opt/mad4b-company-provisioning/bin/company-cache-control-readiness-v1.py')
spec=importlib.util.spec_from_file_location('cachecontrol',SCRIPT)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class ReadinessTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        root=Path(self.temp.name)
        plugin=root/'mu-plugin.php'
        plugin.write_text('approved plugin')
        registry=root/'registry.php'
        registry.write_text('read-only registry code')
        self.expected=hashlib.sha256(plugin.read_bytes()).hexdigest()
        self.plugin=plugin
        self.registry=registry
        self.spec={'plugin':str(plugin),'registry':str(registry),
                   'socket':'/nonexistent/fake.sock','socket_unit':'test.service'}
        self.old_socket=m.socket_state
        m.socket_state=lambda *a:{'state':'PASS','systemd':'active'}

    def tearDown(self):
        m.socket_state=self.old_socket
        self.temp.cleanup()

    def test_pinned_registry_present(self):
        r=m.site_observation('staging',self.spec,self.expected)
        self.assertEqual(r['state'],'PASS')
        self.assertTrue(r['safe_read_only'])

    def test_fallback_explicit_without_registry(self):
        self.registry.unlink()
        r=m.site_observation('wayfa',self.spec,self.expected)
        self.assertEqual(r['state'],'DEGRADED_INHERITANCE')
        self.assertEqual(r['inheritance_coverage'],'LOCAL_FALLBACK_ONLY')

    def test_unknown_plugin_change_alerts_without_repair(self):
        self.plugin.write_text('some other code')
        r=m.site_observation('staging',self.spec,self.expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(self.plugin.read_text(),'some other code')

    def test_missing_plugin_alerts(self):
        self.plugin.unlink()
        self.assertEqual(m.site_observation('a',self.spec,self.expected)['state'],'DRIFT')

    def test_symlinked_plugin_refused(self):
        root=self.plugin.parent
        file=root/'real.txt'
        file.write_text('approved plugin')
        self.plugin.unlink()
        self.plugin.symlink_to(file)
        r=m.site_observation('a',self.spec,self.expected)
        self.assertEqual(r['plugin'],'MISSING_OR_UNSAFE')

    def test_socket_unavailable_alerts(self):
        m.socket_state=lambda *a:{'state':'SERVICE_NOT_ACTIVE'}
        self.assertEqual(m.site_observation('a',self.spec,self.expected)['state'],'DRIFT')

    def test_pinned_registry_unknown_upgrade_drift(self):
        expected=hashlib.sha256(self.registry.read_bytes()).hexdigest()
        self.registry.write_text('unexpected new registry')
        r=m.site_observation('wayfa',self.spec,self.expected,registry_hash=expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['registry_integrity'],'HASH_DRIFT')
        self.assertEqual(self.registry.read_text(),'unexpected new registry')

    def test_pinned_bridge_missing_drift(self):
        self.spec['bridge']=str(self.plugin.parent/'missing-bridge.php')
        r=m.site_observation('wayfa',self.spec,self.expected,
                             bridge_hash=hashlib.sha256(b'expected bridge').hexdigest())
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['bridge_integrity'],'MISSING_OR_UNSAFE')

    def test_litespeed_fastcgi_bridge_sha_drift_detected(self):
        file=self.plugin.parent/'lscbridge.php'
        file.write_text('unexpected updated module')
        self.spec['lsc_bridge']=str(file)
        expected=hashlib.sha256(b'known approved module').hexdigest()
        r=m.site_observation('wayfa',self.spec,self.expected,lsc_bridge_hash=expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['litespeed_fastcgi_bridge_integrity'],'HASH_DRIFT')

    def test_litespeed_fastcgi_bridge_missing_alerts(self):
        self.spec['lsc_bridge']=str(self.plugin.parent/'not-installed.php')
        expected=hashlib.sha256(b'known approved module').hexdigest()
        r=m.site_observation('wayfa',self.spec,self.expected,lsc_bridge_hash=expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['litespeed_fastcgi_bridge_integrity'],'MISSING_OR_UNSAFE')

    def test_safe_html_unknown_hash_drift(self):
        safe=self.plugin.parent/'safe-html.php'
        safe.write_text('unexpected module')
        self.spec['safe_html_plugin']=str(safe)
        expected=hashlib.sha256(b'approved code').hexdigest()
        r=m.site_observation('company',self.spec,self.expected,safe_html_hash=expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['safe_public_html_integrity'],'HASH_DRIFT')

    def test_nginx_global_epoch_missing_or_unrecognized(self):
        safe=self.plugin.parent/'safe-html.php'
        safe.write_text('approved code')
        self.spec['safe_html_plugin']=str(safe)
        self.spec['nginx_conf']=str(self.plugin.parent/'not-enrolled.conf')
        self.spec['global_epoch_key_count']=1
        expected=hashlib.sha256(safe.read_bytes()).hexdigest()
        r=m.site_observation('company',self.spec,self.expected,safe_html_hash=expected)
        self.assertEqual(r['state'],'DRIFT')
        self.assertEqual(r['nginx_global_epoch_integrity'],'MISSING_OR_UNSAFE')

    def test_nginx_global_epoch_valid_config(self):
        safe=self.plugin.parent/'safe-html.php'
        safe.write_text('approved code')
        conf=self.plugin.parent/'nginx.conf'
        conf.write_text("""# MAD4B_PUBLIC_HTML_GLOBAL_EPOCH_V1 BEGIN\nmap $host $mad4b_public_html_global_epoch { default ""; }\n# MAD4B_PUBLIC_HTML_GLOBAL_EPOCH_V1 END\nfastcgi_cache_key "$host|$mad4b_fastcgi_version$mad4b_public_html_global_epoch";\n""")
        self.spec.update(safe_html_plugin=str(safe),nginx_conf=str(conf),global_epoch_key_count=1)
        expected=hashlib.sha256(safe.read_bytes()).hexdigest()
        r=m.site_observation('company',self.spec,self.expected,safe_html_hash=expected)
        self.assertEqual(r['state'],'PASS')
        self.assertEqual(r['nginx_global_epoch_integrity'],'PASS')

    def test_atomic_report_mode_is_private(self):
        target=Path(self.temp.name)/'out.json'
        m.write_json_atomic(target,{'contract':'test','private':True})
        self.assertEqual(target.stat().st_mode & 0o777,0o600)
        self.assertIn('"contract": "test"',target.read_text())

if __name__=='__main__':
    unittest.main()
