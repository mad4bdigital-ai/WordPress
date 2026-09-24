# Reference — Provider Observations Relevant to Feature 007

Checked: 2026-09-24

## Bit Flows repository certification
MAD4B current certified-provider profile reviewed during Feature 007 planning pins:
- provider key: bit_pi
- certified version: 1.24.0

Current target Staging observation supplied for this project:
- installed Bit Flows: 1.29.0
- not active at the observed moment
- write execution capability blocked pending certification

Therefore Feature 007 treats 1.29.0 as an exact recertification candidate, not as trusted by installation and not as a downgrade target.

## Public upstream information
WordPress.org changelog checked on 2026-09-24 lists:
- 1.30.0 — 2026-09-20
- 1.29.0 — 2026-09-08
- 1.28.0 — 2026-08-19

Important upstream changes in the reviewed range include:
- 1.25: Run Code tool
- 1.28: Bit Flows can act as an MCP server
- 1.28: webhook authentication/IP restrictions
- 1.28.1: connection/security hardening
- 1.29: Respond to Webhook and array transformation tooling
- 1.30: additional integrations and workflow fixes

Architecture consequence:
installed_version, certified_version, and upstream_latest_version are separate facts. Upstream latest never creates automatic upgrade authority.

Public source:
https://wordpress.org/plugins/bit-pi/
