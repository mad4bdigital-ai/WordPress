# Published baseline WASM proof — 2026-10-10

These recorded runs used actual pinned PHP 7.4.33 and 8.3.33 WASM CLI engines. They are simulations, not native CI, browser execution or live WordPress acceptance.

- Tested source commit: `b98d378a722c82a3d668b858023681c3d4a44634`
- Tested source tree: `66c71a4fddb283b3166329c0e757192924c79551`
- Plugin-only git archive SHA256: `30521f8e1c5ab422c00ea512306e87823a20da9a02c1bb3a8a28cffea1efe555`
- Copied plugin source snapshot SHA256: `5b4329ec38d3010fc1a4ea04ad8b8b6a1c673fa81cf07f2a7fd1df4618aca1ec`
- Source files: 1,155 regular files
- Runner source SHA256: `c8a50cf573dde0e410fc30e95881de3838322016d0530f2899f8c137ddac1ced`
- Local full matrix report SHA256: `3449b48806fadb08f78989fd78e34f9c1eac4509f5e61a784c7fbb4b37d4ff88`

Calibration: **16 PASS / 0 FAIL**. Per engine: version/crypto/isolation, exit 42, real parse failure 255, supervisor timeout 124, subprocess unavailable, and three independent exact-byte Unicode/NUL/allocator-overwrite checks. Output byte hashes: stdout `be9830902e9b1d244a03366d06b24038189df839d35777babc4aeae5c6ca5951`; stderr `599e7aafeceeff28bd9ae073f8aaff241a51545b08f55458a3dec47f0413af9d`.

Published baseline matrix: **18 PASS / 0 FAIL**, all genuine exit zero, empty stderr and identical before/after source hashes.

| Case | PHP 7.4.33 | PHP 8.3.33 |
| --- | --- | --- |
| Four original CSO01 fixtures | 4 PASS | 4 PASS |
| Gateway normal | 102 checks | 102 checks |
| Gateway partial registration | 5 checks | 5 checks |
| Gateway hostile collision | 6 checks | 6 checks |
| Secrets handoff fixture | 81 checks | 81 checks |
| Form UI PHP syntax | PASS | PASS |

Gateway fixtures double Scope and Registry. Secrets fixtures double Scope, WordPress/native executor, certificate, provider and database. These runs establish the tested component behaviors under those explicit boundaries; they do not establish final real-class composition, native CAS/concurrency or a supported live secret provider. Combined-source fixtures and final integrated-source replay remain separate required evidence.
