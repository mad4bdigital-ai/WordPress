"""Immutable source package: no production writes, no test scripts, no authority."""
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / "versioned_plugin_artifact.py"
spec = importlib.util.spec_from_file_location("plugin_package_contract", SOURCE)
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class VersionedSourceArtifactTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.source = self.home / "source"
        self.source.mkdir()
        self.installed = self.home / "www/wp-content/plugins/example-module"
        self.installed.mkdir(parents=True)
        (self.installed / "example-module.php").write_text("<?php echo 1;")
        def git(*args):
            return subprocess.check_output(["git", "-C", str(self.source), *args],
                                           stderr=subprocess.DEVNULL).decode().strip()
        self.git = git
        git("init", "-q")
        git("config", "user.email", "review@example.invalid")
        git("config", "user.name", "Review")
        self.plugin = self.source / "wp-content/plugins/example-module"
        self.plugin.mkdir(parents=True)
        (self.plugin / "example-module.php").write_text("<?php echo 2;")
        (self.plugin / "includes").mkdir()
        (self.plugin / "includes/class-a.php").write_text("<?php echo 3;")
        (self.plugin / "tests").mkdir()
        (self.plugin / "tests/debug.php").write_text("<?php print_r($_SERVER);")
        git("add", ".")
        git("commit", "-qm", "review")
        self.commit = git("rev-parse", "HEAD")

    def candidate(self):
        return package.plan(self.source, self.commit, "example-module",
                            self.home / "www")

    def test_immutable_package_excludes_publicly_executable_test_scripts(self):
        doc, files = self.candidate()
        self.assertEqual(len(doc["source_runtime_files"]), 2)
        self.assertEqual(doc["source_excluded_paths"], ["tests/debug.php"])
        self.assertEqual(doc["review"], {
            "preserved": 0, "revised": 1, "new": 1, "removed": 0})
        payload = package.archive("example-module", files)
        with tarfile.open(fileobj=io.BytesIO(payload)) as tar:
            names = tar.getnames()
            self.assertNotIn("example-module/tests/debug.php", names)
            self.assertIn("example-module/example-module.php", names)
            self.assertEqual(tar.extractfile(
                "example-module/example-module.php").read(), b"<?php echo 2;")
            for row in tar.getmembers():
                self.assertEqual(row.uid, 0)
                self.assertEqual(row.gid, 0)
                self.assertEqual(row.mtime, 0)
                self.assertFalse(row.issym() or row.islnk())
        self.assertEqual(payload, package.archive("example-module", files))
        self.assertFalse(doc["source_publisher_enrolled_by_this_artifact"])
        self.assertFalse(doc["coherent_activation_proven"])

    def test_removed_live_file_is_a_hold_not_an_auto_delete(self):
        (self.installed / "tenant-override.php").write_text("local owner")
        with self.assertRaisesRegex(package.PackageDenied,
                                    "LIVE_PLUGIN_FILE_REMOVAL_UNRECONCILED"):
            self.candidate()
        self.assertTrue((self.installed / "tenant-override.php").exists())

    def test_new_plugin_source_can_be_prepared_but_never_self_authorized(self):
        import shutil
        shutil.rmtree(self.installed)
        document, files=self.candidate()
        self.assertEqual(document["review"]["new"],2)
        self.assertTrue(document["new_install_requires_original_authority"])
        self.assertFalse(document["live_root_provenance_ready"])
        self.assertEqual(document["state"],
                         "SOURCE_ARTIFACT_REVIEW_ONLY_NOT_DEPLOYABLE")
        self.assertFalse(self.installed.exists())

    def test_source_symlink_and_nonregular_are_rejected(self):
        (self.plugin / "includes/unsafe.php").symlink_to(
            "../example-module.php")
        self.git("add", ".")
        self.git("commit", "-qm", "symlink")
        self.commit = self.git("rev-parse", "HEAD")
        with self.assertRaisesRegex(package.PackageDenied,
                                    "SOURCE_NONREGULAR_OR_SYMLINK_DENIED"):
            self.candidate()

    def test_revision_or_plugin_path_cannot_select_arbitrary_host_content(self):
        with self.assertRaisesRegex(package.PackageDenied,
                                    "IMMUTABLE_COMMIT_INVALID"):
            package.inventory_commit(self.source, "--all", "example-module")
        with self.assertRaisesRegex(package.PackageDenied,
                                    "PLUGIN_SLUG_INVALID"):
            package.inventory_commit(self.source, self.commit, "../unsafe")
        with self.assertRaisesRegex(package.PackageDenied,
                                    "SOURCE_PATH_INVALID"):
            package.relative_path("../../wp-config.php")

    def test_baseline_not_rewritten_and_snapshot_pins_reviewed_file_hashes(self):
        baseline=(self.installed/"example-module.php").read_bytes()
        document, files=self.candidate()
        self.assertEqual((self.installed/"example-module.php").read_bytes(),
                         baseline)
        self.assertEqual(document["live_preimage"][0]["sha256"],
                         package.digest(baseline))
        self.assertEqual(document["manifest_sha256"],
                         package.digest(package.canonical({
                            k:v for k,v in document.items()
                            if k!="manifest_sha256"})))
        self.assertGreater(len(package.archive("example-module",files)),0)

    @unittest.skipUnless(os.geteuid()==0,
                         "Root-private artifact writing requires original Host")
    def test_root_private_artifact_is_new_only_and_no_live_write(self):
        document, files=self.candidate()
        output=self.home/"root-private"
        output.mkdir(mode=0o700)
        output.chmod(0o700)
        package.write_private_new(output/"candidate.tar",
                                  package.archive("example-module",files))
        self.assertEqual((output/"candidate.tar").stat().st_mode & 0o777,0o600)
        with self.assertRaisesRegex(package.PackageDenied,
                                    "ARTIFACT_ALREADY_EXISTS"):
            package.write_private_new(output/"candidate.tar",b"not allowed")
        self.assertEqual((self.installed/"example-module.php").read_text(),
                         "<?php echo 1;")
        output.chmod(0o755)
        with self.assertRaisesRegex(package.PackageDenied,
                                    "ARTIFACT_ROOT_PRIVATE_DIRECTORY_REQUIRED"):
            package.write_private_new(output/"other.tar",b"not allowed")


if __name__ == "__main__":
    unittest.main()
