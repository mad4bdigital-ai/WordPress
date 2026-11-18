# Original305 one-time signed-readiness incident reconciliation

This is the narrow, independently reviewed original Host Source readiness
HOLD created 16 November 2026 with reason ISSUER_SIGNED_RESULT_UNCONFIRMED.
The first-party Original Broker v8 signed readiness issuer is SHA pinned and
its only WordPress mutation is the time-limited option:
mad4b_original_host_source_readiness_v1

The Central WordPress independent readback establishes:
- Original Broker Ed25519 signature on the saved receipt is VALID.
- Signed receipt issued after the HOLD was created.
- Receipt is EXPIRED and no longer confers a current readiness lease.
- Existing original Broker v8 code matches original SHA-pinned registry.
- Original Host policy and principal ledger have valid standing signatures.

The root-only manual reconcile.py plan checks all evidence without mutation.
Apply requires a nonbusy original signer lock, exact HOLD SHA256 and contract,
valid expired signed receipt, original pinned issuer with a bounded WordPress
readiness option and the original current Host ledger. It archives the exact
HOLD to an independent root-only audit receipt; it does not destroy evidence.
An audit receipt failure restores the original HOLD.

Reconciliation is a manual, bounded incident operation. It does not reenable
the disabled timer. The next separate step is one explicit call to the
original first-party Original305 readiness supervisor v7. No new signature
is implied until that call independently succeeds.

Live script: /usr/local/libexec/mad4b-original305-incident-reconcile-v1.py
Private receipts:
/opt/mad4b-company-provisioning/reports/original305-incident-reconcile-20261118/

The readiness signature is NOT an Original305/328 Grant, Host Authority
Anchor v2, Original312/313 job certificate, or Production content write.
No user or administrator is impersonated. No manual WordPress option
overrides are allowed.

Source-only hermetic unit tests:
python3 -B -m unittest discover -s ops/original305-incident-reconciliation-v1 -p 'test_*.py' -v
