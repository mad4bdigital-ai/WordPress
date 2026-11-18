# Original signed Host Principal v2: exact read-only Production routing

This adapter completes first-party Hostinger target discovery without replacing
the signed Original305/328/312/313 execution path.

Company scopes: egypt-tour-gates:production and tourism-almallahgroup:production.
Canonical origins and UUIDs are checked against existing active exact Production
owner assignments and independently pinned SSH WordPress Site Profiles.

The original Host operator policy and router are updated with *read-only*
binding. allow_operator, allow_mutation, allow_production_mutation, allow_sql,
and allow_remote_shell remain FALSE. allow_pinned_wpcli_read is TRUE. These
values are required by the actor-free signed_host_principal_v2 original lane;
generic Host write flags are not acceptable for a Hostinger remote target.

The root-only first-party tool has plan, apply and verify modes. It checks
Central owner approval, exact Site Profile, original signed policy-ledger and
Ed25519 signer before changing files. Apply stores original root-only preimages,
writes the exact two read-only scope records, and invokes the original
native-vps-policy-rotate.py signer. It requires signed ledger readback.

This action does NOT generate any signed Original305/312/313 operation grant.
Independent signed Host-v2 authority anchor, Original328 activation, and signed
per-job Original312/313 still gate any actual Production content write.

Live private original policy and router preimages are retained in
/opt/mad4b-company-provisioning/reports/original-host-v2-readonly-scope-20261118.

Hermetic tests: python3 -B -m unittest discover -s
ops/original-host-v2-readonly-scope -p 'test_*.py' -v
