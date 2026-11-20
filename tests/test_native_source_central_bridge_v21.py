"""V21: candidate bridge vs actual live status, and no-authority WP read contract."""
import importlib.util
from pathlib import Path
import json
import subprocess
import tempfile
import unittest
import os

ROOT=Path(__file__).resolve().parents[1]
MOD=ROOT/'ops/native-source-publisher-v1/audit_release_readiness.py'
spec=importlib.util.spec_from_file_location('mad4b_source_bridge_v21_audit',MOD)
audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
REL=Path('wp-content/plugins/mad4b-site-control-plane')
REQUIRE="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-source-release-review.php';"
BOOT='MAD4B_SCP_Native_Source_Release_Review::boot();'

class BridgeReadiness(unittest.TestCase):
    def setUp(self):
        d=tempfile.TemporaryDirectory(prefix='mad4b-central-source-bridge-v21-')
        self.addCleanup(d.cleanup)
        self.root=Path(d.name)
        self.source=self.root/'source';self.live=self.root/'live'
        for folder in (self.source,self.live):
            (folder/REL/'includes').mkdir(parents=True)
        self.install(self.source)

    def install(self,root,main=None,module=b'<?php // exact read-only module'):
        (root/REL/'mad4b-site-control-plane.php').write_text(main or
            '<?php\n'+REQUIRE+'\n'+BOOT+'\n')
        (root/REL/'includes/class-mad4b-scp-native-source-release-review.php').write_bytes(module)

    def check(self):
        return audit.central_source_bridge_static_parity(self.source,self.live)

    def test_git_candidate_is_not_installed(self):
        found=self.check()
        self.assertTrue(found['source_candidate_present'])
        self.assertFalse(found['installed_source_byte_parity'])
        self.assertFalse(found['wordpress_ability_runtime_verified'])
        self.assertFalse(found['signed_authority_verified'])

    def test_exact_live_module_and_bootstrap_are_needed(self):
        self.install(self.live)
        found=self.check()
        self.assertTrue(found['installed_source_byte_parity'])
        self.assertFalse(found['wordpress_ability_runtime_verified'])
        self.assertFalse(found['signed_authority_verified'])

    def test_missing_boot_cannot_fake_deployment(self):
        self.install(self.live,main='<?php\n'+REQUIRE+'\n')
        self.assertFalse(self.check()['installed_source_byte_parity'])

    def test_comment_does_not_count_as_registration(self):
        self.install(self.live,main='<?php\n// '+REQUIRE+'\n// '+BOOT+'\n')
        self.assertFalse(self.check()['installed_source_byte_parity'])

    def test_live_file_drift_does_not_count_as_registered(self):
        self.install(self.live,module=b'<?php // DIFFERENT SOURCE')
        self.assertFalse(self.check()['installed_source_byte_parity'])

    def test_symlink_live_module_does_not_count_as_registered(self):
        self.install(self.live)
        module=self.live/REL/'includes/class-mad4b-scp-native-source-release-review.php'
        module.unlink();module.symlink_to(self.source/REL/'includes/class-mad4b-scp-native-source-release-review.php')
        self.assertFalse(self.check()['installed_source_byte_parity'])

    def test_installed_source_is_never_signed_authority_by_itself(self):
        result=audit.central_source_bridge_static_parity()
        self.assertTrue(result['source_candidate_present'])
        self.assertFalse(result['wordpress_ability_runtime_verified'])
        self.assertFalse(result['signed_authority_verified'])

    def test_offline_php_registers_only_read_abilities(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-bridge-php-fixture-') as tmp:
            base=Path(tmp)
            # This directory contains a disposable public test-only JS fixture.
            # Docker user-namespace remapping must be able to traverse the
            # read-only bind mount; no enrolled Host secrets are included.
            base.chmod(0o755)
            path=base/'wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js'
            path.parent.mkdir(parents=True)
            path.write_text('window.sourceBridgeFixture = 1;\n')
            cmd=['docker','run','--rm','--pull=never','--network','none','--read-only',
                '--cap-drop=ALL','--security-opt=no-new-privileges',
                '-v',str(ROOT)+':/work:ro','-v',str(base)+':/fixture:ro',
                '--entrypoint','php',os.environ.get('MAD4B_TEST_PHP_IMAGE','php:8.5-cli'),
                '/work/tests/native-source-central-bridge-v21.php']
            p=subprocess.run(cmd,text=True,capture_output=True,timeout=30)
            self.assertEqual(p.returncode,0,p.stderr[-500:]+p.stdout[-500:])
            x=json.loads(p.stdout)
            self.assertEqual(x['read_abilities_registered'],2)
            self.assertFalse(x['source_write_ability_registered'])
            self.assertEqual(x['valid_request'],'PASS')
            self.assertEqual(x['valid_preflight'],'HOLD__DYNAMIC_PROVIDER_OR_PREIMAGE_NOT_READY')
            self.assertTrue(x['traversal_rejected'])
            self.assertTrue(x['backslash_rejected'])
            self.assertTrue(x['site_mismatch_rejected'])
            self.assertFalse(x['approval_issued'])
            self.assertFalse(x['mutation_performed'])

if __name__=='__main__':unittest.main()
