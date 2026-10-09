# Native oracle workstream
- [x] N001 Implement native page canonical semantic oracle with public row validation and bounded snapshot
- [x] N002 Bind exact build provenance, plugin/theme discovery, profile and operator revision to HMAC plan
- [x] N003 Implement stateless 600s signed challenge and server-side reconstruction
- [x] N004 Implement independently RSA-attested external browser evidence and verified reducer
- [x] N005 Provision signer env allowlist without forwarding unrelated MCP/OAuth/GitHub credentials
- [x] N006 Fail before browser sessions if signer missing or weak
- [x] N007 Add native PHP fixture, JS RSA fixture, and profile ambiguity fixture to workflow
- [ ] N008 Native exact-head PHP 8.3/8.4 and Node test matrix on actual built package
- [ ] N009 Two live dissimilar Staging sites: exact native build, external browser RSA + HMAC signed receipts
- [ ] N010 Managed secure key provisioning and rotation via existing authority (not hardcoded keys)
- [ ] N011 Independent network DNS rebinding/IP egress, timeout/replay and origin-scope audit
- [ ] N012 Specialized business oracles: listing/AJAX parity, WPML, WooCommerce, booking, reversible workflows
- [ ] N013 Production release gates, owner attestation and rollback/exact package acceptance

x markers are source deliverables, NOT live runtime certification.

## Additional proof hardening
- [x] N014 Verify RSA type and minimum key size on WordPress side, derive matching SPKI key ID
- [x] N015 Orchestrator preflights actual public/private signer key ID before plan/billable browser
- [x] N016 Unique cryptographic challenge nonce per issued plan; no write from read-only MCP
- [x] N017 Native oracle uses current registered Providers rather than empty recognition fingerprint
- [x] N018 Plan readback checks current discovery digest and operator configuration revision
- [x] N019 Strict passive-only network policy for generic capability observations
- [x] N020 Capability Atlas never maps source inventory completeness to release readiness
- [ ] N021 Independent single-use replay ledger with exact run-ID, server-enforced atomic consumption
- [ ] N022 External browser provider session/network attestation and site-local exact-run receipts
