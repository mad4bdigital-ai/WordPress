#!/usr/bin/env python3
"""Read-only preflight for MAD4B host-pinned local Git Control Plane packages.

Uses immutable live package requirements, not semantic-version ordering alone.
This gate does not choose a branch, fetch from a network, mutate WP, or deploy.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

CONTROL='wp-content/plugins/mad4b-site-control-plane'
GUARDED_METHODS={
 'includes/class-mad4b-scp-self-update.php':[
    'reviewed_archive_no_regression',
    'trusted_master_release_candidate',
 ],
 'includes/class-mad4b-scp-authorization.php':[
    'execution_attempt_sha256',
    'execution_attempt_state',
    'execution_boundary_verified',
    'local_approval_planner_execution',
 ],
}
GUARDED_FILES=[
 'includes/adapters/class-mad4b-scp-elementor-adapter.php',
 'includes/class-mad4b-scp-staging-write-authority.php',
 'includes/class-mad4b-scp-runtime-release-set.php',
 'includes/class-mad4b-scp-self-update.php',
 'includes/class-mad4b-scp-authorization.php',
]
VERSION=re.compile(r'^\s*\* Version: 0\.4\.0-rc\.(\d+)\s*$', re.M)
METHOD=re.compile(r'\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(')

def digest(path:Path)->str:
 return hashlib.sha256(path.read_bytes()).hexdigest()

def methods(path:Path)->set[str]:
 return set(METHOD.findall(path.read_text(errors='replace')))

def inspect(candidate:Path,live:Path,require_package:bool=False)->dict:
 report={'contract':'mad4b.host-pinned-local-git-readonly-admission.v1',
         'candidate':str(candidate),'live':str(live),
         'preflight_pass':False,'release_authorized':False,'errors':[],'warnings':[],'verified':{},'changed_core':[]}
 cp=candidate/CONTROL
 if not cp.is_dir():
  report['errors'].append('CANDIDATE_CONTROL_PLANE_MISSING');return report
 plugin=cp/'mad4b-site-control-plane.php'
 actual=live/'mad4b-site-control-plane.php'
 if not plugin.is_file() or not actual.is_file():
  report['errors'].append('CONTROL_PLANE_ENTRY_MISSING');return report
 new=VERSION.search(plugin.read_text(errors='replace'))
 old=VERSION.search(actual.read_text(errors='replace'))
 report['verified']['candidate_version']=new.group(1) if new else None
 report['verified']['live_version']=old.group(1) if old else None
 if not new or not old or int(new.group(1))<int(old.group(1)):
  report['errors'].append('UNPROVEN_OR_DOWNGRADE_VERSION')
 if not (live/'MAD4B-BUILD-PROVENANCE.json').is_file():
  report['errors'].append('LIVE_SIGNED_PROVENANCE_MISSING')
 else:
  provenance=json.loads((live/'MAD4B-BUILD-PROVENANCE.json').read_text())
  report['verified']['live_artifact_identity']=provenance.get('artifact_identity')
  report['verified']['live_source_commit_sha']=provenance.get('source_commit_sha')
  report['verified']['live_manifest_file_count']=provenance.get('manifest_file_count')
  if provenance.get('control_plane_version')!='0.4.0-rc.'+old.group(1):
   report['errors'].append('LIVE_PROVENANCE_VERSION_CONFLICT')
 for relative,required in GUARDED_METHODS.items():
  src=cp/relative;actual=live/relative
  if not src.is_file() or not actual.is_file():
   report['errors'].append('REQUIRED_METHOD_SOURCE_MISSING:'+relative);continue
  live_methods=methods(actual);src_methods=methods(src)
  for symbol in required:
   if symbol not in live_methods:
    report['errors'].append('LIVE_BASELINE_FUNCTION_MISSING:'+symbol)
   if symbol not in src_methods:
    report['errors'].append('REGRESSION_FUNCTION_MISSING:'+relative+':'+symbol)
  report['verified'][relative+'_methods']={'candidate':len(src_methods),'live':len(live_methods)}
 for relative in GUARDED_FILES:
  src=cp/relative;actual=live/relative
  if not src.is_file() or not actual.is_file():
   report['errors'].append('REQUIRED_FILE_MISSING:'+relative);continue
  if digest(src)!=digest(actual):
   report['changed_core'].append(relative)
 if report['changed_core']:
  report['warnings'].append('CRITICAL_RUNTIME_FILES_DIFFER_REQUIRE_BEHAVIORAL_PARITY_AND_ROLLBACK')
 # The central issuer public key can be injected only by a signed build. This
 # check applies to the final *package*, not necessarily to the Git tree.
 if require_package:
  key=cp/'config/central-release-issuer-public.pem'
  prov=cp/'MAD4B-BUILD-PROVENANCE.json'
  if not key.is_file() or key.stat().st_size<100:
   report['errors'].append('SIGNED_RELEASE_ISSUER_PUBLIC_KEY_NOT_PACKAGED')
  if not prov.is_file():
   report['errors'].append('FINAL_BUILD_PROVENANCE_NOT_PACKAGED')
 report['verified']['require_final_package']=bool(require_package)
 report['preflight_pass']=not report['errors'] and not report['changed_core']
 report['release_authorized']=False  # Original signed certification is a separate authority.
 report['warnings'].append('PREFLIGHT_IS_NOT_AN_ORIGINAL_SIGNED_RELEASE_CERTIFICATE')
 return report

def main()->int:
 p=argparse.ArgumentParser()
 p.add_argument('--candidate',required=True,type=Path)
 p.add_argument('--live-plugin',required=True,type=Path)
 p.add_argument('--require-package',action='store_true')
 p.add_argument('--output',type=Path)
 args=p.parse_args()
 report=inspect(args.candidate,args.live_plugin,args.require_package)
 text=json.dumps(report,indent=2,ensure_ascii=False)+'\n'
 if args.output:args.output.write_text(text)
 print(text)
 return 0 if report['preflight_pass'] else 2
if __name__=='__main__':sys.exit(main())
