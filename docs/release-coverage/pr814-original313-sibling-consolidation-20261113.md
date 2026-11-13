# PR #814 — Original313 V27 sibling consolidation: exact source / release HOLD

**Status: source integrated in local review only; release not approved.**

The sibling `05b6a111090a3ce473b876e7e0c155155da31cb3` was combined with the
Native Source V27 foundation, preserving both branches' *exact Git parents*.
Five conflicts were resolved without replacing the more recent Central
Original305 production-company Job/Claim readback with an older same-site-only
implementation. The current readback checks the verified central operator and
registered Company/Environment target (exact UUID, HTTPS origin, environment),
and discloses only bounded/redacted read evidence. It does not create any
Original312 Grant, Job, Claim, original313 receipt, or mutation authority.

The sibling also added an unintended duplicate `require_once` of the native
source catalog review; this was removed **together with its orphan comment**.
The resulting complete Control Plane bootstrap is byte-for-byte the already
reviewed PR #808 V2 file (SHA256
`4b70ca42120f6dc5842b139cebe253d66503977d2b2d3921feb82164808caa3b`).
The original bootstrap now registers each read module exactly once; all
existing Elementor, FCP, registered operation admission, WPML and early-return
policy hooks remain in their original ordering.

The old V21 readback module SHA256
`cbde0e415f23c69c65e8940e5f9912188fb9532a7fa6312bc79253d404f89e2d`
is still checked **against its original immutable Git source object**. Its
historical provenance verifier has not been changed. The current module SHA256
`81fca113099a1e491127b8e301d5371f5c87130a12bcd4c6427e22406fd52d0b`
was reviewed separately and its exact Git Blob recorded in the non-authorizing
Capability Coverage ledger; the current test explicitly rejects representing
that successor as the historical module.

The old 238-file additive source manifesto and its original SHA-verifier were
restored verbatim from `31badd1e350c033dcd6f274d031c80d295bbbdf9`, not
rewritten. The snapshot matching all **238 original files** is
`a3164cb61d46d7e8347326dd5e3279de15d794fd`. The later 11 November Cache
Lifecycle private-URL fix changed one PHP file, so the source verifier runs
against the original Git archive, not the current deployable tree. The separate
69-file/22-reviewed-delta history is checked against the 31badd Git snapshot.
This historical evidence is **not** a claim that active company runtime files
have old SHA values and must never trigger an old-code rollback.

## Repeatable test evidence

- 203/203 native-source Python source tests, 103/103 Company Release tests,
  14/14 Control Plane source contract tests, 6/6 immutable additive plugin
  archive checks, and 46/46 admin readback PHP assertions.
- Native Source central catalog mocked PHP acceptance passed for **two
  independently identified operators/sites**, including CAS, absent provider,
  bounded no-write previews, with zero grants.
- Full root unittest discovery currently has 292 tests and **14 historical
  Original313 signed-postimage errors**. The same class of 14 failures was
  reproduced on the unmodified V27 parent; `mad4b-tenant-sync` current file
  postimage does not match the independently signed historical bytes. Do not
  normalize, override or accept the historical SHA to make tests green.

No WordPress installation, source publication, credential, company Grant,
Original313 signature, signed release verdict, production mutation or live
site file was modified. **Release HOLD remains mandatory**; source CI cannot
certify the missing Original313 readback.
