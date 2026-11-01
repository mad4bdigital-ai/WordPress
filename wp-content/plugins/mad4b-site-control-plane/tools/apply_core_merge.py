#!/usr/bin/env python3
"""Integrate reviewed Local-only Unified Approval core code into MAD4B SCP.
Default is read-only verification. --apply makes a timestamped, rollback-ready
backup and changes only the explicitly selected Local plugin tree.
"""
import argparse
import datetime
import hashlib
import os
import re
from pathlib import Path
import shutil
import sys

FILES = (
    'class-mad4b-scp-unified-policy.php',
    'class-mad4b-scp-unified-approval-workflow.php',
    'class-mad4b-scp-background-authorization.php',
    'class-mad4b-scp-unified-gate-bridge.php',
    'class-mad4b-scp-unified-approval-admin.php',
    'class-mad4b-scp-unified-approval-extensions.php',
    'class-mad4b-scp-unified-approval-queue-bridge.php',
)
ANCHOR = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-ai-only-local-activation.php';"
EARLY_GATE_ANCHOR = "\t\tif ( ! $mad4b_scp_early_owned ) $mad4b_scp_early_zero_touch_reason = 'foreign_rest';"
# Exact Local REST allowlist: preserve rc.102's foreign-REST zero-touch path.
EARLY_GATE = """\t\t// Unified Approval is a Local-only, source-bound MAD4B-owned REST surface.
\t\tif ( function_exists('wp_get_environment_type') && 'local' === wp_get_environment_type()
\t\t     && in_array( $mad4b_scp_early_rest_path, array(
\t\t         '/mad4b-unified/v1/policy',
\t\t         '/mad4b-unified/v1/gates/plan',
\t\t         '/mad4b-unified/v1/reviews/open',
\t\t         '/mad4b-unified/v1/reviews/ai',
\t\t         '/mad4b-unified/v1/reviews/read',
\t\t         '/mad4b-unified/v1/browser/plan',
\t\t         '/mad4b-unified/v1/queue/gate',
\t\t         '/mad4b-unified/v1/extensions',
\t\t     ), true ) ) $mad4b_scp_early_owned = true;
"""
TAG = '// MAD4B core: Unified Approval & Agent Authorization (review-only, local pilot).'
BOOTSTRAP = TAG + '\n' + '\n'.join(
    f"require_once MAD4B_SCP_DIR . 'includes/{name}';" for name in FILES
) + "\n// The policy engine loads on all environments; writable review workflows stay Local-only.\n" + (
    "if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {\n"
    "    add_action('plugins_loaded', array('MAD4B_SCP_Unified_Approval_Admin', 'boot'), 110);\n"
    "}\n"
)

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--plugin-root',type=Path,required=True,help='Existing mad4b-site-control-plane plugin directory')
    ap.add_argument('--apply',action='store_true',help='Opt in to Local-only file modification')
    ap.add_argument('--environment',choices=['local'],help='Must explicitly be local when applying')
    args=ap.parse_args()
    base=args.plugin_root.expanduser().resolve()
    mainfile=base/'mad4b-site-control-plane.php'
    if not mainfile.is_file() or not (base/'includes'/'class-mad4b-scp-context-authority.php').is_file():
        raise SystemExit('BLOCK: not a canonical MAD4B Control Plane plugin tree')
    bootstrap=mainfile.read_text(encoding='utf-8')
    count=bootstrap.count(ANCHOR)
    if count!=1:
        raise SystemExit('BLOCK: expected exactly one canonical AI activation anchor, found '+str(count))
    if TAG in bootstrap:
        raise SystemExit('BLOCK: already integrated; reconcile existing Core manually')
    if bootstrap.count(EARLY_GATE_ANCHOR)!=1:
        raise SystemExit('BLOCK: missing rc.102 exact early-REST fastpath gate')
    src=Path(__file__).resolve().parent.parent/'includes'
    for name in FILES:
        if not (src/name).is_file(): raise SystemExit('BLOCK: package missing '+name)
        if (base/'includes'/name).exists(): raise SystemExit('BLOCK: destination file already exists: '+name)
    newbootstrap=bootstrap.replace(ANCHOR,ANCHOR+'\n'+BOOTSTRAP,1)
    newbootstrap=newbootstrap.replace(EARLY_GATE_ANCHOR,EARLY_GATE+EARLY_GATE_ANCHOR,1)
    print('MAD4B Core merge target:',base)
    print('Exact bootstrap anchor verified; includes to install:',len(FILES))
    print('Old bootstrap SHA256:',digest(mainfile))
    print('New bootstrap SHA256:',hashlib.sha256(newbootstrap.encode()).hexdigest())
    if not args.apply:
        print('CHECK ONLY — no files changed. Use --environment local --apply to install.')
        return
    if args.environment!='local': raise SystemExit('BLOCK: explicit --environment local required')
    # Do not let a manual arg override an actual production environment marker.
    wpconfig=base.parent.parent.parent/'wp-config.php'
    if not wpconfig.is_file():
        raise SystemExit('BLOCK: cannot verify installation type without wp-config.php')
    config=wpconfig.read_text(encoding='utf-8',errors='replace')
    if not re.search(r"define\(\s*['\"]WP_ENVIRONMENT_TYPE['\"]\s*,\s*['\"]local['\"]\s*\)", config):
        raise SystemExit('BLOCK: wp-config.php does not explicitly identify Local environment')
    timestamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup=base.parent/('mad4b-unified-core-merge-backup-'+timestamp)
    backup.mkdir(mode=0o700)
    original_bootstrap_stat=mainfile.stat()
    original_include_stat=(base/'includes'/'class-mad4b-scp-context-authority.php').stat()
    shutil.copy2(mainfile,backup/mainfile.name)
    copied=[]
    try:
        for name in FILES:
            destination=base/'includes'/name
            shutil.copy2(src/name,destination)
            copied.append(destination)
            os.chown(destination,original_include_stat.st_uid,original_include_stat.st_gid)
            destination.chmod(original_include_stat.st_mode & 0o777)
        temp=base/(mainfile.name+'.tmp-unified-approval')
        temp.write_text(newbootstrap,encoding='utf-8')
        os.chown(temp,original_bootstrap_stat.st_uid,original_bootstrap_stat.st_gid)
        temp.chmod(original_bootstrap_stat.st_mode & 0o777)
        os.replace(temp,mainfile)
        if mainfile.stat().st_gid != original_bootstrap_stat.st_gid:
            raise OSError('Core bootstrap group ownership changed during replacement')
    except BaseException:
        for path in copied:path.unlink(missing_ok=True)
        if 'temp' in locals(): temp.unlink(missing_ok=True)
        shutil.copy2(backup/mainfile.name,mainfile)
        os.chown(mainfile,original_bootstrap_stat.st_uid,original_bootstrap_stat.st_gid)
        mainfile.chmod(original_bootstrap_stat.st_mode & 0o777)
        raise
    print('Installed in Local Core. Rollback bootstrap backup:',backup)
    print('Back out by restoring bootstrap and removing only these exact seven new files.')
    print('IMPORTANT: deactivate the old mad4b-unified-approval standalone pilot before runtime activation.')

if __name__=='__main__':main()
