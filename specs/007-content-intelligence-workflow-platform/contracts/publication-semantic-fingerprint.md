# Contract — Semantic Publication Fingerprints

Contract: mad4b.publication-fingerprint.v1

## Problem

Raw full-HTML byte hashes are unstable in the presence of nonces, timestamps, personalization, A/B assignment, ads, cache-busters or dynamic widgets.

## Verification views

Publication verification may compute independent normalized fingerprints:
- CONTENT_FINGERPRINT
- SEO_FINGERPRINT
- STRUCTURE_FINGERPRINT
- LINK_FINGERPRINT
- MEDIA_FINGERPRINT

## Normalization

Site/profile-specific normalizer may:
- identify main content root;
- normalize whitespace/Unicode;
- remove declared volatile nodes/attributes;
- canonicalize title/meta/canonical/robots;
- canonicalize JSON-LD;
- normalize internal links/media refs;
- ignore permitted personalization regions.

Normalizer version is part of the fingerprint identity.

## Verdict

A page can be:
- CONTENT_MATCH
- SEO_MATCH
- STRUCTURE_MATCH
with warnings on non-authoritative volatile differences.

Required dimensions are defined by PublicationVerificationProfile.

## Safety

Normalization MUST NOT remove security/SEO-critical differences simply to make a test pass.
Every ignored volatile selector/field is explicit and versioned.
