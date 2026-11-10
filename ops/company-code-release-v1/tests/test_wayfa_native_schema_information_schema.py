"""Strictly read-only current SQL schema probe. No WordPress or DDL calls."""
import importlib.util
from pathlib import Path
from types import SimpleNamespace
import unittest

SCRIPT=Path(__file__).resolve().parents[1]/'integrations/wayfa_native_schema_information_schema.py'
sp=importlib.util.spec_from_file_location('wayfa_live_schema_readback',SCRIPT)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)

class SchemaReadbackTests(unittest.TestCase):
    def test_missing_four_tables(self):
        row=m.interpret('')
        self.assertEqual(set(row['tables']),{'approvals','maps','overrides','cache'})
        self.assertTrue(all(v['exists'] is False for v in row['tables'].values()))
        self.assertFalse(row['sql_write_executed'])
        self.assertFalse(row['schema_created'])

    def test_existence_not_inferred_from_report_but_mariadb_output(self):
        row=m.interpret('wp_mad4b_tenant_approvals\tInnoDB\tutf8mb4_unicode_ci\n')
        self.assertTrue(row['tables']['approvals']['exists'])
        self.assertFalse(row['tables']['maps']['exists'])
        self.assertEqual(row['tables']['approvals']['engine'],'InnoDB')

    def test_unknown_table_or_duplicate_denied(self):
        for value in ('wp_unrelated\tInnoDB\tutf8mb4_unicode_ci',
                      'wp_mad4b_tenant_approvals\tInnoDB\tutf8mb4_unicode_ci\n'*2,
                      'wp_mad4b_tenant_approvals\tbad-value!\tutf8mb4_unicode_ci'):
            with self.subTest(value=value),self.assertRaises(m.Denied):m.interpret(value)

    def test_database_scope_explicit_and_query_only(self):
        calls=[]
        def run(args,**kw):
            calls.append((args,kw))
            return SimpleNamespace(returncode=0,stdout='',stderr='')
        row=m.collect(runner=run)
        self.assertEqual(row['schema_name'],'wayfa_rebuild_wp')
        self.assertEqual(len(calls),1)
        cmd=' '.join(calls[0][0])
        self.assertIn('MARIADB_DATABASE',cmd)
        self.assertIn('information_schema.tables',cmd)
        self.assertNotIn('CREATE TABLE',cmd)
        self.assertNotIn('ALTER TABLE',cmd)
        self.assertNotIn('DROP TABLE',cmd)

    def test_nonzero_exit_never_claims_current_schema(self):
        def fail(args,**kw):return SimpleNamespace(returncode=1,stdout='',stderr='')
        with self.assertRaisesRegex(m.Denied,'ORIGINAL_DATABASE_SCHEMA_READBACK_UNAVAILABLE'):
            m.collect(runner=fail)

if __name__=='__main__':unittest.main()
