from pathlib import Path
import importlib.util
import hashlib
import tempfile
import unittest

SCRIPT=Path(__file__).resolve().parents[0]
# Prefer the local host module when testing the deployed VPS.
HOST=Path('/opt/mad4b-company-provisioning/bin/company-redis-flush-provenance-v2.py')
PORTABLE=SCRIPT.parent/'bin/company-redis-flush-provenance-v2.py'
SCRIPT=PORTABLE if PORTABLE.is_file() else HOST
spec=importlib.util.spec_from_file_location('redis_provenance_integrity',SCRIPT)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)


class HookIntegrityTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.root=Path(self.tmp.name)
        self.original=mod.INSTRUMENTATION
        fixtures={}
        for site in ('tours-dev','wayfatours'):
            folder=self.root/site
            folder.mkdir()
            vendor=folder/'object.lib.php';vendor.write_text('pinned vendor '+site)
            emitter=folder/'provenance.php';emitter.write_text('pinned emitter '+site)
            fixtures[site]={
                'wrapper':str(vendor),
                'wrapper_sha256':hashlib.sha256(vendor.read_bytes()).hexdigest(),
                'emitter':str(emitter),
                'emitter_sha256':hashlib.sha256(emitter.read_bytes()).hexdigest(),
            }
        mod.INSTRUMENTATION=fixtures

    def tearDown(self):
        mod.INSTRUMENTATION=self.original
        self.tmp.cleanup()

    def test_both_pinned_fixtures_pass_without_mutation(self):
        for site in ('tours-dev','wayfatours'):
            r=mod.instrumentation_integrity(site)
            self.assertEqual(r['state'],'PASS')
            self.assertEqual(r['components'],{'wrapper':'PASS','emitter':'PASS'})

    def test_unknown_vendor_version_alerts_without_restoring(self):
        f=Path(mod.INSTRUMENTATION['tours-dev']['wrapper'])
        f.write_text('changed vendor version')
        r=mod.instrumentation_integrity('tours-dev')
        self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
        self.assertEqual(r['components']['wrapper'],'HASH_DRIFT')
        self.assertEqual(f.read_text(),'changed vendor version')

    def test_missing_instrumentation_warns(self):
        f=Path(mod.INSTRUMENTATION['wayfatours']['emitter'])
        f.unlink()
        r=mod.instrumentation_integrity('wayfatours')
        self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
        self.assertEqual(r['components']['emitter'],'MISSING_OR_UNSAFE_FILE')

    def test_symlink_not_trusted(self):
        f=Path(mod.INSTRUMENTATION['tours-dev']['emitter'])
        target=f.with_suffix('.target')
        target.write_bytes(f.read_bytes())
        f.unlink()
        f.symlink_to(target)
        r=mod.instrumentation_integrity('tours-dev')
        self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
        self.assertEqual(r['components']['emitter'],'MISSING_OR_UNSAFE_FILE')

if __name__=='__main__':unittest.main()
