#!/usr/bin/env python3
"""CAS-only correction for first Host Anchor original306 queued-Job TTL.

WordPress v94 issues typed non-WPCLI Anchor requests with 300s expiry.
The original Root Worker remote minimum 360s discarded EVERY such Job.
Allow this 120s admission window only on the exact Staging first-Anchor
candidate and retain original 360s requirement for normal remote WPCLI.
This patch never makes Original312/313 operational.
"""
from __future__ import annotations
import hashlib

PREIMAGE_SHA256="2309db32ba4c7f1e78b29544649e31574cb6443018ffaeed114b72386c294ed6"
BEFORE="""  min_lifetime=360 if remote else 90
  if entry.get('site_uuid')!=target.get('site_uuid') or int(entry.get('expires_epoch') or 0)<time.time()+min_lifetime:continue
"""
AFTER="""  # Original305 Host Anchor issue/bootstrap requests are 300s, while the
  # legacy remote WPCLI needs 360s. Before v98 the first Host Anchor could
  # NEVER reach its owner/328/plan verifier. Only All Royal Egypt Staging
  # uses a 120s pre-read window; standard remote WPCLI still needs 360s.
  first_anchor_scope=(remote and scope=='all-royal-egypt:staging'
      and entry.get('target_host_id')=='hosting-2')
  min_lifetime=(120 if first_anchor_scope else (360 if remote else 90))
  if entry.get('site_uuid')!=target.get('site_uuid') or int(entry.get('expires_epoch') or 0)<time.time()+min_lifetime:continue
"""
BEFORE_ANCHOR="""   if firstparty_host_v95_first_claim_only(actor,entry):
    # This returns the ordinary signed Original306 *Claim*, not an Anchor.
"""
AFTER_ANCHOR="""   if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):
    # This returns the ordinary signed Original306 *Claim*, not an Anchor.
"""
BEFORE_REMOTE="""  if remote:
   if (j.get('command_sha256')!=DIGEST
"""
AFTER_REMOTE="""  if remote:
   # Preserve legacy remote WPCLI >360s admission even when the same
   # Staging scope had a 120s first-Anchor pre-read window.
   if int(entry.get('expires_epoch') or 0)<time.time()+360:continue
   if (j.get('command_sha256')!=DIGEST
"""

def render(raw:bytes)->bytes:
    actual=hashlib.sha256(raw).hexdigest()
    if actual!=PREIMAGE_SHA256:
        raise ValueError('ORIGINAL306_WORKER_PREIMAGE_CHANGED:'+actual)
    source=raw.decode('utf-8')
    for before,after in (
        (BEFORE,AFTER),(BEFORE_ANCHOR,AFTER_ANCHOR),
        (BEFORE_REMOTE,AFTER_REMOTE)):
        if source.count(before)!=1:
            raise ValueError('ORIGINAL306_CLAIM_WINDOW_PATCH_POINT_MISMATCH')
        source=source.replace(before,after,1)
    updated=source.encode()
    compile(updated,'original306-v98-worker.py','exec')
    return updated
