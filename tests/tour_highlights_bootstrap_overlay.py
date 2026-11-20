"""Exact non-authorizing Source overlay: PR813 atop approved PR808 Bootstrap.

Historic approval/pins are immutable; only this 3-line additive overlay may
be stripped for testing the earlier source. Any extra edit fails closed.
"""
from pathlib import Path
import hashlib
import json

ROOT=Path(__file__).resolve().parents[1]
MAIN='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
BASE_SHA256='4b70ca42120f6dc5842b139cebe253d66503977d2b2d3921feb82164808caa3b'
CANDIDATE_SHA256='fdc0a6c7be27604b531a8fc80f5dc08891f8b1e9c5aa6c67c749ae189f328d64'
# Exact successor source, reviewed separately from the immutable PR808/813
# certificate. A changed PHP file OR changed successor content fails closed.
DESTINATION_SUCCESSOR_SHA256='87007b03164b4de741920384431f22950870f9ff395654cbf28816ac1a5b6ef3'
DESTINATION_REVIEW='docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json'
ANCHOR=b"define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );\n"
INSERT=(
 b'// Lightweight, source-verified JetEngine Tour Highlights consumer.\n'
 b'// Register before the optional fastpath return so Elementor/JetEngine AJAX works.\n'
 b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-tour-highlights-v1.php';\n"
)

class SourceOverlayDenied(ValueError):
 pass

def _restore_destination_successor(candidate:bytes)->bytes:
 # The historical approval is immutable. An additional successor can only
 # be removed if its EXACT reviewed SHA and insertion shapes are present.
 if hashlib.sha256(candidate).hexdigest()!=DESTINATION_SUCCESSOR_SHA256:
  raise SourceOverlayDenied('DESTINATION_SUCCESSOR_SOURCE_NOT_REVIEWED')
 review_path=ROOT/DESTINATION_REVIEW
 if not review_path.is_file() or review_path.is_symlink():
  raise SourceOverlayDenied('DESTINATION_SUCCESSOR_REVIEW_MISSING')
 try:
  review=json.loads(review_path.read_text())
  row=review['original_plugin_entry']
  if (review['contract']!='mad4b.pr814.pr813-additive-destination-reconcile.v1'
      or row['path']!=MAIN or row['current_sha256']!=DESTINATION_SUCCESSOR_SHA256
      or row['historical_sha256']!=CANDIDATE_SHA256
      or row['new_authority_granted'] is not False
      or row['new_registered_publisher'] is not False
      or len(candidate)!=row['current_bytes']
      or len(row['additions'])!=3):
   raise SourceOverlayDenied('DESTINATION_SUCCESSOR_REVIEW_INVALID')
  lines=candidate.splitlines(True)
  additions=row['additions']
  starts=[i['start_line'] for i in additions]
  if starts!=sorted(set(starts)):
   raise SourceOverlayDenied('DESTINATION_SUCCESSOR_ORDER_INVALID')
  for entry in reversed(additions):
   start=entry['start_line']
   length=entry['line_count']
   if type(start)!=int or type(length)!=int or start<0 or length<1:
    raise SourceOverlayDenied('DESTINATION_SUCCESSOR_RANGE_INVALID')
   chunk=b''.join(lines[start:start+length])
   if len(lines[start:start+length])!=length or hashlib.sha256(chunk).hexdigest()!=entry['sha256']:
    raise SourceOverlayDenied('DESTINATION_SUCCESSOR_INSERT_MISMATCH')
   del lines[start:start+length]
  historical=b''.join(lines)
  if (len(historical)!=row['historical_bytes']
      or hashlib.sha256(historical).hexdigest()!=CANDIDATE_SHA256):
   raise SourceOverlayDenied('DESTINATION_SUCCESSOR_PRIOR_NOT_RESTORED')
  return historical
 except (OSError, ValueError, KeyError, TypeError, IndexError) as exc:
  raise SourceOverlayDenied('DESTINATION_SUCCESSOR_INVALID') from exc


def original_bootstrap_bytes(candidate:bytes)->bytes:
 digest=hashlib.sha256(candidate).hexdigest()
 # Original305 has a separate exact, nonauthorizing 7-line overlay review.
 # Restore it before replaying the immutable PR813/PR808 source certificates.
 # On rc129 Host integration, the same review first strips the independently
 # pinned, no-signer Host loader. Nothing is inferred from version strings.
 if digest not in (DESTINATION_SUCCESSOR_SHA256,CANDIDATE_SHA256):
  try:
   from qa31_original305_source_overlay import restore_exact_original305_overlay
   record=json.loads((ROOT/'docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json').read_text())
   candidate=restore_exact_original305_overlay(candidate,record['original_plugin_entry'])
   digest=hashlib.sha256(candidate).hexdigest()
  except (OSError,AssertionError,ValueError,KeyError,TypeError) as exc:
   raise SourceOverlayDenied('ORIGINAL305_OR_HOST_ADDENDUM_NOT_EXACT') from exc
 if digest==DESTINATION_SUCCESSOR_SHA256:
  candidate=_restore_destination_successor(candidate)
 elif digest!=CANDIDATE_SHA256:
  raise SourceOverlayDenied('TOUR_HIGHLIGHTS_OVERLAY_NOT_EXACT')
 if candidate.count(ANCHOR+INSERT)!=1:
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
