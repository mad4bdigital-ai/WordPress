#!/usr/bin/env python3
"""Exactly reproducible minimal live-first Original306 Worker adapter patch.

Reads the current first-party live Worker as source preimage. Adds ONLY
read-only Host Anchor v2 claimed-job classification; does not change claim
signing, Broker queue selection, original312 signing, execution scheduling,
or final Original313 receipt logic. CAS mismatch must fail, not overwrite.

Requires separate signed commissioning for issue/revoke; the classifier
cannot authorize an operation and returns HOLD for any matching Host v2 Job.
"""
from __future__ import annotations

import hashlib
from pathlib import Path

ORIGINAL_WORKER_SHA256 = (
    "625aa6916ca1f37a0177a22437cc6a88fddeadf888a153dcfae5b467c1fc0b99"
)
CLASSIFIER_SHA256 = (
    "84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f"
)
FIRST_INSERT = "def firstparty_host_source_claimed_execution(actor, entry, *, execute=False):"
SECOND_INSERT = """  if target_id!='srv2030908':
   # Only existing preclaimed read Jobs are allowed through the Remote lane."""

READ_ONLY_ADAPTER = '''def firstparty_host_v2_claimed_observation(actor, entry):
    """Read-only Original306 v2 dispatch inspection; NEVER an authority.

    SHA-pinned claim classifier is called only after an exact Root Registry
    Host Anchor plan/command match. Any v2 match is kept off the remote
    read-canary, WordPress and Host Source execution lanes. Current manifests
    are deliberately execution_enabled=false. No new Broker claim/Grant/Job
    is created; the independent Original312/313 process remains separate.
    """
    if (not isinstance(entry, dict)
        or entry.get('target_id') not in ('hosting-2', 'hosting-3')
        or not isinstance(entry.get('job_id'), str)
        or not HEX32.fullmatch(entry['job_id'])
        or not isinstance(entry.get('command_sha256'), str)
        or not re.fullmatch(r'[a-f0-9]{64}', entry['command_sha256'])):
        return None
    try:
        if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
            sys.path.insert(0, str(DYNAMIC_REGISTRY_ROOT))
        import registry
        index = registry.Registry(DYNAMIC_REGISTRY_ROOT)
        matching = []
        planstore = DYNAMIC_PLAN_STORE
        if not planstore.is_dir() or planstore.is_symlink():
            raise RuntimeError('ORIGINAL306_ANCHOR_ROOT_PLAN_STORE_UNAVAILABLE')
        plans = sorted(planstore.glob('*.json'))
        if len(plans) > 256:
            raise RuntimeError('ORIGINAL306_ANCHOR_PLAN_STORE_UNBOUNDED')
        for operation in ('company.host-authority.anchor.issue',
                          'company.host-authority.anchor.revoke'):
            manifest = index.resolve(operation)
            if (manifest.get('resource_kind') != 'host_anchor'
                or manifest.get('target_scope') != 'host'
                or manifest.get('job_type') !=
                   'signed_remote_hostinger_wpcli_v2'
                or manifest.get('command', {}).get('format') !=
                   'host.anchor.ref.v2'
                or manifest.get('execution_enabled') is not False):
                raise RuntimeError('ORIGINAL306_HOST_ANCHOR_PROVIDER_DRIFT')
            for file in plans:
                if not re.fullmatch(r'[a-f0-9]{64}', file.stem):
                    raise RuntimeError('ORIGINAL306_UNPINNED_ROOT_PLAN_NAME')
                plan = registry.checked_json(file, planstore)
                if plan.get('contract') != manifest.get('plan_contract'):
                    continue
                if plan.get('plan_sha256') != file.stem:
                    raise RuntimeError('ORIGINAL306_ROOT_ANCHOR_PLAN_DIGEST_DRIFT')
                if (index.command_sha256(operation, file.stem)
                    == entry['command_sha256']):
                    matching.append((operation, plan))
        if not matching:
            return None
        if len(matching) != 1:
            raise RuntimeError('ORIGINAL306_HOST_ANCHOR_PLAN_AMBIGUOUS')
        operation, plan = matching[0]
        from pathlib import Path
        import stat
        modulefile = (DYNAMIC_REGISTRY_ROOT / 'host-anchor-v86'
                      / 'original306_typed_claim.py')
        st = modulefile.lstat()
        if (modulefile.is_symlink() or not stat.S_ISREG(st.st_mode)
            or st.st_uid != 0 or st.st_gid != 0
            or st.st_mode & 0o077
            or hashlib.sha256(modulefile.read_bytes()).hexdigest() !=
               '84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f'):
            raise RuntimeError('ORIGINAL306_V86_SIGNED_CLASSIFIER_SOURCE_DRIFT')
        spec = importlib.util.spec_from_file_location(
            '_mad4b_original306_exact_host_v2_classifier', modulefile)
        if spec is None or spec.loader is None:
            raise RuntimeError('ORIGINAL306_HOST_V2_CLASSIFIER_NOT_FOUND')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        id = entry['job_id']
        php = ('$id="'+id+'";'
          '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          '$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
          'echo wp_json_encode(["job"=>is_array($j)?$j:[],'
          '"claim"=>is_array($c)?$c:[]]);')
        original = cli_json(['--user='+str(actor), 'eval', php], timeout=30)
        result = module.resolve_remote_host_v2_claim(
            index, original.get('job',{}), original.get('claim',{}),
            entry, plan)
        if (result.get('operation_id') != operation
            or result.get('execution_authorized') is not False
            or result.get('original313_certified') is not False
            or result.get('issuer_commissioned') is not False):
            raise RuntimeError('ORIGINAL306_HOST_V2_CLASSIFIER_NOT_A_HOLD')
        return result
    except Exception as exc:
        print('ORIGINAL306_HOST_V2_CLAIM_HELD:'
              + safe_capability_error(exc), file=sys.stderr)
        # Keep a malformed v2 claim out of generic Host Source/WordPress
        # whenever its command was identified as a Host Anchor root plan.
        if 'matching' in locals() and matching:
            return {'state': 'HOST_ANCHOR_V2_HOLD',
                    'execution_authorized': False,
                    'anchor_issued': False,
                    'original313_certified': False}
        return None


'''

CLAIMED_BRANCH = """  if target_id!='srv2030908':
   host_v2_observation=firstparty_host_v2_claimed_observation(actor,row)
   if host_v2_observation is not None:
    # Original312 + independent Original313 are not commissioned.
    # Never execute a generic remote canary or fallback for this claim.
    continue
   # Only existing preclaimed read Jobs are allowed through the Remote lane."""


def render(original: bytes) -> bytes:
    actual = hashlib.sha256(original).hexdigest()
    if actual != ORIGINAL_WORKER_SHA256:
        raise ValueError("ORIGINAL306_LIVE_SOURCE_PREIMAGE_CHANGED:" + actual)
    data = original.decode("utf-8")
    if data.count(FIRST_INSERT) != 1 or data.count(SECOND_INSERT) != 1:
        raise ValueError("ORIGINAL306_ORIGINAL_CONTROL_FLOW_ANCHOR_MISSING")
    updated = data.replace(FIRST_INSERT, READ_ONLY_ADAPTER + FIRST_INSERT, 1)
    updated = updated.replace(SECOND_INSERT, CLAIMED_BRANCH, 1)
    compile(updated, "original306_live_worker_v86.py", "exec")
    if updated.count("firstparty_host_v2_claimed_observation") != 2:
        raise ValueError("ORIGINAL306_HOST_V2_HOOK_INSERTION_COUNT_INVALID")
    return updated.encode("utf-8")


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--read-only-source", required=True)
    parser.add_argument("--verify")
    opts = parser.parse_args()
    source = Path(opts.read_only_source)
    if not source.is_file() or source.is_symlink():
        raise SystemExit("ORIGINAL306_SOURCE_NOT_A_REGULAR_FILE")
    result = render(source.read_bytes())
    print("ORIGINAL306_V86_CANDIDATE_SHA256",
          hashlib.sha256(result).hexdigest())
    print("ORIGINAL306_V86_CANDIDATE_BYTES", len(result))
    if opts.verify:
        check = Path(opts.verify)
        if check.read_bytes() != result:
            raise SystemExit("ORIGINAL306_CANDIDATE_CHANGED")
        print("ORIGINAL306_EXACT_CANDIDATE_MATCHED")
