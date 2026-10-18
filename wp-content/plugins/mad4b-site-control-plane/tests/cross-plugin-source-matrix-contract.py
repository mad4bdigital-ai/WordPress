#!/usr/bin/env python3
"""Two-provider hermetic matrix test; no registered production site is touched."""
import json
import pathlib
import subprocess
import sys
import tempfile
import hashlib
import zipfile

root=pathlib.Path(__file__).resolve().parents[4]
select=root/'tools/mad4b-cross-plugin-candidates.py'
build=root/'tools/mad4b-cross-plugin-package.py'
def run(args,valid=True):
    r=subprocess.run(args,capture_output=True,text=True)
    if valid and r.returncode:
        raise AssertionError(r.stderr)
    return r

with tempfile.TemporaryDirectory() as td:
    tmp=pathlib.Path(td)
    git=tmp/'repo'
    testcases=[('plugin-alpha','start.php','1.3.0'),('plugin-beta','beta.php','2.0.1-rc.2')]
    for slug,filename,version in testcases:
        path=git/'wp-content/plugins'/slug
        path.mkdir(parents=True)
        (path/filename).write_text('<?php\n/**\n * Plugin Name: Demo\n * Version: '+version+'\n */\n')
        (path/'index.php').write_text('<?php // test\n')
    run(['git','init','-q',str(git)])
    run(['git','-C',str(git),'config','user.email','fixture@example.invalid'])
    run(['git','-C',str(git),'config','user.name','CI'])
    run(['git','-C',str(git),'add','.'])
    run(['git','-C',str(git),'commit','-qm','fixture'])
    sha=run(['git','-C',str(git),'rev-parse','HEAD']).stdout.strip()
    sources=[]
    for slug,name,version in testcases:
        sources.append(dict(id=slug,repository='sample/repo',plugin_subdirectory='wp-content/plugins/'+slug,
            plugin_file=slug+'/'+name,provider_id=slug,review_state='source_reviewed_artifact_pending',
            candidate_source_commit_sha=sha,candidate_artifact_version=version,
            package_certification='pending',artifact_sha256='',production_promotion_attestation=''))
    manifest=tmp/'manifest.json'
    def save():manifest.write_text(json.dumps(dict(contract='mad4b.cross-plugin-sources.v1',sources=sources)))
    save()
    cmd=[sys.executable,str(select),'--manifest',str(manifest)]
    matrix=json.loads(run(cmd).stdout)['include']
    assert [x['source_id'] for x in matrix]==[x[0] for x in testcases]
    assert len(json.loads(run(cmd+['--source-id','plugin-beta']).stdout)['include'])==1
    assert run(cmd+['--source-id','unknown'],False).returncode != 0
    for slug,entry,version in testcases:
        args=[sys.executable,str(build),'--trusted-manifest',str(manifest),'--checkout',str(git),
              '--source-id',slug,'--sha',sha]
        dest1=tmp/(slug+'-a')
        dest2=tmp/(slug+'-b')
        run(args+['--out',str(dest1)])
        run(args+['--out',str(dest2)])
        arc1=next(dest1.glob('*.zip'))
        arc2=next(dest2.glob('*.zip'))
        actual=hashlib.sha256(arc1.read_bytes()).hexdigest()
        assert actual==hashlib.sha256(arc2.read_bytes()).hexdigest()
        receipt=json.loads(next(dest1.glob('*.json')).read_text())
        assert receipt['source_id']==slug and receipt['archive_sha256']==actual
        assert receipt['version']==version and receipt['outcome_authorizes_deployment'] is False
        # The independent certification preflight verifies the actual ZIP;
        # it never upgrades a candidate receipt to deployment authority.
        review_tool = root/'tools/mad4b-cross-plugin-certification-review.py'
        verification = tmp/(slug+'-verification.json')
        run([sys.executable,str(review_tool),'--manifest',str(manifest),
             '--source-id',slug,'--zip',str(arc1),
             '--receipt',str(next(dest1.glob('*.json'))),
             '--out',str(verification)])
        review=json.loads(verification.read_text())
        assert review['state']=='VERIFIED_CANDIDATE_NEEDS_INDEPENDENT_REVIEW'
        assert review['archive_sha256']==actual
        assert review['staging_apply_authorized'] is False
        assert review['production_promotion_authorized'] is False
        wrong=tmp/(slug+'-wrong-receipt.json')
        forged=dict(receipt,archive_sha256='0'*64)
        wrong.write_text(json.dumps(forged))
        assert run([sys.executable,str(review_tool),'--manifest',str(manifest),
                 '--source-id',slug,'--zip',str(arc1),
                 '--receipt',str(wrong),
                 '--out',str(tmp/(slug+'-wrong-review.json'))],False).returncode!=0
        with zipfile.ZipFile(arc1) as z:
            assert slug+'/'+entry in z.namelist()
    sources.append(dict(sources[0]))
    save()
    assert run(cmd,False).returncode!=0
    sources.pop()
    sources[0]['candidate_source_commit_sha']='0'*40
    save()
    bad=run([sys.executable,str(build),'--trusted-manifest',str(manifest),'--checkout',str(git),
             '--source-id','plugin-alpha','--sha',sha,'--out',str(tmp/'invalid')],False)
    assert bad.returncode != 0 and 'exact reviewed registry SHA' in bad.stderr
    sources[0]['candidate_source_commit_sha']=sha
    sources[0]['candidate_artifact_version']='9.9.9'
    save()
    assert run([sys.executable,str(build),'--trusted-manifest',str(manifest),'--checkout',str(git),
            '--source-id','plugin-alpha','--sha',sha,'--out',str(tmp/'version')],False).returncode!=0
    sources[0]['candidate_artifact_version']='1.3.0'
    save()
    (git/'wp-content/plugins/plugin-alpha/link.php').symlink_to('start.php')
    assert run([sys.executable,str(build),'--trusted-manifest',str(manifest),'--checkout',str(git),
            '--source-id','plugin-alpha','--sha',sha,'--out',str(tmp/'symlink')],False).returncode!=0
print('CROSS_PLUGIN_MULTI_PROVIDER_MATRIX: PASS')
