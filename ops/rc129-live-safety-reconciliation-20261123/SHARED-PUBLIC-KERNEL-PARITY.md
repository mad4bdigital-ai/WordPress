# Restore active source omission: Public Document Kernel (rc128 → rc129)

**Source-only, review-ready correction. No WordPress deployment.**

Both original signed rc128 live sites have exact matching SHA256 bytes for:

- `includes/class-mad4b-scp-public-document-kernel.php`: `b50a4c6e49c052bea0f2230604a47d3d732f195b94823e6fea7c32e20275528c`
- `config/public-document-kernel-manifest-v1.json`: `746d8d9b6eed46ca674c5c1b89edea1cd3a65914d5ce45d453e8815174b6db55`

Both deployed main plugin entrypoints call the kernel immediately after the
preexisting foreign/zero-touch early return and before loading the admin route
registry. Candidate rc129 omitted the class, manifest and 8-line guarded
entrypoint. Live DB option `mad4b_scp_public_document_kernel_mode_v1` is **on**
on both sites with 1 allowed prefix and 2 exact verified paths each, so this is
an **active** shared feature, not optional dead code.

Restore both byte-identical live files and exact entrypoint block, preserving
`OFF` as the manifest default and **all company-specific configuration in DB**.
There is no hard-coded tour route allowlist or new permit. The reduced boot is
strictly refused for admin, CLI, AJAX, cron, query/cookies, signed/credential
headers, unknown host or market context and routes outside exact company-owned
paths. It is not a cache permit or a remote write capability.

`tests/public-document-kernel-live-parity-runtime.php` runs independently of
WordPress and executes **31/31** positive/negative checks. The source contract
pins the guarded entrypoint, its order, site profile checks and OFF default.
CI runs both, without touching any company runtime.

**Not a release:** other plugin and MU changes still differ; additional
behavior, source attestation and rollback receipts are required. Never infer
cross-company source parity or a signed verdict from this one restored slice.
