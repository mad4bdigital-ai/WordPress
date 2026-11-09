#!/usr/bin/env python3
"""Hermetic verification of RC125-127 reviewed deltas and the divergent GitHub integration."""
from pathlib import Path
import argparse,hashlib,json,re,subprocess,tempfile,shutil
P=Path(__file__).resolve().parents[3]/'wp-content/plugins/mad4b-site-control-plane'
O=Path(__file__).resolve().parents[1]
def sha(path):return hashlib.sha256(Path(path).read_bytes()).hexdigest()
def check_branch():
    bootstrap=(P/'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php').read_text()
    guard=(P/'includes/class-mad4b-scp-staging-write-planning-guard.php').read_text()
    abilities=(P/'includes/class-mad4b-scp-abilities.php').read_text()
    checks={
      'mcp_class_autoload_registered':bootstrap.count('spl_autoload_register( $mad4b_mcp_mu_certified_autoload')==1,
      'mcp_class_autoload_unregistered':bootstrap.count('spl_autoload_unregister( $mad4b_mcp_mu_certified_autoload')==1,
      'mcp_class_autoload_hash_guard':'hash_equals( $hash, (string) hash_file( ' in bootstrap,
      'mcp_class_autoload_official_root':'$mad4b_mcp_mu_official_root' in bootstrap,
      'enrolled_oauth_admin_resolved':'authenticated_enrolled_oauth_user' in guard and 'get_current_user_id()' in guard,
      'oauth_bearer_client_verified':'verified_bearer_client_is' in guard and 'CHATGPT_CIMD_CLIENT_ID' in guard,
      'oauth_subject_exact_bound':'subject_binding( \'oauth\', $fingerprint )' in guard,
      'oauth_exact_approval_grant':"self::ABILITY, 'core'" in guard and "'mad4b-write'" in guard,
      'ai_approval_never_circuits_decision':'MAD4B_SCP_Authorization::authorize_mutation' in guard,
      'three_exact_intake_targets':all(x in abilities for x in ("'mad4b/fleet-intake-plan'","'mad4b/fleet-intake-auto'","'mad4b/fleet-intake-save'")),
      'oauth_intake_requires_ticket':"mad4b_intake_delegation_ticket_required" in abilities and "MAD4B_SCP_Approval_Tickets::get" in abilities,
      'oauth_intake_ticket_not_reused':"empty( $ticket['used_at'] )" in abilities or "! empty( $ticket['used_at'] )" in abilities,
      'write_preparation_signed':'MAD4B_SCP_Preparation_Receipt::issue' in abilities,
      'nonintake_write_preserved':'! $oauth_intake && ! MAD4B_SCP_Policy::can_admin()' in abilities,
      'no_global_write_admission':'MAD4B_SCP_Policy::can_mutate()' in abilities
    }
    if not all(checks.values()):raise RuntimeError('INTEGRATION_INVARIANT_FAILED '+str({k:v for k,v in checks.items() if not v}))
    return checks
def verify_deltas(release_root):
    versions=[
      ('rc125','fleet-adaptive-native-write-rc124','fleet-adaptive-mcp-source-rc125','bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'),
      ('rc126','fleet-adaptive-mcp-source-rc125','fleet-adaptive-oauth-planner-rc126','includes/class-mad4b-scp-staging-write-planning-guard.php'),
      ('rc127','fleet-adaptive-oauth-planner-rc126','fleet-adaptive-intake-dispatch-rc127','includes/class-mad4b-scp-abilities.php')
    ]
    verified=[]
    for tag,prior,later,path in versions:
        evidence=json.loads((O/'patches/rc125-rc127'/(tag+'-evidence.json')).read_text())
        patch=next((O/'patches/rc125-rc127').glob(tag+'-*.patch'))
        src=release_root/prior/'source/mad4b-site-control-plane'/path
        expected=release_root/later/'source/mad4b-site-control-plane'/path
        assert evidence['source_before_sha256']==sha(src)
        assert evidence['source_after_sha256']==sha(expected)
        assert evidence['patch_sha256']==sha(patch)
        with tempfile.TemporaryDirectory(prefix='mad4b-rc127-source-qa-') as tmp:
            a=Path(tmp)/path;a.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src,a)
            p=subprocess.run(['git','apply','--check',str(patch)],cwd=tmp,capture_output=True,text=True)
            if p.returncode:raise RuntimeError('PATCH_NOT_APPLICABLE '+tag+' '+p.stderr[:350])
            p=subprocess.run(['git','apply',str(patch)],cwd=tmp,capture_output=True,text=True)
            if p.returncode:raise RuntimeError('PATCH_APPLY_FAILED '+tag)
            if sha(a)!=sha(expected):raise RuntimeError('PATCH_SOURCE_HASH_MISMATCH '+tag)
        verified.append(tag)
    return verified
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--release-root',type=Path)
    args=ap.parse_args()
    checks=check_branch()
    result={'contract':'mad4b.rc127-source-integration-qa.v1','branch_invariants_pass':len(checks),
            'patch_series_verified':verify_deltas(args.release_root) if args.release_root else [],
            'no_live_requests':True,'no_user_data':True}
    print(json.dumps(result))
if __name__=='__main__':main()
