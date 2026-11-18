#!/usr/bin/env python3
"""Fail-closed QA31 release source parity gate. SHA-only offline evidence; never authorizes Production."""
from __future__ import annotations
import argparse,hashlib,json,re,subprocess,sys
from pathlib import Path
SHA=re.compile(r'^[a-f0-9]{64}$')
SHA40=re.compile(r'^[a-f0-9]{40}$')
def digest(p:Path)->str:return hashlib.sha256(p.read_bytes()).hexdigest()
_REVIEWED_CI_ONLY_SOURCE_TESTS = {
    'mad4b-site-control-plane.php': 'wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.py',
    'config/cross-plugin-sources.json': 'wp-content/plugins/mad4b-site-control-plane/tests/dfsb-rc15-signed-review-source-pin.py',
    'includes/class-mad4b-scp-server-write-catalog.php': 'wp-content/plugins/mad4b-site-control-plane/tests/live-write-provider-snapshot-runtime.php',
}

def reviewed_rc129_pinned_successor(repo:Path,path:str,contents:bytes):
    """Find an explicit, frozen source successor. Never infer allowlists from
    arbitrary coverage rows, titles, runtime endpoints or production state.
    """
    file=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
    if not file.is_file() or file.is_symlink():return None
    try:
        manifest=json.loads(file.read_text(encoding='utf-8'))
        legacy_path=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
        historical=json.loads(legacy_path.read_text(encoding='utf-8'))
        entries=manifest.get('reviewed_entries')
        base='ee6c57e870db6b4aada4de64b0471a953c8bdf0a'
        if (manifest.get('contract')!='mad4b.qa31-rc129-reviewed-source-successors.v1'
            or manifest.get('authorizing') is not False
            or manifest.get('production_authorized') is not False
            or manifest.get('live_evidence_required') is not True
            or manifest.get('is_build_provenance') is not False
            or manifest.get('reviewed_for_ci_only') is not True
            or manifest.get('source_commit')!=base
            or manifest.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or manifest.get('frozen_legacy_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or manifest.get('frozen_legacy_ledger_sha256')!=digest(legacy_path)
            or not isinstance(entries,list) or len(entries)!=65
            or manifest.get('total_reviewed_successors')!=len(entries)):return None
        names=[e.get('path') for e in entries if isinstance(e,dict)]
        if len(names)!=len(entries) or names!=sorted(set(names)) or path not in names:return None
        entry=entries[names.index(path)]
        legacy={x['path']:x for x in historical.get('source_decisions',[])}
        record=legacy.get(path)
        if not isinstance(record,dict) or record.get('state') not in ('STAGING_ONLY','DIVERGENT'):
            return None
        if (entry.get('state')!=record['state']
            or entry.get('historical_staging_sha256')!=record.get('staging_sha256')
            or entry.get('historical_github_sha256')!=record.get('github_sha256')
            or not SHA.fullmatch(str(entry.get('current_sha256','')))
            or not SHA40.fullmatch(str(entry.get('current_git_blob_sha','')))
            or hashlib.sha256(contents).hexdigest()!=entry['current_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=entry['current_git_blob_sha']):return None
        test=entry.get('required_regression')
        if path.startswith('bootstrap/'):
            if path not in (
                'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php',
                'bootstrap/mad4b-runtime-cache-convergence-mu.php'):
                return None
            review=entry.get('independent_bootstrap_review')
            controls=review.get('control_ids') if isinstance(review,dict) else None
            known=json.loads((repo/'governance/production-readiness/controls.json').read_text())
            known_ids={x['id'] for x in known.get('controls',[]) if isinstance(x,dict)}
            if (not isinstance(review,dict)
                or review.get('status')!='reviewed_for_ci_only'
                or review.get('production_authorized') is not False
                or review.get('live_evidence_required') is not True
                or not review.get('owner_role')
                or len(str(review.get('review_rationale','')))<90
                or not isinstance(controls,list) or len(controls)<3
                or len(controls)!=len(set(controls)) or not set(controls)<=known_ids
                or test!='tests/qa31_rc129_bootstrap_source_contract.py'):
                return None
        elif entry.get('independent_bootstrap_review') is not None:
            return None
        if (not isinstance(test,str) or not test.startswith(('tests/','wp-content/plugins/mad4b-site-control-plane/tests/'))
            or '..' in test.split('/') or not (repo/test).is_file()
            or (repo/test).is_symlink()):return None
        # CI checks out only the PR HEAD by default. If a pinned predecessor
        # is present, verify its exact blob. Otherwise permit only a *genuine*
        # shallow Git checkout; the current HEAD blob, reviewed registry SHA,
        # explicit manifest SHA256 and all source gates remain mandatory.
        parent=subprocess.run(['git','cat-file','-e',base+'^{commit}'],
                              cwd=repo,stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL,timeout=8)
        if parent.returncode==0:
            exact=subprocess.check_output(
                ['git','rev-parse',base+':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if exact!=entry['current_git_blob_sha']:return None
        else:
            shallow=subprocess.check_output(
                ['git','rev-parse','--is-shallow-repository'],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if shallow!='true':return None
        return test
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,subprocess.TimeoutExpired,json.JSONDecodeError):
        return None


def reviewed_rc129_host_integration_successor(repo:Path,path:str,contents:bytes):
    """Return only the source-pinned Host regression for the six exact current
    files. The original QA31 ledger and prior rc129 successor reviews are
    immutable; this review neither authorizes release nor grants Host writes.
    """
    src=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
    if not src.is_file() or src.is_symlink():return None
    base='27883e019d7b9ddc744f6be33608b78c54ec411b'
    allowed=(
        'includes/class-mad4b-scp-artifacts.php',
        'includes/class-mad4b-scp-brand-review-routing.php',
        'includes/class-mad4b-scp-content-jobs.php',
        'includes/class-mad4b-scp-context-authority.php',
        'includes/class-mad4b-scp-host-agent-bridge.php',
        'mad4b-site-control-plane.php',
    )
    if path not in allowed or not isinstance(contents,bytes):return None
    try:
        v=json.loads(src.read_text(encoding='utf-8'))
        previous=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
        historical=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
        ledger=json.loads(historical.read_text(encoding='utf-8'))
        rows=v.get('source_entries')
        if (v.get('contract')!='mad4b.qa31-host-integration-exact-successors.v1'
            or v.get('base_source_commit')!=base
            or v.get('original_qa31_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or v.get('previous_rc129_reviews')!='governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
            or v.get('original_qa31_sha256')!=digest(historical)
            or v.get('previous_rc129_reviews_sha256')!=digest(previous)
            or v.get('authorizing') is not False
            or v.get('production_authorized') is not False
            or v.get('source_promotion_approved') is not False
            or v.get('is_build_provenance') is not False
            or v.get('live_evidence_required') is not True
            or v.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or v.get('exact_source_files')!=6
            or v.get('historical_qa31_overrides')!=3
            or not isinstance(rows,list) or len(rows)!=6):return None
        names=[r.get('path') for r in rows if isinstance(r,dict)]
        if len(names)!=6 or tuple(names)!=allowed:return None
        entry=rows[allowed.index(path)]
        existing={r['path']:r for r in ledger['source_decisions']}
        listed=existing.get(path)
        if listed:
            if (entry.get('source_record_scope')!='qa31_historical'
                or entry.get('original_qa31_state')!=listed.get('state')
                or entry.get('original_qa31_staging_sha256')!=listed.get('staging_sha256')
                or entry.get('original_qa31_github_sha256')!=listed.get('github_sha256')):return None
        elif entry.get('source_record_scope')!='new_or_separate_review':return None
        if (entry.get('required_regression')!='wp-content/plugins/mad4b-site-control-plane/tests/host-agent-source-parity-contract.py'
            or not SHA.fullmatch(str(entry.get('current_sha256','')))
            or not SHA40.fullmatch(str(entry.get('current_blob_sha','')))
            or hashlib.sha256(contents).hexdigest()!=entry['current_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=entry['current_blob_sha']):return None
        previous_sha=entry.get('parent_sha256')
        previous_blob=entry.get('parent_blob_sha')
        # Source-owner reviewed predecessor pairs are immutable across the
        # shallow checkout used by GitHub Actions. Merely omitting the parent
        # Git object must never turn a forged historical blob into approval.
        certified_parents={
            'includes/class-mad4b-scp-artifacts.php':
                ('7dcbcff762053e27ab858bd2554c277fb6f39d44','27c37d078984f9ed05639a8bdffda59f0406ecb6a63b42e9f2e79e3e00666efe'),
            'includes/class-mad4b-scp-brand-review-routing.php':
                ('ad582bbdfe20f02166050558d0cf80fda3fc5b65','273824bd5fbdfe3343b07b7e234e43b198d73770751b5a4a8ff2870f5cd2321e'),
            'includes/class-mad4b-scp-content-jobs.php':
                ('6414871eaef0a7ab4e19d9ecf7cfc1200c1a76c4','cc8ed43625db6ea74cb3d20b395f06545db661267880cec5b37f94dedcca7a70'),
            'includes/class-mad4b-scp-context-authority.php':
                ('43e71753bfb1e43e0cb48f22690ab3522c44d695','7c0b8507a7bc6d54bb88669797f413a0e3fe2dbe139322d90149bde0a0f1ec50'),
            'includes/class-mad4b-scp-host-agent-bridge.php':(None,None),
            'mad4b-site-control-plane.php':
                ('fcf54c0b6312c575c0ab34b3d751cd5168ca46fa','f613ca22a1e9158b9711d0ebd85d406b83e5d2b81bd0fbba2835d99a7200e1b9'),
        }
        if (previous_blob,previous_sha)!=certified_parents[path]:return None
        newfile=path=='includes/class-mad4b-scp-host-agent-bridge.php'
        if not newfile and (not SHA.fullmatch(str(previous_sha)) or not SHA40.fullmatch(str(previous_blob))):
            return None
        parent=subprocess.run(['git','cat-file','-e',base+'^{commit}'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8)
        if parent.returncode==0:
            old=subprocess.run(['git','rev-parse','--verify',
                base+':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=8)
            if newfile:
                if old.returncode==0:return None
            elif old.returncode!=0 or old.stdout.decode().strip()!=previous_blob:
                return None
        else:
            is_shallow=subprocess.check_output(['git','rev-parse','--is-shallow-repository'],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if is_shallow!='true':return None
        return entry['required_regression']
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError):
        return None


def reviewed_rc129_context_media_successor(repo:Path,path:str,contents:bytes):
    """Read-only SHA-pinned reconciliation for exactly three live Context Media
    integrity changes. Previous QA31 source ledgers remain immutable; neither
    media fixture success nor this review is Production authorization.
    """
    allowed={
        'includes/class-mad4b-scp-brand-context-builder.php':
            ('bce973b8a6957e5c820253fc3b6ab4d4cf642a78','77059ff5ad1c2a8b7d4984256ff8e425716b5cdd17d50f42e473161be087f028'),
        'includes/class-mad4b-scp-drive-scan-continuation.php':
            ('d440f529a5a022a266961872d2de16d97bf17074','76f69898a5e205dd5fef0a81e8c27f4795da71ccc9cc1a2f8f4ce87f4e46b43c'),
        'includes/class-mad4b-scp-google-drive-context.php':
            ('2768e78e8ca214145aea083440ff9634adc2304b','c3a69ee0fded972fbe2d07dcdd3bf9a35a5fe02d83d87c9c2b4d27490b76b3be')
    }
    if path not in allowed or not isinstance(contents,bytes):return None
    reg=repo/'governance/release-parity/qa31-rc129-context-media-successors-20261118.json'
    if not reg.is_file() or reg.is_symlink():return None
    source_base='1fe48421cbc1444bc6698f57bf589784ec4a982f'
    required='wp-content/plugins/mad4b-site-control-plane/tests/context-scan-media-integrity/isolated-contract.php'
    doc_test='wp-content/plugins/mad4b-site-control-plane/tests/context-document-normalization-runtime.php'
    historical=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
    host=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
    predecessor=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
    try:
        data=json.loads(reg.read_text(encoding='utf-8'))
        old=json.loads(historical.read_text(encoding='utf-8'))
        entries=data.get('entries')
        names=[i.get('path') for i in entries if isinstance(i,dict)] if isinstance(entries,list) else []
        if (data.get('contract')!='mad4b.qa31-rc129-context-media-successors.v1'
            or data.get('base_source_commit')!=source_base
            or data.get('source_review_only') is not True
            or data.get('authorizing') is not False
            or data.get('production_authorized') is not False
            or data.get('source_promotion_approved') is not False
            or data.get('is_build_provenance') is not False
            or data.get('live_evidence_required') is not True
            or data.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or data.get('historical_qa31_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or data.get('historical_qa31_sha256')!=digest(historical)
            or data.get('host_integration_review')!='governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
            or data.get('host_integration_sha256')!=digest(host)
            or data.get('original_rc129_review_sha256')!=digest(predecessor)
            or data.get('exact_source_files')!=3
            or data.get('legacy_qa31_overrides')!=2
            or names!=sorted(allowed)):return None
        entry=entries[names.index(path)]
        if ((entry.get('parent_blob_sha'),entry.get('parent_sha256'))!=allowed[path]
            or entry.get('required_regression')!=required
            or entry.get('document_regression')!=doc_test
            or not SHA.fullmatch(str(entry.get('current_sha256','')))
            or not SHA40.fullmatch(str(entry.get('current_blob_sha','')))
            or hashlib.sha256(contents).hexdigest()!=entry['current_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=entry['current_blob_sha']):return None
        original={e['path']:e for e in old.get('source_decisions',[])}
        prior=original.get(path)
        if prior is None:
            if entry.get('legacy_qa31_state') is not None:return None
            if any(k in entry for k in ('legacy_qa31_staging_sha256','legacy_qa31_github_sha256')):return None
        else:
            if (entry.get('legacy_qa31_state')!=prior.get('state')
                or entry.get('legacy_qa31_staging_sha256')!=prior.get('staging_sha256')
                or entry.get('legacy_qa31_github_sha256')!=prior.get('github_sha256')):return None
        if (not (repo/required).is_file() or (repo/required).is_symlink()
            or not (repo/doc_test).is_file() or (repo/doc_test).is_symlink()):return None
        have_base=subprocess.run(['git','cat-file','-e',source_base+'^{commit}'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8)
        if have_base.returncode==0:
            prev=subprocess.check_output(['git','rev-parse',source_base+
                ':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if prev!=entry['parent_blob_sha']:return None
        elif subprocess.check_output(['git','rev-parse','--is-shallow-repository'],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()!='true':
            return None
        return required
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError):
        return None



def reviewed_rc129_chatgpt_cimd_successor(repo:Path,path:str,contents:bytes):
    """Exact rc129 OAuth successor from reviewed Tours.dev PR #865. CI-only:
    neither matching metadata URL nor this evidence grants OAuth authority."""
    exact='includes/class-mad4b-scp-local-oauth-server.php'
    if path!=exact or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-rc129-cimd-source-successor-20261118.json'
    media=repo/'governance/release-parity/qa31-rc129-context-media-successors-20261118.json'
    old=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
    test='wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cimd-client-security-runtime.php'
    baseline='6ee165ef798c1e37bdc0e09513d57e4dfcb224af'
    old_pair=('407a81c61bf5346d33d8206312bab0010f311fa4',
              '8408f559be99bf92bdc1d42dc5abcbc831261a8e2d2dcbedf2dda9261935dab3')
    new_pair=('0fd413ea1abb92a808c7372d41f001b447c0be2f',
              'd27f262e12993b2ed0574f1386762ac84ae55b6718ec0a7d572e382689547135')
    try:
        if not manifest.is_file() or manifest.is_symlink():return None
        v=json.loads(manifest.read_text(encoding='utf-8'))
        entry=v.get('entry')
        if (v.get('contract')!='mad4b.qa31-rc129-cimd-source-successor.v1'
            or v.get('base_source_commit')!=baseline
            or v.get('source_review_only') is not True
            or v.get('authorizing') is not False
            or v.get('production_authorized') is not False
            or v.get('source_promotion_approved') is not False
            or v.get('is_build_provenance') is not False
            or v.get('live_evidence_required') is not True
            or v.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or v.get('exact_source_files')!=1
            or v.get('source_review_reference')!='https://github.com/mad4bdigital-ai/WordPress/pull/865'
            or v.get('source_reviewed_blob_sha')!=new_pair[0]
            or v.get('context_media_manifest')!='governance/release-parity/qa31-rc129-context-media-successors-20261118.json'
            or v.get('context_media_manifest_sha256')!=digest(media)
            or v.get('historical_qa31_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or v.get('historical_qa31_sha256')!=digest(old)
            or not isinstance(entry,dict)
            or entry.get('path')!=exact
            or (entry.get('parent_blob_sha'),entry.get('parent_sha256'))!=old_pair
            or (entry.get('current_blob_sha'),entry.get('current_sha256'))!=new_pair
            or entry.get('required_regression')!=test
            or hashlib.sha256(contents).hexdigest()!=new_pair[1]
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=new_pair[0]
            or not (repo/test).is_file() or (repo/test).is_symlink()):return None
        has_base=subprocess.run(['git','cat-file','-e',baseline+'^{commit}'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8)
        if has_base.returncode==0:
            predecessor=subprocess.check_output(['git','rev-parse',
                baseline+':wp-content/plugins/mad4b-site-control-plane/'+exact],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if predecessor!=old_pair[0]:return None
        elif subprocess.check_output(['git','rev-parse','--is-shallow-repository'],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()!='true':
            return None
        return test
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError):
        return None


def reviewed_rc129_chatbotx_cpt_successor(repo:Path,path:str,contents:bytes):
    """Exactly three reviewed site-neutral ChatbotX files. No CPT grant:
    this is static QA31 source evidence, not an activation or deployment."""
    allowed={
      'includes/adapters/class-mad4b-scp-chatbotx-governed-writes.php':
        ('953850829b7cd20df115451be44b868510fabf1a','11ed54a15cd53d6e1a9351204791e4f8ff38d5da','0832e17160191066e8ab5de4d08d1506c009ed1a448583a7b15d0fdbefafc545'),
      'includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php':
        ('2bedb1739c3952c004ace0e9b37373b04220c764','b6af664ae88e3bf89571bfce239ae31a9f3a5f72','5f95b30d40b15db55a493178a30819733faf8c396f06bcb1b25f935a92395e2b'),
      'includes/adapters/class-mad4b-scp-chatbotx-write-preflight.php':
        ('f878ee584ab1fef1e4de2b6214621355f098b0a8','7de977116f29da225ac9624610fbdf93ce397963','9ee3139c6443786904a36ad80eb43089b4be8879b6e85c2020837c8658b12488')
    }
    if path not in allowed or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-rc129-chatbotx-cpt-successor-20261118.json'
    previous=repo/'governance/release-parity/qa31-rc129-cimd-source-successor-20261118.json'
    historical=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
    base='924b2051144df7af2cf409e7fc617d96e23d95d8'
    test='wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-chatbotx-site-neutral-runtime.php'
    oldtest='tests/chatbotx-native-adapter-surface-hermetic.php'
    try:
        if not manifest.is_file() or manifest.is_symlink():return None
        m=json.loads(manifest.read_text(encoding='utf-8'))
        old=json.loads(historical.read_text(encoding='utf-8'))
        entries=m.get('entries')
        names=[z.get('path') for z in entries if isinstance(z,dict)] if isinstance(entries,list) else []
        if (m.get('contract')!='mad4b.qa31-rc129-chatbotx-site-neutral-cpt-successor.v1'
            or m.get('base_source_commit')!=base
            or m.get('prior_cimd_manifest')!='governance/release-parity/qa31-rc129-cimd-source-successor-20261118.json'
            or m.get('prior_cimd_sha256')!=digest(previous)
            or m.get('historical_qa31_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or m.get('historical_qa31_sha256')!=digest(historical)
            or m.get('source_review_reference')!='https://github.com/mad4bdigital-ai/WordPress/pull/900'
            or m.get('source_review_only') is not True
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('live_evidence_required') is not True
            or m.get('is_build_provenance') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or m.get('exact_source_files')!=3
            or m.get('required_regression')!=test
            or m.get('existing_regression')!=oldtest
            or names!=sorted(allowed)):return None
        row=entries[names.index(path)]
        parent_sha,source_sha,source_digest=allowed[path]
        if (row.get('parent_blob_sha')!=parent_sha
            or row.get('current_blob_sha')!=source_sha
            or row.get('current_sha256')!=source_digest
            or hashlib.sha256(contents).hexdigest()!=source_digest
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=source_sha):return None
        original={e['path']:e for e in old.get('source_decisions',[])}
        if original.get(path,{}).get('state')!='STAGING_ONLY':return None
        if not (repo/test).is_file() or (repo/test).is_symlink():return None
        if not (repo/oldtest).is_file() or (repo/oldtest).is_symlink():return None
        pre=subprocess.run(['git','cat-file','-e',base+'^{commit}'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8)
        if pre.returncode==0:
            git_parent=subprocess.check_output(['git','rev-parse',base+
                ':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if git_parent!=parent_sha:return None
        elif subprocess.check_output(['git','rev-parse','--is-shallow-repository'],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()!='true':
            return None
        return test
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError):
        return None

def reviewed_ci_only_source_successor(root:Path,path:str,contents:bytes)->bool:
    """Accept exact, reviewed Git blobs for CI inventory only; never parity.
    Static legacy entries remain valid; rc129 successors are independently
    pinned in a frozen SHA-backed ledger with named regression evidence.
    """
    if not isinstance(contents,bytes):return False
    repo=Path(__file__).resolve().parents[1]
    expected=repo/'wp-content/plugins/mad4b-site-control-plane'
    if root.resolve()!=expected.resolve():return False
    source=root/path
    if source.is_symlink() or not source.is_file() or source.read_bytes()!=contents:
        return False
    requested=reviewed_rc129_chatbotx_cpt_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_chatgpt_cimd_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_context_media_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_host_integration_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_pinned_successor(repo,path,contents)
    if requested is None:
        requested=_REVIEWED_CI_ONLY_SOURCE_TESTS.get(path)
    if requested is None:return False
    try:
        registry=json.loads((repo/'governance/production-readiness/capability-coverage.json').read_text(encoding='utf-8'))
        blob=hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()
        git_blob=subprocess.check_output(
            ['git','rev-parse','HEAD:wp-content/plugins/mad4b-site-control-plane/'+path],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
        test=repo/requested
    except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return False
    if (blob!=git_blob or not test.is_file() or test.is_symlink()
        or registry.get('contract')!='mad4b.readiness-capability-coverage.v1'
        or registry.get('authorizing') is not False
        or registry.get('production_promotion_from_ci') is not False):
        return False
    canonical='wp-content/plugins/mad4b-site-control-plane/'+path
    # These two exact MU bootstrap sources are not general Control Plane
    # runtime paths; their separate, non-authorizing, SHA-pinned QA31 review
    # is validated in the frozen successor manifest above.
    if (path in ('bootstrap/mad4b-mcp-adapter-mu-bootstrap.php',
                 'bootstrap/mad4b-runtime-cache-convergence-mu.php')
        and requested=='tests/qa31_rc129_bootstrap_source_contract.py'):
        return True
    for row in registry.get('reviewed_changes',[]):
        if (isinstance(row,dict) and row.get('path')==canonical
            and row.get('source_blob_sha')==blob
            and row.get('status')=='reviewed_for_ci_only'
            and row.get('production_authorized') is False
            and row.get('live_evidence_required') is True
            and len(row.get('control_ids',[]))>=2
            and isinstance(row.get('regression_tests'),list)
            and requested in row['regression_tests']):
            return True
    return False

def reviewed_bootstrap_successor(root:Path,path:str,contents:bytes)->bool:
    """Bootstrap compatibility wrapper: no cross-path authorization."""
    return path=='mad4b-site-control-plane.php' and reviewed_ci_only_source_successor(root,path,contents)

def evaluate(evidence:dict,root:Path)->dict:
    if evidence.get('contract')!='mad4b.qa31.source-parity-evidence.v1' or        evidence.get('authorizing') is not False or        evidence.get('production_authorized') is not False or        evidence.get('live_production_evidence') is not False:
        raise ValueError('Untrusted or authorizing evidence contract')
    if not SHA40.fullmatch(str(evidence.get('github_base_source_sha',''))) or        not SHA40.fullmatch(str(evidence.get('staging_build_source_sha',''))):
        raise ValueError('Missing exact source commit provenance')
    records=evidence.get('source_decisions')
    if not isinstance(records,list) or not records:raise ValueError('Missing source coverage evidence')
    if len({x.get('path') for x in records})!=len(records):raise ValueError('Duplicate source identity')
    root=root.resolve(strict=True)
    installed_version=''
    main=root/'mad4b-site-control-plane.php'
    if main.is_file():
        m=re.search(r'(?m)^\s*\* Version:\s*(\S+)',main.read_text(errors='replace'))
        installed_version=m.group(1) if m else ''
    pending=[];unexpected=[];covered=0;reviewed_successors=[]
    for row in records:
        path=row.get('path')
        if not isinstance(path,str) or not path or '\\' in path or any(x in ('.','..','') for x in path.split('/')):
            raise ValueError('Unsafe source path')
        if row.get('category') not in ('active_source','needs_classification'):
            raise ValueError('Non-source record added to protected ledger')
        if row.get('state') not in ('STAGING_ONLY','GITHUB_ONLY','DIVERGENT'):
            raise ValueError('Unknown divergence state')
        s=row.get('staging_sha256');g=row.get('github_sha256')
        for hashval in (s,g):
            if hashval is not None and not SHA.fullmatch(str(hashval)):raise ValueError('Invalid source hash')
        f=root/path
        if f.is_symlink():raise ValueError('Symlinked source path')
        current=digest(f) if f.is_file() else None
        state=row['state']
        if state=='STAGING_ONLY':
            if g is not None or s is None:raise ValueError('Invalid missing-source record')
            if current==s:covered+=1
            elif current is None:pending.append(path)
            elif reviewed_ci_only_source_successor(root,path,f.read_bytes()):
                pending.append(path)
                reviewed_successors.append(path)
            else:unexpected.append(path)
        elif state=='GITHUB_ONLY':
            if g is None or s is not None:raise ValueError('Invalid Git-only record')
            if current==g:pending.append(path) # Staging compatibility still unverified
            else:unexpected.append(path)
        else:
            if g is None or s is None:raise ValueError('Invalid divergent record')
            if current==s:covered+=1
            elif current==g:pending.append(path)
            elif current is not None and reviewed_ci_only_source_successor(root,path,f.read_bytes()):
                pending.append(path)
                reviewed_successors.append(path)
            else:unexpected.append(path)
    version_match=installed_version==evidence.get('staging_package_version')
    decision=('SOURCE_PARITY_CANDIDATE_UNCERTIFIED'
       if not pending and not unexpected and version_match else 'BLOCKED_SOURCE_RECONCILIATION')
    return {'contract':'mad4b.qa31.source-parity-gate-result.v1',
      'authorizing':False,'production_authorized':False,'source_parity_only':True,
      'decision':decision,'evidence_source_sha':evidence['github_base_source_sha'],
      'github_version':installed_version,'staging_version':evidence['staging_package_version'],
      'version_match':version_match,'tracked_source_records':len(records),
      'merged_matching_staging_hash':covered,'pending_count':len(pending),'unexpected_count':len(unexpected),
      'reviewed_github_successor_count':len(reviewed_successors),'reviewed_github_successor_paths':reviewed_successors,
      'pending_paths':pending,'unexpected_paths':unexpected}
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--evidence',required=True);p.add_argument('--github-plugin-root',required=True)
    p.add_argument('--strict',action='store_true')
    p.add_argument('--out')
    a=p.parse_args();e=json.loads(Path(a.evidence).read_text())
    r=evaluate(e,Path(a.github_plugin_root))
    if a.out:Path(a.out).write_text(json.dumps(r,indent=2)+'\n')
    print(json.dumps({k:v for k,v in r.items() if k not in ('pending_paths','unexpected_paths')},indent=2))
    if r['unexpected_count']>0:return 3
    if a.strict and r['decision']!='SOURCE_PARITY_CANDIDATE_UNCERTIFIED':return 4
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError) as exc:
        print('QA31_PARITY_FAIL_CLOSED: '+str(exc),file=sys.stderr);sys.exit(3)
