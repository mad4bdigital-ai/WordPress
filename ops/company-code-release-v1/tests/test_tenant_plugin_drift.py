"""Read-only, dynamic inventory catches tenant-only files and newer code."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

SOURCE=Path(__file__).resolve().parents[1]/"tenant_plugin_drift.py"
spec=importlib.util.spec_from_file_location("mad4b_tenant_plugin_drift_test",SOURCE)
audit=importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class TenantDriftTests(unittest.TestCase):
    def test_snapshot_does_not_relabel_newer_tenant_code(self):
        with tempfile.TemporaryDirectory() as tmp:
            source=Path(tmp)/"src"
            tenant=Path(tmp)/"tenant"
            for root in (source,tenant):
                files=root/"wp-content/plugins"/"example-module"
                files.mkdir(parents=True)
                (files/"shared.php").write_text("old\n")
            (tenant/"wp-content/plugins/example-module/shared.php").write_text(
                "new native authority\n")
            (tenant/"wp-content/plugins/example-module/authority.php").write_text(
                "native authority\n")
            report=audit.reconcile(source,tenant,"example-module")
            self.assertEqual(report["source_file_count"],1)
            self.assertEqual(report["tenant_file_count"],2)
            self.assertEqual(len(report["tenant_only"]),1)
            self.assertEqual(len(report["changed"]),1)
            self.assertFalse(report["safe_to_overwrite_tenant"])
            self.assertFalse(report["production_mutated"])
            with self.assertRaisesRegex(ValueError,"ID_INVALID"):
                audit.reconcile(source,tenant,"../outside")

    def test_symlinked_component_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            plugin=root/"wp-content/plugins"/"example-module"
            plugin.mkdir(parents=True)
            (plugin/"bad.php").symlink_to("/etc/hosts")
            with self.assertRaisesRegex(ValueError,"SYMLINK_DENIED"):
                audit.inventory(root,"example-module")


if __name__=="__main__":
    unittest.main()
