# MAD4B Property-wide Supplier Sheet Rate Review — live deployment, 21 Nov 2026

## Authorized operation completed

The user-supplied hotel quotation sheet was read through connected Google
Sheets. Its fresh snapshot is saved privately on the host; three Hotels
rows changed in the Peak section, not imported here. Cruises and Coastal
were not changed. No raw vendor prices, private spreadsheet IDs or source
notes are committed to Git.

Imported into the native JetEngine property-rates CPT:
- Tours.dev: 47 Draft Property-wide cost reference Rates, 13 Properties
- Wayfatours: 56 Draft Property-wide cost reference Rates, 17 Properties

103 of 103 Rates were read back and verified against original cell amounts,
Property identity, Double/Triple occupancy, USD, BB meal plan and the
original enabled Season Term Meta.

All remain Draft, Stop Sale 1, cost-kind reference, Property scope,
Unit ID zero, with a private supplier quote proposal flag recording:
supplier_contract_verified=false; nightly_basis_verified=false;
cost_receipt_approved=false; commercial_activation_allowed=false.

Price basis is per_person, NOT invented as per_person_night. No Unit Type,
inventory, margin, supplier net contract, price eligibility or room
availability was invented. Single, Peak, Cruises, Coastal soft-AI, unclear
per-person basis and unlinked Properties were excluded from auto import.
The spreadsheet was not edited.

## Calendar inheritance

No explicit valid_from or valid_to duplicates. The existing Accommodation
Layered Pricing module resolves the source period through original enabled
Season Term Meta with a source anchor year (Summer 2026 or Winter 2026/27).
A complete explicit Rate custom range takes precedence, and partial
custom ranges fail closed. It does not silently recycle old quotations
into future years.

The original admin panel now shows inherited period, cell reference,
occupancy and pending approval. The existing Rate editor shows a
permission-checked readonly source review block with source-cell link.

## Commercial publication boundary

Publishing reviewed application code and importing editorial reference
Rate Drafts was explicitly authorized. Actual SELLABLE supplier cost Rate
publication was not: the source sheet does not prove an independently
verified nightly price basis or approved Cost Receipt.

A WordPress published-state attempt on a pending supplier quote is forced
to Draft. Stage Guard rejects unapproved supplier sheet references, and
requires the original native Unit relation, occupancy and availability
before Package Rate generation. No direct price, meal or occupancy weight
is compounded onto an explicit Rate.

After a supplier document verifies actual per-night/room scope, board,
Peak, mandatory supplements, period, inventory and approval, use the
original company authority workflow to promote the Rate. No booking,
checkout, Unit inventory or supplier commercial approval was changed.

## Source and rollback

Full host checkpoint:
  /opt/mad4b-package-checkpoints/2026-11-21-property-wide-sheet-import/

Includes original live code before hashes, ten tested tenant-specific
patches, full source readback receipts (103), an idempotent importer and
safe record IDs for an exact conditional rollback.
Never restore old code over newer site changes. Never delete a Rate after
a later user edit without reconciling that change.

Native shared-commerce guard reconciled into existing local source
worktree at commit 24f862890b11f28822d90352f85a77b570c867af.
That local source archive has no separately configured GitHub remote.
The existing Draft WordPress PR #1003 receives code and exact patches;
exact-head owner attestation remains a separate release gate.

The original WordPress CPT edit index is at
/wp-admin/edit.php?post_type=property-rates
Search for Supplier quote to inspect the new Drafts and references.

