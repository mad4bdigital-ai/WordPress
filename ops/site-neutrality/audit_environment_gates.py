#!/usr/bin/env python3
"""Read-only catalog of environment-dependent WordPress execution paths.
No authorization is granted by this audit; classifications require human review.
"""
from pathlib import Path
import argparse, collections, json, re

GROUPS = {
 'simulation_or_test': ('test', 'fixture', 'canary', 'simulator', 'staging-brand-trial'),
 'developer_or_privileged_grants': ('developer', 'agent-grant', 'ai-native', 'ai-grant', 'operator-grant', 'breakglass', 'grant-reconcil'),
 'recovery_or_host_mutation': ('restore', 'recovery', 'upgrade-continuity', 'wp-config', 'host-capabilities', 'local-client', 'self-update', 'deployment'),
 'remote_transport_or_secrets': ('oauth', 'mcp-transport', 'credential', 'application-password', 'connection', 'local-transport'),
 'content_or_company_ops': ('content', 'company', 'research', 'market', 'readiness', 'import', 'google-drive', 'brand'),
}
PATTERN=re.compile(r'wp_get_environment_type\s*\(\s*\)|WP_ENVIRONMENT_TYPE|(?:staging|production|nonproduction|local)_only', re.I)
DECISION=re.compile(r'\bif\s*\(|\breturn\b|\bthrow\b|===|!==|in_array\s*\(',re.I)

def main():
 ap=argparse.ArgumentParser();ap.add_argument('--root',type=Path,required=True);ap.add_argument('--output',type=Path);args=ap.parse_args()
 base=args.root/'wp-content/plugins/mad4b-site-control-plane/includes';files=sorted(base.glob('*.php'))
 findings=[]
 for path in files:
  lines=path.read_text(errors='replace').splitlines()
  for n,line in enumerate(lines,1):
   if not PATTERN.search(line) or not DECISION.search(line):continue
   kind=next((g for g,parts in GROUPS.items() if any(p in path.stem for p in parts)),'other_or_reporting')
   findings.append({'file':path.name,'line':n,'category':kind,'text':line.strip()[:210]})
 counts=collections.Counter(x['category'] for x in findings)
 payload={'contract':'mad4b.site-neutrality-environment-guard-inventory.v1','note':'Candidate lines are not proof of active gate. Includes reporting, testing, and protective checks. Review each call path before modification.','files_scanned':len(files),'matching_lines':len(findings),'matching_files':len(set(x['file'] for x in findings)),'categories':dict(sorted(counts.items())),'findings':findings}
 output=json.dumps(payload,indent=2,ensure_ascii=False)+'\n'
 if args.output:args.output.parent.mkdir(parents=True,exist_ok=True);args.output.write_text(output)
 else:print(output)
 print(f"inventory: {payload['matching_lines']} candidate lines across {payload['matching_files']} files",flush=True)
if __name__=='__main__':main()
