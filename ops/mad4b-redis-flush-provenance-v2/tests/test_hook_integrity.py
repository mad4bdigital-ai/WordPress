from pathlib import Path
import importlib.util
import hashlib
import tempfile
import unittest

SCRIPT=Path(__file__).resolve().parent.parent/'bin/company-redis-flush-provenance-v2.py'
spec=importlib.util.spec_from_file_location('redis_provenance_integrity',SCRIPT)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)

class HookIntegrityTests(unittest.TestCase):
    def test_both_real_hooks_present_without_mutation(self):
        for site in ('tours-dev','wayfatours'):
            r=mod.instrumentation_integrity(site)
            self.assertEqual(r['state'],'PASS')
            self.assertEqual(r['components'],{'wrapper':'PASS','emitter':'PASS'})

    def test_unknown_vendor_version_alerts_without_restoring(self):
        with tempfile.TemporaryDirectory() as d:
            f=Path(d)/'object.lib.php';f.write_text('changed vendor version')
            original=mod.INSTRUMENTATION['tours-dev']
            try:
                mod.INSTRUMENTATION['tours-dev']={
                  **original,'wrapper':str(f),'wrapper_sha256':'0'*64}
                r=mod.instrumentation_integrity('tours-dev')
                self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
                self.assertEqual(r['components']['wrapper'],'HASH_DRIFT')
                self.assertEqual(f.read_text(),'changed vendor version')
            finally:mod.INSTRUMENTATION['tours-dev']=original

    def test_missing_instrumentation_warns(self):
        original=mod.INSTRUMENTATION['wayfatours']
        try:
            mod.INSTRUMENTATION['wayfatours']={
              **original,'emitter':'/does/not/exist/mad4b-provenance.php'}
            r=mod.instrumentation_integrity('wayfatours')
            self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
            self.assertEqual(r['components']['emitter'],'MISSING_OR_UNSAFE_FILE')
        finally:mod.INSTRUMENTATION['wayfatours']=original

    def test_symlink_not_trusted(self):
        with tempfile.TemporaryDirectory() as d:
            original=mod.INSTRUMENTATION['tours-dev']
            link=Path(d)/'emitter.php';link.symlink_to(original['emitter'])
            try:
                mod.INSTRUMENTATION['tours-dev']={**original,'emitter':str(link)}
                r=mod.instrumentation_integrity('tours-dev')
                self.assertEqual(r['state'],'PROVENANCE_HOOK_DRIFT_ALERT')
                self.assertEqual(r['components']['emitter'],'MISSING_OR_UNSAFE_FILE')
            finally:mod.INSTRUMENTATION['tours-dev']=original
if __name__=='__main__':unittest.main()
