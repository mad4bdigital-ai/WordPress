#!/usr/bin/env python3
"""QA31 read-only exact-source reconciliation. No database, tenant credentials, deployment or writes to source."""
import argparse,hashlib,json,re,subprocess,sys
from pathlib import Path
from collections import Counter
def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
def inventory(root):
    records={}
    for p in root.rglob('*'):
        if p.is_symlink():raise ValueError('Refuse symlink: '+str(p))
        if p.is_file():
            rel=p.relative_to(root).as_posix()
            records[rel]={"sha256":sha(p),"bytes":p.stat().st_size}
    return records
def category(path):
    if path.startswith(('tests/','docs/','languages/')):return 'support_material'
    if path in ('MAD4B-BUILD-PROVENANCE.json','MAD4B-RUNTIME-BUILD.txt','README.md'):return 'build_provenance'
    if path.endswith('.generated.json') or path.startswith('data/qa'):return 'generated_fixture'
    if path.startswith(('includes/','bootstrap/','config/','data/','assets/','skill-seeds/')) or path.endswith('.php'):
        return 'active_source'
    return 'needs_classification'
def act(status,cat):
    if status=='MATCH':return 'PRESERVE'
    if cat in ('build_provenance','generated_fixture'):
        return 'REGENERATE_OR_EXCLUDE_FROM_RELEASE'
    if cat=='support_material':
        return 'PRESERVE_GITHUB_WITH_REGRESSION_REVIEW'
    if status=='STAGING_ONLY':
        return 'SOURCE_IMPORT_REQUIRES_CAPABILITY_REVIEW'
    if status=='GITHUB_ONLY':
        return 'PRESERVE_GITHUB_VALIDATE_STAGING_COMPATIBILITY'
    return 'THREE_WAY_MERGE_REQUIRED'
def main():
    a=argparse.ArgumentParser()
    for k in ('staging_root','github_root','expected_head','out'):a.add_argument('--'+k.replace('_','-'),required=True)
    x=a.parse_args()
    stage=Path(x.staging_root).resolve();github=Path(x.github_root).resolve()
    if not stage.is_dir() or not github.is_dir():raise ValueError('Missing root')
    parent=github.parent
    current=subprocess.check_output(['git','-C',str(parent),'rev-parse','HEAD'],text=True).strip()
    if current!=x.expected_head:raise ValueError('Git HEAD mismatch')
    prov=json.loads((stage/'MAD4B-BUILD-PROVENANCE.json').read_text())
    assert prov['control_plane_version'].startswith('0.4.0-rc.')
    si=inventory(stage);gi=inventory(github)
    corrupt=[e['path'] for e in prov['package_files'] if e['path'] not in si or
             si[e['path']]['sha256']!=e['sha256'] or si[e['path']]['bytes']!=e['bytes']]
    if corrupt:raise ValueError('Staging package manifest drift: '+str(corrupt[:7]))
    allpaths=sorted(set(si)|set(gi))
    records=[];summary=Counter();actions=Counter()
    for path in allpaths:
        s=si.get(path);g=gi.get(path)
        status=('MATCH' if s and g and s['sha256']==g['sha256'] else
                'DIVERGENT' if s and g else 'STAGING_ONLY' if s else 'GITHUB_ONLY')
        cat=category(path)
        action=act(status,cat)
        if status!='MATCH':
            records.append({'path':path,'state':status,'category':cat,'decision':action,
                'staging_sha256':s['sha256'] if s else None,'github_sha256':g['sha256'] if g else None,
                'staging_bytes':s['bytes'] if s else None,'github_bytes':g['bytes'] if g else None})
            summary[status]+=1;actions[action]+=1
    sensitive=[r for r in records if r['category'] in ('active_source','needs_classification')]
    report={
      'contract':'mad4b.qa31.source-reconciliation-inventory.v1',
      'authorizing':False,'production_authorized':False,'contains_source_bodies':False,
      'github':{'head_sha':current,'version':re.search(r'(?m)^\s*\* Version:\s*(\S+)',(github/'mad4b-site-control-plane.php').read_text()).group(1),'files':len(gi)},
      'staging':{'package_version':prov['control_plane_version'],'build_source_sha':prov['source_commit_sha'],'manifest_verified_count':len(prov['package_files']),'files':len(si)},
      'matched_files':len(set(si)&set(gi))-summary['DIVERGENT'],
      'diff_count_by_state':dict(summary),'diff_count_by_action':dict(actions),
      'protected_source_divergence_count':len(sensitive),
      'release_gate':'BLOCKED_SOURCE_DIVERGENCE' if sensitive or summary['DIVERGENT'] else 'REVIEW_REQUIRED',
      'decisions':records}
    out=Path(x.out);out.parent.mkdir(parents=True,exist_ok=True)
    out.write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    print(json.dumps({'result':report['release_gate'],'github_version':report['github']['version'],
      'staging_version':report['staging']['package_version'],
      'matched_files':report['matched_files'],
      'diff_by_state':report['diff_count_by_state'],
      'decisions':report['diff_count_by_action'],
      'protected_source_divergence':len(sensitive),
      'manifest_verified':len(prov['package_files']),
      'report_sha256':sha(out)},indent=2))
if __name__=='__main__':
  try:main()
  except Exception as exc:print('QA31 FAIL CLOSED: '+str(exc),file=sys.stderr);sys.exit(2)
