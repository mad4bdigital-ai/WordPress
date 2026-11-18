#!/usr/bin/env python3
"""Hermetic QA31 regression: stale build manifest blocks release, not diagnostics."""
from pathlib import Path
import hashlib
import json
import os
import subprocess
import sys
import tempfile

SCRIPT = Path(__file__).resolve().with_name("qa31_source_reconciliation.py")


def run(*cmd, cwd=None):
    return subprocess.run(cmd, cwd=cwd, text=True, capture_output=True, timeout=20,
                          env={**os.environ, "GIT_CONFIG_NOSYSTEM":"1"})


def sha(b):
    return hashlib.sha256(b).hexdigest()


def main():
    with tempfile.TemporaryDirectory(prefix="mad4b-qa31-drift-test-") as temp:
        t=Path(temp)
        source=t/"staging"
        src=source/"includes"
        src.mkdir(parents=True)
        plugin=t/"git"/"wp-content/plugins/mad4b-site-control-plane"
        (plugin/"includes").mkdir(parents=True)
        (plugin/"mad4b-site-control-plane.php").write_text(
            "<?php\n/**\n * Plugin Name: MAD4B Site Control Plane\n * Version: 0.4.0-rc.128\n */\n")
        original=b"<?php return 'baseline';\n"
        (plugin/"includes/sample.php").write_bytes(original)
        for argv in (("init", "-q"), ("add",".")):
            p=run("git",*argv,cwd=plugin.parents[2])
            assert p.returncode==0,p.stderr
        p=run("git","-c","user.name=QA31 CI","-c","user.email=qa31@example.invalid",
              "commit","-qm","test fixture",cwd=plugin.parents[2])
        assert p.returncode==0,p.stderr
        p=run("git","rev-parse","HEAD",cwd=plugin.parents[2])
        assert p.returncode==0
        head=p.stdout.strip()
        (src/"sample.php").write_bytes(original)
        manifest={"control_plane_version":"0.4.0-rc.128","source_commit_sha":head,
                  "package_files":[{"path":"includes/sample.php",
                                     "sha256":sha(original),"bytes":len(original)}]}
        (source/"MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
        output=t/"out.json"
        args=(sys.executable,str(SCRIPT),"--staging-root",str(source),
              "--github-root",str(plugin),"--expected-head",head,"--out",str(output))
        # A clean historical manifest still permits normal diagnostics.
        p=run(*args)
        assert p.returncode==0,(p.stdout,p.stderr)
        report=json.loads(output.read_text())
        assert report["historical_manifest"]["state"]=="MATCH"
        assert report["staging"]["manifest_verified_count"]==1
        # A changed real file does not become a valid build just because an
        # inventory option is requested. Default behavior is unchanged.
        sentinel=b"<?php return 'a brand new live change SECRET_CONTENT_NOT_FOR_LOGGING';\n"
        (src/"sample.php").write_bytes(sentinel)
        output.unlink()
        p=run(*args)
        assert p.returncode!=0 and "Staging package manifest drift" in p.stderr
        assert not output.exists(), "legacy fail-closed release path must not emit report"
        p=run(*args,"--report-manifest-drift")
        assert p.returncode==0,(p.stdout,p.stderr)
        report=json.loads(output.read_text())
        assert report["release_gate"]=="BLOCKED_STALE_BUILD_PROVENANCE"
        assert report["historical_manifest"]["state"]=="DRIFT"
        assert report["historical_manifest"]["changed_file_count"]==1
        assert report["historical_manifest"]["missing_file_count"]==0
        assert report["historical_manifest"]["diagnostic_override_used"] is True
        assert report["staging"]["manifest_verified_count"]==0
        assert report["production_authorized"] is False
        assert report["authorizing"] is False
        assert report["contains_source_bodies"] is False
        assert "SECRET_CONTENT_NOT_FOR_LOGGING" not in output.read_text()
        assert (src/"sample.php").read_bytes()==sentinel
        # Missing files remain denied, but can be enumerated without
        # fabricating a release certificate.
        manifest["package_files"].append({"path":"includes/no-longer-present.php",
                     "sha256":"f"*64,"bytes":32})
        (source/"MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
        p=run(*args,"--report-manifest-drift")
        assert p.returncode==0,(p.stdout,p.stderr)
        report=json.loads(output.read_text())
        assert report["historical_manifest"]["missing_file_count"]==1
        assert report["historical_manifest"]["changed_file_count"]==1
        assert report["release_gate"]=="BLOCKED_STALE_BUILD_PROVENANCE"
        # Invalid provenance structure is never accepted by the diagnostic.
        manifest["package_files"]="not-a-list"
        (source/"MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(manifest))
        output.unlink()
        p=run(*args,"--report-manifest-drift")
        assert p.returncode!=0 and "Malformed historical" in p.stderr
        assert not output.exists()
    print("QA31_STALE_MANIFEST_INVENTORY: PASS (default deny; explicit diagnostic; no disclosure or authority)")


if __name__=="__main__":
    main()
