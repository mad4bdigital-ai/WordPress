import importlib.util
from pathlib import Path
import tempfile
import unittest

MOD=Path(__file__).resolve().parents[1]/"runtime_topology.py"
spec=importlib.util.spec_from_file_location("mad4b_runtime_topology_test",MOD)
lib=importlib.util.module_from_spec(spec)
spec.loader.exec_module(lib)


class TopologyTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        (self.root/"wp-content/plugins/demo-plugin").mkdir(parents=True)
        (self.root/"wp-content/themes/demo-theme").mkdir(parents=True)
        (self.root/"wp-content/mu-plugins").mkdir(parents=True)
        (self.root/"wp-content/mu-plugins/demo.php").write_text("<?php")
        (self.root/"wp-load.php").write_text("<?php")
        self.current=lib.code_inventory(self.root)
        self.plan={
          "contract":lib.runtime.CONTRACT,
          "site":{"company":"site-x","environment":"production",
                  "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
                  "origin":"https://wayfatours.com"},
          "base_image_id":"sha256:"+"a"*64,
          "layers":[{"plugin":"demo-plugin"}],
          "source_publisher_authorized":False,
          "original312_signed":False,
          "original313_finalized":False,
          "cutover_verified":False}
        self.plan["runtime_plan_sha256"]=lib.runtime.sha(
            lib.runtime.canonical(self.plan))
        self.mounts=[{"Destination":"/var/www/html","RW":True,"Type":"bind"},
                     {"Destination":"/run/secrets/fake","RW":False,"Type":"bind"}]

    def check(self,**changes):
        args={"current":self.current,
              "live_mounts":self.mounts,
              "runtime_plan":self.plan,
              "candidate_core_in_target_root":False,
              "candidate_wordpress_config_external":False,
              "proposed_writable_data_mounts":[]}
        args.update(changes)
        return lib.audit(**args)

    def test_real_site_coverage_is_held_even_when_one_plugin_matches(self):
        r=self.check()
        self.assertIn("LIVE_WORDPRESS_CODE_ROOT_WRITABLE_BIND",r["legacy_risks"])
        self.assertNotIn("LIVE_WORDPRESS_CODE_ROOT_WRITABLE_BIND",r["blockers"])
        self.assertIn("CANDIDATE_CORE_NOT_IN_WORDPRESS_RUNTIME_PATH",r["blockers"])
        self.assertIn("THEME_CODE_LAYERS_NOT_DECLARED",r["blockers"])
        self.assertIn("MU_PLUGIN_CODE_LAYERS_NOT_DECLARED",r["blockers"])
        self.assertEqual(r["uncovered_installed_plugin_count"],0)
        self.assertNotIn("source",str(r["live_mounts_redacted"]))
        self.assertFalse(r["site_files_modified"])

    def test_plugin_coverage_unmatched_blocks(self):
        self.plan["layers"]=[]
        self.plan["runtime_plan_sha256"]=lib.runtime.sha(lib.runtime.canonical({
            k:v for k,v in self.plan.items() if k!="runtime_plan_sha256"}))
        r=self.check()
        self.assertIn("FULL_INSTALLED_PLUGIN_CODE_COVERAGE_MISSING",r["blockers"])
        self.assertEqual(r["uncovered_installed_plugin_count"],1)

    def test_tampered_runtime_plan_cannot_claim_plugin_coverage(self):
        self.plan["layers"].append({"plugin":"unsigned-plugin"})
        with self.assertRaisesRegex(lib.TopologyHold,"PLAN_UNVERIFIED"):
            self.check()

    def test_mutable_code_root_not_valid_data_mount(self):
        with self.assertRaisesRegex(lib.TopologyHold,"NOT_CHILD_OF_CODE_ROOT"):
            self.check(proposed_writable_data_mounts=["/var/www/html"])
        with self.assertRaisesRegex(lib.TopologyHold,"MOUNTS_OVERLAP"):
            self.check(proposed_writable_data_mounts=[
                "/var/www/html/wp-content/uploads",
                "/var/www/html/wp-content/uploads/foo"])

    def test_external_secret_mount_not_exported_to_git(self):
        r=self.check(proposed_writable_data_mounts=[
            "/var/www/html/wp-content/uploads"])
        self.assertEqual(r["proposed_writable_data_destinations"],
                         ["/var/www/html/wp-content/uploads"])
        self.assertIn("ORIGINAL_NATIVE_SIGNED_RUNTIME_ACTIVATION_REQUIRED",
                      r["original_native_requirements"])

    def test_legacy_writable_code_does_not_deadlock_immutable_migration(self):
        self.current["families"]["themes"]=[]
        self.current["families"]["mu-plugins"]=[]
        data="/var/www/html/wp-content/uploads"
        result=self.check(
            candidate_core_in_target_root=True,
            candidate_wordpress_config_external=True,
            candidate_code_root_protected=True,
            proposed_writable_data_mounts=[data],
            candidate_mounts=[{
                "Destination":data,"RW":True,"Type":"volume",
                "source_fingerprint_sha256":"a"*64,
                "data_migration_receipt_sha256":"b"*64}])
        self.assertEqual(result["blockers"],[])
        self.assertEqual(result["state"],
            "CANDIDATE_STRUCTURALLY_READY_AWAITING_ORIGINAL_NATIVE_AUTHORITY")
        self.assertIn("LIVE_WORDPRESS_CODE_ROOT_WRITABLE_BIND",
                      result["legacy_risks"])
        self.assertFalse(result["host_traffic_modified"])
        self.assertIn("ORIGINAL312_EXACT_SCOPE_GRANT_REQUIRED",
                      result["original_native_requirements"])

    def test_unproven_or_ephemeral_mutable_data_volume_must_block(self):
        data="/var/www/html/wp-content/uploads"
        tmpfs=self.check(proposed_writable_data_mounts=[data],
              candidate_mounts=[{"Destination":data,"RW":True,"Type":"tmpfs"}])
        self.assertIn("CANDIDATE_DATA_MOUNT_NOT_DURABLE_WRITABLE_STORAGE",
                      tmpfs["blockers"])
        self.assertIn("CANDIDATE_DATA_VOLUME_IDENTITY_NOT_PINNED",
                      tmpfs["blockers"])

    def test_new_candidate_cannot_shadow_immutable_code_root(self):
        result=self.check(
            candidate_mounts=[{"Destination":"/var/www/html",
                               "RW":False,"Type":"bind"}])
        self.assertIn("CANDIDATE_IMAGE_CODE_ROOT_SHADOWED_BY_BIND",
                      result["blockers"])
        result=self.check(
            candidate_mounts=[{"Destination":"/var/www/html/wp-content/plugins",
                               "RW":True,"Type":"bind"}])
        self.assertIn("CANDIDATE_UNDECLARED_WRITABLE_CODE_MOUNT",
                      result["blockers"])

    def test_single_file_plugin_and_mu_subdir_are_not_silently_ignored(self):
        (self.root/"wp-content/plugins/single-plugin.php").write_text("<?php")
        (self.root/"wp-content/mu-plugins/extensions").mkdir()
        (self.root/"wp-content/themes/symlink-theme").symlink_to("/tmp")
        observed=lib.code_inventory(self.root)
        self.assertIn("single-plugin.php",observed["families"]["plugins"])
        self.assertIn("extensions",observed["families"]["mu-plugins"])
        self.assertEqual(observed["unreviewed_code_symlink_count"],1)
        r=self.check(current=observed)
        self.assertIn("LIVE_CODE_SYMLINKS_REQUIRE_EXPLICIT_PROVENANCE",r["blockers"])

    def test_symlinked_live_code_path_rejected(self):
        (self.root/"wp-load.php").unlink()
        (self.root/"wp-load.php").symlink_to("/etc/passwd")
        with self.assertRaisesRegex(lib.TopologyHold,"CORE_FILE_MISSING"):
            lib.code_inventory(self.root)


if __name__=="__main__":
    unittest.main()
