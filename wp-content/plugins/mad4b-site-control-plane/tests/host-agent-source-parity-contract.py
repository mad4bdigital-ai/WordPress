#!/usr/bin/env python3
"""Source-preserving parity checks for dormant, independently scoped Host Agent."""
from pathlib import Path
import hashlib
ROOT=Path(__file__).resolve().parents[1]
INCLUDES=ROOT/'includes'
def read(name): return (ROOT/name).read_text()
# Tourism operations are optional and must not be bundled into the tenant-neutral core.
TOUR_MODULES = (
    'class-mad4b-scp-host-media-intake.php',
    'class-mad4b-scp-host-media-closure.php',
    'class-mad4b-scp-host-media-reconcile-proposal.php',
    'class-mad4b-scp-host-visual-strategy-proposal.php',
)
ADDON = ROOT.parent / 'mad4b-tour-media-host-bridge'
for name in TOUR_MODULES:
    assert not (INCLUDES/name).exists(), 'tour-only module leaked into generic control plane'
    # Standalone Control Plane distributions may omit the optional addon.
    if ADDON.exists(): assert (ADDON/'includes'/name).is_file(),name
p=read('mad4b-site-control-plane.php')
assert p.count("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-host-agent-bridge.php';")==1
assert not (INCLUDES/'mad4b-host-agent-public.b64').exists(), 'site-specific host trust must never be inherited'
assert 'mad4b_optional_tour_media_unavailable' in read('includes/class-mad4b-scp-host-agent-bridge.php')
bridge=read('includes/class-mad4b-scp-host-agent-bridge.php')
for v in ['sodium_crypto_sign_verify_detached','body_sha256','binding_ready()','active_for($ability)','nonce','expires_at','site_uuid']:
 assert v in bridge, v
for rel,required_markers in {
 'includes/class-mad4b-scp-context-authority.php':['function review_asset_by_host_agent','self::review_asset_with_actor'],
 'includes/class-mad4b-scp-content-jobs.php':['Host_Agent_Bridge::active_for','type\' => \'nhi'],
 'includes/class-mad4b-scp-artifacts.php':['Host_Agent_Bridge::active_for','host-agent:'],
}.items():
 data=read(rel)
 for marker in required_markers:assert marker in data,(rel,marker)
print('PASS host-agent staging source parity: no credentials, no source feature deletion')
