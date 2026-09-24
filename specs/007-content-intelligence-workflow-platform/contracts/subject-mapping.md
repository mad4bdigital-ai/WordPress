# Contract — Issuer-Bound Subject Mapping

Contract: mad4b.authority-subject-mapping.v1

## Purpose
Decouple external platform subject identifiers from WordPress numeric primary keys.

## Mapping key
(site_uuid, authority_id/issuer, normalized_external_sub)
→ principal binding.

## Binding output
- wp_user_id where human WordPress principal is required
- nhi_public_id where NHI mapping is required
- enrollment identity/revision
- enabled/status
- binding revision
- created/updated audit identity

## Rules
1. External sub need not use user:<wp_numeric_id>.
2. Mapping is issuer-bound; identical sub under another issuer is a different identity.
3. Mapping is site-scoped.
4. Zero matches: deny.
5. Multiple active matches: deny.
6. Disabled/unenrolled principal: deny.
7. Mapping mutation is governed and audited.
8. Runtime token claims never directly choose wp_user_id.
9. Local native mapper MAY support user:<numeric-id> as one mapper implementation.
10. External mapper MAY support opaque or tenant-scoped subjects.

## Full-chain test
A real REST request MUST traverse:
- OAuth Subject Gate
- OAuth Resource Bridge
- OAuth Subject User Bridge / mapper
- permission callback
- MCP handler

Tests cannot substitute direct class-method calls for this acceptance path.

## Negative cases
- External subject under Local authority → deny
- Local-only subject under External authority → deny unless exact mapping exists
- unenrolled target user → deny
- ambiguous mapping → deny
- disabled mapping → deny
- wrong site mapping → deny
