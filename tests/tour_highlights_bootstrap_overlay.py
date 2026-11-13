"""Exact non-authorizing Source overlay: PR813 atop approved PR808 Bootstrap.

Historic approval/pins are immutable; only this 3-line additive overlay may
be stripped for testing the earlier source. Any extra edit fails closed.
"""
from pathlib import Path
import hashlib

ROOT=Path(__file__).resolve().parents[1]
MAIN='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
BASE_SHA256='4b70ca42120f6dc5842b139cebe253d66503977d2b2d3921feb82164808caa3b'
CANDIDATE_SHA256='fdc0a6c7be27604b531a8fc80f5dc08891f8b1e9c5aa6c67c749ae189f328d64'
ANCHOR=b"define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );\n"
INSERT=(
 b'// Lightweight, source-verified JetEngine Tour Highlights consumer.\n'
 b'// Register before the optional fastpath return so Elementor/JetEngine AJAX works.\n'
 b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-tour-highlights-v1.php';\n"
)

class SourceOverlayDenied(ValueError):
 pass

def original_bootstrap_bytes(candidate:bytes)->bytes:
 if hashlib.sha256(candidate).hexdigest()!=CANDIDATE_SHA256 or candidate.count(ANCHOR+INSERT)!=1:
  raise SourceOverlayDenied('TOUR_HIGHLIGHTS_OVERLAY_NOT_EXACT')
 previous=candidate.replace(ANCHOR+INSERT,ANCHOR,1)
 if hashlib.sha256(previous).hexdigest()!=BASE_SHA256:
  raise SourceOverlayDenied('PR808_REVIEWED_BOOTSTRAP_NOT_RESTORED')
 return previous

def current_source_verified(root:Path=ROOT)->bytes:
 p=Path(root)/MAIN
 if not p.is_file() or p.is_symlink():
  raise SourceOverlayDenied('TOUR_HIGHLIGHTS_BOOTSTRAP_NOT_REGULAR')
 return original_bootstrap_bytes(p.read_bytes())
