# Host v130 — Persistent Write is the default standing entitlement

MAD4B default for each newly signed Host v3 enrollment:

| Setting | Default | Meaning |
| --- | --- | --- |
| Connection | persistent | No calendar expiry; revocation applies |
| Write entitlement | persistent | Approved site remains eligible for specific writes without repeated enrollment |
| Standing approval | Original305 signed | Separate from the connection enrollment |
| Original313 | Fresh independent high-water, max 30 seconds old | May revoke entitlement |
| Allowed actions | site.code.atomic.install and site.code.atomic.rollback | No generic shell or arbitrary SQL |
| Each write | Signed exact operation and activity heartbeat | Standing entitlement alone never authorizes a mutation |
| Backup | Signed atomic plan and sealed verified preimage | Mandatory before committing a change |
| Postwrite | Verified signed Current from target | Required to record successful completion |
| Production | Separately signed production consent | Never derived from a boolean flag |

The unsigned repository default in standing_write_policy.json is NOT an
operational signature. It is a source policy for new site enrollments.
It cannot activate Write on an existing read-only Host Runner profile.

## Root-first checks

standing_write.py implements two distinct gates:

1. verify_standing_write requires an Original305-signed indefinite Write
   entitlement and a fresh independent Original313 ACTIVE high-water.
   The record has expires_epoch=null; revocation invalidates it and a
   stale checkpoint fails closed. Production additionally requires a
   distinct signed exact-site production consent.
2. admit_exact_standing_write also requires the signed atomic plan from
   an independent trusted plan signer, an Original312 v3 persistent
   connection with Original318 and Original313 readback, separately
   signed exact job grant, ongoing signed activity, and verified backup.
   This returns a verified specific admission, not an executed mutation.

An operation may run for days with updated signed heartbeat. Its idle
watchdog is not a fixed total operation lifespan.

## Fleet and security

Global default for new signed enrollments includes persistent Write
eligibility; independent signing, site identity, source approval,
rollback and per-action admission are still mandatory. Per-site
configuration can disable Write. Production requires its own signed
consent, separate from Staging.

## Live activation status

- Tours.dev Staging has a signed read-only Current profile active every
  15 minutes. Independent signature and installed-source checks passed.
- Additive Original318 v3 PHP readback installed on Tours.dev Staging,
  with saved exact previous state and unchanged Original318 v2.
- Original305 signed v3 owner approval, Original312 v3 enrollment,
  independent Original313 v3 high-water and approved write executor have
  NOT yet been activated.
- This v130 source change does not self-authorize or forge Root evidence.
- Wayfatours Production and All Royal Egypt Production are unchanged.

## Recovery

Missing keys or missing Owner approval must not be replaced by an
unsigned JSON file, Git commit, expired v2 Anchor, or text consent.
The two additive Original318 v3 MU files can be rolled back to their
recorded previously absent state after validating installed hashes.
