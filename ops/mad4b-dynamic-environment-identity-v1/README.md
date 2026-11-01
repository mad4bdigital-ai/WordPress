# Dynamic company + environment signed identity

This Staging source candidate reconciles legacy central `291` signed identity reads with existing centrally certified `299` environment-scoped P-256 trust. It **does not** introduce a second trust store or migrate any Production key.

- Production canonical directory and P-256 trust remain unchanged.
- For every enrolled company Staging environment, resolve exact canonical origin and UUID from the Company Environment Registry. Obtain the expected public key from `299`'s existing `company + staging` trust option, never the legacy company-wide key.
- The legacy Staging-only `291::verify` endpoint delegates to `299::verify(company,'staging')` and validates the independent result. The old `291` implementation must no longer write Staging identity into the legacy Production-only Portfolio registry.
- The underlying `299` signer checks its own dynamically resolved Production hostname rather than one hardcoded company's hostname.
- All trust provisioning remains owner-controlled: observing a public key or receiving an HTTP 200 is **not sufficient** to enroll trust.
- Signed verification updates only its Staging identity proof timestamp. No content writes, Manager delegations, Native Executor activation, NHI grants or Production mutation.

To run hermetic regression locally:

```sh
php -l ops/mad4b-dynamic-environment-identity-v1/291-mad4b-central-signed-site-profile-v4.php
php -l ops/mad4b-dynamic-environment-identity-v1/299-mad4b-environment-signed-identity-v4.php
php ops/mad4b-dynamic-environment-identity-v1/tests/dynamic_env_signed_v4.php
```

Release must preserve live-only WordPress MU differences through an exact Staging patch with backup, hash, independent readback and reversible rollback. This folder is reviewed source, not proof of live deployment.

External hosting (such as `staging.allroyalegypt.com` on the same Hostinger account as the Production domain) must use its enrolled remote transport. Do not require a same-host Docker container and do not repurpose the central VPS operator's root shell to impersonate a remote company.
