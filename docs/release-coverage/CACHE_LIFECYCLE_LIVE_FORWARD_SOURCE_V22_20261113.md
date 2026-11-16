# V22 — Source forward-reconcile of installed cache lifecycle

2026-11-13 — Source candidate only. No live site, tenant cache, or production changes.

The original 238-file additive Git source inventory pins an earlier 1.0.0
cache runtime SHA-256 `1268ef162a5080e93d142027be4f7fe8526d6ad6dd594e23cb60be6af8ce17c4`.
The pre-V22 Git integration runtime SHA-256
`aad979f9979f061a046407f6a6263f33e970f9bcf2f0b6fd8347199a38f72ebf`
contained the Nov 11 WCML/unified-date private URL query patch but **lacked**
newer live cache invalidation/Elementor metadata safeguards. An automatic
Company Reconcile from that Git file would overwrite existing version 1.0.5.

The installed Tours.dev runtime (independently observed, not copied to
Production) is version **1.0.5**, SHA-256
`7c0124ec9be8ffad09613398d0b821661b3b50352b3717562339e99d0ad5b11e`.
V22 preserves its exact reviewed bytes in Git; the existing WCML/date patch is
already present there, as is the home-page dependency-graph fallback and
Elementor transient metadata classification. Offline fixtures verify that
anonymous/cacheable requests are not marked private, while personal cookies,
booking/locale-date query arguments and relevant content edits remain private
or cause conservative invalidation.

**Do not regenerate historical provenance** or silently replace the pinned
238-file additive manifest. Its earlier source fixture remains immutable and
its exact cached runtime SHA conflict requires a separately approved layered
review; two legacy test labels were already failing before V22. V22's
independent pinned tests must remain valid alongside the legacy review.

This is an exact source parity candidate, not signed approval to install,
activate the Native Publisher, create Broker grants, or issue Original313.
The live runtime is unchanged by this Git modification.
