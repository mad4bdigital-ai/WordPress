"""Fail-closed local Git source-owner provenance; never authorizes release."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT = Path(__file__).resolve().parents[4]
MANIFEST = ROOT / 'ops/company-reconcile/portfolio-source-owner-recovery-20261117.json'
CATALOG = ROOT / 'wp-content/plugins/mad4b-site-control-plane/config/wordpress-addon-catalog.json'

class OriginalPortfolioOwnerSource(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
        cls.catalog = json.loads(CATALOG.read_text(encoding='utf-8'))

    def test_original_site_owner_exact_code_bytes(self):
        m = self.manifest
        self.assertEqual(m['source_file_count'], 86)
        self.assertEqual(m['source_php_file_count'], 82)
        files = m['files']
        self.assertEqual(len(files), len(set(f['path'] for f in files)))
        for item in files:
            with self.subTest(path=item['path']):
                p = ROOT / item['path']
                self.assertTrue(p.is_file() and not p.is_symlink())
                self.assertEqual(p.stat().st_size, item['bytes'])
                self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(), item['sha256'])

    def test_backup_runtime_artifacts_excluded(self):
        m = self.manifest
        self.assertEqual(len(m['excluded_inactive_backups']), 18)
        for x in m['excluded_inactive_backups']:
            self.assertFalse((ROOT/'wp-content/plugins/mad4b-company-portfolio-manager'/x['path']).exists())
        self.assertTrue(all(v == 0 for v in m['secret_pattern_scan'].values()))

    def test_addon_source_root_present_but_release_still_uncertified(self):
        items = [v for v in self.catalog['addons'] if v.get('addon_id') == 'mad4b-company-management']
        self.assertEqual(len(items), 1)
        source = items[0]
        self.assertEqual(source['source_root'], 'wp-content/plugins/mad4b-company-portfolio-manager')
        self.assertEqual(source['release_ring'], 'dynamic')
        self.assertEqual(source['certified_pairs'], [])
        self.assertEqual(source['release_policy']['unknown_pair_fallback'], 'shadow')
        self.assertFalse(source['release_policy']['production_authorized'])
        self.assertTrue((ROOT/source['source_root']/source['plugin_file'].split('/')[-1]).is_file())
        self.assertFalse(self.manifest['signed_original313_deployment_authority'])
        self.assertFalse(self.manifest['production_site_deployment'])


    def test_restored_write_surface_cannot_be_silently_reclassified(self):
        """Pin the unapproved first-party write inventory, not execution authority.

        The Pre-Staging scanner must continue denying the original ADDON's
        raw mutating primitives until the exact owner/operation guards have
        been independently reviewed.  A reviewed source SHA is not a grant.
        """
        import re
        from collections import Counter

        patterns = {
            'direct_content_or_option_mutation':
                r'\b(?:wp_update_post|update_option|add_option|delete_option)\s*\(',
            'direct_wpdb_query':
                r'\$wpdb\s*->\s*(?:query|insert|update|delete)\s*\(',
            'direct_filesystem_write':
                r'\b(?:file_put_contents|fwrite|unlink|rename)\s*\(',
            'direct_outbound_http':
                r'\bwp_remote_(?:get|post|request|head)\s*\(',
            'shell_exec': r'\bshell_exec\s*\(',
            'proc_open': r'\bproc_open\s*\(',
            'passthru': r'\bpassthru\s*\(',
            'eval': r'\beval\s*\(',
        }
        counts = Counter()
        affected_files = set()
        php_count = 0
        for item in self.manifest['files']:
            if not item['path'].endswith('.php'):
                continue
            php_count += 1
            code = (ROOT / item['path']).read_text(encoding='utf-8')
            for kind, pattern in patterns.items():
                n = len(re.findall(pattern, code))
                counts[kind] += n
                if n:
                    affected_files.add(item['path'])

        self.assertEqual(php_count, 82)
        self.assertEqual(len(affected_files), 33)
        self.assertEqual(dict(counts), {
            'direct_content_or_option_mutation': 74,
            'direct_wpdb_query': 12,
            'direct_filesystem_write': 11,
            'direct_outbound_http': 2,
            'shell_exec': 0,
            'proc_open': 0,
            'passthru': 0,
            'eval': 0,
        })
        self.assertEqual(sum(counts.values()), 99)

        # This deliberately includes non-executing text, method declarations
        # and CLI-only fixtures. Removing those is NOT permission to allow
        # genuine company/site, provider or host mutations.
        catalog_item = next(
            x for x in self.catalog['addons']
            if x.get('addon_id') == 'mad4b-company-management'
        )
        self.assertEqual(catalog_item['certified_pairs'], [])
        self.assertFalse(catalog_item['release_policy']['production_authorized'])
        self.assertFalse(self.manifest['signed_original313_deployment_authority'])
        scanner = (ROOT / 'tools/mad4b_pre_staging_hybrid_audit.py').read_text(
            encoding='utf-8'
        )
        self.assertIn('if addon_static_hits:', scanner)
        self.assertIn('forbidden direct execution/mutation primitives:', scanner)

    def test_cloudflare_original_company_write_denials(self):
        """Execute the immutable original Cloudflare class without WP/network/DB."""
        import os
        import shutil
        import subprocess
        import tempfile

        cli = shutil.which('php')
        self.assertIsNotNone(cli, 'PHP CLI is required for original runtime security tests')
        source = ROOT / (
            'wp-content/plugins/mad4b-company-portfolio-manager/'
            'includes/class-mad4b-scp-company-domain-cloudflare.php'
        )
        original = source.read_text(encoding='utf-8')
        php = r'''define('ABSPATH','/tmp/');
define('LOGGED_IN_COOKIE','mad4b_test_cookie');
class WP_Error {
 private $code;
 function __construct($c,$m='',$d=[]){$this->code=$c;}
 function get_error_code(){return $this->code;}
}
$GLOBALS['caps']=['manage_options'=>false,'activate_plugins'=>false];
$GLOBALS['cookie_identity']=0;$GLOBALS['session_token']='';$GLOBALS['routes']=[];
function current_user_can($c){return !empty($GLOBALS['caps'][$c]);}
function wp_validate_auth_cookie($c,$s){return $GLOBALS['cookie_identity'];}
function wp_get_session_token(){return $GLOBALS['session_token'];}
function get_current_user_id(){return 17;}
function wp_verify_nonce($n,$a){return $n==='correct-nonce'&&$a==='wp_rest';}
function register_rest_route($ns,$path,$options){$GLOBALS['routes'][$ns.$path]=$options;}
function wp_remote_request(){throw new Exception('UNEXPECTED_NETWORK');}
function wp_remote_get(){throw new Exception('UNEXPECTED_NETWORK');}
function update_option(){throw new Exception('UNEXPECTED_DATABASE_WRITE');}
function add_option(){throw new Exception('UNEXPECTED_DATABASE_WRITE');}
require getenv('MAD4B_CLOUDFLARE_SOURCE');
$checks=0;
function check($ok,$key){global $checks;if(!$ok){fwrite(STDERR,'FAIL_'.$key."\n");exit(1);} $checks++;}
function deny($obj,$code){return $obj instanceof WP_Error && $obj->get_error_code()===$code;}
$c='MAD4B_SCP_Company_Domain_Cloudflare';
$s=['origin_ipv4'=>'93.184.216.34','registry'=>[
 'demo'=>['production_admitted'=>true,'origin_host'=>'origin.example.com','approved_custom_domains'=>['approved.example.org']],
 'notlive'=>['production_admitted'=>false,'origin_host'=>'origin.example.com']
]];
check($c::valid_host('demo.mad4b.com'),'valid_host');
check(!$c::valid_host('Demo.mad4b.com'),'uppercase_host');
check(!$c::valid_host('127.0.0.1'),'ip_host');
check(!$c::valid_host('foo..example.com'),'invalid_domain');
check(is_array($c::validate_target(['company_code'=>'demo','mode'=>'subdomain','hostname'=>'demo.mad4b.com'],$s)),'valid_company_subdomain');
check(deny($c::validate_target(['company_code'=>'demo','mode'=>'subdomain','hostname'=>'other.mad4b.com'],$s),'mad4b_domain_subdomain_scope'),'other_company_host');
check(deny($c::validate_target(['company_code'=>'unknown','mode'=>'subdomain','hostname'=>'unknown.mad4b.com'],$s),'mad4b_domain_company_not_admitted'),'unknown_company');
check(deny($c::validate_target(['company_code'=>'notlive','mode'=>'subdomain','hostname'=>'notlive.mad4b.com'],$s),'mad4b_domain_company_not_admitted'),'unadmitted_company');
check(deny($c::validate_target(['company_code'=>'demo','mode'=>'custom_domain','hostname'=>'other.example.org'],$s),'mad4b_domain_not_company_approved'),'foreign_custom');
check(is_array($c::validate_target(['company_code'=>'demo','mode'=>'custom_domain','hostname'=>'approved.example.org'],$s)),'approved_custom');
$s['origin_ipv4']='10.0.0.1';
check(deny($c::validate_target(['company_code'=>'demo','mode'=>'subdomain','hostname'=>'demo.mad4b.com'],$s),'mad4b_domain_origin_unconfigured'),'private_origin');
check(deny($c::can_write(),'mad4b_domain_owner_required'),'missing_role');
$GLOBALS['caps']['manage_options']=true;
check(deny($c::can_write(),'mad4b_domain_owner_required'),'missing_plugin_cap');
$GLOBALS['caps']['activate_plugins']=true;
check(deny($c::can_write(),'mad4b_domain_browser_approval_required'),'missing_cookie');
$_COOKIE[LOGGED_IN_COOKIE]='cookie-value';
check(deny($c::can_write(),'mad4b_domain_browser_approval_required'),'wrong_cookie_user');
$GLOBALS['cookie_identity']=17;
check(deny($c::can_write(),'mad4b_domain_browser_approval_required'),'missing_token');
$GLOBALS['session_token']='session';
check(deny($c::can_write(),'mad4b_domain_browser_approval_required'),'missing_nonce');
$_SERVER['HTTP_X_WP_NONCE']='wrong-nonce';
check(deny($c::can_write(),'mad4b_domain_browser_approval_required'),'bad_nonce');
$_SERVER['HTTP_X_WP_NONCE']='correct-nonce';
check($c::can_write()===true,'valid_owner_session');
$c::routes();
foreach(['/company-domains/zones/enroll','/company-domains/dns/commit'] as $route){
 $row=$GLOBALS['routes']['mad4b/v1'.$route]??null;
 check(is_array($row)&&$row['methods']==='POST'&&$row['permission_callback']===[$c,'can_write'],'write_route_'.$route);
}
echo json_encode(['contract'=>'mad4b.cloudflare-scope-negative-runtime.v1','passed'=>$checks,'provider_network_called'=>false,'wordpress_mutation_performed'=>false])."\n";'''

        def run(test_source):
            env = os.environ.copy()
            env['MAD4B_CLOUDFLARE_SOURCE'] = str(test_source)
            return subprocess.run(
                [cli, '-r', php],
                cwd=ROOT, env=env, capture_output=True, text=True,
                timeout=15, check=False,
            )

        baseline = run(source)
        self.assertEqual(baseline.returncode, 0, baseline.stderr)
        result = json.loads(baseline.stdout)
        self.assertEqual(result['contract'], 'mad4b.cloudflare-scope-negative-runtime.v1')
        self.assertEqual(result['passed'], 21)
        self.assertIs(result['provider_network_called'], False)
        self.assertIs(result['wordpress_mutation_performed'], False)

        # Mutation testing: the SAME executable negative cases must fail if
        # the browser nonce, company hostname or REST write gate is removed.
        guard_mutations = [
            (
                "|| !wp_verify_nonce($nonce,'wp_rest')",
                '|| false',
                'nonce_denial',
            ),
            (
                "if ($mode === 'subdomain' && $hostname !== $code.'.'.self::SUBZONE) {",
                "if (false && $hostname !== $code.'.'.self::SUBZONE) {",
                'company_hostname_denial',
            ),
            (
                "'permission_callback' => array(__CLASS__, 'can_write')",
                "'permission_callback' => array(__CLASS__, 'can_read')",
                'rest_write_permission',
            ),
        ]
        with tempfile.TemporaryDirectory(prefix='mad4b-cloudflare-negative-') as temp:
            file = Path(temp) / 'source.php'
            for original_guard, bad_guard, label in guard_mutations:
                with self.subTest(label=label):
                    self.assertEqual(original.count(original_guard), 1)
                    file.write_text(original.replace(original_guard, bad_guard, 1), encoding='utf-8')
                    broken = run(file)
                    self.assertNotEqual(broken.returncode, 0, label + ': ' + broken.stdout)


if __name__ == '__main__':
    unittest.main()
