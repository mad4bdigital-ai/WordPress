# Contract — Multi-Authority Live Certification

Contract: mad4b.multi-authority-live-certification.v1

## Goal
Produce one fail-closed verdict proving the exact deployed candidate can authenticate and serve MCP correctly through every configured authority path.

## Inputs
- exact source_commit_sha
- build_fingerprint
- package_manifest_digest
- installed artifact identity
- site_uuid/environment
- Authority Registry snapshot
- protected-resource inventory
- MCP Adapter exact version/package identity

## Mandatory P0 probes
1. exact deployment provenance.
2. exact installed package/artifact identity.
3. protected resource metadata has exact resource values.
4. Local AS live metadata/JWKS/issuer/PKCE/CIMD path where Local is enabled.
5. External AS discovery/JWKS/exact issuer/protected-resource compatibility where trusted/advertised.
6. Full subject mapping through complete REST filter chain.
7. Real ChatGPT browser OAuth consent/code/token/MCP handshake where supported by acceptance environment.
8. MCP tools discovery/call using the same authenticated identity.
9. Cross-authority token/JWK/subject isolation.
10. authority_resource_policy isolation, especially ChatGPT vs Developer/Breakglass.
11. Production isolation: no Production mutation/breakglass/implicit approval.
12. expected failure for unknown issuer/wrong aud/wrong resource/missing scope/expired token.

## P1 probes
- External outage/status truthfulness and no unintended fallback.
- Local key persistence across PHP/service restart.
- key rotation overlap.
- refresh token replay/family poisoning.
- unknown kid controlled refresh then deny.
- Breakglass-disabled absence.

## Verdict
PASS only when every mandatory probe required by configured policy passes against the same exact deployed candidate.

DISPOSABLE_PASS is not LIVE_PASS.

Repository CI and disposable exact-origin WordPress tests are supporting evidence only.

## Evidence
Emit:
- contract
- candidate/source/build/package identity
- authority snapshot SHA
- individual probe results/reason codes
- timestamps
- correlation IDs
- live endpoints represented without secrets
- verdict
- evidence_sha256
