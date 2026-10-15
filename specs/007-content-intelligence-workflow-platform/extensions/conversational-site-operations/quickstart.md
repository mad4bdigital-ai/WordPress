# CSO01 — Spec-only verification

At the exact repository HEAD, run:

python3 specs/007-content-intelligence-workflow-platform/extensions/conversational-site-operations/validate.py
python3 specs/007-content-intelligence-workflow-platform/extensions/conversational-site-operations/test_validate.py

Neither command deploys WordPress, executes PHP, opens a browser, writes to a site nor proves provider acceptance. It validates the cross-linked design backlog only.

Implementation slice zero must inspect a read-only Site Explorer on disposable Staging, reject unknown plugin/table, stale target revision, hidden/private meta, forged actor and missing provider. Next slices add typed form and secure handoff (never secret plaintext in chat), then a single certified write with independent readback, then workflows/bulk/content/multisite/Production as separate gated deliveries.
