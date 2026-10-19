# ETG Dynamic Filter SEO Bridge — governed MCP writes (Staging only)

## Integration boundaries

**Read:** Existing \`MAD4B_SCP_ETG_DFSB_Adapter\` remains strictly read-only.
**Write:** Reviewed \`MAD4B_SCP_ETG_DFSB_Write_Adapter\` is a separate
source-approved bundled class. Registration does **not** create a grant,
certification, or mutation authority.

Only WordPress **Staging** with the exact ETG Alpha13 service contract can
execute its bounded operations. The WordPress administrator capability,
MAD4B global mutation gate, NHI/agent identity, provider mutation
certification, per-ability authorization and approval must all pass.
Unknown certification or missing approval blocks execution. Production
remains disabled by design.

## Operation flow

1. Discover \`wordpress.etg-dfsb.slot.apply\` or
   \`wordpress.etg-dfsb.profile.apply\` in MAD4B's semantic catalog.
2. For slots, read \`etg-dfsb/slot-plan\` with the proposed slot object.
   Review its **revision** and **proposed_sha256**.
3. For profiles, read \`etg-dfsb/profile-diff\` for an existing disabled
   profile. Review the exact **revision**, **snapshot_fingerprint**,
   structural diff and safe-to-apply indication. No optional taxonomy,
   indexing, SEO publication or profile activation is permitted.
4. After independent authorization, call the corresponding *admin*
   ability through governed MAD4B execution (never direct WP REST):
   - \`etg-dfsb/slot-apply\` with \`confirmed:true\`, \`slot\`,
     \`expected_revision\`, \`expected_proposed_sha256\`.
   - \`etg-dfsb/profile-apply\` with \`confirmed:true\`, \`profile_id\`,
     \`expected_revision\`, \`snapshot_fingerprint\`.
5. The **existing** MAD4B reversible adapter journal captures the
   before-state, rejects missing grants/certification, performs the bounded
   write, reads back the provider state, records SHA-256, mutation ID and
   undo expiry. The adapter's per-target lock rejects simultaneous writes.
6. Independently read \`etg-dfsb/write-verify\` with
   \`target_type: slot|profile\`, \`target_id\`, and the optional exact
   \`expected_sha256\`. Comparison only; verification never grants rights.
7. To undo, invoke the **existing** \`mad4b/mutation-undo\` with the exact
   recorded mutation ID through its independent governed approval path.
   Undo requires a matching per-ability restore contract, the same
   authorized agent (unless separately approved), a non-expired window,
   and matching current-state digest. Stale state is rejected. Staging,
   administration and global-off guards are repeated at restore time.

## Verification & failure contract

- Different **config revision**, **slot revision**, **inventory fingerprint**
  or **proposed slot hash** => fail closed.
- No \`confirmed:true\`, no journal context, no separate certification,
  no bound NHI/admin or non-Staging environment => fail closed.
- Write/readback mismatch => failure evidence in central mutation journal;
  never report success solely because \`update_option\` returned.
- Raced target or a previous unreleased lock => deny rather than overwrite.
- Rollback must preserve disabled Profile / Global OFF and exactly restore
  captured state; any mismatch produces an error with no automatic waiver.
- The operation does **not** enable profiles, add indexable taxonomies,
  change allowed combinations, publish SEO, modify Elementor documents,
  configure LiteSpeed caching or modify Production.

## Acceptance needed before activation

CI tests are only source/disposable-runtime evidence. An authorized
Staging candidate still requires exact deployed ETG/MAD4B source readback,
native PHP 7.4/8.3/8.5 lint, WP 6.9+ integration, isolated real
Apply→Readback→Undo→Readback test on a disabled test profile and custom
slot, stale/duplicate-apply and race tests, provider mutation
certification and exact owner approval. Keep Production disabled.
