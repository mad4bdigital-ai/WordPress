# QA31 — Resource-Bounded Complete Dependency Analysis

Status: **source-only CI control**, non-authorizing. This design does not approve a
release, grant OAuth/Write authority, or replace site-specific lifecycle evidence.

## Scope and completeness

The predictive gate derives changed MAD4B_SCP class, interface, trait and enum
identities from both the exact base and checked-out source. It scans **every
shipped PHP file** under the mad4b-site-control-plane plugin folder,
including nested adapters, bootstrap, and test fixtures, to identify direct
references. Every discovered reverse dependency is retained and contributes to
the domain graph before regression selection. No dependency-count cutoff or
automatic all-domain fallback is applied.

Cross-plugin/vendor/runtime relationships remain separately governed by provider
and integration contracts. This static source scan does not claim to resolve
dynamic PHP dispatch or unknown external runtime dependencies.

## Resource budgets

Fixed budget defaults in the predictive dependency gate Python script:

| Budget | Default | On exhaustion |
|---|---:|---|
| Total PHP input bytes scanned | 120 MB | Fail closed |
| Maximum single PHP source file | 8 MB | Fail closed |
| Aggregated dependency-path evidence bytes | 12 MB | Fail closed |
| Reverse-dependency analysis wall time | 45 seconds | Fail closed |

There is **no fixed maximum number of dependent files**. A source larger than
the file budget is never silently skipped. A missing plugin root, symlinked
source, unavailable historical class, or changing file during inspection also
fails closed. Raising a resource budget is a reviewed code policy change, never
a bypass based on a particular PR's number of matches.

## Reproducible reporting

Run the gate with the --output option. The primary JSON contains the full,
sorted, unique reverse_dependency_files, complete=true, and a SHA256 digest
of all paths joined by LF (newline, without a terminal newline).
For ease of review, reverse_dependency_pagination references immutable,
content-addressed reverse-<digest-prefix>/part-NNNNN.json artifacts with
up to 250 paths each. Every page has its own SHA256 digest. The manifest also
has a canonical JSON digest. Consumers must verify every page, recombine them
in order, verify the full digest and count, and reject missing/modified pages.

Self-tests cover over 1,200 dependencies, nested PHP consumers, exact domain
selection, pagination, integrity failures, exhausted resource budgets and source
symlinks.

A complete source report is **not** a site authorization receipt. Existing
mandatory regressions must pass (--execute --enforce), and live Staging
certification, rollback provenance, exact-head owner attestations and the QA31
source-reconciliation gate remain independent prerequisites.
