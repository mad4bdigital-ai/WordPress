"""Backup route failure/recovery tests: all work on isolated temporary files."""
import io
import tempfile
import tarfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import unittest
import central_staging_backup as b

class BackupRouteTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.out=Path(self.tmp.name)/"backup.sql"
        self.expected=b"".join(("CREATE TABLE "+chr(96)+t+chr(96)+" (id INTEGER);\n").encode() for t in b.TABLES)
    def test_missing_wp_uses_mariadb(self):
        calls=[]
        def fake(cmd,**kw):
            calls.append(cmd)
            kw["stdout"].write(self.expected)
            return SimpleNamespace(returncode=0)
        with patch.object(b.subprocess,"run",side_effect=fake):
            lane=b.export_sql(self.out,{"wp_mysqldump":False,"db_mariadb_dump":True})
        self.assertEqual(lane,"native_mariadb_dump")
        self.assertIn("mariadb-dump",calls[0][-1])
    def test_wp_failure_uses_mariadb_fallback(self):
        calls=[]
        def fake(cmd,**kw):
            calls.append(cmd)
            kw["stdout"].write(b"partial" if len(calls)==1 else self.expected)
            return SimpleNamespace(returncode=1 if len(calls)==1 else 0)
        with patch.object(b.subprocess,"run",side_effect=fake):
            lane=b.export_sql(self.out,{"wp_mysqldump":True,"db_mariadb_dump":True})
        self.assertEqual(lane,"native_mariadb_dump")
        self.assertEqual(self.out.read_bytes(),self.expected)
        self.assertEqual(len(calls),2)
    def test_healthy_wp_preferred(self):
        calls=[]
        def fake(cmd,**kw):
            calls.append(cmd)
            kw["stdout"].write(self.expected)
            return SimpleNamespace(returncode=0)
        with patch.object(b.subprocess,"run",side_effect=fake):
            lane=b.export_sql(self.out,{"wp_mysqldump":True,"db_mariadb_dump":True})
        self.assertEqual(lane,"wordpress_wpcli_mysqldump")
        self.assertEqual(len(calls),1)
    def test_no_db_lanes_fails_closed(self):
        with self.assertRaisesRegex(RuntimeError,"BOTH_DATABASE_DUMP_ROUTES_UNAVAILABLE"):
            b.export_sql(self.out,{"wp_mysqldump":False,"db_mariadb_dump":False})
    def test_invalid_dump_deleted(self):
        def fake(cmd,**kw):
            kw["stdout"].write(b"bad")
            return SimpleNamespace(returncode=0)
        with patch.object(b.subprocess,"run",side_effect=fake):
            with self.assertRaisesRegex(RuntimeError,"ALL_DUMP_ROUTES_FAILED"):
                b.export_sql(self.out,{"wp_mysqldump":False,"db_mariadb_dump":True})
        self.assertFalse(self.out.exists())
    def test_docker_cp_preferred(self):
        folder=Path(self.tmp.name)
        (folder/b.PLUGIN).mkdir()
        with patch.object(b.subprocess,"run",return_value=SimpleNamespace(returncode=0)):
            result,lane=b.snapshot_plugin(folder,{"php_tar":True})
        self.assertEqual((result,lane),(folder/b.PLUGIN,"docker_cp"))
    def test_tar_fallback(self):
        calls=[]
        def fake(cmd,**kw):
            calls.append(cmd)
            if len(calls)==1:return SimpleNamespace(returncode=1)
            with tarfile.open(fileobj=kw["stdout"],mode="w") as tar:
                for i in range(901):
                    tar.addfile(tarfile.TarInfo(b.PLUGIN+"/file"+str(i)),io.BytesIO())
            return SimpleNamespace(returncode=0)
        with patch.object(b.subprocess,"run",side_effect=fake):
            result,lane=b.snapshot_plugin(Path(self.tmp.name),{"php_tar":True})
        self.assertEqual(lane,"docker_exec_tar")
        self.assertTrue(result.is_file())
    def test_no_tar_fails_closed(self):
        with patch.object(b.subprocess,"run",return_value=SimpleNamespace(returncode=1)):
            with self.assertRaisesRegex(RuntimeError,"BOTH_PLUGIN_SNAPSHOT_ROUTES_UNAVAILABLE"):
                b.snapshot_plugin(Path(self.tmp.name),{"php_tar":False})

if __name__=="__main__":
    unittest.main()
