# Original Host v2, v81 signed-admission source (review only)

This directory contains an **exact, selective source reconciliation** of the original v81 Host Authority plan and independent evidence verifiers from [PR #801](https://github.com/mad4bdigital-ai/WordPress/pull/801), rather than a parallel Host signer. The historical v79 operation-plan verifier is retained so first-anchor bootstrap and existing operational issue/revoke semantics are independently compared.

The modules **do not issue signatures, register providers, run Original312/313, authorize Production, mutate WordPress, or create Grants**. No test fixture is a live authority. `scripts/host_v2_live_release_readiness.py` was adapted only to locate the already-reviewed v82 candidate at `ops/original318-signed-anchor-candidate-v82/`. Its output is explicitly non-authorizing.

Acceptance requires independent first-party Host Anchor **issue and revoke** providers, exact registered Original306/312 job routing, certified signing/revision high-water, Original313 readback and rollback, the original Source Publisher's exact file path/plan/enrolled scope, and a signed Master/Broker job. Do not copy v82 to WordPress by root shell or use owner approval comments as file-write authority.

Local preflight executed on the authorized Central VPS: six original test modules completed 84/84 hermetic Python tests, no live mutation. Preserve original source SHAs in the Git tree and existing trust policy.