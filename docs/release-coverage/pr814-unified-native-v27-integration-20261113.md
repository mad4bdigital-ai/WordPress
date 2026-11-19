# PR814 integration — Native Source V27 / Original313 source candidate

Status: **source-only merge candidate / RELEASE HOLD**.

Integrates the exact `review/native-source-v27-foundation-20261113` Git head
`21dcebbc94c046e1c6c3f03dfe05b09a80fbbb64` into the currently verified
Company Release/Elementor/UAEL/Term Eligibility integration source.

The generated merge conflicts with the newer Company Release only in the
registered component catalog and three sections of `company_code_release.py`.
In each, the newer company source is retained deliberately: it keeps the
four registered MU components, verifies the exact PHP loader of nested JS/CSS,
rechecks functional capability ownership, PHP literal dependencies and tenant
profiles, enforces per-file CAS and signed rollback, and reads actual Git source
only for requested deployment files. All non-conflicting native-source additions
from V27 remain in the merged Git tree with V27's history as a second parent.

`ops/native-source-publisher-v1/catalog/generated/source.code.release.manifest.json`
was regenerated from the merged source using the first-party builder; its
SHA256 is `eb6fe7621980c99be8256756f35d1e58f984164f8b32f2287f6de99c471b6e03`.
This manifest is an **unsigned non-installed candidate**, not a Grant or
original Host receipt. The exact dynamic descriptor and dependency SHA pins
are regression-tested, along with source-only enrollment flags.

The independent historical receipt tests currently encounter 14 errors on the
Original313 live readback because the installed signed historical postimage
of `mad4b-tenant-sync/includes/class-mad4b-tenant-workflow.php` no longer
matches the expected postimage. Exactly the same 14 errors were reproduced on
V27's **original unmodified Git branch**; this is an existing live evidence
blocker, not a merge-created exception. These tests must **remain fail-closed**
and must not be disabled, mocked green or treated as release completion.

Company Release, native-source and the separate core runtime contracts are
validated individually. No deployment, first-party Registry enrollment,
Original305/306/312/313 certification, production mutation or source branch
closure is authorized by this integration document.
