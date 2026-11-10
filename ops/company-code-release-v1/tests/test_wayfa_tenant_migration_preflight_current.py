"""Reject stale/mis-scoped SQL schema reports for a signed migration."""
import importlib.util,datetime as dt,hashlib,json,tempfile
from pathlib import Path
import unittest
from unittest.mock import patch

PATH=Path(__file__).resolve().parents[1]/'integrations/wayfa_tenant_migration_preflight_current.py'
sp=importlib.util.spec_from_file_location('company_tenant_preflight_current',PATH)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
LIVE=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110/wayfa-native-schema-current-sql-readback.json')

class CurrentPreflightTests(unittest.TestCase):
    def build(self,root:Path,**alter):
        now=dt.datetime.now(dt.timezone.utc).isoformat()
        readiness={'contract':'mad4b.company-tenant-approved-content-production-preflight.v1',
                   'observed_utc':now,'environment':'production','read_only':True,'writes_executed':False}
        (root/'wayfa-tenant-readiness.json').write_text(json.dumps(readiness))
        report=json.loads(LIVE.read_text())
        report['observed_utc']=now
        report.update(alter)
        (root/'wayfa-native-schema-current-sql-readback.json').write_text(json.dumps(report))
        (root/'wayfa-native-schema-current-sql-readback.json').chmod(0o600)
        return report

    def call(self,root):
        def fake(args,timeout=20):
            name=args[-1].split('/')[-1]
            return hashlib.sha256((m.SRC/name).read_bytes()).hexdigest()+' '+args[-1]
        with patch.object(m,'ROOT',root),patch.object(m,'OUT',root/'preview.json'),\
             patch.object(m,'RESTORE_PROOF',root/'missing-proof.json'),\
             patch.object(m,'BACKUPS',()),patch.object(m,'run',side_effect=fake):
            m.inspect()
        return json.loads((root/'preview.json').read_text())

    def test_fresh_readback_drives_exact_four_table_state(self):
        with tempfile.TemporaryDirectory() as d:
            r=Path(d);self.build(r)
            result=self.call(r)
            self.assertEqual(result['schema_tables'],{'approvals':False,'cache':False,'maps':False,'overrides':False})
            self.assertTrue(result['read_only'])
            self.assertFalse(result['database_modified'])
            self.assertFalse(result['schema_migration_authorized'])
            self.assertIn('ORIGINAL_SIGNED_SCHEMA_MIGRATION_GRANT_NOT_PROVEN',result['blockers'])

    def test_expired_schema_report_never_reused(self):
        with tempfile.TemporaryDirectory() as d:
            r=Path(d);old=(dt.datetime.now(dt.timezone.utc)-dt.timedelta(hours=1)).isoformat()
            self.build(r,observed_utc=old)
            with self.assertRaisesRegex(RuntimeError,'CURRENT_SCHEMA_READBACK_STALE'):
                self.call(r)

    def test_changed_target_name_or_flag_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            r=Path(d);row=self.build(r)
            row['tables']['approvals']['name']='wp_unrelated'
            (r/'wayfa-native-schema-current-sql-readback.json').write_text(json.dumps(row))
            with self.assertRaisesRegex(RuntimeError,'CURRENT_SCHEMA_TABLE_SCOPE_DRIFT'):
                self.call(r)
        with tempfile.TemporaryDirectory() as d:
            r=Path(d);self.build(r,sql_write_executed=True)
            with self.assertRaisesRegex(RuntimeError,'CURRENT_SCHEMA_READBACK_UNTRUSTED'):
                self.call(r)

if __name__=='__main__':unittest.main()
