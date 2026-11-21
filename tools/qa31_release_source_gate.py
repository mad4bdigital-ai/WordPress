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

def reviewed_rc129_dormant_ollama_bootstrap_successor(repo:Path,path:str,contents:bytes):
    """Exact one-line Original305 successor, reviewed for CI inventory only.

    A new bootstrap blob is *not* a Production release receipt or permission
    to alter a historical QA31 / Host Agent review. Reconstruct the exact
    independently reviewed parent instead of trusting an arbitrary new SHA.
    """
    if path!='mad4b-site-control-plane.php' or not isinstance(contents,bytes):
        return None
    manifest_path=repo/'governance/release-parity/qa31-rc129-dormant-ollama-bootstrap-successor-20261119.json'
    host_path=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
    current_sha='5ad46a5c6028fdc2a31e74e48e16f54423e3d38666bc2276131b84a6c1c50119'
    parent_sha='9e20c4a6eb57b884bf31c5959a4bc7895717b0d9497d94def27c0e41343f7977'
    current_blob='2c497ec72c57f420d1abf1eef242748be40f7bb2'
    parent_blob='dcae0a0a32495bfcedd4300fd78a8ec765235697'
    old=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-ollama-ability.php';"
    replacement=b'// Local Ollama remains source-only until an explicit local owner activation.'
    test='tests/qa31_dormant_ollama_bootstrap_successor_contract.py'
    coverage_test='tests/source-unification-review-contract.py'
    try:
        m=json.loads(manifest_path.read_text(encoding='utf-8'))
        host=json.loads(host_path.read_text(encoding='utf-8'))
        previous=next((e for e in host['source_entries']
                       if e.get('path')==path),None)
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        ids=m.get('control_ids')
        if (m.get('contract')!='mad4b.qa31-rc129-dormant-ollama-bootstrap-review.v1'
            or m.get('source_commit')!='cba6f6547ac0700dbf4eb16c75668bb2c4d86b02'
            or m.get('previous_review_path')!='governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
            or m.get('previous_review_sha256')!=digest(host_path)
            or m.get('source_path')!=path
            or m.get('previous_git_blob_sha')!=parent_blob
            or m.get('previous_sha256')!=parent_sha
            or m.get('current_git_blob_sha')!=current_blob
            or m.get('current_sha256')!=current_sha
            or m.get('exact_delta')!='one-only: old Ollama require becomes a dormant source-only comment'
            or m.get('required_regression')!=test
            or m.get('coverage_regression')!=coverage_test
            or not isinstance(ids,list) or ids!=['AI-01','GOVERNANCE-01','SECURITY-01']
            or not set(ids)<=known or not m.get('owner_role')
            or len(str(m.get('review_rationale','')))<90
            or m.get('reviewed_for_ci_only') is not True
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('live_evidence_required') is not True
            or m.get('is_build_provenance') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or previous is None or previous.get('current_blob_sha')!=parent_blob
            or previous.get('current_sha256')!=parent_sha
            or contents.count(replacement)!=1 or old in contents
            or hashlib.sha256(contents).hexdigest()!=current_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=current_blob):
            return None
        restored=contents.replace(replacement,old)
        if (hashlib.sha256(restored).hexdigest()!=parent_sha
            or hashlib.sha1(b'blob '+str(len(restored)).encode()+b'\0'+restored).hexdigest()!=parent_blob
            or not (repo/test).is_file() or (repo/test).is_symlink()):
            return None
        # The already-reviewed exact blob is registered under its immutable
        # source-unification suite. QA31 separately runs the new strict review;
        # do not duplicate or rewrite historical capability-coverage evidence.
        return coverage_test
    except (OSError,ValueError,KeyError,TypeError):
        return None


def reviewed_rc129_wayfa_source_followup_successor(repo:Path,path:str,contents:bytes):
    """Finite post-rc129 CI-only source reviews; no promotion or runtime grants."""
    pins={
        'includes/class-mad4b-scp-content-readiness-alerts.php':(
            '7df661262c7b6592a86557babfdffab6e44cf64e',
            '75a905625b4ff78080c7018e28a4b1f05ea4fd60404526e0afbda5219e4356cf',
            '4d6800c533ebfb769210ca71fcb121e1cc712f11',
            '5fd112b0dcdf0d0e1ebfb8107be0fff4c03727823357353d7b3cce68388c78f4',
            '746e7562f99a97f1a5e11168854441464f02eeca',
            'wp-content/plugins/mad4b-site-control-plane/tests/content-readiness/isolated-contract.php',
            ['AI-01','LOCALIZATION-01','PROVIDERS-02','SECURITY-01']),
        'includes/class-mad4b-scp-skills-admin-ui.php':(
            '6db5aced6e2e50179ac5415ae9e74735756e539d',
            '4c562fd3c62a3f70d1726bab8e20db205b06e8a2ddef2547de5f1cb235f3762c',
            'e0eec63d312afe5abaf427736a12472f9e31f72b',
            '85d2b11eecaccd55b2086a635a7b3bfd4b3ef7837b851546c7b661bac7a19002',
            'd4b78b7da3bad4940d921540b47649b8d4ce7b2e',
            'tests/skills-external-source-admin-source-contract.py',
            ['ADMIN-01','BROWSER-01','SECURITY-02']),
    }
    if not isinstance(contents,bytes) or path not in pins:return None
    try:
        reviewed=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
        source=repo/'governance/release-parity/qa31-rc129-wayfa-followup-source-successors-20261119.json'
        m=json.loads(source.read_text(encoding='utf-8'))
        previous=json.loads(reviewed.read_text(encoding='utf-8'))
        entries=m.get('reviewed_entries')
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-rc129-wayfa-source-followup-reviews.v1'
            or m.get('prior_review_path')!='governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
            or m.get('prior_review_sha256')!=digest(reviewed)
            or m.get('scope')!='exactly two source revisions, not build provenance'
            or m.get('exact_source_files')!=2
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or not isinstance(entries,list) or len(entries)!=2
            or [e.get('path') for e in entries]!=sorted(pins)):
            return None
        prior={e.get('path'):e for e in previous['reviewed_entries']}
        for row in entries:
            name=row['path']
            old_blob,old_sha,new_blob,new_sha,commit,test,ids=pins[name]
            old=prior[name]
            if (old['current_git_blob_sha']!=old_blob
                or old['current_sha256']!=old_sha
                or row.get('parent_git_blob_sha')!=old_blob
                or row.get('parent_sha256')!=old_sha
                or row.get('current_git_blob_sha')!=new_blob
                or row.get('current_sha256')!=new_sha
                or row.get('source_commit')!=commit
                or row.get('required_regression')!=test
                or row.get('control_ids')!=ids
                or not set(ids)<=known
                or len(str(row.get('review_rationale','')))<90
                or not (repo/test).is_file() or (repo/test).is_symlink()):
                return None
        old_blob,old_sha,new_blob,new_sha,commit,test,ids=pins[path]
        if (hashlib.sha256(contents).hexdigest()!=new_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=new_blob):
            return None
        return test
    except (OSError,ValueError,KeyError,TypeError):
        return None


def reviewed_rc129_company_staff_ceiling_successor(repo:Path,path:str,contents:bytes):
    """One exact staff-capability tightening successor, CI-only and non-authorizing."""
    name='includes/class-mad4b-scp-company-master-admin.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-rc129-company-staff-ceiling-source-successor-20261119.json'
    historical=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
    parent_blob='546fd5e6a4f81ce7dfc5e67fcde294b338ddef40'
    parent_sha='0cfe68ea11598587a9cdaeed2313859cb95dff893b3972d2bb6a593fd2af4e4d'
    current_blob='f5f3dc802c4f1931b258257920b908a4f3b6bb59'
    current_sha='e5da7ea83d8c2f0290dfddfcf9c52bc585dd8a24db12bb844cde04bbb8766fff'
    test='tests/source-unification-review-contract.py'
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        source=json.loads(historical.read_text(encoding='utf-8'))
        row=next(x for x in source['source_decisions'] if x.get('path')==path)
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        ids=['ADMIN-01','BROWSER-01','SECURITY-02']
        if (m.get('contract')!='mad4b.qa31-rc129-company-staff-ceiling-source-review.v1'
            or m.get('path')!=path
            or m.get('historical_qa31_path')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or m.get('historical_qa31_sha256')!=digest(historical)
            or row.get('state')!='STAGING_ONLY'
            or row.get('staging_sha256')!=parent_sha
            or row.get('github_sha256') is not None
            or m.get('previous_blob_sha')!=parent_blob
            or m.get('previous_sha256')!=parent_sha
            or m.get('current_blob_sha')!=current_blob
            or m.get('current_sha256')!=current_sha
            or m.get('source_commit')!='4be344cd5ea6df9f73d64d130fe0d9fc30c7a7c7'
            or m.get('required_regression')!=test
            or m.get('control_ids')!=ids or not set(ids)<=known
            or len(str(m.get('review_rationale','')))<90
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or not (repo/test).is_file() or (repo/test).is_symlink()
            or hashlib.sha256(contents).hexdigest()!=current_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=current_blob):
            return None
        # The reviewed source has no role-grant escape hatch: both creation
        # and the visible role selector require the exact caller ceiling.
        required=(b'public static function roleWithinCaller(string $role):bool',
                  b"if($role==='operator')return false",
                  b'!current_user_can($cap)',
                  b'if(!self::roleWithinCaller($role))',
                  b'if(self::roleWithinCaller($role))')
        if not all(x in contents for x in required):return None
        return test
    except (OSError,ValueError,KeyError,TypeError,StopIteration):
        return None


def reviewed_pr997_wayfa_addon_site_bound_ring_source(repo:Path,path:str,contents:bytes):
    """Exact, nonauthorizing review: dynamic Addon rings remain tenant-bound."""
    name='includes/class-mad4b-scp-addon-registry.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-pr997-wayfa-addon-site-bound-source-20261121.json'
    parent='532fbd467a94232a5460f0d2d53ceff86c3414f9'
    prior='governance/release-parity/qa31-pr995-wayfa-skill-cimd-source-20261121.json'
    frozen_prior='617a736ddf34f9dc7309be1a30ef875ec682de846bdd32821b438a0b8ed3daff'
    frozen_live='bb62947b1927581683f74541c6f8cf2cdebe5e33b80259beae5c1eb6b88df813'
    old_blob='4db830176dc8da77c29e954a0783b7e4a0bab2d2'
    old_sha='86e9edbf083ecc4c5748239aba0f228557f737e0ef6412ed00d57aaeb284aa46'
    new_blob='b901793c67eba29764311e0fae5af016531c96a0'
    new_sha='8153b244678120e53937e8ca13bf36158e7273b5f8714a9128be9ff728f036c5'
    regression='wp-content/plugins/mad4b-site-control-plane/tests/wordpress-addon-execution-guard-contract.php'
    negative='tests/qa31_wayfa_addon_site_bound_ring_source_contract.py'
    spec='specs/007-content-intelligence-workflow-platform/contracts/wordpress-addon-manifest.md'
    control_ids=['GOVERNANCE-01','SECURITY-01','PLATFORM-01','EXECUTION-01','CROSSPLUGIN-01','UPDATES-01','RECOVERY-01','ROLLOUT-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        known={c['id'] for c in json.loads((repo/'governance/production-readiness/controls.json').read_text()).get('controls',[]) if isinstance(c,dict)}
        if (m.get('contract')!='mad4b.qa31-pr997-wayfa-addon-site-bound-ring.v1'
            or m.get('base_head_sha')!=parent
            or m.get('prior_source_review_path')!=prior
            or m.get('prior_source_review_sha256')!=frozen_prior
            or hashlib.sha256((repo/prior).read_bytes()).hexdigest()!=frozen_prior
            or m.get('source_path')!=name
            or m.get('original_parent_blob_sha')!=old_blob
            or m.get('original_parent_sha256')!=old_sha
            or m.get('reviewed_blob_sha')!=new_blob
            or m.get('reviewed_sha256')!=new_sha
            or m.get('frozen_wayfatours_live_sha256')!=frozen_live
            or m.get('frozen_wayfatours_live_source_basename')!='wayfa-control-plane-live-before-reconciliation.tar.gz'
            or m.get('regression')!=regression
            or m.get('negative_contract')!=negative
            or m.get('spec_path')!=spec
            or m.get('control_ids')!=control_ids or not set(control_ids)<=known
            or m.get('source_only') is not True or m.get('reviewed_for_ci_only') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or m.get('live_host_evidence_required') is not True
            or any(m.get(key) is not False for key in (
                'production_authorized','release_approved','host_anchor_issued',
                'addon_activated','addon_deployed','execution_grant_created',
                'wordpress_mutated','source_promoted','legacy_rings_modified'))
            or len(m.get('reason',''))<780
            or not all((repo/p).is_file() and not (repo/p).is_symlink() for p in (regression,negative,spec))
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],
                cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0):
            return None
        old=subprocess.check_output(
            ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if (hashlib.sha256(old).hexdigest()!=old_sha
            or hashlib.sha1(b'blob '+str(len(old)).encode()+b'\0'+old).hexdigest()!=old_blob
            or hashlib.sha256(contents).hexdigest()!=new_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=new_blob):
            return None
        code=contents.decode('utf-8')
        for marker in (
            'private static function enrolled_site_binding()',
            "'mode' => 'site_bound_certified_pair'",
            "'unknown_pair_fallback' => 'shadow'",
            "'production_authorized' => false",
            'site_urls_match_enrollment',
            '$certified_pair = 1 === $matching_certificates',
            "'effective_release_ring' => $effective_ring",
            "'mutating_capabilities_eligible' => 'certified' === $state",
            "'active' === $effective_ring",
            'mad4b_addon_release_ring_not_active',
            'public static function mutation_guard('):
            if marker not in code:return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr995_wayfa_skill_cimd_source(repo:Path,path:str,contents:bytes):
    """Exact source-only first-party ChatGPT CIMD/Skill observer compatibility."""
    name='includes/class-mad4b-scp-skill-abilities.php'
    if path!=name or not isinstance(contents,bytes):return None
    evidence=repo/'governance/release-parity/qa31-pr995-wayfa-skill-cimd-source-20261121.json'
    parent='18f4d734558b82d12ce436dd05fcd23efcd9e4a0'
    previous='governance/release-parity/qa31-pr990-wayfa-selective-operator-selfupdate-source-20261121.json'
    previous_sha='f3a40adaa33ebd49a83d0876f995066070d9b0a6987b42ab192f1fbb8b35d6b4'
    original_blob='e2fcd06a87017f40706c9a31da083ad81ee80264'
    reviewed_blob='ade041467c097f1a0b3d7c322a698f403388a177'
    original_sha='59debec476bff113a6e149a2702c0d8a22696c21d8e7265f22a2c8a24eb4709a'
    reviewed_sha='1de9fb010b98a5db7dc95c5e2f6b8608bb58aede0f0e2a8ab32fa72e76237508'
    regression='wp-content/plugins/mad4b-site-control-plane/tests/skill-external-cimd-binding-hermetic.php'
    negative='tests/qa31_wayfa_skill_external_cimd_source_contract.py'
    controls=['SECURITY-01','EXECUTION-01','MCP-01','AUTOMATION-01','PLATFORM-01','PERFORMANCE-01']
    try:
        m=json.loads(evidence.read_text())
        known={x['id'] for x in json.loads((repo/'governance/production-readiness/controls.json').read_text()).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr995-wayfa-skill-cimd-client-source.v1'
            or m.get('base_head_sha')!=parent
            or m.get('prior_source_review_path')!=previous
            or m.get('prior_source_review_sha256')!=previous_sha
            or hashlib.sha256((repo/previous).read_bytes()).hexdigest()!=previous_sha
            or m.get('source_path')!=name
            or m.get('original_parent_blob_sha')!=original_blob
            or m.get('original_parent_sha256')!=original_sha
            or m.get('reviewed_blob_sha')!=reviewed_blob
            or m.get('reviewed_sha256')!=reviewed_sha
            or m.get('required_regression')!=regression
            or m.get('negative_contract')!=negative
            or m.get('control_ids')!=controls or not set(controls)<=known
            or m.get('source_only') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('live_host_evidence_required') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<450
            or any(m.get(k) is not False for k in (
                'authority_issued','production_authorized','release_approved',
                'grants_created','oauth_client_registered','external_snapshot_persisted',
                'live_wordpress_modified'))
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],
                cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                timeout=8).returncode!=0):return None
        original=subprocess.check_output(
            ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if (hashlib.sha256(original).hexdigest()!=original_sha
            or hashlib.sha1(b'blob '+str(len(original)).encode()+b'\0'+original).hexdigest()!=original_blob
            or hashlib.sha256(contents).hexdigest()!=reviewed_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=reviewed_blob):return None
        code=contents.decode('utf-8')
        for mark in (
            'MAD4B_SCP_Local_OAuth_Server::trusted_chatgpt_client_ids_match(',
            'MAD4B_SCP_Local_OAuth_Server::trusted_chatgpt_client_id(',
            "'external_client_server_binding_mismatch'",
            'self::SERVER_ID !==',
            'public static function finalize_external_snapshot_observation()',
            'public static function dynamic_snapshot_status()'):
            if mark not in code:return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr990_wayfa_selective_operator_selfupdate_source(repo:Path,path:str,contents:bytes):
    """Two exact source-only differences; never an Operator/Release authorization."""
    prefix='wp-content/plugins/mad4b-site-control-plane/'
    evidence=repo/'governance/release-parity/qa31-pr990-wayfa-selective-operator-selfupdate-source-20261121.json'
    parent='784ae71e5a088ee299fe4db20912f0d8fe21932e'
    previous='governance/release-parity/qa31-pr986-wayfa-host-pinned-uuid-source-20261120.json'
    control_ids=['GOVERNANCE-02', 'SECURITY-01', 'EXECUTION-01', 'PLATFORM-01', 'UPDATES-01', 'CROSSPLUGIN-01', 'RECOVERY-01', 'ROLLOUT-01']
    required={
        'includes/class-mad4b-scp-operator-grant-convergence.php':
            'tests/remote-execution/operator-grants-policy-binding-hermetic.php',
        'includes/class-mad4b-scp-self-update.php':
            'wp-content/plugins/mad4b-site-control-plane/tests/self-update-native-candidate-trust-hermetic.php',
    }
    if path not in required or not isinstance(contents,bytes):return None
    try:
        m=json.loads(evidence.read_text(encoding='utf-8'))
        controls={x['id'] for x in json.loads(
            (repo/'governance/production-readiness/controls.json').read_text()
            ).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr990-wayfa-selective-operator-selfupdate-source.v1'
            or m.get('base_head_sha')!=parent or m.get('prior_review_path')!=previous
            or m.get('prior_review_sha256')!='02b3103d662ad4e0d037544c6c9ea312fc9b5f4d21b68fb1dc240132f576d386'
            or hashlib.sha256((repo/previous).read_bytes()).hexdigest()!=m.get('prior_review_sha256')
            or not isinstance(m.get('source_paths'),dict)
            or set(m['source_paths'])!=set(required)
            or m.get('related_fixture')!='tests/remote-execution/operator-grants-convergence-smoke.php'
            or m.get('adversarial_regression')!='tests/qa31_wayfa_selective_operator_selfupdate_source_contract.py'
            or m.get('controls')!=control_ids or not set(control_ids)<=controls
            or m.get('scope')!='selective manual source reconciliation; no version approval or deployment'
            or m.get('source_only') is not True or m.get('reviewed_for_ci_only') is not True
            or m.get('live_host_evidence_required') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<630
            or any(m.get(key) is not False for key in (
                'release_approved','production_authorized','host_grant_issued',
                'operator_grants_written','update_executed','mail_sent',
                'legacy_v1_signed_plan_modified','untrusted_candidate_promoted',
                'changes_live_wordpress'))
            or not (repo/m['adversarial_regression']).is_file()
            or not (repo/m['related_fixture']).is_file()
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],cwd=repo,
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0):
            return None
        for key,reg in required.items():
            row=m['source_paths'][key]
            if (not isinstance(row,dict) or row.get('regression')!=reg
                or not (repo/reg).is_file() or (repo/reg).is_symlink()):
                return None
            old=subprocess.check_output(
                ['git','show',parent+':'+prefix+key],cwd=repo,
                stderr=subprocess.DEVNULL,timeout=8)
            current=(repo/prefix/key).read_bytes()
            if (row.get('original_sha256')!=hashlib.sha256(old).hexdigest()
                or row.get('original_blob_sha')!=hashlib.sha1(
                    b'blob '+str(len(old)).encode()+b'\0'+old).hexdigest()
                or row.get('reviewed_sha256')!=hashlib.sha256(current).hexdigest()
                or row.get('reviewed_blob_sha')!=hashlib.sha1(
                    b'blob '+str(len(current)).encode()+b'\0'+current).hexdigest()):
                return None
        row=m['source_paths'][path]
        if (hashlib.sha256(contents).hexdigest()!=row['reviewed_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=row['reviewed_blob_sha']):
            return None
        code=contents.decode('utf-8')
        markers=(
            (
                "'expected_policy_sha256' => array(",
                "'policy_sha256' => $policy_v2['governance_policy_sha256']",
                "'mad4b_operator_grants_policy_changed'",
                "'mad4b_operator_grants_policy_recheck_changed'",
                "MAD4B_SCP_Registered_Write_Admission::preflight( self::ABILITY )",
                "MAD4B_SCP_Developer_Authority::apply( $canonical )",
            ) if path.startswith('includes/class-mad4b-scp-operator-grant') else (
                "'wordpress_native_candidate_upload' === $plan['channel']",
                'self::fetch_manifest( true )',
                'self::trusted_master_release_candidate(',
                'if ( $trusted_fields ) $apply_target = array_merge( $apply_target, $trusted_fields );',
                'self::apply_verified_archive( $tmp, $apply_target',
            )
        )
        if not all(marker in code for marker in markers):return None
        if path.startswith('includes/class-mad4b-scp-operator-grant'):
            new=code[code.index('// The policy reviewed at PLAN'):
                     code.index('$canonical = array(',code.index('// The policy reviewed at PLAN'))]
            if any(bad in new for bad in ('update_option(', 'wp_mail(', 'wp_schedule_event(')):
                return None
        else:
            new=code[code.index('// Selective Wayfatours parity:'):
                     code.index('self::apply_verified_archive( $tmp, $apply_target',
                         code.index('// Selective Wayfatours parity:'))]
            if any(bad in new for bad in ('update_option(', 'wp_mail(', 'wp_schedule_event(')):
                return None
        return row['regression']
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr986_wayfa_host_pinned_uuid_source(repo:Path,path:str,contents:bytes):
    """SHA-pinned Host UUID first-enrollment compatibility, CI-only."""
    name='includes/class-mad4b-scp-site-profile.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-pr986-wayfa-host-pinned-uuid-source-20261120.json'
    parent='d143c734ab6ecd8bc8b29802110cb56dbd39ffee'
    prior='governance/release-parity/qa31-pr985-wayfa-indexed-scan-readback-source-20261120.json'
    prior_sha='44a3b1309bf6861ba570802188738b6f7595f689c86a28d34e36bcac121c9f21'
    before_blob='511317c6d76a1b19e155aab05c0c6bb581b7fa14'
    before_sha='889e9719141f59b5232bd1dd992190d4c7173d3cb65964437fa2b6890c9893ea'
    after_blob='0bf69fc5cabf2b9ef061f0a2e322cec576f01ac3'
    after_sha='8aeff027196d6b6bf281ebc7a281e3503462a5d4e382154fd04788e563764187'
    regression='wp-content/plugins/mad4b-site-control-plane/tests/site-profile-host-pinned-uuid-hermetic.php'
    negative='tests/qa31_wayfa_host_pinned_site_uuid_source_contract.py'
    ids=['GOVERNANCE-02','SECURITY-01','PLATFORM-01','ADMIN-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        controls={x['id'] for x in json.loads((repo/'governance/production-readiness/controls.json').read_text()).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr986-wayfa-host-pinned-uuid-source.v1'
            or m.get('original_github_head_sha')!=parent
            or m.get('prior_context_readback_review_path')!=prior
            or m.get('prior_context_readback_review_sha256')!=prior_sha
            or hashlib.sha256((repo/prior).read_bytes()).hexdigest()!=prior_sha
            or m.get('source_path')!=name
            or m.get('original_parent_blob_sha')!=before_blob
            or m.get('original_parent_sha256')!=before_sha
            or m.get('reviewed_blob_sha')!=after_blob
            or m.get('reviewed_sha256')!=after_sha
            or m.get('required_regression')!=regression
            or m.get('negative_contract')!=negative
            or m.get('control_ids')!=ids or not set(ids)<=controls
            or m.get('source_only') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('host_uuid_claim_alone_authorizes') is not False
            or m.get('existing_profile_rebind_allowed') is not False
            or m.get('reserved_uuid_filter_bypassed') is not False
            or m.get('dual_uuid_claim_allowed') is not False
            or m.get('site_profile_write_performed') is not False
            or m.get('host_anchor_issued') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<450
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()):
            return None
        original=subprocess.check_output(
            ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if (hashlib.sha256(original).hexdigest()!=before_sha
            or hashlib.sha1(b'blob '+str(len(original)).encode()+b'\0'+original).hexdigest()!=before_blob
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],cwd=repo,
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0):
            return None
        if (hashlib.sha256(contents).hexdigest()!=after_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=after_blob):
            return None
        code=contents.decode('utf-8')
        for marker in (
            'function host_pinned_uuid_preflight( array $input, $origin,',
            '$host_pinned_uuid = self::host_pinned_uuid_preflight(',
            "'host_pinned_site_uuid'", "'MAD4B_TENANT_SITE_UUID'",
            "'MAD4B_TENANT_ORIGIN'", "'MAD4B_TENANT_COMPANY_CODE'",
            '|| $existing_valid', "self::wordpress_environment_explicit()",
            "'reserved_site_uuid'", "'mad4b_scp_authorized_reserved_site_uuid'",
            "$site_uuid = '' !== $host_pinned_uuid ? $host_pinned_uuid",
            'public static function save_current_site( array $input )'):
            if marker not in code:return None
        section=code[code.index('public static function host_pinned_uuid_preflight('):
                     code.index('public static function save_current_site(')]
        if any(needle in section for needle in ('update_option(', 'wp_mail(', 'wp_schedule_event(', 'wp_insert_user(', 'apply_filters(')):
            return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr985_wayfa_indexed_scan_readback_source(repo:Path,path:str,contents:bytes):
    """Bounded historical source-index readback, never a live Drive verdict."""
    name='includes/class-mad4b-scp-context-admin-ui.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-pr985-wayfa-indexed-scan-readback-source-20261120.json'
    parent='bd82ac3747f27d33b91633fab01bc4c1b52d7f9a'
    previous='governance/release-parity/qa31-pr983-wayfa-classification-reconcile-source-20261120.json'
    previous_sha='bfcbe564d739c3dc7ab0a3f369ab49068adb8f7673a6d8efe7943ead3ce36879'
    before_blob='2c7e9655dc3e41c1f28ba291ab6f4a8d48c1dd2b'
    after_blob='a1086650dbb739ff131829b30490eeb0ab371b0a'
    after_sha='6175b141bd483ddf656b69bcf5568b838c9d08ed6be1b620bfb03d19f14816b6'
    regression='wp-content/plugins/mad4b-site-control-plane/tests/context-indexed-scan-readback-hermetic.php'
    negative='tests/qa31_wayfa_indexed_scan_readback_source_contract.py'
    ids=['GOVERNANCE-02','SECURITY-01','ADMIN-01','AI-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        controls={x['id'] for x in json.loads((repo/'governance/production-readiness/controls.json').read_text()).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr985-wayfa-indexed-scan-readback-source.v1'
            or m.get('original_github_head_sha')!=parent
            or m.get('previous_context_classification_review_path')!=previous
            or m.get('previous_context_classification_review_sha256')!=previous_sha
            or hashlib.sha256((repo/previous).read_bytes()).hexdigest()!=previous_sha
            or m.get('source_path')!=name
            or m.get('original_parent_blob_sha')!=before_blob
            or m.get('reviewed_blob_sha')!=after_blob
            or m.get('reviewed_sha256')!=after_sha
            or m.get('required_regression')!=regression
            or m.get('negative_contract')!=negative
            or m.get('control_ids')!=ids or not set(ids)<=controls
            or m.get('source_only') is not True or m.get('reviewed_for_ci_only') is not True
            or m.get('authorizing') is not False or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('oauth_refresh_on_get_allowed') is not False
            or m.get('scan_on_get_allowed') is not False
            or m.get('automatic_context_approval_authorized') is not False
            or m.get('historical_readback_grants_drive_freshness') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<380
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()):
            return None
        previous_source=subprocess.check_output(
            ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if (hashlib.sha256(previous_source).hexdigest()!=m['original_parent_sha256']
            or hashlib.sha1(b'blob '+str(len(previous_source)).encode()+b'\0'+previous_source).hexdigest()!=before_blob
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],cwd=repo,
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0):
            return None
        if (hashlib.sha256(contents).hexdigest()!=after_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=after_blob):
            return None
        code=contents.decode('utf-8')
        for marker in (
            'function indexed_scan_evidence( array $source, array $progress,',
            'function indexed_scan_readback( array $source, array $progress )',
            'mad4b.scan-ui-progress.v1',
            'Previously indexed subset verified',
            'Historical Audit only; current Google Drive changes not checked.',
            'current_google_files_verified',
            "'source_rescanned' => false, 'review_modified' => false",
            "'authorizing' => false, 'registry_mutation_performed' => false",
            'ORDER BY id DESC LIMIT 100',
            'self::indexed_scan_readback( $source, $progress )'):
            if marker not in code:return None
        # A readback cannot trigger the current scan planner or approval.
        new_block=code[code.index('public static function indexed_scan_evidence('):
                       code.index('private static function render_sources()')]
        if ('source_scan_plan(' in new_block or 'source_scan_apply(' in new_block
            or 'update_option(' in new_block or 'wp_mail(' in new_block
            or 'refresh_token(' in new_block):
            return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr983_wayfa_classification_reconcile_source(repo:Path,path:str,contents:bytes):
    """Single exact local-only Context classification repair. Never release authority."""
    name='includes/class-mad4b-scp-context-authority.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-pr983-wayfa-classification-reconcile-source-20261120.json'
    parent='ec422eae1706a697e2b1d379f2dc071c16ba1ceb'
    previous='governance/release-parity/qa31-rc129-context-title-authority-successor-20261119.json'
    previous_sha='e9b84503655589365ccab7d493cb2aca154437b2843754e44b3745c4be750e26'
    previous_blob='8d5e50c17a780ce580a18be5e3d2912a2e06920c'
    previous_content_sha='ea788a5b7197991999ed2aff6a9b9e449fafc522eb4654f44d190010b795eff1'
    current_blob='cd451220793dbbf3325bc5cd7c9dfcfc6a88e8e8'
    current_sha='1cb4819fa531d362d4d22b64e1226ec18ac3bbdd04c43ff29f6784a0520e7727'
    regression='wp-content/plugins/mad4b-site-control-plane/tests/context-classification-reconcile-hermetic.php'
    negative='tests/qa31_wayfa_classification_reconcile_source_contract.py'
    controls=['GOVERNANCE-02','SECURITY-01','AI-01','PLATFORM-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        known={x['id'] for x in json.loads((repo/'governance/production-readiness/controls.json').read_text()).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr983-wayfa-classification-reconcile-source.v1'
            or m.get('original_github_head_sha')!=parent
            or m.get('previous_context_title_review_path')!=previous
            or m.get('previous_context_title_review_sha256')!=previous_sha
            or hashlib.sha256((repo/previous).read_bytes()).hexdigest()!=previous_sha
            or m.get('source_path')!=name
            or m.get('original_parent_blob_sha')!=previous_blob
            or m.get('original_parent_sha256')!=previous_content_sha
            or m.get('reviewed_blob_sha')!=current_blob
            or m.get('reviewed_sha256')!=current_sha
            or m.get('required_regression')!=regression
            or m.get('negative_contract')!=negative
            or m.get('control_ids')!=controls or not set(controls)<=known
            or m.get('source_only') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('automatic_context_approval_authorized') is not False
            or m.get('remote_mutation_ability_added') is not False
            or m.get('original_human_review_mutation_authorized') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<350
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()):
            return None
        original=subprocess.check_output(['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        # Revalidate the entire immutable predecessor title/Host chain.
        # A new reviewed source must not hide tampering with older QA31 evidence.
        if reviewed_rc129_context_title_authority_successor(repo,path,original) != (
            'wp-content/plugins/mad4b-site-control-plane/tests/context-classification-title-contract.php'):
            return None
        if (hashlib.sha256(original).hexdigest()!=previous_content_sha
            or hashlib.sha1(b'blob '+str(len(original)).encode()+b'\0'+original).hexdigest()!=previous_blob
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],cwd=repo,
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0):return None
        if (hashlib.sha256(contents).hexdigest()!=current_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()!=current_blob):return None
        code=contents.decode('utf-8')
        for marker in (
            'function classification_reconcile_preview(array $source, array $site,',
            'function classification_reconcile_plan($source_id)',
            'function classification_reconcile_apply(array $request)',
            "'classification_source'] ?? '') !== 'automatic_heuristic'",
            "'review_status'] ?? '') !== 'unreviewed'",
            "!defined('WP_CLI')", "self::with_registry_lock('classification_reconcile'",
            "self::commit_option_changes(array(self::ASSETS_OPTION => $records)",
            "'approval_granted' => false, 'content_modified' => false",
            "'source_rescanned' => false", "'authorizing' => false"):
            if marker not in code:return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr970_wayfa_drive_exact_evidence_source(repo:Path,path:str,contents:bytes):
    """Exact SHA-pinned original Google Drive evidence only, not a release."""
    manifest=repo/'governance/release-parity/qa31-pr970-wayfa-drive-exact-evidence-source-20261120.json'
    parent='beececa3eeafe784b1bbeee21844a73755fa7b17'
    prior_media='governance/release-parity/qa31-rc129-context-media-successors-20261118.json'
    prior_media_sha='04d37d3c6dfcdc66e74f42399a445161b98659a981c4f22afcd7a0dbdc54fa02'
    paths={
        'includes/class-mad4b-scp-google-drive-context.php':(
            'baa55d92c01aeaaaeb47c815c96a0c492ee49f24',
            '440edf20b7588f30a834b6fa21f616a6835cb64bb3b9404e9b7b5629a5db9e89',
            '9e28d611064381ce9a85413d9390fb049e4b6b6d',
            'ce7e60da92094e6f366f9e5edf6341fd090f1ba52543c3777e573f43eb295b97',
            'wp-content/plugins/mad4b-site-control-plane/tests/google-drive-exact-review-provider-hermetic.php'),
        'includes/class-mad4b-scp-ai-review-lifecycle.php':(
            'e4f86724f02bd2d92ddffbd47b91268fa1a09bc6',
            '273bcb162bcd1e45a428bf9163e4c0d54e7029f9d0552b0dc7d6650fe8a5bed6',
            'e249286f0a0f3e82432c4327982891e20ca77bb4',
            '80f621df063ff2376b8f5f34cbf110b3ec47b85b100adbafeba795b5b6681823',
            'wp-content/plugins/mad4b-site-control-plane/tests/ai-review-drive-exact-evidence-hermetic.php')
    }
    if path not in paths or not isinstance(contents,bytes):return None
    old_blob,old_sha,new_blob,new_sha,test=paths[path]
    controls=['GOVERNANCE-02','SECURITY-01','PLATFORM-01','EXECUTION-01']
    negative='tests/qa31_wayfa_drive_exact_evidence_source_contract.py'
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        entries=m.get('reviewed_source_entries')
        if (m.get('contract')!='mad4b.qa31-pr970-wayfa-drive-exact-evidence-source.v1'
            or m.get('original_github_head_sha')!=parent
            or m.get('negative_contract')!=negative
            or m.get('previous_visual_media_source_review_path')!=prior_media
            or m.get('previous_visual_media_source_review_sha256')!=prior_media_sha
            or hashlib.sha256((repo/prior_media).read_text(encoding='utf-8').encode('utf-8')).hexdigest()!=prior_media_sha
            or m.get('source_only') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('automated_drive_review_approval_authorized') is not False
            or m.get('oauth_refresh_in_read_path_allowed') is not False
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or len(m.get('review_rationale',''))<350
            or not isinstance(entries,list) or len(entries)!=2
            or [row.get('source_path') for row in entries]!=list(paths)
            or not (repo/negative).is_file()
            or (repo/negative).is_symlink()):
            return None
        known={item['id'] for item in json.loads(
            (repo/'governance/production-readiness/controls.json').read_text()
        ).get('controls',[]) if isinstance(item,dict)}
        if not set(controls)<=known:return None
        for row in entries:
            name=row['source_path']
            before_blob,before_sha,after_blob,after_sha,regression=paths[name]
            if (row.get('original_parent_blob_sha')!=before_blob
                or row.get('original_parent_sha256')!=before_sha
                or row.get('reviewed_blob_sha')!=after_blob
                or row.get('reviewed_sha256')!=after_sha
                or row.get('required_regression')!=regression
                or row.get('control_ids')!=controls
                or row.get('status')!='reviewed_for_ci_only'
                or row.get('production_authorized') is not False
                or row.get('live_evidence_required') is not True
                or len(row.get('review_rationale',''))<260
                or not (repo/regression).is_file()
                or (repo/regression).is_symlink()):
                return None
            parent_bytes=subprocess.check_output(
                ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
            if (hashlib.sha1(b'blob '+str(len(parent_bytes)).encode('ascii')+bytes([0])+parent_bytes).hexdigest()!=before_blob
                or hashlib.sha256(parent_bytes).hexdigest()!=before_sha):
                return None
        if subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8).returncode!=0:
            return None
        if (hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+bytes([0])+contents).hexdigest()!=new_blob
            or hashlib.sha256(contents).hexdigest()!=new_sha):
            return None
        code=contents.decode('utf-8')
        if path.endswith('class-mad4b-scp-google-drive-context.php'):
            for token in (
                'function exact_review_text_evidence(array $asset, array $source)',
                "||empty($connection['credential_fresh'])||!empty($connection['refresh_required'])",
                "||$site_uuid!==(string)($asset['site_uuid']??'')",
                "if(!hash_equals($hash,hash('sha256',$content)))",
                "||!hash_equals($source_id,(string)($current['source_id']??''))",
                "'mutation_performed'=>false,'publication_authorized'=>false"):
                if code.count(token)!=1:return None
        else:
            for token in (
                "($source['provider']??'')==='google_drive'",
                "MAD4B_SCP_Google_Drive_Context::exact_review_text_evidence($asset,$source)",
                "'mad4b_ai_evidence_registry_changed'",
                "'mad4b_ai_evidence_queue_unavailable'",
                "'publication_authorized'=>false"):
                if token not in code:return None
        return test
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr970_optional_host_catalog_recursion_successor(repo:Path,path:str,contents:bytes):
    """One exact CI-only source fix to prevent recursive optional Host lookup."""
    name='includes/class-mad4b-scp-server-core-catalog.php'
    if path!=name or not isinstance(contents,bytes):return None
    parent='75bcfef854c1bcad6b646a95d957a6d41cce0d40'
    old_blob='7f508bf4210fef1c80855475ac85eb0a81d67046'
    old_sha='6e955ced5a49cfc3224c3391e01a6581d7fe834e40a5eba7931efdfd845397c9'
    new_blob='a46d7b44a398edc9a7dfd41cb5532d9f51dfd67e'
    new_sha='726c2aaa970f324936a5dedacd439668625fd6e614a1f6e1ec2c7d2683266a5e'
    old_review='governance/release-parity/qa31-pr951-pr937-optional-host-catalog-merge-20261120.json'
    old_review_digest='4902789225512de72bd1630e777e4dd9991e8698210628fe055ea7e28864f8f3'
    regression='tests/authority/test-optional-source-read-catalog.php'
    extra=['tests/servers-native-core-catalog-hermetic.php',
           'tests/server-registration-descriptor-boundary-hermetic.php']
    negative='tests/qa31_pr970_optional_host_catalog_recursion_contract.py'
    controls=['MCP-01','SECURITY-01','EXECUTION-01','PLATFORM-01']
    manifest=repo/'governance/release-parity/qa31-pr970-optional-host-catalog-recursion-successor-20261120.json'
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        known={x['id'] for x in json.loads(
            (repo/'governance/production-readiness/controls.json').read_text()
        ).get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr970-optional-host-catalog-recursion-successor.v1'
            or m.get('source_path')!=name
            or m.get('frozen_parent_head_sha')!=parent
            or m.get('frozen_parent_source_blob_sha')!=old_blob
            or m.get('frozen_parent_source_sha256')!=old_sha
            or m.get('frozen_original_source_review_path')!=old_review
            or m.get('frozen_original_source_review_sha256')!=old_review_digest
            or m.get('reviewed_source_blob_sha')!=new_blob
            or m.get('reviewed_source_sha256')!=new_sha
            or m.get('required_regression')!=regression
            or m.get('additional_regressions')!=extra
            or m.get('negative_regression')!=negative
            or m.get('control_ids')!=controls or not set(controls)<=known
            or len(str(m.get('review_rationale','')))<500
            or m.get('status')!='reviewed_for_ci_only'
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or hashlib.sha256((repo/old_review).read_bytes()).hexdigest()!=old_review_digest
            or hashlib.sha1(b'blob '+str(len(contents)).encode()+bytes([0])+contents).hexdigest()!=new_blob
            or hashlib.sha256(contents).hexdigest()!=new_sha
            or any(not (repo/t).is_file() or (repo/t).is_symlink()
                   for t in [regression,negative]+extra)):
            return None
        old=subprocess.check_output(
            ['git','show',parent+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if (hashlib.sha256(old).hexdigest()!=old_sha
            or hashlib.sha1(b'blob '+str(len(old)).encode()+bytes([0])+old).hexdigest()!=old_blob
            or subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],
                              cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                              timeout=8).returncode!=0):
            return None
        code=contents.decode('utf-8')
        for marker in (
            'private static $optional_host_binding_in_progress = false;',
            'if ( ! self::$optional_host_binding_in_progress )',
            'self::$optional_host_binding_in_progress = true;',
            'self::$optional_host_binding_in_progress = false;',
            "MAD4B_SCP_Capability_Descriptor_Registry::binding( $ability_name, 'servers' )",
        ):
            if code.count(marker)!=1:return None
        if code.count('} finally {')<1:return None
        return regression
    except (OSError,ValueError,TypeError,KeyError,UnicodeError,
            subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_pr937_optional_host_catalog_merge_successor(repo:Path,path:str,contents:bytes):
    """Allow exactly the PR937 SHA-reviewed optional Host catalog, CI-only."""
    name='includes/class-mad4b-scp-server-core-catalog.php'
    if path!=name or not isinstance(contents,bytes):return None
    review=repo/'governance/release-parity/qa31-pr951-pr937-optional-host-catalog-merge-20261120.json'
    original937='a044005e3b960c874ad492276a07814bf66e350d'
    original951='1d8a9b4a491368c2f648a0f6e424f7972e20dce0'
    reviewed_blob='7f508bf4210fef1c80855475ac85eb0a81d67046'
    reviewed_sha='6e955ced5a49cfc3224c3391e01a6581d7fe834e40a5eba7931efdfd845397c9'
    old_blob='015180f657097cb717d48b1639d3b47a51401d59'
    primary='tests/authority/test-optional-source-read-catalog.php'
    others=['tests/servers-native-core-catalog-hermetic.php',
            'tests/server-registration-descriptor-boundary-hermetic.php',
            'tests/test_servers_single_owner_convergence.py']
    negative='tests/qa31_pr937_optional_host_catalog_merge_contract.py'
    controls=['MCP-01','SECURITY-01','EXECUTION-01','PLATFORM-01']
    try:
        m=json.loads(review.read_text(encoding='utf-8'))
        ids=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in ids.get('controls',[]) if isinstance(x,dict)}
        if (m.get('contract')!='mad4b.qa31-pr951-pr937-optional-host-catalog-merge.v1'
            or m.get('source_path')!=name
            or m.get('exact_original_pr937_head_sha')!=original937
            or m.get('exact_existing_pr951_head_sha')!=original951
            or m.get('original_pr937_source_blob_sha')!=reviewed_blob
            or m.get('original_pr937_source_sha256')!=reviewed_sha
            or m.get('original_pr951_source_blob_sha')!=old_blob
            or m.get('current_source_blob_sha')!=reviewed_blob
            or m.get('current_source_sha256')!=reviewed_sha
            or m.get('required_regression')!=primary
            or m.get('additional_regressions')!=others
            or m.get('qa31_negative_regression')!=negative
            or m.get('control_ids')!=controls or not set(controls)<=known
            or len(str(m.get('review_rationale','')))<300
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('original_release_admission_required') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+bytes([0])+contents).hexdigest()!=reviewed_blob
            or hashlib.sha256(contents).hexdigest()!=reviewed_sha
            or not (repo/primary).is_file() or (repo/primary).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()
            or any(not (repo/t).is_file() or (repo/t).is_symlink() for t in others)):
            return None
        original=subprocess.check_output(
            ['git','show',original937+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        previous=subprocess.check_output(
            ['git','show',original951+':wp-content/plugins/mad4b-site-control-plane/'+name],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8)
        if original!=contents:return None
        if hashlib.sha1(b'blob '+str(len(previous)).encode('ascii')+bytes([0])+previous).hexdigest()!=old_blob:
            return None
        # Require both exact original reviewed Git parents, not any claimed SHA.
        for parent in (original937,original951):
            if subprocess.run(['git','merge-base','--is-ancestor',parent,'HEAD'],
                              cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                              timeout=8).returncode!=0:
                return None
        code=contents.decode('utf-8')
        if (code.count("'mad4b/control-plane-test-route' => array( 'mad4b-read', 'mad4b-chatgpt' )")!=1
            or code.count("'mad4b/control-plane-test-route'")!=1
            or "wp_has_ability( $ability_name )" not in code
            or "MAD4B_SCP_Capability_Descriptor_Registry::binding( $ability_name, 'servers' )" not in code):
            return None
        return primary
    except (OSError,ValueError,KeyError,TypeError,StopIteration,
            UnicodeError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return None


def reviewed_rc129_company_email_native_preflight_successor(repo:Path,path:str,contents:bytes):
    """One exact title-/feed-bound, read-only company notice successor."""
    name='includes/class-mad4b-scp-company-email-routing.php'
    if path!=name or not isinstance(contents,bytes):return None
    previous=repo/'governance/release-parity/qa31-rc129-company-email-recipient-safety-successor-20261119.json'
    manifest=repo/'governance/release-parity/qa31-rc129-company-email-native-preflight-successor-20261120.json'
    old_blob='a722124f023290f6fe2f167cf04c02fb413e647d'
    old_sha='77379941659f3a87591d7fb545309cf18f7d0ea9dfc3a1ba7bde51ffbefafe83'
    new_blob='c3c4392cf805a5c4b4b10f12d699721256629e07'
    new_sha='aff9b7b3bde6f1628a964d984f26b5af9924b47ad3b6f82dd39ca458d9b357f0'
    frozen_parent_sha='b77fe30152dd2c4c977aae97402a6018c4be6ac2e2401d624b8ded9f22888845'
    regression='tests/company-email-routing-original-hermetic.php'
    negative='tests/qa31_company_email_native_preflight_successor_contract.py'
    ids=['GOVERNANCE-01','PLATFORM-01','SECURITY-01','EXECUTION-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        parent=json.loads(previous.read_text(encoding='utf-8'))
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        if (digest(previous)!=frozen_parent_sha
            or parent.get('contract')!='mad4b.qa31-rc129-company-email-recipient-safety.v1'
            or parent.get('prior_review_sha256')!='dce059a5058b66cc1cb5f6d4cd01aab24b1e6b75a8fba6a1655579de038ff821'
            or parent.get('prior_review_path')!='governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
            or parent.get('previous_source_git_blob_sha')!='65a3e14d0738e66bf86d9268fa6ba941523ce669'
            or parent.get('previous_source_sha256')!='094517b0cdf5e117dc6d6f1a7ea78a49d6e2cbfb4cd6c5fed473fa99051114a6'
            or parent.get('required_regression')!=regression
            or parent.get('control_ids')!=ids
            or parent.get('authorizing') is not False
            or parent.get('production_authorized') is not False
            or parent.get('source_promotion_approved') is not False
            or parent.get('is_build_provenance') is not False
            or parent.get('live_evidence_required') is not True
            or parent.get('reviewed_for_ci_only') is not True
            or parent.get('native_notification_writes_added') is not False
            or parent.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or m.get('contract')!='mad4b.qa31-rc129-company-email-native-preflight.v1'
            or m.get('source_path')!=name
            or m.get('parent_review_path')!='governance/release-parity/qa31-rc129-company-email-recipient-safety-successor-20261119.json'
            or m.get('parent_review_sha256')!=frozen_parent_sha
            or parent.get('current_source_git_blob_sha')!=old_blob
            or parent.get('current_source_sha256')!=old_sha
            or m.get('previous_source_git_blob_sha')!=old_blob
            or m.get('previous_source_sha256')!=old_sha
            or m.get('current_source_git_blob_sha')!=new_blob
            or m.get('current_source_sha256')!=new_sha
            or m.get('required_regression')!=regression
            or m.get('qa31_negative_regression')!=negative
            or m.get('test_count')!=44
            or m.get('control_ids')!=ids or not set(ids)<=known
            or len(str(m.get('review_rationale','')))<150
            or m.get('native_notification_writes_added') is not False
            or m.get('scheduled_jobs_added') is not False
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('source_release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()
            or hashlib.sha256(contents).hexdigest()!=new_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=new_blob):
            return None
        code=contents.decode('utf-8')
        a=code.index('public static function native_notice_preflight(')
        b=code.index('public static function readiness_issues()',a)
        preview=code[a:b]
        expected=(
            "if (!self::is_company_admin())",
            "'status' => 'BLOCKED'",
            "'read_only' => true",
            "'mutation_performed' => false",
            "'messages_sent' => 0",
            "'authorizing' => false",
            "count($managed) !== 1",
            "managed_feed_exact_binding_missing_or_stale",
            "company_email_identity_unverified",
            "native_form_not_published",
            "native_form_read_failed",
            "native_contact_provider_error",
            "ORDER BY id ASC LIMIT 120",
            "if (!is_array($rows) || count($rows) >= 120)",
            "true !== MAD4B_SCP_Company_Communication::canSend()",
            "hash_equals((string)($entry['source_sha256'] ?? ''), $hash)",
            "if ($formId !== self::native_booking_form_id() && !$boundForm && !$managed)",
            "'methods' => 'GET'",
        )
        if any(x not in code for x in expected):return None
        for dangerous in ('update_option(', 'wp_schedule_event(', 'SettingsService(', 'wp_mail('):
            if dangerous in preview:return None
        if 'return $managed ?' not in code or "if ($managed) return ''" not in code:
            return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,StopIteration,UnicodeError):
        return None


def reviewed_rc129_company_email_recipient_safety_successor(repo:Path,path:str,contents:bytes):
    """Exact non-authorizing live recipient policy hardening; no mail writes."""
    name='includes/class-mad4b-scp-company-email-routing.php'
    if path!=name or not isinstance(contents,bytes):return None
    previous=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
    manifest=repo/'governance/release-parity/qa31-rc129-company-email-recipient-safety-successor-20261119.json'
    old_blob='65a3e14d0738e66bf86d9268fa6ba941523ce669'
    old_sha='094517b0cdf5e117dc6d6f1a7ea78a49d6e2cbfb4cd6c5fed473fa99051114a6'
    new_blob='a722124f023290f6fe2f167cf04c02fb413e647d'
    new_sha='77379941659f3a87591d7fb545309cf18f7d0ea9dfc3a1ba7bde51ffbefafe83'
    frozen_prior_sha='dce059a5058b66cc1cb5f6d4cd01aab24b1e6b75a8fba6a1655579de038ff821'
    regression='tests/company-email-routing-original-hermetic.php'
    negative='tests/qa31_company_email_recipient_safety_successor_contract.py'
    ids=['GOVERNANCE-01','PLATFORM-01','SECURITY-01','EXECUTION-01']
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        prior_text=previous.read_text(encoding='utf-8')
        prior=json.loads(prior_text)
        old=next(e for e in prior['reviewed_entries'] if e.get('path')==path)
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        if (digest(previous)!=frozen_prior_sha
            or m.get('contract')!='mad4b.qa31-rc129-company-email-recipient-safety.v1'
            or m.get('source_path')!=name
            or m.get('prior_review_path')!='governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
            or m.get('prior_review_sha256')!=frozen_prior_sha
            or old.get('current_git_blob_sha')!=old_blob
            or old.get('current_sha256')!=old_sha
            or m.get('previous_source_git_blob_sha')!=old_blob
            or m.get('previous_source_sha256')!=old_sha
            or m.get('current_source_git_blob_sha')!=new_blob
            or m.get('current_source_sha256')!=new_sha
            or m.get('required_regression')!=regression
            or m.get('qa31_negative_regression')!=negative
            or m.get('control_ids')!=ids or not set(ids)<=known
            or len(str(m.get('review_rationale','')))<130
            or m.get('native_notification_writes_added') is not False
            or m.get('authorizing') is not False or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True or m.get('reviewed_for_ci_only') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or not (repo/negative).is_file() or (repo/negative).is_symlink()
            or hashlib.sha256(contents).hexdigest()!=new_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=new_blob):
            return None
        body=contents.decode('utf-8')
        section=body[body.index('public static function fluent_form_recipient('):body.index('public static function readiness_issues(')]
        needles=(
            "['reservation_company_v1', 'company_contact_v1'], true",
            "return $managed ? '' : $address;",
            "if ($formId !== 6 && !$boundForm && !$managed) return $address;",
            "if (true !== MAD4B_SCP_Company_Communication::canSend()) return '';",
            "if (count($matched) !== 1) return '';",
            "hash_equals((string)$binding['source_sha256']",
        )
        if not all(token in section for token in needles):return None
        return regression
    except (OSError,ValueError,KeyError,TypeError,StopIteration,UnicodeError):
        return None


def reviewed_rc129_context_title_authority_successor(repo:Path,path:str,contents:bytes):
    """Exact source-only Context classification hardening, no release authority."""
    name='includes/class-mad4b-scp-context-authority.php'
    if path!=name or not isinstance(contents,bytes):return None
    manifest=repo/'governance/release-parity/qa31-rc129-context-title-authority-successor-20261119.json'
    host_path=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
    parent_blob='037d6563a3a42561a69b30e8f5d450e40a2d4a40'
    parent_sha='c7e06a51d85ae3dde944d7e38eff3a34f456f25dc2e2f0f3510c5912f8aa3398'
    current_blob='8d5e50c17a780ce580a18be5e3d2912a2e06920c'
    current_sha='ea788a5b7197991999ed2aff6a9b9e449fafc522eb4654f44d190010b795eff1'
    frozen_host_sha='3184e983d42404f7792c1784dca621bba33570f8b199b1a22a78c1293f16493e'
    test='wp-content/plugins/mad4b-site-control-plane/tests/context-classification-title-contract.php'
    regression='tests/qa31_context_title_authority_successor_contract.py'
    try:
        m=json.loads(manifest.read_text(encoding='utf-8'))
        frozen_host_text=host_path.read_text(encoding='utf-8')
        host=json.loads(frozen_host_text)
        preceding=next(x for x in host['source_entries'] if x.get('path')==path)
        controls=json.loads((repo/'governance/production-readiness/controls.json').read_text())
        known={x['id'] for x in controls.get('controls',[]) if isinstance(x,dict)}
        ids=['GOVERNANCE-01','SECURITY-01','MCP-01','AI-01']
        if (m.get('contract')!='mad4b.qa31-rc129-context-title-only-authority.v1'
            or m.get('source_path')!=name
            or m.get('previous_review_path')!='governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
            or m.get('previous_review_sha256')!=digest(host_path)
            or m.get('previous_review_sha256')!=frozen_host_sha
            or digest(host_path)!=frozen_host_sha
            or hashlib.sha256(frozen_host_text.encode('utf-8')).hexdigest()!=frozen_host_sha
            or m.get('previous_source_git_blob_sha')!=parent_blob
            or m.get('previous_source_sha256')!=parent_sha
            or preceding.get('current_blob_sha')!=parent_blob
            or preceding.get('current_sha256')!=parent_sha
            or m.get('current_source_git_blob_sha')!=current_blob
            or m.get('current_source_sha256')!=current_sha
            or m.get('required_regression')!=test
            or m.get('qa31_negative_regression')!=regression
            or m.get('additional_regression')!=[
                'tests/context-authority-original-review-suite.py',
                'wp-content/plugins/mad4b-site-control-plane/tests/context-authority-contract.py']
            or m.get('control_ids')!=ids or not set(ids)<=known
            or len(str(m.get('review_rationale','')))<90
            or m.get('authorizing') is not False
            or m.get('production_authorized') is not False
            or m.get('source_promotion_approved') is not False
            or m.get('is_build_provenance') is not False
            or m.get('live_evidence_required') is not True
            or m.get('reviewed_for_ci_only') is not True
            or m.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or not (repo/test).is_file() or (repo/test).is_symlink()
            or not (repo/regression).is_file() or (repo/regression).is_symlink()
            or hashlib.sha256(contents).hexdigest()!=current_sha
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=current_blob):
            return None
        source=contents.decode('utf-8')
        classify=source[source.index('public static function classify_asset('):source.index('public static function score_asset(')]
        expected=(
            'Folder paths and body references are provenance/context',
            "$haystack = function_exists( 'mb_strtolower' )",
            '$title_path = $haystack;',
            'automatic_classification_drift( array $asset )',
            'automatic_classification_stale_requires_reindex',
            'mad4b_context_auto_classification_stale_requires_reindex',
        )
        if any(x not in source for x in expected) or len(classify)<1000:return None
        return test
    except (OSError,ValueError,KeyError,TypeError,StopIteration,UnicodeError):
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
    requested=reviewed_rc129_dormant_ollama_bootstrap_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_wayfa_source_followup_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_pr997_wayfa_addon_site_bound_ring_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr995_wayfa_skill_cimd_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr990_wayfa_selective_operator_selfupdate_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr986_wayfa_host_pinned_uuid_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr985_wayfa_indexed_scan_readback_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr983_wayfa_classification_reconcile_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr970_wayfa_drive_exact_evidence_source(repo,path,contents)
    if requested is None:
        requested=reviewed_pr970_optional_host_catalog_recursion_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_pr937_optional_host_catalog_merge_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_company_email_native_preflight_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_company_email_recipient_safety_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_context_title_authority_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_company_staff_ceiling_successor(repo,path,contents)
    if requested is None:
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
