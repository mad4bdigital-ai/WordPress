import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

PATH=Path(__file__).resolve().parent.parent/'bin/company-redis-flush-guard-reconcile-v1.py'
spec=importlib.util.spec_from_file_location('guard',PATH)
guard=importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)
data=(PATH.parent/'mad4b-property-type-fix-scoped.php').read_bytes()
original=(Path(__file__).resolve().parent/'fixtures/legacy-property-cache-script.php').read_bytes()

class ToolResponse:
    def __init__(self,returncode=0,stdout=''):
        self.returncode=returncode
        self.stdout=stdout
        self.stderr=''

class CacheGuardTest(unittest.TestCase):
    def setUp(self):
        self.orig=guard.call
        self.site={'container':'php-wkgtkzta35wkkgouwt6ruljc',
           'runtime_path':'/tmp/mad4b-property-type-fix.php',
           'original_sha256':guard.sha(original),
           'approved_sha256':guard.sha(data)}
    def tearDown(self):
        guard.call=self.orig
    def test_audit_accepts_approved_without_writing(self):
        def fake(args,seconds=14):
            if args[0:2]==['docker','exec'] and (args[3]=='sh' or 'sha256sum' in args):
                return ToolResponse(stdout=guard.sha(data)+'  /tmp/name\n')
            if args[0:2]==['docker','exec'] and 'php' in args:
                return ToolResponse(stdout='No syntax errors\n')
            raise AssertionError('unexpected action: '+str(args))
        guard.call=fake
        x=guard.assess_site('test',self.site,data,False)
        self.assertEqual(x['state'],'PASS')
        self.assertEqual(x['action'],'NONE')
    def test_old_known_script_may_be_repaired_but_only_in_apply_mode(self):
        calls=[]
        def fake(args,seconds=14):
            calls.append(args)
            if args[0:2]==['docker','exec'] and (args[3]=='sh' or 'sha256sum' in args):
                return ToolResponse(stdout=guard.sha(original)+'  /tmp/name\n')
            if args[0]=='pgrep':return ToolResponse(returncode=1)
            return ToolResponse()
        guard.call=fake
        x=guard.assess_site('test',self.site,data,False)
        self.assertEqual(x['state'],'DRIFT')
        self.assertEqual(x['action'],'AUDIT_ONLY')
        self.assertFalse(any('cp' in c for c in calls))
    def test_unknown_change_refused(self):
        def fake(args,seconds=14):
            return ToolResponse(stdout='1'*64+' /tmp/name\n')
        guard.call=fake
        x=guard.assess_site('test',self.site,data,True)
        self.assertEqual(x['state'],'DRIFT')
        self.assertEqual(x['action'],'UNKNOWN_CHANGE_REFUSED')
    def test_active_wpcli_defers_restore(self):
        def fake(args,seconds=14):
            if args[0]=='pgrep':return ToolResponse(stdout='999 wp eval-file /tmp/mad4b-property-type-fix.php\n')
            return ToolResponse(stdout=guard.sha(original)+' /tmp/name\n')
        guard.call=fake
        x=guard.assess_site('test',self.site,data,True)
        self.assertEqual(x['state'],'DEFERRED')
        self.assertEqual(x['action'],'CONCURRENT_WPCLI_RUNNING')
    def test_wrong_site_container_refused(self):
        s=self.site|{'container':'php-bad;rm -rf'}
        self.assertRaises(ValueError,guard.assess_site,'test',s,data,True)
    def test_pinning_refuses_modified_approved_code(self):
        self.assertRaises(ValueError,guard.assess_site,'test',self.site,b'not the approved code',True)

if __name__=='__main__':
    unittest.main()
