# Contract — MCP Protocol Evolution

Contract: mad4b.mcp-protocol-evolution.v1

## Current baseline
The currently certified WordPress MCP Adapter package remains exact-package governed.

## Upgrade policy
Do not replace a certified release with arbitrary upstream trunk.

A protocol/package upgrade requires:
- official/reviewed release artifact;
- package hash and provenance;
- semantic delta;
- transport/auth impact review;
- existing protocol regression;
- new protocol regression;
- MCP client compatibility;
- resource/OAuth/subject mapping regression;
- Staging canary.

## Compatibility reporting
Report separately:
- certified_protocol_versions[]
- observed_supported_versions[]
- client-negotiated version
- unsupported_latest_protocol_known optional

Do not claim compliance with a protocol version that has not been tested on the exact deployed adapter.

## Transition
If future adapter supports both legacy session-based and newer stateless lifecycle paths, certification treats them as separate transport behavior profiles with shared authority invariants.
