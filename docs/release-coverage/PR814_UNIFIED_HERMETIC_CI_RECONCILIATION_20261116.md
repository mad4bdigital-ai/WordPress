# Unified MAD4B source — native CI source regressions, 16 Nov 2026

This is **source-only** test evidence on an isolated VPS Git worktree. It does
not imply original signed authority, an approved Production release, a
successful GitHub Actions run or deployed WordPress changes.

## Reproduced and fixed as legitimate test regressions

- The PR808 Developer V1 and Registered Write historic test sources initially
  required current files to equal their 13 Nov 2026 exact original hashes.
  Real successor versions had introduced more stringent live source and policy
  fingerprints. The tests now independently hash the exact original Git
  ancestor source and verify bounded current successor source and explicit
  fail-closed code witnesses. Frozen historic PR808 review JSONs stay byte-
  unchanged; no owner/Host312/313 or grant receipt was generated. 7/7 pass.
- A QA31 Python test expected a native host php executable, but the VPS host
  intentionally has containerized PHP only. Run within an ephemeral wrapper
  invoking an isolated php:8.5-cli container without network, with the source
  bind-mounted read-only. 15/15 hermetic PHP fixtures passed. No actual host
  PHP was installed or runtime application code changed.
- In the 60-source-PHP case GitHub workflow matrix, two fixtures needed
  updates to reflect newer runtime contracts. The WordPress-free Tour
  Highlights fixture had omitted WP core's has_filter function (which exists
  on real WordPress). The MCP server-registration fixture omitted the original
  Capability Descriptor GENERATION_CONTRACT and non-authorizing
  authority_revalidation_required fields, causing an unintended fatal before
  testing its intended fail-closed preflight. These fixture corrections preserve
  the existing stronger runtime behavior. All **60/60** PHP source cases
  passed in a networkless read-only php:8.5-cli container; both corrected
  cases independently passed under php:7.4-cli.

## Release boundaries

- Local source and CI fixture acceptance is not Original305 incident closure,
  original Host312 proof, Master signed ticket, original313 signed release or
  Wayfatours promotion.
- GitHub PR814 head is still behind the local branch until Git credentials
  or another first-party authorized source transport publishes the exact HEAD.
- VPS local Git bundle with original PR814 HEAD as a prerequisite permits a
  **non-force** source transport using an independently authorized Git client.
