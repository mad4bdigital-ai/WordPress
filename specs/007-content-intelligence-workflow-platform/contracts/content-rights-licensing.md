# Contract — Content Rights, Licensing and Attribution

Contract: mad4b.content-rights.v1

## Purpose

Track whether source material and media may be used, transformed, quoted, attributed, published or retained.

## RightsRecord

Fields:
- source_ref/artifact/media identity;
- rights class;
- owner/licensor if known;
- license identifier/terms ref;
- allowed uses;
- attribution requirement;
- modification allowance;
- commercial-use allowance;
- territory/expiry optional;
- quote/excerpt constraints;
- evidence;
- review status.

## Rights classes

Examples:
- OWNED_FIRST_PARTY;
- LICENSED;
- PUBLIC_DOMAIN;
- PERMITTED_REFERENCE_ONLY;
- QUOTE_WITH_ATTRIBUTION;
- UNKNOWN;
- PROHIBITED.

UNKNOWN does not imply permission to reproduce.

## Research vs publication

A source may be usable for research/analysis but not for copying or media publication.

Scraped competitor pages are evidence/reference by default, not reusable article text.

## Media

MediaManifest records rights provenance for externally sourced assets.
Generated media records generation source/model and any policy-required disclosure/provenance.

## Similarity/plagiarism guard

Before publication, policy MAY run:
- phrase overlap;
- near-duplicate;
- source similarity;
- existing-site duplication.

High similarity requires review or rewrite according to policy.

## Attribution

Required attribution becomes part of the draft/publish requirements and is verified after publication.

## Takedown

A governed takedown workflow can:
- identify affected artifacts/content;
- stop reuse;
- unpublish/replace if authorized;
- preserve legal/audit tombstone;
- invalidate downstream artifacts.

## Legal note

This contract records operational rights policy and evidence; it does not by itself determine legal ownership.
