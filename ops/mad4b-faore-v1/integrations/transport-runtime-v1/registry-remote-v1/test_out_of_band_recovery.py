import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

HERE=Path(__file__).parent
spec=importlib.util.spec_from_file_location("out_of_band_registry",HERE/"registry_remote.py")
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class NativeOutOfBandRecoveryTests(unittest.TestCase):
    def test_doctor_script_and_recovery_script_are_executable_python(self):
        compile(m.DOCTOR_SCRIPT,"doctor","exec")
        compile(m.REPAIR_SCRIPT,"transaction","exec")

    def test_doctor_validates_registered_staging_scope(self):
        route="hosting-3:staging"
        report={"contract":"mad4b.cross-ssh-out-of-band-doctor.v1",
          "state":"CONTROL_PLANE_BOOT_FAILED","identity_verified":True,
          "target_site_uuid_expected":m.TARGETS[route]["site_uuid"],
          "canonical_origin":m.ORIGINS[route],"mutation_performed":False,
          "write_authorized":False}
        with patch.object(m,"guard",return_value=m.TARGETS[route]["root"]),\
             patch.object(m,"remote",return_value=json.dumps(report)) as call:
            result=m.doctor(route)
        self.assertEqual(result["target"],route)
        self.assertEqual(result["state"],"CONTROL_PLANE_BOOT_FAILED")
        command=call.call_args.args[1]
        self.assertIn("--skip-plugins",m.DOCTOR_SCRIPT)
        self.assertNotIn("--user=1",command)

    def test_doctor_rejects_forged_host_site_contract(self):
        route="hosting-3:staging"
        report={"contract":"mad4b.cross-ssh-out-of-band-doctor.v1",
          "target_site_uuid_expected":"wrong","canonical_origin":m.ORIGINS[route],
          "mutation_performed":False,"write_authorized":False}
        with patch.object(m,"guard",return_value=m.TARGETS[route]["root"]),\
             patch.object(m,"remote",return_value=json.dumps(report)):
            with self.assertRaisesRegex(ValueError,"DOCTOR_READBACK_CONTRACT_INVALID"):
                m.doctor(route)

    def test_doctor_runs_when_control_plane_and_adapter_both_fail_to_load(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)
            binroot=root/"bin"
            binroot.mkdir()
            wp=binroot/"wp"
            wp.write_text("""#!/bin/sh
case " $* " in
 *--skip-plugins*) echo 'MAD4B_RECOVERY_ID:{"siteurl":"https://staging.egypttourgates.com","home":"https://staging.egypttourgates.com","site_uuid":"d745d81f-6fc4-5c6a-99dd-d953c92137bf","environment":"staging","wp_environment":"staging"}'; exit 0;;
 *) exit 1;;
esac
""")
            wp.chmod(0o700)
            process=subprocess.run([sys.executable,"-c",m.DOCTOR_SCRIPT,
                 str(root),"d745d81f-6fc4-5c6a-99dd-d953c92137bf",
                 "https://staging.egypttourgates.com"],
                 capture_output=True,text=True,timeout=15,
                 env={**os.environ,"PATH":str(binroot)+":"+os.environ.get("PATH","")})
            self.assertEqual(process.returncode,0,process.stderr)
            report=json.loads(process.stdout)
            self.assertTrue(report["identity_verified"])
            self.assertTrue(report["wpcli_without_plugins"])
            self.assertFalse(report["control_plane_boot_verified"])
            self.assertFalse(report["write_authorized"])

    def _transaction(self, root, backup_root, staged,old,new,boot=True):
        binroot=root/"bin"
        binroot.mkdir(exist_ok=True)
        wp=binroot/"wp"
        wp.write_text("#!/bin/sh\n" + (
          "echo 'MAD4B_RECOVERY_BOOT:1'\n" if boot else "exit 1\n"))
        wp.chmod(0o700)
        php=binroot/"php"
        php.write_text("#!/bin/sh\nexit 0\n")
        php.chmod(0o700)
        rel="includes/class-mad4b-scp-test.php"
        cmd=[sys.executable,"-c",m.REPAIR_SCRIPT,str(root),
             "mad4b-site-control-plane",rel,
             hashlib.sha256(old).hexdigest(),hashlib.sha256(new).hexdigest(),
             str(staged),str(backup_root)]
        return subprocess.run(cmd,capture_output=True,text=True,timeout=15,
           env={**os.environ,"PATH":str(binroot)+":"+os.environ.get("PATH","")})

    def _setup(self,tmp):
        root=Path(tmp)
        target=root/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-test.php"
        target.parent.mkdir(parents=True)
        old=b"<?php echo 'before';\n"
        new=b"<?php echo 'after';\n"
        target.write_bytes(old)
        staged=root/"inbound"
        staged.write_bytes(new)
        return root,target,staged,old,new

    def test_exact_file_repair_has_external_backup_and_readback(self):
        with tempfile.TemporaryDirectory() as folder:
            root,target,staged,old,new=self._setup(folder)
            result=self._transaction(root,root/"privatebackup",staged,old,new)
            self.assertEqual(result.returncode,0,result.stderr)
            receipt=json.loads(result.stdout)
            self.assertEqual(receipt["state"],"EXACT_STAGING_FILE_REPLACED_AND_BOOT_VERIFIED")
            self.assertEqual(target.read_bytes(),new)
            self.assertEqual(Path(receipt["rollback_path"]).read_bytes(),old)
            self.assertFalse(staged.exists())
            self.assertFalse(receipt["grant_issued"])

    def test_failed_wordpress_boot_rolls_back_original_bytes(self):
        with tempfile.TemporaryDirectory() as folder:
            root,target,staged,old,new=self._setup(folder)
            result=self._transaction(root,root/"privatebackup",staged,old,new,boot=False)
            self.assertEqual(result.returncode,0,result.stderr)
            receipt=json.loads(result.stdout)
            self.assertEqual(receipt["state"],"REPAIR_REJECTED_AUTOMATIC_ROLLBACK_VERIFIED")
            self.assertEqual(target.read_bytes(),old)

    def test_mismatched_current_bytes_never_overwrite(self):
        with tempfile.TemporaryDirectory() as folder:
            root,target,staged,old,new=self._setup(folder)
            result=self._transaction(root,root/"privatebackup",staged,b"bad old",new)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(target.read_bytes(),old)

    def test_symlink_target_never_overwrites_its_destination(self):
        with tempfile.TemporaryDirectory() as folder:
            root,target,staged,old,new=self._setup(folder)
            external=root/"external.php"
            external.write_bytes(old)
            target.unlink()
            target.symlink_to(external)
            result=self._transaction(root,root/"privatebackup",staged,old,new)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(external.read_bytes(),old)

    def test_unreviewed_relative_paths_rejected(self):
        for path in ("../../wp-config.php","wp-config.php",
                     "includes/../../wp-config.php","uploads/image.php"):
            self.assertIsNone(m.RECOVERY_RELATIVE.fullmatch(path))


if __name__=="__main__":
    unittest.main()
